<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F2b: loan repayments (sp_repay_loan).
 *
 * - loan_payment: what was paid (type, channel, principal, interest charged and
 *   waived), how (the LOAN_PAYMENT transaction and account for ONLINE) and by whom
 *   (paid_by user for ONLINE, received_by employee for BRANCH). A BRANCH (cash)
 *   payment has no transaction: transactions rows belong to an account.
 *   remaining_balance is the sum of the loan's UNPAID instalments after the payment.
 * - loan_instalment: amount_paid and interest_waived, set when a row is PAID or SETTLED.
 *
 * The new loan_payment columns are NOT NULL, and the new CHECK covers existing
 * instalments, so up() refuses to run unless loan_payment is empty and every
 * instalment is UNPAID. down() needs an empty loan_payment too.
 *
 * transaction_id, account_id, paid_by and received_by appear in a CHECK, so their
 * foreign keys are ON UPDATE RESTRICT (MySQL error 3823; see database/schema/README.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertEmpty(DB::table('loan_payment'), 'loan_payment', 'Phase F2b adds required columns to loan_payment');
        $this->assertEmpty(DB::table('loan_instalment')->where('status', '<>', 'UNPAID'), 'loan_instalment (paid or settled rows)', 'Phase F2b adds a payment check to loan_instalment');

        Schema::table('loan_payment', function (Blueprint $table) {
            $table->enum('payment_type', ['INSTALMENT', 'EARLY_PAYOFF'])->after('branch_id');
            $table->enum('channel', ['ONLINE', 'BRANCH'])->after('payment_type');
            $table->decimal('principal_paid', 15, 2)->after('payment_amount');
            $table->decimal('interest_charged', 15, 2)->after('principal_paid');
            $table->decimal('interest_waived', 15, 2)->default(0)->after('interest_charged');
            $table->foreignId('transaction_id')->nullable()->unique()->after('status')->constrained('transactions', 'transaction_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('account_id')->nullable()->after('transaction_id')->constrained('account', 'account_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('paid_by')->nullable()->after('account_id')->constrained('users', 'user_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('received_by')->nullable()->after('paid_by')->constrained('employee', 'employee_id')->restrictOnDelete()->restrictOnUpdate();

            $table->index(['loan_id', 'payment_date'], 'idx_loan_payment_loan_date');
        });
        // On a first run MySQL drops loan_id's own foreign key index here by itself, because
        // the composite index now serves the foreign key. After a down() it is an explicit
        // index, which MySQL keeps, so drop it to end up with the same schema either way.
        if (Schema::hasIndex('loan_payment', 'loan_payment_loan_id_foreign')) {
            Schema::table('loan_payment', fn (Blueprint $table) => $table->dropIndex('loan_payment_loan_id_foreign'));
        }
        DB::statement('ALTER TABLE loan_payment ADD CONSTRAINT chk_loan_payment_parts CHECK (principal_paid > 0 AND interest_charged >= 0 AND interest_waived >= 0 AND payment_amount = principal_paid + interest_charged AND remaining_balance >= 0)');
        DB::statement("ALTER TABLE loan_payment ADD CONSTRAINT chk_loan_payment_waiver CHECK (payment_type = 'EARLY_PAYOFF' OR interest_waived = 0)");
        DB::statement(<<<'SQL'
ALTER TABLE loan_payment ADD CONSTRAINT chk_loan_payment_channel CHECK (
    (channel = 'ONLINE' AND transaction_id IS NOT NULL AND account_id IS NOT NULL AND paid_by IS NOT NULL AND received_by IS NULL)
    OR (channel = 'BRANCH' AND transaction_id IS NULL AND account_id IS NULL AND paid_by IS NULL AND received_by IS NOT NULL)
)
SQL);

        Schema::table('loan_instalment', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->nullable()->after('status');
            $table->decimal('interest_waived', 15, 2)->nullable()->after('amount_paid');
        });
        // loan_payment_id has a cascading foreign key, so sp_repay_loan sets it rather than this CHECK.
        DB::statement(<<<'SQL'
ALTER TABLE loan_instalment ADD CONSTRAINT chk_loan_instalment_payment CHECK (
    (status = 'UNPAID' AND amount_paid IS NULL AND interest_waived IS NULL AND paid_at IS NULL)
    OR (status <> 'UNPAID' AND paid_at IS NOT NULL AND interest_waived >= 0 AND interest_waived <= interest
        AND amount_paid = amount - interest_waived AND (status <> 'PAID' OR interest_waived = 0))
)
SQL);
    }

    public function down(): void
    {
        $this->assertEmpty(DB::table('loan_payment'), 'loan_payment', 'Rolling back Phase F2b would drop payment details');

        DB::statement('ALTER TABLE loan_instalment DROP CHECK chk_loan_instalment_payment');
        Schema::table('loan_instalment', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'interest_waived']);
        });

        foreach (['chk_loan_payment_parts', 'chk_loan_payment_waiver', 'chk_loan_payment_channel'] as $check) {
            DB::statement("ALTER TABLE loan_payment DROP CHECK {$check}");
        }

        Schema::table('loan_payment', function (Blueprint $table) {
            // MySQL dropped loan_id's own foreign key index when up() added the
            // composite one, so put it back before dropping the composite.
            $table->index('loan_id', 'loan_payment_loan_id_foreign');
            $table->dropIndex('idx_loan_payment_loan_date');
            foreach (['transaction_id', 'account_id', 'paid_by', 'received_by'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropUnique(['transaction_id']);
            $table->dropColumn([
                'payment_type', 'channel', 'principal_paid', 'interest_charged', 'interest_waived',
                'transaction_id', 'account_id', 'paid_by', 'received_by',
            ]);
        });
    }

    private function assertEmpty(Builder $query, string $what, string $why): void
    {
        $count = $query->count();

        if ($count > 0) {
            throw new RuntimeException("{$why}, which is only safe while {$what} is empty; it has {$count} row(s). Nothing was changed.");
        }
    }
};

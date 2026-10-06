<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F2a: loan applications, verification, maker-checker approval and the
 * repayment schedule.
 *
 * - loan_type: name and annual rate (> 0); the rate is copied onto the loan at apply time.
 * - loan: disbursement account, repayment plan, the quote at apply time, the staff
 *   approval (staff_approved_*), rejection and cancellation. approved_by / approval_date
 *   keep their meaning as the final (admin) approval, which is also the disbursement.
 *   Status APPROVED is dropped: approval and disbursement are one step in sp_disburse_loan.
 * - loan_application: the applicant's answers, as a snapshot (1:1 with loan).
 * - loan_guarantor: one guarantor per loan.
 * - loan_verification: the four staff checks, one row per check.
 * - loan_instalment: the schedule written at disbursement.
 *
 * Changing loan's columns and status values is only safe while loan is empty,
 * so up() refuses to run otherwise. down() needs empty tables too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertEmpty('loan', 'Phase F2a adds required columns to loan and changes its status values');

        Schema::create('loan_type', function (Blueprint $table) {
            $table->id('loan_type_id');
            $table->string('type_name', 50)->unique();
            $table->decimal('interest_rate', 5, 2);
        });
        DB::statement('ALTER TABLE loan_type ADD CONSTRAINT chk_loan_type_interest_rate CHECK (interest_rate > 0 AND interest_rate < 100)');

        Schema::table('loan', function (Blueprint $table) {
            $table->foreignId('loan_type_id')->after('branch_id')->constrained('loan_type', 'loan_type_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('account_id')->after('loan_type_id')->constrained('account', 'account_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('repayment_plan', ['MONTHLY', 'SINGLE'])->after('loan_term_months');
            $table->decimal('monthly_instalment', 15, 2)->nullable()->after('repayment_plan');
            $table->decimal('total_interest', 15, 2)->after('monthly_instalment');
            $table->decimal('total_repayable', 15, 2)->after('total_interest');
            $table->enum('status', ['PENDING', 'AWAITING_ADMIN', 'REJECTED', 'CANCELLED', 'ACTIVE', 'CLOSED'])->default('PENDING')->change();
            $table->foreignId('staff_approved_by')->nullable()->after('application_date')->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('staff_approved_at')->nullable()->after('staff_approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approval_date')->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
            $table->dateTime('cancelled_at')->nullable()->after('rejection_reason');
            $table->foreignId('disbursement_transaction_id')->nullable()->after('cancelled_at')->constrained('transactions', 'transaction_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('closed_at')->nullable()->after('disbursement_transaction_id');

            $table->index(['customer_id', 'status'], 'idx_loan_customer_status');
            $table->index(['branch_id', 'status'], 'idx_loan_branch_status');
        });
        // 600 is a structural bound only; config('bank.loans') sets the real limit.
        DB::statement('ALTER TABLE loan ADD CONSTRAINT chk_loan_term CHECK (loan_term_months BETWEEN 1 AND 600)');
        DB::statement("ALTER TABLE loan ADD CONSTRAINT chk_loan_plan_instalment CHECK ((repayment_plan = 'MONTHLY' AND monthly_instalment > 0) OR (repayment_plan = 'SINGLE' AND monthly_instalment IS NULL))");
        DB::statement('ALTER TABLE loan ADD CONSTRAINT chk_loan_totals CHECK (total_interest > 0 AND total_repayable = loan_amount + total_interest)');

        Schema::create('loan_application', function (Blueprint $table) {
            $table->foreignId('loan_id')->primary()->constrained('loan', 'loan_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('purpose_category', ['BUSINESS', 'EDUCATION', 'MEDICAL', 'HOME', 'PERSONAL', 'OTHER']);
            $table->string('purpose_description', 500);
            $table->decimal('monthly_income', 15, 2);
            $table->string('tin', 20);
            $table->enum('employment_type', ['EMPLOYED', 'BUSINESS_OWNER', 'CONTENT_CREATOR', 'OTHER']);
            // Which of these are required depends on employment_type (LoanApplicationRequest).
            $table->string('employer_name', 150)->nullable();
            $table->string('workplace_address', 255)->nullable();
            $table->string('employer_phone', 20)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_registration_number', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('account_handle', 100)->nullable();
            $table->string('employment_description', 500)->nullable();
            // The customer's profile contact details when they applied.
            $table->string('contact_phone', 20);
            $table->string('contact_email', 150);
        });
        DB::statement('ALTER TABLE loan_application ADD CONSTRAINT chk_loan_application_income CHECK (monthly_income > 0)');

        Schema::create('loan_guarantor', function (Blueprint $table) {
            $table->id('loan_guarantor_id');
            $table->foreignId('loan_id')->unique()->constrained('loan', 'loan_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('full_name', 150);
            $table->string('phone', 20);
            $table->string('occupation', 100);
            $table->string('address', 255);
            $table->string('email', 150);
        });

        Schema::create('loan_verification', function (Blueprint $table) {
            $table->id('loan_verification_id');
            $table->foreignId('loan_id')->constrained('loan', 'loan_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('check_type', ['BANK_STATEMENT', 'EMPLOYMENT', 'GUARANTOR_CONTACTED', 'GUARANTOR_OCCUPATION']);
            $table->string('note', 500);
            $table->foreignId('verified_by')->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('verified_at');

            $table->unique(['loan_id', 'check_type'], 'uq_loan_verification_check');
        });

        Schema::create('loan_instalment', function (Blueprint $table) {
            $table->id('loan_instalment_id');
            $table->foreignId('loan_id')->constrained('loan', 'loan_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->unsignedSmallInteger('instalment_number');
            $table->date('due_date');
            $table->decimal('principal', 15, 2);
            $table->decimal('interest', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->enum('status', ['UNPAID', 'PAID', 'SETTLED'])->default('UNPAID');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('loan_payment_id')->nullable()->constrained('loan_payment', 'loan_payment_id')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['loan_id', 'instalment_number'], 'uq_loan_instalment_number');
            $table->index(['loan_id', 'status'], 'idx_loan_instalment_status');
        });
        DB::statement('ALTER TABLE loan_instalment ADD CONSTRAINT chk_loan_instalment_amounts CHECK (principal > 0 AND interest >= 0 AND amount = principal + interest AND balance_after >= 0)');
    }

    public function down(): void
    {
        $this->assertEmpty('loan', 'Rolling back Phase F2a would drop loan applications and schedules');

        Schema::dropIfExists('loan_instalment');
        Schema::dropIfExists('loan_verification');
        Schema::dropIfExists('loan_guarantor');
        Schema::dropIfExists('loan_application');

        foreach (['chk_loan_term', 'chk_loan_plan_instalment', 'chk_loan_totals'] as $check) {
            DB::statement("ALTER TABLE loan DROP CHECK {$check}");
        }

        Schema::table('loan', function (Blueprint $table) {
            // MySQL dropped the foreign keys' own indexes when up() added these
            // composite ones, so put them back before dropping the composites.
            $table->index('customer_id', 'loan_customer_id_foreign');
            $table->index('branch_id', 'loan_branch_id_foreign');
            $table->dropIndex('idx_loan_customer_status');
            $table->dropIndex('idx_loan_branch_status');
            foreach (['loan_type_id', 'account_id', 'staff_approved_by', 'rejected_by', 'disbursement_transaction_id'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn([
                'loan_type_id', 'account_id', 'repayment_plan', 'monthly_instalment', 'total_interest', 'total_repayable',
                'staff_approved_by', 'staff_approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
                'cancelled_at', 'disbursement_transaction_id', 'closed_at',
            ]);
        });

        Schema::table('loan', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'ACTIVE', 'CLOSED'])->default('PENDING')->change();
        });

        Schema::dropIfExists('loan_type');
    }

    private function assertEmpty(string $table, string $why): void
    {
        $count = DB::table($table)->count();

        if ($count > 0) {
            throw new RuntimeException("{$why}, which is only safe while {$table} is empty; it has {$count} row(s). Nothing was changed.");
        }
    }
};

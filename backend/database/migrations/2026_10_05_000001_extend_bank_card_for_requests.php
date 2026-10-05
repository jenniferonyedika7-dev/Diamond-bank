<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Debit-card requests (Phase F1). A card starts as REQUESTED with no number;
 * staff issue it, which sets the number, last4 and dates.
 *
 * card_number holds Laravel `encrypted` ciphertext, so it becomes TEXT and loses
 * its unique index (each encryption uses a random IV); uniqueness moves to
 * card_number_hash, an HMAC of the number keyed with APP_KEY. No CVV is stored.
 *
 * Changing card_number's type is only safe while bank_card is empty, so up()
 * refuses to run otherwise. down() needs an empty table too: ciphertext does
 * not fit the old varchar(19).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertEmpty('Phase F1 changes bank_card.card_number to encrypted TEXT');

        Schema::table('bank_card', function (Blueprint $table) {
            $table->dropUnique('bank_card_card_number_unique');
        });

        Schema::table('bank_card', function (Blueprint $table) {
            $table->text('card_number')->nullable()->change();
            $table->char('card_number_hash', 64)->nullable()->unique()->after('card_number');
            $table->char('last4', 4)->nullable()->after('card_number_hash');
            $table->date('expiry_date')->nullable()->change();
            $table->enum('status', ['REQUESTED', 'ACTIVE', 'BLOCKED', 'REJECTED', 'EXPIRED'])->default('REQUESTED')->change();
            $table->date('issued_date')->nullable()->change();
            $table->foreignId('requested_by')->nullable()->after('issued_date')->constrained('users', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('requested_at')->useCurrent()->after('requested_by');
            $table->foreignId('decided_by')->nullable()->after('requested_at')->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('decided_at')->nullable()->after('decided_by');
            $table->string('rejection_reason', 255)->nullable()->after('decided_at');

            $table->index(['account_id', 'status'], 'idx_bank_card_account_status');
        });
    }

    public function down(): void
    {
        $this->assertEmpty('Rolling back would drop card requests and cannot fit encrypted card numbers into varchar(19)');

        Schema::table('bank_card', function (Blueprint $table) {
            $table->dropIndex('idx_bank_card_account_status');
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['decided_by']);
            $table->dropUnique(['card_number_hash']);
            $table->dropColumn(['card_number_hash', 'last4', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'rejection_reason']);
        });

        Schema::table('bank_card', function (Blueprint $table) {
            $table->string('card_number', 19)->nullable(false)->unique()->change();
            $table->date('expiry_date')->nullable(false)->change();
            $table->enum('status', ['ACTIVE', 'BLOCKED', 'EXPIRED'])->default('ACTIVE')->change();
            $table->date('issued_date')->nullable(false)->change();
        });
    }

    private function assertEmpty(string $why): void
    {
        $count = DB::table('bank_card')->count();

        if ($count > 0) {
            throw new RuntimeException("{$why}, which is only safe while bank_card is empty; it has {$count} row(s). Nothing was changed.");
        }
    }
};

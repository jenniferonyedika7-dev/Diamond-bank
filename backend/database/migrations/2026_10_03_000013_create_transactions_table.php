<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id('transaction_id');
            $table->foreignId('account_id')->constrained('account', 'account_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('transaction_type_id')->constrained('transaction_type', 'transaction_type_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->nullable()->constrained('branch', 'branch_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('employee_id')->nullable()->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('transfer_id')->nullable()->constrained('transfer', 'transfer_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 15, 2);
            $table->enum('channel', ['BRANCH', 'ONLINE', 'MOBILE', 'ATM']);
            $table->decimal('balance_after', 15, 2);
            $table->dateTime('transaction_date')->useCurrent();
            $table->string('description', 255)->nullable();

            $table->index(['account_id', 'transaction_date'], 'idx_transactions_account_date');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

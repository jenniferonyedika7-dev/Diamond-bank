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
        Schema::create('loan_payment', function (Blueprint $table) {
            $table->id('loan_payment_id');
            $table->foreignId('loan_id')->constrained('loan', 'loan_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branch', 'branch_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('payment_amount', 15, 2);
            $table->dateTime('payment_date')->useCurrent();
            $table->decimal('remaining_balance', 15, 2);
            $table->enum('status', ['COMPLETED', 'REVERSED'])->default('COMPLETED');
        });

        DB::statement('ALTER TABLE loan_payment ADD CONSTRAINT chk_loan_payment_amount CHECK (payment_amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_payment');
    }
};

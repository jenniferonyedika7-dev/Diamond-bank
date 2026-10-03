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
        Schema::create('loan', function (Blueprint $table) {
            $table->id('loan_id');
            $table->foreignId('customer_id')->constrained('customer', 'customer_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branch', 'branch_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('approved_by')->nullable()->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('loan_amount', 15, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->unsignedSmallInteger('loan_term_months');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'ACTIVE', 'CLOSED'])->default('PENDING');
            $table->dateTime('application_date')->useCurrent();
            $table->dateTime('approval_date')->nullable();
        });

        DB::statement('ALTER TABLE loan ADD CONSTRAINT chk_loan_amount CHECK (loan_amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan');
    }
};

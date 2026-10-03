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
        Schema::create('account', function (Blueprint $table) {
            $table->id('account_id');
            $table->foreignId('customer_id')->constrained('customer', 'customer_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branch', 'branch_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('account_type_id')->constrained('account_type', 'account_type_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('account_number', 20)->unique();
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->char('currency_code', 3)->default('GMD');
            $table->enum('status', ['ACTIVE', 'FROZEN', 'CLOSED'])->default('ACTIVE');
            $table->dateTime('opened_date')->useCurrent();
        });

        DB::statement('ALTER TABLE account ADD CONSTRAINT chk_account_balance CHECK (balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account');
    }
};

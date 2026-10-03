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
        // ON UPDATE RESTRICT (not CASCADE): MySQL forbids CHECK constraints on columns
        // used in a cascading foreign key action (error 3823). Primary keys never change.
        Schema::create('transfer', function (Blueprint $table) {
            $table->id('transfer_id');
            $table->foreignId('from_account_id')->constrained('account', 'account_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('to_account_id')->constrained('account', 'account_id')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('amount', 15, 2);
            $table->dateTime('transfer_date')->useCurrent();
            $table->enum('status', ['COMPLETED', 'FAILED', 'REVERSED'])->default('COMPLETED');
        });

        DB::statement('ALTER TABLE transfer ADD CONSTRAINT chk_transfer_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE transfer ADD CONSTRAINT chk_transfer_distinct_accounts CHECK (from_account_id <> to_account_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer');
    }
};

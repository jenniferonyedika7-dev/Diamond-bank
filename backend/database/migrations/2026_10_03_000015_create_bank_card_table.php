<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_card', function (Blueprint $table) {
            $table->id('bank_card_id');
            $table->foreignId('account_id')->constrained('account', 'account_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('card_type_id')->constrained('card_type', 'card_type_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('card_number', 19)->unique();
            $table->date('expiry_date');
            $table->enum('status', ['ACTIVE', 'BLOCKED', 'EXPIRED'])->default('ACTIVE');
            $table->date('issued_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_card');
    }
};

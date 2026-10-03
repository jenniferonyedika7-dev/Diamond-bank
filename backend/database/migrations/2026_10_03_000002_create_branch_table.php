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
        Schema::create('branch', function (Blueprint $table) {
            $table->id('branch_id');
            $table->foreignId('bank_id')->constrained('bank', 'bank_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('branch_name', 100);
            $table->string('branch_code', 20)->unique();
            $table->string('address', 255);
            $table->string('phone', 20);
            $table->date('opened_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch');
    }
};

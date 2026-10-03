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
        Schema::create('customer_address', function (Blueprint $table) {
            $table->id('customer_address_id');
            $table->foreignId('customer_id')->constrained('customer', 'customer_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('address_type', ['HOME', 'WORK', 'MAILING']);
            $table->string('country_region', 100);
            $table->string('city_street', 255);
            $table->string('postal_code', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_address');
    }
};

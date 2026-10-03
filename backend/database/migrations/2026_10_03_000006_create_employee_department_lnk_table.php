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
        Schema::create('employee_department_lnk', function (Blueprint $table) {
            $table->id('employee_department_lnk_id');
            $table->foreignId('employee_id')->constrained('employee', 'employee_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('department_id')->constrained('department', 'department_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->date('start_date');
            $table->date('end_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_department_lnk');
    }
};

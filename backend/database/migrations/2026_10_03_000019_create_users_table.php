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
        // customer_id / employee_id use ON UPDATE RESTRICT (not CASCADE): MySQL forbids CHECK
        // constraints on columns used in a cascading foreign key action (error 3823).
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->foreignId('role_id')->constrained('role', 'role_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customer', 'customer_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employee', 'employee_id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('user_name', 50)->unique();
            $table->string('password', 255);
            $table->enum('channel', ['WEB', 'MOBILE'])->default('WEB');
            $table->enum('status', ['PENDING', 'ACTIVE', 'BLOCKED'])->default('PENDING');
            $table->dateTime('last_login')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_one_owner CHECK ((customer_id IS NOT NULL AND employee_id IS NULL) OR (customer_id IS NULL AND employee_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

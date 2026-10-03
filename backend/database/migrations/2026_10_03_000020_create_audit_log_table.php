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
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id('audit_log_id');
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('action_type', 50);
            $table->string('table_affected', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('action_timestamp')->useCurrent();

            $table->index(['table_affected', 'record_id'], 'idx_audit_log_table_record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};

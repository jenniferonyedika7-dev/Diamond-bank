<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1b: simulated SMS (App\Services\SmsSender), shown on the local-only
 * demo outbox page. message is encrypted with APP_KEY because it can hold a
 * temporary password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sms_outbox', function (Blueprint $table) {
            $table->id('demo_sms_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('phone', 20);
            $table->text('message');
            $table->dateTime('sent_at');

            $table->index('sent_at', 'idx_demo_sms_outbox_sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sms_outbox');
    }
};

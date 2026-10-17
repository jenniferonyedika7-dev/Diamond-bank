<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1b: lets the demo SMS outbox hide a temporary password once it can't
 * be used any more. purpose says what each text was for (SmsSender's
 * $purpose); used_at records when a TEMPORARY_PASSWORD text's code was changed.
 *
 * Existing rows are backfilled from audit_log, without decrypting anything:
 * purpose from the DEMO_SMS_SENT row SmsSender wrote with each text, used_at
 * from the user's first PASSWORD_CHANGED after it. A row with no audit match
 * keeps purpose NULL; the outbox always masks codes in such rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sms_outbox', function (Blueprint $table) {
            $table->string('purpose', 40)->nullable()->after('user_id');
            $table->dateTime('used_at')->nullable()->after('sent_at');

            $table->index(['user_id', 'purpose'], 'idx_demo_sms_outbox_user_purpose');
        });

        DB::statement("UPDATE demo_sms_outbox s
            JOIN audit_log a ON a.action_type = 'DEMO_SMS_SENT' AND a.table_affected = 'demo_sms_outbox' AND a.record_id = s.demo_sms_id
            SET s.purpose = JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.purpose'))");

        DB::statement("UPDATE demo_sms_outbox s
            SET s.used_at = (SELECT MIN(a.action_timestamp) FROM audit_log a
                WHERE a.action_type = 'PASSWORD_CHANGED' AND a.table_affected = 'users'
                  AND a.record_id = s.user_id AND a.action_timestamp >= s.sent_at)
            WHERE s.purpose = 'TEMPORARY_PASSWORD'");
    }

    public function down(): void
    {
        Schema::table('demo_sms_outbox', function (Blueprint $table) {
            // up()'s index let MySQL drop the foreign key's own user_id index; the key needs one back first.
            $table->index('user_id', 'demo_sms_outbox_user_id_foreign');
        });
        Schema::table('demo_sms_outbox', function (Blueprint $table) {
            $table->dropIndex('idx_demo_sms_outbox_user_purpose');
            $table->dropColumn(['purpose', 'used_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1b: when a temporary password (issued by an administrator after an
 * access request or a staff restore) stops working. NULL when the user's
 * password is their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set when an administrator issues a temporary password; cleared when the user changes it.
            $table->dateTime('temp_password_expires_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temp_password_expires_at');
        });
    }
};

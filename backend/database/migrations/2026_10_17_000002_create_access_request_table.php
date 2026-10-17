<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1b: a customer locked out by wrong passwords (or holding an expired
 * temporary password) asks the bank to get back in. Staff at the customer's
 * home branch record the identity checks; an administrator approves or rejects.
 *
 * - One PENDING request per customer: pending_customer_id is customer_id while
 *   PENDING and NULL after, and UNIQUE ignores NULLs.
 * - The four checks must all be true, a decided request records who decided it,
 *   and only a rejection has (and needs) a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        // customer_id and reviewed_by use ON UPDATE RESTRICT: MySQL forbids generated columns and
        // CHECK constraints over a column used in a cascading foreign key action (error 3823).
        Schema::create('access_request', function (Blueprint $table) {
            $table->id('access_request_id');
            $table->foreignId('customer_id')->constrained('customer', 'customer_id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained('users', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branch', 'branch_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('reason', ['LOCKOUT_BLOCKED', 'TEMP_PASSWORD_EXPIRED']);
            $table->enum('contact_channel', ['BRANCH_VISIT', 'PHONE_CALL']);
            $table->boolean('dob_confirmed');
            $table->boolean('phone_confirmed');
            $table->boolean('history_reviewed');
            $table->boolean('declaration_confirmed');
            $table->enum('request_type', ['UNBLOCK_ONLY', 'UNBLOCK_AND_RESET']);
            $table->string('notes', 1000);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->foreignId('requested_by')->constrained('users', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'user_id')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedBigInteger('pending_customer_id')->nullable()->storedAs("IF(status = 'PENDING', customer_id, NULL)");

            $table->unique('pending_customer_id', 'uq_access_request_one_pending');
            $table->index(['status', 'requested_at'], 'idx_access_request_status');
        });
        DB::statement('ALTER TABLE access_request ADD CONSTRAINT chk_access_request_checks CHECK (dob_confirmed = 1 AND phone_confirmed = 1 AND history_reviewed = 1 AND declaration_confirmed = 1)');
        DB::statement("ALTER TABLE access_request ADD CONSTRAINT chk_access_request_review CHECK ((status = 'PENDING' AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status <> 'PENDING' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
        DB::statement("ALTER TABLE access_request ADD CONSTRAINT chk_access_request_rejection CHECK ((status = 'REJECTED' AND rejection_reason IS NOT NULL) OR (status <> 'REJECTED' AND rejection_reason IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('access_request');
    }
};

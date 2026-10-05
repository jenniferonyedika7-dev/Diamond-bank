<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every card is requested by a user, so bank_card.requested_by becomes NOT NULL.
 * 2026_10_05_000001_extend_bank_card_for_requests added it as nullable without
 * needing to (that migration only runs on an empty table). The foreign key to
 * users.user_id (ON DELETE RESTRICT, ON UPDATE CASCADE) is unchanged.
 * Stops without changing anything if a card has no requesting user.
 */
return new class extends Migration
{
    public function up(): void
    {
        $missing = DB::table('bank_card')->whereNull('requested_by')->orderBy('bank_card_id')->pluck('bank_card_id');

        if ($missing->isNotEmpty()) {
            throw new RuntimeException("bank_card.requested_by can't be made NOT NULL: {$missing->count()} card(s) have no requesting user "
                .'(bank_card_id '.$missing->take(10)->implode(', ').($missing->count() > 10 ? ', …' : '').'). '
                .'Set requested_by on them first. Nothing was changed.');
        }

        Schema::table('bank_card', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bank_card', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
    }
};

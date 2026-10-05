<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Diamond Bank issues debit cards only: there is no credit line in the schema.
 * Deletes unused card types named like "credit". A credit type that cards
 * already use is a decision for a person, so the migration stops instead.
 * CardTypeRequest refuses such names from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $types = DB::table('card_type')->where('type_name', 'like', '%credit%')->lockForUpdate()->get(['card_type_id', 'type_name']);

            $used = $types->filter(fn ($type) => DB::table('bank_card')->where('card_type_id', $type->card_type_id)->exists());
            if ($used->isNotEmpty()) {
                throw new RuntimeException('Credit card types are used by existing cards and were not removed: '
                    .$used->pluck('type_name')->implode(', ').'. Move those cards to a debit type first. Nothing was changed.');
            }

            DB::table('card_type')->whereIn('card_type_id', $types->pluck('card_type_id'))->delete();
        });
    }

    /** Deleted credit types are not recreated. */
    public function down(): void {}
};

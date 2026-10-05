<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Debit cards. card_number is encrypted with APP_KEY and never leaves the
 * server; screens show masked_number, built from last4. There is no CVV.
 * Status changes are written by the card controllers under row locks, so
 * nothing is fillable.
 */
#[Hidden(['card_number', 'card_number_hash'])]
class BankCard extends Model
{
    /** A card in one of these states counts as the account's one live card. */
    public const LIVE_STATUSES = ['REQUESTED', 'ACTIVE'];

    protected $table = 'bank_card';

    protected $primaryKey = 'bank_card_id';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'card_number' => 'encrypted',
            'expiry_date' => 'date:Y-m-d',
            'issued_date' => 'date:Y-m-d',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** "**** **** **** 1234", or null before the card is issued. */
    public static function mask(?string $last4): ?string
    {
        return $last4 === null ? null : "**** **** **** {$last4}";
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /** @return BelongsTo<CardType, $this> */
    public function cardType(): BelongsTo
    {
        return $this->belongsTo(CardType::class, 'card_type_id', 'card_type_id');
    }
}

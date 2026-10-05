<?php

namespace App\Queries;

use App\Models\BankCard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Card listings for the customer and staff areas. Selects last4 only (never
 * card_number or its hash); present() turns a row into the API shape with
 * masked_number.
 */
class CardDirectory
{
    public static function query(): Builder
    {
        return DB::table('bank_card')
            ->join('account', 'account.account_id', '=', 'bank_card.account_id')
            ->join('card_type', 'card_type.card_type_id', '=', 'bank_card.card_type_id')
            ->join('customer', 'customer.customer_id', '=', 'account.customer_id')
            ->orderByDesc('bank_card.requested_at')
            ->orderByDesc('bank_card.bank_card_id')
            ->select([
                'bank_card.bank_card_id', 'bank_card.last4', 'bank_card.status', 'bank_card.expiry_date', 'bank_card.issued_date',
                'bank_card.requested_at', 'bank_card.decided_at', 'bank_card.rejection_reason',
                'card_type.card_type_id', 'card_type.type_name', 'card_type.daily_limit',
                'account.account_number', 'account.status as account_status', 'account.branch_id',
                'customer.customer_id', 'customer.first_name', 'customer.last_name',
            ]);
    }

    /** @return array<string, mixed> */
    public static function present(object $row, bool $withCustomer = false): array
    {
        return [
            'bank_card_id' => $row->bank_card_id,
            'masked_number' => BankCard::mask($row->last4),
            'last4' => $row->last4,
            'status' => $row->status,
            'card_type' => ['card_type_id' => $row->card_type_id, 'type_name' => $row->type_name, 'daily_limit' => $row->daily_limit],
            'account_number' => $row->account_number,
            'account_status' => $row->account_status,
            'expiry_date' => $row->expiry_date,
            'issued_date' => $row->issued_date,
            'requested_at' => $row->requested_at,
            'decided_at' => $row->decided_at,
            'rejection_reason' => $row->rejection_reason,
            ...($withCustomer ? ['customer' => [
                'customer_id' => $row->customer_id,
                'name' => "{$row->first_name} {$row->last_name}",
            ]] : []),
        ];
    }
}

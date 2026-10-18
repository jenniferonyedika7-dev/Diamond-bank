<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The daily transfer limit per source account (account_type.daily_transfer_limit),
 * for display. sp_transfer_funds enforces it, reading the same sum as a locking
 * read; both share fn_transfer_day_start(), the bank's day in Africa/Banjul.
 * Amounts are decimal strings, never floats.
 */
final class DailyTransferLimit
{
    /** Completed transfers out of the account today, on any channel (fn_transferred_today). */
    public static function sentToday(int $accountId): string
    {
        return bcadd((string) DB::selectOne('SELECT fn_transferred_today(?) AS sent', [$accountId])->sent, '0', 2);
    }

    /** What may still leave the account today: the limit less today's transfers, never below zero. */
    public static function remaining(int $accountId, string $limit): string
    {
        $remaining = bcsub($limit, self::sentToday($accountId), 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 16-digit Luhn-valid test card numbers: config('bank.card_bin'), random
 * digits, check digit. hash() is the keyed HMAC stored in
 * bank_card.card_number_hash to keep numbers unique (the number itself is
 * encrypted, so it can't carry a unique index).
 */
class CardNumber
{
    public const LENGTH = 16;

    /** A number not yet used by any card. */
    public static function generateUnique(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = self::generate();
            if (! DB::table('bank_card')->where('card_number_hash', self::hash($number))->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not generate an unused card number.');
    }

    public static function generate(): string
    {
        $body = (string) config('bank.card_bin');
        while (strlen($body) < self::LENGTH - 1) {
            $body .= random_int(0, 9);
        }

        return $body.self::checkDigit($body);
    }

    public static function isLuhnValid(string $number): bool
    {
        return ctype_digit($number) && strlen($number) > 1
            && self::checkDigit(substr($number, 0, -1)) === (int) substr($number, -1);
    }

    public static function hash(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }

    /** The Luhn digit to append to $body. */
    private static function checkDigit(string $body): int
    {
        $sum = 0;
        // Double every second digit counting from the right of the body (the check digit will sit right of it).
        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 === 0) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10;
    }
}

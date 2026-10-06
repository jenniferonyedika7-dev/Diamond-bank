<?php

namespace App\Support;

/** Display formatting for decimal strings, without converting them to floats. */
final class Money
{
    /** "5000000.00" -> "D 5,000,000.00" */
    public static function dalasi(string $amount): string
    {
        [$whole, $fraction] = explode('.', bcadd($amount, '0', 2));

        return 'D '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}

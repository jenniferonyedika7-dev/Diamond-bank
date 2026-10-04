<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Shows a transfer recipient without revealing who they are:
 * "Awa Jallow" -> "A*** J***". The number of stars is fixed so it
 * doesn't leak the name's length.
 */
class MaskedName
{
    public static function of(string $firstName, string $lastName): string
    {
        return collect([$firstName, $lastName])
            ->map(fn (string $part) => Str::upper(Str::substr(trim($part), 0, 1)).'***')
            ->implode(' ');
    }
}

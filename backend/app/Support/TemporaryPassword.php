<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * System-generated temporary passwords. Nobody types or sees one apart from
 * its recipient: the caller hashes it and texts it (SmsSender), and it is never
 * returned by the API or logged.
 */
final class TemporaryPassword
{
    public const LENGTH = 12;

    // No 0/O, 1/l/I: the code is read off a phone screen.
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    /** 12 characters from a CSPRNG, with at least one upper-case letter, one lower-case letter and one digit. */
    public static function generate(): string
    {
        $all = self::UPPER.self::LOWER.self::DIGITS;
        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS)];
        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }

        // Fisher-Yates with random_int, so the guaranteed characters aren't always first.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** A regex fragment (no delimiters) matching exactly one code generate() can make. */
    public static function pattern(): string
    {
        return '['.preg_quote(self::UPPER.self::LOWER.self::DIGITS, '/').']{'.self::LENGTH.'}';
    }

    public static function lifetimeMinutes(): int
    {
        return (int) config('bank.temp_password_expiry_minutes');
    }

    /** "24 hours", "1 hour", "30 minutes", "90 minutes": whole hours from 60 minutes up, minutes otherwise. */
    public static function lifetimeText(): string
    {
        $minutes = self::lifetimeMinutes();

        return $minutes >= 60 && $minutes % 60 === 0
            ? ($minutes / 60).' '.Str::plural('hour', $minutes / 60)
            : $minutes.' '.Str::plural('minute', $minutes);
    }

    private static function pick(string $from): string
    {
        return $from[random_int(0, strlen($from) - 1)];
    }
}

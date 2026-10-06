<?php

namespace App\Support;

/**
 * Taxpayer Identification Numbers on loan applications. The full TIN is shown
 * only on the staff and admin loan detail; lists and the audit log use mask().
 */
final class Tin
{
    /** Digits only, 8 to 15 of them. The one place the format is defined. */
    public const PATTERN = '/^\d{8,15}$/';

    /** "123456789" -> "******789" */
    public static function mask(?string $tin): ?string
    {
        if ($tin === null) {
            return null;
        }

        return str_repeat('*', max(strlen($tin) - 3, 0)).substr($tin, -3);
    }
}

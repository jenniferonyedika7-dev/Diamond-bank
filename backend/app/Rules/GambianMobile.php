<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Gambian mobile number: 9 digits, no country code, starting with 83 (QCell),
 * 86 (Comium) or 87 (Africell), the prefixes after PURA's September 2026
 * numbering migration. Gamcel numbers are not supported.
 *
 * Requests strip spaces with normalize() in prepareForValidation, so the
 * stored value is the bare 9 digits.
 */
class GambianMobile implements ValidationRule
{
    public const PATTERN = '/^8[367]\d{7}$/';

    public const MESSAGE = 'Enter a 9-digit Gambian mobile number starting with 83, 86 or 87.';

    /** Removes the spaces a user may type ("87 123 4567"); non-strings pass through for the other rules to refuse. */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? str_replace(' ', '', $value) : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(self::MESSAGE);
        }
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Debit Cards
    |--------------------------------------------------------------------------
    |
    | Card numbers are generated test numbers: this 6-digit prefix, 9 random
    | digits and a Luhn check digit. The default 990000 sits in the ISO
    | "national use" range, so it never matches a real card network.
    | A card expires at the end of the month, this many years after issue.
    |
    */

    'card_bin' => env('BANK_CARD_BIN', '990000'),

    'card_validity_years' => (int) env('BANK_CARD_VALIDITY_YEARS', 3),

    /*
    |--------------------------------------------------------------------------
    | Loans
    |--------------------------------------------------------------------------
    |
    | Limits for new applications (money as strings, never floats). Every loan
    | needs a staff approval and then an admin approval. The affordability
    | warning flags a monthly repayment above this percentage of the declared
    | monthly income; it never rejects a loan by itself. Staff and admins see
    | the customer's transactions for the last statement_months months.
    |
    */

    'loans' => [
        'min_amount' => env('BANK_LOAN_MIN_AMOUNT', '1000.00'),
        'max_amount' => env('BANK_LOAN_MAX_AMOUNT', '5000000.00'),
        'min_term_months' => (int) env('BANK_LOAN_MIN_TERM_MONTHS', 1),
        'max_term_months' => (int) env('BANK_LOAN_MAX_TERM_MONTHS', 12),
        'affordability_warning_percent' => env('BANK_LOAN_AFFORDABILITY_WARNING_PERCENT', '33'),
        'statement_months' => (int) env('BANK_LOAN_STATEMENT_MONTHS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Re-check Lockout
    |--------------------------------------------------------------------------
    |
    | Wrong passwords on the in-app re-checks (transfers, card reveal, loan
    | payments, change-password), counted per user. max_failures wrong ->
    | locked for first_minutes; after that lock, one more wrong -> locked for
    | second_minutes; after that, one more wrong -> the login is BLOCKED
    | (admins are never blocked: they get another second_minutes lock).
    | A correct password clears the count, and so does reset_hours without a
    | wrong password. The login page counts toward the same lockout.
    |
    */

    'password_lockout' => [
        'max_failures' => 5,
        'first_minutes' => (int) env('LOCKOUT_FIRST_MINUTES', 1),
        'second_minutes' => (int) env('LOCKOUT_SECOND_MINUTES', 2),
        'reset_hours' => (int) env('LOCKOUT_RESET_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Passwords
    |--------------------------------------------------------------------------
    |
    | How long a temporary password texted after an approved access request
    | (or a staff restore) works. The user must change it at first login;
    | after this many minutes it is refused and they must contact the bank.
    |
    */

    'temp_password_expiry_minutes' => (int) env('TEMP_PASSWORD_EXPIRY_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Idle Logout
    |--------------------------------------------------------------------------
    |
    | A signed-in session with no user activity (clicks, typing, navigation,
    | reported by the frontend to POST auth/activity) for this many minutes is
    | logged out by the server (EnsureSessionNotIdle). Background requests
    | never keep a session alive.
    |
    */

    'idle_timeout_minutes' => (int) env('IDLE_TIMEOUT_MINUTES', 5),

];

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

];

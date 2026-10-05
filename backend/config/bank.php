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

];

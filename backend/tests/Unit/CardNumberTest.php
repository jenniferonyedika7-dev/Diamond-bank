<?php

use App\Support\CardNumber;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('generates 16-digit Luhn-valid numbers with the configured prefix', function () {
    config(['bank.card_bin' => '990000']);

    foreach (range(1, 200) as $i) {
        $number = CardNumber::generate();

        expect($number)->toMatch('/^990000\d{10}$/')
            ->and(CardNumber::isLuhnValid($number))->toBeTrue();
    }
});

it('validates the Luhn check digit', function (string $number, bool $valid) {
    expect(CardNumber::isLuhnValid($number))->toBe($valid);
})->with([
    'Visa test number' => ['4111111111111111', true],
    'Mastercard test number' => ['5555555555554444', true],
    'Amex test number' => ['378282246310005', true],
    'last digit off by one' => ['4111111111111112', false],
    'two digits swapped' => ['4111111111111161', false],
    'not digits' => ['4111-1111-1111-1111', false],
    'empty' => ['', false],
]);

it('hashes numbers with a key, so the hash is not a plain SHA-256', function () {
    $hash = CardNumber::hash('4111111111111111');

    expect($hash)->toHaveLength(64)
        ->and($hash)->toBe(CardNumber::hash('4111111111111111'))
        ->and($hash)->not->toBe(hash('sha256', '4111111111111111'));
});

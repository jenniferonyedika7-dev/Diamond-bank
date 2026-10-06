<?php

use App\Support\Amortisation;
use Carbon\CarbonImmutable;

$start = fn () => CarbonImmutable::parse('2026-01-31');

it('builds the standard monthly schedule: 10,000 at 12% over 12 months', function () use ($start) {
    $quote = Amortisation::quote('10000', '12', 12, 'MONTHLY', $start());

    expect($quote['monthly_instalment'])->toBe('888.49')
        ->and($quote['number_of_payments'])->toBe(12)
        ->and($quote['total_interest'])->toBe('661.86')
        ->and($quote['total_repayable'])->toBe('10661.86')
        ->and($quote['schedule'][0])->toBe([
            'instalment_number' => 1, 'due_date' => '2026-02-28',
            'principal' => '788.49', 'interest' => '100.00', 'amount' => '888.49', 'balance_after' => '9211.51',
        ])
        ->and($quote['schedule'][1]['due_date'])->toBe('2026-03-31')
        // The final instalment absorbs the rounding: its principal is whatever balance is left.
        ->and($quote['schedule'][11])->toBe([
            'instalment_number' => 12, 'due_date' => '2027-01-31',
            'principal' => '879.67', 'interest' => '8.80', 'amount' => '888.47', 'balance_after' => '0.00',
        ]);
});

it('keeps every monthly schedule exact', function (string $amount, string $rate) {
    foreach (range(1, 12) as $months) {
        $quote = Amortisation::quote($amount, $rate, $months, 'MONTHLY', CarbonImmutable::parse('2026-03-15'));
        $schedule = $quote['schedule'];
        $opening = bcadd($amount, '0', 2);
        $principal = $interest = '0';

        expect($schedule)->toHaveCount($months);

        foreach ($schedule as $i => $row) {
            foreach (['principal', 'interest', 'amount', 'balance_after'] as $field) {
                expect($row[$field])->toMatch('/^\d+\.\d{2}$/');
            }
            expect($row['instalment_number'])->toBe($i + 1)
                ->and($row['interest'])->toBe(Amortisation::monthlyInterest($opening, $rate))
                ->and(bccomp($row['interest'], '0', 2))->toBe(1)
                ->and(bccomp($row['principal'], '0', 2))->toBe(1)
                ->and(bcadd($row['principal'], $row['interest'], 2))->toBe($row['amount'])
                ->and(bcsub($opening, $row['principal'], 2))->toBe($row['balance_after']);

            if ($i < $months - 1) {
                expect($row['amount'])->toBe($quote['monthly_instalment']);
            }

            $opening = $row['balance_after'];
            $principal = bcadd($principal, $row['principal'], 2);
            $interest = bcadd($interest, $row['interest'], 2);
        }

        expect($principal)->toBe(bcadd($amount, '0', 2))
            ->and($opening)->toBe('0.00')
            ->and($quote['total_interest'])->toBe($interest)
            ->and($quote['total_repayable'])->toBe(bcadd($amount, $interest, 2));
    }
})->with([
    ['1000', '12'],
    ['1234.56', '18.75'],
    ['50000', '0.5'],
    ['999999.99', '24'],
    ['5000000', '36'],
    ['77777.77', '99.99'],
]);

it('handles a one-month monthly loan as principal plus one month of interest', function () use ($start) {
    $quote = Amortisation::quote('5000', '12', 1, 'MONTHLY', $start());

    expect($quote['monthly_instalment'])->toBe('5050.00')
        ->and($quote['schedule'])->toBe([[
            'instalment_number' => 1, 'due_date' => '2026-02-28',
            'principal' => '5000.00', 'interest' => '50.00', 'amount' => '5050.00', 'balance_after' => '0.00',
        ]]);
});

it('charges simple interest on a single payment at the end of the term', function () use ($start) {
    $quote = Amortisation::quote('10000', '12', 6, 'SINGLE', $start());

    expect($quote['monthly_instalment'])->toBeNull()
        ->and($quote['number_of_payments'])->toBe(1)
        ->and($quote['total_interest'])->toBe('600.00')
        ->and($quote['total_repayable'])->toBe('10600.00')
        ->and($quote['schedule'])->toBe([[
            'instalment_number' => 1, 'due_date' => '2026-07-31',
            'principal' => '10000.00', 'interest' => '600.00', 'amount' => '10600.00', 'balance_after' => '0.00',
        ]]);
});

it('rounds half away from zero', function () {
    // 1000.50 × 12% / 12 = 10.005
    expect(Amortisation::simpleInterest('1000.50', '12', 1))->toBe('10.01')
        ->and(Amortisation::monthlyInterest('1000.50', '12'))->toBe('10.01')
        ->and(Amortisation::simpleInterest('1234.56', '18.75', 7))->toBe('135.03')
        ->and(Amortisation::simpleInterest('1000.01', '12', 5))->toBe('50.00');
});

it('measures affordability against monthly income and flags only above the threshold', function () use ($start) {
    $monthly = Amortisation::quote('10000', '12', 12, 'MONTHLY', $start());
    $single = Amortisation::quote('10000', '12', 6, 'SINGLE', $start());

    expect(Amortisation::monthlyRepayment($monthly))->toBe('888.49')
        ->and(Amortisation::monthlyRepayment($single))->toBe('1766.67') // 10,600 / 6
        ->and(Amortisation::affordability('888.49', '2000', '33'))->toMatchArray(['percent_of_income' => '44.4', 'above_warning' => true])
        ->and(Amortisation::affordability('888.49', '3000', '33'))->toMatchArray(['percent_of_income' => '29.6', 'above_warning' => false])
        ->and(Amortisation::affordability('1766.67', '10000', '33'))->toMatchArray(['percent_of_income' => '17.7', 'above_warning' => false])
        ->and(Amortisation::affordability('330.00', '1000', '33'))->toMatchArray(['percent_of_income' => '33.0', 'above_warning' => false])
        ->and(Amortisation::affordability('330.01', '1000', '33'))->toMatchArray(['above_warning' => true]);
});

it('refuses an unknown plan or a zero term', function () use ($start) {
    expect(fn () => Amortisation::quote('1000', '12', 0, 'MONTHLY', $start()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Amortisation::quote('1000', '12', 3, 'WEEKLY', $start()))->toThrow(InvalidArgumentException::class);
});

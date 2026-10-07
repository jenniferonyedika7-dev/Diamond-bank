<?php

use App\Support\Amortisation;
use App\Support\LoanRepayment;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

afterEach(fn () => Carbon::setTestNow());

/** A loan disbursed on $start with its full schedule; $paid instalments are already PAID. */
function repaymentLoan(string $plan, string $start, string $amount = '10000', string $rate = '12', int $term = 12, int $paid = 0): array
{
    $quote = Amortisation::quote($amount, $rate, $term, $plan, CarbonImmutable::parse($start, 'UTC'));
    $loan = (object) [
        'repayment_plan' => $plan, 'loan_amount' => $quote['amount'], 'interest_rate' => $quote['interest_rate'],
        'loan_term_months' => $term, 'approval_date' => "{$start} 10:30:00",
    ];
    $rows = array_map(
        fn (array $row) => $row + ['status' => $row['instalment_number'] <= $paid ? 'PAID' : 'UNPAID'],
        $quote['schedule'],
    );

    return [$loan, $rows, $quote];
}

function quoteOn(string $today, array $loanAndRows): array
{
    Carbon::setTestNow(CarbonImmutable::parse("{$today} 12:00:00", 'UTC'));

    return LoanRepayment::quote($loanAndRows[0], $loanAndRows[1]);
}

/** Every amount is a 2-decimal string, and charged + waived is the unpaid rows' scheduled interest. */
function expectConsistentPayoff(array $quote, array $rows): void
{
    $unpaid = array_filter($rows, fn ($row) => $row['status'] === 'UNPAID');
    $scheduled = array_reduce($unpaid, fn ($sum, $row) => bcadd($sum, $row['interest'], 2), '0');
    $principal = array_reduce($unpaid, fn ($sum, $row) => bcadd($sum, $row['principal'], 2), '0');
    $payoff = $quote['payoff'];

    foreach (['amount', 'principal', 'interest_charged', 'interest_waived'] as $field) {
        expect($payoff[$field])->toMatch('/^\d+\.\d{2}$/');
    }
    expect(bcadd($payoff['interest_charged'], $payoff['interest_waived'], 2))->toBe($scheduled)
        ->and($payoff['principal'])->toBe($principal)
        ->and($payoff['amount'])->toBe(bcadd($principal, $payoff['interest_charged'], 2));

    foreach ($payoff['rows'] as $i => $row) {
        $scheduledRow = array_values($unpaid)[$i];
        expect($row['instalment_number'])->toBe($scheduledRow['instalment_number'])
            ->and(bcadd($row['amount_paid'], $row['interest_waived'], 2))->toBe($scheduledRow['amount']);
    }
}

describe('MONTHLY', function () {
    // 10,000 at 12% over 12 months from 15 Jan 2026: interest 100.00, 92.12, 84.15, ... 8.80; 661.86 in all.

    it('charges only the first month on the disbursement day', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15');
        $quote = quoteOn('2026-01-15', $loan);

        expect($quote['payoff'])->toMatchArray([
            'amount' => '10100.00', 'principal' => '10000.00', 'interest_charged' => '100.00', 'interest_waived' => '561.86', 'months_charged' => 1,
        ])
            ->and($quote['next_instalment'])->toMatchArray(['instalment_number' => 1, 'amount' => '888.49', 'overdue' => false])
            ->and($quote['outstanding'])->toBe('10661.86')
            ->and($quote['overdue_count'])->toBe(0);
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('charges the current month and waives later months when nothing is overdue', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15', paid: 1);
        $quote = quoteOn('2026-02-20', $loan);

        // Row 2 (due 15 Mar) is current; rows 3-12 are principal only.
        expect($quote['payoff'])->toMatchArray(['interest_charged' => '92.12', 'interest_waived' => '469.74', 'amount' => '9303.63'])
            ->and($quote['next_instalment'])->toMatchArray(['instalment_number' => 2, 'overdue' => false])
            ->and($quote['payoff']['rows'][0])->toBe(['instalment_number' => 2, 'amount_paid' => '888.49', 'interest_waived' => '0.00'])
            ->and($quote['payoff']['rows'][1])->toBe(['instalment_number' => 3, 'amount_paid' => '804.34', 'interest_waived' => '84.15']);
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('charges overdue rows in full, plus the current row', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15');
        $quote = quoteOn('2026-03-20', $loan);

        // Rows 1 and 2 are overdue, row 3 (due 15 Apr) is current.
        expect($quote['payoff'])->toMatchArray(['interest_charged' => '276.27', 'interest_waived' => '385.59', 'amount' => '10276.27', 'months_charged' => 3])
            ->and($quote['overdue_count'])->toBe(2)
            ->and($quote['next_instalment'])->toMatchArray(['instalment_number' => 1, 'due_date' => '2026-02-15', 'overdue' => true]);
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('treats a row as current, not overdue, on its due date', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15');

        $onDueDate = quoteOn('2026-02-15', $loan);
        expect($onDueDate['payoff']['interest_charged'])->toBe('100.00')
            ->and($onDueDate['next_instalment']['overdue'])->toBeFalse()
            ->and($onDueDate['overdue_count'])->toBe(0);

        $dayAfter = quoteOn('2026-02-16', $loan);
        expect($dayAfter['payoff']['interest_charged'])->toBe('192.12')
            ->and($dayAfter['next_instalment']['overdue'])->toBeTrue();
    });

    it('waives nothing in the last month', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15', paid: 11);
        $quote = quoteOn('2027-01-10', $loan);

        expect($quote['payoff'])->toMatchArray(['amount' => '888.47', 'interest_charged' => '8.80', 'interest_waived' => '0.00'])
            ->and($quote['next_instalment']['amount'])->toBe('888.47')
            ->and($quote['unpaid_count'])->toBe(1);
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('charges every row in full once all are overdue', function () {
        $loan = repaymentLoan('MONTHLY', '2026-01-15');
        $quote = quoteOn('2027-03-01', $loan);

        expect($quote['payoff'])->toMatchArray(['amount' => '10661.86', 'interest_charged' => '661.86', 'interest_waived' => '0.00', 'months_charged' => 12])
            ->and($quote['overdue_count'])->toBe(12);
    });

    it('has nothing to quote once every row is paid', function () {
        $quote = quoteOn('2027-03-01', repaymentLoan('MONTHLY', '2026-01-15', paid: 12));

        expect($quote['payoff'])->toBeNull()
            ->and($quote['next_instalment'])->toBeNull()
            ->and($quote['outstanding'])->toBe('0.00');
    });
});

describe('SINGLE', function () {
    // 10,000 at 18% for 12 months: 150.00 interest a month, 1,800.00 scheduled.

    it('charges one month on the first day and offers no instalment option', function () {
        $loan = repaymentLoan('SINGLE', '2026-01-15', rate: '18');
        $quote = quoteOn('2026-01-15', $loan);

        expect($quote['payoff'])->toMatchArray([
            'amount' => '10150.00', 'principal' => '10000.00', 'interest_charged' => '150.00', 'interest_waived' => '1650.00', 'months_charged' => 1,
        ])
            ->and($quote['payoff']['rows'])->toBe([['instalment_number' => 1, 'amount_paid' => '10150.00', 'interest_waived' => '1650.00']])
            ->and($quote['next_instalment'])->toBeNull();
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('rounds started months up, with the boundary day still in the earlier month', function (string $today, int $months, string $charged) {
        $quote = quoteOn($today, repaymentLoan('SINGLE', '2026-01-15', rate: '18'));

        expect($quote['payoff']['months_charged'])->toBe($months)
            ->and($quote['payoff']['interest_charged'])->toBe($charged);
    })->with([
        'mid first month' => ['2026-02-01', 1, '150.00'],
        'exactly one month' => ['2026-02-15', 1, '150.00'],
        'one day past a month' => ['2026-02-16', 2, '300.00'],
        'exactly eleven months' => ['2026-12-15', 11, '1650.00'],
        'one day past eleven months' => ['2026-12-16', 12, '1800.00'],
        'last day of the term (due date)' => ['2027-01-15', 12, '1800.00'],
        'past maturity, capped at the term' => ['2027-06-01', 12, '1800.00'],
    ]);

    it('caps a short term at the term', function () {
        $loan = repaymentLoan('SINGLE', '2026-01-15', rate: '18', term: 3);
        $quote = quoteOn('2028-01-01', $loan);

        expect($quote['payoff'])->toMatchArray(['months_charged' => 3, 'interest_charged' => '450.00', 'interest_waived' => '0.00']);
        expectConsistentPayoff($quote, $loan[1]);
    });

    it('rounds the interest half away from zero', function () {
        // 1000 × 0.03 × 1 / 1200 = 0.025 exactly, which rounds up to 0.03.
        $quote = quoteOn('2026-01-15', repaymentLoan('SINGLE', '2026-01-15', amount: '1000', rate: '0.03', term: 3));

        expect($quote['payoff']['interest_charged'])->toBe('0.03');
    });
});

describe('monthsStarted', function () {
    $months = fn (string $start, string $today, int $term = 12) => LoanRepayment::monthsStarted(
        CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($today, 'UTC'), $term,
    );

    it('adds each boundary to the original start, never chaining (31 Jan)', function () use ($months) {
        // Boundaries from 31 Jan 2027: 28 Feb, 31 Mar, 30 Apr. A chained date would give 28 Mar.
        expect($months('2027-01-31', '2027-01-31'))->toBe(1)
            ->and($months('2027-01-31', '2027-02-28'))->toBe(1)
            ->and($months('2027-01-31', '2027-03-01'))->toBe(2)
            ->and($months('2027-01-31', '2027-03-28'))->toBe(2)
            ->and($months('2027-01-31', '2027-03-29'))->toBe(2)
            ->and($months('2027-01-31', '2027-03-31'))->toBe(2)
            ->and($months('2027-01-31', '2027-04-01'))->toBe(3)
            ->and($months('2027-01-31', '2027-04-30'))->toBe(3)
            ->and($months('2027-01-31', '2027-05-01'))->toBe(4);
    });

    it('clamps a 30 Jan start to 28 Feb, then returns to the 30th', function () use ($months) {
        expect($months('2027-01-30', '2027-02-28'))->toBe(1)
            ->and($months('2027-01-30', '2027-03-01'))->toBe(2)
            ->and($months('2027-01-30', '2027-03-30'))->toBe(2)
            ->and($months('2027-01-30', '2027-03-31'))->toBe(3);
    });

    it('uses 29 Feb in a leap year', function () use ($months) {
        expect($months('2028-01-31', '2028-02-29'))->toBe(1)
            ->and($months('2028-01-31', '2028-03-01'))->toBe(2)
            ->and($months('2028-02-29', '2028-03-29'))->toBe(1)
            ->and($months('2028-02-29', '2028-03-30'))->toBe(2)
            ->and($months('2028-02-29', '2028-04-29'))->toBe(2)
            ->and($months('2028-02-29', '2028-04-30'))->toBe(3);
    });

    it('is at least 1 and at most the term', function () use ($months) {
        expect($months('2027-01-31', '2027-01-31', 1))->toBe(1)
            ->and($months('2027-01-31', '2030-01-01', 1))->toBe(1)
            ->and($months('2027-01-31', '2030-01-01', 6))->toBe(6);
    });
});

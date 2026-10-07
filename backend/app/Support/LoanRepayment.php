<?php

namespace App\Support;

use BcMath\Number;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a loan payment costs, for the quote screens: the next instalment, and
 * paying everything now. Pure BCMath on decimal strings, no floats and no
 * database writes. sp_repay_loan works the same amounts out again under its
 * row locks and refuses a payment whose amount differs, so a change here
 * needs the matching change in 2026_10_08_000002_create_repay_loan_procedure.
 *
 * Interest is charged only for months that have started:
 * - INSTALMENT (MONTHLY only): the oldest UNPAID row in full. Due dates rise
 *   with the number, so overdue rows come first and none can be skipped.
 * - EARLY_PAYOFF, MONTHLY: overdue rows (due before today) and the current row
 *   (the oldest UNPAID row not yet overdue) in full; every later row's interest
 *   is waived and only its principal is paid.
 * - EARLY_PAYOFF, SINGLE: principal + round(P × rate × months / 1200, 2), where
 *   months counts the months started since disbursement: at least 1, at most
 *   the term. Month n starts the day after start + n months, so on a boundary
 *   day itself the earlier month is still the current one, as for MONTHLY rows.
 *
 * A SINGLE loan has no INSTALMENT option: before maturity it would charge
 * interest for months that haven't started, and from maturity on the payoff
 * amount is the same. Dates are UTC, like UTC_DATE() in the procedure.
 */
final class LoanRepayment
{
    public const INSTALMENT = 'INSTALMENT';

    public const EARLY_PAYOFF = 'EARLY_PAYOFF';

    public const TYPES = [self::INSTALMENT, self::EARLY_PAYOFF];

    /**
     * @param  object  $loan  repayment_plan, loan_amount, interest_rate, loan_term_months, approval_date (the disbursement)
     * @param  iterable<object|array<string, mixed>>  $rows  the loan's instalments; only UNPAID ones are used
     * @return array{
     *     today: string, outstanding: string, unpaid_count: int, overdue_count: int,
     *     next_instalment: ?array{instalment_number: int, due_date: string, principal: string, interest: string, amount: string, overdue: bool},
     *     payoff: ?array{amount: string, principal: string, interest_charged: string, interest_waived: string, months_charged: int,
     *         rows: list<array{instalment_number: int, amount_paid: string, interest_waived: string}>}
     * }
     */
    public static function quote(object $loan, iterable $rows, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now('UTC'))->toDateString();
        $unpaid = collect($rows)
            ->map(fn ($row) => (array) $row)
            ->filter(fn (array $row) => ($row['status'] ?? 'UNPAID') === 'UNPAID')
            ->sortBy('instalment_number')
            ->values();

        $outstanding = $unpaid->reduce(fn (Number $sum, array $row) => $sum->add($row['amount']), new Number('0'));
        $first = $unpaid->first();

        return [
            'today' => $today,
            'outstanding' => self::money($outstanding),
            'unpaid_count' => $unpaid->count(),
            'overdue_count' => $unpaid->filter(fn (array $row) => (string) $row['due_date'] < $today)->count(),
            'next_instalment' => $first === null || $loan->repayment_plan !== Amortisation::MONTHLY ? null : [
                'instalment_number' => (int) $first['instalment_number'],
                'due_date' => (string) $first['due_date'],
                'principal' => self::money(new Number($first['principal'])),
                'interest' => self::money(new Number($first['interest'])),
                'amount' => self::money(new Number($first['amount'])),
                'overdue' => (string) $first['due_date'] < $today,
            ],
            'payoff' => $first === null ? null : match ($loan->repayment_plan) {
                Amortisation::MONTHLY => self::monthlyPayoff($unpaid->all(), $today),
                Amortisation::SINGLE => self::singlePayoff($loan, $first, $today),
            },
        ];
    }

    /**
     * The months started on $today for a loan disbursed on $start: 1 on the
     * disbursement day, n + 1 from the day after start + n months, at most $term.
     * Each boundary is added to the original start, never to the previous
     * boundary (31 Jan + 2 months is 31 Mar, not 28 Feb + 1 month = 28 Mar),
     * which is how MySQL's DATE_ADD clamps the end of a month.
     */
    public static function monthsStarted(CarbonInterface $start, CarbonInterface $today, int $term): int
    {
        $todayString = $today->toDateString();
        $months = 1;

        while ($months < $term && $start->copy()->addMonthsNoOverflow($months)->toDateString() < $todayString) {
            $months++;
        }

        return $months;
    }

    /**
     * @param  list<array<string, mixed>>  $unpaid  ordered by instalment_number
     * @return array{amount: string, principal: string, interest_charged: string, interest_waived: string, months_charged: int,
     *     rows: list<array{instalment_number: int, amount_paid: string, interest_waived: string}>}
     */
    private static function monthlyPayoff(array $unpaid, string $today): array
    {
        $principal = $charged = $waived = new Number('0');
        $currentFound = false;
        $monthsCharged = 0;
        $rows = [];

        foreach ($unpaid as $row) {
            $charge = (string) $row['due_date'] < $today || ! $currentFound;
            if ((string) $row['due_date'] >= $today) {
                $currentFound = true;
            }

            $principal = $principal->add($row['principal']);
            $rowWaived = $charge ? new Number('0') : new Number($row['interest']);
            $charged = $charged->add($charge ? $row['interest'] : '0');
            $waived = $waived->add($rowWaived);
            $monthsCharged += $charge ? 1 : 0;

            $rows[] = [
                'instalment_number' => (int) $row['instalment_number'],
                'amount_paid' => self::money((new Number($row['amount']))->sub($rowWaived)),
                'interest_waived' => self::money($rowWaived),
            ];
        }

        return self::payoff($principal, $charged, $waived, $monthsCharged, $rows);
    }

    /**
     * @param  array<string, mixed>  $row  the single UNPAID instalment
     * @return array{amount: string, principal: string, interest_charged: string, interest_waived: string, months_charged: int,
     *     rows: list<array{instalment_number: int, amount_paid: string, interest_waived: string}>}
     */
    private static function singlePayoff(object $loan, array $row, string $today): array
    {
        $start = CarbonImmutable::parse($loan->approval_date, 'UTC')->startOfDay();
        $months = self::monthsStarted($start, CarbonImmutable::parse($today, 'UTC'), (int) $loan->loan_term_months);

        $charged = new Number(Amortisation::simpleInterest((string) $loan->loan_amount, (string) $loan->interest_rate, $months));
        $waived = (new Number($row['interest']))->sub($charged);

        return self::payoff(new Number($row['principal']), $charged, $waived, $months, [[
            'instalment_number' => (int) $row['instalment_number'],
            'amount_paid' => self::money((new Number($row['amount']))->sub($waived)),
            'interest_waived' => self::money($waived),
        ]]);
    }

    /**
     * @param  list<array{instalment_number: int, amount_paid: string, interest_waived: string}>  $rows
     * @return array{amount: string, principal: string, interest_charged: string, interest_waived: string, months_charged: int,
     *     rows: list<array{instalment_number: int, amount_paid: string, interest_waived: string}>}
     */
    private static function payoff(Number $principal, Number $charged, Number $waived, int $monthsCharged, array $rows): array
    {
        return [
            'amount' => self::money($principal->add($charged)),
            'principal' => self::money($principal),
            'interest_charged' => self::money($charged),
            'interest_waived' => self::money($waived),
            'months_charged' => $monthsCharged,
            'rows' => $rows,
        ];
    }

    /** Sums and differences of 2-decimal amounts are exact, so this only formats: "1000" -> "1000.00". */
    private static function money(Number $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}

<?php

namespace App\Support;

use BcMath\Number;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RoundingMode;

/**
 * The loan maths, in one place: quotes, schedules and affordability. Pure
 * BCMath on decimal strings, no floats and no database. sp_disburse_loan
 * re-checks a schedule built here using the same expressions, so a change
 * here needs the matching change in 2026_10_06_000002_create_loan_procedures.
 *
 * - MONTHLY: reducing balance. The instalment M = P·r·(1+r)^n / ((1+r)^n − 1),
 *   r = annual rate / 1200, rounded to 2 decimals. Each month's interest is
 *   round(opening balance × rate / 1200, 2) and the rest of M is principal.
 *   The last instalment takes the remaining balance as principal, so the
 *   principal parts add up to P exactly.
 * - SINGLE: one instalment at the end of the term. Simple interest on the
 *   full amount: round(P × rate × months / 1200, 2).
 *
 * Rounding is half away from zero, like MySQL's ROUND() on DECIMAL.
 */
final class Amortisation
{
    public const MONTHLY = 'MONTHLY';

    public const SINGLE = 'SINGLE';

    public const PLANS = [self::MONTHLY, self::SINGLE];

    private const SCALE = 20;

    /**
     * @return array{
     *     repayment_plan: string, amount: string, interest_rate: string, term_months: int,
     *     monthly_instalment: ?string, number_of_payments: int, total_interest: string, total_repayable: string,
     *     schedule: list<array{instalment_number: int, due_date: string, principal: string, interest: string, amount: string, balance_after: string}>
     * }
     */
    public static function quote(string $amount, string $annualRate, int $months, string $plan, CarbonInterface $start): array
    {
        if ($months < 1) {
            throw new InvalidArgumentException('The term must be at least 1 month.');
        }

        $schedule = match ($plan) {
            self::MONTHLY => self::monthlySchedule($amount, $annualRate, $months, $start),
            self::SINGLE => self::singleSchedule($amount, $annualRate, $months, $start),
            default => throw new InvalidArgumentException("Unknown repayment plan {$plan}."),
        };

        $totalInterest = new Number('0');
        foreach ($schedule as $row) {
            $totalInterest = $totalInterest->add($row['interest']);
        }

        return [
            'repayment_plan' => $plan,
            'amount' => self::money(new Number($amount)),
            'interest_rate' => self::money(new Number($annualRate)),
            'term_months' => $months,
            'monthly_instalment' => $plan === self::MONTHLY ? self::monthlyInstalment($amount, $annualRate, $months) : null,
            'number_of_payments' => count($schedule),
            'total_interest' => self::money($totalInterest),
            'total_repayable' => self::money($totalInterest->add($amount)),
            'schedule' => $schedule,
        ];
    }

    /** M for a MONTHLY loan, rounded to 2 decimals. */
    public static function monthlyInstalment(string $amount, string $annualRate, int $months): string
    {
        $principal = new Number($amount);
        $r = (new Number($annualRate))->div(1200, self::SCALE);

        if ($r->compare(0) === 0) {
            return self::round($principal->div($months, self::SCALE));
        }

        $growth = $r->add(1)->pow($months, self::SCALE);

        return self::round($principal->mul($r)->mul($growth)->div($growth->sub(1), self::SCALE));
    }

    /** One month's interest on a reducing balance: round(opening × rate / 1200, 2). */
    public static function monthlyInterest(string $openingBalance, string $annualRate): string
    {
        return self::round((new Number($openingBalance))->mul($annualRate)->div(1200, self::SCALE));
    }

    /** SINGLE plan interest: round(P × rate × months / 1200, 2). */
    public static function simpleInterest(string $amount, string $annualRate, int $months): string
    {
        return self::round((new Number($amount))->mul($annualRate)->mul($months)->div(1200, self::SCALE));
    }

    /**
     * What the loan costs per month, for the affordability check: M for
     * MONTHLY, total repayable / months (rounded) for SINGLE.
     *
     * @param  array{repayment_plan: string, monthly_instalment: ?string, total_repayable: string, term_months: int}  $loan
     */
    public static function monthlyRepayment(array $loan): string
    {
        return $loan['repayment_plan'] === self::MONTHLY
            ? self::money(new Number($loan['monthly_instalment']))
            : self::round((new Number($loan['total_repayable']))->div($loan['term_months'], self::SCALE));
    }

    /**
     * The monthly repayment as a percentage of monthly income (1 decimal),
     * flagged above $warningPercent. A warning only, never a rejection.
     *
     * @return array{monthly_repayment: string, monthly_income: string, percent_of_income: string, warning_percent: string, above_warning: bool}
     */
    public static function affordability(string $monthlyRepayment, string $monthlyIncome, string $warningPercent): array
    {
        $percent = (new Number($monthlyRepayment))->mul(100)->div($monthlyIncome, self::SCALE);

        return [
            'monthly_repayment' => self::money(new Number($monthlyRepayment)),
            'monthly_income' => self::money(new Number($monthlyIncome)),
            'percent_of_income' => bcadd((string) $percent->round(1, RoundingMode::HalfAwayFromZero), '0', 1),
            'warning_percent' => (string) $warningPercent,
            'above_warning' => $percent->compare($warningPercent) > 0,
        ];
    }

    /** @return list<array{instalment_number: int, due_date: string, principal: string, interest: string, amount: string, balance_after: string}> */
    private static function monthlySchedule(string $amount, string $annualRate, int $months, CarbonInterface $start): array
    {
        $instalment = new Number(self::monthlyInstalment($amount, $annualRate, $months));
        $balance = new Number($amount);
        $rows = [];

        for ($i = 1; $i <= $months; $i++) {
            $interest = new Number(self::monthlyInterest(self::money($balance), $annualRate));
            $principal = $i === $months ? $balance : $instalment->sub($interest);
            $balance = $balance->sub($principal);

            $rows[] = self::row($i, $start->copy()->addMonthsNoOverflow($i), $principal, $interest, $balance);
        }

        return $rows;
    }

    /** @return list<array{instalment_number: int, due_date: string, principal: string, interest: string, amount: string, balance_after: string}> */
    private static function singleSchedule(string $amount, string $annualRate, int $months, CarbonInterface $start): array
    {
        $interest = new Number(self::simpleInterest($amount, $annualRate, $months));

        return [self::row(1, $start->copy()->addMonthsNoOverflow($months), new Number($amount), $interest, new Number('0'))];
    }

    /** @return array{instalment_number: int, due_date: string, principal: string, interest: string, amount: string, balance_after: string} */
    private static function row(int $number, CarbonInterface $dueDate, Number $principal, Number $interest, Number $balanceAfter): array
    {
        return [
            'instalment_number' => $number,
            'due_date' => $dueDate->toDateString(),
            'principal' => self::money($principal),
            'interest' => self::money($interest),
            'amount' => self::money($principal->add($interest)),
            'balance_after' => self::money($balanceAfter),
        ];
    }

    private static function round(Number $value): string
    {
        return self::money($value->round(2, RoundingMode::HalfAwayFromZero));
    }

    /** "1000" -> "1000.00". Only called on values that already have at most 2 decimals. */
    private static function money(Number $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}

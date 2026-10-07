<?php

use App\Models\Branch;
use App\Support\Amortisation;
use App\Support\LoanRepayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * LoanRepayment (PHP, the quote the customer sees) and sp_repay_loan (SQL, the
 * amount actually taken) must agree to the cent. For a grid of loans back-dated
 * to awkward disbursement dates, each loan is paid with the PHP figures: the
 * procedure answers 409 if its own amount differs, and on success the stored
 * payment and instalment rows must equal the quote exactly.
 *
 * DatabaseTruncation, not a rolled-back transaction: the procedure's START
 * TRANSACTION would commit a test-wrapping one. "Today" is the database's
 * UTC_DATE(), passed to the PHP quote, because the procedure's clock can't be frozen.
 *
 * Rate 0 is not in the grid: loan_type, loan and sp_disburse_loan all require
 * interest above zero (chk_loan_type_interest_rate, chk_loan_totals), so 0.01 is the lowest.
 */
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->beforeEach(fn () => $this->seed());

const PARITY_PRINCIPALS = ['1000.00', '1234.57', '9999.99', '250000.00'];
const PARITY_RATES = ['0.01', '12.00', '17.50', '18.00'];
const PARITY_TERMS = [1, 3, 6, 12];

/** The most recent $monthDay (e.g. "01-31") on or before $today, in a year where that date exists. */
function lastCalendarDate(CarbonImmutable $today, string $monthDay): CarbonImmutable
{
    for ($year = (int) $today->year; ; $year--) {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', "{$year}-{$monthDay}", 'UTC');
        if ($date !== false && $date->format('m-d') === $monthDay && $date->lessThanOrEqualTo($today)) {
            return $date;
        }
    }
}

/** The disbursement date for a named scenario, relative to the database's today. */
function parityStart(string $scenario, CarbonImmutable $today): CarbonImmutable
{
    return match ($scenario) {
        'disbursed today' => $today,
        'mid-month' => $today->subDays(15),
        'exactly one month ago' => $today->subMonthsNoOverflow(1),
        'exactly two months ago' => $today->subMonthsNoOverflow(2),
        'one month and a day ago' => $today->subMonthsNoOverflow(1)->subDay(),
        'a day short of a month ago' => $today->subMonthsNoOverflow(1)->addDay(),
        'past maturity' => $today->subMonthsNoOverflow(14),
        'on 31 Jan' => lastCalendarDate($today, '01-31'),
        'on 30 Jan' => lastCalendarDate($today, '01-30'),
        'on 28 Feb' => lastCalendarDate($today, '02-28'),
        'on 29 Feb' => lastCalendarDate($today, '02-29'),
        'on 1 Mar' => lastCalendarDate($today, '03-01'),
    };
}

/** Pays $loan with the PHP quote and checks the stored payment equals it. */
function payAndCompare(object $loan, string $type, object $staff, string $label): void
{
    $quote = LoanRepayment::quote($loan, instalmentsOf($loan), dbToday());
    $expected = $type === LoanRepayment::INSTALMENT
        ? ['amount' => $quote['next_instalment']['amount'], 'instalment' => $quote['next_instalment']['instalment_number']]
        : ['amount' => $quote['payoff']['amount'], 'instalment' => null];

    try {
        $result = DB::select('CALL sp_repay_loan(?, ?, ?, ?, ?, ?, ?)', [
            $loan->loan_id, $type, 'BRANCH', null, $expected['instalment'], $expected['amount'], $staff->user_id,
        ])[0];
    } catch (QueryException $e) {
        test()->fail("{$label}: sp_repay_loan refused PHP's {$type} amount {$expected['amount']}: {$e->getMessage()}");
    }

    $payment = DB::table('loan_payment')->where('loan_payment_id', $result->loan_payment_id)->first();
    $rows = DB::table('loan_instalment')->where('loan_payment_id', $payment->loan_payment_id)->orderBy('instalment_number')
        ->get(['instalment_number', 'amount_paid', 'interest_waived'])
        ->map(fn ($row) => (array) $row)->all();

    if ($type === LoanRepayment::INSTALMENT) {
        $next = $quote['next_instalment'];
        expect([$label, $payment->payment_amount, $payment->interest_charged, $payment->interest_waived, $rows])->toBe([
            $label, $next['amount'], $next['interest'], '0.00',
            [['instalment_number' => $next['instalment_number'], 'amount_paid' => $next['amount'], 'interest_waived' => '0.00']],
        ]);
    } else {
        $payoff = $quote['payoff'];
        expect([$label, $payment->payment_amount, $payment->principal_paid, $payment->interest_charged, $payment->interest_waived, $rows])->toBe([
            $label, $payoff['amount'], $payoff['principal'], $payoff['interest_charged'], $payoff['interest_waived'], $payoff['rows'],
        ]);
    }
}

it('agrees with sp_repay_loan to the cent', function (string $plan, string $scenario) {
    $customer = bankCustomer();
    $account = accountFor($customer, '0.00');
    $staff = staffAtBranch(Branch::find($account->branch_id));
    $start = parityStart($scenario, dbToday());
    $checked = 0;

    foreach (PARITY_RATES as $rate) {
        $type = loanType("Rate {$rate}", $rate);

        foreach (PARITY_PRINCIPALS as $principal) {
            foreach (PARITY_TERMS as $term) {
                if (Amortisation::quote($principal, $rate, $term, $plan, $start)['total_interest'] === '0.00') {
                    continue; // Can't exist: chk_loan_totals requires interest.
                }

                $label = "{$plan} {$principal} at {$rate}% for {$term} months, disbursed {$start->toDateString()}, today ".dbToday()->toDateString();
                $types = $plan === Amortisation::MONTHLY ? LoanRepayment::TYPES : [LoanRepayment::EARLY_PAYOFF];

                foreach ($types as $paymentType) {
                    $loan = activeLoanFor($customer, $account, [
                        'loan_type_id' => $type->loan_type_id, 'loan_amount' => $principal, 'loan_term_months' => $term, 'repayment_plan' => $plan,
                    ], $start);

                    payAndCompare($loan, $paymentType, $staff, "{$label}, {$paymentType}");
                    $checked++;
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(50);
})->with(['MONTHLY', 'SINGLE'])->with([
    'disbursed today', 'mid-month', 'exactly one month ago', 'exactly two months ago', 'one month and a day ago',
    'a day short of a month ago', 'past maturity', 'on 31 Jan', 'on 30 Jan', 'on 28 Feb', 'on 29 Feb', 'on 1 Mar',
]);

it('rounds half away from zero, like MySQL ROUND() on DECIMAL', function (string $value) {
    // monthlyInterest(v × 1200, 1) is round(v, 2) through Amortisation's rounding helper.
    $php = Amortisation::monthlyInterest(bcmul($value, '1200', 10), '1');
    $mysql = DB::selectOne('SELECT CAST(ROUND(CAST(? AS DECIMAL(30,10)), 2) AS CHAR) AS r', [$value])->r;

    expect($php)->toBe($mysql);
})->with(['0.005', '0.015', '0.025', '2.675', '1234.565', '0.0049999', '0.0050001', '18.004125', '99.995', '1.115']);

it('clamps month ends like MySQL DATE_ADD', function () {
    $mismatches = [];

    foreach ([2027, 2028] as $year) {
        foreach (range(1, 12) as $month) {
            foreach ([28, 29, 30, 31] as $day) {
                if (! checkdate($month, $day, $year)) {
                    continue;
                }
                $start = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
                $placeholders = implode(', ', array_map(fn ($k) => "CAST(DATE_ADD(?, INTERVAL {$k} MONTH) AS CHAR) AS k{$k}", range(1, 12)));
                $mysql = (array) DB::selectOne("SELECT {$placeholders}", array_fill(0, 12, $start->toDateString()));

                foreach (range(1, 12) as $k) {
                    $php = $start->addMonthsNoOverflow($k)->toDateString();
                    if ($php !== $mysql["k{$k}"]) {
                        $mismatches[] = "{$start->toDateString()} + {$k}: PHP {$php}, MySQL {$mysql["k{$k}"]}";
                    }
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

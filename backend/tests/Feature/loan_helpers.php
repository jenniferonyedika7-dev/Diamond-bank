<?php

use App\Models\Loan;
use App\Models\LoanType;
use App\Models\User;
use App\Queries\LoanDirectory;
use App\Support\Amortisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function loanType(string $name = 'Personal', string $rate = '12.00'): LoanType
{
    return LoanType::firstOrCreate(['type_name' => $name], ['interest_rate' => $rate]);
}

/** A valid application body for POST /api/v1/customer/loans. */
function loanApplication(object $account, array $overrides = []): array
{
    return array_replace_recursive([
        'loan_type_id' => loanType()->loan_type_id,
        'account_number' => $account->account_number,
        'amount' => '10000.00',
        'term_months' => 12,
        'repayment_plan' => 'MONTHLY',
        'purpose_category' => 'BUSINESS',
        'purpose_description' => 'Stock for my shop',
        'monthly_income' => '25000.00',
        'tin' => '123456789',
        'employment_type' => 'EMPLOYED',
        'employer_name' => 'Gambia Ports Authority',
        'workplace_address' => 'Wharf Road, Banjul',
        'employer_phone' => '+220 4227266',
        'job_title' => 'Clerk',
        'guarantor' => [
            'full_name' => 'Fatou Sowe',
            'phone' => '+220 7012345',
            'occupation' => 'Teacher',
            'address' => '12 Kairaba Avenue, Serrekunda',
            'email' => 'fatou.sowe@example.test',
        ],
    ], $overrides);
}

/**
 * Inserts a loan directly (test setup), quoted with Amortisation like the apply
 * endpoint, with its application and guarantor rows. $overrides go on the loan row.
 */
function loanFor(User $customer, object $account, array $overrides = []): object
{
    $type = isset($overrides['loan_type_id']) ? LoanType::findOrFail($overrides['loan_type_id']) : loanType();
    $quote = Amortisation::quote(
        $overrides['loan_amount'] ?? '10000.00',
        (string) $type->interest_rate,
        $overrides['loan_term_months'] ?? 12,
        $overrides['repayment_plan'] ?? 'MONTHLY',
        now()->startOfDay(),
    );

    $loanId = DB::table('loan')->insertGetId(array_merge([
        'customer_id' => $customer->customer_id,
        'branch_id' => $account->branch_id,
        'loan_type_id' => $type->loan_type_id,
        'account_id' => $account->account_id,
        'loan_amount' => $quote['amount'],
        'interest_rate' => $quote['interest_rate'],
        'loan_term_months' => $quote['term_months'],
        'repayment_plan' => $quote['repayment_plan'],
        'monthly_instalment' => $quote['monthly_instalment'],
        'total_interest' => $quote['total_interest'],
        'total_repayable' => $quote['total_repayable'],
        'status' => 'PENDING',
    ], $overrides), 'loan_id');

    DB::table('loan_application')->insert([
        'loan_id' => $loanId,
        'purpose_category' => 'PERSONAL',
        'purpose_description' => 'School fees',
        'monthly_income' => '25000.00',
        'tin' => '123456789',
        'employment_type' => 'OTHER',
        'employment_description' => 'Farmer',
        'contact_phone' => '7000000',
        'contact_email' => 'me@example.test',
    ]);
    DB::table('loan_guarantor')->insert([
        'loan_id' => $loanId,
        'full_name' => 'Fatou Sowe',
        'phone' => '+220 7012345',
        'occupation' => 'Teacher',
        'address' => '12 Kairaba Avenue, Serrekunda',
        'email' => 'fatou.sowe@example.test',
    ]);

    return DB::table('loan')->where('loan_id', $loanId)->first();
}

/** A loan with the staff approval done, waiting for an admin. */
function awaitingAdminLoan(User $customer, object $account, User $staff, array $overrides = []): object
{
    return loanFor($customer, $account, [
        'status' => 'AWAITING_ADMIN',
        'staff_approved_by' => $staff->employee_id,
        'staff_approved_at' => now(),
        ...$overrides,
    ]);
}

function recordChecks(object $loan, User $staff, array $checks = Loan::CHECKS): void
{
    foreach ($checks as $check) {
        DB::table('loan_verification')->insert([
            'loan_id' => $loan->loan_id,
            'check_type' => $check,
            'note' => "{$check} checked",
            'verified_by' => $staff->employee_id,
            'verified_at' => now(),
        ]);
    }
}

function loanRow(object $loan): object
{
    return DB::table('loan')->where('loan_id', $loan->loan_id)->first();
}

/** The schedule an admin approval would send today. */
function scheduleFor(object $loan): array
{
    return Amortisation::quote($loan->loan_amount, $loan->interest_rate, $loan->loan_term_months, $loan->repayment_plan, now('UTC')->startOfDay())['schedule'];
}

function expectProcedureError(string $sqlState, string $message, callable $call): void
{
    try {
        $call();
    } catch (QueryException $e) {
        expect((string) $e->getCode())->toBe($sqlState)
            ->and($e->getMessage())->toContain($message);

        return;
    }

    test()->fail("Expected SQLSTATE {$sqlState}: {$message}");
}

/** Today as the database sees it (UTC_DATE()), which sp_repay_loan uses. */
function dbToday(): CarbonImmutable
{
    return CarbonImmutable::parse(DB::selectOne('SELECT UTC_DATE() AS today')->today, 'UTC');
}

/**
 * An ACTIVE loan disbursed on $disbursedOn (default: today), with the schedule
 * sp_disburse_loan would have written that day: back-date it to make rows overdue.
 * $paid instalments are marked PAID as if paid on their due dates (no loan_payment row).
 */
function activeLoanFor(User $customer, object $account, array $overrides = [], ?CarbonImmutable $disbursedOn = null, int $paid = 0): object
{
    $disbursedOn ??= dbToday();
    $loan = loanFor($customer, $account, [
        'status' => 'ACTIVE',
        'approval_date' => $disbursedOn->setTime(10, 30),
        ...$overrides,
    ]);

    foreach (Amortisation::quote($loan->loan_amount, $loan->interest_rate, $loan->loan_term_months, $loan->repayment_plan, $disbursedOn)['schedule'] as $row) {
        $isPaid = $row['instalment_number'] <= $paid;
        DB::table('loan_instalment')->insert([
            'loan_id' => $loan->loan_id,
            ...$row,
            'status' => $isPaid ? 'PAID' : 'UNPAID',
            'amount_paid' => $isPaid ? $row['amount'] : null,
            'interest_waived' => $isPaid ? '0.00' : null,
            'paid_at' => $isPaid ? $row['due_date'] : null,
        ]);
    }

    return loanRow($loan);
}

/** @return list<object> the loan's instalments, in order */
function instalmentsOf(object $loan): array
{
    return DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->orderBy('instalment_number')->get()->all();
}

/** The quote the screens would show for this loan right now. */
function repaymentQuoteFor(object $loan): array
{
    return LoanDirectory::repaymentQuote(loanRow($loan));
}

<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * sp_disburse_loan called directly. DatabaseTruncation (not RefreshDatabase):
 * the procedure's START TRANSACTION would implicitly commit a test-wrapping transaction.
 */
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->beforeEach(fn () => $this->seed());

beforeEach(function () {
    $this->customer = bankCustomer();
    $this->account = accountFor($this->customer, '1000.00');
    $this->staff = staffAtBranch(Branch::find($this->account->branch_id));
    $this->admin = User::factory()->admin()->create();
});

function disburse(object $loan, array $schedule, User $by): object
{
    return DB::select('CALL sp_disburse_loan(?, ?, ?)', [$loan->loan_id, json_encode($schedule), $by->user_id])[0];
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

/** Nothing moved: balance, transactions, instalments and loan status are as before. */
function expectNotDisbursed(object $loan, object $account): void
{
    expect(balanceOfAccount($account))->toBe($account->balance)
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('loan_instalment')->count())->toBe(0)
        ->and(loanRow($loan)->status)->toBe($loan->status);
}

it('disburses a monthly loan: balance, transaction, schedule, loan row and audit', function () {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);
    $schedule = scheduleFor($loan);

    $result = disburse($loan, $schedule, $this->admin);

    expect((string) $result->balance_after)->toBe('11000.00')
        ->and($result->account_number)->toBe($this->account->account_number)
        ->and(balanceOfAccount($this->account))->toBe('11000.00');

    $transaction = DB::table('transactions')->where('transaction_id', $result->transaction_id)->first();
    expect($transaction)->toMatchObject([
        'account_id' => $this->account->account_id,
        'transaction_type_id' => DB::table('transaction_type')->where('type_name', 'LOAN_DISBURSEMENT')->value('transaction_type_id'),
        'branch_id' => $this->account->branch_id,
        'employee_id' => $this->admin->employee_id,
        'amount' => '10000.00',
        'channel' => 'BRANCH',
        'balance_after' => '11000.00',
        'description' => "Loan #{$loan->loan_id} disbursement",
    ]);

    $stored = DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->orderBy('instalment_number')
        ->get(['instalment_number', 'due_date', 'principal', 'interest', 'amount', 'balance_after'])
        ->map(fn ($row) => (array) $row)->all();
    expect($stored)->toBe($schedule)
        ->and(DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->where('status', 'UNPAID')->count())->toBe(12);

    expect(loanRow($loan))->toMatchObject([
        'status' => 'ACTIVE',
        'approved_by' => $this->admin->employee_id,
        'staff_approved_by' => $this->staff->employee_id,
        'disbursement_transaction_id' => $result->transaction_id,
    ])->and(loanRow($loan)->approval_date)->not->toBeNull();

    $audit = DB::table('audit_log')->where('action_type', 'LOAN_APPROVED_ADMIN')->sole();
    expect($audit->user_id)->toBe($this->admin->user_id)
        ->and($audit->record_id)->toBe($loan->loan_id)
        ->and(json_decode($audit->details, true))->toMatchArray([
            'before' => ['status' => 'AWAITING_ADMIN'], 'after' => ['status' => 'ACTIVE'],
            'account_number' => $this->account->account_number, 'transaction_id' => $result->transaction_id,
            'repayment_plan' => 'MONTHLY',
        ]);
});

it('disburses a single-payment loan with one instalment at the end of the term', function () {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff, ['repayment_plan' => 'SINGLE', 'loan_term_months' => 6]);

    disburse($loan, scheduleFor($loan), $this->admin);

    $row = DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->sole();
    expect($row)->toMatchObject([
        'instalment_number' => 1,
        'due_date' => now('UTC')->addMonthsNoOverflow(6)->toDateString(),
        'principal' => '10000.00',
        'interest' => '600.00',
        'amount' => '10600.00',
        'status' => 'UNPAID',
    ])->and(loanRow($loan)->status)->toBe('ACTIVE');
});

it('signals 45001 when the loan is no longer awaiting admin approval', function (string $status) {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff, ['status' => $status]);

    expectProcedureError('45001', 'This loan is no longer awaiting approval.', fn () => disburse($loan, scheduleFor($loan), $this->admin));
    expectNotDisbursed($loan, $this->account);
})->with(['PENDING', 'CANCELLED', 'REJECTED']);

it('signals 45001 on a second approval and pays out only once', function () {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);
    disburse($loan, scheduleFor($loan), $this->admin);

    expectProcedureError('45001', 'no longer awaiting approval', fn () => disburse($loan, scheduleFor($loan), User::factory()->admin()->create()));

    expect(balanceOfAccount($this->account))->toBe('11000.00')
        ->and(DB::table('transactions')->count())->toBe(1)
        ->and(DB::table('loan_instalment')->count())->toBe(12);
});

it('accepts only an admin performer', function () {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);

    expectProcedureError('45000', 'Only an administrator can give the final loan approval.', fn () => disburse($loan, scheduleFor($loan), $this->staff));
    expectProcedureError('45000', 'Only an administrator', fn () => disburse($loan, scheduleFor($loan), $this->customer));
    expectNotDisbursed($loan, $this->account);
});

it('refuses when the admin also made the staff approval', function () {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff, ['staff_approved_by' => $this->admin->employee_id]);

    expectProcedureError('45000', "The same person can't make both approvals.", fn () => disburse($loan, scheduleFor($loan), $this->admin));
    expectNotDisbursed($loan, $this->account);
});

it('re-checks the account, KYC and login at disbursement', function (Closure $break, string $message) {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);
    $break($this);

    expectProcedureError('45000', $message, fn () => disburse($loan, scheduleFor($loan), $this->admin));
    expectNotDisbursed($loan, $this->account);
})->with([
    'frozen account' => [fn ($t) => DB::table('account')->where('account_id', $t->account->account_id)->update(['status' => 'FROZEN']), 'The disbursement account is frozen.'],
    'closed account' => [fn ($t) => DB::table('account')->where('account_id', $t->account->account_id)->update(['status' => 'CLOSED']), 'The disbursement account is closed.'],
    'KYC not verified' => [fn ($t) => DB::table('customer')->where('customer_id', $t->customer->customer_id)->update(['kyc_status' => 'REJECTED']), 'The customer is not KYC verified.'],
    'blocked login' => [fn ($t) => DB::table('users')->where('user_id', $t->customer->user_id)->update(['status' => 'BLOCKED']), "The customer's login is not active."],
]);

it('rejects a tampered monthly schedule', function (Closure $tamper) {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);
    $schedule = $tamper(scheduleFor($loan));

    expectProcedureError('45000', 'The repayment schedule is invalid.', fn () => disburse($loan, $schedule, $this->admin));
    expectNotDisbursed($loan, $this->account);
})->with([
    'zero interest' => [fn (array $s) => array_map(fn ($r) => ['interest' => '0.00', 'amount' => $r['principal']] + $r, $s)],
    'wrong interest on one row' => [function (array $s) {
        $s[3]['interest'] = bcadd($s[3]['interest'], '0.01', 2);
        $s[3]['amount'] = bcadd($s[3]['amount'], '0.01', 2);

        return $s;
    }],
    // Amounts, totals and the balance chain all still add up; only the per-row interest check catches it.
    'interest moved between rows' => [function (array $s) {
        $s[0]['interest'] = bcadd($s[0]['interest'], '1', 2);
        $s[0]['principal'] = bcsub($s[0]['principal'], '1', 2);
        $s[0]['balance_after'] = bcadd($s[0]['balance_after'], '1', 2);
        $s[1]['interest'] = bcsub($s[1]['interest'], '1', 2);
        $s[1]['principal'] = bcadd($s[1]['principal'], '1', 2);

        return $s;
    }],
    'a middle instalment that is not the monthly amount' => [function (array $s) {
        $s[4]['principal'] = bcadd($s[4]['principal'], '1', 2);
        $s[4]['amount'] = bcadd($s[4]['amount'], '1', 2);
        $s[4]['balance_after'] = bcsub($s[4]['balance_after'], '1', 2);
        $s[11]['principal'] = bcsub($s[11]['principal'], '1', 2);
        $s[11]['amount'] = bcsub($s[11]['amount'], '1', 2);

        return $s;
    }],
    'one instalment missing' => [fn (array $s) => array_slice($s, 0, 11)],
    'an extra instalment' => [fn (array $s) => [...$s, ['instalment_number' => 13] + $s[11]]],
    'duplicate numbers' => [function (array $s) {
        $s[1]['instalment_number'] = 1;

        return $s;
    }],
    'principal does not add up to the loan' => [function (array $s) {
        $s[11]['principal'] = bcadd($s[11]['principal'], '5', 2);
        $s[11]['amount'] = bcadd($s[11]['amount'], '5', 2);

        return $s;
    }],
    'broken balance chain' => [function (array $s) {
        $s[2]['balance_after'] = bcadd($s[2]['balance_after'], '1', 2);

        return $s;
    }],
    'wrong due date' => [function (array $s) {
        $s[0]['due_date'] = now('UTC')->addMonthNoOverflow()->addDay()->toDateString();

        return $s;
    }],
    'more than 2 decimals' => [function (array $s) {
        $s[0]['interest'] = $s[0]['interest'].'1';

        return $s;
    }],
    'a missing field' => [function (array $s) {
        unset($s[5]['interest']);

        return $s;
    }],
    'not a list' => [fn (array $s) => ['schedule' => $s]],
]);

it('rejects a tampered single-payment schedule', function (Closure $tamper) {
    $loan = awaitingAdminLoan($this->customer, $this->account, $this->staff, ['repayment_plan' => 'SINGLE', 'loan_term_months' => 6]);
    $schedule = $tamper(scheduleFor($loan));

    expectProcedureError('45000', 'The repayment schedule is invalid.', fn () => disburse($loan, $schedule, $this->admin));
    expectNotDisbursed($loan, $this->account);
})->with([
    'zero interest' => [fn (array $s) => [['interest' => '0.00', 'amount' => '10000.00'] + $s[0]]],
    'wrong interest' => [fn (array $s) => [['interest' => '599.99', 'amount' => '10599.99'] + $s[0]]],
    'two instalments' => [fn (array $s) => [
        ['principal' => '5000.00', 'interest' => '300.00', 'amount' => '5300.00', 'balance_after' => '5000.00'] + $s[0],
        ['instalment_number' => 2, 'principal' => '5000.00', 'interest' => '300.00', 'amount' => '5300.00'] + $s[0],
    ]],
    'due after one month instead of at the end of the term' => [fn (array $s) => [['due_date' => now('UTC')->addMonthNoOverflow()->toDateString()] + $s[0]]],
]);

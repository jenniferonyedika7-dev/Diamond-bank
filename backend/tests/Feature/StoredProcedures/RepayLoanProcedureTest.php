<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * sp_repay_loan called directly. DatabaseTruncation (not RefreshDatabase):
 * the procedure's START TRANSACTION would implicitly commit a test-wrapping transaction.
 * The procedure uses UTC_DATE(), so loans are back-dated rather than the clock frozen.
 * Unless a test says otherwise, loans are 10,000 at 12% over 12 months, MONTHLY:
 * 888.49 a month, interest 100.00 in month 1 and 661.86 in all.
 */
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->beforeEach(fn () => $this->seed());

beforeEach(function () {
    $this->customer = bankCustomer();
    $this->account = accountFor($this->customer, '20000.00');
    DB::table('account_type')->where('account_type_id', $this->account->account_type_id)->update(['minimum_balance' => 500]);
    $this->staff = staffAtBranch(Branch::find($this->account->branch_id));
});

function repay(object $loan, string $type, string $channel, ?object $account, ?int $instalment, string $amount, User $by): object
{
    return DB::select('CALL sp_repay_loan(?, ?, ?, ?, ?, ?, ?)', [
        $loan->loan_id, $type, $channel, $account?->account_id, $instalment, $amount, $by->user_id,
    ])[0];
}

/** Nothing moved: balance, transactions, payments, instalments and loan status are as before. */
function expectNothingPaid(object $loan, object $account): void
{
    expect(balanceOfAccount($account))->toBe($account->balance)
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('loan_payment')->count())->toBe(0)
        ->and(DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->whereNotNull('loan_payment_id')->count())->toBe(0)
        ->and(loanRow($loan)->status)->toBe($loan->status);
}

function auditDetails(string $action, int $recordId): array
{
    $row = DB::table('audit_log')->where('action_type', $action)->where('record_id', $recordId)->sole();

    return json_decode($row->details, true);
}

it('pays the next instalment online: balance, transaction, payment, instalment and audit', function () {
    $loan = activeLoanFor($this->customer, $this->account);

    $result = repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->customer);

    expect((string) $result->amount)->toBe('888.49')
        ->and((string) $result->balance_after)->toBe('19111.51')
        ->and((string) $result->remaining_balance)->toBe('9773.37')
        ->and($result->loan_status)->toBe('ACTIVE')
        ->and(balanceOfAccount($this->account))->toBe('19111.51');

    $transaction = DB::table('transactions')->sole();
    expect($transaction->transaction_type_id)->toBe(DB::table('transaction_type')->where('type_name', 'LOAN_PAYMENT')->value('transaction_type_id'))
        ->and($transaction->account_id)->toBe($this->account->account_id)
        ->and($transaction->amount)->toBe('888.49')
        ->and($transaction->channel)->toBe('ONLINE')
        ->and($transaction->balance_after)->toBe('19111.51')
        ->and($transaction->branch_id)->toBeNull()
        ->and($transaction->employee_id)->toBeNull()
        ->and($transaction->description)->toBe("Loan #{$loan->loan_id} repayment");

    $payment = DB::table('loan_payment')->sole();
    expect($payment)->toMatchObject([
        'loan_payment_id' => $result->loan_payment_id, 'loan_id' => $loan->loan_id, 'branch_id' => $loan->branch_id,
        'payment_type' => 'INSTALMENT', 'channel' => 'ONLINE', 'payment_amount' => '888.49', 'principal_paid' => '788.49',
        'interest_charged' => '100.00', 'interest_waived' => '0.00', 'remaining_balance' => '9773.37', 'status' => 'COMPLETED',
        'transaction_id' => $transaction->transaction_id, 'account_id' => $this->account->account_id,
        'paid_by' => $this->customer->user_id, 'received_by' => null,
    ]);

    [$first, $second] = instalmentsOf($loan);
    expect($first)->toMatchObject(['status' => 'PAID', 'amount_paid' => '888.49', 'interest_waived' => '0.00', 'loan_payment_id' => $payment->loan_payment_id])
        ->and($first->paid_at)->not->toBeNull()
        ->and($second)->toMatchObject(['status' => 'UNPAID', 'amount_paid' => null, 'loan_payment_id' => null]);

    expect(auditDetails('LOAN_PAYMENT', $payment->loan_payment_id))->toMatchArray([
        'loan_id' => $loan->loan_id, 'payment_type' => 'INSTALMENT', 'channel' => 'ONLINE', 'amount' => 888.49,
        'interest_charged' => 100, 'interest_waived' => 0, 'instalments' => [1],
        'before' => ['status' => 'UNPAID'], 'after' => ['status' => 'PAID'],
        'account_number' => $this->account->account_number, 'balance_before' => 20000, 'balance_after' => 19111.51,
        'remaining_balance' => 9773.37,
    ]);
});

it('pays everything off online: rows SETTLED, interest waived, loan CLOSED', function () {
    $loan = activeLoanFor($this->customer, $this->account);

    $result = repay($loan, 'EARLY_PAYOFF', 'ONLINE', $this->account, null, '10100.00', $this->customer);

    expect($result->loan_status)->toBe('CLOSED')
        ->and((string) $result->interest_charged)->toBe('100.00')
        ->and((string) $result->interest_waived)->toBe('561.86')
        ->and(balanceOfAccount($this->account))->toBe('9900.00');

    $payment = DB::table('loan_payment')->sole();
    expect($payment)->toMatchObject([
        'payment_type' => 'EARLY_PAYOFF', 'payment_amount' => '10100.00', 'principal_paid' => '10000.00',
        'interest_charged' => '100.00', 'interest_waived' => '561.86', 'remaining_balance' => '0.00',
    ]);

    $rows = instalmentsOf($loan);
    expect(collect($rows)->pluck('status')->unique()->all())->toBe(['SETTLED'])
        ->and(collect($rows)->pluck('loan_payment_id')->unique()->all())->toBe([$payment->loan_payment_id])
        ->and($rows[0])->toMatchObject(['amount_paid' => '888.49', 'interest_waived' => '0.00'])
        ->and($rows[1])->toMatchObject(['amount_paid' => '796.37', 'interest_waived' => '92.12'])
        ->and($rows[11])->toMatchObject(['amount_paid' => '879.67', 'interest_waived' => '8.80']);

    $closed = loanRow($loan);
    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull();

    expect(auditDetails('LOAN_CLOSED', $loan->loan_id))->toMatchArray([
        'before' => ['status' => 'ACTIVE'], 'after' => ['status' => 'CLOSED'], 'loan_payment_id' => $payment->loan_payment_id,
    ])
        ->and(auditDetails('LOAN_PAYMENT', $payment->loan_payment_id))->toMatchArray([
            'instalments' => range(1, 12), 'after' => ['status' => 'SETTLED'], 'interest_waived' => 561.86,
        ]);
});

it('records a cash instalment at the branch: no transaction, the employee who received it', function () {
    $loan = activeLoanFor($this->customer, $this->account);

    $result = repay($loan, 'INSTALMENT', 'BRANCH', null, 1, '888.49', $this->staff);

    expect($result->transaction_id)->toBeNull()
        ->and($result->balance_after)->toBeNull()
        ->and(balanceOfAccount($this->account))->toBe('20000.00')
        ->and(DB::table('transactions')->count())->toBe(0);

    expect(DB::table('loan_payment')->sole())->toMatchObject([
        'channel' => 'BRANCH', 'branch_id' => $loan->branch_id, 'payment_amount' => '888.49',
        'transaction_id' => null, 'account_id' => null, 'paid_by' => null, 'received_by' => $this->staff->employee_id,
    ])
        ->and(instalmentsOf($loan)[0]->status)->toBe('PAID')
        ->and(auditDetails('LOAN_PAYMENT', $result->loan_payment_id))->toMatchArray(['channel' => 'BRANCH', 'received_by' => $this->staff->employee_id]);
});

it('records a cash payoff of a SINGLE loan, charging only the months started', function () {
    // Disbursed one month and one day ago: month 2 has started. 12% a year on 10,000 is 100.00 a month.
    $loan = activeLoanFor($this->customer, $this->account, ['repayment_plan' => 'SINGLE'], dbToday()->subMonthsNoOverflow(1)->subDay());

    $result = repay($loan, 'EARLY_PAYOFF', 'BRANCH', null, null, '10200.00', $this->staff);

    expect($result->loan_status)->toBe('CLOSED')
        ->and(DB::table('loan_payment')->sole())->toMatchObject([
            'payment_amount' => '10200.00', 'principal_paid' => '10000.00', 'interest_charged' => '200.00', 'interest_waived' => '1000.00',
        ])
        ->and(instalmentsOf($loan)[0])->toMatchObject(['status' => 'SETTLED', 'amount_paid' => '10200.00', 'interest_waived' => '1000.00'])
        ->and(auditDetails('LOAN_PAYMENT', $result->loan_payment_id)['months_charged'])->toBe(2);
});

it('pays the overdue instalment first and refuses to skip ahead', function () {
    $loan = activeLoanFor($this->customer, $this->account, disbursedOn: dbToday()->subMonthsNoOverflow(3)->subDays(5));
    $quote = repaymentQuoteFor($loan);

    expect($quote['overdue_count'])->toBe(3)
        ->and($quote['next_instalment'])->toMatchArray(['instalment_number' => 1, 'overdue' => true]);

    expectProcedureError('45001', 'The amount has changed. Please review it again.',
        fn () => repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 3, '888.49', $this->customer));

    repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->customer);

    expect(collect(instalmentsOf($loan))->pluck('status')->take(3)->all())->toBe(['PAID', 'UNPAID', 'UNPAID'])
        ->and(repaymentQuoteFor($loan)['next_instalment']['instalment_number'])->toBe(2);
});

it('refuses an amount that is no longer what is owed, moving nothing', function (string $type, ?int $instalment, string $amount) {
    $loan = activeLoanFor($this->customer, $this->account);

    expectProcedureError('45001', 'The amount has changed. Please review it again.',
        fn () => repay($loan, $type, 'ONLINE', $this->account, $instalment, $amount, $this->customer));

    expectNothingPaid($loan, $this->account);
})->with([
    'instalment a cent short' => ['INSTALMENT', 1, '888.48'],
    'payoff with the full scheduled interest' => ['EARLY_PAYOFF', null, '10661.86'],
]);

it('refuses a repeated instalment payment (a double submit)', function () {
    $loan = activeLoanFor($this->customer, $this->account);
    repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->customer);

    // Instalment 2 is also 888.49; only the instalment number tells the repeat apart.
    expectProcedureError('45001', 'The amount has changed. Please review it again.',
        fn () => repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->customer));

    expect(DB::table('loan_payment')->count())->toBe(1)
        ->and(balanceOfAccount($this->account))->toBe('19111.51');
});

it('refuses a payment the account cannot cover', function (string $balance, string $minimum) {
    DB::table('account_type')->where('account_type_id', $this->account->account_type_id)->update(['minimum_balance' => $minimum]);
    DB::table('account')->where('account_id', $this->account->account_id)->update(['balance' => $balance]);
    $account = DB::table('account')->where('account_id', $this->account->account_id)->first();
    $loan = activeLoanFor($this->customer, $account);

    expectProcedureError('45000', 'Insufficient funds: this payment would take the account below its minimum balance.',
        fn () => repay($loan, 'INSTALMENT', 'ONLINE', $account, 1, '888.49', $this->customer));

    expectNothingPaid($loan, $account);
})->with([
    'insufficient balance' => ['888.48', '0'],
    'below the minimum balance' => ['1388.48', '500'],
]);

it('allows a payment that leaves exactly the minimum balance', function () {
    DB::table('account')->where('account_id', $this->account->account_id)->update(['balance' => '1388.49']);
    $loan = activeLoanFor($this->customer, $this->account);

    repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->customer);

    expect(balanceOfAccount($this->account))->toBe('500.00');
});

it('refuses frozen, closed and other customers\' accounts', function (string $case, string $message) {
    $loan = activeLoanFor($this->customer, $this->account);
    $account = match ($case) {
        'other' => accountFor(bankCustomer(), '20000.00'),
        default => accountFor($this->customer, '20000.00', ['status' => $case]),
    };

    expectProcedureError('45000', $message, fn () => repay($loan, 'INSTALMENT', 'ONLINE', $account, 1, '888.49', $this->customer));

    expectNothingPaid($loan, $account);
})->with([
    'frozen' => ['FROZEN', 'This account is frozen.'],
    'closed' => ['CLOSED', 'This account is closed.'],
    "someone else's" => ['other', 'Choose one of your own active accounts.'],
]);

it('answers 45001 for a loan that is not ACTIVE', function (string $status) {
    $loan = loanFor($this->customer, $this->account, ['status' => $status]);

    expectProcedureError('45001', 'This loan is not active.',
        fn () => repay($loan, 'EARLY_PAYOFF', 'BRANCH', null, null, '10100.00', $this->staff));

    expectNothingPaid($loan, $this->account);
})->with(['PENDING', 'AWAITING_ADMIN', 'REJECTED', 'CANCELLED', 'CLOSED']);

it('refuses an instalment payment on a SINGLE loan', function () {
    $loan = activeLoanFor($this->customer, $this->account, ['repayment_plan' => 'SINGLE']);

    expectProcedureError('45000', 'This loan is repaid in one payment. Choose to pay everything now.',
        fn () => repay($loan, 'INSTALMENT', 'BRANCH', null, 1, '11200.00', $this->staff));

    expectNothingPaid($loan, $this->account);
});

it('refuses another customer\'s loan and another branch\'s staff', function () {
    $loan = activeLoanFor($this->customer, $this->account);
    $stranger = bankCustomer();
    $strangerAccount = accountFor($stranger, '20000.00');

    expectProcedureError('45000', 'Loan not found.',
        fn () => repay($loan, 'INSTALMENT', 'ONLINE', $strangerAccount, 1, '888.49', $stranger));
    expectProcedureError('45000', 'This loan is managed at another branch.',
        fn () => repay($loan, 'INSTALMENT', 'BRANCH', null, 1, '888.49', staffAtBranch()));

    expectNothingPaid($loan, $this->account);
});

it('keeps each channel to its own people', function () {
    $loan = activeLoanFor($this->customer, $this->account);

    expectProcedureError('45000', 'Only the customer can pay a loan online.',
        fn () => repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 1, '888.49', $this->staff));
    expectProcedureError('45000', 'Only staff can record a cash payment.',
        fn () => repay($loan, 'INSTALMENT', 'BRANCH', null, 1, '888.49', $this->customer));
    expectProcedureError('45000', 'A cash payment is not taken from an account.',
        fn () => repay($loan, 'INSTALMENT', 'BRANCH', $this->account, 1, '888.49', $this->staff));

    expectNothingPaid($loan, $this->account);
});

it('closes the loan with the last instalment', function () {
    $loan = activeLoanFor($this->customer, $this->account, disbursedOn: dbToday()->subMonthsNoOverflow(11)->subDays(3), paid: 11);

    $result = repay($loan, 'INSTALMENT', 'ONLINE', $this->account, 12, '888.47', $this->customer);

    expect($result->loan_status)->toBe('CLOSED')
        ->and((string) $result->remaining_balance)->toBe('0.00')
        ->and(loanRow($loan)->status)->toBe('CLOSED')
        ->and(loanRow($loan)->closed_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action_type', 'LOAN_CLOSED')->count())->toBe(1);

    expectProcedureError('45001', 'This loan is not active.',
        fn () => repay($loan, 'EARLY_PAYOFF', 'ONLINE', $this->account, null, '888.47', $this->customer));
});

it('charges overdue and current rows in a payoff and waives the rest', function () {
    // Disbursed two months and three days ago: rows 1 and 2 are overdue, row 3 is current.
    $loan = activeLoanFor($this->customer, $this->account, disbursedOn: dbToday()->subMonthsNoOverflow(2)->subDays(3));

    $result = repay($loan, 'EARLY_PAYOFF', 'BRANCH', null, null, '10276.27', $this->staff);

    $rows = instalmentsOf($loan);
    expect((string) $result->interest_charged)->toBe('276.27')
        ->and((string) $result->interest_waived)->toBe('385.59')
        ->and(collect($rows)->take(3)->pluck('interest_waived')->all())->toBe(['0.00', '0.00', '0.00'])
        ->and($rows[3]->interest_waived)->toBe($rows[3]->interest)
        ->and($rows[3]->amount_paid)->toBe($rows[3]->principal);
});

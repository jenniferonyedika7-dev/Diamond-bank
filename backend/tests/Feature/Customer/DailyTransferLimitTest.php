<?php

use App\Models\Branch;
use App\Support\StoredProcedure;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Each account may send at most its type's daily_transfer_limit per day
| (00:00-23:59 Africa/Banjul): Savings 100,000, Current 500,000. Only
| completed transfers out of the account count. sp_transfer_funds enforces
| it under the source account's row lock.
*/

beforeEach(function () {
    $this->me = bankCustomer();
    $this->savings = accountFor($this->me, '300000.00');   // Savings: minimum 500, limit 100,000
    $this->other = bankCustomer();
    $this->theirs = accountFor($this->other, '1000000.00');
    $this->actingAs($this->me);
});

afterEach(fn () => thawClock());

function sendFrom(object $from, object $to, string $amount): TestResponse
{
    return test()->postJson('/api/v1/customer/transfers', [
        'from_account_number' => $from->account_number,
        'to_account_number' => $to->account_number,
        'amount' => $amount,
        'password' => 'password1',
    ]);
}

function limitMessage(string $limit, string $remaining): string
{
    return "This transfer is over the daily transfer limit of GMD {$limit} for this account. Remaining today: GMD {$remaining}.";
}

function remainingToday(object $account): string
{
    return collect(test()->getJson('/api/v1/customer/overview')->assertOk()->json('data.accounts'))
        ->firstWhere('account_number', $account->account_number)['remaining_today'];
}

it('allows a transfer of exactly the daily limit', function () {
    sendFrom($this->savings, $this->theirs, '100000.00')->assertCreated();

    expect(balanceOfAccount($this->savings))->toBe('200000.00')
        ->and(remainingToday($this->savings))->toBe('0.00');
});

it('refuses 0.01 over the limit, says what remains, changes nothing and audits the refusal (committed)', function () {
    sendFrom($this->savings, $this->theirs, '40000.00')->assertCreated();

    sendFrom($this->savings, $this->theirs, '60000.01')
        ->assertUnprocessable()
        ->assertExactJson(['success' => false, 'message' => limitMessage('100,000.00', '60,000.00'), 'data' => null]);

    expect(balanceOfAccount($this->savings))->toBe('260000.00')
        ->and(DB::table('transfer')->count())->toBe(1);

    // Read through a second connection: only committed rows are visible there.
    $audit = committedView()->table('audit_log')->where('action_type', 'TRANSFER_LIMIT_REFUSED')->sole();
    expect($audit->user_id)->toBe($this->me->user_id)
        ->and(json_decode($audit->details, true))->toEqual([
            'from_account_number' => $this->savings->account_number, 'to_account_number' => $this->theirs->account_number,
            'amount' => '60000.01', 'daily_limit' => '100000.00', 'sent_today' => '40000.00', 'remaining_today' => '60000.00',
        ]);
});

it('allows several transfers adding up to exactly the limit, then refuses even 0.01', function () {
    foreach (['25000.00', '30000.50', '44999.49', '0.01'] as $amount) {
        sendFrom($this->savings, $this->theirs, $amount)->assertCreated();
    }

    sendFrom($this->savings, $this->theirs, '0.01')
        ->assertUnprocessable()
        ->assertJsonPath('message', limitMessage('100,000.00', '0.00'));
    expect(balanceOfAccount($this->savings))->toBe('200000.00');
});

it('does not count incoming transfers, deposits, withdrawals, loan payments or refused transfers', function () {
    $staff = staffAtBranch(Branch::find(DB::table('customer')->where('customer_id', $this->me->customer_id)->value('branch_id')));
    $loan = activeLoanFor($this->me, $this->savings);

    // Incoming: 90,000 from someone else's account.
    StoredProcedure::call('sp_transfer_funds', [$this->theirs->account_id, $this->savings->account_number, '90000.00', null, 'ONLINE', $this->other->user_id]);
    // Online loan payment by the customer.
    $this->postJson("/api/v1/customer/loans/{$loan->loan_id}/payments", [
        'payment_type' => 'INSTALMENT', 'instalment_number' => 1, 'amount' => '888.49',
        'account_number' => $this->savings->account_number, 'password' => 'password1',
    ])->assertCreated();
    // Refused: more than the balance allows.
    sendFrom($this->savings, $this->theirs, '9000000.00')->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Insufficient funds'));

    // Staff: a deposit, a withdrawal and a cash loan payment.
    actingFresh($staff)->postJson("/api/v1/staff/accounts/{$this->savings->account_number}/deposit", ['amount' => '120000.00'])->assertOk();
    $this->postJson("/api/v1/staff/accounts/{$this->savings->account_number}/withdraw", ['amount' => '110000.00'])->assertOk();
    $this->postJson("/api/v1/staff/loans/{$loan->loan_id}/payments", ['payment_type' => 'INSTALMENT', 'instalment_number' => 2, 'amount' => '888.49'])->assertCreated();

    // Failed and reversed transfer rows from earlier today.
    foreach (['FAILED', 'REVERSED'] as $status) {
        DB::table('transfer')->insert(['from_account_id' => $this->savings->account_id, 'to_account_id' => $this->theirs->account_id, 'amount' => '50000.00', 'status' => $status]);
    }

    actingFresh($this->me);
    expect(remainingToday($this->savings))->toBe('100000.00');
    sendFrom($this->savings, $this->theirs, '100000.00')->assertCreated();
});

it('starts a new day at midnight Africa/Banjul', function () {
    freezeClock('2026-10-17 23:59:30');
    sendFrom($this->savings, $this->theirs, '100000.00')->assertCreated();

    freezeClock('2026-10-17 23:59:59');
    sendFrom($this->savings, $this->theirs, '0.01')->assertUnprocessable()->assertJsonPath('message', limitMessage('100,000.00', '0.00'));
    expect(remainingToday($this->savings))->toBe('0.00');

    freezeClock('2026-10-18 00:00:00');
    expect(remainingToday($this->savings))->toBe('100000.00');
    sendFrom($this->savings, $this->theirs, '100000.00')->assertCreated();
    expect(DB::table('transfer')->pluck('transfer_date')->all())->toBe(['2026-10-17 23:59:30', '2026-10-18 00:00:00']);
});

it('keeps a separate limit per account, by account type', function () {
    $currentType = DB::table('account_type')->insertGetId(['type_name' => 'Current', 'interest_rate' => 0, 'minimum_balance' => 1000, 'daily_transfer_limit' => '500000.00'], 'account_type_id');
    $current = accountFor($this->me, '600000.00', ['account_type_id' => $currentType]);

    sendFrom($this->savings, $this->theirs, '100000.00')->assertCreated();
    sendFrom($this->savings, $this->theirs, '0.01')->assertUnprocessable();

    expect(remainingToday($current))->toBe('500000.00');
    sendFrom($current, $this->theirs, '500000.00')->assertCreated();
    sendFrom($current, $this->theirs, '0.01')->assertUnprocessable()->assertJsonPath('message', limitMessage('500,000.00', '0.00'));
});

it('shows the limit and what remains today for each account on the overview', function () {
    sendFrom($this->savings, $this->theirs, '12345.67')->assertCreated();

    $account = collect($this->getJson('/api/v1/customer/overview')->assertOk()->json('data.accounts'))->firstWhere('account_number', $this->savings->account_number);
    expect($account)->toMatchArray(['daily_transfer_limit' => '100000.00', 'remaining_today' => '87654.33'])
        ->and($account)->not->toHaveKey('account_id');
});

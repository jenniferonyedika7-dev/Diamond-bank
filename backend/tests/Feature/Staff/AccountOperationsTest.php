<?php

use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->staff = staffAtBranch();
    $this->actingAs($this->staff);
});

it('refuses to open an account for a customer whose KYC is pending, with the procedure\'s message', function () {
    $customer = customerWithLogin('PENDING');

    $this->postJson('/api/v1/staff/accounts', [
        'customer_id' => $customer->customer_id,
        'account_type_id' => savingsType()->account_type_id,
        'initial_deposit' => 500,
    ])
        ->assertUnprocessable()
        ->assertExactJson([
            'success' => false,
            'message' => 'The customer must be KYC verified before an account can be opened.',
            'data' => null,
        ]);

    $this->assertDatabaseCount('account', 0);
});

it('opens an account at the staff member\'s branch, then deposits and withdraws', function () {
    $customer = customerWithLogin('VERIFIED');
    $branchId = currentBranchId($this->staff);

    $number = openAccountFor($customer, '500.00');
    $account = Account::firstWhere('account_number', $number);
    expect($account->branch_id)->toBe($branchId)
        ->and($account->balance)->toBe('500.00');

    $this->postJson("/api/v1/staff/accounts/{$number}/deposit", ['amount' => '250.50', 'description' => 'Salary'])
        ->assertOk()
        ->assertJson(['message' => 'Deposit successful.', 'data' => ['account_number' => $number, 'balance_after' => '750.50']]);

    $this->postJson("/api/v1/staff/accounts/{$number}/withdraw", ['amount' => 50])
        ->assertOk()
        ->assertJsonPath('data.balance_after', '700.50');

    expect($account->fresh()->balance)->toBe('700.50');

    // Initial deposit + deposit + withdrawal, all at this staff member's branch, by this employee.
    $rows = DB::table('transactions')->where('account_id', $account->account_id)->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('branch_id')->unique()->all())->toBe([$branchId])
        ->and($rows->pluck('employee_id')->unique()->all())->toBe([$this->staff->employee_id]);

    $this->getJson("/api/v1/staff/accounts/{$number}/transactions")
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.items.0.type_name', 'WITHDRAWAL')
        ->assertJsonPath('data.items.0.balance_after', '700.50');

    $this->getJson("/api/v1/staff/accounts/{$number}")
        ->assertOk()
        ->assertJsonPath('data.balance', '700.50')
        ->assertJsonPath('data.account_type.minimum_balance', '100.00')
        ->assertJsonPath('data.customer.customer_id', $customer->customer_id);

    expect(DB::table('audit_log')->whereIn('action_type', ['ACCOUNT_OPENED', 'DEPOSIT', 'WITHDRAWAL'])->count())->toBe(3);
});

it('refuses a withdrawal below the minimum balance and changes nothing', function () {
    $number = openAccountFor(customerWithLogin('VERIFIED'), '500.00');
    $transactionsBefore = DB::table('transactions')->count();

    $this->postJson("/api/v1/staff/accounts/{$number}/withdraw", ['amount' => '400.01'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient funds: this withdrawal would take the account below its minimum balance.');

    expect(Account::firstWhere('account_number', $number)->balance)->toBe('500.00')
        ->and(DB::table('transactions')->count())->toBe($transactionsBefore);
});

it('rejects amounts with more than 2 decimals', function () {
    $number = openAccountFor(customerWithLogin('VERIFIED'));

    $this->postJson("/api/v1/staff/accounts/{$number}/deposit", ['amount' => '12.345'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Amounts can have at most 2 decimal places.');
});

it('freezes an account: no deposits or transfers out until unfrozen', function () {
    $customer = customerWithLogin('VERIFIED');
    $number = openAccountFor($customer, '500.00');
    $other = openAccountFor(customerWithLogin('VERIFIED'), '200.00');

    $this->postJson("/api/v1/staff/accounts/{$number}/freeze", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/staff/accounts/{$number}/freeze", ['reason' => 'Court order'])
        ->assertOk()
        ->assertJsonPath('message', 'Account frozen.');

    $this->postJson("/api/v1/staff/accounts/{$number}/deposit", ['amount' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This account is frozen.');

    // Transfers out of a frozen account are refused by sp_transfer_funds.
    $accountId = Account::firstWhere('account_number', $number)->account_id;
    expect(fn () => DB::select('CALL sp_transfer_funds(?, ?, ?, ?, ?, ?)', [$accountId, $other, 10, 'Test', 'BRANCH', $this->staff->user_id]))
        ->toThrow(QueryException::class, 'The source account is not active.');

    $audit = DB::table('audit_log')->where('action_type', 'ACCOUNT_FROZEN')->first();
    expect(json_decode($audit->details, true))->toMatchArray(['before' => ['status' => 'ACTIVE'], 'after' => ['status' => 'FROZEN'], 'reason' => 'Court order']);

    $this->postJson("/api/v1/staff/accounts/{$number}/unfreeze")->assertOk();
    $this->postJson("/api/v1/staff/accounts/{$number}/deposit", ['amount' => 10])->assertOk()->assertJsonPath('data.balance_after', '510.00');
    $this->assertDatabaseHas('audit_log', ['action_type' => 'ACCOUNT_UNFROZEN', 'record_id' => $accountId]);
});

it('cannot freeze or unfreeze a closed account', function () {
    $number = openAccountFor(customerWithLogin('VERIFIED'));
    DB::table('account')->where('account_number', $number)->update(['status' => 'CLOSED']);

    $this->postJson("/api/v1/staff/accounts/{$number}/freeze", ['reason' => 'x'])
        ->assertStatus(409)->assertJsonPath('message', "Closed accounts can't be changed.");
    $this->postJson("/api/v1/staff/accounts/{$number}/unfreeze")
        ->assertStatus(409)->assertJsonPath('message', "Closed accounts can't be changed.");
});

it('returns a friendly 404 for an unknown account number', function () {
    $this->postJson('/api/v1/staff/accounts/DB0000000000/deposit', ['amount' => 10])
        ->assertNotFound()
        ->assertJsonPath('message', 'The requested record was not found.');
});

it('lists account types for the open-account form', function () {
    savingsType();

    $this->getJson('/api/v1/staff/account-types')
        ->assertOk()
        ->assertJsonPath('data.0.type_name', 'Savings')
        ->assertJsonPath('data.0.minimum_balance', '100.00');
});

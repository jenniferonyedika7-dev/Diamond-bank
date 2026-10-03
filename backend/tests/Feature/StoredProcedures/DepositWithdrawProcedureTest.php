<?php

namespace Tests\Feature\StoredProcedures;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BankFixtures;
use Tests\TestCase;

/**
 * DatabaseTruncation (not RefreshDatabase): the procedure's START TRANSACTION
 * would implicitly commit a test-wrapping transaction.
 */
class DepositWithdrawProcedureTest extends TestCase
{
    use BankFixtures, DatabaseTruncation;

    protected bool $seed = true;

    private int $tellerId;

    private object $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBankAndBranch();

        $this->tellerId = $this->staffUser();
        $this->assignToBranch($this->tellerId);

        // SAVINGS: minimum balance 100.00.
        $this->account = $this->createAccount($this->createCustomer(), 500.00, ['account_type_id' => $this->savingsTypeId]);
    }

    public function test_deposit_adds_to_the_balance_and_records_branch_employee_and_audit(): void
    {
        $result = $this->deposit($this->account->account_number, '250.75', $this->tellerId, 'Salary');

        $this->assertSame($this->account->account_number, $result->account_number);
        $this->assertSame('250.75', (string) $result->amount);
        $this->assertSame('750.75', (string) $result->balance_after);
        $this->assertSame('750.75', $this->balanceOf($this->account->account_id));

        $employeeId = DB::table('users')->where('user_id', $this->tellerId)->value('employee_id');
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $result->transaction_id,
            'account_id' => $this->account->account_id,
            'transaction_type_id' => DB::table('transaction_type')->where('type_name', 'DEPOSIT')->value('transaction_type_id'),
            'branch_id' => $this->branchId,
            'employee_id' => $employeeId,
            'amount' => '250.75',
            'channel' => 'BRANCH',
            'balance_after' => '750.75',
            'description' => 'Salary',
        ]);

        $audit = DB::table('audit_log')->where('action_type', 'DEPOSIT')->first();
        $this->assertSame($this->tellerId, $audit->user_id);
        $this->assertSame('transactions', $audit->table_affected);
        $this->assertSame($result->transaction_id, $audit->record_id);
        $details = json_decode($audit->details, true);
        $this->assertSame($this->account->account_number, $details['account_number']);
        $this->assertEquals(500, $details['balance_before']);
        $this->assertEquals(750.75, $details['balance_after']);
    }

    public function test_withdraw_subtracts_down_to_the_minimum_balance(): void
    {
        $result = $this->withdraw($this->account->account_number, '400.00', $this->tellerId);

        $this->assertSame('100.00', (string) $result->balance_after);
        $this->assertSame('100.00', $this->balanceOf($this->account->account_id));
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $result->transaction_id,
            'transaction_type_id' => DB::table('transaction_type')->where('type_name', 'WITHDRAWAL')->value('transaction_type_id'),
            'branch_id' => $this->branchId,
            'amount' => '400.00',
            'balance_after' => '100.00',
        ]);
        $this->assertDatabaseHas('audit_log', ['action_type' => 'WITHDRAWAL', 'record_id' => $result->transaction_id]);
    }

    public function test_withdraw_below_minimum_balance_is_rejected_and_rolled_back(): void
    {
        $this->assertProcedureError('Insufficient funds: this withdrawal would take the account below its minimum balance.', fn () => $this->withdraw($this->account->account_number, '400.01', $this->tellerId));

        $this->assertSame('500.00', $this->balanceOf($this->account->account_id));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseMissing('audit_log', ['action_type' => 'WITHDRAWAL']);
    }

    public function test_frozen_and_closed_accounts_are_rejected(): void
    {
        DB::table('account')->where('account_id', $this->account->account_id)->update(['status' => 'FROZEN']);
        $this->assertProcedureError('This account is frozen.', fn () => $this->deposit($this->account->account_number, 10, $this->tellerId));
        $this->assertProcedureError('This account is frozen.', fn () => $this->withdraw($this->account->account_number, 10, $this->tellerId));

        DB::table('account')->where('account_id', $this->account->account_id)->update(['status' => 'CLOSED']);
        $this->assertProcedureError('This account is closed.', fn () => $this->deposit($this->account->account_number, 10, $this->tellerId));

        $this->assertSame('500.00', $this->balanceOf($this->account->account_id));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_rejects_non_staff_performers_and_staff_without_a_branch(): void
    {
        $customerUserId = $this->createUser('customer', $this->createCustomer());
        $this->assertProcedureError('Only staff or administrators can make a deposit.', fn () => $this->deposit($this->account->account_number, 10, $customerUserId));
        $this->assertProcedureError('Only staff or administrators can make a withdrawal.', fn () => $this->withdraw($this->account->account_number, 10, $customerUserId));

        $this->assertProcedureError('You are not assigned to a branch.', fn () => $this->deposit($this->account->account_number, 10, $this->staffUser()));
        $this->assertProcedureError('Your user account is not active.', fn () => $this->deposit($this->account->account_number, 10, $this->staffUser('BLOCKED')));
        $this->assertProcedureError('The user performing this action was not found.', fn () => $this->deposit($this->account->account_number, 10, 999999));

        $this->assertSame('500.00', $this->balanceOf($this->account->account_id));
    }

    public function test_rejects_invalid_amounts_and_unknown_accounts(): void
    {
        $number = $this->account->account_number;

        $this->assertProcedureError('Amounts can have at most 2 decimal places.', fn () => $this->deposit($number, '10.005', $this->tellerId));
        $this->assertProcedureError('Amounts can have at most 2 decimal places.', fn () => $this->withdraw($number, '10.005', $this->tellerId));
        $this->assertProcedureError('The deposit amount must be greater than zero.', fn () => $this->deposit($number, 0, $this->tellerId));
        $this->assertProcedureError('The withdrawal amount must be greater than zero.', fn () => $this->withdraw($number, -5, $this->tellerId));
        $this->assertProcedureError('The deposit amount must be greater than zero.', fn () => $this->deposit($number, null, $this->tellerId));
        $this->assertProcedureError('Account not found.', fn () => $this->deposit('DB0009999999', 10, $this->tellerId));

        $this->assertSame('500.00', $this->balanceOf($this->account->account_id));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_deposit_cannot_overflow_the_balance_column(): void
    {
        DB::table('account')->where('account_id', $this->account->account_id)->update(['balance' => '9999999999999.00']);

        $this->assertProcedureError('The account cannot hold this amount.', fn () => $this->deposit($this->account->account_number, '1.00', $this->tellerId));
    }
}

<?php

namespace Tests\Feature\StoredProcedures;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BankFixtures;
use Tests\TestCase;

/**
 * DatabaseTruncation (not RefreshDatabase): the procedure's START TRANSACTION
 * would implicitly commit a test-wrapping transaction.
 */
class OpenAccountProcedureTest extends TestCase
{
    use BankFixtures, DatabaseTruncation;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBankAndBranch();
    }

    public function test_staff_opens_account_with_initial_deposit(): void
    {
        $customerId = $this->createCustomer();
        $staffId = $this->staffUser();

        $result = $this->openAccount($customerId, $this->savingsTypeId, '500.00', 'GMD', $staffId);

        $expectedNumber = 'DB'.str_pad((string) $this->branchId, 3, '0', STR_PAD_LEFT).str_pad((string) $result->account_id, 7, '0', STR_PAD_LEFT);
        $this->assertSame($expectedNumber, $result->account_number);
        $this->assertSame('500.00', (string) $result->balance);

        $this->assertDatabaseHas('account', [
            'account_id' => $result->account_id,
            'account_number' => $expectedNumber,
            'customer_id' => $customerId,
            'balance' => '500.00',
            'currency_code' => 'GMD',
            'status' => 'ACTIVE',
        ]);

        $deposit = DB::table('transactions')->where('account_id', $result->account_id)->first();
        $this->assertSame(DB::table('transaction_type')->where('type_name', 'DEPOSIT')->value('transaction_type_id'), $deposit->transaction_type_id);
        $this->assertSame('500.00', $deposit->amount);
        $this->assertSame('500.00', $deposit->balance_after);
        $this->assertSame('BRANCH', $deposit->channel);
        $this->assertNull($deposit->transfer_id);
        $this->assertNotNull($deposit->employee_id);

        $audit = DB::table('audit_log')->where('action_type', 'ACCOUNT_OPENED')->first();
        $this->assertSame($staffId, $audit->user_id);
        $this->assertSame('account', $audit->table_affected);
        $this->assertSame($result->account_id, $audit->record_id);
        $this->assertSame($expectedNumber, json_decode($audit->details)->account_number);
    }

    public function test_zero_deposit_creates_no_transaction_row(): void
    {
        $result = $this->openAccount($this->createCustomer(), $this->currentTypeId, 0, 'GMD', $this->staffUser());

        $this->assertSame('0.00', (string) $result->balance);
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action_type', 'ACCOUNT_OPENED')->count());
    }

    public function test_admin_can_open_account_and_currency_is_normalised(): void
    {
        $adminId = DB::table('users')->where('user_name', config('admin.username'))->value('user_id');

        $result = $this->openAccount($this->createCustomer(), $this->currentTypeId, 10, 'usd', $adminId);

        $this->assertSame('USD', DB::table('account')->where('account_id', $result->account_id)->value('currency_code'));
    }

    public function test_currency_longer_than_three_characters_is_rejected_by_mysql(): void
    {
        // p_currency_code is CHAR(3): MySQL rejects longer input (SQLSTATE 22001) before the
        // procedure body runs, so the app should validate currency before calling.
        try {
            $this->openAccount($this->createCustomer(), $this->currentTypeId, 10, 'USDX', $this->staffUser());
            $this->fail('Expected a data-too-long error.');
        } catch (QueryException $e) {
            $this->assertSame('22001', (string) $e->getCode());
        }

        $this->assertSame(0, DB::table('account')->count());
    }

    public function test_rejects_customer_role_performer(): void
    {
        $customerId = $this->createCustomer();
        $customerUser = $this->createUser('customer', $customerId);

        $this->assertProcedureError('Only staff or administrators can open accounts.',
            fn () => $this->openAccount($customerId, $this->currentTypeId, 100, 'GMD', $customerUser));
    }

    public function test_rejects_inactive_or_unknown_performer(): void
    {
        $customerId = $this->createCustomer();

        $this->assertProcedureError('Your user account is not active.',
            fn () => $this->openAccount($customerId, $this->currentTypeId, 100, 'GMD', $this->staffUser('BLOCKED')));

        $this->assertProcedureError('The user performing this action was not found.',
            fn () => $this->openAccount($customerId, $this->currentTypeId, 100, 'GMD', 999999));
    }

    public function test_rejects_customer_without_verified_kyc(): void
    {
        $this->assertProcedureError('must be KYC verified',
            fn () => $this->openAccount($this->createCustomer('PENDING'), $this->currentTypeId, 100, 'GMD', $this->staffUser()));

        $this->assertProcedureError('Customer not found.',
            fn () => $this->openAccount(999999, $this->currentTypeId, 100, 'GMD', $this->staffUser()));
    }

    public function test_rejects_unknown_branch_and_account_type(): void
    {
        $customerId = $this->createCustomer();
        $staffId = $this->staffUser();

        $this->assertProcedureError('Branch not found.',
            fn () => $this->openAccount($customerId, $this->currentTypeId, 100, 'GMD', $staffId, 999999));

        $this->assertProcedureError('Account type not found.',
            fn () => $this->openAccount($customerId, 999999, 100, 'GMD', $staffId));
    }

    public function test_rejects_invalid_deposits(): void
    {
        $customerId = $this->createCustomer();
        $staffId = $this->staffUser();

        $this->assertProcedureError('cannot be negative',
            fn () => $this->openAccount($customerId, $this->currentTypeId, '-1.00', 'GMD', $staffId));

        $this->assertProcedureError('at most 2 decimal places',
            fn () => $this->openAccount($customerId, $this->currentTypeId, '10.555', 'GMD', $staffId));

        $this->assertProcedureError('below the minimum balance',
            fn () => $this->openAccount($customerId, $this->savingsTypeId, '99.99', 'GMD', $staffId));

        $this->assertProcedureError('too large',
            fn () => $this->openAccount($customerId, $this->currentTypeId, '10000000000000.00', 'GMD', $staffId));

        $this->assertSame(0, DB::table('account')->count());
    }

    public function test_rejects_invalid_currency(): void
    {
        $customerId = $this->createCustomer();
        $staffId = $this->staffUser();

        foreach (['G1D', 'GM', ''] as $currency) {
            $this->assertProcedureError('Currency code must be 3 letters',
                fn () => $this->openAccount($customerId, $this->currentTypeId, 100, $currency, $staffId));
        }
    }

    public function test_missing_deposit_type_gives_setup_error(): void
    {
        DB::table('transaction_type')->where('type_name', 'DEPOSIT')->delete();

        $this->assertProcedureError('Setup error: transaction type DEPOSIT is missing',
            fn () => $this->openAccount($this->createCustomer(), $this->currentTypeId, 100, 'GMD', $this->staffUser()));
    }

    public function test_connection_is_usable_after_a_failed_call(): void
    {
        $customerId = $this->createCustomer();
        $staffId = $this->staffUser();

        $this->assertProcedureError('below the minimum balance',
            fn () => $this->openAccount($customerId, $this->savingsTypeId, '1.00', 'GMD', $staffId));

        $result = $this->openAccount($customerId, $this->savingsTypeId, '100.00', 'GMD', $staffId);

        $this->assertSame(1, DB::table('account')->count());
        $this->assertSame('100.00', (string) $result->balance);
    }
}

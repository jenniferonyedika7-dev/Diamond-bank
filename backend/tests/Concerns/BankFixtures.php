<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal reference data for exercising the stored procedures.
 * Roles, transaction types and the admin user come from DatabaseSeeder.
 */
trait BankFixtures
{
    protected int $branchId;

    protected int $savingsTypeId;

    protected int $currentTypeId;

    protected function createBankAndBranch(): void
    {
        $bankId = DB::table('bank')->insertGetId([
            'bank_name' => 'Diamond Bank',
            'swift_code' => 'DIAMGMGM',
            'established_date' => '2000-01-01',
        ], 'bank_id');

        $this->branchId = DB::table('branch')->insertGetId([
            'bank_id' => $bankId,
            'branch_name' => 'Banjul Main',
            'branch_code' => 'BJL001',
            'address' => '1 Independence Drive, Banjul',
            'phone' => '4220000',
            'opened_date' => '2000-01-01',
        ], 'branch_id');

        $this->savingsTypeId = DB::table('account_type')->insertGetId([
            'type_name' => 'SAVINGS', 'interest_rate' => 3.50, 'minimum_balance' => 100.00,
        ], 'account_type_id');

        $this->currentTypeId = DB::table('account_type')->insertGetId([
            'type_name' => 'CURRENT', 'interest_rate' => 0.00, 'minimum_balance' => 0.00,
        ], 'account_type_id');
    }

    protected function createCustomer(string $kycStatus = 'VERIFIED'): int
    {
        static $n = 0;
        $n++;

        return DB::table('customer')->insertGetId([
            'branch_id' => $this->branchId,
            'first_name' => 'Test',
            'last_name' => "Customer{$n}",
            'date_of_birth' => '1990-01-01',
            'gender' => 'F',
            'national_id' => "NID-{$n}-".uniqid(),
            'phone' => '7000000',
            'email' => "customer{$n}-".uniqid().'@example.test',
            'kyc_status' => $kycStatus,
        ], 'customer_id');
    }

    protected function createEmployee(): int
    {
        return DB::table('employee')->insertGetId([
            'full_name' => 'Teller One',
            'national_id' => 'EMP-'.uniqid(),
            'position' => 'Teller',
            'phone' => '7000001',
            'email' => 'teller-'.uniqid().'@diamondbank.local',
            'hired_date' => '2020-01-01',
        ], 'employee_id');
    }

    /** Creates a login. $role is customer|staff|admin; owner is a customer_id or employee_id. */
    protected function createUser(string $role, int $ownerId, string $status = 'ACTIVE'): int
    {
        return DB::table('users')->insertGetId([
            'role_id' => DB::table('role')->where('role_name', $role)->value('role_id'),
            'customer_id' => $role === 'customer' ? $ownerId : null,
            'employee_id' => $role === 'customer' ? null : $ownerId,
            'user_name' => "{$role}-".uniqid(),
            'password' => Hash::make('secret'),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');
    }

    protected function staffUser(string $status = 'ACTIVE'): int
    {
        return $this->createUser('staff', $this->createEmployee(), $status);
    }

    /** Inserts an account directly (bypassing sp_open_account) for transfer tests. */
    protected function createAccount(int $customerId, float $balance, array $overrides = []): object
    {
        $id = DB::table('account')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'branch_id' => $this->branchId,
            'account_type_id' => $this->currentTypeId,
            'account_number' => 'TEST'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'balance' => $balance,
        ], $overrides), 'account_id');

        return DB::table('account')->where('account_id', $id)->first();
    }

    protected function openAccount(int $customerId, int $accountTypeId, mixed $deposit, ?string $currency, int $performedBy, ?int $branchId = null): object
    {
        return DB::select('CALL sp_open_account(?, ?, ?, ?, ?, ?)', [
            $customerId, $accountTypeId, $branchId ?? $this->branchId, $deposit, $currency, $performedBy,
        ])[0];
    }

    protected function transfer(int $fromAccountId, string $toAccountNumber, mixed $amount, ?string $channel, int $performedBy, ?string $description = 'Test transfer'): object
    {
        return DB::select('CALL sp_transfer_funds(?, ?, ?, ?, ?, ?)', [
            $fromAccountId, $toAccountNumber, $amount, $description, $channel, $performedBy,
        ])[0];
    }

    /** Asserts the callback fails with SIGNAL SQLSTATE 45000 carrying the given message. */
    protected function assertProcedureError(string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException $e) {
            $this->assertSame('45000', (string) $e->getCode(), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("Expected procedure error: {$message}");
    }

    protected function balanceOf(int $accountId): string
    {
        return DB::table('account')->where('account_id', $accountId)->value('balance');
    }
}

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
class TransferFundsProcedureTest extends TestCase
{
    use BankFixtures, DatabaseTruncation;

    protected bool $seed = true;

    private int $aliceId;

    private int $aliceUserId;

    private object $aliceAccount;

    private object $bobAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBankAndBranch();

        $this->aliceId = $this->createCustomer();
        $this->aliceUserId = $this->createUser('customer', $this->aliceId);
        $this->aliceAccount = $this->createAccount($this->aliceId, 1000.00);
        $this->bobAccount = $this->createAccount($this->createCustomer(), 50.00);
    }

    public function test_customer_transfers_from_own_account(): void
    {
        $result = $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, '250.50', 'ONLINE', $this->aliceUserId, 'Rent');

        $this->assertSame('749.50', (string) $result->balance_after);
        $this->assertSame('250.50', (string) $result->amount);
        $this->assertSame('749.50', $this->balanceOf($this->aliceAccount->account_id));
        $this->assertSame('300.50', $this->balanceOf($this->bobAccount->account_id));

        $this->assertDatabaseHas('transfer', [
            'transfer_id' => $result->transfer_id,
            'from_account_id' => $this->aliceAccount->account_id,
            'to_account_id' => $this->bobAccount->account_id,
            'amount' => '250.50',
            'status' => 'COMPLETED',
        ]);

        $types = DB::table('transaction_type')->pluck('transaction_type_id', 'type_name');
        $rows = DB::table('transactions')->where('transfer_id', $result->transfer_id)->get()->keyBy('account_id');
        $this->assertCount(2, $rows);

        $out = $rows[$this->aliceAccount->account_id];
        $this->assertSame($types['TRANSFER_OUT'], $out->transaction_type_id);
        $this->assertSame('749.50', $out->balance_after);
        $this->assertSame('ONLINE', $out->channel);
        $this->assertSame('Rent', $out->description);
        $this->assertNull($out->employee_id);

        $in = $rows[$this->bobAccount->account_id];
        $this->assertSame($types['TRANSFER_IN'], $in->transaction_type_id);
        $this->assertSame('300.50', $in->balance_after);

        $audit = DB::table('audit_log')->where('action_type', 'TRANSFER')->first();
        $this->assertSame($this->aliceUserId, $audit->user_id);
        $this->assertSame('transfer', $audit->table_affected);
        $this->assertSame($result->transfer_id, $audit->record_id);
        $details = json_decode($audit->details, true);
        $this->assertSame($this->aliceAccount->account_id, $details['from']);
        $this->assertSame($this->bobAccount->account_id, $details['to']);
        $this->assertEquals(250.50, $details['amount']);
    }

    public function test_transfer_to_lower_account_id_works(): void
    {
        // Exercises the other lock-ordering branch (from_account_id > to_account_id).
        $bobUserId = $this->createUser('customer', $this->bobAccount->customer_id);

        $result = $this->transfer($this->bobAccount->account_id, $this->aliceAccount->account_number, '50.00', 'MOBILE', $bobUserId);

        $this->assertSame('0.00', (string) $result->balance_after);
        $this->assertSame('1050.00', $this->balanceOf($this->aliceAccount->account_id));
    }

    public function test_staff_transfer_records_employee_and_lowercase_channel_is_accepted(): void
    {
        $employeeId = $this->createEmployee();
        $staffUserId = $this->createUser('staff', $employeeId);

        $result = $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'branch', $staffUserId);

        $rows = DB::table('transactions')->where('transfer_id', $result->transfer_id)->get();
        $this->assertSame([$employeeId, $employeeId], $rows->pluck('employee_id')->all());
        $this->assertSame(['BRANCH', 'BRANCH'], $rows->pluck('channel')->all());
    }

    public function test_customer_cannot_transfer_from_someone_elses_account(): void
    {
        $this->assertProcedureError('You can only transfer from your own accounts.',
            fn () => $this->transfer($this->bobAccount->account_id, $this->aliceAccount->account_number, 10, 'ONLINE', $this->aliceUserId));

        $this->assertNothingChanged();
    }

    public function test_rejects_transfer_below_minimum_balance_and_rolls_back(): void
    {
        $savings = $this->createAccount($this->aliceId, 150.00, ['account_type_id' => $this->savingsTypeId]);

        // Minimum balance on SAVINGS is 100.00: 50.00 is allowed, 50.01 is not.
        $this->assertProcedureError('Insufficient funds',
            fn () => $this->transfer($savings->account_id, $this->bobAccount->account_number, '50.01', 'ONLINE', $this->aliceUserId));

        $this->assertSame('150.00', $this->balanceOf($savings->account_id));
        $this->assertNothingChanged();

        $this->transfer($savings->account_id, $this->bobAccount->account_number, '50.00', 'ONLINE', $this->aliceUserId);
        $this->assertSame('100.00', $this->balanceOf($savings->account_id));
    }

    public function test_rejects_overdraft_on_zero_minimum_account(): void
    {
        $this->assertProcedureError('Insufficient funds',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, '1000.01', 'ONLINE', $this->aliceUserId));

        $this->assertNothingChanged();
    }

    public function test_rejects_invalid_amounts(): void
    {
        $to = $this->bobAccount->account_number;
        $from = $this->aliceAccount->account_id;

        $this->assertProcedureError('must be greater than zero', fn () => $this->transfer($from, $to, 0, 'ONLINE', $this->aliceUserId));
        $this->assertProcedureError('must be greater than zero', fn () => $this->transfer($from, $to, '-5.00', 'ONLINE', $this->aliceUserId));
        $this->assertProcedureError('at most 2 decimal places', fn () => $this->transfer($from, $to, '1.001', 'ONLINE', $this->aliceUserId));
        $this->assertProcedureError('too large', fn () => $this->transfer($from, $to, '10000000000000.00', 'ONLINE', $this->aliceUserId));

        $this->assertNothingChanged();
    }

    public function test_rejects_invalid_channel(): void
    {
        $this->assertProcedureError('Channel must be BRANCH, ONLINE, MOBILE or ATM.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'FAX', $this->aliceUserId));
    }

    public function test_rejects_unknown_or_inactive_performer(): void
    {
        $this->assertProcedureError('The user performing this action was not found.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'ONLINE', 999999));

        DB::table('users')->where('user_id', $this->aliceUserId)->update(['status' => 'BLOCKED']);

        $this->assertProcedureError('Your user account is not active.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'ONLINE', $this->aliceUserId));
    }

    public function test_rejects_unknown_accounts_and_same_account(): void
    {
        $this->assertProcedureError('Source account not found.',
            fn () => $this->transfer(999999, $this->bobAccount->account_number, 10, 'ONLINE', $this->aliceUserId));

        $this->assertProcedureError('Destination account not found.',
            fn () => $this->transfer($this->aliceAccount->account_id, 'NOPE', 10, 'ONLINE', $this->aliceUserId));

        $this->assertProcedureError('You cannot transfer money to the same account.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->aliceAccount->account_number, 10, 'ONLINE', $this->aliceUserId));
    }

    public function test_rejects_inactive_accounts(): void
    {
        DB::table('account')->where('account_id', $this->bobAccount->account_id)->update(['status' => 'FROZEN']);

        $this->assertProcedureError('The destination account is not active.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'ONLINE', $this->aliceUserId));

        DB::table('account')->where('account_id', $this->aliceAccount->account_id)->update(['status' => 'CLOSED']);

        $this->assertProcedureError('The source account is not active.',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'ONLINE', $this->aliceUserId));

        $this->assertNothingChanged();
    }

    public function test_rejects_currency_mismatch(): void
    {
        $usd = $this->createAccount($this->createCustomer(), 0, ['currency_code' => 'USD']);

        $this->assertProcedureError('Both accounts must use the same currency.',
            fn () => $this->transfer($this->aliceAccount->account_id, $usd->account_number, 10, 'ONLINE', $this->aliceUserId));

        $this->assertNothingChanged();
    }

    public function test_missing_transfer_types_give_setup_error(): void
    {
        DB::table('transaction_type')->where('type_name', 'TRANSFER_IN')->delete();

        $this->assertProcedureError('Setup error: transaction types TRANSFER_OUT / TRANSFER_IN are missing',
            fn () => $this->transfer($this->aliceAccount->account_id, $this->bobAccount->account_number, 10, 'ONLINE', $this->aliceUserId));
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame('1000.00', $this->balanceOf($this->aliceAccount->account_id));
        $this->assertSame('50.00', $this->balanceOf($this->bobAccount->account_id));
        $this->assertSame(0, DB::table('transfer')->count());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DB::table('audit_log')->where('action_type', 'TRANSFER')->count());
    }
}

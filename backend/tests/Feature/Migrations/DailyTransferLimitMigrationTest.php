<?php

use App\Exceptions\ProcedureFailed;
use App\Support\StoredProcedure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| 2026_10_19_000001 adds account_type.daily_transfer_limit (Savings 100,000,
| Current 500,000, others 100,000), the transfer(from_account_id, transfer_date)
| index, the day functions and the limit in sp_transfer_funds; down() undoes it.
*/

it('sets the limits on existing types and can be rolled back and re-applied', function () {
    $migration = require database_path('migrations/2026_10_19_000001_add_daily_transfer_limit.php');
    $migration->down();
    expect(Schema::hasColumn('account_type', 'daily_transfer_limit'))->toBeFalse();

    foreach (['Savings' => 500, 'Current' => 1000, 'Fixed Deposit' => 0] as $name => $minimum) {
        DB::table('account_type')->insert(['type_name' => $name, 'interest_rate' => 1, 'minimum_balance' => $minimum]);
    }
    // Without the limit, 150,000 in a day from Savings goes through.
    $me = bankCustomer();
    $from = accountFor($me, '400000.00');
    $to = accountFor(bankCustomer(), '0.00');
    StoredProcedure::call('sp_transfer_funds', [$from->account_id, $to->account_number, '150000.00', null, 'ONLINE', $me->user_id]);

    $migration->up();

    expect(DB::table('account_type')->orderBy('type_name')->pluck('daily_transfer_limit', 'type_name')->all())
        ->toBe(['Current' => '500000.00', 'Fixed Deposit' => '100000.00', 'Savings' => '100000.00'])
        ->and(collect(DB::select('SHOW INDEX FROM transfer'))->where('Key_name', 'idx_transfer_from_date')->pluck('Column_name')->all())
        ->toBe(['from_account_id', 'transfer_date']);
    expect(fn () => DB::table('account_type')->where('type_name', 'Savings')->update(['daily_transfer_limit' => 0]))
        ->toThrow(QueryException::class, 'chk_account_type_daily_transfer_limit');

    // Today's 150,000 already exceeds the new 100,000 limit.
    expect(fn () => StoredProcedure::call('sp_transfer_funds', [$from->account_id, $to->account_number, '0.01', null, 'ONLINE', $me->user_id]))
        ->toThrow(ProcedureFailed::class, 'Remaining today: GMD 0.00.');
});

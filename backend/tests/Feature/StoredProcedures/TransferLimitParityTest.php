<?php

use App\Exceptions\ProcedureFailed;
use App\Models\User;
use App\Support\DailyTransferLimit;
use App\Support\StoredProcedure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * DailyTransferLimit (PHP, the "Remaining today" the customer sees) and
 * sp_transfer_funds (SQL, the locking sum that decides) must agree to the cent.
 * After each sequence of transfers, the procedure must accept exactly the PHP
 * remaining amount, refuse one cent more with that amount in its message, and
 * both must ignore yesterday's and tomorrow's rows and non-completed ones.
 */
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->beforeEach(fn () => $this->seed());

function parityTransfer(object $from, object $to, User $by, string $amount): void
{
    StoredProcedure::call('sp_transfer_funds', [$from->account_id, $to->account_number, $amount, null, 'ONLINE', $by->user_id]);
}

it('agrees with the procedure on what remains today', function (array $amounts, string $limit) {
    $me = bankCustomer();
    $typeId = DB::table('account_type')->insertGetId(['type_name' => 'Parity '.$limit, 'interest_rate' => 1, 'minimum_balance' => 0, 'daily_transfer_limit' => $limit], 'account_type_id');
    $from = accountFor($me, '9999999.00', ['account_type_id' => $typeId]);
    $to = accountFor(bankCustomer(), '0.00');

    // Rows the day's sum must ignore: yesterday, tomorrow, not completed.
    $row = ['from_account_id' => $from->account_id, 'to_account_id' => $to->account_id, 'amount' => '777.77'];
    DB::table('transfer')->insert([
        [...$row, 'status' => 'COMPLETED', 'transfer_date' => DB::raw('fn_transfer_day_start() - INTERVAL 1 SECOND')],
        [...$row, 'status' => 'COMPLETED', 'transfer_date' => DB::raw('fn_transfer_day_start() + INTERVAL 1 DAY')],
        [...$row, 'status' => 'FAILED', 'transfer_date' => DB::raw('NOW()')],
        [...$row, 'status' => 'REVERSED', 'transfer_date' => DB::raw('NOW()')],
    ]);

    foreach ($amounts as $amount) {
        parityTransfer($from, $to, $me, $amount);
    }
    $remaining = DailyTransferLimit::remaining($from->account_id, $limit);
    expect($remaining)->toBe(bcsub($limit, array_reduce($amounts, fn ($sum, $a) => bcadd($sum, $a, 2), '0.00'), 2));

    try {
        parityTransfer($from, $to, $me, bcadd($remaining, '0.01', 2));
        $this->fail('Expected the procedure to refuse one cent over the remaining amount.');
    } catch (ProcedureFailed $e) {
        expect($e->sqlState)->toBe('45002');
        preg_match('/Remaining today: GMD ([\d,]+\.\d{2})\./', $e->getMessage(), $match);
        expect(str_replace(',', '', $match[1] ?? ''))->toBe($remaining);
    }

    if (bccomp($remaining, '0', 2) > 0) {
        parityTransfer($from, $to, $me, $remaining);
    }
    expect(DailyTransferLimit::remaining($from->account_id, $limit))->toBe('0.00')
        ->and(DailyTransferLimit::sentToday($from->account_id))->toBe($limit);
})->with([
    'nothing sent yet' => [[], '100000.00'],
    'one transfer' => [['33333.33'], '100000.00'],
    'odd cents' => [['0.01', '0.99', '12345.67', '0.33'], '100000.00'],
    'limit used up' => [['60000.00', '40000.00'], '100000.00'],
    'current account' => [['250000.00', '149999.99'], '500000.00'],
    'small limit' => [['9.99'], '10.00'],
]);

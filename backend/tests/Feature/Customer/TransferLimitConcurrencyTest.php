<?php

use App\Exceptions\ProcedureFailed;
use App\Support\StoredProcedure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
| The daily limit holds when transfers really run at the same time, and MySQL
| giving up on a lock (lock wait timeout 1205, deadlock 1213 / 40001) reaches
| the customer as a 409 "try again", with nothing changed.
*/

beforeEach(function () {
    $this->me = bankCustomer();
    $this->savings = accountFor($this->me, '300000.00');   // Savings: limit 100,000
    $this->theirs = accountFor(bankCustomer(), '100.00');
});

afterEach(function () {
    DB::unprepared('DROP PROCEDURE IF EXISTS sp_test_busy');
});

/** A PHP process running tests/Support/concurrent_transfer.php against the testing database. */
function helperProcess(array $arguments): Process
{
    $connection = config('database.connections.'.config('database.default'));
    expect($connection['database'])->toBe('bank_db_testing');

    return new Process([PHP_BINARY, base_path('tests/Support/concurrent_transfer.php'), ...$arguments], base_path(), [
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
    ], null, 30);
}

/** @return list<array> each process's JSON answer */
function runTogether(object $from, object $to, int $performedBy, array $amounts): array
{
    $startAt = (string) (microtime(true) + 1.5);
    $processes = array_map(fn (string $amount) => tap(helperProcess(['transfer', $from->account_id, $to->account_number, $amount, $performedBy, $startAt]))->start(), $amounts);

    return array_map(function (Process $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        return json_decode(trim($process->getOutput()), true);
    }, $processes);
}

it('lets only one of two simultaneous transfers through when both would pass the limit alone', function () {
    $results = runTogether($this->savings, $this->theirs, $this->me->user_id, ['60000.00', '60000.00']);

    $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
    $refused = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    expect($ok)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]['sqlstate'])->toBe('45002')
        ->and($refused[0]['message'])->toContain('Remaining today: GMD 40,000.00.')
        ->and(DB::table('transfer')->where('from_account_id', $this->savings->account_id)->sum('amount'))->toEqual('60000.00')
        ->and(balanceOfAccount($this->savings))->toBe('240000.00');
});

it('lets both through when together they are exactly the limit', function () {
    $results = runTogether($this->savings, $this->theirs, $this->me->user_id, ['50000.00', '50000.00']);

    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(balanceOfAccount($this->savings))->toBe('200000.00');
});

it('reads today\'s transfers with a lock, and answers 409 when that lock times out', function () {
    $this->actingAs($this->me);
    $send = fn (string $amount) => $this->postJson('/api/v1/customer/transfers', [
        'from_account_number' => $this->savings->account_number, 'to_account_number' => $this->theirs->account_number,
        'amount' => $amount, 'password' => 'password1',
    ]);
    $send('1000.00')->assertCreated();
    $transferId = DB::table('transfer')->value('transfer_id');

    // Another session holds today's transfer row (not the account row) exclusively.
    $holder = committedView();
    $holder->beginTransaction();
    $holder->select('SELECT transfer_id FROM transfer WHERE transfer_id = ? FOR UPDATE', [$transferId]);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        // A plain (non-locking) sum would not wait for it, and this transfer would go through.
        $send('1000.00')
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => StoredProcedure::BUSY, 'data' => null]);
    } finally {
        $holder->rollBack();
    }

    expect(DB::table('transfer')->count())->toBe(1)
        ->and(balanceOfAccount($this->savings))->toBe('299000.00');
});

it('answers 409 when MySQL picks the transfer as a deadlock victim', function () {
    // The procedure locks the lower account id first: make the source the lower one.
    expect($this->savings->account_id)->toBeLessThan($this->theirs->account_id);
    $ready = tempnam(sys_get_temp_dir(), 'deadlock');
    unlink($ready);
    $helper = tap(helperProcess(['deadlock', $this->theirs->account_id, $this->savings->account_id, $ready]))->start();
    $waited = 0;
    while (! file_exists($ready) && $waited++ < 200) {
        usleep(25000);
    }
    expect(file_exists($ready))->toBeTrue($helper->getErrorOutput());

    $this->actingAs($this->me)->postJson('/api/v1/customer/transfers', [
        'from_account_number' => $this->savings->account_number, 'to_account_number' => $this->theirs->account_number,
        'amount' => '1000.00', 'password' => 'password1',
    ])->assertConflict()->assertJsonPath('message', StoredProcedure::BUSY);

    $helper->wait();
    @unlink($ready);
    expect(json_decode(trim($helper->getOutput()), true))->toBe(['ok' => true])
        ->and(DB::table('transfer')->count())->toBe(0)
        ->and(balanceOfAccount($this->savings))->toBe('300000.00')
        ->and(DB::table('audit_log')->where('action_type', 'TEST_DEADLOCK_HELPER')->count())->toBe(0);
});

it('maps SQLSTATE 40001 to the same 409', function () {
    DB::unprepared("CREATE PROCEDURE sp_test_busy() BEGIN SIGNAL SQLSTATE '40001' SET MESSAGE_TEXT = 'Serialization failure'; END");

    try {
        StoredProcedure::call('sp_test_busy', []);
        $this->fail('Expected ProcedureFailed');
    } catch (ProcedureFailed $e) {
        expect($e->status)->toBe(409)
            ->and($e->getMessage())->toBe(StoredProcedure::BUSY)
            ->and($e->sqlState)->toBe('40001');
    }
});

<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Freezes both clocks at $at: PHP (Carbon::setTestNow) and this test's MySQL
 * session (SET timestamp), which drives NOW(), UTC_TIMESTAMP() and column
 * defaults inside the stored procedures. $this->travel() moves only PHP's.
 * Undo with thawClock().
 */
function freezeClock(string $at, string $timezone = 'Africa/Banjul'): CarbonImmutable
{
    $moment = CarbonImmutable::parse($at, $timezone);
    Carbon::setTestNow($moment);
    DB::statement('SET timestamp = ?', [$moment->getTimestamp()]);

    return $moment;
}

function thawClock(): void
{
    Carbon::setTestNow();
    DB::statement('SET timestamp = DEFAULT');
}

/**
 * A second connection to the testing database: it sees only committed rows,
 * so a row written inside a rolled-back (or still open) transaction is invisible to it.
 */
function committedView(): Connection
{
    config(['database.connections.committed_view' => config('database.connections.'.config('database.default'))]);
    $connection = DB::connection('committed_view');
    expect($connection->getDatabaseName())->toBe('bank_db_testing');

    return $connection;
}

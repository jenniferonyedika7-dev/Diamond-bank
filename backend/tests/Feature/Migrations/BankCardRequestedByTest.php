<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const REQUESTED_BY_MIGRATION = '2026_10_05_000004_make_bank_card_requested_by_required';

function requestedByIsNullable(): bool
{
    return DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'bank_card')
        ->where('COLUMN_NAME', 'requested_by')
        ->value('IS_NULLABLE') === 'YES';
}

/** A bank_card row with every required column except requested_by. */
function cardRowWithoutRequester(object $account): array
{
    return [
        'account_id' => $account->account_id,
        'card_type_id' => cardType()->card_type_id,
        'status' => 'REQUESTED',
        'requested_at' => now(),
    ];
}

/** Runs $callback and returns the MySQL error number it fails with. */
function mysqlErrorOf(callable $callback): ?int
{
    try {
        $callback();
    } catch (QueryException $e) {
        return $e->errorInfo[1] ?? null;
    }

    return null;
}

it('refuses a card without a requesting user at the database level', function () {
    $account = accountFor(bankCustomer(), '100.00');

    expect(requestedByIsNullable())->toBeFalse()
        // 1364: Field 'requested_by' doesn't have a default value.
        ->and(mysqlErrorOf(fn () => DB::table('bank_card')->insert(cardRowWithoutRequester($account))))->toBe(1364)
        // 1048: Column 'requested_by' cannot be null.
        ->and(mysqlErrorOf(fn () => DB::table('bank_card')->insert([...cardRowWithoutRequester($account), 'requested_by' => null])))->toBe(1048);

    $this->assertDatabaseCount('bank_card', 0);
});

it('keeps the foreign key to users with ON DELETE RESTRICT', function () {
    $owner = bankCustomer();
    cardFor(accountFor($owner, '100.00'));

    // 1451: Cannot delete or update a parent row: a foreign key constraint fails.
    expect(mysqlErrorOf(fn () => DB::table('users')->where('user_id', $owner->user_id)->delete()))->toBe(1451);
    $this->assertDatabaseHas('users', ['user_id' => $owner->user_id]);
});

it('stops without changing anything while a card has no requesting user', function () {
    $migration = require database_path('migrations/'.REQUESTED_BY_MIGRATION.'.php');
    $account = accountFor(bankCustomer(), '100.00');

    try {
        // a. Back to nullable, as before the migration.
        $migration->down();
        expect(requestedByIsNullable())->toBeTrue();

        // b. A card with no requesting user.
        $orphanId = DB::table('bank_card')->insertGetId(cardRowWithoutRequester($account), 'bank_card_id');

        // c. The migration refuses and leaves the column nullable.
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, "1 card(s) have no requesting user (bank_card_id {$orphanId}). Set requested_by on them first. Nothing was changed.");
        expect(requestedByIsNullable())->toBeTrue();

        // d. Once the row is fixed, it applies.
        DB::table('bank_card')->where('bank_card_id', $orphanId)->delete();
        $migration->up();
        expect(requestedByIsNullable())->toBeFalse();
    } finally {
        // Later tests share this schema: always leave the column NOT NULL, even if an assertion failed.
        DB::table('bank_card')->whereNull('requested_by')->delete();
        if (requestedByIsNullable()) {
            $migration->up();
        }
    }
});

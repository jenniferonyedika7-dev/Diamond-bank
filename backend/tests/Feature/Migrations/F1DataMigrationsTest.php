<?php

use Illuminate\Support\Facades\DB;

function runMigration(string $file): void
{
    (require database_path("migrations/{$file}.php"))->up();
}

it('title-cases account type, card type and department names', function () {
    DB::table('account_type')->insert([
        ['type_name' => 'SAVINGS', 'interest_rate' => 3.5, 'minimum_balance' => 100],
        ['type_name' => 'Current', 'interest_rate' => 0, 'minimum_balance' => 0],
    ]);
    DB::table('card_type')->insert(['type_name' => 'debit gold', 'daily_limit' => 1000]);
    DB::table('department')->insert(['department_name' => 'customer SERVICE']);

    runMigration('2026_10_05_000003_title_case_reference_names');
    runMigration('2026_10_05_000003_title_case_reference_names'); // safe to run twice

    expect(DB::table('account_type')->orderBy('account_type_id')->pluck('type_name')->all())->toBe(['Savings', 'Current'])
        ->and(DB::table('card_type')->value('type_name'))->toBe('Debit Gold')
        ->and(DB::table('department')->value('department_name'))->toBe('Customer Service');
});

it('changes nothing when two names would clash', function () {
    // The column's collation is case-insensitive, so the clash comes from spacing.
    DB::table('card_type')->insert([
        ['type_name' => 'DEBIT GOLD', 'daily_limit' => 1000],
        ['type_name' => ' debit gold', 'daily_limit' => 2000],
    ]);
    DB::table('department')->insert(['department_name' => 'OPERATIONS']);

    expect(fn () => runMigration('2026_10_05_000003_title_case_reference_names'))
        ->toThrow(RuntimeException::class, 'card_type: these names would clash');

    expect(DB::table('card_type')->orderBy('card_type_id')->pluck('type_name')->all())->toBe(['DEBIT GOLD', ' debit gold'])
        ->and(DB::table('department')->value('department_name'))->toBe('OPERATIONS');
});

it('deletes unused credit card types and keeps debit ones', function () {
    DB::table('card_type')->insert([
        ['type_name' => 'Credit Platinum', 'daily_limit' => 1000],
        ['type_name' => 'Debit Classic', 'daily_limit' => 1000],
    ]);

    runMigration('2026_10_05_000002_remove_credit_card_types');

    expect(DB::table('card_type')->pluck('type_name')->all())->toBe(['Debit Classic']);
});

it('stops instead of deleting a credit type that cards use', function () {
    $customer = bankCustomer();
    $account = accountFor($customer, '100.00');
    $credit = DB::table('card_type')->insertGetId(['type_name' => 'Credit Platinum', 'daily_limit' => 1000], 'card_type_id');
    cardFor($account, 'ACTIVE', ['card_type_id' => $credit]);

    expect(fn () => runMigration('2026_10_05_000002_remove_credit_card_types'))
        ->toThrow(RuntimeException::class, 'Credit Platinum');

    $this->assertDatabaseHas('card_type', ['card_type_id' => $credit]);
});

it('refuses to change bank_card while it holds rows', function () {
    $migration = require database_path('migrations/2026_10_05_000001_extend_bank_card_for_requests.php');
    cardFor(accountFor(bankCustomer(), '100.00'), 'REQUESTED');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'it has 1 row(s). Nothing was changed.');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'it has 1 row(s). Nothing was changed.');
});

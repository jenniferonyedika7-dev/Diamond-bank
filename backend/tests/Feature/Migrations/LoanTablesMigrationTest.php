<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('refuses to change the loan table once it holds loans, changing nothing', function () {
    $customer = bankCustomer();
    loanFor($customer, accountFor($customer, '100.00'));
    $migration = require database_path('migrations/2026_10_06_000001_create_loan_tables.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'only safe while loan is empty; it has 1 row(s). Nothing was changed.')
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Nothing was changed.');

    expect(Schema::hasTable('loan_type'))->toBeTrue()
        ->and(Schema::hasColumn('loan', 'repayment_plan'))->toBeTrue()
        ->and(DB::table('loan')->count())->toBe(1);
});

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function loanPaymentMigration(): object
{
    return require database_path('migrations/2026_10_08_000001_extend_loan_payment_for_repayments.php');
}

function loanPaymentIndexes(): array
{
    return collect(Schema::getIndexes('loan_payment'))->pluck('name')->sort()->values()->all();
}

it('refuses to run either way once loan_payment holds payments, changing nothing', function () {
    $customer = bankCustomer();
    $account = accountFor($customer, '20000.00');
    $loan = activeLoanFor($customer, $account);
    DB::select('CALL sp_repay_loan(?, ?, ?, ?, ?, ?, ?)', [$loan->loan_id, 'INSTALMENT', 'ONLINE', $account->account_id, 1, '888.49', $customer->user_id]);
    $migration = loanPaymentMigration();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'only safe while loan_payment is empty; it has 1 row(s). Nothing was changed.')
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Nothing was changed.');

    expect(Schema::hasColumns('loan_payment', ['payment_type', 'channel', 'interest_waived', 'received_by']))->toBeTrue()
        ->and(DB::table('loan_payment')->count())->toBe(1);
});

it('rolls back and reapplies cleanly, restoring the foreign key index, and refuses paid instalments', function () {
    $customer = bankCustomer();
    $loan = activeLoanFor($customer, accountFor($customer, '100.00'));
    $migration = loanPaymentMigration();
    $migrated = loanPaymentIndexes();

    try {
        $migration->down();

        expect(Schema::hasColumn('loan_payment', 'payment_type'))->toBeFalse()
            ->and(Schema::hasColumn('loan_instalment', 'amount_paid'))->toBeFalse()
            ->and(loanPaymentIndexes())->toBe(['loan_payment_branch_id_foreign', 'loan_payment_loan_id_foreign', 'primary']);

        // A paid instalment (possible only before this migration) makes up() refuse.
        DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->where('instalment_number', 1)->update(['status' => 'PAID', 'paid_at' => now()]);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'only safe while loan_instalment (paid or settled rows) is empty; it has 1 row(s).');
        expect(Schema::hasColumn('loan_payment', 'payment_type'))->toBeFalse();

        DB::table('loan_instalment')->where('loan_id', $loan->loan_id)->update(['status' => 'UNPAID', 'paid_at' => null]);
    } finally {
        // Leave the schema migrated for the tests that follow.
        if (! Schema::hasColumn('loan_payment', 'payment_type')) {
            DB::table('loan_instalment')->update(['status' => 'UNPAID', 'paid_at' => null]);
            $migration->up();
        }
    }

    expect(loanPaymentIndexes())->toBe($migrated)
        ->and($migrated)->toContain('idx_loan_payment_loan_date', 'loan_payment_transaction_id_unique')
        ->and($migrated)->not->toContain('loan_payment_loan_id_foreign')
        ->and(Schema::hasColumns('loan_instalment', ['amount_paid', 'interest_waived']))->toBeTrue();
});

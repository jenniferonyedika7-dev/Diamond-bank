<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionTypeSeeder extends Seeder
{
    /**
     * Seed the transaction_type lookup table. Safe to run repeatedly.
     * sp_open_account and sp_transfer_funds depend on these rows.
     */
    public function run(): void
    {
        $types = ['DEPOSIT', 'WITHDRAWAL', 'TRANSFER_OUT', 'TRANSFER_IN', 'LOAN_DISBURSEMENT', 'LOAN_PAYMENT'];

        foreach ($types as $typeName) {
            DB::table('transaction_type')->updateOrInsert(['type_name' => $typeName]);
        }
    }
}

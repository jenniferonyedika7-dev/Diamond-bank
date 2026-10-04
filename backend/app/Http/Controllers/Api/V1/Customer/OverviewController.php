<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OverviewController extends CustomerAreaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);

        $customer = DB::table('customer')
            ->join('branch', 'branch.branch_id', '=', 'customer.branch_id')
            ->where('customer.customer_id', $customerId)
            ->first(['customer.first_name', 'customer.last_name', 'customer.kyc_status', 'branch.branch_name']);

        $accounts = DB::table('account')
            ->join('account_type', 'account_type.account_type_id', '=', 'account.account_type_id')
            ->where('account.customer_id', $customerId)
            ->orderBy('account.opened_date')
            ->orderBy('account.account_id')
            ->get(['account.account_number', 'account_type.type_name', 'account_type.minimum_balance',
                'account.balance', 'account.currency_code', 'account.status']);

        $totals = DB::table('account')
            ->where('customer_id', $customerId)
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get(['currency_code', DB::raw('SUM(balance) AS balance')]);

        $recent = DB::table('transactions')
            ->join('account', 'account.account_id', '=', 'transactions.account_id')
            ->join('transaction_type', 'transaction_type.transaction_type_id', '=', 'transactions.transaction_type_id')
            ->leftJoin('branch', 'branch.branch_id', '=', 'transactions.branch_id')
            ->where('account.customer_id', $customerId)
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.transaction_id')
            ->limit(5)
            ->get(['transactions.transaction_id', 'account.account_number', 'transaction_type.type_name', 'transactions.amount',
                'transactions.balance_after', 'transactions.channel', 'branch.branch_name', 'transactions.description', 'transactions.transaction_date']);

        return ApiResponse::success('Overview.', [
            'customer' => $customer,
            'accounts' => $accounts,
            'totals' => $totals,
            'recent_transactions' => $recent,
        ]);
    }
}

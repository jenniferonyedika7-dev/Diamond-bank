<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends CustomerAreaController
{
    public function show(Request $request, string $accountNumber): JsonResponse
    {
        $account = $this->ownAccount($request, $accountNumber)->load(['accountType', 'branch']);

        return ApiResponse::success('Account.', [
            ...$account->only(['account_number', 'balance', 'currency_code', 'status', 'opened_date']),
            'account_type' => $account->accountType->only(['type_name', 'interest_rate', 'minimum_balance']),
            'branch_name' => $account->branch->branch_name,
        ]);
    }

    public function transactions(Request $request, string $accountNumber): JsonResponse
    {
        $account = $this->ownAccount($request, $accountNumber);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::exists('transaction_type', 'type_name')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = DB::table('transactions')
            ->join('transaction_type', 'transaction_type.transaction_type_id', '=', 'transactions.transaction_type_id')
            ->leftJoin('branch', 'branch.branch_id', '=', 'transactions.branch_id')
            ->where('transactions.account_id', $account->account_id)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('transaction_type.type_name', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('transactions.transaction_date', '>=', $v.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('transactions.transaction_date', '<=', $v.' 23:59:59'))
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.transaction_id')
            ->select(['transactions.transaction_id', 'transaction_type.type_name', 'transactions.amount', 'transactions.balance_after',
                'transactions.channel', 'branch.branch_name', 'transactions.description', 'transactions.transaction_date'])
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated('Transactions.', $transactions);
    }
}

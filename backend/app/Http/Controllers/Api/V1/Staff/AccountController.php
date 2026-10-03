<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Requests\ReasonRequest;
use App\Http\Requests\Staff\CashRequest;
use App\Http\Requests\Staff\OpenAccountRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\AccountType;
use App\Support\StoredProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Balances change only through the stored procedures. A procedure's refusal
 * (SQLSTATE 45000) becomes a 422 with its message (see bootstrap/app.php).
 */
class AccountController extends StaffAreaController
{
    /** Read-only list for the "Open account" form (admins manage types under /admin). */
    public function types(): JsonResponse
    {
        return ApiResponse::success('Account types.', AccountType::query()->orderBy('type_name')->get());
    }

    /** Opens the account at the staff member's current branch via sp_open_account. */
    public function store(OpenAccountRequest $request): JsonResponse
    {
        $result = StoredProcedure::call('sp_open_account', [
            $request->validated('customer_id'),
            $request->validated('account_type_id'),
            $this->branch($request)->branch_id,
            $request->validated('initial_deposit'),
            $request->validated('currency_code') ?? 'GMD',
            $request->user()->user_id,
        ]);

        return ApiResponse::success("Account {$result->account_number} opened.", $result, 201);
    }

    public function show(Account $account): JsonResponse
    {
        $account->load(['accountType', 'customer', 'branch']);

        return ApiResponse::success('Account.', [
            ...$account->only(['account_id', 'account_number', 'balance', 'currency_code', 'status', 'opened_date']),
            'account_type' => $account->accountType->only(['account_type_id', 'type_name', 'interest_rate', 'minimum_balance']),
            'customer' => [
                'customer_id' => $account->customer->customer_id,
                'name' => "{$account->customer->first_name} {$account->customer->last_name}",
                'kyc_status' => $account->customer->kyc_status,
            ],
            'branch' => $account->branch->only(['branch_id', 'branch_name', 'branch_code']),
        ]);
    }

    public function deposit(CashRequest $request, Account $account): JsonResponse
    {
        return $this->cash('sp_deposit', 'Deposit', $request, $account);
    }

    public function withdraw(CashRequest $request, Account $account): JsonResponse
    {
        return $this->cash('sp_withdraw', 'Withdrawal', $request, $account);
    }

    public function freeze(ReasonRequest $request, Account $account): JsonResponse
    {
        return $this->changeStatus($account, 'ACTIVE', 'FROZEN', 'ACCOUNT_FROZEN', 'Only an active account can be frozen.', 'Account frozen.',
            ['reason' => $request->validated('reason')]);
    }

    public function unfreeze(Account $account): JsonResponse
    {
        return $this->changeStatus($account, 'FROZEN', 'ACTIVE', 'ACCOUNT_UNFROZEN', 'Only a frozen account can be unfrozen.', 'Account unfrozen.');
    }

    public function transactions(Request $request, Account $account): JsonResponse
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']])['per_page'] ?? 20;

        $transactions = DB::table('transactions')
            ->join('transaction_type', 'transaction_type.transaction_type_id', '=', 'transactions.transaction_type_id')
            ->leftJoin('branch', 'branch.branch_id', '=', 'transactions.branch_id')
            ->where('transactions.account_id', $account->account_id)
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.transaction_id')
            ->select(['transactions.transaction_id', 'transaction_type.type_name', 'transactions.amount', 'transactions.balance_after',
                'transactions.channel', 'branch.branch_name', 'transactions.description', 'transactions.transaction_date'])
            ->paginate($perPage);

        return ApiResponse::paginated('Transactions.', $transactions);
    }

    private function cash(string $procedure, string $label, CashRequest $request, Account $account): JsonResponse
    {
        $result = StoredProcedure::call($procedure, [
            $account->account_number,
            // As sent, so the procedure can reject more than 2 decimals rather than PHP rounding them.
            (string) $request->input('amount'),
            $request->validated('description'),
            $request->user()->user_id,
        ]);

        return ApiResponse::success("{$label} successful.", $result);
    }

    /** @param  array<string, mixed>  $extraDetails */
    private function changeStatus(Account $account, string $from, string $to, string $action, string $conflict, string $message, array $extraDetails = []): JsonResponse
    {
        return DB::transaction(function () use ($account, $from, $to, $action, $conflict, $message, $extraDetails) {
            $status = DB::table('account')->where('account_id', $account->account_id)->lockForUpdate()->value('status');

            if ($status === 'CLOSED') {
                return ApiResponse::error("Closed accounts can't be changed.", 409);
            }
            if ($status !== $from) {
                return ApiResponse::error($conflict, 409);
            }

            DB::table('account')->where('account_id', $account->account_id)->update(['status' => $to]);

            $this->audit->log($action, 'account', $account->account_id, [
                'account_number' => $account->account_number,
                'before' => ['status' => $status],
                'after' => ['status' => $to],
                ...$extraDetails,
            ]);

            return ApiResponse::success($message);
        });
    }
}

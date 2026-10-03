<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\AccountTypeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountTypeController extends AdminController
{
    public function index(): JsonResponse
    {
        $types = AccountType::query()
            ->select('account_type.*')
            ->selectSub(DB::table('account')->selectRaw('count(*)')->whereColumn('account.account_type_id', 'account_type.account_type_id'), 'accounts_count')
            ->orderBy('type_name')
            ->get();

        return ApiResponse::success('Account types.', $types);
    }

    public function store(AccountTypeRequest $request): JsonResponse
    {
        $type = DB::transaction(function () use ($request) {
            $type = AccountType::create($request->validated());
            $this->audit->log('ACCOUNT_TYPE_CREATED', 'account_type', $type->account_type_id, ['before' => null, 'after' => $this->snapshot($type)]);

            return $type;
        });

        return ApiResponse::success('Account type created.', $type->refresh(), 201);
    }

    public function update(AccountTypeRequest $request, AccountType $accountType): JsonResponse
    {
        DB::transaction(function () use ($request, $accountType) {
            $before = $this->snapshot($accountType);
            $accountType->update($request->validated());
            $this->audit->log('ACCOUNT_TYPE_UPDATED', 'account_type', $accountType->account_type_id, ['before' => $before, 'after' => $this->snapshot($accountType->refresh())]);
        });

        return ApiResponse::success('Account type updated.', $accountType);
    }

    public function destroy(AccountType $accountType): JsonResponse
    {
        return $this->deleteUnlessUsed($accountType, 'account type', [
            'account' => ['account_type_id', 'account', 'accounts'],
        ], 'ACCOUNT_TYPE_DELETED', 'Account type deleted.');
    }
}

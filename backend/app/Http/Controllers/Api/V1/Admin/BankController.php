<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\UpsertBankRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** The system has exactly one bank row: PUT creates it the first time, then updates it. */
class BankController extends AdminController
{
    public function show(): JsonResponse
    {
        $bank = Bank::query()->first();

        return ApiResponse::success($bank ? 'Bank details.' : 'The bank has not been set up yet.', $bank);
    }

    public function upsert(UpsertBankRequest $request): JsonResponse
    {
        [$bank, $created] = DB::transaction(function () use ($request) {
            $bank = Bank::query()->lockForUpdate()->first();

            if ($bank === null) {
                $bank = Bank::create($request->validated());
                $this->audit->log('BANK_CREATED', 'bank', $bank->bank_id, ['before' => null, 'after' => $this->snapshot($bank)]);

                return [$bank, true];
            }

            $before = $this->snapshot($bank);
            $bank->update($request->validated());
            $this->audit->log('BANK_UPDATED', 'bank', $bank->bank_id, ['before' => $before, 'after' => $this->snapshot($bank)]);

            return [$bank, false];
        });

        return $created
            ? ApiResponse::success('Bank details saved.', $bank, 201)
            : ApiResponse::success('Bank details updated.', $bank);
    }
}

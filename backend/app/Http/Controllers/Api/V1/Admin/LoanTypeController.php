<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\LoanTypeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\LoanType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Changing a rate affects new applications only: each loan keeps the rate it was quoted. */
class LoanTypeController extends AdminController
{
    public function index(): JsonResponse
    {
        $types = LoanType::query()
            ->select('loan_type.*')
            ->selectSub(DB::table('loan')->selectRaw('count(*)')->whereColumn('loan.loan_type_id', 'loan_type.loan_type_id'), 'loans_count')
            ->orderBy('type_name')
            ->get();

        return ApiResponse::success('Loan types.', $types);
    }

    public function store(LoanTypeRequest $request): JsonResponse
    {
        $type = DB::transaction(function () use ($request) {
            $type = LoanType::create($request->validated());
            $this->audit->log('LOAN_TYPE_CREATED', 'loan_type', $type->loan_type_id, ['before' => null, 'after' => $this->snapshot($type)]);

            return $type;
        });

        return ApiResponse::success('Loan type created.', $type->refresh(), 201);
    }

    public function update(LoanTypeRequest $request, LoanType $loanType): JsonResponse
    {
        DB::transaction(function () use ($request, $loanType) {
            $before = $this->snapshot($loanType);
            $loanType->update($request->validated());
            $this->audit->log('LOAN_TYPE_UPDATED', 'loan_type', $loanType->loan_type_id, ['before' => $before, 'after' => $this->snapshot($loanType->refresh())]);
        });

        return ApiResponse::success('Loan type updated.', $loanType);
    }

    public function destroy(LoanType $loanType): JsonResponse
    {
        return $this->deleteUnlessUsed($loanType, 'loan type', [
            'loan' => ['loan_type_id', 'loan', 'loans'],
        ], 'LOAN_TYPE_DELETED', 'Loan type deleted.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\BranchRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Bank;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');

        $branches = Branch::query()
            ->select('branch.*')
            ->selectSub(DB::table('customer')->selectRaw('count(*)')->whereColumn('customer.branch_id', 'branch.branch_id'), 'customers_count')
            ->selectSub(DB::table('account')->selectRaw('count(*)')->whereColumn('account.branch_id', 'branch.branch_id'), 'accounts_count')
            ->selectSub(
                DB::table('employee_branch_lnk')->selectRaw('count(*)')->whereColumn('employee_branch_lnk.branch_id', 'branch.branch_id')->whereNull('end_date'),
                'staff_count',
            )
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('branch_name', 'like', "%{$search}%")
                ->orWhere('branch_code', 'like', "%{$search}%")))
            ->orderBy('branch_name')
            ->paginate($filters['per_page'] ?? 15);

        return ApiResponse::paginated('Branches.', $branches);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = DB::transaction(function () use ($request) {
            $branch = Branch::create([...$request->validated(), 'bank_id' => Bank::query()->value('bank_id')]);
            $this->audit->log('BRANCH_CREATED', 'branch', $branch->branch_id, ['before' => null, 'after' => $this->snapshot($branch)]);

            return $branch;
        });

        return ApiResponse::success('Branch created.', $branch, 201);
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        DB::transaction(function () use ($request, $branch) {
            $before = $this->snapshot($branch);
            $branch->update($request->validated());
            $this->audit->log('BRANCH_UPDATED', 'branch', $branch->branch_id, ['before' => $before, 'after' => $this->snapshot($branch)]);
        });

        return ApiResponse::success('Branch updated.', $branch);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        return $this->deleteUnlessUsed($branch, 'branch', [
            'customer' => ['branch_id', 'customer', 'customers'],
            'account' => ['branch_id', 'account', 'accounts'],
            'employee_branch_lnk' => ['branch_id', 'employee assignment', 'employee assignments'],
            'loan' => ['branch_id', 'loan', 'loans'],
            'loan_payment' => ['branch_id', 'loan payment', 'loan payments'],
            'transactions' => ['branch_id', 'transaction', 'transactions'],
        ], 'BRANCH_DELETED', 'Branch deleted.');
    }
}

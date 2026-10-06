<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Loan;
use App\Queries\LoanDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared by the staff and admin loan controllers. loanBranchId() scopes every
 * lookup: staff see only loans at their current branch (others get the same
 * 404 as a missing loan); admins see every branch (null). Expects $this->audit.
 */
trait ReviewsLoans
{
    /** The branch the user is limited to, or null for every branch. */
    abstract protected function loanBranchId(Request $request): ?int;

    protected function listLoans(Request $request, string $defaultStatus): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Loan::STATUSES)],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');
        $branchId = $this->loanBranchId($request) ?? ($filters['branch_id'] ?? null);

        $loans = LoanDirectory::query()
            ->where('loan.status', $filters['status'] ?? $defaultStatus)
            ->when($branchId !== null, fn ($q) => $q->where('loan.branch_id', $branchId))
            ->when($search !== '', fn ($q) => LoanDirectory::search($q, $search))
            ->paginate($filters['per_page'] ?? 20)
            ->through(fn ($row) => LoanDirectory::presentForStaff($row));

        return ApiResponse::paginated('Loans.', $loans, ['counts' => $this->statusCounts($request)]);
    }

    protected function showLoan(Request $request, string $loanId): JsonResponse
    {
        $row = LoanDirectory::query()
            ->where('loan.loan_id', $loanId)
            ->when($this->loanBranchId($request) !== null, fn ($q) => $q->where('loan.branch_id', $this->loanBranchId($request)))
            ->first();

        return $row === null ? $this->loanNotFound() : ApiResponse::success('Loan.', LoanDirectory::staffDetail($row));
    }

    /** The applicant's transactions on all their accounts over the last few months (config bank.loans.statement_months). */
    protected function loanStatement(Request $request, string $loanId): JsonResponse
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']])['per_page'] ?? 20;

        $loan = $this->findLoan($request, $loanId);
        if ($loan === null) {
            return $this->loanNotFound();
        }

        return ApiResponse::paginated('Statement.', LoanDirectory::statement($loan->customer_id, $perPage), [
            'months' => config('bank.loans.statement_months'),
        ]);
    }

    /** $expected -> REJECTED with the reason, which the customer sees. */
    protected function rejectLoan(ReasonRequest $request, string $loanId, string $expected, string $by): JsonResponse
    {
        $reason = $request->validated('reason');

        return DB::transaction(function () use ($request, $loanId, $expected, $by, $reason) {
            $loan = $this->findLoan($request, $loanId, lock: true);
            if ($loan === null) {
                return $this->loanNotFound();
            }
            if ($loan->status !== $expected) {
                return ApiResponse::error($expected === 'PENDING'
                    ? 'Only a pending loan application can be rejected here.'
                    : 'Only a loan awaiting admin approval can be rejected here.', 409);
            }

            DB::table('loan')->where('loan_id', $loan->loan_id)->update([
                'status' => 'REJECTED',
                'rejected_by' => $request->user()->employee_id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log('LOAN_REJECTED', 'loan', $loan->loan_id, [
                'by' => $by,
                'before' => ['status' => $expected],
                'after' => ['status' => 'REJECTED'],
                'reason' => $reason,
            ]);

            return ApiResponse::success('Loan application rejected.');
        });
    }

    /** The loan row if the user may see it, optionally locked FOR UPDATE. */
    protected function findLoan(Request $request, string $loanId, bool $lock = false): ?object
    {
        $branchId = $this->loanBranchId($request);

        return DB::table('loan')
            ->where('loan_id', $loanId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    protected function loanNotFound(): JsonResponse
    {
        return ApiResponse::error('Loan not found.', 404);
    }

    /** @return array<string, int> loans per status, for the tabs */
    private function statusCounts(Request $request): array
    {
        $branchId = $this->loanBranchId($request);
        $counts = DB::table('loan')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('status')
            ->pluck(DB::raw('count(*)'), 'status');

        return collect(Loan::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ReviewsLoans;
use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Loan;
use App\Support\Amortisation;
use App\Support\Money;
use App\Support\StoredProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The final (second) approval of loans at every branch. Approving calls
 * sp_disburse_loan, which re-checks the loan and the schedule built here,
 * pays the loan into the customer's account and activates it in one
 * transaction. A loan that is no longer AWAITING_ADMIN (approved already,
 * cancelled, rejected) gets 409.
 */
class LoanController extends AdminController
{
    use ReviewsLoans;

    public function index(Request $request): JsonResponse
    {
        return $this->listLoans($request, 'AWAITING_ADMIN');
    }

    public function show(Request $request, string $loanId): JsonResponse
    {
        return $this->showLoan($request, $loanId);
    }

    public function statement(Request $request, string $loanId): JsonResponse
    {
        return $this->loanStatement($request, $loanId);
    }

    public function approve(Request $request, string $loanId): JsonResponse
    {
        $loan = Loan::find($loanId);
        if ($loan === null) {
            return $this->loanNotFound();
        }

        // Due dates count from today (UTC); the procedure checks them against UTC_DATE().
        $schedule = Amortisation::quote(
            (string) $loan->loan_amount,
            (string) $loan->interest_rate,
            (int) $loan->loan_term_months,
            $loan->repayment_plan,
            now('UTC')->startOfDay(),
        )['schedule'];

        $result = StoredProcedure::call('sp_disburse_loan', [$loan->loan_id, json_encode($schedule), $request->user()->user_id]);

        return ApiResponse::success(
            'Loan approved. '.Money::dalasi((string) $result->amount)." was paid into account {$result->account_number}.",
            $result,
        );
    }

    public function reject(ReasonRequest $request, string $loanId): JsonResponse
    {
        return $this->rejectLoan($request, $loanId, 'AWAITING_ADMIN', 'admin');
    }

    protected function loanBranchId(Request $request): ?int
    {
        return null;
    }
}

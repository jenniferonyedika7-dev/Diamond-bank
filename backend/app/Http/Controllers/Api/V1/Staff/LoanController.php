<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Concerns\RecordsLoanPayments;
use App\Http\Controllers\Concerns\ReviewsLoans;
use App\Http\Requests\LoanPaymentRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Loans at the staff member's current branch (loan.branch_id, the branch of
 * the disbursement account). Staff record the four checks, then make the first
 * approval (PENDING -> AWAITING_ADMIN) or reject. An admin makes the second
 * approval, which disburses (sp_disburse_loan). Once a loan is ACTIVE, staff
 * record cash payments for it (sp_repay_loan, channel BRANCH).
 */
class LoanController extends StaffAreaController
{
    use RecordsLoanPayments;
    use ReviewsLoans;

    public function index(Request $request): JsonResponse
    {
        return $this->listLoans($request, 'PENDING');
    }

    public function show(Request $request, string $loanId): JsonResponse
    {
        return $this->showLoan($request, $loanId);
    }

    public function statement(Request $request, string $loanId): JsonResponse
    {
        return $this->loanStatement($request, $loanId);
    }

    /** Records (or corrects) one check while the loan is PENDING. Corrections keep their history in the audit log. */
    public function recordCheck(Request $request, string $loanId, string $checkType): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return DB::transaction(function () use ($request, $loanId, $checkType, $note) {
            $loan = $this->findLoan($request, $loanId, lock: true);
            if ($loan === null) {
                return $this->loanNotFound();
            }
            if ($loan->status !== 'PENDING') {
                return ApiResponse::error('Checks can only be recorded while the application is pending.', 409);
            }

            $existing = DB::table('loan_verification')->where('loan_id', $loan->loan_id)->where('check_type', $checkType)->first();
            $values = ['note' => $note, 'verified_by' => $request->user()->employee_id, 'verified_at' => now()];

            if ($existing === null) {
                DB::table('loan_verification')->insert(['loan_id' => $loan->loan_id, 'check_type' => $checkType, ...$values]);
            } else {
                DB::table('loan_verification')->where('loan_verification_id', $existing->loan_verification_id)->update($values);
            }

            $this->audit->log('LOAN_CHECK_RECORDED', 'loan', $loan->loan_id, [
                'check_type' => $checkType,
                'before' => $existing === null ? null : [
                    'note' => $existing->note, 'verified_by' => $existing->verified_by, 'verified_at' => $existing->verified_at,
                ],
                'after' => ['note' => $note, 'verified_by' => $values['verified_by'], 'verified_at' => $values['verified_at']->toDateTimeString()],
            ]);

            return ApiResponse::success(Loan::CHECK_LABELS[$checkType].': recorded.');
        });
    }

    /** First approval: PENDING -> AWAITING_ADMIN, once all four checks are recorded. */
    public function approve(Request $request, string $loanId): JsonResponse
    {
        // Maker-checker: the second approval is an admin's, so an admin can't also make the first.
        if ($request->user()->hasRole('admin')) {
            return ApiResponse::error('Staff make the first approval. Administrators give the final approval on the admin loans page.', 403);
        }

        return DB::transaction(function () use ($request, $loanId) {
            $loan = $this->findLoan($request, $loanId, lock: true);
            if ($loan === null) {
                return $this->loanNotFound();
            }
            if ($loan->status !== 'PENDING') {
                return ApiResponse::error('Only a pending loan application can be approved by staff.', 409);
            }

            $recorded = DB::table('loan_verification')->where('loan_id', $loan->loan_id)->pluck('check_type')->all();
            $missing = array_values(array_diff(Loan::CHECKS, $recorded));
            if ($missing !== []) {
                $labels = array_map(fn ($check) => Loan::CHECK_LABELS[$check], $missing);

                return ApiResponse::error('Record all four checks before approving. Missing: '.implode(', ', $labels).'.', 422, ['missing_checks' => $missing]);
            }

            DB::table('loan')->where('loan_id', $loan->loan_id)->update([
                'status' => 'AWAITING_ADMIN',
                'staff_approved_by' => $request->user()->employee_id,
                'staff_approved_at' => now(),
            ]);

            $this->audit->log('LOAN_APPROVED_STAFF', 'loan', $loan->loan_id, [
                'before' => ['status' => 'PENDING'],
                'after' => ['status' => 'AWAITING_ADMIN'],
                'amount' => $loan->loan_amount,
            ]);

            return ApiResponse::success('Loan approved. It now needs an administrator\'s approval.');
        });
    }

    public function reject(ReasonRequest $request, string $loanId): JsonResponse
    {
        return $this->rejectLoan($request, $loanId, 'PENDING', 'staff');
    }

    /** The next instalment and the pay-everything-now amount, for the cash payment form. */
    public function repayment(Request $request, string $loanId): JsonResponse
    {
        $loan = $this->findLoan($request, $loanId);

        return $loan === null ? $this->loanNotFound() : $this->quoteResponse($loan);
    }

    /** Records cash taken at this branch. No account or transaction is involved; received_by is this employee. */
    public function pay(LoanPaymentRequest $request, string $loanId): JsonResponse
    {
        $loan = $this->findLoan($request, $loanId);
        if ($loan === null) {
            return $this->loanNotFound();
        }

        return $this->recordPayment($request, $loan, 'BRANCH', null);
    }

    protected function loanBranchId(Request $request): ?int
    {
        return $this->branch($request)->branch_id;
    }
}

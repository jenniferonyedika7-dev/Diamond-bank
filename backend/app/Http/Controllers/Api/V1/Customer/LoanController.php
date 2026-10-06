<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Requests\Customer\LoanApplicationRequest;
use App\Http\Requests\Customer\LoanQuoteRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Loan;
use App\Models\LoanType;
use App\Queries\LoanDirectory;
use App\Support\Amortisation;
use App\Support\Tin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's loans: quote, apply, view, cancel. A customer has at most one
 * open loan (PENDING, AWAITING_ADMIN or ACTIVE); applying locks the customer
 * row so two applications can't both pass that check. Another customer's loan
 * gets the same 404 as a missing one.
 */
class LoanController extends CustomerAreaController
{
    public function types(): JsonResponse
    {
        return ApiResponse::success('Loan types.', LoanType::query()->orderBy('type_name')->get());
    }

    /** What the apply form shows but doesn't ask for: profile contact details, accounts and limits. */
    public function applyContext(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        $customer = DB::table('customer')->where('customer_id', $customerId)->first(['first_name', 'last_name', 'phone', 'email', 'kyc_status']);

        return ApiResponse::success('Loan application details.', [
            'contact' => [
                'name' => "{$customer->first_name} {$customer->last_name}",
                'phone' => $customer->phone,
                'email' => $customer->email,
                'hint' => 'These come from your profile. If they are wrong, ask your branch to update your profile before you apply.',
            ],
            'kyc_status' => $customer->kyc_status,
            'has_open_loan' => $this->hasOpenLoan($customerId),
            'accounts' => DB::table('account')
                ->join('account_type', 'account_type.account_type_id', '=', 'account.account_type_id')
                ->where('account.customer_id', $customerId)
                ->where('account.status', 'ACTIVE')
                ->orderBy('account.account_number')
                ->get(['account.account_number', 'account_type.type_name', 'account.balance', 'account.currency_code']),
            'loan_types' => LoanType::query()->orderBy('type_name')->get(),
            'limits' => config('bank.loans'),
            'purposes' => LoanApplicationRequest::PURPOSES,
            'employment_fields' => LoanApplicationRequest::EMPLOYMENT_FIELDS,
        ]);
    }

    /** The backend preview of a loan: instalment, totals and schedule. Nothing is saved. */
    public function quote(LoanQuoteRequest $request): JsonResponse
    {
        $this->customerId($request);
        $type = LoanType::findOrFail($request->validated('loan_type_id'));

        return ApiResponse::success('Loan quote.', [
            'loan_type' => $type->only(['loan_type_id', 'type_name']),
            ...$this->quoteFor($request, $type),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $loans = LoanDirectory::query()
            ->where('loan.customer_id', $this->customerId($request))
            ->get()
            ->map(fn ($row) => LoanDirectory::presentForCustomer($row));

        return ApiResponse::success('Loans.', $loans);
    }

    public function show(Request $request, string $loanId): JsonResponse
    {
        $row = LoanDirectory::query()
            ->where('loan.loan_id', $loanId)
            ->where('loan.customer_id', $this->customerId($request))
            ->first();

        return $row === null ? $this->notFound() : ApiResponse::success('Loan.', LoanDirectory::customerDetail($row));
    }

    public function store(LoanApplicationRequest $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        $type = LoanType::findOrFail($request->validated('loan_type_id'));

        return DB::transaction(function () use ($request, $customerId, $type) {
            // Serializes applications by this customer, so only one can pass the open-loan check.
            $customer = DB::table('customer')->where('customer_id', $customerId)->lockForUpdate()->first();

            if ($customer->kyc_status !== 'VERIFIED') {
                return ApiResponse::error('Your identity must be verified before you can apply for a loan.', 422);
            }
            if ($request->user()->status !== 'ACTIVE') {
                return ApiResponse::error('Your login is not active.', 422);
            }

            $account = DB::table('account')
                ->where('account_number', $request->validated('account_number'))
                ->where('customer_id', $customerId)
                ->first();
            if ($account === null || $account->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['account_number' => 'Choose one of your own active accounts.']);
            }

            if ($this->hasOpenLoan($customerId)) {
                return ApiResponse::error('You already have a loan application in progress or an active loan.', 409);
            }

            $quote = $this->quoteFor($request, $type);

            $loanId = DB::table('loan')->insertGetId([
                'customer_id' => $customerId,
                'branch_id' => $account->branch_id,
                'loan_type_id' => $type->loan_type_id,
                'account_id' => $account->account_id,
                'loan_amount' => $quote['amount'],
                'interest_rate' => $quote['interest_rate'],
                'loan_term_months' => $quote['term_months'],
                'repayment_plan' => $quote['repayment_plan'],
                'monthly_instalment' => $quote['monthly_instalment'],
                'total_interest' => $quote['total_interest'],
                'total_repayable' => $quote['total_repayable'],
                'status' => 'PENDING',
                'application_date' => now(),
            ], 'loan_id');

            $application = $request->application();
            DB::table('loan_application')->insert([
                'loan_id' => $loanId,
                ...$application,
                'contact_phone' => $customer->phone,
                'contact_email' => $customer->email,
            ]);

            $guarantor = $request->validated('guarantor');
            DB::table('loan_guarantor')->insert([
                'loan_id' => $loanId,
                ...array_intersect_key($guarantor, array_flip(['full_name', 'phone', 'occupation', 'address', 'email'])),
            ]);

            $this->audit->log('LOAN_APPLIED', 'loan', $loanId, [
                'before' => null,
                'after' => ['status' => 'PENDING'],
                'loan_type' => $type->type_name,
                'account_number' => $account->account_number,
                'amount' => $quote['amount'],
                'interest_rate' => $quote['interest_rate'],
                'term_months' => $quote['term_months'],
                'repayment_plan' => $quote['repayment_plan'],
                'total_repayable' => $quote['total_repayable'],
                'purpose_category' => $application['purpose_category'],
                'employment_type' => $application['employment_type'],
                'monthly_income' => $application['monthly_income'],
                'tin' => Tin::mask($application['tin']),
                'guarantor' => $guarantor['full_name'],
            ]);

            $row = LoanDirectory::query()->where('loan.loan_id', $loanId)->first();

            return ApiResponse::success('Loan application submitted. The bank will review it.', LoanDirectory::presentForCustomer($row), 201);
        });
    }

    /** PENDING or AWAITING_ADMIN -> CANCELLED. Once a loan is decided it can't be cancelled. */
    public function cancel(Request $request, string $loanId): JsonResponse
    {
        $customerId = $this->customerId($request);

        return DB::transaction(function () use ($loanId, $customerId) {
            $loan = DB::table('loan')->where('loan_id', $loanId)->where('customer_id', $customerId)->lockForUpdate()->first();

            if ($loan === null) {
                return $this->notFound();
            }
            if (! in_array($loan->status, ['PENDING', 'AWAITING_ADMIN'], true)) {
                return ApiResponse::error('Only a loan application that is still being reviewed can be cancelled.', 409);
            }

            DB::table('loan')->where('loan_id', $loan->loan_id)->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);

            $this->audit->log('LOAN_CANCELLED', 'loan', $loan->loan_id, [
                'before' => ['status' => $loan->status],
                'after' => ['status' => 'CANCELLED'],
            ]);

            return ApiResponse::success('Loan application cancelled.');
        });
    }

    /** @return array<string, mixed> */
    private function quoteFor(LoanQuoteRequest $request, LoanType $type): array
    {
        return Amortisation::quote(
            $request->amount(),
            (string) $type->interest_rate,
            (int) $request->validated('term_months'),
            $request->validated('repayment_plan'),
            now()->startOfDay(),
        );
    }

    private function hasOpenLoan(int $customerId): bool
    {
        return DB::table('loan')->where('customer_id', $customerId)->whereIn('status', Loan::OPEN_STATUSES)->exists();
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Loan not found.', 404);
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\LoanPaymentRequest;
use App\Http\Responses\ApiResponse;
use App\Queries\LoanDirectory;
use App\Support\Money;
use App\Support\StoredProcedure;
use Illuminate\Http\JsonResponse;

/**
 * Shared by the customer (ONLINE) and staff (BRANCH, cash) loan controllers.
 * The controller finds the loan within the user's scope first (404 otherwise);
 * sp_repay_loan then moves the money and records the payment.
 */
trait RecordsLoanPayments
{
    /** The quote for an ACTIVE loan the user may see; 409 for any other status. */
    protected function quoteResponse(object $loan, array $extra = []): JsonResponse
    {
        if ($loan->status !== 'ACTIVE') {
            return ApiResponse::error('This loan is not active.', 409);
        }

        return ApiResponse::success('Repayment quote.', [...LoanDirectory::repaymentQuote($loan), ...$extra]);
    }

    protected function recordPayment(LoanPaymentRequest $request, object $loan, string $channel, ?int $accountId): JsonResponse
    {
        $result = StoredProcedure::call('sp_repay_loan', [
            $loan->loan_id,
            $request->validated('payment_type'),
            $channel,
            $accountId,
            $request->instalmentNumber(),
            $request->amount(),
            $request->user()->user_id,
        ]);

        $amount = Money::dalasi((string) $result->amount);
        $closed = $result->loan_status === 'CLOSED';
        $message = $channel === 'ONLINE'
            ? "Payment received. {$amount} was paid from account {$result->account_number}.".($closed ? ' Your loan is now paid off.' : '')
            : "Cash payment of {$amount} recorded.".($closed ? ' The loan is now paid off.' : '');

        return ApiResponse::success($message, [
            'loan_payment_id' => $result->loan_payment_id,
            'payment_type' => $result->payment_type,
            'channel' => $result->channel,
            'amount' => (string) $result->amount,
            'principal' => (string) $result->principal,
            'interest_charged' => (string) $result->interest_charged,
            'interest_waived' => (string) $result->interest_waived,
            'transaction_id' => $result->transaction_id,
            'account_number' => $result->account_number,
            'balance_after' => $result->balance_after === null ? null : (string) $result->balance_after,
            'remaining_balance' => (string) $result->remaining_balance,
            'loan_status' => $result->loan_status,
        ], 201);
    }
}

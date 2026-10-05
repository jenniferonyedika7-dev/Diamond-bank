<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Requests\Customer\TransferRequest;
use App\Http\Responses\ApiResponse;
use App\Support\MaskedName;
use App\Support\StoredProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends CustomerAreaController
{
    /** One message for missing, frozen and closed accounts, so the endpoint can't be used to probe numbers. */
    private const CANNOT_RECEIVE = "This account can't receive transfers. Check the number and try again.";

    /** Who a transfer would go to, masked. Never returns balances, ids or the full name. */
    public function lookup(Request $request): JsonResponse
    {
        $this->customerId($request);
        $number = $request->validate(['to_account_number' => ['required', 'string', 'max:20']])['to_account_number'];

        $recipient = $this->recipient($number);
        if ($recipient === null) {
            return ApiResponse::error(self::CANNOT_RECEIVE, 422);
        }

        return ApiResponse::success('Recipient found.', [
            'account_number' => $number,
            'recipient' => MaskedName::of($recipient->first_name, $recipient->last_name),
            'can_receive' => true,
        ]);
    }

    /**
     * Confirms the password, checks the source is the customer's own account,
     * then lets sp_transfer_funds move the money (channel ONLINE, performer = this user).
     */
    public function store(TransferRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $this->confirmPassword($request, $data['password'], 'TRANSFER_PASSWORD_FAILED', [
            'from_account_number' => $data['from_account_number'],
            'to_account_number' => $data['to_account_number'],
            'amount' => $data['amount'],
        ]);

        $from = $this->findOwnAccount($request, $data['from_account_number']);
        if ($from === null) {
            throw ValidationException::withMessages(['from_account_number' => 'Choose one of your own accounts.']);
        }

        $result = StoredProcedure::call('sp_transfer_funds', [
            $from->account_id,
            $data['to_account_number'],
            // As sent, so the procedure sees the exact amount rather than a float.
            (string) $request->input('amount'),
            $data['description'] ?? null,
            'ONLINE',
            $user->user_id,
        ]);

        $recipient = $this->recipient($data['to_account_number'], activeOnly: false);

        return ApiResponse::success('Transfer complete.', [
            'transfer_id' => $result->transfer_id,
            'amount' => (string) $result->amount,
            'balance_after' => (string) $result->balance_after,
            'from_account_number' => $from->account_number,
            'to_account_number' => $data['to_account_number'],
            'recipient' => $recipient ? MaskedName::of($recipient->first_name, $recipient->last_name) : null,
        ], 201);
    }

    private function recipient(string $accountNumber, bool $activeOnly = true): ?object
    {
        return DB::table('account')
            ->join('customer', 'customer.customer_id', '=', 'account.customer_id')
            ->where('account.account_number', $accountNumber)
            ->when($activeOnly, fn ($q) => $q->where('account.status', 'ACTIVE'))
            ->first(['customer.first_name', 'customer.last_name']);
    }
}

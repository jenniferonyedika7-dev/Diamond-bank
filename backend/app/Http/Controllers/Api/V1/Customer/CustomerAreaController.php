<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Services\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the /api/v1/customer controllers. Everything is scoped to the
 * logged-in user's customer_id; account numbers from the browser are only
 * ever looked up together with that id.
 */
abstract class CustomerAreaController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    protected function customerId(Request $request): int
    {
        $customerId = $request->user()->customer_id;

        if ($customerId === null) {
            throw new HttpResponseException(ApiResponse::error('Your login is not linked to a customer profile.', 403));
        }

        return $customerId;
    }

    /** The account if it belongs to this customer, otherwise null (callers answer as if it didn't exist). */
    protected function findOwnAccount(Request $request, string $accountNumber): ?Account
    {
        return Account::query()
            ->where('account_number', $accountNumber)
            ->where('customer_id', $this->customerId($request))
            ->first();
    }

    /** Same 404 for someone else's account and a non-existent one, so numbers can't be probed. */
    protected function ownAccount(Request $request, string $accountNumber): Account
    {
        return $this->findOwnAccount($request, $accountNumber)
            ?? throw new HttpResponseException(ApiResponse::error('Account not found.', 404));
    }

    /**
     * Re-checks the customer's password before a sensitive action (a transfer,
     * revealing a card number). A wrong password writes $failedAction to the
     * audit log with $details (never the password) and fails with a 422 on
     * the password field. The calling route carries the throttle.
     *
     * @param  array<string, mixed>  $details
     */
    protected function confirmPassword(Request $request, string $password, string $failedAction, array $details): void
    {
        $user = $request->user();

        if (! Hash::check($password, $user->password)) {
            $this->audit->log($failedAction, 'users', $user->user_id, $details);

            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
    }
}

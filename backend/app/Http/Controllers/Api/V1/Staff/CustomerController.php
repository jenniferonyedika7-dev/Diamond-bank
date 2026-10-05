<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Requests\ReasonRequest;
use App\Http\Requests\Staff\UpdateCustomerRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Queries\CardDirectory;
use App\Queries\CustomerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends StaffAreaController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kyc_status' => ['nullable', 'in:PENDING,VERIFIED,REJECTED'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated('Customers.', CustomerDirectory::paginate($filters));
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['branch', 'addresses', 'user']);

        $accounts = CustomerDirectory::accounts($customer->customer_id);

        $cards = CardDirectory::query()
            ->where('account.customer_id', $customer->customer_id)
            ->get()
            ->map(fn ($row) => CardDirectory::present($row));

        $recent = DB::table('transactions')
            ->join('account', 'account.account_id', '=', 'transactions.account_id')
            ->join('transaction_type', 'transaction_type.transaction_type_id', '=', 'transactions.transaction_type_id')
            ->leftJoin('branch', 'branch.branch_id', '=', 'transactions.branch_id')
            ->where('account.customer_id', $customer->customer_id)
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.transaction_id')
            ->limit(10)
            ->get(['transactions.transaction_id', 'account.account_number', 'transaction_type.type_name', 'transactions.amount',
                'transactions.balance_after', 'transactions.channel', 'branch.branch_name', 'transactions.description', 'transactions.transaction_date']);

        return ApiResponse::success('Customer.', [
            ...$customer->only(['customer_id', 'first_name', 'last_name', 'gender', 'national_id', 'phone', 'email', 'kyc_status', 'branch_id']),
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'registration_date' => $customer->registration_date,
            'verified_at' => $customer->verified_at,
            'verified_by_name' => $customer->verified_by
                ? DB::table('employee')->where('employee_id', $customer->verified_by)->value('full_name')
                : null,
            'branch' => $customer->branch?->only(['branch_id', 'branch_name', 'branch_code']),
            'addresses' => $customer->addresses,
            'login' => $customer->user?->only(['user_id', 'user_name', 'status', 'last_login']),
            'accounts' => $accounts,
            'cards' => $cards,
            'recent_transactions' => $recent,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        DB::transaction(function () use ($request, $customer) {
            $before = $this->snapshot($customer);
            $customer->update($request->validated());
            $this->audit->log('CUSTOMER_UPDATED', 'customer', $customer->customer_id, ['before' => $before, 'after' => $this->snapshot($customer)]);
        });

        return ApiResponse::success('Customer details updated.');
    }

    /** KYC PENDING -> VERIFIED, recording who verified and when. */
    public function verify(Request $request, Customer $customer): JsonResponse
    {
        return $this->changeKyc($customer, 'VERIFIED', 'CUSTOMER_VERIFIED', 'Only customers with pending KYC can be verified.', 'Customer verified.', [
            'verified_by' => $request->user()->employee_id,
            'verified_at' => now()->toDateTimeString(),
        ]);
    }

    /** KYC PENDING -> REJECTED, with the reason in the audit details. */
    public function rejectKyc(ReasonRequest $request, Customer $customer): JsonResponse
    {
        return $this->changeKyc($customer, 'REJECTED', 'CUSTOMER_KYC_REJECTED', 'Only customers with pending KYC can be rejected.', 'KYC rejected.', [],
            ['reason' => $request->validated('reason')]);
    }

    /** Blocks the customer's login and ends their sessions. */
    public function block(ReasonRequest $request, Customer $customer): JsonResponse
    {
        return $this->changeLogin($customer, 'ACTIVE', 'BLOCKED', 'CUSTOMER_BLOCKED', 'Only an active login can be blocked.', 'Customer login blocked.',
            ['reason' => $request->validated('reason')]);
    }

    public function unblock(Customer $customer): JsonResponse
    {
        return $this->changeLogin($customer, 'BLOCKED', 'ACTIVE', 'CUSTOMER_UNBLOCKED', 'Only a blocked login can be unblocked.', 'Customer login unblocked.');
    }

    /**
     * @param  array<string, mixed>  $extraColumns
     * @param  array<string, mixed>  $extraDetails
     */
    private function changeKyc(Customer $customer, string $to, string $action, string $conflict, string $message, array $extraColumns = [], array $extraDetails = []): JsonResponse
    {
        return DB::transaction(function () use ($customer, $to, $action, $conflict, $message, $extraColumns, $extraDetails) {
            $from = DB::table('customer')->where('customer_id', $customer->customer_id)->lockForUpdate()->value('kyc_status');
            if ($from !== 'PENDING') {
                return ApiResponse::error($conflict, 409);
            }

            DB::table('customer')->where('customer_id', $customer->customer_id)->update(['kyc_status' => $to, ...$extraColumns]);

            $this->audit->log($action, 'customer', $customer->customer_id, [
                'before' => ['kyc_status' => $from],
                'after' => ['kyc_status' => $to, ...$extraColumns],
                ...$extraDetails,
            ]);

            return ApiResponse::success($message);
        });
    }

    /** @param  array<string, mixed>  $extraDetails */
    private function changeLogin(Customer $customer, string $from, string $to, string $action, string $conflict, string $message, array $extraDetails = []): JsonResponse
    {
        $user = $customer->user;
        if ($user === null) {
            return ApiResponse::error('This customer has no login.', 409);
        }

        return DB::transaction(function () use ($user, $from, $to, $action, $conflict, $message, $extraDetails) {
            $status = DB::table('users')->where('user_id', $user->user_id)->lockForUpdate()->value('status');
            if ($status !== $from) {
                return ApiResponse::error($conflict, 409);
            }

            DB::table('users')->where('user_id', $user->user_id)->update(['status' => $to, 'updated_at' => now()]);
            if ($to === 'BLOCKED') {
                $user->endSessions();
            }

            $this->audit->log($action, 'users', $user->user_id, [
                'user_name' => $user->user_name,
                'customer_id' => $user->customer_id,
                'before' => ['status' => $status],
                'after' => ['status' => $to],
                ...$extraDetails,
            ]);

            return ApiResponse::success($message);
        });
    }
}

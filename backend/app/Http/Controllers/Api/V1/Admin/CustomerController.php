<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Queries\CustomerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of customers and their accounts. Changes to customers and
 * accounts (KYC, freeze, block) are staff work at a branch; the only admin
 * action on a customer is renaming their login (UsernameController).
 */
class CustomerController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kyc_status' => ['nullable', 'in:PENDING,VERIFIED,REJECTED'],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated('Customers.', CustomerDirectory::paginate($filters));
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['branch', 'user']);

        return ApiResponse::success('Customer.', [
            ...$customer->only(['customer_id', 'first_name', 'last_name', 'gender', 'national_id', 'phone', 'email', 'kyc_status']),
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'registration_date' => $customer->registration_date,
            'branch' => $customer->branch?->only(['branch_id', 'branch_name', 'branch_code']),
            'login' => $customer->user?->only(['user_id', 'user_name', 'status', 'last_login']),
            'accounts' => CustomerDirectory::accounts($customer->customer_id),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Http\Requests\Auth\RegisterStaffRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Customer + ACTIVE customer login in one transaction. KYC starts PENDING. */
    public function customer(RegisterCustomerRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $customer = Customer::create([
                ...$request->safe()->only(['first_name', 'last_name', 'date_of_birth', 'gender', 'national_id', 'phone', 'email', 'branch_id']),
                'kyc_status' => 'PENDING',
            ]);

            $user = User::create([
                'role_id' => Role::idFor('customer'),
                'customer_id' => $customer->customer_id,
                'user_name' => $request->validated('user_name'),
                'password' => $request->validated('password'),
                'status' => 'ACTIVE',
            ]);

            $this->audit->log('CUSTOMER_REGISTERED', 'customer', $customer->customer_id, ['user_name' => $user->user_name], $user);

            return $user;
        });

        return ApiResponse::success('Registration successful. You can now log in.', [
            'user_id' => $user->user_id,
            'user_name' => $user->user_name,
        ], 201);
    }

    /** Employee + PENDING staff login in one transaction. An admin must approve it. */
    public function staff(RegisterStaffRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $employee = Employee::create([
                ...$request->safe()->only(['full_name', 'national_id', 'position', 'phone', 'email']),
                'hired_date' => now()->toDateString(),
            ]);

            $user = User::create([
                'role_id' => Role::idFor('staff'),
                'employee_id' => $employee->employee_id,
                'user_name' => $request->validated('user_name'),
                'password' => $request->validated('password'),
                'status' => 'PENDING',
            ]);

            $this->audit->log('STAFF_REGISTERED', 'employee', $employee->employee_id, ['user_name' => $user->user_name], $user);

            return $user;
        });

        return ApiResponse::success('Registration received. Your account is awaiting admin approval.', [
            'user_id' => $user->user_id,
            'user_name' => $user->user_name,
            'status' => 'PENDING',
        ], 201);
    }
}

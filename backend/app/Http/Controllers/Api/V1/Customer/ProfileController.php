<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only: customers can't edit their own KYC data; staff do that. */
class ProfileController extends CustomerAreaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $customer = Customer::with(['branch', 'addresses'])->findOrFail($this->customerId($request));

        return ApiResponse::success('Profile.', [
            ...$customer->only(['first_name', 'last_name', 'gender', 'national_id', 'phone', 'email', 'kyc_status']),
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'registration_date' => $customer->registration_date,
            'branch_name' => $customer->branch?->branch_name,
            'addresses' => $customer->addresses->map->only(['address_type', 'country_region', 'city_street', 'postal_code']),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\ChangeUsername;
use App\Http\Requests\Admin\UsernameRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Renames customer and staff logins. There is deliberately no admin route
 * for passwords: users change their own (auth/change-password).
 */
class UsernameController extends AdminController
{
    public function customer(UsernameRequest $request, Customer $customer, ChangeUsername $changeUsername): JsonResponse
    {
        $user = $customer->user;
        if ($user === null) {
            return ApiResponse::error('This customer has no login.', 409);
        }

        return $this->rename($request, $user, $changeUsername);
    }

    /** {staffUser} is resolved by StaffController::resolveStaffUser (admin accounts get 403). */
    public function staff(UsernameRequest $request, User $staffUser, ChangeUsername $changeUsername): JsonResponse
    {
        return $this->rename($request, $staffUser, $changeUsername);
    }

    private function rename(UsernameRequest $request, User $user, ChangeUsername $changeUsername): JsonResponse
    {
        $old = $user->user_name;
        $changeUsername($user, $request->validated('user_name'));

        return ApiResponse::success("Username changed from {$old} to {$user->user_name}.", $user->only(['user_id', 'user_name']));
    }
}

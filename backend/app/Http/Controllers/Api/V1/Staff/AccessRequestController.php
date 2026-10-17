<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Requests\Staff\AccessRequestRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AccessRequest;
use App\Models\Customer;
use App\Models\User;
use App\Services\AccessRecovery;
use App\Services\SmsSender;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff at the customer's home branch (CustomerPolicy, on the route) raise an
 * access request for a login locked by wrong passwords or holding an expired
 * temporary password. An administrator decides it (Admin\AccessRequestController).
 */
class AccessRequestController extends StaffAreaController
{
    public function store(AccessRequestRequest $request, Customer $customer, SmsSender $sms): JsonResponse
    {
        if ($customer->user === null) {
            return ApiResponse::error('This customer has no online login.', 409);
        }
        $branch = $this->branch($request);

        try {
            return DB::transaction(function () use ($request, $customer, $branch, $sms) {
                // Locks the login, so two staff can't raise a request at once and its status can't change underneath.
                $user = User::query()->with('role')->lockForUpdate()->findOrFail($customer->user->user_id);

                $reason = AccessRecovery::needs($user);
                if ($reason === null) {
                    return ApiResponse::error("This customer's login doesn't need an access request.", 409);
                }
                if (AccessRequest::query()->where('customer_id', $customer->customer_id)->where('status', 'PENDING')->exists()) {
                    return $this->alreadyPending();
                }
                if (! AccessRecovery::allows($reason, $request->validated('request_type'))) {
                    throw ValidationException::withMessages([
                        'request_type' => 'The temporary password has expired, so the customer needs a new one: choose unblock and reset.',
                    ]);
                }

                $id = DB::table('access_request')->insertGetId([
                    'customer_id' => $customer->customer_id,
                    'user_id' => $user->user_id,
                    'branch_id' => $branch->branch_id,
                    'reason' => $reason,
                    'contact_channel' => $request->validated('contact_channel'),
                    'dob_confirmed' => true,
                    'phone_confirmed' => true,
                    'history_reviewed' => true,
                    'declaration_confirmed' => true,
                    'request_type' => $request->validated('request_type'),
                    'notes' => $request->validated('notes'),
                    'status' => 'PENDING',
                    'requested_by' => $request->user()->user_id,
                    'requested_at' => now(),
                ], 'access_request_id');

                $this->audit->log('ACCESS_REQUEST_CREATED', 'access_request', $id, [
                    'customer_id' => $customer->customer_id,
                    'user_id' => $user->user_id,
                    'reason' => $reason,
                    'contact_channel' => $request->validated('contact_channel'),
                    'request_type' => $request->validated('request_type'),
                ]);
                $sms->send($user, "Diamond Bank: We received your account access request at {$branch->branch_name}. "
                    .'You will receive an SMS when it has been reviewed.', 'ACCESS_REQUEST_RECEIVED');

                return ApiResponse::success('Access request sent to the administrator for approval.', ['access_request_id' => $id, 'status' => 'PENDING'], 201);
            });
        } catch (QueryException $e) {
            // uq_access_request_one_pending: another request was raised at the same moment.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $this->alreadyPending();
            }
            throw $e;
        }
    }

    private function alreadyPending(): JsonResponse
    {
        return ApiResponse::error('This customer already has a pending access request.', 409);
    }
}

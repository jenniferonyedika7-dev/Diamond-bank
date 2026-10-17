<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AccessRequest;
use App\Models\User;
use App\Queries\CustomerDirectory;
use App\Services\AccessRecovery;
use App\Services\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administrators decide the access requests staff raise for customers locked
 * out of their login. Approving reopens the login (AccessRecovery::restore),
 * texting a temporary password for UNBLOCK_AND_RESET; rejecting texts the
 * customer to visit their branch. Whoever raised a request can't approve it.
 */
class AccessRequestController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:PENDING,APPROVED,REJECTED'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'PENDING';

        $requests = $this->query()
            ->where('ar.status', $status)
            ->orderBy($status === 'PENDING' ? 'ar.requested_at' : 'ar.reviewed_at', $status === 'PENDING' ? 'asc' : 'desc')
            ->orderByDesc('ar.access_request_id')
            ->paginate($filters['per_page'] ?? 15);

        return ApiResponse::paginated('Access requests.', $requests, [
            'pending_count' => AccessRequest::query()->where('status', 'PENDING')->count(),
        ]);
    }

    public function show(AccessRequest $accessRequest): JsonResponse
    {
        $row = $this->query()->where('ar.access_request_id', $accessRequest->access_request_id)->first();
        $customer = $accessRequest->customer()->with('branch')->first();
        $user = User::query()->with('role')->find($accessRequest->user_id);

        return ApiResponse::success('Access request.', [
            ...(array) $row,
            'checks' => $accessRequest->only(AccessRequest::CHECKS),
            'notes' => $accessRequest->notes,
            'customer' => [
                ...$customer->only(['customer_id', 'first_name', 'last_name', 'national_id', 'phone', 'email', 'kyc_status']),
                'date_of_birth' => $customer->date_of_birth?->toDateString(),
                'branch' => $customer->branch?->only(['branch_id', 'branch_name', 'branch_code']),
                'accounts' => CustomerDirectory::accounts($customer->customer_id),
            ],
            'login' => [
                ...$user->only(['user_id', 'user_name', 'status', 'last_login']),
                'recovery' => AccessRecovery::needs($user),
            ],
        ]);
    }

    public function approve(Request $request, AccessRequest $accessRequest, AccessRecovery $recovery): JsonResponse
    {
        return DB::transaction(function () use ($request, $accessRequest, $recovery) {
            $locked = AccessRequest::query()->lockForUpdate()->findOrFail($accessRequest->access_request_id);
            if ($locked->status !== 'PENDING') {
                return ApiResponse::error('Only a pending access request can be approved.', 409);
            }
            if ((int) $locked->requested_by === (int) $request->user()->user_id) {
                return ApiResponse::error('You raised this access request, so another administrator must approve it.', 403);
            }

            $user = User::query()->with('role')->lockForUpdate()->findOrFail($locked->user_id);
            $reason = AccessRecovery::needs($user);
            if ($reason === null) {
                return ApiResponse::error("This customer's login no longer needs an access request. Reject the request instead.", 409);
            }
            if (! AccessRecovery::allows($reason, $locked->request_type)) {
                return ApiResponse::error('The temporary password has expired, so unblocking alone would not let the customer sign in. Reject the request instead.', 409);
            }

            DB::table('access_request')->where('access_request_id', $locked->access_request_id)->update([
                'status' => 'APPROVED', 'reviewed_by' => $request->user()->user_id, 'reviewed_at' => now(),
            ]);
            $this->audit->log('ACCESS_REQUEST_APPROVED', 'access_request', $locked->access_request_id, [
                'customer_id' => $locked->customer_id, 'user_id' => $user->user_id, 'request_type' => $locked->request_type,
            ]);
            if ($user->status === 'BLOCKED') {
                $this->audit->log('CUSTOMER_UNBLOCKED', 'users', $user->user_id, [
                    'user_name' => $user->user_name,
                    'customer_id' => $user->customer_id,
                    'before' => ['status' => 'BLOCKED'],
                    'after' => ['status' => 'ACTIVE'],
                    'via' => 'ACCESS_REQUEST',
                    'access_request_id' => $locked->access_request_id,
                ]);
            }

            $recovery->restore($user, $locked->request_type);

            return ApiResponse::success($locked->request_type === AccessRecovery::UNBLOCK_AND_RESET
                ? 'Access request approved. The customer has been sent a temporary password by SMS.'
                : 'Access request approved. The customer has been told by SMS that their login is unblocked.');
        });
    }

    public function reject(ReasonRequest $request, AccessRequest $accessRequest, SmsSender $sms): JsonResponse
    {
        return DB::transaction(function () use ($request, $accessRequest, $sms) {
            $locked = AccessRequest::query()->lockForUpdate()->findOrFail($accessRequest->access_request_id);
            if ($locked->status !== 'PENDING') {
                return ApiResponse::error('Only a pending access request can be rejected.', 409);
            }

            DB::table('access_request')->where('access_request_id', $locked->access_request_id)->update([
                'status' => 'REJECTED', 'reviewed_by' => $request->user()->user_id, 'reviewed_at' => now(),
                'rejection_reason' => $request->validated('reason'),
            ]);
            $this->audit->log('ACCESS_REQUEST_REJECTED', 'access_request', $locked->access_request_id, [
                'customer_id' => $locked->customer_id, 'user_id' => $locked->user_id, 'reason' => $request->validated('reason'),
            ]);
            $sms->send(User::query()->findOrFail($locked->user_id),
                'Diamond Bank: We could not complete your account access request. Please visit your branch.', 'ACCESS_REQUEST_REJECTED');

            return ApiResponse::success('Access request rejected. The customer has been told by SMS.');
        });
    }

    /** One row per request: who it is for, where and by whom it was raised, and who decided it. */
    private function query()
    {
        return DB::table('access_request as ar')
            ->join('customer', 'customer.customer_id', '=', 'ar.customer_id')
            ->join('users as login', 'login.user_id', '=', 'ar.user_id')
            ->join('branch', 'branch.branch_id', '=', 'ar.branch_id')
            ->join('users as requester', 'requester.user_id', '=', 'ar.requested_by')
            ->leftJoin('employee as requester_emp', 'requester_emp.employee_id', '=', 'requester.employee_id')
            ->leftJoin('users as reviewer', 'reviewer.user_id', '=', 'ar.reviewed_by')
            ->leftJoin('employee as reviewer_emp', 'reviewer_emp.employee_id', '=', 'reviewer.employee_id')
            ->select([
                'ar.access_request_id', 'ar.customer_id', 'ar.reason', 'ar.request_type', 'ar.contact_channel', 'ar.status',
                'ar.requested_at', 'ar.reviewed_at', 'ar.rejection_reason',
                DB::raw("CONCAT(customer.first_name, ' ', customer.last_name) as customer_name"),
                'login.user_name', 'branch.branch_name', 'requester_emp.full_name as requested_by_name',
                'reviewer_emp.full_name as reviewed_by_name',
            ]);
    }
}

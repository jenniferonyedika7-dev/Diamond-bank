<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\ApproveStaffRequest;
use App\Http\Requests\Admin\RestoreAccessRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AccessRecovery;
use App\Services\PasswordLockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff accounts only. The {staffUser} route parameter is resolved by
 * resolveStaffUser(): customers are "not found" and admin accounts (including
 * the caller's own) are refused with 403 before any validation runs.
 */
class StaffController extends AdminController
{
    public static function resolveStaffUser(string $userId): User
    {
        $user = ctype_digit($userId) ? User::with('role')->find((int) $userId) : null;

        if ($user?->hasRole('admin')) {
            throw new HttpResponseException(ApiResponse::error("Admin accounts can't be changed from here.", 403));
        }

        if (! $user?->hasRole('staff')) {
            throw new HttpResponseException(ApiResponse::error('Staff member not found.', 404));
        }

        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:PENDING,ACTIVE,BLOCKED'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');

        $staff = DB::table('users')
            ->join('role', 'role.role_id', '=', 'users.role_id')
            ->join('employee', 'employee.employee_id', '=', 'users.employee_id')
            ->leftJoin('employee_branch_lnk as ebl', fn ($j) => $j->on('ebl.employee_id', '=', 'employee.employee_id')->whereNull('ebl.end_date'))
            ->leftJoin('branch', 'branch.branch_id', '=', 'ebl.branch_id')
            ->leftJoin('employee_department_lnk as edl', fn ($j) => $j->on('edl.employee_id', '=', 'employee.employee_id')->whereNull('edl.end_date'))
            ->leftJoin('department', 'department.department_id', '=', 'edl.department_id')
            ->where('role.role_name', 'staff')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('users.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('employee.full_name', 'like', "%{$search}%")
                ->orWhere('users.user_name', 'like', "%{$search}%")))
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.user_id')
            ->select([
                'users.user_id', 'users.user_name', 'users.status', 'users.temp_password_expires_at', 'users.created_at',
                'employee.full_name', 'employee.national_id', 'employee.position', 'employee.email', 'employee.phone',
                'branch.branch_id', 'branch.branch_name', 'department.department_id', 'department.department_name',
            ])
            ->paginate($filters['per_page'] ?? 15)
            ->through(fn ($row) => [
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'status' => $row->status,
                'full_name' => $row->full_name,
                'national_id' => $row->national_id,
                'position' => $row->position,
                'email' => $row->email,
                'phone' => $row->phone,
                'branch' => $row->branch_id ? ['branch_id' => $row->branch_id, 'branch_name' => $row->branch_name] : null,
                'department' => $row->department_id ? ['department_id' => $row->department_id, 'department_name' => $row->department_name] : null,
                'created_at' => $row->created_at,
                // LOCKOUT_BLOCKED or TEMP_PASSWORD_EXPIRED: restored with restoreAccess(), not unblock().
                'recovery' => $row->status === 'BLOCKED' || $row->temp_password_expires_at !== null
                    ? AccessRecovery::needs(User::with('role')->find($row->user_id))
                    : null,
            ]);

        return ApiResponse::paginated('Staff.', $staff);
    }

    /** PENDING -> ACTIVE, assigning a branch (and optionally a department) from today. */
    public function approve(ApproveStaffRequest $request, User $staffUser): JsonResponse
    {
        return DB::transaction(function () use ($request, $staffUser) {
            $status = $this->lockedStatus($staffUser);
            if ($status !== 'PENDING') {
                return ApiResponse::error('Only pending staff can be approved.', 409);
            }

            $today = now()->toDateString();
            $branchId = $request->validated('branch_id');
            $departmentId = $request->validated('department_id');

            $this->setStatus($staffUser, 'ACTIVE');
            DB::table('employee_branch_lnk')->insert([
                'employee_id' => $staffUser->employee_id, 'branch_id' => $branchId, 'start_date' => $today, 'end_date' => null,
            ]);
            if ($departmentId !== null) {
                DB::table('employee_department_lnk')->insert([
                    'employee_id' => $staffUser->employee_id, 'department_id' => $departmentId, 'start_date' => $today, 'end_date' => null,
                ]);
            }

            $this->audit->log('STAFF_APPROVED', 'users', $staffUser->user_id, [
                'user_name' => $staffUser->user_name,
                'before' => ['status' => $status],
                'after' => ['status' => 'ACTIVE', 'branch_id' => $branchId, 'department_id' => $departmentId],
            ]);

            return ApiResponse::success("{$staffUser->user_name} has been approved.");
        });
    }

    /** ACTIVE -> BLOCKED, ending their sessions immediately. */
    public function block(ReasonRequest $request, User $staffUser): JsonResponse
    {
        return $this->blockWithReason($staffUser, $request->validated('reason'), 'ACTIVE', 'STAFF_BLOCKED',
            'Only active staff can be blocked.', "{$staffUser->user_name} has been blocked.");
    }

    /** PENDING -> BLOCKED: a refused registration (the staff sign-up page is public). */
    public function reject(ReasonRequest $request, User $staffUser): JsonResponse
    {
        return $this->blockWithReason($staffUser, $request->validated('reason'), 'PENDING', 'STAFF_REJECTED',
            'Only pending staff can be rejected.', "{$staffUser->user_name}'s registration has been rejected.");
    }

    /**
     * A staff member locked out by wrong passwords (PasswordLockout), or holding
     * an expired temporary password, contacted the administrator, who checked
     * who they are. There is no higher approver, so this reopens the login
     * straight away (AccessRecovery::restore), texting a temporary password for
     * UNBLOCK_AND_RESET.
     */
    public function restoreAccess(RestoreAccessRequest $request, User $staffUser, AccessRecovery $recovery): JsonResponse
    {
        return DB::transaction(function () use ($request, $staffUser, $recovery) {
            $user = User::query()->with('role')->lockForUpdate()->findOrFail($staffUser->user_id);

            $reason = AccessRecovery::needs($user);
            if ($reason === null) {
                return ApiResponse::error('Only staff locked out by wrong passwords, or with an expired temporary password, can be restored here.', 409);
            }
            $type = $request->validated('request_type');
            if (! AccessRecovery::allows($reason, $type)) {
                throw ValidationException::withMessages([
                    'request_type' => 'The temporary password has expired, so they need a new one: choose unblock and reset.',
                ]);
            }

            $this->audit->log('STAFF_ACCESS_RESTORED', 'users', $user->user_id, [
                'user_name' => $user->user_name,
                'before' => ['status' => $user->status],
                'after' => ['status' => 'ACTIVE'],
                'reason' => $reason,
                'contact_channel' => $request->validated('contact_channel'),
                'verification_note' => $request->validated('verification_note'),
                'request_type' => $type,
            ]);
            $recovery->restore($user, $type);

            return ApiResponse::success($type === AccessRecovery::UNBLOCK_AND_RESET
                ? "{$user->user_name}'s access has been restored. A temporary password has been sent by SMS."
                : "{$user->user_name}'s access has been restored. They have been told by SMS.");
        });
    }

    /** BLOCKED -> ACTIVE. A rejected applicant has no branch and stays blocked. */
    public function unblock(User $staffUser): JsonResponse
    {
        return DB::transaction(function () use ($staffUser) {
            $status = $this->lockedStatus($staffUser);
            if ($status !== 'BLOCKED') {
                return ApiResponse::error('Only blocked staff can be unblocked.', 409);
            }
            if (PasswordLockout::blockedByLockout($staffUser->fresh())) {
                return ApiResponse::error('This staff member was locked out by wrong passwords. Use Restore access instead.', 409);
            }

            $hasBranch = DB::table('employee_branch_lnk')
                ->where('employee_id', $staffUser->employee_id)->whereNull('end_date')->exists();
            if (! $hasBranch) {
                return ApiResponse::error("This registration was rejected and can't be unblocked.", 409);
            }

            $this->setStatus($staffUser, 'ACTIVE');
            $this->audit->log('STAFF_UNBLOCKED', 'users', $staffUser->user_id, [
                'user_name' => $staffUser->user_name,
                'before' => ['status' => $status],
                'after' => ['status' => 'ACTIVE'],
            ]);

            return ApiResponse::success("{$staffUser->user_name} has been unblocked.");
        });
    }

    private function blockWithReason(User $staffUser, string $reason, string $requiredStatus, string $action, string $conflict, string $message): JsonResponse
    {
        return DB::transaction(function () use ($staffUser, $reason, $requiredStatus, $action, $conflict, $message) {
            $status = $this->lockedStatus($staffUser);
            if ($status !== $requiredStatus) {
                return ApiResponse::error($conflict, 409);
            }

            $this->setStatus($staffUser, 'BLOCKED');
            $staffUser->endSessions();

            $this->audit->log($action, 'users', $staffUser->user_id, [
                'user_name' => $staffUser->user_name,
                'before' => ['status' => $status],
                'after' => ['status' => 'BLOCKED'],
                'reason' => $reason,
            ]);

            return ApiResponse::success($message);
        });
    }

    /** Re-reads the status under a row lock, so two admins can't act on the same user at once. */
    private function lockedStatus(User $user): string
    {
        return DB::table('users')->where('user_id', $user->user_id)->lockForUpdate()->value('status');
    }

    private function setStatus(User $user, string $status): void
    {
        DB::table('users')->where('user_id', $user->user_id)->update(['status' => $status, 'updated_at' => now()]);
    }
}

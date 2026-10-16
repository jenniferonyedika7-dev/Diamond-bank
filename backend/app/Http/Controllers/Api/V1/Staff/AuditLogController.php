<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\StaffBranch;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view of audit_log. Admins see every row. Staff see their branch's
 * rows: actions by staff who worked at the branch on that day
 * (employee_branch_lnk dates), and by customers whose home branch it is.
 * Rows by admins, and rows without a user, are the admin's only.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action_type' => ['nullable', 'string', 'max:50'],
            'table' => ['nullable', 'string', 'max:64'],
            'user' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $visible = DB::table('audit_log')
            ->leftJoin('users', 'users.user_id', '=', 'audit_log.user_id')
            ->leftJoin('role', 'role.role_id', '=', 'users.role_id')
            ->when(! $request->user()->hasRole('admin'), fn ($q) => $this->scopeToBranch($q, StaffBranch::id()));

        $entries = (clone $visible)
            ->when($filters['action_type'] ?? null, fn ($q, $v) => $q->where('audit_log.action_type', $v))
            ->when($filters['table'] ?? null, fn ($q, $v) => $q->where('audit_log.table_affected', $v))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('users.user_name', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('audit_log.action_timestamp', '>=', $v.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('audit_log.action_timestamp', '<=', $v.' 23:59:59'))
            ->orderByDesc('audit_log.audit_log_id')
            ->select(['audit_log.audit_log_id', 'audit_log.action_type', 'audit_log.table_affected', 'audit_log.record_id',
                'audit_log.details', 'audit_log.ip_address', 'audit_log.action_timestamp', 'users.user_id', 'users.user_name', 'role.role_name'])
            ->paginate($filters['per_page'] ?? 25)
            ->through(function ($row) {
                $row->details = $row->details === null ? null : json_decode($row->details, true);

                return $row;
            });

        return ApiResponse::paginated('Audit log.', $entries, [
            'filters' => [
                'action_types' => (clone $visible)->distinct()->orderBy('audit_log.action_type')->pluck('audit_log.action_type'),
                'tables' => (clone $visible)->distinct()->orderBy('audit_log.table_affected')->pluck('audit_log.table_affected'),
            ],
        ]);
    }

    /** Rows by staff assigned to $branchId on the day of the action, or by customers whose home branch it is. */
    private function scopeToBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($staff) => $staff
                ->where('role.role_name', 'staff')
                ->whereExists(fn ($link) => $link->selectRaw('1')
                    ->from('employee_branch_lnk')
                    ->whereColumn('employee_branch_lnk.employee_id', 'users.employee_id')
                    ->where('employee_branch_lnk.branch_id', $branchId)
                    ->whereRaw('employee_branch_lnk.start_date <= DATE(audit_log.action_timestamp)')
                    ->where(fn ($end) => $end
                        ->whereNull('employee_branch_lnk.end_date')
                        ->orWhereRaw('employee_branch_lnk.end_date >= DATE(audit_log.action_timestamp)'))))
            ->orWhere(fn ($customer) => $customer
                ->where('role.role_name', 'customer')
                ->whereExists(fn ($home) => $home->selectRaw('1')
                    ->from('customer')
                    ->whereColumn('customer.customer_id', 'users.customer_id')
                    ->where('customer.branch_id', $branchId))));
    }
}

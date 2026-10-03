<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only view of audit_log for staff and admins. */
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

        $entries = DB::table('audit_log')
            ->leftJoin('users', 'users.user_id', '=', 'audit_log.user_id')
            ->leftJoin('role', 'role.role_id', '=', 'users.role_id')
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
                'action_types' => DB::table('audit_log')->distinct()->orderBy('action_type')->pluck('action_type'),
                'tables' => DB::table('audit_log')->distinct()->orderBy('table_affected')->pluck('table_affected'),
            ],
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff work at their current branch: the employee_branch_lnk row with
 * end_date NULL. Without one they get 403. The branch is stored on the
 * request as the 'staff_branch' attribute (branch_id, branch_name, branch_code).
 *
 * Usage: ->middleware('staff.branch'), or 'staff.branch:admin-optional' to let
 * an admin without a branch through (read-only screens such as the audit log).
 */
class EnsureStaffHasBranch
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        $branch = $user?->employee_id === null ? null : DB::table('employee_branch_lnk')
            ->join('branch', 'branch.branch_id', '=', 'employee_branch_lnk.branch_id')
            ->where('employee_branch_lnk.employee_id', $user->employee_id)
            ->whereNull('employee_branch_lnk.end_date')
            ->orderByDesc('employee_branch_lnk.start_date')
            ->orderByDesc('employee_branch_lnk.employee_branch_lnk_id')
            ->first(['branch.branch_id', 'branch.branch_name', 'branch.branch_code']);

        if ($branch === null && ! ($mode === 'admin-optional' && $user?->hasRole('admin'))) {
            return ApiResponse::error('You are not assigned to a branch. Contact the administrator.', 403);
        }

        $request->attributes->set('staff_branch', $branch);

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    /** The staff member's current branch and department. */
    public function branch(Request $request): JsonResponse
    {
        $department = DB::table('employee_department_lnk')
            ->join('department', 'department.department_id', '=', 'employee_department_lnk.department_id')
            ->where('employee_department_lnk.employee_id', $request->user()->employee_id)
            ->whereNull('employee_department_lnk.end_date')
            ->orderByDesc('employee_department_lnk.start_date')
            ->first(['department.department_id', 'department.department_name']);

        return ApiResponse::success('Your branch.', [
            'branch' => $request->attributes->get('staff_branch'),
            'department' => $department,
        ]);
    }
}

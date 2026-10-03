<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DepartmentController extends AdminController
{
    public function index(): JsonResponse
    {
        $departments = Department::query()
            ->select('department.*')
            ->selectSub(
                DB::table('employee_department_lnk')->selectRaw('count(*)')->whereColumn('employee_department_lnk.department_id', 'department.department_id')->whereNull('end_date'),
                'employees_count',
            )
            ->orderBy('department_name')
            ->get();

        return ApiResponse::success('Departments.', $departments);
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = DB::transaction(function () use ($request) {
            $department = Department::create($request->validated());
            $this->audit->log('DEPARTMENT_CREATED', 'department', $department->department_id, ['before' => null, 'after' => $this->snapshot($department)]);

            return $department;
        });

        return ApiResponse::success('Department created.', $department, 201);
    }

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        DB::transaction(function () use ($request, $department) {
            $before = $this->snapshot($department);
            $department->update($request->validated());
            $this->audit->log('DEPARTMENT_UPDATED', 'department', $department->department_id, ['before' => $before, 'after' => $this->snapshot($department)]);
        });

        return ApiResponse::success('Department updated.', $department);
    }

    public function destroy(Department $department): JsonResponse
    {
        return $this->deleteUnlessUsed($department, 'department', [
            'employee_department_lnk' => ['department_id', 'employee assignment', 'employee assignments'],
        ], 'DEPARTMENT_DELETED', 'Department deleted.');
    }
}

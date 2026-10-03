<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Concerns\SnapshotsModels;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Shared by the /api/v1/staff controllers. Routes run behind the staff.branch middleware. */
abstract class StaffAreaController extends Controller
{
    use SnapshotsModels;

    public function __construct(protected AuditLogger $audit) {}

    /** The acting staff member's current branch (branch_id, branch_name, branch_code), set by EnsureStaffHasBranch. */
    protected function branch(Request $request): object
    {
        return $request->attributes->get('staff_branch');
    }
}

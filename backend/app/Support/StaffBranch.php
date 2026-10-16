<?php

namespace App\Support;

/** The acting staff member's current branch, as set on the request by EnsureStaffHasBranch. */
final class StaffBranch
{
    /** The branch id, or null when the request has none. */
    public static function id(): ?int
    {
        $branch = request()->attributes->get('staff_branch');

        return $branch === null ? null : (int) $branch->branch_id;
    }

    public static function is(int|string|null $branchId): bool
    {
        return $branchId !== null && self::id() === (int) $branchId;
    }
}

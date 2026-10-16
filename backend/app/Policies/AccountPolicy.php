<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\StaffBranch;
use Illuminate\Auth\Access\Response;

/**
 * Staff freeze and unfreeze an account only at the branch that holds it
 * (account.branch_id). Viewing it and cash deposits and withdrawals are open
 * at every branch. Used as ->can('manage', 'account') behind staff.branch.
 */
class AccountPolicy
{
    public function manage(User $user, Account $account): Response
    {
        return StaffBranch::is($account->branch_id)
            ? Response::allow()
            : Response::deny('This account is managed at another branch.');
    }
}

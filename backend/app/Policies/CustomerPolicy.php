<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\StaffBranch;
use Illuminate\Auth\Access\Response;

/**
 * Staff change a customer (KYC, details, login block) only at the customer's
 * own branch (customer.branch_id). Viewing is open at every branch. Used as
 * ->can('manage', 'customer') on staff routes behind the staff.branch middleware.
 */
class CustomerPolicy
{
    public function manage(User $user, Customer $customer): Response
    {
        return StaffBranch::is($customer->branch_id)
            ? Response::allow()
            : Response::deny('This customer is managed at another branch.');
    }
}

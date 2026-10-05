<?php

namespace App\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Customer lists and account summaries shared by the staff and admin areas. */
class CustomerDirectory
{
    /**
     * Newest registrations first.
     *
     * @param  array{kyc_status?: ?string, branch_id?: ?int, search?: ?string, per_page?: ?int}  $filters
     */
    public static function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim($filters['search'] ?? '');

        return DB::table('customer')
            ->join('branch', 'branch.branch_id', '=', 'customer.branch_id')
            ->leftJoin('users', 'users.customer_id', '=', 'customer.customer_id')
            ->when($filters['kyc_status'] ?? null, fn ($q, $status) => $q->where('customer.kyc_status', $status))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('customer.branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where(DB::raw("CONCAT(customer.first_name, ' ', customer.last_name)"), 'like', "%{$search}%")
                ->orWhere('customer.national_id', 'like', "%{$search}%")
                ->orWhere('customer.phone', 'like', "%{$search}%")
                ->orWhere('customer.email', 'like', "%{$search}%")
                ->orWhere('users.user_name', 'like', "%{$search}%")))
            ->orderByDesc('customer.registration_date')
            ->orderByDesc('customer.customer_id')
            ->select([
                'customer.customer_id', 'customer.first_name', 'customer.last_name', 'customer.national_id',
                'customer.phone', 'customer.email', 'customer.kyc_status', 'customer.registration_date',
                'branch.branch_id', 'branch.branch_name', 'users.user_name', 'users.status as user_status',
            ])
            ->selectSub(DB::table('account')->selectRaw('count(*)')->whereColumn('account.customer_id', 'customer.customer_id'), 'accounts_count')
            ->paginate($filters['per_page'] ?? 15)
            ->through(fn ($row) => [
                'customer_id' => $row->customer_id,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'national_id' => $row->national_id,
                'phone' => $row->phone,
                'email' => $row->email,
                'kyc_status' => $row->kyc_status,
                'registration_date' => $row->registration_date,
                'branch' => ['branch_id' => $row->branch_id, 'branch_name' => $row->branch_name],
                'user_name' => $row->user_name,
                'user_status' => $row->user_status,
                'accounts_count' => (int) $row->accounts_count,
            ]);
    }

    /** The customer's accounts, oldest first, with type and branch. */
    public static function accounts(int $customerId): Collection
    {
        return DB::table('account')
            ->join('account_type', 'account_type.account_type_id', '=', 'account.account_type_id')
            ->join('branch', 'branch.branch_id', '=', 'account.branch_id')
            ->where('account.customer_id', $customerId)
            ->orderBy('account.opened_date')
            ->orderBy('account.account_id')
            ->get(['account.account_id', 'account.account_number', 'account_type.type_name', 'account.balance',
                'account.currency_code', 'account.status', 'account.opened_date', 'branch.branch_id', 'branch.branch_name']);
    }
}

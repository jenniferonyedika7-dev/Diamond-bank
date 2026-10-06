<?php

use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** An ACTIVE staff user with a current branch (employee_branch_lnk, end_date NULL). */
function staffAtBranch(?Branch $branch = null): User
{
    $staff = User::factory()->staff()->create();
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $staff->employee_id,
        'branch_id' => ($branch ?? Branch::factory()->create())->branch_id,
        'start_date' => now()->toDateString(),
        'end_date' => null,
    ]);

    return $staff;
}

function currentBranchId(User $staff): int
{
    return DB::table('employee_branch_lnk')->where('employee_id', $staff->employee_id)->whereNull('end_date')->value('branch_id');
}

/** A customer with an ACTIVE login. */
function customerWithLogin(string $kycStatus = 'PENDING'): Customer
{
    $customer = Customer::factory()->create(['kyc_status' => $kycStatus]);
    User::factory()->create(['customer_id' => $customer->customer_id]);

    return $customer;
}

function savingsType(): AccountType
{
    return AccountType::firstOrCreate(['type_name' => 'Savings'], ['interest_rate' => 3.5, 'minimum_balance' => 100]);
}

/** Opens an account through the API (sp_open_account) and returns its number. */
function openAccountFor(Customer $customer, string $deposit = '500.00'): string
{
    return test()->postJson('/api/v1/staff/accounts', [
        'customer_id' => $customer->customer_id,
        'account_type_id' => savingsType()->account_type_id,
        'initial_deposit' => $deposit,
    ])->assertCreated()->json('data.account_number');
}

/** Every registered /api/v1/staff route as [method, uri], with parameters filled in. */
function staffRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/staff'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, '/'.preg_replace(['/\{checkType\}/', '/\{[^}]+\}/'], ['BANK_STATEMENT', '999999'], $route->uri())]))
        ->values()
        ->all();
}

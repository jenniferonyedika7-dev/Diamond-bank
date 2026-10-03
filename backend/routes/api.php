<?php

use App\Http\Controllers\Api\V1\Admin\AccountTypeController;
use App\Http\Controllers\Api\V1\Admin\BankController;
use App\Http\Controllers\Api\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\V1\Admin\CardTypeController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\TransactionTypeController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\Staff\AccountController;
use App\Http\Controllers\Api\V1\Staff\AuditLogController;
use App\Http\Controllers\Api\V1\Staff\CustomerController;
use App\Http\Controllers\Api\V1\Staff\MeController;
use App\Http\Controllers\Api\V1\Staff\OverviewController as StaffOverviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('auth/register/customer', [RegisterController::class, 'customer'])->name('auth.register.customer');
    Route::post('auth/register/staff', [RegisterController::class, 'staff'])->name('auth.register.staff');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login'); // throttled in LoginRequest

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Reachable while must_change_password is set.
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        Route::middleware('password.changed')->group(function () {
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

            Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
                Route::get('overview', OverviewController::class)->name('overview');

                Route::get('bank', [BankController::class, 'show'])->name('bank.show');
                Route::put('bank', [BankController::class, 'upsert'])->name('bank.upsert');

                Route::apiResource('branches', AdminBranchController::class)->except('show')->parameters(['branches' => 'branch']);
                Route::apiResource('account-types', AccountTypeController::class)->except('show');
                Route::apiResource('card-types', CardTypeController::class)->except('show');
                Route::apiResource('departments', DepartmentController::class)->except('show');
                Route::get('transaction-types', [TransactionTypeController::class, 'index'])->name('transaction-types.index');

                // {staffUser}: staff only; admin accounts get 403 (see StaffController::resolveStaffUser).
                Route::bind('staffUser', fn (string $value) => StaffController::resolveStaffUser($value));
                Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
                Route::post('staff/{staffUser}/approve', [StaffController::class, 'approve'])->name('staff.approve');
                Route::post('staff/{staffUser}/reject', [StaffController::class, 'reject'])->name('staff.reject');
                Route::post('staff/{staffUser}/block', [StaffController::class, 'block'])->name('staff.block');
                Route::post('staff/{staffUser}/unblock', [StaffController::class, 'unblock'])->name('staff.unblock');
            });

            Route::prefix('staff')->name('staff.')->middleware('role:staff,admin')->group(function () {
                // Read-only; an admin without a branch may use it too (the admin area links here).
                Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('staff.branch:admin-optional')->name('audit-log');

                // Everything else happens at the staff member's current branch.
                Route::middleware('staff.branch')->group(function () {
                    Route::get('me/branch', [MeController::class, 'branch'])->name('me.branch');
                    Route::get('overview', StaffOverviewController::class)->name('overview');

                    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
                    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
                    Route::post('customers/{customer}/verify', [CustomerController::class, 'verify'])->name('customers.verify');
                    Route::post('customers/{customer}/reject-kyc', [CustomerController::class, 'rejectKyc'])->name('customers.reject-kyc');
                    Route::post('customers/{customer}/block', [CustomerController::class, 'block'])->name('customers.block');
                    Route::post('customers/{customer}/unblock', [CustomerController::class, 'unblock'])->name('customers.unblock');

                    Route::get('account-types', [AccountController::class, 'types'])->name('account-types.index');
                    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
                    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
                    Route::get('accounts/{account}/transactions', [AccountController::class, 'transactions'])->name('accounts.transactions');
                    Route::post('accounts/{account}/deposit', [AccountController::class, 'deposit'])->name('accounts.deposit');
                    Route::post('accounts/{account}/withdraw', [AccountController::class, 'withdraw'])->name('accounts.withdraw');
                    Route::post('accounts/{account}/freeze', [AccountController::class, 'freeze'])->name('accounts.freeze');
                    Route::post('accounts/{account}/unfreeze', [AccountController::class, 'unfreeze'])->name('accounts.unfreeze');
                });
            });
        });
    });
});

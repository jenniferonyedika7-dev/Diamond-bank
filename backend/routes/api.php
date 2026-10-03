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
        });
    });
});

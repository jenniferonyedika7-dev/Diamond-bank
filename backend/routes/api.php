<?php

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

            // Future routes go here, e.g. ->middleware('role:admin,staff').
        });
    });
});

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $staffByStatus = DB::table('users')
            ->join('role', 'role.role_id', '=', 'users.role_id')
            ->where('role.role_name', 'staff')
            ->groupBy('users.status')
            ->pluck(DB::raw('count(*)'), 'users.status');

        $customersByKyc = DB::table('customer')->groupBy('kyc_status')->pluck(DB::raw('count(*)'), 'kyc_status');

        return ApiResponse::success('Overview.', [
            'bank_configured' => DB::table('bank')->exists(),
            'pending_staff' => (int) ($staffByStatus['PENDING'] ?? 0),
            'active_staff' => (int) ($staffByStatus['ACTIVE'] ?? 0),
            'blocked_staff' => (int) ($staffByStatus['BLOCKED'] ?? 0),
            'branches' => DB::table('branch')->count(),
            'customers' => [
                'total' => (int) $customersByKyc->sum(),
                'PENDING' => (int) ($customersByKyc['PENDING'] ?? 0),
                'VERIFIED' => (int) ($customersByKyc['VERIFIED'] ?? 0),
                'REJECTED' => (int) ($customersByKyc['REJECTED'] ?? 0),
            ],
            'accounts' => DB::table('account')->count(),
        ]);
    }
}

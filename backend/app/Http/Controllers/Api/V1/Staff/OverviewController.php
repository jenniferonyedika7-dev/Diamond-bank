<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byKyc = DB::table('customer')->groupBy('kyc_status')->pluck(DB::raw('count(*)'), 'kyc_status');

        return ApiResponse::success('Overview.', [
            'customers' => [
                'total' => (int) $byKyc->sum(),
                'PENDING' => (int) ($byKyc['PENDING'] ?? 0),
                'VERIFIED' => (int) ($byKyc['VERIFIED'] ?? 0),
                'REJECTED' => (int) ($byKyc['REJECTED'] ?? 0),
            ],
        ]);
    }
}

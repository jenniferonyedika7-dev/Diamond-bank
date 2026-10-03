<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /** Public: id + name only, for the registration forms. */
    public function index(): JsonResponse
    {
        return ApiResponse::success('Branches.', Branch::query()->orderBy('branch_name')->get(['branch_id', 'branch_name']));
    }
}

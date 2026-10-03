<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TransactionType;
use Illuminate\Http\JsonResponse;

/** Read-only: the stored procedures look these rows up by type_name. */
class TransactionTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success('Transaction types.', TransactionType::query()->orderBy('type_name')->get());
    }
}

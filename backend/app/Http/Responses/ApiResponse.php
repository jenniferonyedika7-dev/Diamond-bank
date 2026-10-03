<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The { success, message, data } envelope used by every API response
 * except 422 validation errors, which keep Laravel's standard format.
 */
class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    /** data: { items, pagination: { current_page, last_page, per_page, total } } */
    public static function paginated(string $message, LengthAwarePaginator $paginator): JsonResponse
    {
        return self::success($message, [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** @param  array<string, string>  $headers */
    public static function error(string $message, int $status, mixed $data = null, array $headers = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => $data], $status, $headers);
    }
}

<?php

namespace App\Http\Responses;

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

    /** @param  array<string, string>  $headers */
    public static function error(string $message, int $status, mixed $data = null, array $headers = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => $data], $status, $headers);
    }
}

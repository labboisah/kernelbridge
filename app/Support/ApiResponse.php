<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    public static function success(Request $request, mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => ['request_id' => $request->attributes->get('request_id'), ...$meta]], $status);
    }

    public static function error(Request $request, string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details], 'meta' => ['request_id' => $request->attributes->get('request_id')]], $status);
    }
}

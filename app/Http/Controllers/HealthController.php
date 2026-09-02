<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json([
            'status' => $result['healthy'] ? 'ok' : 'degraded',
            'checks' => collect($result['checks'])->map(fn (bool $passed): string => $passed ? 'ok' : 'failed'),
        ], $result['healthy'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}

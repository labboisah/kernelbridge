<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => ['service' => 'kernelbridge-api', 'version' => 'v1', 'status' => 'ok']]);
    }
}

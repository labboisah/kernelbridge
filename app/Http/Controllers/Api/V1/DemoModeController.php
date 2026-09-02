<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Demo\Services\DemoModeService;
use App\Http\Controllers\Controller;
use App\Models\DemoSession;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DemoModeController extends Controller
{
    public function config(Request $request, DemoModeService $service): JsonResponse
    {
        return ApiResponse::success($request, $service->config($request->attributes->get('api_client')));
    }

    public function start(Request $request, DemoModeService $service): JsonResponse
    {
        $data = $request->validate(['anonymous_identifier' => ['required', 'string', 'max:255'], 'access_token' => ['nullable', 'string', 'max:255'], 'organization_identifier' => ['nullable', 'string', 'max:255'], 'entry_url' => ['nullable', 'url', 'max:2000'], 'referrer' => ['nullable', 'url', 'max:2000'], 'metadata' => ['nullable', 'array']]);
        $session = $service->startSession($request->attributes->get('api_client'), $data);

        return ApiResponse::success($request, ['session_uuid' => $session->uuid, 'started_at' => $session->started_at->toISOString()], 201);
    }

    public function event(Request $request, DemoModeService $service): JsonResponse
    {
        $data = $request->validate(['session_uuid' => ['required', 'uuid'], 'event_id' => ['required', 'string', 'max:150'], 'event_type' => ['required', 'in:session_start,page_view,feature_used,workflow_completed,cta_clicked,request_full_version,session_end'], 'page' => ['nullable', 'string', 'max:255'], 'feature' => ['nullable', 'string', 'max:255'], 'cta' => ['nullable', 'string', 'max:255'], 'occurred_at' => ['required', 'date'], 'metadata' => ['nullable', 'array']]);
        $session = DemoSession::query()->where('uuid', $data['session_uuid'])->firstOrFail();
        unset($data['session_uuid']);
        $data['external_event_id'] = $data['event_id'];
        unset($data['event_id']);
        $uuid = $service->recordEvent($request->attributes->get('api_client'), $session, $data);

        return ApiResponse::success($request, ['event_uuid' => $uuid, 'accepted' => true], 202);
    }

    public function end(Request $request, DemoSession $session, DemoModeService $service): JsonResponse
    {
        $service->endSession($request->attributes->get('api_client'), $session);

        return ApiResponse::success($request, ['session_uuid' => $session->uuid, 'ended' => true]);
    }

    public function acknowledgeReset(Request $request, DemoModeService $service): JsonResponse
    {
        $data = $request->validate(['instruction_version' => ['required', 'integer', 'min:1']]);
        $service->acknowledgeReset($request->attributes->get('api_client'), $data['instruction_version']);

        return ApiResponse::success($request, ['acknowledged' => true, 'instruction_version' => $data['instruction_version']]);
    }
}

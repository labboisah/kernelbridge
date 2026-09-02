<?php

use App\Http\Controllers\Api\V1\DemoModeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProductIntegrationController;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware('auth:sanctum')->get('/user', fn (Request $request): UserResource => new UserResource($request->user()))->name('user');

Route::middleware(['api.request_id', 'api.security', 'api.log', 'api.client', 'api.rate_limit'])->group(function (): void {
    Route::get('/products/{code}', [ProductIntegrationController::class, 'product'])->name('products.show');
    Route::post('/licenses/activate', [ProductIntegrationController::class, 'activate'])->name('licenses.activate');
    Route::post('/licenses/verify', [ProductIntegrationController::class, 'verify'])->name('licenses.verify');
    Route::post('/licenses/deactivate', [ProductIntegrationController::class, 'deactivate'])->name('licenses.deactivate');
    Route::get('/subscriptions/status', [ProductIntegrationController::class, 'subscription'])->name('subscriptions.status');
    Route::get('/entitlements', [ProductIntegrationController::class, 'entitlements'])->name('entitlements.index');
    Route::get('/demo/config', [DemoModeController::class, 'config'])->name('demo.config');
    Route::post('/demo/sessions', [DemoModeController::class, 'start'])->name('demo.sessions.start');
    Route::post('/demo/sessions/{session:uuid}/end', [DemoModeController::class, 'end'])->name('demo.sessions.end');
    Route::post('/demo/events', [DemoModeController::class, 'event'])->name('demo.events.store');
    Route::post('/demo/resets/acknowledge', [DemoModeController::class, 'acknowledgeReset'])->name('demo.resets.acknowledge');
});

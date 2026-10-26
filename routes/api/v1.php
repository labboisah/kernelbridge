<?php

use App\Http\Controllers\Api\V1\DemoModeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstallationVerificationController;
use App\Http\Controllers\Api\V1\McpController;
use App\Http\Controllers\Api\V1\ProductIntegrationController;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware('auth:sanctum')->get('/user', fn (Request $request): UserResource => new UserResource($request->user()))->name('user');

// Authenticated by the installation grant bearer, not a product API client.
Route::middleware(['api.request_id', 'api.security', 'throttle:60,1'])->post('/installations/verify', [InstallationVerificationController::class, 'verify'])->name('installations.verify');

Route::middleware(['api.request_id', 'api.security', 'api.log', 'api.client', 'api.rate_limit'])->group(function (): void {
    Route::get('/products/{code}', [ProductIntegrationController::class, 'product'])->name('products.show');
    Route::post('/licenses/redeem', [ProductIntegrationController::class, 'redeem'])->name('licenses.redeem');
    Route::post('/licenses/installation-grants', [ProductIntegrationController::class, 'issueInstallationGrant'])->name('licenses.installation-grants.issue');
    Route::post('/licenses/installation-grants/{grant}/rotate', [ProductIntegrationController::class, 'rotateInstallationGrant'])->name('licenses.installation-grants.rotate');
    Route::post('/licenses/installation-grants/{grant}/revoke', [ProductIntegrationController::class, 'revokeInstallationGrant'])->name('licenses.installation-grants.revoke');
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
    Route::prefix('mcp')->name('mcp.')->group(function (): void {
        Route::middleware('api.scope:mcp:read')->group(function (): void {
            Route::get('/customers/search', [McpController::class, 'customers'])->name('customers.search');
            Route::get('/customers/{customer}/services', [McpController::class, 'customerServices'])->name('customers.services');
            Route::get('/services/{subscription}/setup', [McpController::class, 'serviceSetup'])->name('services.setup');
            Route::get('/licenses/explain-activation-issue', [McpController::class, 'explainActivationIssue'])->name('licenses.explain-activation-issue');
            Route::get('/licenses/{license}/activations', [McpController::class, 'activations'])->name('licenses.activations');
        });
        Route::middleware('api.scope:mcp:write')->group(function (): void {
            Route::post('/licenses/issue', [McpController::class, 'issueLicense'])->name('licenses.issue');
            Route::post('/license-activations/{activation}/deactivate', [McpController::class, 'deactivateActivation'])->name('license-activations.deactivate');
        });
    });
});


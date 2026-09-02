<?php

use App\Http\Middleware\AddApiSecurityHeaders;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitApiClientRequests;
use App\Http\Middleware\LogApiRequest;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: ['payments/flutterwave/webhook']);
        $middleware->statefulApi();
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'api.request_id' => AssignApiRequestId::class,
            'api.security' => AddApiSecurityHeaders::class,
            'api.client' => AuthenticateApiClient::class,
            'api.rate_limit' => LimitApiClientRequests::class,
            'api.log' => LogApiRequest::class,
        ]);
        $middleware->web(append: [
            AddSecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }
            if ($exception instanceof ValidationException) {
                return ApiResponse::error($request, 'validation_failed', 'The request data is invalid.', 422, $exception->errors());
            }
            if ($exception instanceof ModelNotFoundException) {
                return ApiResponse::error($request, 'not_found', 'The requested resource was not found.', 404);
            }
            if ($exception instanceof HttpExceptionInterface) {
                return ApiResponse::error($request, $exception->getStatusCode() === 404 ? 'not_found' : 'http_error', $exception->getStatusCode() === 404 ? 'The requested resource was not found.' : ($exception->getMessage() ?: 'The request could not be completed.'), $exception->getStatusCode());
            }
            report($exception);

            return ApiResponse::error($request, 'server_error', 'An unexpected error occurred.', 500);
        });
    })->create();

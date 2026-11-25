<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

final class DatabasePermissionMiddleware
{
    public function handle(Request $request, Closure $next, $permission, ?string $guard = null): Response
    {
        $user = Auth::guard($guard)->user();
        if ($user instanceof User) {
            foreach (['roles', 'permissions', 'temporaryPermissions'] as $relation) {
                $user->unsetRelation($relation);
            }
        }

        return app(PermissionMiddleware::class)->handle($request, $next, $permission, $guard);
    }
}

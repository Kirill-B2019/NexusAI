<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, array_filter(array_map(
                static fn (string $role): ?UserRole => UserRole::tryFrom($role),
                $roles,
            )), true),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}

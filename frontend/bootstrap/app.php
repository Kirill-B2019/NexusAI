<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (Request $request): string => route('home'));
        $middleware->alias([
            'idle.timeout' => EnforceIdleTimeout::class,
            'password.change' => RequirePasswordChange::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash([
            'api_key',
            'project_api_key',
            'nexus_api_key',
            'NEXUS_AI_ADMIN_KEY',
            'secret',
            'key',
        ]);
    })->create();

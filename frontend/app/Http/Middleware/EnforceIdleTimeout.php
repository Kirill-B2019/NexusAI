<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $timeoutSeconds = max(1, (int) config('session.idle_timeout', 15)) * 60;
        $now = now()->timestamp;
        $lastActivity = $request->session()->get('nexus.last_user_activity_at');

        if ($lastActivity !== null && $now - (int) $lastActivity >= $timeoutSeconds) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home');
        }

        if ($lastActivity === null) {
            $lastActivity = $now;
            $request->session()->put('nexus.last_user_activity_at', $lastActivity);
        }

        $remainingSeconds = max(0, $timeoutSeconds - ($now - (int) $lastActivity));
        $request->attributes->set('idle_timeout_remaining_seconds', $remainingSeconds);
        $request->attributes->set('idle_timeout_last_activity_at', (int) $lastActivity);
        $request->attributes->set('idle_timeout_server_now', $now);

        if ($request->routeIs('session.activity.update')) {
            $lastActivity = $now;
            $request->session()->put('nexus.last_user_activity_at', $lastActivity);
            $request->attributes->set('idle_timeout_remaining_seconds', $timeoutSeconds);
            $request->attributes->set('idle_timeout_last_activity_at', $lastActivity);
        }

        return $next($request);
    }
}

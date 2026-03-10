<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNoLinuxUser
{
    /**
     * Handle an incoming request.
     *
     * Redirects to the bind user page if the authenticated user hasn't bound a Linux user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip redirect if we're already on wizard routes
        if ($request->is('wizard') || $request->is('wizard/*')) {
            return $next($request);
        }

        // Skip redirect if user is not authenticated
        if (!$request->user()) {
            return $next($request);
        }

        // Skip redirect if user already has a username bound
        if ($request->user()->username) {
            return $next($request);
        }

        // Redirect to bind user page if no username bound
        return redirect('/wizard/bind-user');
    }
}

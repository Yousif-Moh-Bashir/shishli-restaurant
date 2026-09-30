<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalCartAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        if ($request->bearerToken() && $user === null) {
            throw new AuthenticationException;
        }
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}

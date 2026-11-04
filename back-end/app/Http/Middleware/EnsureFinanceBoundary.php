<?php

namespace App\Http\Middleware;

use App\Services\Finance\FinanceSecurity;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureFinanceBoundary
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }
        $financial = FinanceSecurity::isFinanceRequest($request);
        if ($financial) {
            abort_unless(FinanceSecurity::allowedHost($request), 404);
        } elseif (FinanceSecurity::host($request) === config('finance.host')) {
            abort(404);
        }
        if ($bearer = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearer);
            if ($token?->name === 'finance') {
                abort_unless($financial && FinanceSecurity::allowedHost($request), 403);
            }
        }

        return $next($request);
    }
}

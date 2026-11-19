<?php

namespace App\Http\Middleware;

use App\Models\PrintAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $connectivity = $request->isMethod('POST') && $request->routeIs('api.v1.print-agent.connectivity');
        abort_unless($connectivity || config('printing.enabled'), 503);
        abort_unless($request->isSecure(), 403);
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\Aspa_[a-f0-9]{64}\z/', $token), 401);
        $agent = PrintAgent::where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
        abort_unless($agent !== null && $agent->expires_at->isFuture(), 401);
        $request->attributes->set('print_agent', $agent);
        if (! $connectivity) {
            $agent->update(['last_seen_at' => now()]);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        app(AdminAccessService::class)->assertAllowed($request->user());

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Finance\FinanceSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FinanceResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return FinanceSecurity::headers($next($request), $request);
    }
}

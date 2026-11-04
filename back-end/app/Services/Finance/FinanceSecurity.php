<?php

namespace App\Services\Finance;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FinanceSecurity
{
    public static function isFinanceRequest(Request $request): bool
    {
        return $request->is('api/v1/finance', 'api/v1/finance/*');
    }

    public static function allowedHost(Request $request): bool
    {
        if (self::host($request) === config('finance.host')) {
            return true;
        }

        return app()->environment('local', 'testing') && in_array(self::host($request), config('finance.local_hosts'), true);
    }

    public static function host(Request $request): string
    {
        $host = $request->headers->get('host', '');
        if (! preg_match('/\A(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]+)?\z/i', $host)) {
            return '';
        }

        return strtolower(trim((string) parse_url('http://'.$host, PHP_URL_HOST), '[]'));
    }

    public static function headers(Response $response, Request $request): Response
    {
        if (self::isFinanceRequest($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}

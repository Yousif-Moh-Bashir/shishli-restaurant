<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    public function test_browser_can_read_retry_after_and_request_id_on_api_rate_limit_response(): void
    {
        Route::get('/api/release-test-rate-limit', fn (): Response => response('', 429, ['Retry-After' => '90']))->middleware('api');

        $response = $this->withHeader('Origin', 'https://restaurant.example')->get('/api/release-test-rate-limit');

        $response->assertTooManyRequests()->assertHeader('Retry-After', '90');
        $this->assertContains('retry-after', array_map('strtolower', array_map('trim', explode(',', $response->headers->get('Access-Control-Expose-Headers', '')))));
        $this->assertContains('x-request-id', array_map('strtolower', array_map('trim', explode(',', $response->headers->get('Access-Control-Expose-Headers', '')))));
    }

    #[TestWith(['production', '', 'https://unexpected.example', null])]
    #[TestWith(['production', 'https://restaurant.example', 'https://restaurant.example', 'https://restaurant.example'])]
    #[TestWith(['production', 'https://restaurant.example', 'https://unexpected.example', 'https://restaurant.example'])]
    #[TestWith(['production', ' https://restaurant.example, https://admin.example ', 'https://admin.example', 'https://admin.example'])]
    #[TestWith(['local', '', 'http://localhost:5173', '*'])]
    public function test_preflight_only_authorizes_configured_origins(string $environment, string $origins, string $origin, ?string $allowed): void
    {
        $values = ['APP_ENV' => $environment, 'CORS_ALLOWED_ORIGINS' => $origins];
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            $path = config_path('cors.php');
            if (is_file($path)) {
                config(['cors' => require $path]);
            }

            $response = $this->withHeaders([
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'Authorization',
            ])->options('/api/v1/branches');

            $response->assertNoContent();
            if ($allowed === null) {
                $response->assertHeaderMissing('Access-Control-Allow-Origin');
            } else {
                $response->assertHeader('Access-Control-Allow-Origin', $allowed);
            }
            $response->assertHeaderMissing('Access-Control-Allow-Credentials');
        } finally {
            foreach ($original as $key => [$process, $env, $server]) {
                putenv($process === false ? $key : $key.'='.$process);
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    #[TestWith(['10.0.0.10', true, '203.0.113.20'])]
    #[TestWith(['10.0.0.99', false, '10.0.0.99'])]
    public function test_forwarded_scheme_and_ip_are_only_trusted_from_configured_proxy(string $remote, bool $secure, string $ip): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.10']]);
        $request = Request::create('http://restaurant.example/up', server: [
            'REMOTE_ADDR' => $remote,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
        ]);

        try {
            app(TrustProxies::class)->handle($request, function (Request $forwarded) use ($secure, $ip): Response {
                $this->assertSame($secure, $forwarded->isSecure());
                $this->assertSame($ip, $forwarded->ip());

                return response('ok');
            });
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }
}

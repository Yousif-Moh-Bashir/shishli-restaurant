<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditAccessBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_authenticated_api_route_rejects_missing_and_invalid_bearer_tokens(): void
    {
        Http::preventStrayRequests();
        $tested = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '11111111-1111-4111-8111-111111111111', $route->uri());
            $method = $route->methods()[0];
            foreach ([[], ['Authorization' => 'Bearer audit-invalid-token']] as $headers) {
                $this->json($method, $uri, [], $headers)->assertUnauthorized()->assertJsonPath('success', false);
            }
            $tested++;
        }
        $this->assertGreaterThan(100, $tested);
    }

    public function test_customer_cannot_use_any_admin_or_driver_api_route(): void
    {
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->create()->assignRole('customer'));
        $tested = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/') && ! str_starts_with($route->uri(), 'api/v1/driver/')) {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '11111111-1111-4111-8111-111111111111', $route->uri());
            Cache::flush();
            $response = $this->json($route->methods()[0], $uri)->assertJsonPath('success', false);
            $this->assertContains($response->status(), [403, 404], $route->uri());
            $tested++;
        }
        $this->assertGreaterThan(100, $tested);
    }
}

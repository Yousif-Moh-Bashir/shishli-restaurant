<?php

namespace Tests\Feature\Http;

use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_missing_api_route_returns_json_404_even_when_html_is_requested(): void
    {
        $this->get('/api/missing', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => false, 'message' => 'العنصر المطلوب غير موجود']);
    }

    public function test_guest_receives_json_401_from_protected_route(): void
    {
        $this->get('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'يجب تسجيل الدخول']);
    }

    public function test_denied_permission_returns_json_403(): void
    {
        Gate::define('test-denied', fn (User $user): bool => false);
        Route::middleware(['api', 'can:test-denied'])->get('/api/test-denied', fn () => response()->noContent());

        $this->actingAs(User::factory()->make())->getJson('/api/test-denied')
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'ليس لديك صلاحية لتنفيذ هذه العملية']);
    }

    public function test_missing_bound_model_returns_json_404(): void
    {
        Route::middleware('api')->get('/api/test-users/{user}', fn (User $user): UserResource => new UserResource($user));

        $this->getJson('/api/test-users/00000000-0000-4000-8000-000000000001')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'العنصر المطلوب غير موجود']);
    }

    public function test_validation_returns_422_with_field_errors(): void
    {
        Route::middleware('api')->post('/api/test-validation', function (Request $request) {
            return $request->validate(['email' => ['required']], ['email.required' => 'البريد الإلكتروني مطلوب']);
        });

        $this->postJson('/api/test-validation', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'البيانات المدخلة غير صحيحة',
                'errors' => ['email' => ['البريد الإلكتروني مطلوب']],
            ]);
    }

    public function test_422_without_field_errors_contains_an_empty_json_object(): void
    {
        Route::middleware('api')->get('/api/test-invalid', fn () => abort(422));

        $response = $this->getJson('/api/test-invalid')->assertUnprocessable();

        $this->assertInstanceOf(stdClass::class, json_decode($response->getContent())->errors);
    }

    public function test_405_preserves_allow_header(): void
    {
        Route::middleware('api')->post('/api/test-method', fn () => response()->noContent());

        $this->getJson('/api/test-method')
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST')
            ->assertExactJson(['success' => false, 'message' => 'طريقة الطلب غير مسموح بها']);
    }

    public function test_http_authentication_error_preserves_challenge_header(): void
    {
        Route::middleware('api')->get('/api/test-challenge', fn () => abort(401, 'Private message', ['WWW-Authenticate' => 'Bearer']));

        $this->getJson('/api/test-challenge')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['success' => false, 'message' => 'يجب تسجيل الدخول']);
    }

    #[DataProvider('debugModes')]
    public function test_server_error_is_reported_without_exposing_details(bool $debug): void
    {
        config(['app.debug' => $debug]);
        Exceptions::fake();
        $exception = new RuntimeException('Sensitive database details');
        Route::middleware('api')->get('/api/test-server-error', fn () => throw $exception);

        $this->getJson('/api/test-server-error')
            ->assertInternalServerError()
            ->assertExactJson(['success' => false, 'message' => 'حدث خطأ في الخادم، يرجى المحاولة لاحقًا']);

        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function debugModes(): array
    {
        return ['debug' => [true], 'production' => [false]];
    }

    public function test_non_api_html_errors_keep_their_html_response(): void
    {
        $this->get('/missing-web-page', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function test_non_api_request_expecting_json_gets_the_same_error_format(): void
    {
        $this->getJson('/missing-json-page')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'العنصر المطلوب غير موجود']);
    }
}

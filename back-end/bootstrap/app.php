<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ((! $request->is('api', 'api/*') && ! $request->expectsJson()) || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $message = match ($status) {
                400 => 'الطلب غير صحيح',
                401 => 'يجب تسجيل الدخول',
                403 => 'ليس لديك صلاحية لتنفيذ هذه العملية',
                404 => 'العنصر المطلوب غير موجود',
                405 => 'طريقة الطلب غير مسموح بها',
                409 => 'يتعارض الطلب مع الحالة الحالية',
                419 => 'انتهت صلاحية الجلسة، يرجى المحاولة مجددًا',
                422 => 'البيانات المدخلة غير صحيحة',
                429 => 'تم تجاوز عدد المحاولات المسموح بها، يرجى المحاولة لاحقًا',
                503 => 'الخدمة غير متاحة مؤقتًا',
                default => $status >= 500 ? 'حدث خطأ في الخادم، يرجى المحاولة لاحقًا' : 'تعذر تنفيذ الطلب',
            };

            return ApiResponse::error(
                message: $message,
                errors: $exception instanceof ValidationException ? $exception->errors() : null,
                statusCode: $status,
            )->withHeaders(Arr::except($response->headers->all(), ['content-type', 'content-length']));
        });
    })->create();

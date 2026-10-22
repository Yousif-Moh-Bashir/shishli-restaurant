<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\Promotion;
use App\Services\Admin\AdminActivityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordAdminActivity
{
    public function __construct(private AdminActivityService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route?->getName() ?? '';
        $tracked = in_array($name, ['api.v1.admin.orders.cancel', 'api.v1.admin.promotions.store', 'api.v1.admin.promotions.update', 'api.v1.admin.promotions.destroy'], true);
        $subject = $route?->parameter('promotion') ?? $route?->parameter('order');
        $old = $tracked && ($subject instanceof Order || $subject instanceof Promotion) ? $this->audit->values($subject) : [];
        $response = $next($request);
        if ($tracked && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $request->user() !== null) {
            try {
                if ($subject === null && $response instanceof JsonResponse) {
                    $uuid = $response->getData(true)['data']['id'] ?? null;
                    if (is_string($uuid)) {
                        $subject = Promotion::where('uuid', $uuid)->first();
                    }
                }
                $new = $subject instanceof Order || $subject instanceof Promotion ? $this->audit->values($subject->fresh() ?? $subject) : [];
                $this->audit->record($request->user(), str_replace('api.v1.admin.', '', $name), $subject instanceof Order ? 'order' : 'promotion', $subject?->uuid, $old, $new);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Admin\AdminOrderActions;
use App\Services\Drivers\DeliveryExceptionService;
use Illuminate\Http\Request;

class AdminOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $kitchen = $actor->hasRole('kitchen') && ! $actor->hasAnyRole(['super_admin', 'manager', 'cashier']);
        $data = $kitchen ? (new KitchenOrderResource($this->resource))->toArray($request) : parent::toArray($request);
        $data['available_actions'] = app(AdminOrderActions::class)->forOrder($this->resource, $actor);
        $data['created_at'] = $this->created_at?->toIso8601String();
        if (! $kitchen) {
            $data['cash_collections'] = $this->whenLoaded('cashCollections', fn () => $this->cashCollections->map(fn ($collection): array => [
                'amount' => $collection->amount, 'currency' => $collection->currency,
                'collector' => $collection->driver?->name, 'collected_at' => $collection->collected_at?->toISOString(),
                'settled_at' => $collection->settled_at?->toISOString(),
            ])->all());
            $data['delivery_exception'] = [
                'revision' => $this->delivery_revision ?? 0,
                'requested_at' => $this->delivery_exception_requested_at?->toISOString(),
                'reason' => $this->delivery_exception_reason,
                'task_closed_at' => $this->delivery_task_closed_at?->toISOString(),
                'block' => app(DeliveryExceptionService::class)->block($this->resource),
                'actions' => app(DeliveryExceptionService::class)->actions($this->resource, $actor),
                'financial_review_required' => in_array($this->delivery_status?->value, ['returned', 'released'], true)
                    && in_array($this->payment_status->value, ['paid', 'partially_refunded'], true),
            ];
            $data['delivery'] = ['status' => $this->delivery_status?->value,
                'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->uuid, 'name' => $this->driver->name] : null),
                'accepted_at' => $this->driver_accepted_at?->toISOString(),
                'history' => $this->whenLoaded('deliveryHistory', fn () => $this->deliveryHistory->map(fn ($entry): array => [
                    'from_status' => $entry->from_status, 'to_status' => $entry->to_status, 'actor' => $entry->actor?->name,
                    'driver' => $entry->driver?->name, 'reason' => $entry->reason, 'at' => $entry->created_at?->toISOString()])->all())];
            $data['status_history'] = OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory'));
        }

        return $data;
    }
}

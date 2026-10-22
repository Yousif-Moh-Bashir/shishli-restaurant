<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Services\Drivers\DeliveryExceptionService;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owned = $this->driver_id === $request->user()->id && $this->branch_id === $request->user()->driver_branch_id;
        $data = ['id' => $this->uuid, 'order_number' => $this->order_number, 'branch' => ['id' => $this->branch->uuid, 'name' => $this->branch->name],
            'preparation_status' => $this->status->value, 'estimated_ready_at' => $this->estimated_ready_at?->toISOString(),
            'district' => $this->address?->district, 'delivery_zone' => $this->address?->delivery_zone_name,
            'delivery_fee' => $this->delivery_fee, 'currency' => $this->currency, 'delivery_status' => $this->delivery_status?->value,
            'delivery_status_label' => $this->delivery_status?->label() ?? 'بانتظار المندوب', 'accepted_at' => $this->driver_accepted_at?->toISOString(),
            'available_actions' => $owned ? app(DriverOrderService::class)->actions($request->user(), $this->resource)
                : (($this->status === OrderStatus::Ready && ($request->attributes->get('driver_listing_context')['can_accept'] ?? false)) ? ['accept'] : [])];
        if ($owned) {
            $data += ['customer' => ['name' => $this->customer_name, 'phone' => $this->customer_phone],
                'delivery_revision' => $this->delivery_revision ?? 0,
                'delivery_block' => app(DeliveryExceptionService::class)->block($this->resource),
                'can_request_intervention' => $this->delivery_exception_requested_at === null && $this->delivery_task_closed_at === null
                    && in_array($this->delivery_status?->value, DriverOrderService::ACTIVE_STATUSES, true)
                    && ! in_array($this->status, [OrderStatus::Completed, OrderStatus::Cancelled], true),
                'intervention_requested_at' => $this->delivery_exception_requested_at?->toISOString(),
                'task_closed_at' => $this->delivery_task_closed_at?->toISOString(),
                'address' => $this->address ? new OrderAddressResource($this->address) : null,
                'branch_location' => ['latitude' => $this->branch->latitude, 'longitude' => $this->branch->longitude],
                'total' => $this->total, 'payment_method' => $this->payment_method->value, 'payment_status' => $this->payment_status->value,
                'cash_due' => $this->payment_method->value === 'cash' && $this->payment_status->value === 'pending' ? $this->total : '0.00',
                'notes' => $this->customer_notes,
                'history' => $this->whenLoaded('deliveryHistory', fn () => $this->deliveryHistory->map(fn ($history): array => [
                    'from_status' => $history->from_status, 'to_status' => $history->to_status, 'reason' => $history->reason,
                    'at' => $history->created_at?->toISOString(), 'actor' => $history->actor?->name])->all())];
        }

        return $data;
    }
}

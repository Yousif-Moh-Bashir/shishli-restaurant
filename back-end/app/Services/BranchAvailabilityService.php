<?php

namespace App\Services;

use App\Enums\BranchAvailabilityIssueCode as Issue;
use App\Enums\OrderType;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;

class BranchAvailabilityService
{
    public function __construct(private RestaurantSettingsService $settings, private BranchScheduleService $schedule) {}

    /** Precedence: maintenance, global switch, inactive, branch switch, closure, hours, type switch, support. */
    public function evaluate(Branch $branch, ?OrderType $type = null, ?CarbonInterface $at = null, ?array $values = null): BranchAvailabilityData
    {
        $at ??= CarbonImmutable::now();
        $values ??= $this->settings->all();
        $active = ! $branch->trashed() && $branch->is_active;
        $timezone = $values['restaurant.timezone'];
        $open = $active && $this->schedule->isOpenAt($branch, $at, $timezone);
        $base = match (true) {
            $values['orders.maintenance_mode'] => Issue::OrderingMaintenance,
            ! $values['orders.enabled'] => Issue::RestaurantOrdersDisabled,
            ! $active => Issue::BranchInactive,
            ! $branch->accepts_orders => Issue::BranchNotAcceptingOrders,
            $this->schedule->closureAt($branch, $at) !== null => Issue::BranchTemporarilyClosed,
            ! $open => Issue::BranchClosed,
            default => null,
        };
        $pickup = $base ?? (! $values['pickup.enabled'] ? Issue::PickupDisabled : (! $branch->supports_pickup ? Issue::PickupNotSupported : null));
        $delivery = $base ?? (! $values['delivery.enabled'] ? Issue::DeliveryDisabled : (! $branch->supports_delivery ? Issue::DeliveryNotSupported : null));
        $reason = $type === null ? ($base ?? ($pickup !== null && $delivery !== null ? $pickup : null)) : ($type === OrderType::Pickup ? $pickup : $delivery);
        $next = $active && ! $open ? $this->schedule->nextOpeningTime($branch, $at, $timezone) : null;

        return new BranchAvailabilityData($active, $open, $base === null && ($pickup === null || $delivery === null),
            $pickup === null, $delivery === null, $reason,
            $reason === Issue::OrderingMaintenance ? $values['orders.maintenance_message'] : $reason?->message(), $next, $pickup, $delivery);
    }

    public function assertAvailable(Branch $branch, OrderType $type, CarbonInterface $at, array $values): void
    {
        $result = $this->evaluate($branch, $type, $at, $values);
        if ($result->reasonCode !== null) {
            throw new HttpResponseException(ApiResponse::error($result->message, ['availability' => [['code' => $result->reasonCode->value, 'message' => $result->message]]], 422));
        }
    }
}

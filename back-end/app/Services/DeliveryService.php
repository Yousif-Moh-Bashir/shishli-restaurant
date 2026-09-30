<?php

namespace App\Services;

use App\Enums\DeliveryIssueCode;
use App\Enums\DeliveryZoneType;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;

class DeliveryService
{
    public function __construct(private GeoService $geo, private PricingService $pricing) {}

    public function supportsOrderType(Branch $branch, OrderType $type): bool
    {
        return ! $branch->trashed() && $branch->is_active && $branch->accepts_orders
            && ($type === OrderType::Pickup ? $branch->supports_pickup : $branch->supports_delivery);
    }

    public function calculateDelivery(Branch $branch, DeliveryAddressData|CustomerAddress $address, string $subtotal): DeliveryQuote
    {
        $subtotal = Money::decimal(Money::minor($subtotal));
        if ($branch->trashed() || ! $branch->is_active || ! $branch->accepts_orders) {
            return $this->unavailable($subtotal, DeliveryIssueCode::BranchNotAcceptingOrders);
        }
        if (! $branch->supports_delivery) {
            return $this->unavailable($subtotal, DeliveryIssueCode::DeliveryNotAvailable);
        }
        $address = $address instanceof CustomerAddress ? DeliveryAddressData::fromAddress($address) : $address;
        if (! $address->isValid()) {
            return $this->unavailable($subtotal, DeliveryIssueCode::InvalidAddress);
        }
        $resolved = $this->resolveDeliveryZone($branch, $address);
        $zone = $resolved['zone'];
        if ($zone === null) {
            return $this->unavailable($subtotal, $resolved['issue']);
        }
        $minor = Money::minor($subtotal);
        $minimum = Money::minor($zone->minimum_order);
        $met = $minor >= $minimum;
        $free = $zone->free_delivery_threshold !== null && $minor >= Money::minor($zone->free_delivery_threshold);
        $fee = $free ? '0.00' : $zone->delivery_fee;

        return new DeliveryQuote(
            isAvailable: $met, subtotal: $subtotal, deliveryFee: $fee,
            estimatedTotal: $this->pricing->totalWithDelivery($subtotal, $fee),
            zoneUuid: $zone->uuid, zoneName: $zone->name,
            minimumOrder: $zone->minimum_order, minimumOrderMet: $met,
            remainingAmount: Money::decimal(max(0, $minimum - $minor)), freeDeliveryApplied: $free,
            estimatedMinMinutes: $zone->estimated_min_minutes, estimatedMaxMinutes: $zone->estimated_max_minutes,
            distanceKm: $resolved['distance'], issueCode: $met ? null : DeliveryIssueCode::MinimumOrderNotMet,
        );
    }

    /** @return array{zone:?DeliveryZone,distance:?float,issue:?DeliveryIssueCode} */
    public function resolveDeliveryZone(Branch $branch, DeliveryAddressData $address): array
    {
        $zones = $branch->deliveryZones()->where('is_active', true)->with('districts')
            ->orderByDesc('priority')->orderBy('type')->orderBy('id')->get();
        $needsCoordinates = false;
        foreach ($zones as $zone) {
            if ($zone->type === DeliveryZoneType::District) {
                if ($branch->city !== null && AddressNormalizer::normalize($branch->city) === AddressNormalizer::normalize($address->city)
                    && $zone->districts->contains('normalized_name', AddressNormalizer::normalize($address->district))) {
                    return ['zone' => $zone, 'distance' => null, 'issue' => null];
                }

                continue;
            }
            if ($address->latitude === null || $address->longitude === null) {
                $needsCoordinates = true;

                continue;
            }
            $distance = $this->geo->distanceInKilometers((float) $zone->center_latitude, (float) $zone->center_longitude, (float) $address->latitude, (float) $address->longitude);
            if ($distance <= (float) $zone->radius_km) {
                return ['zone' => $zone, 'distance' => $distance, 'issue' => null];
            }
        }

        return ['zone' => null, 'distance' => null, 'issue' => $needsCoordinates ? DeliveryIssueCode::AddressCoordinatesRequired : DeliveryIssueCode::AddressOutsideDeliveryArea];
    }

    private function unavailable(string $subtotal, DeliveryIssueCode $issue): DeliveryQuote
    {
        return new DeliveryQuote(false, $subtotal, '0.00', $subtotal, issueCode: $issue);
    }
}

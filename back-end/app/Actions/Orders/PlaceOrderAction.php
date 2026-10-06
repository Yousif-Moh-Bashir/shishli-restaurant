<?php

namespace App\Actions\Orders;

use App\Actions\Cart\RepriceCartAction;
use App\Actions\Inventory\ReserveOrderStockAction;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\OrderTiming;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\BranchAvailabilityService;
use App\Services\CartResolver;
use App\Services\DeliveryAddressData;
use App\Services\DeliveryService;
use App\Services\Money;
use App\Services\OrderEstimateService;
use App\Services\OrderNumberGenerator;
use App\Services\OrderTimingService;
use App\Services\PricingService;
use App\Services\PromotionEngine;
use App\Services\RestaurantSettingsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrderAction
{
    public function __construct(
        private CartResolver $resolver,
        private RepriceCartAction $reprice,
        private DeliveryService $delivery,
        private PricingService $pricing,
        private OrderNumberGenerator $numbers,
        private PromotionEngine $promotions,
        private RestaurantSettingsService $settings,
        private BranchAvailabilityService $availability,
        private OrderTimingService $timing,
        private ReserveOrderStockAction $reserveStock,
        private OrderEstimateService $estimates,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Cart $cart, array $data): Order
    {
        return DB::transaction(function () use ($cart, $data): Order {
            if ($cart->user_id !== null) {
                $customer = User::whereKey($cart->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($customer->is_active, 401);
            }
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $existing = $cart->order()->first();
            if ($existing !== null) {
                return $existing->load(['branch', 'items.options', 'address', 'promotions']);
            }
            $this->resolver->assertActive($cart);
            $branch = Branch::withTrashed()->whereKey($cart->branch_id)->lockForUpdate()->firstOrFail();
            $settings = $this->settings->all(fresh: true);
            [$timing, $at] = $this->timing->resolve($data, $settings);
            $type = OrderType::from($data['type']);
            $this->availability->assertAvailable($branch, $type, $at, $settings);
            if ($cart->user_id === null && ! $settings['orders.allow_guest_checkout']) {
                throw ValidationException::withMessages(['customer' => 'يلزم تسجيل الدخول لإتمام الطلب.']);
            }
            $cart = $this->reprice->handle($cart);
            $discounts = $this->promotions->calculate($cart, lock: true);
            $this->promotions->present($cart, $discounts);
            $cart->save();
            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'السلة فارغة.']);
            }
            $issues = $cart->issues;
            foreach ($cart->items as $item) {
                foreach ($item->issues as $issue) {
                    $issues[] = $issue + ['item_uuid' => $item->uuid];
                }
            }
            if ($issues !== []) {
                throw new HttpResponseException(ApiResponse::error('السلة غير صالحة لإتمام الطلب.', ['cart' => $issues], 422));
            }
            $type = OrderType::from($data['type']);
            if (! $this->delivery->supportsOrderType($cart->branch, $type)) {
                throw ValidationException::withMessages(['type' => 'نوع الطلب غير متاح لهذا الفرع.']);
            }
            $subtotal = Money::decimal($cart->items->sum(fn ($item): int => Money::minor($item->line_total)));
            if ($cart->items->sum('quantity') > $settings['orders.maximum_items_per_order']) {
                throw ValidationException::withMessages(['cart' => 'عدد الوحدات يتجاوز الحد المسموح للطلب.']);
            }
            // Minimums use the pre-discount food subtotal, matching the existing delivery-zone contract.
            $minimum = max(Money::minor($settings['orders.minimum_amount']),
                $type === OrderType::Pickup ? Money::minor($settings['pickup.minimum_amount']) : 0);
            if (Money::minor($subtotal) < $minimum) {
                throw ValidationException::withMessages(['cart' => 'لم يتم بلوغ الحد الأدنى للطلب.']);
            }
            $fee = '0.00';
            $address = null;
            $quote = null;
            if ($type === OrderType::Delivery) {
                if (isset($data['address_uuid'])) {
                    abort_if($cart->user_id === null, 404);
                    $saved = $cart->user->addresses()->where('uuid', $data['address_uuid'])->firstOrFail();
                    $address = $saved->only(OrderAddress::SNAPSHOT_FIELDS);
                } else {
                    $address = Arr::only($data['address'], OrderAddress::SNAPSHOT_FIELDS);
                }
                if ($settings['delivery.require_coordinates'] && (($address['latitude'] ?? null) === null || ($address['longitude'] ?? null) === null)) {
                    throw ValidationException::withMessages(['address' => 'إحداثيات عنوان التوصيل مطلوبة.']);
                }
                $quote = $this->delivery->calculateDelivery($cart->branch, DeliveryAddressData::fromArray($address), $subtotal, Money::decimal($discounts->subtotalAfterDiscount));
                if (! $quote->isAvailable) {
                    throw new HttpResponseException(ApiResponse::error('التوصيل غير متاح.', ['delivery' => [
                        ['code' => $quote->issueCode->value, 'message' => $quote->issueCode->message()],
                    ]], 422));
                }
                $fee = $quote->deliveryFee;
            }
            $branch->refresh();
            $settings = $this->settings->all(fresh: true);
            [$timing, $at] = $this->timing->resolve($data, $settings);
            $this->availability->assertAvailable($branch, $type, $at, $settings);
            $order = new Order([
                'order_timing' => $timing, 'scheduled_for' => $timing === OrderTiming::Scheduled ? $at : null,
                'user_id' => $cart->user_id, 'branch_id' => $cart->branch_id, 'cart_id' => $cart->id,
                'order_number' => $this->numbers->generate(), 'type' => $type, 'status' => OrderStatus::Pending,
                'payment_method' => $data['payment_method'], 'payment_status' => PaymentStatus::Pending,
                'customer_name' => $data['customer']['name'], 'customer_phone' => $data['customer']['phone'],
                'customer_email' => $data['customer']['email'] ?? null, 'customer_notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal, 'delivery_fee' => $fee, 'discount_total' => Money::decimal($discounts->discountTotal), 'tax_total' => '0.00',
                'total' => $this->pricing->totalWithDelivery(Money::decimal($discounts->subtotalAfterDiscount), $fee), 'currency' => $settings['restaurant.currency'], 'placed_at' => now(),
            ]);
            $order->access_token = $cart->user_id === null ? Str::random(64) : null;
            $this->estimates->snapshot($order, $cart, $settings, $quote);
            $order->save();
            $order->order_number = $this->numbers->forOrderId($order->id);
            $order->saveQuietly();
            if ($order->payment_method->isOffline()) {
                $order->payments()->create([
                    'method' => $order->payment_method, 'provider' => null, 'status' => PaymentStatus::Pending,
                    'amount' => $order->total, 'currency' => $order->currency, 'idempotency_key' => 'checkout_cash',
                ]);
            }
            $order->statusHistory()->create([
                'from_status' => null, 'to_status' => OrderStatus::Pending,
                'source' => OrderStatusSource::System, 'changed_by' => null, 'note' => null,
            ]);
            if ($address !== null) {
                $order->address()->create($address + ['delivery_zone_name' => $quote->zoneName, 'delivery_zone_uuid' => $quote->zoneUuid]);
            }
            $cart->loadMissing('items.product.category');
            foreach ($cart->items as $item) {
                $snapshot = $order->items()->create([
                    'product_id' => $item->product_id, 'product_uuid' => $item->product->uuid,
                    'product_name' => $item->product->name, 'product_sku' => $item->product->sku,
                    'category_uuid' => $item->product->category?->uuid, 'category_name' => $item->product->category?->name,
                    'quantity' => $item->quantity, 'base_price' => $item->base_price, 'options_total' => $item->options_total,
                    'unit_price' => $item->unit_price, 'line_total' => $item->line_total,
                    'discount_total' => $item->discountTotal, 'line_total_after_discount' => $item->lineTotalAfterDiscount,
                ]);
                $snapshot->options()->createMany($item->options->map(fn ($option): array => [
                    'option_group_uuid' => $option->optionGroup->uuid, 'option_group_name' => $option->optionGroup->name,
                    'option_value_uuid' => $option->optionValue->uuid, 'option_value_name' => $option->optionValue->name,
                    'price_modifier' => $option->price_modifier,
                ])->all());
            }
            foreach ($discounts->appliedPromotions as $applied) {
                $promotion = $applied->promotion;
                $order->promotions()->create([
                    'promotion_uuid' => $applied->promotionUuid, 'promotion_name' => $applied->promotionName,
                    'promotion_type' => $promotion->type, 'discount_type' => $promotion->discount_type,
                    'discount_value' => $promotion->discount_value, 'coupon_code' => $applied->couponCode,
                    'discount_amount' => Money::decimal($applied->discount->discountAmount),
                ]);
                $promotion->usages()->create([
                    'coupon_id' => $applied->coupon?->id, 'order_id' => $order->id, 'user_id' => $cart->user_id,
                    'discount_amount' => Money::decimal($applied->discount->discountAmount), 'used_at' => now(),
                ]);
                $promotion->increment('used_count');
                $applied->coupon?->increment('used_count');
            }
            $this->reserveStock->handle($order);
            $cart->update(['status' => CartStatus::Converted]);
            $order->load(['branch', 'items.options', 'address', 'promotions']);
            OrderPlaced::dispatch($order);

            return $order;
        }, 3);
    }
}

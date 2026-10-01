<?php

namespace App\Actions\Orders;

use App\Actions\Cart\RepriceCartAction;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Http\Responses\ApiResponse;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\CartResolver;
use App\Services\DeliveryAddressData;
use App\Services\DeliveryService;
use App\Services\Money;
use App\Services\OrderNumberGenerator;
use App\Services\PricingService;
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
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Cart $cart, array $data): Order
    {
        return DB::transaction(function () use ($cart, $data): Order {
            if ($cart->user_id !== null) {
                User::whereKey($cart->user_id)->lockForUpdate()->firstOrFail();
            }
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $existing = $cart->order()->first();
            if ($existing !== null) {
                return $existing->load(['branch', 'items.options', 'address']);
            }
            $this->resolver->assertActive($cart);
            $cart = $this->reprice->handle($cart);
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
                $quote = $this->delivery->calculateDelivery($cart->branch, DeliveryAddressData::fromArray($address), $subtotal);
                if (! $quote->isAvailable) {
                    throw new HttpResponseException(ApiResponse::error('التوصيل غير متاح.', ['delivery' => [
                        ['code' => $quote->issueCode->value, 'message' => $quote->issueCode->message()],
                    ]], 422));
                }
                $fee = $quote->deliveryFee;
            }
            $order = new Order([
                'user_id' => $cart->user_id, 'branch_id' => $cart->branch_id, 'cart_id' => $cart->id,
                'order_number' => $this->numbers->generate(), 'type' => $type, 'status' => OrderStatus::Pending,
                'payment_method' => $data['payment_method'], 'payment_status' => PaymentStatus::Pending,
                'customer_name' => $data['customer']['name'], 'customer_phone' => $data['customer']['phone'],
                'customer_email' => $data['customer']['email'] ?? null, 'customer_notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal, 'delivery_fee' => $fee, 'discount_total' => '0.00', 'tax_total' => '0.00',
                'total' => $this->pricing->totalWithDelivery($subtotal, $fee), 'currency' => 'SAR', 'placed_at' => now(),
            ]);
            $order->access_token = $cart->user_id === null ? Str::random(64) : null;
            $order->save();
            if ($order->payment_method === PaymentMethod::Cash) {
                $order->payments()->create([
                    'method' => PaymentMethod::Cash, 'provider' => null, 'status' => PaymentStatus::Pending,
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
            foreach ($cart->items as $item) {
                $snapshot = $order->items()->create([
                    'product_id' => $item->product_id, 'product_uuid' => $item->product->uuid,
                    'product_name' => $item->product->name, 'product_sku' => $item->product->sku,
                    'quantity' => $item->quantity, 'base_price' => $item->base_price, 'options_total' => $item->options_total,
                    'unit_price' => $item->unit_price, 'line_total' => $item->line_total,
                ]);
                $snapshot->options()->createMany($item->options->map(fn ($option): array => [
                    'option_group_uuid' => $option->optionGroup->uuid, 'option_group_name' => $option->optionGroup->name,
                    'option_value_uuid' => $option->optionValue->uuid, 'option_value_name' => $option->optionValue->name,
                    'price_modifier' => $option->price_modifier,
                ])->all());
            }
            $cart->update(['status' => CartStatus::Converted]);
            $order->load(['branch', 'items.options', 'address']);
            OrderPlaced::dispatch($order);

            return $order;
        }, 3);
    }
}

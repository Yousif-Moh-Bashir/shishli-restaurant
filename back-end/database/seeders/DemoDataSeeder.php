<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $this->call([
                RolePermissionSeeder::class, RestaurantSettingsSeeder::class, CmsPageSeeder::class,
                BranchSeeder::class, DeliveryZoneSeeder::class, CategorySeeder::class,
                ProductSeeder::class, OptionSeeder::class, BranchProductSeeder::class,
            ]);

            foreach (['super_admin', 'manager', 'cashier', 'kitchen'] as $index => $role) {
                $user = User::firstOrCreate(['email' => $role.'@demo.shishli.local'], [
                    'name' => 'موظف تجريبي '.$role, 'phone' => '+96655090010'.$index,
                    'password' => 'DemoTest123!',
                ]);
                if ($user->wasRecentlyCreated) {
                    $user->assignRole($role);
                }
            }

            $customers = [];
            foreach (['أحمد محمد', 'سارة علي', 'محمد حسن', 'فاطمة خالد', 'عمر عبدالله', 'مريم أحمد'] as $index => $name) {
                $user = User::firstOrCreate(['email' => 'customer'.($index + 1).'@demo.shishli.local'], [
                    'name' => $name, 'phone' => '+96655090020'.$index, 'password' => 'DemoTest123!',
                ]);
                if ($user->wasRecentlyCreated) {
                    $user->assignRole('customer');
                    CustomerAddress::factory()->create([
                        'user_id' => $user->id, 'recipient_name' => $name, 'phone' => $user->phone,
                        'street' => 'شارع الاختبار '.($index + 1), 'is_default' => true,
                    ]);
                }
                $customers[] = $user;
            }

            $branch = Branch::where('slug', 'al-masif')->firstOrFail();
            if (! $branch->businessHours()->exists()) {
                for ($day = 1; $day <= 7; $day++) {
                    $branch->businessHours()->createMany([
                        ['day_of_week' => $day, 'opens_at' => '00:00', 'closes_at' => '12:00'],
                        ['day_of_week' => $day, 'opens_at' => '12:00', 'closes_at' => '00:00'],
                    ]);
                }
            }

            $products = Product::whereIn('slug', ['shish-tawook', 'turkish-chicken-with-rice', 'mixed-grill-platter', 'beef-kebab', 'half-turkish-chicken'])->get();
            if ($products->isEmpty()) {
                throw new RuntimeException('Demo products are missing.');
            }

            for ($index = 0; $index < 28; $index++) {
                $number = 'DEMO-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                if (Order::where('order_number', $number)->exists()) {
                    continue;
                }
                $customer = $customers[$index % count($customers)];
                $status = OrderStatus::cases()[$index % count(OrderStatus::cases())];
                $delivery = $index % 2 === 1 || $status === OrderStatus::OutForDelivery;
                $paid = $status === OrderStatus::Completed;
                $placedAt = now()->subDays(intdiv($index, 7))->subMinutes($index * 3);
                $order = Order::factory()->create([
                    'order_number' => $number, 'branch_id' => $branch->id, 'user_id' => $customer->id,
                    'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
                    'customer_email' => $customer->email, 'status' => $status,
                    'type' => $delivery ? OrderType::Delivery : OrderType::Pickup,
                    'delivery_fee' => $delivery ? '5.00' : '0.00',
                    'payment_status' => $paid ? PaymentStatus::Paid : PaymentStatus::Pending,
                    'customer_notes' => 'طلب تجريبي لاختبار لوحة التحكم', 'placed_at' => $placedAt,
                ]);
                for ($line = 0; $line < 2; $line++) {
                    $product = $products[($index + $line) % $products->count()];
                    $price = $product->slug === 'shish-tawook' ? '22.00' : $product->base_price;
                    OrderItem::factory()->create([
                        'order_id' => $order->id, 'product_uuid' => $product->uuid,
                        'product_name' => $product->name, 'product_sku' => $product->sku,
                        'quantity' => 1, 'base_price' => $price, 'unit_price' => $price, 'line_total' => $price,
                    ]);
                }
                if ($delivery) {
                    OrderAddress::factory()->create([
                        'order_id' => $order->id, 'recipient_name' => $customer->name,
                        'phone' => $customer->phone, 'street' => 'شارع الاختبار',
                    ]);
                }
                $order->refresh();
                $payment = $order->payments()->create([
                    'method' => $order->payment_method, 'status' => $order->payment_status,
                    'amount' => $order->total, 'currency' => $order->currency,
                ]);
                if ($paid) {
                    $payment->forceFill(['paid_at' => $placedAt->copy()->addHour()])->save();
                    $payment->transactions()->create([
                        'type' => 'sale', 'status' => 'succeeded', 'amount' => $payment->amount,
                        'processed_at' => $payment->paid_at,
                    ]);
                }
                $path = [OrderStatus::Pending];
                if ($status === OrderStatus::Cancelled) {
                    $path[] = OrderStatus::Cancelled;
                } elseif ($status !== OrderStatus::Pending) {
                    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery, OrderStatus::Completed] as $step) {
                        if ($step === OrderStatus::OutForDelivery && ! $delivery) {
                            continue;
                        }
                        $path[] = $step;
                        if ($step === $status) {
                            break;
                        }
                    }
                }
                $previous = null;
                foreach ($path as $offset => $step) {
                    $time = $placedAt->copy()->addMinutes($offset * 10);
                    $order->statusHistory()->create([
                        'from_status' => $previous, 'to_status' => $step, 'source' => 'system',
                        'note' => 'بيانات اختبار', 'created_at' => $time,
                    ]);
                    if ($step !== OrderStatus::Pending) {
                        $column = $step === OrderStatus::OutForDelivery ? 'out_for_delivery_at' : $step->value.'_at';
                        $order->forceFill([$column => $time])->save();
                    }
                    $previous = $step;
                }
            }

            $promotion = Promotion::where('slug', 'demo-coupon-10')->first();
            if ($promotion === null) {
                $promotion = Promotion::factory()->coupon()->create([
                    'slug' => 'demo-coupon-10', 'name' => 'خصم تجريبي 10%',
                    'discount_value' => '10.00', 'minimum_subtotal' => '20.00',
                ]);
            }
            Coupon::firstOrCreate(['code' => 'DEMO10'], ['promotion_id' => $promotion->id, 'is_active' => true]);
        });

        $this->command?->info('Demo data ready: 4 staff, 6 customers, 28 orders, payments and DEMO10 coupon. Existing accounts and orders were preserved.');
    }
}

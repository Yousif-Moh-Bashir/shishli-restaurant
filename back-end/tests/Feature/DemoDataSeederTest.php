<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Money;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_data_is_consistent_and_repeatable_without_resetting_existing_accounts(): void
    {
        $this->freezeTime();
        $existing = User::factory()->create(['email' => 'admin@shishli.local']);
        $passwordHash = $existing->password;
        $this->seed(DemoDataSeeder::class);
        $staff = User::where('email', 'cashier@demo.shishli.local')->firstOrFail();
        $staff->update(['password' => 'ChangedPassword123!']);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame($passwordHash, $existing->fresh()->password);
        $this->assertTrue(Hash::check('ChangedPassword123!', $staff->fresh()->password));
        $this->assertDatabaseCount('orders', 28);
        $this->assertDatabaseCount('order_items', 56);
        $this->assertDatabaseCount('payments', 28);
        $this->assertDatabaseCount('users', 11);
        $this->assertDatabaseHas('coupons', ['code' => 'DEMO10', 'is_active' => true]);
        $admin = User::where('email', 'super_admin@demo.shishli.local')->firstOrFail();
        $this->assertTrue($admin->can('dashboard.view'));
        $this->assertTrue($admin->can('roles.update_permissions'));

        foreach (Order::with(['items', 'payments', 'address', 'statusHistory'])->get() as $order) {
            $subtotal = $order->items->sum(fn ($item): int => Money::minor($item->line_total));
            $this->assertSame($subtotal, Money::minor($order->subtotal));
            $this->assertSame($subtotal + Money::minor($order->delivery_fee), Money::minor($order->total));
            $this->assertSame($order->total, $order->payments->sole()->amount);
            $this->assertSame($order->status, $order->statusHistory->last()->to_status);
            $this->assertSame($order->type->value === 'delivery', $order->address !== null);
            if ($order->status === OrderStatus::Completed) {
                $this->assertSame(PaymentStatus::Paid, $order->payments->sole()->status);
                $this->assertNotNull($order->completed_at);
            }
        }
    }

    public function test_demo_data_is_rejected_in_production_before_writing_records(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->app->make(DemoDataSeeder::class)->run();
            $this->fail('Production seeding must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}

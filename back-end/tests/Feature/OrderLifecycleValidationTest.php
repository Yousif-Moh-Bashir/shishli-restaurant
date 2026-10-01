<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderLifecycleValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidNotes')]
    public function test_invalid_note_returns_422_without_changing_status_timeline_or_history(mixed $note): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create()->assignRole('manager');
        $order = Order::factory()->create();
        Event::fake([OrderStatusChanged::class]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm', ['note' => $note])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_at);
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public static function invalidNotes(): array
    {
        return ['too long' => [str_repeat('a', 1001)], 'array' => [['unexpected']]];
    }

    public function test_customer_duplicate_cancellation_returns_422_and_keeps_original_audit_data(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $order = Order::factory()->for($owner)->create();
        $path = '/api/v1/orders/'.$order->uuid.'/cancel';
        $this->actingAs($owner, 'sanctum')->postJson($path, ['reason' => 'Changed mind'])->assertOk();
        $cancelledAt = $order->fresh()->cancelled_at->toIso8601String();
        $this->travel(1)->minutes();
        Event::fake([OrderStatusChanged::class]);

        $this->postJson($path, ['reason' => 'Different reason'])->assertUnprocessable();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('Changed mind', $order->fresh()->cancellation_reason);
        $this->assertSame($cancelledAt, $order->fresh()->cancelled_at->toIso8601String());
        $this->assertSame($owner->id, $order->fresh()->cancelled_by);
        $this->assertDatabaseCount('order_status_histories', 1);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }
}

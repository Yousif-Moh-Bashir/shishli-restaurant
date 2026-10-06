<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTrackingTimelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function response(Order $order): TestResponse
    {
        return $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'token' => str_repeat('a', 64)])->assertOk();
    }

    #[DataProvider('normalFlows')]
    public function test_each_status_has_correct_steps_progress_history_and_future_timestamps(string $type, string $status, int $step, int $total, int $percentage): void
    {
        $order = Order::factory()->create(['type' => $type, 'status' => $status, 'access_token' => str_repeat('a', 64)]);
        $flow = $type === 'delivery' ? ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'completed'] : ['pending', 'confirmed', 'preparing', 'ready', 'completed'];
        foreach (array_slice($flow, 0, $step) as $index => $code) {
            OrderStatusHistory::factory()->for($order)->create(['to_status' => $code, 'created_at' => '2026-10-03 00:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).':00']);
        }
        $response = $this->response($order)->assertJsonPath('data.progress.current_step', $step)
            ->assertJsonPath('data.progress.total_steps', $total)->assertJsonPath('data.progress.percentage', $percentage)
            ->assertJsonPath('data.order.status.code', $status)->assertJsonCount($total, 'data.timeline');
        $this->assertSame($flow, array_column($response->json('data.timeline'), 'status'));
        foreach ($response->json('data.timeline') as $index => $entry) {
            if ($index + 1 > $step) {
                $this->assertSame('upcoming', $entry['state']);
                $this->assertNull($entry['occurred_at']);
            } elseif ($index + 1 === $step && $status !== 'completed') {
                $this->assertSame('current', $entry['state']);
            } else {
                $this->assertSame('completed', $entry['state']);
            }
        }
        $this->assertSame('2026-10-03T00:00:00.000000Z', $response->json('data.timeline.0.occurred_at'));
    }

    public static function normalFlows(): array
    {
        return [['pickup', 'pending', 1, 5, 20], ['pickup', 'confirmed', 2, 5, 40], ['pickup', 'preparing', 3, 5, 60],
            ['pickup', 'ready', 4, 5, 80], ['pickup', 'completed', 5, 5, 100], ['delivery', 'pending', 1, 6, 17],
            ['delivery', 'confirmed', 2, 6, 33], ['delivery', 'preparing', 3, 6, 50], ['delivery', 'ready', 4, 6, 67],
            ['delivery', 'out_for_delivery', 5, 6, 83], ['delivery', 'completed', 6, 6, 100]];
    }

    #[DataProvider('cancelledFlows')]
    public function test_cancelled_timeline_ends_with_cancellation_and_has_no_future_progress(string $type, array $history): void
    {
        $order = Order::factory()->create(['type' => $type, 'status' => 'cancelled', 'access_token' => str_repeat('a', 64), 'cancellation_reason' => 'internal cancellation']);
        foreach ([...$history, 'cancelled'] as $code) {
            OrderStatusHistory::factory()->for($order)->create(['to_status' => $code, 'note' => 'secret note']);
        }
        $response = $this->response($order)->assertJsonPath('data.progress.percentage', null)->assertJsonPath('data.progress.current_step', null)
            ->assertJsonPath('data.progress.is_cancelled', true);
        $this->assertSame([...$history, 'cancelled'], array_column($response->json('data.timeline'), 'status'));
        $this->assertSame('cancelled', last($response->json('data.timeline'))['state']);
        $this->assertStringNotContainsString('internal cancellation', $response->getContent());
    }

    public static function cancelledFlows(): array
    {
        return [['pickup', ['pending']], ['pickup', ['pending', 'confirmed', 'preparing']], ['delivery', ['pending', 'confirmed', 'preparing', 'ready']]];
    }

    public function test_labels_are_arabic_and_missing_history_is_not_invented_from_updated_at(): void
    {
        $order = Order::factory()->create(['status' => 'preparing', 'access_token' => str_repeat('a', 64)]);
        $this->response($order)->assertJsonPath('data.order.status.label', 'جاري التحضير')
            ->assertJsonPath('data.timeline.0.label', 'تم استلام الطلب')->assertJsonPath('data.timeline.0.occurred_at', null);
    }

    public function test_unknown_historical_and_current_statuses_do_not_fail_or_hide_the_code(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64)]);
        $history = OrderStatusHistory::factory()->for($order)->create();
        DB::table('order_status_histories')->where('id', $history->id)->update(['to_status' => 'future_state']);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'future_state']);
        $response = $this->response($order)->assertJsonPath('data.order.status.code', 'future_state')->assertJsonPath('data.progress.percentage', null);
        $this->assertContains('future_state', array_column($response->json('data.timeline'), 'status'));
    }

    public function test_last_updated_at_uses_latest_history_even_if_order_timestamp_is_older(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64), 'updated_at' => now()->subHour()]);
        $history = OrderStatusHistory::factory()->for($order)->create(['created_at' => now()]);
        $this->response($order)->assertJsonPath('data.last_updated_at', $history->created_at->toISOString());
    }
}

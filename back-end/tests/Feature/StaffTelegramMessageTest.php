<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Services\Notifications\StaffTelegramOrderMessage;
use App\Services\Notifications\StaffTelegramTransport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StaffTelegramMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['staff_notifications.telegram.token' => 'fake-telegram-secret']);
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
    }

    private function order(string $payment, string $type): Order
    {
        $order = new Order([
            'order_number' => 'TEST-123', 'total' => '100.50', 'currency' => 'SAR',
            'payment_method' => $payment, 'type' => $type,
        ]);
        $order->setRelation('branch', new Branch(['name' => 'فرع الاختبار']));
        $item = new OrderItem(['product_name' => "دجاج\nشيشلي", 'quantity' => 3]);
        $item->setRelation('options', new Collection([
            new OrderItemOption(['option_group_name' => 'الحرارة', 'option_value_name' => 'حار']),
        ]));
        $order->setRelation('items', new Collection([$item]));

        return $order;
    }

    public function test_all_existing_payment_methods_and_order_types_are_rendered_with_full_details(): void
    {
        foreach (['cash' => 'نقدي', 'card' => 'بطاقة إلكترونية', 'network' => 'شبكة'] as $payment => $label) {
            foreach (['pickup' => 'استلام من الفرع', 'delivery' => 'توصيل'] as $type => $typeLabel) {
                $message = app(StaffTelegramOrderMessage::class)->render($this->order($payment, $type), 'https://example.test/admin/orders/test');
                $this->assertStringContainsString('طلب جديد #TEST-123', $message);
                $this->assertStringContainsString('الإجمالي: 100.50 SAR', $message);
                $this->assertStringContainsString('طريقة الدفع: '.$label, $message);
                $this->assertStringContainsString('نوع الطلب: '.$typeLabel, $message);
                $this->assertStringContainsString('دجاج شيشلي × 3', $message);
                $this->assertStringContainsString('الحرارة: حار', $message);
            }
        }
        Http::assertNothingSent();
    }

    public function test_long_details_are_sent_once_as_a_complete_document_instead_of_truncating_items(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $text = app(StaffTelegramOrderMessage::class)->render($this->order('cash', 'pickup'), 'https://example.test/admin/orders/test')
            ."\n".str_repeat("صنف طويل × 2\n", 700).'آخر صنف × 9';
        $result = app(StaffTelegramTransport::class)->send('-100123', $text);
        $this->assertSame('sent', $result['status']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($text): bool {
            $caption = collect($request->data())->firstWhere('name', 'caption')['contents'] ?? '';
            $paid = collect($request->data())->firstWhere('name', 'allow_paid_broadcast')['contents'] ?? '';

            return str_ends_with($request->url(), '/sendDocument')
                && $request->hasFile('document', $text, 'shishli-order-details.txt')
                && str_contains($caption, 'TEST-123') && str_contains($caption, '100.50 SAR')
                && $paid === 'false';
        });
    }

    public function test_utf16_text_limit_preserves_emoji_and_plain_messages_are_not_attachments(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $transport = app(StaffTelegramTransport::class);
        $transport->send('-100123', str_repeat('🍗', 2048));
        $transport->send('-100123', str_repeat('🍗', 2049));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['text'] === str_repeat('🍗', 2048));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendDocument') && $request->hasFile('document', str_repeat('🍗', 2049)));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationErrorCode;
use App\Enums\NotificationType;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\EmailMessageData;
use App\Services\Notifications\LaravelEmailProvider;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationTemplateRenderer;
use App\Services\Notifications\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NotificationTemplatesTest extends TestCase
{
    public function test_laravel_mail_adapter_sends_snapshot_with_reference_header_and_stores_message_id(): void
    {
        config(['mail.default' => 'smtp']);
        $email = (new Email)->from('shop@example.com')->to('customer@example.com')->subject('Subject')->text('Body');
        $sent = new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
        $mailer = \Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->with('Body', \Mockery::on(function (\Closure $callback): bool {
            $message = new Message(new Email);
            $callback($message);
            $this->assertSame('Subject', $message->getSymfonyMessage()->getSubject());
            $this->assertSame('notification-uuid', $message->getSymfonyMessage()->getHeaders()->get('X-Notification-Reference')->getBodyAsString());
            $this->assertSame('customer@example.com', $message->getSymfonyMessage()->getTo()[0]->getAddress());

            return true;
        }))->andReturn($sent);
        $result = app(LaravelEmailProvider::class)->send(new EmailMessageData('customer@example.com', 'Customer', 'Subject', 'Body', 'order-uuid', 'notification-uuid'));
        $this->assertTrue($result->success);
        $this->assertSame($sent->getMessageId(), $result->providerMessageId);
    }

    #[DataProvider('smtpErrors')]
    public function test_smtp_failures_are_safe_and_classified(int $smtpCode, NotificationErrorCode $expected): void
    {
        config(['mail.default' => 'smtp']);
        $mailer = \Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->andThrow(new TransportException('secret credentials', $smtpCode));
        $result = app(LaravelEmailProvider::class)->send(new EmailMessageData('customer@example.com', 'Customer', 'Subject', 'Body', 'reference', 'id'));
        $this->assertFalse($result->success);
        $this->assertSame($expected, $result->errorCode);
        $this->assertStringNotContainsString('secret', $result->safeErrorMessage);
    }

    public static function smtpErrors(): array
    {
        return [[0, NotificationErrorCode::ConnectionFailure], [451, NotificationErrorCode::Unavailable],
            [535, NotificationErrorCode::Unauthorized], [550, NotificationErrorCode::Rejected]];
    }

    use RefreshDatabase;

    #[DataProvider('templates')]
    public function test_arabic_templates_contain_order_number_and_correct_status(string $type, string $orderType, string $text): void
    {
        $context = new NotificationContext('order', fake()->uuid(), 'SH-123', OrderType::from($orderType), 'Customer', '+966501234567', 'customer@example.com');
        $rendered = app(NotificationTemplateRenderer::class)->render(NotificationType::from($type), NotificationChannel::Sms, $context);
        $this->assertStringContainsString($text, $rendered->content);
        $this->assertStringContainsString('SH-123', $rendered->content);
        $this->assertNull($rendered->subject);
        $email = app(NotificationTemplateRenderer::class)->render(NotificationType::from($type), NotificationChannel::Email, $context);
        $this->assertStringContainsString('SH-123', $email->subject);
    }

    public static function templates(): array
    {
        return [['order_placed', 'pickup', 'تم استلام'], ['order_confirmed', 'pickup', 'تم تأكيد'], ['order_preparing', 'pickup', 'تجهيز'],
            ['order_ready', 'pickup', 'جاهز للاستلام'], ['order_ready', 'delivery', 'تم تجهيز'], ['order_out_for_delivery', 'delivery', 'خرج للتوصيل'],
            ['order_completed', 'pickup', 'اكتمل'], ['order_cancelled', 'pickup', 'تم إلغاء'], ['payment_succeeded', 'pickup', 'بنجاح'], ['payment_failed', 'pickup', 'تعذر']];
    }

    public function test_delivery_ready_does_not_tell_customer_to_collect(): void
    {
        $context = NotificationContext::fromOrder(Order::factory()->create(['type' => 'delivery']));
        $rendered = app(NotificationTemplateRenderer::class)->render(NotificationType::OrderReady, NotificationChannel::Sms, $context);
        $this->assertStringNotContainsString('جاهز للاستلام', $rendered->content);
    }

    #[DataProvider('phoneNumbers')]
    public function test_phone_normalization_has_no_implicit_country(string $input, ?string $country, ?string $expected): void
    {
        $this->assertSame($expected, app(PhoneNormalizer::class)->normalize($input, $country));
    }

    public static function phoneNumbers(): array
    {
        return [['+966 50-123-4567', null, '+966501234567'], ['00966501234567', null, '+966501234567'], ['0501234567', null, null],
            ['0501234567', 'SA', '+966501234567'], ['966501234567', 'SA', '+966501234567'], ['966501234567', null, null],
            ['+249912345678', null, '+249912345678'], ['123', null, null], ['+0123456789', null, null], ['+966ABC1234567', null, null],
            ['++966501234567', null, null], ['+1234567890123456', null, null], ['', null, null]];
    }

    public function test_recipient_resolver_uses_order_snapshot_and_skips_missing_email(): void
    {
        $user = User::factory()->create(['phone' => '+249912345678', 'email' => 'profile@example.com']);
        $order = Order::factory()->for($user)->create(['customer_phone' => '+966501234567', 'customer_email' => 'snapshot@example.com', 'customer_name' => 'Snapshot']);
        $context = NotificationContext::fromOrder($order);
        $resolver = app(NotificationRecipientResolver::class);
        $this->assertSame('+966501234567', $resolver->resolve($context, NotificationChannel::Sms)['recipient']);
        $this->assertSame('snapshot@example.com', $resolver->resolve($context, NotificationChannel::Email)['recipient']);
        $this->assertSame('Snapshot', $context->customerName);
        $order->customer_email = null;
        $this->assertNull($resolver->resolve(NotificationContext::fromOrder($order), NotificationChannel::Email));
    }

    public function test_channels_require_global_and_type_enablement_and_payment_defaults_are_off(): void
    {
        $resolver = app(NotificationChannelResolver::class);
        $this->assertSame([], $resolver->resolve(NotificationType::OrderPlaced));
        config(['outbound_notifications.channels.whatsapp.enabled' => true]);
        $this->assertSame([NotificationChannel::WhatsApp], $resolver->resolve(NotificationType::OrderPlaced));
        $this->assertSame([], $resolver->resolve(NotificationType::PaymentFailed));
        $this->assertSame([], $resolver->resolve(NotificationType::PaymentSucceeded));
    }

    public function test_whatsapp_template_configuration_is_rendered_without_inventing_real_names(): void
    {
        $context = NotificationContext::fromOrder(Order::factory()->create());
        $renderer = app(NotificationTemplateRenderer::class);
        $this->assertNull($renderer->render(NotificationType::OrderPlaced, NotificationChannel::WhatsApp, $context)->templateName);
        config(['outbound_notifications.whatsapp_templates.order_placed' => ['name' => 'approved_template', 'language' => 'ar_SA']]);
        $result = $renderer->render(NotificationType::OrderPlaced, NotificationChannel::WhatsApp, $context);
        $this->assertSame('approved_template', $result->templateName);
        $this->assertSame('ar_SA', $result->languageCode);
        $this->assertContains($context->orderNumber, $result->parameters);
    }

    public function test_laravel_mail_adapter_rejects_log_and_array_mailers(): void
    {
        Mail::fake();
        $result = app(LaravelEmailProvider::class)->send(new EmailMessageData('test@example.com', 'Test', 'Subject', 'Body', 'reference', 'id'));
        $this->assertFalse($result->success);
        $this->assertSame(NotificationErrorCode::ProviderNotConfigured, $result->errorCode);
        Mail::assertNothingSent();
    }
}

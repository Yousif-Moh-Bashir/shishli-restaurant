<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\OutboundNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OutboundNotification> */
class OutboundNotificationFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => NotificationType::OrderPlaced, 'channel' => NotificationChannel::Sms, 'status' => NotificationStatus::Pending,
            'recipient' => '+966501234567', 'recipient_name' => 'Customer', 'subject' => null, 'content' => 'تم استلام طلبك رقم SH-TEST.',
            'provider' => 'fake', 'reference_type' => 'order', 'reference_uuid' => fake()->uuid(), 'attempts' => 0, 'max_attempts' => 3];
    }

    public function pending(): static
    {
        return $this->state(['status' => NotificationStatus::Pending]);
    }

    public function processing(): static
    {
        return $this->state(['status' => NotificationStatus::Processing, 'processing_token' => fake()->uuid(), 'processing_started_at' => now()]);
    }

    public function sent(): static
    {
        return $this->state(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'provider_message_id' => 'sent-id']);
    }

    public function failed(): static
    {
        return $this->state(['status' => NotificationStatus::Failed, 'failed_at' => now()]);
    }

    public function sms(): static
    {
        return $this->state(['channel' => NotificationChannel::Sms]);
    }

    public function whatsapp(): static
    {
        return $this->state(['channel' => NotificationChannel::WhatsApp, 'metadata' => ['template' => ['name' => 'approved_order_status', 'language' => 'ar', 'parameters' => ['Customer', 'SH-TEST', 'تم استلام طلبك']]]]);
    }

    public function email(): static
    {
        return $this->state(['channel' => NotificationChannel::Email, 'recipient' => 'customer@example.com', 'subject' => 'شيشلي — طلب SH-TEST']);
    }
}

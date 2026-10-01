<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentWebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return ['provider' => 'test', 'provider_event_id' => fake()->uuid(), 'fingerprint' => hash('sha256', fake()->uuid()),
            'event_type' => 'payment.paid', 'signature_valid' => true, 'status' => WebhookEventStatus::Received];
    }
}

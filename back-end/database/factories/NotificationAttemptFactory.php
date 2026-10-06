<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\NotificationAttempt;
use App\Models\OutboundNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationAttempt> */
class NotificationAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['notification_id' => OutboundNotification::factory(), 'attempt_number' => 1, 'status' => NotificationStatus::Failed, 'provider' => 'fake', 'error_code' => 'TIMEOUT', 'error_message' => 'انتهت المهلة.', 'started_at' => now()->subMinute(), 'finished_at' => now()];
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Notifications\SendNotificationAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationErrorCode;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationDeliveryException;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationAttempt;
use App\Models\OutboundNotification;
use App\Models\User;
use App\Services\Notifications\EmailMessageData;
use App\Services\Notifications\NotificationProviderManager;
use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\SmsMessageData;
use App\Services\Notifications\WhatsAppMessageData;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmailProvider;
use Tests\Support\FakeSmsProvider;
use Tests\Support\FakeWhatsAppProvider;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    #[DataProvider('providerExceptions')]
    public function test_provider_exceptions_have_explicit_retry_classification(\Throwable $exception, bool $retryable, string $code): void
    {
        $notification = OutboundNotification::factory()->create();
        app(FakeSmsProvider::class)->results = [$exception];
        $this->assertSame($retryable, app(SendNotificationAction::class)->handle($notification->uuid));
        $this->assertSame($code, $notification->fresh()->last_error_code);
    }

    public static function providerExceptions(): array
    {
        return [
            [new NotificationDeliveryException(NotificationErrorCode::Timeout), true, 'TIMEOUT'],
            [new ConnectionException('secret'), true, 'CONNECTION_FAILURE'],
            [new \RuntimeException('secret'), false, 'PROVIDER_EXCEPTION'],
        ];
    }

    public function test_fake_providers_cannot_be_selected_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(NotificationDeliveryException::class);
        app(NotificationProviderManager::class)->resolve(NotificationChannel::Sms);
    }

    public function test_upper_date_bound_can_be_used_without_lower_bound(): void
    {
        $this->admin();
        OutboundNotification::factory()->create(['created_at' => '2030-01-01 12:00:00']);
        $this->getJson('/api/v1/admin/notifications?date_to=2030-01-01')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_queries_do_not_load_attempt_history_as_records_grow(): void
    {
        $this->admin();
        $notification = OutboundNotification::factory()->create();
        NotificationAttempt::factory()->for($notification, 'notification')->create();
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/notifications')->assertOk();
        $first = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();
        OutboundNotification::factory()->count(10)->create();
        for ($number = 2; $number <= 20; $number++) {
            NotificationAttempt::factory()->for($notification, 'notification')->create(['attempt_number' => $number]);
        }
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonCount(11, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertLessThanOrEqual($first, count($queries));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('notification_attempts', $query['query']);
        }
    }

    public function test_whatsapp_parameter_snapshot_is_immutable_but_manual_retry_metadata_is_allowed(): void
    {
        $notification = OutboundNotification::factory()->whatsapp()->create();
        $metadata = $notification->metadata;
        $metadata['template']['parameters'] = ['Changed'];
        try {
            $notification->update(['metadata' => $metadata]);
            $this->fail('Template snapshots must be immutable.');
        } catch (\LogicException) {
            $this->assertNotSame(['Changed'], $notification->fresh()->metadata['template']['parameters']);
        }
    }

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['sms' => FakeSmsProvider::class, 'whatsapp' => FakeWhatsAppProvider::class, 'email' => FakeEmailProvider::class] as $channel => $driver) {
            config(['outbound_notifications.channels.'.$channel => ['enabled' => true, 'provider' => 'fake'], 'outbound_notifications.providers.'.$channel.'.fake' => $driver]);
            $this->app->singleton($driver);
        }
    }

    #[DataProvider('channels')]
    public function test_provider_success_and_small_uuid_job_payload(string $channel, string $driver, string $dto): void
    {
        $notification = OutboundNotification::factory()->$channel()->create();
        $job = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $job->handle(app(SendNotificationAction::class));
        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('fake-message-1', $notification->provider_message_id);
        $this->assertNotNull($notification->sent_at);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $notification->id, 'attempt_number' => 1, 'status' => 'sent']);
        $message = app($driver)->messages[0];
        $this->assertInstanceOf($dto, $message);
        $this->assertSame($notification->uuid, $message->idempotencyKey);
        $job->assertNotReleased();
        (new SendNotificationJob($notification->uuid))->handle(app(SendNotificationAction::class));
        $this->assertCount(1, app($driver)->messages);
        $this->assertDatabaseCount('notification_attempts', 1);
        $this->assertStringNotContainsString('content', serialize(new SendNotificationJob($notification->uuid)));
    }

    public static function channels(): array
    {
        return [['sms', FakeSmsProvider::class, SmsMessageData::class], ['whatsapp', FakeWhatsAppProvider::class, WhatsAppMessageData::class], ['email', FakeEmailProvider::class, EmailMessageData::class]];
    }

    public function test_retry_history_and_backoff_preserve_same_remote_idempotency_key(): void
    {
        $notification = OutboundNotification::factory()->create();
        $fake = app(FakeSmsProvider::class);
        $fake->results = [NotificationProviderResult::failure(NotificationErrorCode::Timeout), NotificationProviderResult::fromHttpStatus(500), new NotificationProviderResult(true, 'remote-id')];
        $first = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $first->handle(app(SendNotificationAction::class));
        $first->assertReleased(30);
        $this->assertSame(NotificationStatus::Pending, $notification->fresh()->status);
        $second = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $second->handle(app(SendNotificationAction::class));
        $second->assertReleased(120);
        $third = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $third->handle(app(SendNotificationAction::class));
        $third->assertNotReleased();
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        $this->assertSame(3, $notification->fresh()->attempts);
        $this->assertSame(['failed', 'failed', 'sent'], $notification->attemptLogs()->pluck('status')->map(fn ($status): string => $status->value)->all());
        $this->assertSame([$notification->uuid, $notification->uuid, $notification->uuid], array_map(fn ($message): string => $message->idempotencyKey, $fake->messages));
    }

    #[DataProvider('permanentErrors')]
    public function test_permanent_failure_stops_automatic_retry(NotificationErrorCode $code): void
    {
        $notification = OutboundNotification::factory()->create();
        app(FakeSmsProvider::class)->results = [NotificationProviderResult::failure($code)];
        $job = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $job->handle(app(SendNotificationAction::class));
        $job->assertNotReleased();
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame($code->value, $notification->fresh()->last_error_code);
        $this->assertNotNull($notification->fresh()->failed_at);
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    public static function permanentErrors(): array
    {
        return [[NotificationErrorCode::InvalidTemplate], [NotificationErrorCode::Unauthorized], [NotificationErrorCode::Rejected], [NotificationErrorCode::ProviderNotConfigured]];
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_classify_retryability(int $status, bool $retry): void
    {
        $notification = OutboundNotification::factory()->create();
        app(FakeSmsProvider::class)->results = [NotificationProviderResult::fromHttpStatus($status)];
        $this->assertSame($retry, app(SendNotificationAction::class)->handle($notification->uuid));
    }

    public static function httpFailures(): array
    {
        return [[429, true], [500, true], [503, true], [401, false], [403, false], [400, false], [422, false]];
    }

    public function test_retry_exhaustion_is_terminal_and_duplicate_jobs_do_nothing(): void
    {
        $notification = OutboundNotification::factory()->create();
        app(FakeSmsProvider::class)->results = array_fill(0, 3, NotificationProviderResult::failure(NotificationErrorCode::Timeout));
        $sender = app(SendNotificationAction::class);
        $this->assertTrue($sender->handle($notification->uuid));
        $this->assertTrue($sender->handle($notification->uuid));
        $this->assertFalse($sender->handle($notification->uuid));
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame(3, $notification->fresh()->attempts);
        $this->assertFalse($sender->handle($notification->uuid));
        $this->assertCount(3, app(FakeSmsProvider::class)->messages);
        $this->assertDatabaseCount('notification_attempts', 3);
    }

    public function test_invalid_recipient_never_calls_provider(): void
    {
        $notification = OutboundNotification::factory()->create(['recipient' => 'bad phone']);
        $this->assertFalse(app(SendNotificationAction::class)->handle($notification->uuid));
        $this->assertSame('INVALID_RECIPIENT', $notification->fresh()->last_error_code);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    public function test_unconfigured_provider_fails_clearly_and_wrong_interface_is_rejected(): void
    {
        foreach ([null, 'missing', 'wrong'] as $provider) {
            config(['outbound_notifications.providers.sms.wrong' => FakeEmailProvider::class]);
            $notification = OutboundNotification::factory()->create(['provider' => $provider]);
            if ($provider === null) {
                config(['outbound_notifications.channels.sms.provider' => null]);
            }
            app(SendNotificationAction::class)->handle($notification->uuid);
            $this->assertSame('PROVIDER_NOT_CONFIGURED', $notification->fresh()->last_error_code);
        }
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    public function test_invalid_whatsapp_template_is_permanent_without_provider_call(): void
    {
        $notification = OutboundNotification::factory()->whatsapp()->create(['metadata' => []]);
        app(SendNotificationAction::class)->handle($notification->uuid);
        $this->assertSame('INVALID_TEMPLATE', $notification->fresh()->last_error_code);
        $this->assertCount(0, app(FakeWhatsAppProvider::class)->messages);
    }

    public function test_provider_exception_and_metadata_are_sanitized_in_logs_and_api(): void
    {
        $notification = OutboundNotification::factory()->create();
        $fake = app(FakeSmsProvider::class);
        $fake->results = [new \RuntimeException('Authorization: Bearer TOP_SECRET')];
        Log::spy();
        app(SendNotificationAction::class)->handle($notification->uuid);
        $this->assertStringNotContainsString('TOP_SECRET', $notification->fresh()->last_error_message);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['notification_uuid'] === $notification->uuid && ! str_contains(json_encode($context), 'TOP_SECRET'))->once();
        $other = OutboundNotification::factory()->create();
        $fake->results = [new NotificationProviderResult(false, errorCode: NotificationErrorCode::Rejected, safeErrorMessage: 'TOP_SECRET', metadata: ['http_status' => 400, 'delivery_status' => 'rejected', 'token' => 'TOP_SECRET', 'Authorization' => 'TOP_SECRET', 'raw' => str_repeat('TOP_SECRET', 1000)])];
        app(SendNotificationAction::class)->handle($other->uuid);
        $stored = json_encode($other->attemptLogs()->first()->getAttributes());
        $this->assertStringNotContainsString('TOP_SECRET', $stored);
        $this->assertSame(400, $other->attemptLogs()->first()->metadata['http_status']);
        $this->admin();
        $this->getJson('/api/v1/admin/notifications/'.$other->uuid.'/attempts')->assertOk()->assertJsonMissingPath('data.0.metadata');
    }

    public function test_overlapping_duplicate_job_cannot_claim_or_send_twice(): void
    {
        $notification = OutboundNotification::factory()->create();
        $sender = app(SendNotificationAction::class);
        $fake = app(FakeSmsProvider::class);
        $fake->beforeSend = function () use ($notification, $sender): void {
            $this->assertSame(NotificationStatus::Processing, $notification->fresh()->status);
            $this->assertFalse($sender->handle($notification->uuid));
            $this->assertSame(1, $notification->fresh()->attempts);
        };
        $sender->handle($notification->uuid);
        $this->assertCount(1, $fake->messages);
        $this->assertDatabaseCount('notification_attempts', 1);
    }

    public function test_processing_duplicate_is_released_until_owner_finishes(): void
    {
        $notification = OutboundNotification::factory()->processing()->create();
        $job = (new SendNotificationJob($notification->uuid))->withFakeQueueInteractions();
        $job->handle(app(SendNotificationAction::class));
        $job->assertReleased(60);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    public function test_interrupted_worker_requires_review_instead_of_uncertain_resending(): void
    {
        $notification = OutboundNotification::factory()->processing()->create(['attempts' => 1, 'processing_started_at' => now()->subMinutes(2)]);
        NotificationAttempt::factory()->for($notification, 'notification')->create(['status' => 'processing', 'finished_at' => null]);
        app(SendNotificationAction::class)->handle($notification->uuid);
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame('WORKER_INTERRUPTED', $notification->fresh()->last_error_code);
        $this->assertNotNull($notification->attemptLogs()->first()->finished_at);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    #[DataProvider('terminalStatuses')]
    public function test_queue_terminal_failure_never_overwrites_sent_or_cancelled(string $status): void
    {
        $notification = OutboundNotification::factory()->create(['status' => $status]);
        (new SendNotificationJob($notification->uuid))->failed(new \RuntimeException('TOP_SECRET'));
        $this->assertSame($status, $notification->fresh()->status->value);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    public static function terminalStatuses(): array
    {
        return [['sent'], ['cancelled'], ['failed']];
    }

    public function test_failed_callback_finishes_active_attempt_and_preserves_safe_error(): void
    {
        $notification = OutboundNotification::factory()->processing()->create(['attempts' => 1]);
        NotificationAttempt::factory()->for($notification, 'notification')->create(['status' => 'processing', 'finished_at' => null]);
        (new SendNotificationJob($notification->uuid))->failed(new \RuntimeException('secret'));
        $this->assertSame('QUEUE_FAILED', $notification->fresh()->last_error_code);
        $this->assertSame(NotificationStatus::Failed, $notification->attemptLogs()->first()->status);
    }

    public function test_disabling_channel_cancels_pending_delivery(): void
    {
        $notification = OutboundNotification::factory()->create();
        config(['outbound_notifications.channels.sms.enabled' => false]);
        app(SendNotificationAction::class)->handle($notification->uuid);
        $this->assertSame(NotificationStatus::Cancelled, $notification->fresh()->status);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    #[DataProvider('adminEndpoints')]
    public function test_admin_permission_boundaries(string $method, string $suffix): void
    {
        $notification = OutboundNotification::factory()->failed()->create();
        $url = '/api/v1/admin/notifications'.str_replace('UUID', $notification->uuid, $suffix);
        $this->json($method, $url)->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        foreach (['customer', 'cashier', 'kitchen'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role), 'sanctum')->json($method, $url)->assertForbidden();
        }
    }

    public static function adminEndpoints(): array
    {
        return [['GET', ''], ['GET', '/UUID'], ['GET', '/UUID/attempts'], ['POST', '/UUID/retry']];
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_admin_list_masks_recipients_show_content_and_paginate_history(): void
    {
        $this->admin();
        $notification = OutboundNotification::factory()->failed()->create();
        NotificationAttempt::factory()->for($notification, 'notification')->create();
        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonPath('data.0.recipient', '***4567')->assertJsonMissingPath('data.0.content')->assertJsonMissingPath('data.0.metadata')->assertJsonMissingPath('data.0.processing_token');
        $this->getJson('/api/v1/admin/notifications/'.$notification->uuid)->assertOk()->assertJsonPath('data.content', $notification->content)->assertJsonPath('data.recipient', '+966501234567');
        $this->getJson('/api/v1/admin/notifications/'.$notification->uuid.'/attempts')->assertOk()->assertJsonPath('data.0.attempt_number', 1);
        $this->getJson('/api/v1/admin/notifications/'.$notification->id)->assertNotFound();
    }

    public function test_manual_retry_grants_one_extra_attempt_preserves_history_and_is_marked_manual(): void
    {
        Queue::fake();
        $this->admin();
        $notification = OutboundNotification::factory()->failed()->create(['attempts' => 3, 'max_attempts' => 3]);
        for ($i = 1; $i <= 3; $i++) {
            NotificationAttempt::factory()->for($notification, 'notification')->create(['attempt_number' => $i]);
        }
        $url = '/api/v1/admin/notifications/'.$notification->uuid.'/retry';
        $this->postJson($url)->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.max_attempts', 4);
        Queue::assertPushed(SendNotificationJob::class, fn ($job): bool => $job->notificationUuid === $notification->uuid);
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('notification_attempts', 3);
        app(FakeSmsProvider::class)->results = [NotificationProviderResult::failure(NotificationErrorCode::Timeout)];
        $this->assertFalse(app(SendNotificationAction::class)->handle($notification->uuid));
        $this->assertTrue($notification->attemptLogs()->where('attempt_number', 4)->first()->metadata['manual']);
        $this->assertSame(4, $notification->fresh()->attempts);
        $this->assertDatabaseCount('notification_attempts', 4);
    }

    #[DataProvider('nonFailedStatuses')]
    public function test_admin_cannot_retry_non_failed_notifications(string $status): void
    {
        Queue::fake();
        $this->admin();
        $notification = OutboundNotification::factory()->create(['status' => $status]);
        $this->postJson('/api/v1/admin/notifications/'.$notification->uuid.'/retry')->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public static function nonFailedStatuses(): array
    {
        return [['pending'], ['processing'], ['sent'], ['cancelled']];
    }

    public function test_view_and_retry_permissions_are_independent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $notification = OutboundNotification::factory()->failed()->create();
        Queue::fake();
        $this->actingAs(User::factory()->create()->givePermissionTo('notifications.view'), 'sanctum')->getJson('/api/v1/admin/notifications')->assertOk();
        $this->postJson('/api/v1/admin/notifications/'.$notification->uuid.'/retry')->assertForbidden();
        $this->actingAs(User::factory()->create()->givePermissionTo('notifications.retry'), 'sanctum')->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->postJson('/api/v1/admin/notifications/'.$notification->uuid.'/retry')->assertAccepted();
    }

    public function test_filters_and_literal_recipient_search(): void
    {
        $this->admin();
        $matching = OutboundNotification::factory()->sms()->failed()->create(['created_at' => '2030-01-10 12:00:00', 'recipient' => '+966501234567']);
        OutboundNotification::factory()->email()->sent()->create(['created_at' => '2030-01-11 12:00:00']);
        $query = http_build_query(['status' => 'failed', 'channel' => 'sms', 'type' => 'order_placed', 'provider' => 'fake', 'recipient' => '1234', 'reference_uuid' => $matching->reference_uuid, 'date_from' => '2030-01-10', 'date_to' => '2030-01-10', 'per_page' => 1]);
        $this->getJson('/api/v1/admin/notifications?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->uuid);
        $this->getJson('/api/v1/admin/notifications?recipient=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidFilters')]
    public function test_filter_validation(string $query, string $field): void
    {
        $this->admin();
        $this->getJson('/api/v1/admin/notifications?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [['status=invalid', 'status'], ['channel=push', 'channel'], ['type=marketing', 'type'], ['reference_uuid=bad', 'reference_uuid'], ['per_page=101', 'per_page'], ['date_from=2030-02-01&date_to=2030-01-01', 'date_to']];
    }

    public function test_attempt_history_and_content_snapshots_cannot_be_mutated(): void
    {
        $notification = OutboundNotification::factory()->sent()->create();
        $attempt = NotificationAttempt::factory()->for($notification, 'notification')->create();
        foreach (['content', 'recipient', 'subject'] as $field) {
            try {
                $notification->fresh()->update([$field => 'changed']);
                $this->fail('Immutable snapshot');
            } catch (\LogicException) {
                $this->assertNotSame('changed', $notification->fresh()->$field);
            }
        }
        try {
            $attempt->update(['error_message' => 'changed']);
            $this->fail('Immutable history');
        } catch (\LogicException) {
            $this->assertNotSame('changed', $attempt->fresh()->error_message);
        }
        try {
            $attempt->delete();
            $this->fail('Append-only history');
        } catch (\LogicException) {
            $this->assertModelExists($attempt);
        }
    }
}

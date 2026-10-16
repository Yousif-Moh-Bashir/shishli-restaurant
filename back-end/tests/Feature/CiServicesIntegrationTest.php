<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\OutboundNotification;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('ci-services')]
class CiServicesIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CI_SERVICES_INTEGRATION') !== '1') {
            $this->markTestSkipped('Only run against the disposable GitHub Actions MySQL and Redis services.');
        }

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('true', getenv('GITHUB_ACTIONS'));
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame('shishli_ci_test', config('database.connections.mysql.database'));
        $this->assertSame('127.0.0.1', config('database.redis.default.host'));
        $this->assertSame('127.0.0.1', config('database.redis.cache.host'));
        $this->assertStringStartsWith('shishli_ci_', config('database.redis.options.prefix'));
        $this->assertStringStartsWith('shishli_ci_', config('cache.prefix'));
    }

    public function test_services_use_mysql_84_and_redis_74_with_the_migrated_schema(): void
    {
        $database = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS name');
        $redis = Redis::connection()->info('server');

        $this->assertMatchesRegularExpression('/^8\.4\./', $database->version);
        $this->assertSame('shishli_ci_test', $database->name);
        $this->assertMatchesRegularExpression('/^7\.4\./', $redis['redis_version']);
        foreach (['orders', 'payments', 'outbound_notifications', 'driver_cash_collections'] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Missing migrated table: '.$table);
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            $this->assertSame('InnoDB', $engine->engine);
        }
    }

    public function test_redis_cache_persists_values_and_locks_exclude_a_second_owner(): void
    {
        $store = Cache::store('redis');
        $key = 'ci-probe:'.Str::uuid();
        $first = $store->lock($key.':lock', 60);
        $second = $store->lock($key.':lock', 60);

        try {
            $store->put($key, ['order' => 'SH-CI'], 60);
            $this->assertSame(['order' => 'SH-CI'], $store->get($key));
            $this->assertTrue($first->get());
            $this->assertFalse($second->get());
            $this->assertTrue($first->release());
            $this->assertTrue($second->get());
        } finally {
            $second->release();
            $first->release();
            $store->forget($key);
        }
    }

    public function test_redis_sessions_survive_a_new_session_store_instance(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default', 'session.store' => 'redis', 'session.encrypt' => true]);
        $manager = app('session');
        $manager->forgetDrivers();
        $session = $manager->driver('redis');
        $session->start();
        $id = $session->getId();

        try {
            $session->put('ci_probe', 'persisted');
            $session->save();
            $manager->forgetDrivers();
            $restored = $manager->driver('redis');
            $restored->setId($id);
            $restored->start();

            $this->assertSame('persisted', $restored->get('ci_probe'));
        } finally {
            $session->getHandler()->destroy($id);
        }
    }

    public function test_notification_job_is_queued_after_commit_and_processed_without_external_delivery(): void
    {
        config(['outbound_notifications.channels.sms.enabled' => false]);
        $queue = Queue::connection('redis');
        $name = 'ci-probe-'.Str::uuid();
        $notification = null;

        try {
            DB::beginTransaction();
            $notification = OutboundNotification::factory()->sms()->pending()->create();
            $queue->push((new SendNotificationJob($notification->uuid))->onConnection('redis')->onQueue($name), '', $name);
            $this->assertSame(0, $queue->size($name));

            DB::commit();

            $this->assertSame(1, $queue->size($name));
            $reserved = $queue->pop($name);
            $this->assertInstanceOf(RedisJob::class, $reserved);
            $reserved->fire();
            $reserved->delete();
            $this->assertSame(0, $queue->size($name));
            $this->assertSame(NotificationStatus::Cancelled, $notification->fresh()->status);
            $this->assertSame(0, $notification->fresh()->attempts);
            $this->assertNull($notification->fresh()->sent_at);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $queue->clear($name);
            if ($notification !== null) {
                $notification->attemptLogs()->delete();
                $notification->delete();
            }
        }
    }

    public function test_rolling_back_mysql_does_not_publish_a_notification_job_to_redis(): void
    {
        $queue = Queue::connection('redis');
        $name = 'ci-probe-'.Str::uuid();

        try {
            DB::beginTransaction();
            $notification = OutboundNotification::factory()->sms()->pending()->create();
            $queue->push((new SendNotificationJob($notification->uuid))->onConnection('redis')->onQueue($name), '', $name);

            DB::rollBack();

            $this->assertSame(0, $queue->size($name));
            $this->assertDatabaseMissing('outbound_notifications', ['uuid' => $notification->uuid]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $queue->clear($name);
        }
    }
}

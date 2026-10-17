<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use ShishliRuntimeCi\ProbeJob;

// All failures deliberately omit exceptions/SQL/configuration from stdout.
try {
    $app = require __DIR__.'/bootstrap.php';
    $app->make(Kernel::class)->bootstrap();
    $operation = $argv[1] ?? '';
    $token = $argv[2] ?? '';
    $check = static function (bool $condition): void {
        if (! $condition) {
            throw new RuntimeException('CI_ASSERTION_FAILED');
        }
    };
    $check(config('database.default') === 'mysql');
    $check(config('database.connections.mysql.database') === getenv('DB_DATABASE'));
    $check(config('queue.default') === 'redis');
    $check(config('cache.default') === 'redis');

    switch ($operation) {
        case 'connections':
            foreach (['pdo_mysql', 'pdo_sqlite', 'redis', 'gd', 'intl', 'zip', 'Zend OPcache', 'pcntl', 'mbstring', 'bcmath'] as $extension) {
                $check(extension_loaded($extension));
            }
            $check(str_starts_with(PHP_VERSION, '8.3.'));
            $check(str_starts_with($app->version(), '13.'));
            $check(DB::selectOne('SELECT 1 AS ok')->ok === 1);
            $check(str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.'));
            $info = Redis::connection()->info('server');
            $check(str_starts_with((string) ($info['redis_version'] ?? $info['Server']['redis_version'] ?? ''), '7.4.'));
            $sqlite = new PDO('sqlite::memory:');
            $check($sqlite->query('SELECT 1')->fetchColumn() === 1);
            break;
        case 'fresh':
            $check(Schema::getTableListing() === []);
            break;
        case 'schema':
            $check(Schema::hasTable('migrations') && Schema::hasTable('failed_jobs'));
            $check(! Schema::hasTable('ci_runtime_probes'));
            Schema::create('ci_runtime_probes', static function (Blueprint $table): void {
                $table->string('token', 32)->primary();
                $table->string('kind', 16);
            });
            break;
        case 'redis':
            $key = 'runtime-ci-ttl';
            Redis::setex($key, 2, 'probe');
            $check(Redis::get($key) === 'probe');
            $check(Redis::ttl($key) > 0 && Redis::ttl($key) <= 2);
            $deadline = microtime(true) + 5;
            do {
                usleep(100000);
            } while (Redis::exists($key) && microtime(true) < $deadline);
            $check(! Redis::exists($key));
            $first = Cache::lock('runtime-ci-lock', 10);
            $second = Cache::lock('runtime-ci-lock', 10);
            $check($first->get());
            $check(! $second->get());
            $check($first->release());
            $check($second->get());
            $check($second->release());
            break;
        case 'dispatch':
        case 'dispatch-failure':
            $check(preg_match('/^[a-f0-9]{32}$/', $token) === 1);
            $check(! DB::table('ci_runtime_probes')->where('token', $token)->exists());
            Queue::connection('redis')->push(new ProbeJob($token, $operation === 'dispatch-failure'), '', 'runtime-ci');
            $check(Queue::connection('redis')->size('runtime-ci') === 1);
            break;
        case 'effect':
            if (DB::table('ci_runtime_probes')->where('token', $token)->where('kind', $argv[3] ?? '')->count() !== 1) {
                fwrite(STDOUT, "CI_EFFECT_MISSING\n");
                exit(21);
            }
            break;
        case 'absent':
            $check(! DB::table('ci_runtime_probes')->where('token', $token)->exists());
            break;
        case 'failed-job':
            $check(! DB::table('ci_runtime_probes')->where('token', $token)->exists());
            $check(DB::table('failed_jobs')->count() === 1);
            $failed = DB::table('failed_jobs')->first();
            $check($failed->connection === 'redis' && $failed->queue === 'runtime-ci');
            $check(str_contains($failed->payload, $token));
            $check(str_contains($failed->exception, 'CI_INTENTIONAL_JOB_FAILURE'));
            $check(Queue::connection('redis')->size('runtime-ci') === 0);
            break;
        case 'scheduler-failed':
            $check(DB::table('ci_runtime_probes')->where('token', $token)->where('kind', 'scheduler-fail')->count() === 1);
            $check(! DB::table('ci_runtime_probes')->where('token', $token)->where('kind', 'scheduler')->exists());
            break;
        case 'bad-mysql':
            config(['database.connections.mysql.password' => 'deliberately-wrong-ci-password']);
            DB::purge('mysql');
            DB::connection('mysql')->getPdo();
            throw new RuntimeException('CONNECTION_UNEXPECTEDLY_SUCCEEDED');
        case 'bad-redis':
            config(['database.redis.default.url' => null, 'database.redis.default.port' => 1, 'database.redis.default.timeout' => 1]);
            // A resolved manager snapshots config; purge() does not reload it.
            $redis = new Illuminate\Redis\RedisManager($app, config('database.redis.client'), config('database.redis'));
            $redis->connection()->ping();
            throw new RuntimeException('CONNECTION_UNEXPECTEDLY_SUCCEEDED');
        default:
            throw new RuntimeException('UNKNOWN_PROBE');
    }
    fwrite(STDOUT, "CI_PROBE_OK\n");
    exit(0);
} catch (Throwable $exception) {
    // Distinguish expected connection failures from a broken bootstrap/assertion.
    $expectedMysql = ($operation ?? '') === 'bad-mysql'
        && $exception instanceof PDOException
        && (int) ($exception->errorInfo[1] ?? 0) === 1045;
    $expectedRedis = ($operation ?? '') === 'bad-redis'
        && $exception instanceof RedisException;
    if ($expectedMysql || $expectedRedis) {
        fwrite(STDOUT, "CI_EXPECTED_CONNECTION_FAILURE\n");
        exit(20);
    }
    fwrite(STDOUT, "CI_PROBE_FAILED\n");
    exit(10);
}

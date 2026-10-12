<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Isolated local audit router, never installed in the application middleware.
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (str_starts_with($uri, '/storage/') && ! str_contains($uri, "\0")) {
    $root = realpath(__DIR__.'/../../storage/app/public');
    $file = realpath($root.'/'.substr($uri, strlen('/storage/')));
    if ($file !== false && is_file($file)
        && str_starts_with(strtolower(str_replace('\\', '/', $file)), strtolower(str_replace('\\', '/', $root)).'/')
        && preg_match('/\.(png|jpe?g|webp|avif)$/iD', $file)) {
        header('Content-Type: '.mime_content_type($file));
        header('Content-Length: '.filesize($file));
        readfile($file);

        return true;
    }
}
$started = hrtime(true);
$driver = getenv('PERFORMANCE_DATABASE') ?: 'mysql';
$fixture = json_decode(file_get_contents(__DIR__.'/../../../front-end/.performance-check/audit-'.$driver.'.json'), true, flags: JSON_THROW_ON_ERROR);
if ($driver === 'mysql' && ! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Refusing non-audit MySQL database');
}
if ($driver === 'sqlite' && ! str_ends_with($fixture['database'], '/storage/framework/testing/performance-audit.sqlite')) {
    throw new RuntimeException('Refusing non-audit SQLite database');
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.($_SERVER['SERVER_PORT'] ?? '8019'), 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $fixture['database'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing audit bootstrap with cached application configuration');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$boot = (hrtime(true) - $started) / 1e6;
$queries = [];
DB::listen(function (QueryExecuted $event) use (&$queries): void {
    $queries[] = ['sql' => $event->sql, 'ms' => $event->time];
});
$request = Request::capture();
$response = $kernel->handle($request);
$elapsed = (hrtime(true) - $started) / 1e6;
$db = array_sum(array_column($queries, 'ms'));
$response->headers->set('Server-Timing', sprintf('app;dur=%.2f, boot;dur=%.2f, db;dur=%.2f, queries;desc="%d"', $elapsed, $boot, $db, count($queries)));
$response->headers->set('Access-Control-Expose-Headers', 'Server-Timing');
file_put_contents(__DIR__.'/../../storage/framework/testing/performance-'.$driver.'.jsonl', json_encode(['path' => $request->path(), 'status' => $response->getStatusCode(), 'app_ms' => $elapsed, 'boot_ms' => $boot, 'db_ms' => $db, 'queries' => $queries]).PHP_EOL, FILE_APPEND);
$response->send();
$kernel->terminate($request, $response);

<?php

$backend = dirname(__DIR__, 2).'/back-end';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(Illuminate\Contracts\Http\Kernel::class);
$tests = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend.'/tests')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $tests[str_replace($backend.DIRECTORY_SEPARATOR, '', $file->getPathname())] = file_get_contents($file->getPathname());
    }
}
$rows = [];
$md = "# Shishli audit inventory\n\nAudit date: 2026-10-09. Runtime routes and reflected controller sources. No production mutations. Candidate test references are discovery aids, not proof of complete coverage.\n\n## Architecture\n\nLaravel 13 / Sanctum bearer tokens / Spatie Permission 8; Vue 3 / Pinia / Axios / Vue Router / Vite / Leaflet. OTP, account recovery, live GPS, online payment adapter and refunds are not implemented. Drivers are branch-scoped; staff administration is permission-scoped and supports branch filters, without a general staff-branch assignment model.\n\n## Endpoints\n\nExact controller and FormRequest sources define business rules and validation. Standard API errors: 401 authentication, 403 authorization, 404 absent/hidden object, 409 conflict, 422 validation, 429 throttling, safe 500. Successful response and resource details follow the linked method. A route-source mapping alone does not verify all business branches.\n\n| Methods | URI | Auth / permissions | FormRequest | Controller / business and response source | Test candidates | Missing coverage assessment |\n|---|---|---|---|---|---|---|\n";
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (!str_starts_with($route->uri(), 'api/')) {
        continue;
    }
    $action = $route->getActionName();
    $requests = [];
    $source = $action;
    if (str_contains($action, '@')) {
        [$class, $method] = explode('@', $action);
        if (class_exists($class) && method_exists($class, $method)) {
            $reflection = new ReflectionMethod($class, $method);
            $source = str_replace($backend.DIRECTORY_SEPARATOR, '', $reflection->getFileName()).':'.$reflection->getStartLine();
            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), Illuminate\Foundation\Http\FormRequest::class)) {
                    $requests[] = $type->getName();
                }
            }
        }
    }
    $prefix = preg_replace('/\{[^}]+\}/', '', $route->uri());
    $candidates = [];
    foreach ($tests as $path => $text) {
        if (str_contains($text, $prefix)) {
            $candidates[] = $path;
        }
    }
    $row = ['methods' => $route->methods(), 'uri' => $route->uri(), 'middleware' => $route->gatherMiddleware(), 'action' => $action, 'source' => $source, 'requests' => $requests, 'test_candidates' => $candidates];
    $rows[] = $row;
    $safe = fn ($text) => str_replace('|', ' / ', $text);
    $md .= '| '.$safe(implode('|', $row['methods'])).' | `'.$route->uri().'` | '.$safe(implode('; ', $row['middleware'])).' | '.($requests ? implode(', ', $requests) : 'Controller / route binding').' | `'.$source.'` | '.($candidates ? implode(', ', array_slice($candidates, 0, 6)) : 'NOT MAPPED').' | See TEST_COVERAGE; exact per-endpoint branch completeness NOT VERIFIED |'."\n";
}
file_put_contents(__DIR__.'/AUDIT_INVENTORY.md', $md);
file_put_contents(__DIR__.'/routes.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($rows)." API routes inventoried\n";

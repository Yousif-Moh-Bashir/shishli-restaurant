<?php

header('Content-Type: application/json');
$status = opcache_get_status(false);
echo json_encode([
    'enabled' => $status['opcache_enabled'] ?? false,
    'validate_timestamps' => ini_get('opcache.validate_timestamps'),
    'memory' => $status['memory_usage'] ?? null,
    'statistics' => $status['opcache_statistics'] ?? null,
], JSON_PRETTY_PRINT).PHP_EOL;

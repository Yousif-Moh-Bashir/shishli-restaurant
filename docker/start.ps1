$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
if (-not (Test-Path .env.docker)) { throw 'Create .env.docker from the example with fresh secrets first' }
if (-not (Test-Path .env.mysql)) { throw 'Create .env.mysql with matching database credentials first' }
docker info --format '{{.OSType}}'
if ($LASTEXITCODE) { throw 'Docker Engine unavailable; do not restart shared services automatically' }
docker compose -p shishli config --quiet
if ($LASTEXITCODE) { throw 'Invalid Compose configuration' }
$port = if ($env:SHISHLI_HTTP_PORT) { [int]$env:SHISHLI_HTTP_PORT } else { 8088 }
$existing = docker compose -p shishli ps -q nginx
if (-not $existing) {
    $portListener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback,$port)
    try { $portListener.Start() } finally { $portListener.Stop() }
}
# Serial builds reduce peak resource use during image export on Docker Desktop.
foreach ($service in @('app','nginx','tests')) {
    docker compose -p shishli build $service
    if ($LASTEXITCODE) { throw "Build failed: $service" }
}
docker compose -p shishli up -d --wait app
if ($LASTEXITCODE) { throw 'Application startup failed' }
$appContainer = docker compose -p shishli ps -q app
docker cp docker/initialize-system.php "${appContainer}:/tmp/initialize-system.php"
if ($LASTEXITCODE) { throw 'System initialization script copy failed' }
docker compose -p shishli exec -T app php /tmp/initialize-system.php --verify-connection
if ($LASTEXITCODE) { throw 'Docker database target verification failed' }
docker compose -p shishli exec -T app php artisan migrate --force --no-interaction
if ($LASTEXITCODE) { throw 'Docker database migration failed' }
docker compose -p shishli exec -T app php /tmp/initialize-system.php
if ($LASTEXITCODE) { throw 'System initialization failed' }
docker compose -p shishli up -d --wait nginx queue scheduler
if ($LASTEXITCODE) { throw 'Service startup failed' }
docker compose -p shishli ps

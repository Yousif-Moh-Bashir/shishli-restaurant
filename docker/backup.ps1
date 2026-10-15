param([string]$OutputDirectory = 'docker/backups')
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$destination = Join-Path $root "$OutputDirectory/$stamp"
New-Item -ItemType Directory -Force $destination | Out-Null
# Dump inside the container; docker cp preserves bytes on Windows PowerShell.
$databaseContainer = docker compose -p shishli ps -q mysql
docker cp docker/backup-db.sh "${databaseContainer}:/tmp/shishli-backup-db.sh"
if ($LASTEXITCODE) { throw 'Backup script copy failed' }
docker compose -p shishli exec -T mysql sh /tmp/shishli-backup-db.sh
if ($LASTEXITCODE) { throw 'Database backup failed' }
docker cp "${databaseContainer}:/tmp/shishli-backup.sql" "$destination/database.sql"
if ($LASTEXITCODE) { throw 'Database copy failed' }
docker compose -p shishli exec -T app tar -czf /tmp/shishli-storage.tar.gz -C /var/www/html/storage app
if ($LASTEXITCODE) { throw 'Storage backup failed' }
$appContainer = docker compose -p shishli ps -q app
docker cp "${appContainer}:/tmp/shishli-storage.tar.gz" "$destination/storage.tar.gz"
if ($LASTEXITCODE) { throw 'Storage copy failed' }
Get-FileHash "$destination/database.sql", "$destination/storage.tar.gz" | Select-Object Algorithm,Hash,@{Name='File';Expression={Split-Path $_.Path -Leaf}} | ConvertTo-Json | Set-Content "$destination/checksums.json"
Write-Output $destination

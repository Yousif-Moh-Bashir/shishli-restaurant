param([Parameter(Mandatory=$true)][string]$BackupDirectory)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$backup = (Resolve-Path -LiteralPath $BackupDirectory).Path
foreach ($entry in (Get-Content "$backup/checksums.json" -Raw | ConvertFrom-Json)) {
    if ((Get-FileHash (Join-Path $backup $entry.File)).Hash -ne $entry.Hash) { throw 'Backup checksum mismatch' }
}
docker compose -p shishli --profile test up -d --wait mysql-test
if ($LASTEXITCODE) { throw 'Isolated restore database startup failed' }
$container = docker compose -p shishli ps -q mysql-test
docker cp "$backup/database.sql" "${container}:/tmp/shishli-restore.sql"
if ($LASTEXITCODE) { throw 'Backup copy failed' }
# Fixed restore target is on mysql-test, never on the live mysql service.
docker cp docker/restore-db-test.sh "${container}:/tmp/shishli-restore-db.sh"
if ($LASTEXITCODE) { throw 'Restore script copy failed' }
docker compose -p shishli exec -T mysql-test sh /tmp/shishli-restore-db.sh
if ($LASTEXITCODE) { throw 'Isolated restore failed' }
docker compose -p shishli run --rm --no-deps -v "${backup}:/restore:ro" --entrypoint sh tests -c 'mkdir -p /tmp/storage-restore && tar -xzf /restore/storage.tar.gz -C /tmp/storage-restore && test -d /tmp/storage-restore/app/public && echo STORAGE_RESTORE_OK'
if ($LASTEXITCODE) { throw 'Storage restore failed' }

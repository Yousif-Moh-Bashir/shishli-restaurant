#!/usr/bin/env bash
# Review first. No production restart, stop, SQL import, migration, or cleanup commands.
# InnoDB provides the SQL snapshot. File/reference comparisons check net changes;
# without binlog monitoring they do not prove the absence of transient writes or
# provide an atomic snapshot shared by MySQL and the storage volume.
set +x
set -Eeuo pipefail
umask 077
exec 3>&2
export LC_ALL=C
STAGE=${1:-}; RUN_ID=${2:-}; ARG=${3:-}
[[ "$RUN_ID" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$ ]] || { echo 'Usage: bash shishli-backup.sh STAGE UTC_TIMESTAMP-random8 [argument]'; exit 2; }
[[ "$STAGE" =~ ^(preflight|backup|verify|restore|report|seal)$ ]] || exit 2
PROJECT=/opt/shishli
ROOT=/opt/shishli-backups
B="$ROOT/pre-web-push-$RUN_ID"
HERE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
D=(sudo -n docker)
PRIVATE_READY=false
mark_failed() { if [[ "$PRIVATE_READY" == true ]]; then printf '%s\n' "$STAGE" >"$B/failed"; fi; }
fail() { mark_failed; printf 'STOP: %s\n' "$1" >&3; exit 1; }
DEPLOY_UID=$(id -u deploy)
[[ "$EUID" == "$DEPLOY_UID" ]] || fail 'Run as deploy, using sudo only for Docker.'
[[ -d "$ROOT" && ! -L "$ROOT" && $(readlink -f "$ROOT") == "$ROOT" \
   && $(stat -c %a "$ROOT") == 700 && $(stat -c %u "$ROOT") == "$DEPLOY_UID" \
   && -w "$ROOT" && -x "$ROOT" ]] || fail 'Backup root must be owned and writable by deploy, mode 700, without symlinks.'
if [[ "$STAGE" == preflight ]]; then
  [[ ! -e "$B" ]] || fail 'Run directory already exists. Use a new run ID; no files will be overwritten.'
  mkdir -m 700 -- "$B"
else
  [[ -d "$B" && ! -L "$B" && $(stat -c %a "$B") == 700 && $(stat -c %u "$B") == "$DEPLOY_UID" && -f "$B/preflight.ok" ]] || fail 'Missing private completed preflight.'
fi
bad=$(find "$B" \( ! -uid "$DEPLOY_UID" -o \( -type d ! -perm 0700 \) \
  -o \( -type f \( ! -perm 0600 -o -links +1 \) \) -o \( ! -type d ! -type f \) \) -print -quit)
[[ -z "$bad" ]] || fail 'Unsafe owner, permissions, link, or special file in backup directory.'
PRIVATE_READY=true
[[ ! -f "$B/failed" || "$STAGE" == report ]] || fail 'This run previously failed. Preserve it and use a new run ID.'
exec 2>>"$B/private-errors.log"
trap 'rc=$?; mark_failed; printf "FAILED stage=%s line=%s. Private diagnostics: %s\n" "$STAGE" "$LINENO" "$B/private-errors.log" >&3; exit "$rc"' ERR
cd -- "$PROJECT"
dc() { "${D[@]}" compose -p shishli -f "$PROJECT/compose.yaml" "$@"; }
load() {
  read -r APP <"$B/app.cid"; read -r MYSQL <"$B/mysql.cid"
  read -r APP_IMAGE <"$B/app.image"; read -r MYSQL_IMAGE <"$B/mysql.image"
  read -r DB <"$B/database.name"
  [[ "$APP" =~ ^[a-f0-9]{64}$ && "$MYSQL" =~ ^[a-f0-9]{64}$ && "$DB" =~ ^[a-zA-Z0-9_]+$ ]] || fail 'Invalid saved identifiers.'
}
assert_prod() {
  [[ $(dc ps -q app) == "$APP" && $(dc ps -q mysql) == "$MYSQL" ]] || fail 'Production container IDs changed.'
  [[ $("${D[@]}" inspect -f '{{.Image}}' "$APP") == "$APP_IMAGE" && $("${D[@]}" inspect -f '{{.Image}}' "$MYSQL") == "$MYSQL_IMAGE" ]] || fail 'Production images changed.'
  [[ $("${D[@]}" inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{.Type}}:{{.Name}}{{end}}{{end}}' "$APP") == volume:shishli_storage ]] || fail 'Unexpected storage mount.'
  [[ $("${D[@]}" inspect -f '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{.Type}}:{{.Name}}{{end}}{{end}}' "$MYSQL") == volume:shishli_mysql ]] || fail 'Unexpected production MySQL mount.'
  [[ $("${D[@]}" inspect -f '{{.State.Health.Status}}' "$APP") == healthy && $("${D[@]}" inspect -f '{{.State.Health.Status}}' "$MYSQL") == healthy ]] || fail 'Production database/application health changed.'
}
db_mode() {
  local cid=$1
  if [[ "$cid" == "$MYSQL" ]]; then printf production; return; fi
  [[ "$cid" == "shishli-restore-$RUN_ID" ]] || fail 'Unapproved database target.'
  [[ $("${D[@]}" inspect -f '{{index .Config.Labels "shishli.backup.run"}}' "$cid") == "$RUN_ID" ]] || fail 'Test container label mismatch.'
  [[ $("${D[@]}" inspect -f '{{.HostConfig.NetworkMode}}' "$cid") == none ]] || fail 'Test network mismatch.'
  [[ $("${D[@]}" inspect -f '{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}}{{end}}' "$cid") == "volume:shishli-restore-$RUN_ID-mysql:/var/lib/mysql" ]] || fail 'Test volume mismatch.'
  printf restore
}
sql() {
  local cid=$1 mode; shift
  mode=$(db_mode "$cid")
  "${D[@]}" exec -i "$cid" sh -c '
    set -eu
    mode=$1; shift
    if [ "$mode" = restore ]; then export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; user=root;
    else export MYSQL_PWD="$MYSQL_PASSWORD"; user="$MYSQL_USER"; fi
    exec mysql -u "$user" --batch --raw --skip-column-names "$@"
  ' sh "$mode" "$@"
}
dump() {
  local cid=$1 mode; shift
  mode=$(db_mode "$cid")
  "${D[@]}" exec "$cid" sh -c '
    set -eu
    mode=$1; shift
    if [ "$mode" = restore ]; then export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; user=root;
    else export MYSQL_PWD="$MYSQL_PASSWORD"; user="$MYSQL_USER"; fi
    exec mysqldump -u "$user" --single-transaction --quick --skip-lock-tables --no-tablespaces --hex-blob --set-gtid-purged=OFF "$@"
  ' sh "$mode" "$@"
}
full_dump() {
  dump "$1" --routines --triggers --events --skip-comments --skip-dump-date --order-by-primary \
    --skip-add-locks --skip-disable-keys --databases "$DB"
}
reference() {
  local cid=$1 out=$2
  sql "$cid" "$DB" -e "SELECT JSON_ARRAY(c.TABLE_NAME,t.TABLE_TYPE,c.COLUMN_NAME,c.ORDINAL_POSITION,c.DATA_TYPE,c.IS_NULLABLE) FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION" \
    | "${D[@]}" run --rm -i --pull=never --network none --memory=256m --cpus=1 \
      --mount "type=bind,src=$B,dst=/review,readonly" --entrypoint php "$APP_IMAGE" /review/snapshot.php plan >"$B/snapshot.$out.plan.sql"
  sql "$cid" "$DB" <"$B/snapshot.$out.plan.sql" \
    | "${D[@]}" run --rm -i --pull=never --network none --memory=256m --cpus=1 \
      --mount "type=bind,src=$B,dst=/review,readonly" --entrypoint php "$APP_IMAGE" /review/snapshot.php digest >"$B/snapshot.$out.json"
  sql "$cid" "$DB" <"$B/audit.sql" >"$B/counts.$out.tsv"
  awk '$1 ~ /^(invalid_|negative_)/ && $2 != 0 {bad=1} END {exit bad}' "$B/counts.$out.tsv"
}
inventory() {
  "${D[@]}" run --rm --pull=never --network none --memory=256m --cpus=1 \
    --mount "type=volume,src=$1,dst=/source,readonly" \
    --mount "type=bind,src=$B,dst=/review,readonly" \
    --entrypoint php "$APP_IMAGE" /review/inventory.php >"$B/storage.$2.json"
}
verify() {
  [[ -f "$B/backup.ok" ]] || fail 'Backup stage did not complete.'
  (cd "$B" && sha256sum --strict -c SHA256SUMS)
  gzip -t "$B/database.sql.gz"
  tar -tzf "$B/storage.tar.gz" >"$B/archive-list.checked.txt"
  [[ -s "$B/archive-list.checked.txt" ]] || fail 'Empty storage archive.'
}
if [[ "$STAGE" == preflight ]]; then
  for tool in sudo stat openssl gzip tar sha256sum awk cmp df find id readlink; do command -v "$tool" >/dev/null; done
  [[ $(df -Pk "$ROOT" | awk 'NR==2 {print $4}') -ge 3145728 ]] || fail 'At least 3 GiB free disk is required.'
  [[ $(awk '/MemAvailable:/ {print $2}' /proc/meminfo) -ge 2097152 ]] || fail 'At least 2 GiB available RAM is required.'
  APP=$(dc ps -q app); MYSQL=$(dc ps -q mysql)
  [[ "$APP" =~ ^[a-f0-9]{64}$ && "$MYSQL" =~ ^[a-f0-9]{64}$ ]] || fail 'Production services not found.'
  APP_IMAGE=$("${D[@]}" inspect -f '{{.Image}}' "$APP")
  MYSQL_IMAGE=$("${D[@]}" inspect -f '{{.Image}}' "$MYSQL")
  DB=$("${D[@]}" exec "$MYSQL" sh -c 'printf "%s" "$MYSQL_DATABASE"')
  [[ "$DB" == shishli ]] || fail 'Database differs from reviewed shishli database; review before continuing.'
  assert_prod
  printf '%s\n' "$APP" >"$B/app.cid"; printf '%s\n' "$MYSQL" >"$B/mysql.cid"
  printf '%s\n' "$APP_IMAGE" >"$B/app.image"; printf '%s\n' "$MYSQL_IMAGE" >"$B/mysql.image"
  printf '%s\n' "$DB" >"$B/database.name"
  engines=$(sql "$MYSQL" "$DB" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine<>'InnoDB'")
  [[ "$engines" == 0 ]] || fail 'Non-InnoDB tables require another consistency strategy.'
  dump "$MYSQL" --routines --triggers --events --no-data "$DB" >/dev/null
  "${D[@]}" exec "$MYSQL" mysqldump --version >"$B/mysql-tools.txt"
  [[ $(stat -c %a "$PROJECT/.env.docker") == 600 && $(stat -c %a "$PROJECT/.env.mysql") == 600 ]] || fail 'Environment files must have mode 600.'
  cp -- "$HERE/shishli-backup-inventory.php" "$B/inventory.php"
  cp -- "$HERE/shishli-backup-audit.sql" "$B/audit.sql"
  cp -- "$HERE/shishli-backup-snapshot.php" "$B/snapshot.php"
  openssl rand -hex 32 >"$B/reference.key"
  reference "$MYSQL" preflight
  printf 'ok\n' >"$B/preflight.ok"
elif [[ "$STAGE" == backup ]]; then
  [[ "$ARG" == --quiet-confirmed ]] || fail 'Explicit quiet window confirmation is required.'
  [[ ! -e "$B/database.sql.gz" && ! -e "$B/storage.before.json" ]] || fail 'Partial backup exists; use a new run ID.'
  load; assert_prod
  reference "$MYSQL" before
  sql "$MYSQL" "$DB" -e "SELECT JSON_ARRAY(disk,path) FROM product_images ORDER BY id" >"$B/images.jsonl"
  inventory shishli_storage before
  full_dump "$MYSQL" | gzip -c >"$B/database.sql.gz"
  "${D[@]}" run --rm --pull=never --network none --memory=256m --cpus=1 \
    --mount type=volume,src=shishli_storage,dst=/source,readonly \
    --entrypoint tar "$APP_IMAGE" -czf - -C /source . >"$B/storage.tar.gz"
  inventory shishli_storage after
  reference "$MYSQL" after
  cmp -s "$B/storage.before.json" "$B/storage.after.json"
  cmp -s "$B/snapshot.before.json" "$B/snapshot.after.json"
  cmp -s "$B/counts.before.tsv" "$B/counts.after.tsv"
  mkdir -m 700 "$B/config"
  cp -- "$PROJECT/.env.docker" "$PROJECT/.env.mysql" "$PROJECT/compose.yaml" "$B/config/"
  gzip -t "$B/database.sql.gz"; tar -tzf "$B/storage.tar.gz" >/dev/null
  assert_prod
  (cd "$B" && sha256sum database.sql.gz storage.tar.gz snapshot.before.json snapshot.after.json \
    counts.before.tsv counts.after.tsv images.jsonl storage.before.json storage.after.json inventory.php audit.sql snapshot.php reference.key \
    app.cid mysql.cid app.image mysql.image database.name config/.env.docker config/.env.mysql config/compose.yaml >SHA256SUMS)
  printf 'ok\n' >"$B/backup.ok"
elif [[ "$STAGE" == verify ]]; then
  verify
elif [[ "$STAGE" == restore ]]; then
  load; assert_prod; verify
  [[ $(df -Pk "$ROOT" | awk 'NR==2 {print $4}') -ge 3145728 ]] || fail 'Insufficient disk for isolated restore.'
  [[ $(awk '/MemAvailable:/ {print $2}' /proc/meminfo) -ge 2097152 ]] || fail 'Insufficient RAM for isolated restore.'
  [[ ! -e "$B/restore.started" ]] || fail 'Restore already attempted. Existing test resources are retained.'
  NAME="shishli-restore-$RUN_ID"; VDB="$NAME-mysql"; VSTORE="$NAME-storage"
  if "${D[@]}" container inspect "$NAME" >/dev/null 2>&1; then fail 'Test container name exists.'; fi
  for vol in "$VDB" "$VSTORE"; do
    if "${D[@]}" volume inspect "$vol" >/dev/null 2>&1; then fail 'Test volume name exists.'; fi
  done
  printf 'started\n' >"$B/restore.started"
  { printf 'MYSQL_ROOT_PASSWORD='; openssl rand -hex 32; } >"$B/restore.env"
  "${D[@]}" volume create --label "shishli.backup.run=$RUN_ID" "$VDB" >/dev/null
  "${D[@]}" volume create --label "shishli.backup.run=$RUN_ID" "$VSTORE" >/dev/null
  "${D[@]}" run -d --pull=never --name "$NAME" --label "shishli.backup.run=$RUN_ID" \
    --network none --memory=1g --cpus=1 --env-file "$B/restore.env" \
    --mount "type=volume,src=$VDB,dst=/var/lib/mysql" "$MYSQL_IMAGE" \
    --event-scheduler=OFF --skip-log-bin --innodb-buffer-pool-size=128M >/dev/null
  [[ $("${D[@]}" inspect -f '{{.HostConfig.NetworkMode}}' "$NAME") == none ]] || fail 'Test network isolation failed.'
  [[ $("${D[@]}" inspect -f '{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}}{{end}}' "$NAME") == "volume:$VDB:/var/lib/mysql" ]] || fail 'Unexpected test database mount.'
  ready=false
  for ((i=0;i<60;i++)); do
    if sql "$NAME" -e 'SELECT 1' >/dev/null 2>>"$B/private-errors.log"; then ready=true; break; fi
    sleep 2
  done
  [[ "$ready" == true ]] || fail 'Isolated MySQL did not become ready.'
  gzip -dc "$B/database.sql.gz" | sql "$NAME" >"$B/restore-import.log"
  reference "$NAME" restored
  cmp -s "$B/snapshot.before.json" "$B/snapshot.restored.json"
  cmp -s "$B/counts.before.tsv" "$B/counts.restored.tsv"
  "${D[@]}" run --rm --pull=never --network none --memory=256m --cpus=1 \
    --mount "type=volume,src=$VSTORE,dst=/restore" \
    --mount "type=bind,src=$B/storage.tar.gz,dst=/backup.tar.gz,readonly" \
    --entrypoint tar "$APP_IMAGE" -xzf /backup.tar.gz -C /restore
  inventory "$VSTORE" restored
  cmp -s "$B/storage.before.json" "$B/storage.restored.json"
  assert_prod
  db_mode "$NAME" >/dev/null
  "${D[@]}" stop --time 15 "$NAME" >/dev/null
  printf 'ok\n' >"$B/restore.ok"
elif [[ "$STAGE" == seal ]]; then
  [[ -f "$B/restore.ok" ]] || fail 'A successful isolated restore is required first.'
  [[ "$ARG" =~ ^age1[a-z0-9]+$ ]] || fail 'Provide an approved age PUBLIC recipient, never a private key.'
  command -v age >/dev/null
  verify
  [[ ! -e "$B/external-backup.tar.age" ]] || fail 'Encrypted output already exists.'
  tar -cf - -C "$B" database.sql.gz storage.tar.gz snapshot.before.json counts.before.tsv images.jsonl \
    storage.before.json storage.after.json inventory.php audit.sql snapshot.php reference.key config app.cid mysql.cid app.image mysql.image \
    database.name SHA256SUMS snapshot.after.json counts.after.tsv restore.ok \
    | age -r "$ARG" -o "$B/external-backup.tar.age"
  (cd "$B" && sha256sum external-backup.tar.age >external-backup.tar.age.sha256)
elif [[ "$STAGE" == report ]]; then
  load
  if [[ -f "$B/failed" ]]; then echo 'Run FAILED; not approved. Files and test resources retained.'; exit 1; fi
  [[ -f "$B/backup.ok" ]] || fail 'Backup has not completed.'
  printf 'Backup directory: %s\n' "$B"
  for file in database.sql.gz storage.tar.gz external-backup.tar.age; do
    if [[ -f "$B/$file" ]]; then stat -c '%n %s bytes' "$B/$file"; fi
  done
  grep -E ' [ *](database\.sql\.gz|storage\.tar\.gz)$' "$B/SHA256SUMS"
  if [[ -f "$B/restore.ok" ]]; then
    echo 'Isolated restore: PASSED. Only the labelled test container was stopped; test volumes retained.'
    cat "$B/counts.restored.tsv"
    "${D[@]}" run --rm --pull=never --network none --memory=128m \
      --mount "type=bind,src=$B,dst=/review,readonly" --entrypoint php "$APP_IMAGE" -r \
      '$v=json_decode(file_get_contents("/review/storage.restored.json"),true,flags:JSON_THROW_ON_ERROR);echo json_encode(array_intersect_key($v,array_flip(["image_files","file_bytes","product_image_references"]))),PHP_EOL;'
  else echo 'Isolated restore: NOT COMPLETED'; fi
  echo 'Offsite transfer/decryption: NOT VERIFIED by this script.'
fi
printf 'Stage %s completed. Private directory: %s\n' "$STAGE" "$B"

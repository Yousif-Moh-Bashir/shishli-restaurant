#!/bin/sh
set -eu
test "$MYSQL_DATABASE" = shishli_test
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
expected=$(mysql -u root -N -e 'SELECT CONCAT((SELECT COUNT(*) FROM shishli_e2e.users), "/", (SELECT COUNT(*) FROM shishli_e2e.orders), "/", (SELECT COALESCE(SUM(total),0) FROM shishli_e2e.orders), "/", (SELECT COUNT(*) FROM shishli_e2e.driver_cash_collections))')
mysqldump -u root --single-transaction --no-tablespaces shishli_e2e > /tmp/shishli-e2e-backup.sql
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS shishli_restore_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -u root shishli_restore_e2e < /tmp/shishli-e2e-backup.sql
actual=$(mysql -u root -N -e 'SELECT CONCAT((SELECT COUNT(*) FROM shishli_restore_e2e.users), "/", (SELECT COUNT(*) FROM shishli_restore_e2e.orders), "/", (SELECT COALESCE(SUM(total),0) FROM shishli_restore_e2e.orders), "/", (SELECT COUNT(*) FROM shishli_restore_e2e.driver_cash_collections))')
test "$expected" = "$actual"
echo "DATA_RESTORE_OK users/orders/total/collections=$actual"

#!/bin/sh
set -eu
test "$MYSQL_DATABASE" = shishli_test
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS shishli_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -u root shishli_restore < /tmp/shishli-restore.sql
mysql -u root -N -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="shishli_restore"; SELECT COUNT(*) FROM shishli_restore.users'
mysql -u root -N -e 'SELECT COUNT(*) FROM shishli_restore.migrations; SELECT COUNT(*) FROM shishli_restore.roles; SELECT COUNT(*) FROM shishli_restore.permissions'

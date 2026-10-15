#!/bin/sh
set -eu
test "$MYSQL_DATABASE" = shishli_test
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -u root -e 'SET GLOBAL innodb_flush_log_at_trx_commit=2; SET GLOBAL sync_binlog=0'
echo TEST_ONLY_DURABILITY_SETTINGS_APPLIED

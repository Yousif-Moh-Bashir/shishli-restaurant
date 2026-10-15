#!/bin/sh
set -eu
test "$MYSQL_DATABASE" = shishli
export MYSQL_PWD="$MYSQL_PASSWORD"
mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces --routines --triggers "$MYSQL_DATABASE" > /tmp/shishli-backup.sql

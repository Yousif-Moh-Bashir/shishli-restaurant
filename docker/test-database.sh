#!/bin/sh
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS shishli_fix_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'

#!/usr/bin/env bash
#
# Sets up WordPress for the PHPUnit suite.
#
# Adapted from the install-wp-tests.sh that `wp scaffold plugin-tests` writes
# (wp-cli/scaffold-command, MIT). This repo gets the WordPress test library
# from the wp-phpunit/wp-phpunit Composer package, and tests/wp-tests-config.php
# reads the database settings from the environment. So this script only
# downloads WordPress core into WP_CORE_DIR and creates the test database.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> [db-host] [wp-version] [skip-database-creation]
#
# The database password comes from WP_TESTS_DB_PASSWORD, never from an
# argument, so it doesn't end up in the shell history or the CI log.
#
# Then run the tests with the same settings:
#   WP_CORE_DIR=/tmp/wordpress WP_TESTS_DB_NAME=<db-name> WP_TESTS_DB_USER=<db-user> composer test

set -euo pipefail

if [ $# -lt 2 ]; then
	echo "Usage: $0 <db-name> <db-user> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_HOST=${3-127.0.0.1}
WP_VERSION=${4-latest}
SKIP_DB_CREATE=${5-false}
DB_PASS=${WP_TESTS_DB_PASSWORD-}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=${TMPDIR%/}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WP_CORE_DIR=${WP_CORE_DIR%/}

download() {
	if command -v curl > /dev/null; then
		curl -fsSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
		echo "WordPress is already in $WP_CORE_DIR."
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" = 'nightly' ] || [ "$WP_VERSION" = 'trunk' ]; then
		mkdir -p "$TMPDIR/wordpress-nightly"
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "$TMPDIR/wordpress-nightly/wordpress-nightly.zip"
		unzip -q "$TMPDIR/wordpress-nightly/wordpress-nightly.zip" -d "$TMPDIR/wordpress-nightly/"
		mv "$TMPDIR/wordpress-nightly/wordpress/"* "$WP_CORE_DIR"
	else
		local archive
		if [ "$WP_VERSION" = 'latest' ]; then
			archive='latest'
		else
			archive="wordpress-$WP_VERSION"
		fi
		download "https://wordpress.org/${archive}.tar.gz" "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	echo "WordPress $WP_VERSION is in $WP_CORE_DIR."
}

install_db() {
	if [ "$SKIP_DB_CREATE" = 'true' ]; then
		return
	fi

	local host port
	local args=( "--user=$DB_USER" )
	host=${DB_HOST%%:*}
	port=''
	if [ "$host" != "$DB_HOST" ]; then
		port=${DB_HOST#*:}
	fi

	if [ -z "$port" ]; then
		args+=( "--host=$host" --protocol=tcp )
	elif [[ "$port" =~ ^[0-9]+$ ]]; then
		args+=( "--host=$host" "--port=$port" --protocol=tcp )
	else
		args+=( "--socket=$port" )
	fi

	MYSQL_PWD="$DB_PASS" mysql "${args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"
	echo "The database $DB_NAME is ready."
}

install_wp
install_db

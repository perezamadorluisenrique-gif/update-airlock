#!/usr/bin/env bash
# End-to-end run on a throwaway WordPress (SQLite, PHP built-in server). Downloads come from GitHub only.
#
#   bin/e2e.sh                 # WordPress 7.1.3
#   WP_VERSION=6.6 bin/e2e.sh  # oldest supported
#
# Needs: php (pdo_sqlite, zip), git, curl, node + Playwright (for the screen walkthrough; set NODE_PATH to the global install).
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
WP_VERSION=${WP_VERSION:-7.1.3}
CACHE=${E2E_CACHE:-$ROOT/build/e2e-cache}
SITE=$ROOT/build/e2e-site-$WP_VERSION
PORT=${E2E_PORT:-8899}
URL="http://localhost:$PORT"

mkdir -p "$CACHE"
[ -f "$CACHE/wp-cli.phar" ] || curl -sSfL -o "$CACHE/wp-cli.phar" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
[ -d "$CACHE/wp-$WP_VERSION" ] || git -c advice.detachedHead=false clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress "$CACHE/wp-$WP_VERSION"
[ -d "$CACHE/sqlite" ] || git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration "$CACHE/sqlite"

bin/build.sh >/dev/null
rm -rf "$SITE" && mkdir -p "$SITE"
cp -r "$CACHE/wp-$WP_VERSION/." "$SITE/" && rm -rf "$SITE/.git"
SQLITE_SRC="$CACHE/sqlite/packages/plugin-sqlite-database-integration"
[ -d "$SQLITE_SRC" ] || SQLITE_SRC="$CACHE/sqlite"
cp -rL "$SQLITE_SRC" "$SITE/wp-content/plugins/sqlite-database-integration"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
mkdir -p "$SITE/wp-content/database" "$SITE/wp-content/mu-plugins" "$SITE/wp-content/e2e"
cp tests/e2e/mu/updlock-e2e.php "$SITE/wp-content/mu-plugins/"
php tests/e2e/fixtures.php "$SITE/wp-content/e2e" >/dev/null
cp -r "$SITE/wp-content/e2e/v1/"fx-benign "$SITE/wp-content/e2e/v1/"fx-fatal "$SITE/wp-content/e2e/v1/"fx-evil "$SITE/wp-content/e2e/v1/"fx-assets "$SITE/wp-content/plugins/"
cp -r "$SITE/wp-content/e2e/v1/fx-theme" "$SITE/wp-content/themes/"
cp -r build/update-airlock "$SITE/wp-content/plugins/update-airlock"
cat > "$SITE/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'e2e-' . \$k ); }
\$table_prefix = 'wp_';
define( 'WP_HOME', '$URL' ); define( 'WP_SITEURL', '$URL' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

WP="php $CACHE/wp-cli.phar --allow-root --path=$SITE"
$WP core install --url="$URL" --title=E2E --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null 2>&1
$WP post create --post_title="E2E post" --post_status=publish --post_content="Hello" >/dev/null 2>&1
$WP plugin activate update-airlock fx-benign fx-fatal fx-evil fx-assets >/dev/null 2>&1
$WP theme activate twentytwentyfive >/dev/null 2>&1 || true
echo "WordPress $($WP core version 2>/dev/null) on SQLite at $URL"

PHP_CLI_SERVER_WORKERS=4 php -S "localhost:$PORT" -t "$SITE" >"$ROOT/build/e2e-server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null "$URL/wp-login.php" && break; sleep 0.2; done

export SITE WP URL ROOT PORT
bash tests/e2e/scenarios.sh
RESULT=$?
if [ -f tests/e2e/ui.mjs ] && [ -z "${SKIP_UI:-}" ]; then
	WP_PATH="$SITE" WP_CLI="$WP" BASE_URL="$URL" SHOTS="${SHOTS:-}" node tests/e2e/ui.mjs || RESULT=1
fi
exit $RESULT

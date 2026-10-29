#!/usr/bin/env bash
# Multisite scenarios (MULTISITE=1 bin/e2e.sh): a two-site sub-directory network, plugin network-activated.
PASS=0
FAILS=0
CONTENT=$SITE/wp-content
SUB="$URL/two"

ok() { if [ "$1" = "$2" ]; then PASS=$((PASS + 1)); else FAILS=$((FAILS + 1)); echo "FAIL: $3 (got [$1], want [$2])"; fi; }
ev() { $WP eval "$1" 2>/dev/null | tail -n 1; }
ev_sub() { $WP --url="$SUB" eval "$1" 2>/dev/null | tail -n 1; }

echo "-- multisite"
ok "$(ev 'echo is_multisite() ? "yes" : "no";')" "yes" "network is multisite"
ok "$(ev 'echo is_plugin_active_for_network( "update-airlock/update-airlock.php" ) ? "network" : "site";')" "network" "network-activated"
ok "$(ev 'global $wpdb; echo $wpdb->base_prefix . "updlock_events";')" "wp_updlock_events" "tables use the base prefix"
ok "$(ev_sub 'global $wpdb; echo Updlock\Core\Events::table( "events" );')" "wp_updlock_events" "sub-site uses the same tables"
ok "$(ev_sub 'echo Updlock\Core\Opt::get( "updlock_db_version" );')" "1" "state is network-wide"
ok "$(ev_sub 'echo Updlock\Admin\Labels::cap();')" "manage_network_plugins" "network capability"
ok "$(ev_sub 'echo Updlock\Admin\Labels::url();')" "$URL/wp-admin/network/settings.php?page=update-airlock" "report link points at the network screen"

echo "-- network admin screen"
ok "$(ev 'echo is_network_admin() ? "net" : "site";')" "site" "wp-cli runs outside the network screen"
ok "$(ev 'wp_set_current_user( 1 ); echo current_user_can( Updlock\Admin\Labels::cap() ) ? "yes" : "no";')" "yes" "super admin allowed"
$WP user create siteadmin siteadmin@example.com --role=administrator --user_pass=pw --url="$SUB" >/dev/null 2>&1
ok "$(ev_sub 'wp_set_current_user( get_user_by( "login", "siteadmin" )->ID ); echo current_user_can( Updlock\Admin\Labels::cap() ) ? "yes" : "no";')" "no" "sub-site admin refused"

echo "-- settings saved network-wide"
ev 'Updlock\Core\Opt::set( "updlock_settings", Updlock\Core\Settings::sanitize( array( "keep_snapshots" => 5 ) ) );' >/dev/null
ok "$(ev_sub 'echo Updlock\Core\Settings::get( "keep_snapshots" );')" "5" "sub-site reads network settings"

echo "-- cron lives on the main site"
ok "$(ev 'echo wp_next_scheduled( "updlock_daily" ) ? "scheduled" : "none";')" "scheduled" "daily job on the main site"
ok "$(ev_sub 'echo wp_next_scheduled( "updlock_daily" ) ? "scheduled" : "none";')" "none" "no daily job on sub-sites"

echo "-- network screen over HTTP"
JAR=$(mktemp)
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" -H "Cookie: wordpress_test_cookie=WP%20Cookie%20check" "$URL/wp-login.php"
PAGE=$(curl -s -b "$JAR" "$URL/wp-admin/network/settings.php?page=update-airlock&tab=settings")
case "$PAGE" in *"updlock-root"*) PASS=$((PASS + 1));; *) FAILS=$((FAILS + 1)); echo "FAIL: network screen renders";; esac
case "$PAGE" in *"network/edit.php?action=updlock_settings"*) PASS=$((PASS + 1));; *) FAILS=$((FAILS + 1)); echo "FAIL: settings form posts to the network handler";; esac
NONCE=$(printf '%s' "$PAGE" | grep -o 'name="_wpnonce" value="[a-z0-9]*"' | head -1 | sed 's/.*value="//; s/"//')
curl -s -b "$JAR" -o /dev/null -d "_wpnonce=$NONCE&updlock_settings[keep_snapshots]=7" "$URL/wp-admin/network/edit.php?action=updlock_settings"
ok "$(ev_sub 'echo Updlock\Core\Settings::get( "keep_snapshots" );')" "7" "network settings form saves"
rm -f "$JAR"

echo "== multisite: $PASS passed, $FAILS failed"
[ "$FAILS" -eq 0 ]

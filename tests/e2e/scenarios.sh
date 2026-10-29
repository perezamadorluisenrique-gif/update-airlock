#!/usr/bin/env bash
# Command-line scenarios against the throwaway site prepared by bin/e2e.sh (SITE, WP, URL, ROOT in the environment).
PASS=0
FAILS=0
CONTENT=$SITE/wp-content
ZIPS=$URL/wp-content/e2e/zips

ok() { if [ "$1" = "$2" ]; then PASS=$((PASS + 1)); else FAILS=$((FAILS + 1)); echo "FAIL: $3 (got [$1], want [$2])"; fi; }
contains() { case "$1" in *"$2"*) PASS=$((PASS + 1));; *) FAILS=$((FAILS + 1)); echo "FAIL: $3 (no [$2] in [$1])";; esac; }

ev() { $WP eval "$1" 2>/dev/null | tail -n 1; }
last_event() { # slug field
	ev 'foreach ( Updlock\Core\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "'"$1"'" === $r["slug"] ) { echo is_array( $r["'"$2"'"] ?? null ) ? wp_json_encode( $r["'"$2"'"] ) : $r["'"$2"'"]; break; } }'
}
summary() { # slug key
	ev 'foreach ( Updlock\Core\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "'"$1"'" === $r["slug"] ) { echo $r["summary"]["'"$2"'"] ?? ""; break; } }'
}
events_for() { ev 'echo count( array_filter( Updlock\Core\Events::list( "", "", "", 500, 0 ), function ( $r ) { return "'"$1"'" === $r["slug"]; } ) );'; }
publish() { php "$ROOT/tests/e2e/publish.php" "$CONTENT" "$1" "$2" "$3" "$4" "$ZIPS/$2-$4.zip"; }
version() { $WP plugin get "$1" --field=version 2>/dev/null; }
mails() { [ -f "$CONTENT/e2e/mail.log" ] && wc -l < "$CONTENT/e2e/mail.log" | tr -d ' ' || echo 0; }
treehash() { (cd "$1" && find . -type f | sort | xargs sha256sum | sha256sum | cut -d' ' -f1); }
reset_plugin() { rm -rf "$CONTENT/plugins/$1"; cp -r "$CONTENT/e2e/v1/$1" "$CONTENT/plugins/$1"; $WP plugin activate "$1" >/dev/null 2>&1; $WP transient delete --all >/dev/null 2>&1; }
flag() { echo "{\"$1\": $2}" > "$CONTENT/e2e/flags.json"; }
auto_update() { $WP eval 'define( "DOING_CRON", true ); add_filter( "auto_update_plugin", function ( $u, $item ) { return true; }, 5, 2 ); delete_site_transient( "update_plugins" ); wp_maybe_auto_update(); echo "ran";' 2>&1 | tail -n 3; }

echo "-- environment"
ok "$(ev 'echo class_exists( "ZipArchive" ) ? "zip" : "nozip";')" "zip" "ZipArchive available"
ok "$(ev 'echo (int) Updlock\Core\Opt::get( "updlock_db_version" );')" "1" "schema version"
ok "$(ev 'global $wpdb; echo (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $wpdb->base_prefix . "updlock_events" ) ) !== null );')" "1" "events table exists"
$WP eval 'Updlock\Check\Runner::refresh_baseline();' >/dev/null 2>&1
ok "$(ev 'echo count( Updlock\Check\Runner::baseline()["urls"] ) >= 3 ? "ok" : "few";')" "ok" "baseline captured"

echo "-- benign update commits"
publish plugin fx-benign fx-benign/fx-benign.php 1.1.0
OUT=$($WP plugin update fx-benign 2>&1)
contains "$OUT" "Success" "benign update ran"
ok "$(version fx-benign)" "1.1.0" "benign version"
ok "$(last_event fx-benign state)" "committed" "benign state"
ok "$(last_event fx-benign risk)" "low" "benign risk"
ok "$(summary fx-benign check_verdict)" "pass" "benign checks pass"
ok "$(ev 'echo count( Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ) );')" "1" "benign snapshot kept"
ok "$(mails)" "0" "no mail for a clean update"

echo "-- fatal update is rolled back and held"
publish plugin fx-fatal fx-fatal/fx-fatal.php 1.1.0
OUT=$($WP plugin update fx-fatal 2>&1)
ok "$(last_event fx-fatal state)" "rolled_back" "fatal state"
ok "$(version fx-fatal)" "1.0.0" "fatal version restored"
ok "$(treehash "$CONTENT/plugins/fx-fatal")" "$(treehash "$CONTENT/e2e/v1/fx-fatal")" "fatal files identical to the snapshot"
ok "$(ev 'echo Updlock\Upgrade\Holds::is_held( "plugin", "fx-fatal", "1.1.0" ) ? "held" : "free";')" "held" "fatal version held"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" "200" "home page back to 200"
ok "$(ev 'echo is_plugin_active( "fx-fatal/fx-fatal.php" ) ? "active" : "inactive";')" "active" "fatal plugin still active"
ok "$(summary fx-fatal confirm_verdict)" "pass" "checks pass after rollback"
ok "$(mails)" "1" "one rollback mail"
contains "$(cat "$CONTENT/e2e/mail.log")" "Rolled back" "rollback mail text"

echo "-- missing asset only warns"
publish plugin fx-assets fx-assets/fx-assets.php 1.1.0
$WP plugin update fx-assets >/dev/null 2>&1
ok "$(last_event fx-assets state)" "committed" "assets state"
ok "$(summary fx-assets check_verdict)" "warn" "assets warn"
ok "$(version fx-assets)" "1.1.0" "assets kept"

echo "-- risky update: confirmation, then proceeds"
publish plugin fx-evil fx-evil/fx-evil.php 1.0.1
CONFIG_BEFORE=$(sha256sum "$SITE/wp-config.php" | cut -d' ' -f1)
OUT=$($WP plugin update fx-evil 2>&1)
[ -n "${DEBUG_E2E:-}" ] && { echo "$OUT" | grep -v "^Warning: wp_" | tail -8; $WP eval 'foreach(Updlock\Core\Events::list("","","",20,0) as $r) echo $r["id"]," ",$r["slug"]," ",$r["state"]," ",$r["risk"],"\n"; var_dump(Updlock\Core\Settings::get("confirm_manual_high"));' 2>&1 | tail -12; }
ok "$(last_event fx-evil state)" "awaiting_confirmation" "evil state"
ok "$(last_event fx-evil risk)" "high" "evil risk is High"
ok "$(version fx-evil)" "1.0.0" "evil stays on the old version"
ok "$(ev 'echo is_plugin_active( "fx-evil/fx-evil.php" ) ? "active" : "inactive";')" "active" "evil plugin still active after stop"
RULES=$(ev '$id = 0; foreach ( Updlock\Core\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "fx-evil" === $r["slug"] ) { $id = (int) $r["id"]; break; } } echo implode( " ", array_unique( array_column( Updlock\Core\Events::findings( $id ), "rule" ) ) );')
for r in deser.remote persist.wp_config net.new_host prov.author_uri prov.compat_only_big; do contains "$RULES" "$r" "evil finding $r"; done
REASONS=$(ev '$id = 0; foreach ( Updlock\Core\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "fx-evil" === $r["slug"] ) { $id = (int) $r["id"]; break; } } echo implode( "; ", Updlock\Inspect\Scorer::reasons( Updlock\Core\Events::findings( $id ) ) );')
contains "$REASONS" "analytics.fixture-cdn.test" "reason names the host"
ok "$(ev 'echo count( Updlock\Snapshot\Store::for_item( "plugin", "fx-evil" ) );')" "0" "snapshot dropped when stopped"
$WP eval 'Updlock\Upgrade\Holds::confirm( "plugin", "fx-evil", "1.0.1" );' >/dev/null 2>&1
$WP plugin update fx-evil >/dev/null 2>&1
ok "$(version fx-evil)" "1.0.1" "evil installed after confirmation"
ok "$(sha256sum "$SITE/wp-config.php" | cut -d' ' -f1)" "$CONFIG_BEFORE" "wp-config.php untouched (new code never ran)"
ok "$(last_event fx-evil state)" "committed" "evil committed after confirmation"

echo "-- automatic update rated High is held"
reset_plugin fx-evil
ok "$(version fx-evil)" "1.0.0" "evil reset"
publish plugin fx-evil fx-evil/fx-evil.php 1.0.1
BEFORE=$(events_for fx-evil)
auto_update >/dev/null
[ -n "${DEBUG_E2E:-}" ] && tail -5 "$SITE/debug.log" | cut -c1-300
ok "$(version fx-evil)" "1.0.0" "auto update did not install the High version"
ok "$(last_event fx-evil state)" "held" "auto state held"
ok "$(ev 'echo Updlock\Upgrade\Holds::is_held( "plugin", "fx-evil", "1.0.1" ) ? "held" : "free";')" "held" "hold recorded"
AFTER=$(events_for fx-evil)
auto_update >/dev/null
ok "$(events_for fx-evil)" "$AFTER" "second automatic run skips the held version"
contains "$(cat "$CONTENT/e2e/mail.log")" "Held an update" "hold mail"
$WP eval 'Updlock\Upgrade\Holds::release( "plugin", "fx-evil" );' >/dev/null 2>&1
ok "$(ev 'echo Updlock\Upgrade\Holds::is_held( "plugin", "fx-evil", "1.0.1" ) ? "held" : "free";')" "free" "hold released"

echo "-- theme update"
publish theme fx-theme fx-theme/style.css 1.1.0
$WP theme update fx-theme >/dev/null 2>&1
ok "$($WP theme get fx-theme --field=version 2>/dev/null)" "1.1.0" "theme updated"
ok "$(last_event fx-theme state)" "committed" "theme state"
ok "$(last_event fx-theme kind)" "theme" "theme kind"

echo "-- checks that cannot run are inconclusive"
reset_plugin fx-benign
flag block_loopback true
publish plugin fx-benign fx-benign/fx-benign.php 1.1.0
$WP plugin update fx-benign >/dev/null 2>&1
ok "$(last_event fx-benign state)" "unverified" "blocked loopback is unverified"
ok "$(version fx-benign)" "1.1.0" "update kept"
ok "$(ev 'echo count( Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ) ) > 0 ? "kept" : "gone";')" "kept" "snapshot kept when inconclusive"
contains "$(cat "$CONTENT/e2e/mail.log")" "Could not check" "inconclusive mail"
flag block_loopback false

echo "-- manual rollback from a snapshot"
SNAP=$(ev '$s = Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ); echo $s[0]["id"];')
RES=$(ev '$r = Updlock\Snapshot\Restorer::restore( "'"$SNAP"'", true ); echo is_wp_error( $r ) ? $r->get_error_message() : "restored";')
ok "$RES" "restored" "restore result"
ok "$(version fx-benign)" "1.0.0" "restored version"
ok "$(treehash "$CONTENT/plugins/fx-benign")" "$(treehash "$CONTENT/e2e/v1/fx-benign")" "restored files identical"
ok "$(ev 'echo is_plugin_active( "fx-benign/fx-benign.php" ) ? "active" : "inactive";')" "active" "activation preserved"
ok "$(ls -d "$CONTENT"/upgrade/updlock-* 2>/dev/null | wc -l | tr -d ' ')" "0" "no leftover work folders"

echo "-- a corrupted snapshot is refused"
SNAP=$(ev '$s = Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ); echo $s[0]["id"];')
ZIPFILE=$(ev 'echo Updlock\Snapshot\Store::zip_path( "'"$SNAP"'" );')
cp "$ZIPFILE" "$ZIPFILE.bak"
php -r '$z = new ZipArchive(); $z->open( $argv[1] ); $z->addFromString( "fx-benign/fx-benign.php", "<?php // tampered" ); $z->close();' "$ZIPFILE"
RES=$(ev '$r = Updlock\Snapshot\Restorer::restore( "'"$SNAP"'" ); echo is_wp_error( $r ) ? $r->get_error_code() : "restored";')
ok "$RES" "updlock_verify" "tampered zip fails the hash check"
ok "$(version fx-benign)" "1.0.0" "nothing changed after refusal"
mv "$ZIPFILE.bak" "$ZIPFILE"

echo "-- interrupted swap is recovered"
TARGET=$CONTENT/plugins/fx-benign
TRASH=$CONTENT/upgrade/updlock-trash-test
mkdir -p "$CONTENT/upgrade"
mv "$TARGET" "$TRASH"
$WP eval 'Updlock\Core\Opt::set( "updlock_swap", array( "target" => "'"$TARGET"'", "trash" => "'"$TRASH"'", "work" => "", "id" => "'"$SNAP"'", "time" => time() - 600 ) ); Updlock\Snapshot\Restorer::recover();' >/dev/null 2>&1
ok "$(treehash "$TARGET")" "$(treehash "$CONTENT/e2e/v1/fx-benign")" "folder put back from the trash"
ok "$(ev 'echo false === Updlock\Core\Opt::get( "updlock_swap" ) ? "cleared" : "left";')" "cleared" "swap record cleared"

echo "-- snapshots with the PclZip fallback"
flag pclzip true
reset_plugin fx-benign
publish plugin fx-benign fx-benign/fx-benign.php 1.1.0
$WP plugin update fx-benign >/dev/null 2>&1
ok "$(last_event fx-benign state)" "committed" "pclzip update committed"
ZIPFILE=$(ev '$s = Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ); echo Updlock\Snapshot\Store::zip_path( $s[0]["id"] );')
ok "$(php -r '$z = new ZipArchive(); echo true === $z->open( $argv[1], ZipArchive::CHECKCONS ) ? "valid:" . $z->numFiles : "invalid";' "$ZIPFILE")" "valid:2" "pclzip snapshot is a valid zip with all files"
SNAP=$(ev '$s = Updlock\Snapshot\Store::for_item( "plugin", "fx-benign" ); echo $s[0]["id"];')
RES=$(ev '$r = Updlock\Snapshot\Restorer::restore( "'"$SNAP"'" ); echo is_wp_error( $r ) ? $r->get_error_message() : "restored";')
ok "$RES" "restored" "restore from a pclzip snapshot"
ok "$(treehash "$CONTENT/plugins/fx-benign")" "$(treehash "$CONTENT/e2e/v1/fx-benign")" "pclzip restore identical"
flag pclzip false

echo "-- uploaded zip that replaces a plugin is inspected"
reset_plugin fx-benign
BEFORE=$(events_for fx-benign)
# Same call the Plugins > Add New > Upload screen makes for a zip that replaces an installed plugin.
$WP eval 'require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php"; require_once ABSPATH . "wp-admin/includes/file.php"; require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $skin = new Plugin_Installer_Skin( array( "overwrite" => "update-plugin" ) ); $up = new Plugin_Upgrader( $skin ); $up->install( "'"$CONTENT"'/e2e/zips/fx-benign-1.1.0.zip", array( "overwrite_package" => true ) );' >/dev/null 2>&1
ok "$(version fx-benign)" "1.1.0" "uploaded replacement installed"
ok "$(last_event fx-benign trigger_by)" "upload" "upload recorded"
ok "$(( $(events_for fx-benign) - BEFORE ))" "1" "one event for the upload"

echo "-- maintenance mode defers the checks"
reset_plugin fx-benign
$WP eval 'Updlock\Upgrade\Journal::put( 990, array( "kind" => "plugin", "slug" => "fx-benign", "plugin" => "fx-benign/fx-benign.php", "from" => "1.0.0", "to" => "1.1.0", "state" => "verifying", "trigger" => "manual", "created" => time(), "was_active" => true, "network" => false, "snapshot" => "", "log_offset" => -1, "await_activation" => false ) );' >/dev/null 2>&1
echo '<?php $upgrading = '"$(date +%s)"';' > "$SITE/.maintenance"
ev 'echo Updlock\Upgrade\Verifier::run( 990 ) ? "ran" : "deferred";' >/dev/null
ok "$(ev 'echo Updlock\Upgrade\Journal::get( 990 )["state"];')" "verifying" "still waiting during maintenance"
rm -f "$SITE/.maintenance"

echo "-- an entry that was already judged is not judged again"
$WP eval 'Updlock\Upgrade\Journal::set( 990, array( "state" => "committed" ) );' >/dev/null 2>&1
ok "$(ev 'echo Updlock\Upgrade\Verifier::run( 990 ) ? "ran" : "skipped";')" "skipped" "committed entry skipped"
ok "$(ev 'echo Updlock\Upgrade\Journal::get( 990 )["state"];')" "committed" "committed entry unchanged"
ok "$(ev 'require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php"; echo WP_Upgrader::create_lock( "updlock_verify", 60 ) ? "free" : "stuck"; WP_Upgrader::release_lock( "updlock_verify" );')" "free" "verify lock is released"

echo "-- debug.log has nothing from Update Airlock"
if [ -f "$SITE/debug.log" ]; then
	ok "$(grep -c 'plugins/update-airlock/' "$SITE/debug.log")" "0" "no log lines from the plugin"
fi

echo "== scenarios: $PASS passed, $FAILS failed"
[ "$FAILS" -eq 0 ]

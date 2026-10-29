<?php
/**
 * Activation, deactivation and the versioned schema router.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema changes only go through maybe_upgrade().
 */
class Activator {

	/**
	 * Runs on activation.
	 *
	 * @param bool $network_wide Whether activated for the whole network.
	 */
	public static function activate( $network_wide = false ): void {
		self::maybe_upgrade();
		if ( ! wp_next_scheduled( 'updlock_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'updlock_daily' );
		}
		if ( ! $network_wide && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether this is a bulk activation.
			set_transient( 'updlock_welcome', 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Runs on deactivation: removes scheduled work.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'updlock_daily' );
		wp_clear_scheduled_hook( 'updlock_resume' );
		wp_clear_scheduled_hook( 'updlock_verify' );
	}

	/**
	 * Applies pending schema versions.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) Opt::get( 'updlock_db_version', 0 );
		if ( $installed >= UPDLOCK_DB_VERSION ) {
			return;
		}
		if ( $installed < 1 ) {
			self::schema_1();
		}
		Opt::set( 'updlock_db_version', UPDLOCK_DB_VERSION, true );
	}

	/**
	 * Version 1: events, findings, checks and diffs.
	 */
	private static function schema_1(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$events  = Events::table( 'events' );
		$finds   = Events::table( 'findings' );
		$checks  = Events::table( 'checks' );
		$diffs   = Events::table( 'diffs' );

		dbDelta(
			'CREATE TABLE ' . $events . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL DEFAULT 'plugin',
  slug varchar(120) NOT NULL DEFAULT '',
  name varchar(200) NOT NULL DEFAULT '',
  from_ver varchar(60) NOT NULL DEFAULT '',
  to_ver varchar(60) NOT NULL DEFAULT '',
  trigger_by varchar(12) NOT NULL DEFAULT 'manual',
  risk varchar(10) NOT NULL DEFAULT '',
  state varchar(32) NOT NULL DEFAULT 'new',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  summary longtext NULL,
  PRIMARY KEY  (id),
  KEY slug_ver (slug,to_ver),
  KEY created (created_at)
) " . $charset . ';'
		);
		dbDelta(
			'CREATE TABLE ' . $finds . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rule varchar(40) NOT NULL DEFAULT '',
  severity varchar(10) NOT NULL DEFAULT 'low',
  file varchar(250) NOT NULL DEFAULT '',
  line int(11) NOT NULL DEFAULT 0,
  excerpt text NULL,
  detail varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) " . $charset . ';'
		);
		dbDelta(
			'CREATE TABLE ' . $checks . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url varchar(500) NOT NULL DEFAULT '',
  phase varchar(10) NOT NULL DEFAULT 'after',
  status int(11) NOT NULL DEFAULT 0,
  verdict varchar(14) NOT NULL DEFAULT '',
  metrics longtext NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) " . $charset . ';'
		);
		dbDelta(
			'CREATE TABLE ' . $diffs . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path varchar(250) NOT NULL DEFAULT '',
  hunks longtext NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) " . $charset . ';'
		);
	}
}

<?php
/**
 * Removes the plugin's data on uninstall (unless the keep-data setting is on).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$updlock_settings = get_option( 'updlock_settings', array() );
if ( is_array( $updlock_settings ) && ! empty( $updlock_settings['keep_data'] ) ) {
	return;
}

foreach ( array( 'updlock_daily', 'updlock_resume', 'updlock_verify' ) as $updlock_hook ) {
	wp_clear_scheduled_hook( $updlock_hook );
}

$updlock_sub = (string) get_option( 'updlock_snap_dir', '' );
if ( preg_match( '/^[a-f0-9]{16}$/', $updlock_sub ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	if ( WP_Filesystem() && $wp_filesystem ) {
		$wp_filesystem->delete( WP_CONTENT_DIR . '/updlock-snapshots', true );
	}
}

foreach ( array( 'events', 'findings', 'checks', 'diffs' ) as $updlock_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'updlock_' . $updlock_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing this plugin's own tables.
}

foreach ( array( 'updlock_settings', 'updlock_db_version', 'updlock_snapshots', 'updlock_snap_dir', 'updlock_journal', 'updlock_baselines', 'updlock_holds', 'updlock_confirmed', 'updlock_swap' ) as $updlock_option ) {
	delete_option( $updlock_option );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_updlock_' ) . '%', $wpdb->esc_like( '_transient_timeout_updlock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing this plugin's own transients.

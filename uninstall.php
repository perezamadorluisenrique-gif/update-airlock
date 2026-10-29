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

// On multisite the plugin is network-activated and keeps its state in site options and one set of tables.
$updlock_settings = is_multisite() ? get_site_option( 'updlock_settings', array() ) : get_option( 'updlock_settings', array() );
if ( is_array( $updlock_settings ) && ! empty( $updlock_settings['keep_data'] ) ) {
	return;
}

foreach ( array( 'updlock_daily', 'updlock_resume', 'updlock_verify' ) as $updlock_hook ) {
	wp_clear_scheduled_hook( $updlock_hook );
}

$updlock_sub = (string) ( is_multisite() ? get_site_option( 'updlock_snap_dir', '' ) : get_option( 'updlock_snap_dir', '' ) );
if ( preg_match( '/^[a-f0-9]{16}$/', $updlock_sub ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	$updlock_dir = untrailingslashit( WP_CONTENT_DIR ) . '/updlock-snapshots';
	if ( WP_Filesystem() && $wp_filesystem ) {
		// FTP and SSH methods see wp-content under another path than PHP does.
		$updlock_remote = ( 'direct' === $wp_filesystem->method ) ? $updlock_dir : untrailingslashit( $wp_filesystem->wp_content_dir() ) . '/updlock-snapshots';
		$wp_filesystem->delete( $updlock_remote, true );
	}
	if ( is_dir( $updlock_dir ) ) {
		// The configured method could not remove it; fall back to a plain local delete of this one folder.
		$updlock_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $updlock_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $updlock_items as $updlock_item ) {
			if ( $updlock_item->isDir() && ! $updlock_item->isLink() ) {
				rmdir( $updlock_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fallback when the WP_Filesystem method failed.
			} else {
				unlink( $updlock_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback when the WP_Filesystem method failed.
			}
		}
		rmdir( $updlock_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fallback when the WP_Filesystem method failed.
	}
}

foreach ( array( 'events', 'findings', 'checks', 'diffs' ) as $updlock_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'updlock_' . $updlock_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing this plugin's own tables.
}

foreach ( array( 'updlock_settings', 'updlock_db_version', 'updlock_snapshots', 'updlock_snap_dir', 'updlock_journal', 'updlock_baselines', 'updlock_holds', 'updlock_confirmed', 'updlock_swap' ) as $updlock_option ) {
	if ( is_multisite() ) {
		delete_site_option( $updlock_option );
	} else {
		delete_option( $updlock_option );
	}
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_updlock_' ) . '%', $wpdb->esc_like( '_transient_timeout_updlock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing this plugin's own transients.

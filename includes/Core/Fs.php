<?php
/**
 * WP_Filesystem access.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initializes the filesystem abstraction without printing anything.
 */
class Fs {

	/**
	 * The filesystem object, or null when credentials are needed.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function get() {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, WP_CONTENT_DIR, null, true );
		ob_end_clean();
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return null;
		}
		return $wp_filesystem;
	}

	/**
	 * Path of the plugins or themes folder for a kind.
	 *
	 * @param string $kind 'plugin' or 'theme'.
	 * @return string
	 */
	public static function root( string $kind ): string {
		return 'theme' === $kind ? untrailingslashit( get_theme_root() ) : untrailingslashit( WP_PLUGIN_DIR );
	}

	/**
	 * Validates a slug (folder name) before it is used in a path.
	 *
	 * @param string $slug Folder name.
	 * @return bool
	 */
	public static function valid_slug( string $slug ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $slug ) && '.' !== $slug[0];
	}
	/**
	 * Translates a local path into the path the filesystem object expects.
	 *
	 * @param string $local Local absolute path inside wp-content.
	 * @return string
	 */
	public static function remote( string $local ): string {
		$fs = self::get();
		if ( ! $fs || 'direct' === $fs->method ) {
			return $local;
		}
		$map = array(
			WP_PLUGIN_DIR    => $fs->wp_plugins_dir(),
			get_theme_root() => $fs->wp_themes_dir(),
			WP_CONTENT_DIR   => $fs->wp_content_dir(),
		);
		foreach ( $map as $from => $to ) {
			$from = untrailingslashit( $from );
			if ( 0 === strpos( $local, $from ) ) {
				return untrailingslashit( $to ) . substr( $local, strlen( $from ) );
			}
		}
		return $local;
	}

	/**
	 * Core's working folder for upgrades; restores and inspections unpack here so a rename stays on one disk.
	 *
	 * @return string Local path without a trailing slash.
	 */
	public static function upgrade_dir(): string {
		return untrailingslashit( WP_CONTENT_DIR ) . '/upgrade';
	}
}

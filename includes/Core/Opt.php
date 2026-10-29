<?php
/**
 * The plugin's own options: per site normally, network-wide on multisite.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin and theme files, update offers and updates are shared by the whole network, so is this state.
 */
class Opt {

	/**
	 * Reads an option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = false ) {
		return is_multisite() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	/**
	 * Writes an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Autoload (single site only).
	 */
	public static function set( string $name, $value, bool $autoload = false ): void {
		if ( is_multisite() ) {
			update_site_option( $name, $value );
			return;
		}
		update_option( $name, $value, $autoload );
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 */
	public static function delete( string $name ): void {
		if ( is_multisite() ) {
			delete_site_option( $name );
			return;
		}
		delete_option( $name );
	}
}

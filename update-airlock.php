<?php
/**
 * Plugin Name:       Update Airlock
 * Plugin URI:        https://wordpress.org/plugins/update-airlock/
 * Description:       Checks every plugin and theme update for risky code changes, keeps a snapshot, tests your site afterward and rolls back when a check fails.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            lenrique5454
 * Author URI:        https://profiles.wordpress.org/lenrique5454/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       update-airlock
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPDLOCK_VERSION', '1.0.0' );
define( 'UPDLOCK_DB_VERSION', 1 );
define( 'UPDLOCK_FILE', __FILE__ );
define( 'UPDLOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPDLOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'UPDLOCK_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'Updlock\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, 8 ) );
		if ( ! preg_match( '#^[A-Za-z0-9_/]+$#', $relative ) ) {
			return;
		}
		$path = UPDLOCK_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Updlock\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Updlock\\Core\\Activator', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Updlock\\Core\\Plugin', 'boot' ) );

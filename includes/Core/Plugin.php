<?php
/**
 * Plugin wiring.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

use Updlock\Admin\Actions;
use Updlock\Admin\Ajax;
use Updlock\Admin\Dashboard;
use Updlock\Admin\Page;
use Updlock\Admin\Rows;
use Updlock\Admin\Site_Health;
use Updlock\Check\Runner;
use Updlock\Snapshot\Store;
use Updlock\Upgrade\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers everything on plugins_loaded.
 */
class Plugin {

	/**
	 * Boots the plugin.
	 */
	public static function boot(): void {
		Activator::maybe_upgrade();
		Hooks::register();
		Site_Health::register();
		add_action( 'updlock_daily', array( __CLASS__, 'daily' ) );
		if ( is_admin() ) {
			Page::register();
			Actions::register();
			Ajax::register();
			Rows::register();
			Dashboard::register();
		}
	}

	/**
	 * Daily housekeeping: baseline, old history and leftover temporary folders.
	 */
	public static function daily(): void {
		Runner::refresh_baseline();
		Events::prune( 365 );
		$fs = Fs::get();
		if ( ! $fs ) {
			return;
		}
		$swap = Opt::get( 'updlock_swap' );
		foreach ( (array) glob( Fs::upgrade_dir() . '/updlock-*' ) as $path ) {
			$in_use = is_array( $swap ) && in_array( $path, array( (string) ( $swap['trash'] ?? '' ), (string) ( $swap['work'] ?? '' ) ), true );
			if ( ! $in_use && time() - (int) filemtime( $path ) > DAY_IN_SECONDS ) {
				$fs->delete( Fs::remote( $path ), true );
			}
		}
		foreach ( Store::all() as $entry ) {
			Store::enforce_retention( (string) $entry['kind'], (string) $entry['slug'] );
		}
	}
}

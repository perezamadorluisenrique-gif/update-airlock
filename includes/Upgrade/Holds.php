<?php
/**
 * Versions held back from automatic updates.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Upgrade;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A hold names one version; a newer version clears it.
 */
class Holds {

	const OPTION = 'updlock_holds';

	/**
	 * All holds, keyed by "kind|slug".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Holds a version.
	 *
	 * @param string $kind     plugin or theme.
	 * @param string $slug     Folder name.
	 * @param string $version  Version to hold back.
	 * @param int    $event_id Event that explains why.
	 * @param string $reason   Short reason.
	 */
	public static function add( string $kind, string $slug, string $version, int $event_id, string $reason ): void {
		$all                        = self::all();
		$all[ $kind . '|' . $slug ] = array(
			'version'  => $version,
			'event_id' => $event_id,
			'reason'   => $reason,
			'ts'       => time(),
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Releases a hold.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 */
	public static function release( string $kind, string $slug ): void {
		$all = self::all();
		unset( $all[ $kind . '|' . $slug ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * The hold for an item, if any.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $kind, string $slug ) {
		$all = self::all();
		return $all[ $kind . '|' . $slug ] ?? null;
	}

	/**
	 * Whether a specific version is held. A hold on an older version is dropped.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    Folder name.
	 * @param string $version Version offered by the update.
	 * @return bool
	 */
	public static function is_held( string $kind, string $slug, string $version ): bool {
		$hold = self::get( $kind, $slug );
		if ( ! $hold ) {
			return false;
		}
		if ( (string) $hold['version'] !== $version ) {
			self::release( $kind, $slug );
			return false;
		}
		return true;
	}

	/**
	 * Versions the user reviewed and allowed.
	 *
	 * @return array<string,int>
	 */
	public static function confirmed(): array {
		$all = get_option( 'updlock_confirmed', array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Marks a version as reviewed.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    Folder name.
	 * @param string $version Version.
	 */
	public static function confirm( string $kind, string $slug, string $version ): void {
		$all = self::confirmed();
		$all[ $kind . '|' . $slug . '|' . $version ] = time();
		foreach ( $all as $key => $ts ) {
			if ( time() - (int) $ts > WEEK_IN_SECONDS ) {
				unset( $all[ $key ] );
			}
		}
		update_option( 'updlock_confirmed', $all, false );
		self::release( $kind, $slug );
	}

	/**
	 * Whether a version was reviewed and allowed.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    Folder name.
	 * @param string $version Version.
	 * @return bool
	 */
	public static function is_confirmed( string $kind, string $slug, string $version ): bool {
		return isset( self::confirmed()[ $kind . '|' . $slug . '|' . $version ] );
	}
}

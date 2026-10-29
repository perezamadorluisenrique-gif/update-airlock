<?php
/**
 * Journal of in-flight updates, so an interrupted run can be resumed.
 *
 * States: snapshotting, installing, verifying, committed, rolling_back, rolled_back, held, failed.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Upgrade;

use Updlock\Core\Opt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries live in one non-autoloaded option, keyed by event id.
 */
class Journal {

	const OPTION = 'updlock_journal';

	/**
	 * Open entries (those that still need work).
	 *
	 * @var string[]
	 */
	const OPEN = array( 'snapshotting', 'installing', 'verifying', 'rolling_back' );

	/**
	 * All entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$all = Opt::get( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * One entry.
	 *
	 * @param int $event_id Event id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $event_id ) {
		$all = self::all();
		return $all[ $event_id ] ?? null;
	}

	/**
	 * Adds or replaces an entry.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $entry    Entry.
	 */
	public static function put( int $event_id, array $entry ): void {
		$all              = self::all();
		$entry['updated'] = time();
		$all[ $event_id ] = $entry;
		Opt::set( self::OPTION, self::trim( $all ), false );
	}

	/**
	 * Changes fields of an entry.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $fields   Fields.
	 */
	public static function set( int $event_id, array $fields ): void {
		$entry = self::get( $event_id );
		if ( null !== $entry ) {
			self::put( $event_id, array_merge( $entry, $fields ) );
		}
	}

	/**
	 * Keeps finished entries for a day and open ones for a week.
	 *
	 * @param array<int,array<string,mixed>> $all Entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function trim( array $all ): array {
		foreach ( $all as $id => $entry ) {
			$age  = time() - (int) ( $entry['updated'] ?? 0 );
			$open = in_array( $entry['state'] ?? '', self::OPEN, true );
			if ( ( ! $open && $age > DAY_IN_SECONDS ) || $age > WEEK_IN_SECONDS ) {
				unset( $all[ $id ] );
			}
		}
		return $all;
	}

	/**
	 * Entries waiting for verification.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending(): array {
		$out = array();
		foreach ( self::all() as $id => $entry ) {
			if ( 'verifying' === ( $entry['state'] ?? '' ) ) {
				$out[ $id ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Finds the newest open entry for an item.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 * @return array<string,mixed>|null Entry with its event id under `event_id`.
	 */
	public static function open_for( string $kind, string $slug ) {
		$found = null;
		foreach ( self::all() as $id => $entry ) {
			if ( ( $entry['kind'] ?? '' ) === $kind && ( $entry['slug'] ?? '' ) === $slug && in_array( $entry['state'] ?? '', self::OPEN, true ) ) {
				$entry['event_id'] = $id;
				$found             = $entry;
			}
		}
		return $found;
	}
}

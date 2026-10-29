<?php
/**
 * Data layer for the plugin's own tables.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events, findings, checks and stored diffs.
 */
class Events {

	/**
	 * Table name for a short name.
	 *
	 * @param string $name events, findings, checks or diffs.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->base_prefix . 'updlock_' . $name;
	}

	/**
	 * Creates an event row.
	 *
	 * @param array<string,mixed> $row Column values.
	 * @return int Event id (0 on failure).
	 */
	public static function create( array $row ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'kind'       => (string) ( $row['kind'] ?? 'plugin' ),
			'slug'       => (string) ( $row['slug'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'from_ver'   => (string) ( $row['from_ver'] ?? '' ),
			'to_ver'     => (string) ( $row['to_ver'] ?? '' ),
			'trigger_by' => (string) ( $row['trigger_by'] ?? 'manual' ),
			'risk'       => (string) ( $row['risk'] ?? '' ),
			'state'      => (string) ( $row['state'] ?? 'new' ),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'created_at' => $now,
			'updated_at' => $now,
			'summary'    => wp_json_encode( $row['summary'] ?? array() ),
		);
		$ok   = $wpdb->insert( self::table( 'events' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table owned by this plugin.
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates columns of an event; `summary` values are merged into the stored JSON.
	 *
	 * @param int                 $id     Event id.
	 * @param array<string,mixed> $fields Columns to change.
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$data = array( 'updated_at' => current_time( 'mysql', true ) );
		foreach ( array( 'risk', 'state', 'to_ver', 'from_ver', 'name' ) as $col ) {
			if ( isset( $fields[ $col ] ) ) {
				$data[ $col ] = (string) $fields[ $col ];
			}
		}
		if ( isset( $fields['summary'] ) && is_array( $fields['summary'] ) ) {
			$current         = self::get( $id );
			$merged          = array_merge( $current ? $current['summary'] : array(), $fields['summary'] );
			$data['summary'] = wp_json_encode( $merged );
		}
		$wpdb->update( self::table( 'events' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
	}

	/**
	 * One event with its summary decoded.
	 *
	 * @param int $id Event id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'events' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Events, newest first, with optional filters.
	 *
	 * @param string $kind   plugin, theme or '' for both.
	 * @param string $risk   low, review, high or ''.
	 * @param string $state  A state or ''.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( string $kind = '', string $risk = '', string $state = '', int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR kind = %s ) AND ( %s = '' OR risk = %s ) AND ( %s = '' OR state = %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table( 'events' ),
				$kind,
				$kind,
				$risk,
				$risk,
				$state,
				$state,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of events matching the filters.
	 *
	 * @param string $kind  plugin, theme or ''.
	 * @param string $risk  low, review, high or ''.
	 * @param string $state A state or ''.
	 * @return int
	 */
	public static function count( string $kind = '', string $risk = '', string $state = '' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR kind = %s ) AND ( %s = '' OR risk = %s ) AND ( %s = '' OR state = %s )",
				self::table( 'events' ),
				$kind,
				$kind,
				$risk,
				$risk,
				$state,
				$state
			)
		);
	}

	/**
	 * Newest event for a slug and target version, optionally with a state.
	 *
	 * @param string $kind   plugin or theme.
	 * @param string $slug   Folder slug.
	 * @param string $to_ver Target version.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $kind, string $slug, string $to_ver ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
			$wpdb->prepare( 'SELECT * FROM %i WHERE kind = %s AND slug = %s AND to_ver = %s ORDER BY id DESC LIMIT 1', self::table( 'events' ), $kind, $slug, $to_ver ),
			ARRAY_A
		);
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Decodes the JSON summary of a row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private static function decode( array $row ): array {
		$summary        = json_decode( (string) $row['summary'], true );
		$row['summary'] = is_array( $summary ) ? $summary : array();
		return $row;
	}

	/**
	 * Replaces the findings of an event.
	 *
	 * @param int                            $event_id Event id.
	 * @param array<int,array<string,mixed>> $findings Findings.
	 */
	public static function save_findings( int $event_id, array $findings ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'findings' ), array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		foreach ( $findings as $finding ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table owned by this plugin.
				self::table( 'findings' ),
				array(
					'event_id' => $event_id,
					'rule'     => (string) $finding['rule'],
					'severity' => (string) $finding['severity'],
					'file'     => substr( (string) $finding['file'], 0, 250 ),
					'line'     => (int) $finding['line'],
					'excerpt'  => (string) $finding['excerpt'],
					'detail'   => substr( (string) ( $finding['detail'] ?? '' ), 0, 250 ),
				)
			);
		}
	}

	/**
	 * Findings of an event.
	 *
	 * @param int $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function findings( int $event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
			$wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d ORDER BY FIELD( severity, 'high', 'medium', 'low' ), id", self::table( 'findings' ), $event_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stores the diff hunks of an event.
	 *
	 * @param int                            $event_id Event id.
	 * @param array<string,array<int,mixed>> $diffs    Path => hunks.
	 */
	public static function save_diffs( int $event_id, array $diffs ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'diffs' ), array( 'event_id' => $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		foreach ( $diffs as $path => $hunks ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table owned by this plugin.
				self::table( 'diffs' ),
				array(
					'event_id' => $event_id,
					'path'     => substr( (string) $path, 0, 250 ),
					'hunks'    => wp_json_encode( $hunks ),
				)
			);
		}
	}

	/**
	 * Paths that have a stored diff.
	 *
	 * @param int $event_id Event id.
	 * @return string[]
	 */
	public static function diff_paths( int $event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i WHERE event_id = %d ORDER BY path', self::table( 'diffs' ), $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hunks of one stored diff.
	 *
	 * @param int    $event_id Event id.
	 * @param string $path     File path.
	 * @return array<int,mixed>
	 */
	public static function diff( int $event_id, string $path ): array {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT hunks FROM %i WHERE event_id = %d AND path = %s', self::table( 'diffs' ), $event_id, $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Adds a check result.
	 *
	 * @param int                 $event_id Event id.
	 * @param string              $url      Checked URL.
	 * @param string              $phase    before, after or retry.
	 * @param string              $verdict  pass, warn, fail or inconclusive.
	 * @param array<string,mixed> $metrics  Details.
	 */
	public static function add_check( int $event_id, string $url, string $phase, string $verdict, array $metrics ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table owned by this plugin.
			self::table( 'checks' ),
			array(
				'event_id' => $event_id,
				'url'      => substr( $url, 0, 500 ),
				'phase'    => $phase,
				'status'   => (int) ( $metrics['status'] ?? 0 ),
				'verdict'  => $verdict,
				'metrics'  => wp_json_encode( $metrics ),
			)
		);
	}

	/**
	 * Check results of an event.
	 *
	 * @param int $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function checks( int $event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY id', self::table( 'checks' ), $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$metrics        = json_decode( (string) $row['metrics'], true );
			$row['metrics'] = is_array( $metrics ) ? $metrics : array();
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * Deletes events (and their findings, checks and diffs) older than a number of days.
	 *
	 * @param int $days Age in days.
	 */
	public static function prune( int $days ): void {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE created_at < %s', self::table( 'events' ), $cut ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			foreach ( array( 'findings', 'checks', 'diffs' ) as $child ) {
				$wpdb->delete( self::table( $child ), array( 'event_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
			}
			$wpdb->delete( self::table( 'events' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table owned by this plugin.
		}
	}
}

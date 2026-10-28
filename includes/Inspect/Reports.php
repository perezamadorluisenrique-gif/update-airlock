<?php
/**
 * Stores an inspection report in the events tables.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect;

use Updlock\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report persistence.
 */
class Reports {

	/**
	 * Saves a report on an event.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $report   Report from Engine::finish().
	 */
	public static function persist( int $event_id, array $report ): void {
		$files = $report['files'];
		foreach ( array( 'added', 'removed', 'changed' ) as $key ) {
			$files[ $key . '_total' ] = count( $files[ $key ] );
			$files[ $key ]            = array_slice( $files[ $key ], 0, 500 );
		}
		$files['renamed_total'] = count( $files['renamed'] );
		$files['renamed']       = array_slice( $files['renamed'], 0, 200, true );
		$per_file               = array_slice( $report['per_file'], 0, 500, true );
		$event                  = array(
			'risk'    => (string) $report['level'],
			'summary' => array(
				'files'    => $files,
				'per_file' => $per_file,
				'stats'    => $report['stats'],
				'skipped'  => array_slice( $report['skipped'], 0, 200 ),
				'hosts'    => $report['hosts'],
				'headers'  => $report['headers'],
				'level'    => $report['level'],
			),
		);
		if ( ! empty( $report['headers']['new']['version'] ) ) {
			$event['to_ver'] = (string) $report['headers']['new']['version'];
		}
		Events::update( $event_id, $event );
		Events::save_findings( $event_id, $report['findings'] );
		Events::save_diffs( $event_id, $report['diffs'] );
	}
}

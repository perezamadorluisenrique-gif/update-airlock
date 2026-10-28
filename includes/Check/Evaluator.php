<?php
/**
 * Decides what a set of after-update measurements means (pure logic).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares measurements with a baseline. Failed loopbacks are inconclusive, never a pass.
 */
class Evaluator {

	/**
	 * Judges every page and the error log.
	 *
	 * @param array<string,array<string,mixed>>    $before  Baseline metrics by URL.
	 * @param array<string,array<string,mixed>>    $after   Metrics after the update by URL.
	 * @param array{fatal:string[],other:string[]} $log  New log lines attributed to the plugin or theme.
	 * @param array<string,string[]>               $missing Missing assets by URL.
	 * @param string                               $home    Home page URL (structure collapse only blocks there).
	 * @return array{verdict:string,rows:array<string,array<string,mixed>>,log:array<string,mixed>}
	 */
	public static function judge( array $before, array $after, array $log, array $missing, string $home ): array {
		$rows = array();
		foreach ( $after as $url => $now ) {
			$was     = $before[ $url ] ?? null;
			$reasons = array();
			$verdict = 'pass';
			if ( ! empty( $now['error'] ) || ! empty( $now['maint'] ) ) {
				$rows[ $url ] = array(
					'verdict' => 'inconclusive',
					'reasons' => array( ! empty( $now['maint'] ) ? __( 'The site was in maintenance mode.', 'update-airlock' ) : __( 'The request to this page did not complete.', 'update-airlock' ) ),
					'metrics' => $now,
				);
				continue;
			}
			$status     = (int) $now['status'];
			$was_status = $was ? (int) ( $was['status'] ?? 0 ) : 0;
			if ( $status >= 500 ) {
				if ( $was && $was_status >= 500 ) {
					$verdict   = 'warn';
					$reasons[] = __( 'The page was already failing before the update.', 'update-airlock' );
				} else {
					$verdict = 'fail';
					/* translators: %d: HTTP status code */
					$reasons[] = sprintf( __( 'The page now answers with HTTP %d.', 'update-airlock' ), $status );
				}
			}
			if ( ! empty( $now['fatal'] ) ) {
				if ( $was && ! empty( $was['fatal'] ) ) {
					$verdict   = 'fail' === $verdict ? 'fail' : 'warn';
					$reasons[] = __( 'A PHP error shows on the page, and it did before the update.', 'update-airlock' );
				} else {
					$verdict   = 'fail';
					$reasons[] = __( 'A PHP fatal error shows on the page.', 'update-airlock' );
				}
			}
			if ( $url === $home && $was && ! empty( $was['fp'] ) && ! empty( $now['fp'] ) && Fingerprint::collapsed( $was['fp'], $now['fp'] ) ) {
				$verdict   = 'fail';
				$reasons[] = __( 'The home page lost most of its structure.', 'update-airlock' );
			}
			if ( $was && $was_status >= 200 && $was_status < 400 && in_array( $status, array( 403, 404, 410 ), true ) && 'fail' !== $verdict ) {
				$verdict = 'warn';
				/* translators: 1: earlier HTTP status, 2: current HTTP status */
				$reasons[] = sprintf( __( 'The status changed from %1$d to %2$d.', 'update-airlock' ), $was_status, $status );
			}
			if ( $was && isset( $was['ms'] ) && (int) $now['ms'] > 1500 && (int) $now['ms'] > max( (int) $was['ms'] * 3, (int) $was['ms'] + 2000 ) && 'fail' !== $verdict ) {
				$verdict = 'warn';
				/* translators: 1: earlier time in milliseconds, 2: current time in milliseconds */
				$reasons[] = sprintf( __( 'The page is slower: %1$d ms before, %2$d ms now.', 'update-airlock' ), (int) $was['ms'], (int) $now['ms'] );
			}
			if ( ! empty( $missing[ $url ] ) && 'fail' !== $verdict ) {
				$verdict = 'warn';
				/* translators: %d: number of files */
				$reasons[] = sprintf( _n( '%d script or stylesheet from the updated code does not load.', '%d scripts or stylesheets from the updated code do not load.', count( $missing[ $url ] ), 'update-airlock' ), count( $missing[ $url ] ) );
			}
			$rows[ $url ] = array(
				'verdict' => $verdict,
				'reasons' => $reasons,
				'metrics' => $now,
			);
		}

		$verdicts = array_column( $rows, 'verdict' );
		if ( ! empty( $log['fatal'] ) ) {
			$overall = 'fail';
		} elseif ( in_array( 'fail', $verdicts, true ) ) {
			$overall = 'fail';
		} elseif ( ! $verdicts || ( 1 === count( array_unique( $verdicts ) ) && 'inconclusive' === $verdicts[0] ) ) {
			$overall = 'inconclusive';
		} elseif ( in_array( 'warn', $verdicts, true ) || in_array( 'inconclusive', $verdicts, true ) || ! empty( $log['other'] ) ) {
			$overall = 'warn';
		} else {
			$overall = 'pass';
		}
		return array(
			'verdict' => $overall,
			'rows'    => $rows,
			'log'     => $log,
		);
	}
}

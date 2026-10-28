<?php
/**
 * Checks the site after an update and rolls back when a check fails.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Upgrade;

use Updlock\Check\Error_Log;
use Updlock\Check\Evaluator;
use Updlock\Check\Runner;
use Updlock\Core\Events;
use Updlock\Core\Settings;
use Updlock\Notify\Mailer;
use Updlock\Snapshot\Restorer;
use Updlock\Snapshot\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification, commit, rollback and holds.
 */
class Verifier {

	const LOCK = 'updlock_verify';

	/**
	 * Runs one pending verification (one heavy job per request).
	 */
	public static function run_pending(): void {
		foreach ( Journal::all() as $id => $entry ) {
			$state = $entry['state'] ?? '';
			if ( 'verifying' === $state ) {
				if ( self::run( (int) $id ) ) {
					return;
				}
			} elseif ( 'rolling_back' === $state && time() - (int) ( $entry['updated'] ?? 0 ) > 120 ) {
				Restorer::recover();
				self::rollback( (int) $id, array() );
				return;
			}
		}
	}

	/**
	 * Verifies one update.
	 *
	 * @param int $event_id Event id.
	 * @return bool Whether work was done.
	 */
	public static function run( int $event_id ): bool {
		$entry = Journal::get( $event_id );
		if ( ! $entry || 'verifying' !== $entry['state'] ) {
			return false;
		}
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			self::schedule( 120 );
			return false;
		}
		$age = time() - (int) $entry['created'];
		if ( ! empty( $entry['await_activation'] ) && 'plugin' === $entry['kind'] && ! is_plugin_active( (string) $entry['plugin'] ) && ! is_plugin_active_for_network( (string) $entry['plugin'] ) ) {
			if ( $age < 300 ) {
				self::schedule( 60 );
				return false;
			}
			self::finish( $event_id, $entry, 'committed', array( 'note' => 'not_reactivated' ) );
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! \WP_Upgrader::create_lock( self::LOCK, 600 ) ) {
			return false;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- Checks make several page requests and must not be cut short.
		}
		$judged = self::measure( $entry );
		self::save_checks( $event_id, $judged, 'after' );
		\WP_Upgrader::release_lock( self::LOCK );

		$verdict = $judged['verdict'];
		if ( 'fail' === $verdict ) {
			if ( Settings::get( 'auto_rollback' ) && ! empty( $entry['snapshot'] ) ) {
				self::rollback( $event_id, $judged );
			} else {
				self::finish( $event_id, $entry, 'failed_checks', array( 'verdict' => $verdict ) );
				Mailer::alert( __( 'Checks failed after an update', 'update-airlock' ), self::event( $event_id ), self::lines( $judged ) );
			}
		} elseif ( 'inconclusive' === $verdict ) {
			self::finish( $event_id, $entry, 'unverified', array( 'verdict' => $verdict ) );
			Mailer::alert( __( 'Could not check the site after an update', 'update-airlock' ), self::event( $event_id ), self::lines( $judged ) );
		} else {
			self::refresh_baseline( $judged );
			self::finish( $event_id, $entry, 'committed', array( 'verdict' => $verdict ) );
		}
		return true;
	}

	/**
	 * Runs the check suite for an entry.
	 *
	 * @param array<string,mixed> $entry Journal entry.
	 * @return array<string,mixed> Evaluator result.
	 */
	private static function measure( array $entry ): array {
		$baseline = Runner::baseline();
		$urls     = array_keys( Runner::urls() );
		$admin    = Runner::admin_url();
		if ( '' !== $admin ) {
			$urls[] = $admin;
		}
		$prefix  = 'plugin' === $entry['kind'] ? content_url( 'plugins/' . $entry['slug'] . '/' ) : trailingslashit( get_theme_root_uri() ) . $entry['slug'] . '/';
		$after   = Runner::fetch( $urls, $prefix );
		$missing = array();
		foreach ( $after as $url => $metrics ) {
			if ( ! empty( $metrics['assets'] ) ) {
				$bad = Runner::missing_assets( $metrics['assets'] );
				if ( $bad ) {
					$missing[ $url ] = $bad;
				}
			}
		}
		$needle = ( 'plugin' === $entry['kind'] ? '/plugins/' : '/themes/' ) . $entry['slug'];
		$log    = Error_Log::since( (int) ( $entry['log_offset'] ?? -1 ), $needle );
		return Evaluator::judge( $baseline['urls'], $after, $log, $missing, home_url( '/' ) );
	}

	/**
	 * Stores the per-page rows.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $judged   Evaluator result.
	 * @param string              $phase    Phase label.
	 */
	private static function save_checks( int $event_id, array $judged, string $phase ): void {
		foreach ( $judged['rows'] as $url => $row ) {
			$metrics            = $row['metrics'];
			$metrics['reasons'] = $row['reasons'];
			unset( $metrics['fp'], $metrics['assets'] );
			Events::add_check( $event_id, (string) $url, $phase, (string) $row['verdict'], $metrics );
		}
		Events::update(
			$event_id,
			array(
				'summary' => array(
					'check_verdict' => $judged['verdict'],
					'log'           => $judged['log'],
				),
			)
		);
	}

	/**
	 * Evidence lines for an email.
	 *
	 * @param array<string,mixed> $judged Evaluator result.
	 * @return string[]
	 */
	private static function lines( array $judged ): array {
		$lines = array();
		foreach ( $judged['rows'] as $url => $row ) {
			if ( 'pass' !== $row['verdict'] ) {
				$lines[] = strtoupper( (string) $row['verdict'] ) . ' ' . $url . ( $row['reasons'] ? ' - ' . implode( ' ', $row['reasons'] ) : '' );
			}
		}
		foreach ( $judged['log']['fatal'] as $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Updates the baseline with pages that passed.
	 *
	 * @param array<string,mixed> $judged Evaluator result.
	 */
	private static function refresh_baseline( array $judged ): void {
		$base = Runner::baseline();
		foreach ( $judged['rows'] as $url => $row ) {
			if ( in_array( $row['verdict'], array( 'pass', 'warn' ), true ) ) {
				$base['urls'][ $url ] = $row['metrics'];
			}
		}
		$base['ts'] = time();
		update_option( Runner::BASELINE, $base, false );
	}

	/**
	 * Restores the snapshot, confirms the result, and holds the failed version.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $judged   Evaluator result that triggered it.
	 */
	public static function rollback( int $event_id, array $judged ): void {
		$entry = Journal::get( $event_id );
		if ( ! $entry ) {
			return;
		}
		Journal::set( $event_id, array( 'state' => 'rolling_back' ) );
		Events::update( $event_id, array( 'state' => 'rolling_back' ) );
		$result = Restorer::restore( (string) $entry['snapshot'], false );
		if ( is_wp_error( $result ) ) {
			self::finish( $event_id, $entry, 'failed', array( 'error' => $result->get_error_message() ) );
			Mailer::alert(
				__( 'An update failed its checks and the rollback also failed', 'update-airlock' ),
				self::event( $event_id ),
				array_merge( self::lines( $judged ), array( $result->get_error_message() ) )
			);
			return;
		}
		$confirm = self::measure( $entry );
		self::save_checks( $event_id, $confirm, 'retry' );
		Holds::add( (string) $entry['kind'], (string) $entry['slug'], (string) $entry['to'], $event_id, __( 'Checks failed after the update.', 'update-airlock' ) );
		self::finish( $event_id, $entry, 'rolled_back', array( 'confirm_verdict' => $confirm['verdict'] ) );
		Mailer::alert(
			__( 'Rolled back an update that failed its checks', 'update-airlock' ),
			self::event( $event_id ),
			array_merge( self::lines( $judged ), array( __( 'After the rollback the checks give:', 'update-airlock' ) . ' ' . $confirm['verdict'] ) )
		);
	}

	/**
	 * Records the end state of an update.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $entry    Journal entry.
	 * @param string              $state    End state.
	 * @param array<string,mixed> $summary  Extra summary values.
	 */
	private static function finish( int $event_id, array $entry, string $state, array $summary ): void {
		Journal::set( $event_id, array( 'state' => $state ) );
		Events::update(
			$event_id,
			array(
				'state'   => $state,
				'summary' => $summary,
			)
		);
		if ( 'committed' === $state ) {
			$hold = Holds::get( (string) $entry['kind'], (string) $entry['slug'] );
			if ( $hold && (string) $hold['version'] === (string) $entry['to'] ) {
				Holds::release( (string) $entry['kind'], (string) $entry['slug'] );
			}
		}
	}

	/**
	 * Loads an event row.
	 *
	 * @param int $event_id Event id.
	 * @return array<string,mixed>
	 */
	private static function event( int $event_id ): array {
		$event = Events::get( $event_id );
		return $event ? $event : array(
			'id'     => $event_id,
			'name'   => '',
			'to_ver' => '',
		);
	}

	/**
	 * Schedules another look soon.
	 *
	 * @param int $seconds Delay.
	 */
	public static function schedule( int $seconds ): void {
		if ( ! wp_next_scheduled( 'updlock_resume' ) ) {
			wp_schedule_single_event( time() + $seconds, 'updlock_resume' );
		}
	}
}

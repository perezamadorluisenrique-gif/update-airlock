<?php
/**
 * Site Health tests and the welcome environment check.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Check\Error_Log;
use Updlock\Core\Fs;
use Updlock\Snapshot\Store;
use Updlock\Upgrade\Holds;
use Updlock\Upgrade\Journal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment facts and Site Health tests.
 */
class Site_Health {

	/**
	 * Registers the tests.
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
	}

	/**
	 * Adds the tests.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public static function tests( array $tests ): array {
		$tests['direct']['updlock_environment'] = array(
			'label' => __( 'Update Airlock can make snapshots', 'update-airlock' ),
			'test'  => array( __CLASS__, 'test_environment' ),
		);
		$tests['direct']['updlock_state']       = array(
			'label' => __( 'Update Airlock held and unchecked updates', 'update-airlock' ),
			'test'  => array( __CLASS__, 'test_state' ),
		);
		return $tests;
	}

	/**
	 * Facts about the server that matter for snapshots and checks.
	 *
	 * @return array<int,array{label:string,ok:bool,text:string}>
	 */
	public static function environment(): array {
		$rows   = array();
		$fs     = Fs::get();
		$rows[] = array(
			'label' => __( 'File access', 'update-airlock' ),
			'ok'    => (bool) $fs,
			'text'  => $fs ? __( 'WordPress can write files here.', 'update-airlock' ) : __( 'WordPress needs file access credentials; snapshots cannot be made until they are set.', 'update-airlock' ),
		);
		$rows[] = array(
			'label' => __( 'Zip support', 'update-airlock' ),
			'ok'    => true,
			'text'  => class_exists( 'ZipArchive' ) ? __( 'ZipArchive is available.', 'update-airlock' ) : __( 'ZipArchive is missing, so the zip library bundled with WordPress is used.', 'update-airlock' ),
		);
		$free   = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host; a missing value is treated as unknown.
		$rows[] = array(
			'label' => __( 'Disk space', 'update-airlock' ),
			'ok'    => false === $free || $free > 100 * MB_IN_BYTES,
			/* translators: %s: free space such as 2 GB */
			'text'  => false === $free ? __( 'Free space cannot be read on this host.', 'update-airlock' ) : sprintf( __( '%s free.', 'update-airlock' ), size_format( (float) $free ) ),
		);
		$log    = Error_Log::path();
		$rows[] = array(
			'label' => __( 'Error log', 'update-airlock' ),
			'ok'    => '' !== $log,
			'text'  => '' !== $log ? __( 'A readable error log was found, so new PHP errors from an updated plugin are noticed.', 'update-airlock' ) : __( 'No readable error log. Page checks still run; log-based checks are skipped.', 'update-airlock' ),
		);
		$probe  = wp_remote_get(
			add_query_arg( 'updlock_check', '1', home_url( '/' ) ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the same way core does for loopback requests.
			)
		);
		$ok     = ! is_wp_error( $probe ) && (int) wp_remote_retrieve_response_code( $probe ) > 0;
		$rows[] = array(
			'label' => __( 'Requests to this site', 'update-airlock' ),
			'ok'    => $ok,
			'text'  => $ok ? __( 'The site can request its own pages.', 'update-airlock' ) : __( 'The site could not request its own pages. Checks after updates will be marked inconclusive, never passed.', 'update-airlock' ),
		);
		return $rows;
	}

	/**
	 * Site Health: can snapshots be made.
	 *
	 * @return array<string,mixed>
	 */
	public static function test_environment(): array {
		$bad = array();
		foreach ( self::environment() as $row ) {
			if ( ! $row['ok'] ) {
				$bad[] = $row['label'] . ': ' . $row['text'];
			}
		}
		$size   = Store::total_size();
		$result = array(
			'label'       => __( 'Update Airlock can make snapshots and check pages', 'update-airlock' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Updates', 'update-airlock' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Snapshots, page checks and the error log are ready.', 'update-airlock' ) . '</p>',
			'actions'     => '<a href="' . esc_url( Labels::url() ) . '">' . esc_html__( 'Open Update Airlock', 'update-airlock' ) . '</a>',
			'test'        => 'updlock_environment',
		);
		if ( $bad ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Update Airlock has limits on this host', 'update-airlock' );
			$result['description'] = '<p>' . esc_html( implode( ' ', $bad ) ) . '</p>';
		} elseif ( $size > 500 * MB_IN_BYTES ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Update Airlock snapshots are using a lot of disk space', 'update-airlock' );
			$result['description'] = '<p>' . esc_html__( 'Lower the number of snapshots kept in Settings.', 'update-airlock' ) . '</p>';
		}
		return $result;
	}

	/**
	 * Site Health: held updates and updates that could not be checked.
	 *
	 * @return array<string,mixed>
	 */
	public static function test_state(): array {
		$holds   = Holds::all();
		$pending = array_filter(
			Journal::all(),
			static function ( $entry ) {
				return in_array( $entry['state'] ?? '', array( 'unverified', 'failed', 'failed_checks' ), true );
			}
		);
		$result  = array(
			'label'       => __( 'No updates are held or unchecked', 'update-airlock' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Updates', 'update-airlock' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Nothing waits for your review.', 'update-airlock' ) . '</p>',
			'actions'     => '<a href="' . esc_url( Labels::url() ) . '">' . esc_html__( 'Open Update Airlock', 'update-airlock' ) . '</a>',
			'test'        => 'updlock_state',
		);
		if ( $holds || $pending ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Update Airlock has updates that need a look', 'update-airlock' );
			$result['description'] = '<p>' . esc_html__( 'Some updates are held back for review, or were kept without a conclusive check.', 'update-airlock' ) . '</p>';
		}
		return $result;
	}
}

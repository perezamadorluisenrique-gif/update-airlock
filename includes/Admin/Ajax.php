<?php
/**
 * AJAX handlers: inspection in small steps and the baseline capture.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Check\Runner;
use Updlock\Core\Events;
use Updlock\Core\Fs;
use Updlock\Inspect\Engine;
use Updlock\Inspect\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each handler verifies the nonce and the capability before reading any field.
 */
class Ajax {

	/**
	 * Registers the handlers.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_updlock_inspect', array( __CLASS__, 'inspect' ) );
		add_action( 'wp_ajax_updlock_baseline', array( __CLASS__, 'baseline' ) );
		add_action( 'wp_ajax_updlock_recheck', array( Page::class, 'recheck' ) );
	}

	/**
	 * Starts or continues an inspection.
	 */
	public static function inspect(): void {
		check_ajax_referer( 'updlock_inspect' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the line above.
		$kind  = isset( $_POST['kind'] ) && 'theme' === $_POST['kind'] ? 'theme' : 'plugin';
		$slug  = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( 'theme' === $kind ? 'update_themes' : 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'update-airlock' ) ), 403 );
		}
		if ( '' === $token ) {
			if ( ! Fs::valid_slug( $slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid request.', 'update-airlock' ) ) );
			}
			self::start( $kind, $slug );
		}
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'update-airlock' ) ) );
		}
		self::advance( $token );
	}

	/**
	 * Downloads and unpacks the package, then stores the job.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 */
	private static function start( string $kind, string $slug ): void {
		$offer = null;
		foreach ( Views::pending_updates() as $item ) {
			if ( $item['kind'] === $kind && $item['slug'] === $slug ) {
				$offer = $item;
			}
		}
		$old = Fs::root( $kind ) . '/' . $slug;
		if ( ! $offer || '' === $offer['package'] ) {
			wp_send_json_error( array( 'message' => __( 'WordPress has no download address for this update (a premium plugin, for example). It is inspected when you install it.', 'update-airlock' ) ) );
		}
		if ( ! is_dir( $old ) ) {
			wp_send_json_error( array( 'message' => __( 'Only plugins and themes that live in their own folder can be inspected before the update.', 'update-airlock' ) ) );
		}
		$fs = Fs::get();
		if ( ! $fs ) {
			wp_send_json_error( array( 'message' => __( 'WordPress needs file access credentials for this.', 'update-airlock' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$zip = download_url( $offer['package'], 300 );
		if ( is_wp_error( $zip ) ) {
			wp_send_json_error( array( 'message' => $zip->get_error_message() ) );
		}
		$token = bin2hex( random_bytes( 8 ) );
		$work  = WP_CONTENT_DIR . '/upgrade/updlock-' . $token;
		$fs->mkdir( Fs::remote( WP_CONTENT_DIR . '/upgrade' ), FS_CHMOD_DIR );
		$fs->mkdir( Fs::remote( $work ), FS_CHMOD_DIR );
		$res = unzip_file( $zip, Fs::remote( $work ) );
		wp_delete_file( $zip );
		if ( is_wp_error( $res ) ) {
			$fs->delete( Fs::remote( $work ), true );
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$root = $work;
		$list = array_values( array_diff( (array) scandir( $work ), array( '.', '..' ) ) );
		if ( 1 === count( $list ) && is_dir( $work . '/' . $list[0] ) ) {
			$root = $work . '/' . $list[0];
		}
		$event_id = Events::create(
			array(
				'kind'       => $kind,
				'slug'       => $slug,
				'name'       => $offer['name'],
				'from_ver'   => $offer['from'],
				'to_ver'     => $offer['to'],
				'trigger_by' => 'inspect',
				'state'      => 'inspecting',
				'user_id'    => get_current_user_id(),
			)
		);
		$state    = Engine::begin(
			$old,
			$root,
			array(
				'kind' => $kind,
				'slug' => $slug,
			)
		);
		set_transient(
			'updlock_job_' . $token,
			array(
				'user'  => get_current_user_id(),
				'event' => $event_id,
				'work'  => $work,
				'state' => $state,
			),
			HOUR_IN_SECONDS
		);
		wp_send_json_success(
			array(
				'token'    => $token,
				'progress' => 5,
				'done'     => false,
			)
		);
	}

	/**
	 * Runs a few seconds of work.
	 *
	 * @param string $token Job token.
	 */
	private static function advance( string $token ): void {
		$job = get_transient( 'updlock_job_' . $token );
		if ( ! is_array( $job ) || get_current_user_id() !== (int) $job['user'] ) {
			wp_send_json_error( array( 'message' => __( 'This inspection expired. Start it again.', 'update-airlock' ) ) );
		}
		$state = $job['state'];
		$done  = Engine::step( $state, microtime( true ) + 4 );
		if ( ! $done ) {
			$job['state'] = $state;
			set_transient( 'updlock_job_' . $token, $job, HOUR_IN_SECONDS );
			$count = max( 1, 'index' === $state['phase'] ? count( $state['paths'] ) : count( $state['todo'] ) );
			$part  = min( 1, $state['pos'] / $count );
			wp_send_json_success(
				array(
					'token'    => $token,
					'progress' => (int) round( 'index' === $state['phase'] ? 5 + $part * 45 : 50 + $part * 50 ),
					'done'     => false,
				)
			);
		}
		$report = Engine::finish( $state );
		Reports::persist( (int) $job['event'], $report );
		Events::update( (int) $job['event'], array( 'state' => 'inspected' ) );
		$fs = Fs::get();
		if ( $fs && 0 === strpos( (string) $job['work'], WP_CONTENT_DIR . '/upgrade/updlock-' ) ) {
			$fs->delete( Fs::remote( (string) $job['work'] ), true );
		}
		delete_transient( 'updlock_job_' . $token );
		wp_send_json_success(
			array(
				'done'     => true,
				'progress' => 100,
				'level'    => $report['level'],
				'label'    => Labels::risk( $report['level'] ),
				'redirect' => Labels::url(
					array(
						'view' => 'event',
						'id'   => (int) $job['event'],
					)
				),
			)
		);
	}

	/**
	 * Fetches one page of the baseline.
	 */
	public static function baseline(): void {
		check_ajax_referer( 'updlock_baseline' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$urls = array_keys( Runner::urls() );
		if ( ! in_array( $url, $urls, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown page.', 'update-airlock' ) ) );
		}
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			wp_send_json_error( array( 'message' => __( 'The site is in maintenance mode.', 'update-airlock' ) ) );
		}
		$res                  = Runner::fetch( array( $url ) );
		$base                 = Runner::baseline();
		$base['urls'][ $url ] = $res[ $url ];
		$base['ts']           = time();
		update_option( Runner::BASELINE, $base, false );
		$status = (int) ( $res[ $url ]['status'] ?? 0 );
		wp_send_json_success(
			array(
				'status' => $status,
				'ms'     => (int) ( $res[ $url ]['ms'] ?? 0 ),
				'ok'     => $status >= 200 && $status < 400,
				'error'  => (string) ( $res[ $url ]['error'] ?? '' ),
			)
		);
	}
}

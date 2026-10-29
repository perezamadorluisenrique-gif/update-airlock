<?php
/**
 * Form actions (admin-post): every handler checks the nonce and the capability first.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Core\Events;
use Updlock\Core\Fs;
use Updlock\Snapshot\Restorer;
use Updlock\Snapshot\Store;
use Updlock\Upgrade\Holds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirm, release, roll back, delete and export.
 */
class Actions {

	/**
	 * Registers the handlers.
	 */
	public static function register(): void {
		foreach ( array( 'confirm', 'release', 'keep', 'rollback', 'delete_snapshot', 'export' ) as $action ) {
			add_action( 'admin_post_updlock_' . $action, array( __CLASS__, $action ) );
		}
	}

	/**
	 * Nonce and capability check shared by every handler.
	 *
	 * @param string $action Action name.
	 * @param string $kind   plugin or theme ('' when it depends on the request).
	 */
	private static function guard( string $action, string $kind = '' ): void {
		check_admin_referer( 'updlock_' . $action );
		$cap = Labels::cap( $kind );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'update-airlock' ), 403 );
		}
	}

	/**
	 * Reads a plugin or theme identity from the request (after guard()).
	 *
	 * @return array{0:string,1:string}
	 */
	private static function item(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$kind = isset( $_POST['kind'] ) && 'theme' === $_POST['kind'] ? 'theme' : 'plugin';
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! Fs::valid_slug( $slug ) ) {
			wp_die( esc_html__( 'Invalid request.', 'update-airlock' ), 400 );
		}
		return array( $kind, $slug );
	}

	/**
	 * Sends the user back with a notice.
	 *
	 * @param string $text Message.
	 * @param string $type success or error.
	 * @param string $to   URL to return to.
	 */
	private static function back( string $text, string $type = 'success', string $to = '' ): void {
		Views::flash( $text, $type );
		wp_safe_redirect( '' !== $to ? $to : Labels::url() );
		exit;
	}

	/**
	 * Marks a reviewed High version as allowed.
	 */
	public static function confirm(): void {
		check_admin_referer( 'updlock_confirm' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the line above.
		$kind    = isset( $_POST['kind'] ) && 'theme' === $_POST['kind'] ? 'theme' : 'plugin';
		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$event   = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( Labels::cap( $kind ) ) || ! Fs::valid_slug( $slug ) || '' === $version ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'update-airlock' ), 403 );
		}
		Holds::confirm( $kind, $slug, $version );
		if ( $event ) {
			Events::update( $event, array( 'summary' => array( 'confirmed_by' => get_current_user_id() ) ) );
		}
		self::back( __( 'Allowed. Run the update again from the Updates screen.', 'update-airlock' ), 'success', admin_url( 'update-core.php' ) );
	}

	/**
	 * Releases a hold.
	 */
	public static function release(): void {
		check_admin_referer( 'updlock_release' );
		list( $kind, $slug ) = self::item();
		if ( ! current_user_can( Labels::cap( $kind ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'update-airlock' ), 403 );
		}
		Holds::release( $kind, $slug );
		self::back( __( 'Released. Automatic updates may install this version.', 'update-airlock' ) );
	}

	/**
	 * Keeps a hold (no change; confirms the choice).
	 */
	public static function keep(): void {
		self::guard( 'keep' );
		self::back( __( 'Still held back.', 'update-airlock' ) );
	}

	/**
	 * Restores a snapshot.
	 */
	public static function rollback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verifies the nonce before anything else runs.
		$id    = isset( $_POST['snapshot'] ) ? sanitize_key( wp_unslash( $_POST['snapshot'] ) ) : '';
		$entry = Store::get( $id );
		self::guard( 'rollback', $entry ? (string) $entry['kind'] : '' );
		$with_options = ! empty( $_POST['options'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $entry ) {
			self::back( __( 'That snapshot is not available.', 'update-airlock' ), 'error' );
		}
		$result = Restorer::restore( $id, $with_options );
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		$offered = '';
		if ( 'plugin' === $entry['kind'] ) {
			$upd = get_site_transient( 'update_plugins' );
			if ( is_object( $upd ) && '' !== (string) $entry['plugin'] && isset( $upd->response[ $entry['plugin'] ]->new_version ) ) {
				$offered = (string) $upd->response[ $entry['plugin'] ]->new_version;
			}
		} else {
			$upd = get_site_transient( 'update_themes' );
			if ( is_object( $upd ) && isset( $upd->response[ $entry['slug'] ]['new_version'] ) ) {
				$offered = (string) $upd->response[ $entry['slug'] ]['new_version'];
			}
		}
		$event_id = Events::create(
			array(
				'kind'       => $entry['kind'],
				'slug'       => $entry['slug'],
				'name'       => $entry['name'],
				'from_ver'   => $offered,
				'to_ver'     => $entry['version'],
				'trigger_by' => 'manual',
				'state'      => 'rolled_back',
				'user_id'    => get_current_user_id(),
				'summary'    => array(
					'snapshot' => $id,
					'manual'   => true,
					'notes'    => $result['notes'],
				),
			)
		);
		if ( '' !== $offered ) {
			Holds::add( $entry['kind'], $entry['slug'], $offered, $event_id, __( 'Rolled back by hand.', 'update-airlock' ) );
		}
		self::back(
			__( 'Restored.', 'update-airlock' ),
			'success',
			Labels::url(
				array(
					'view' => 'event',
					'id'   => $event_id,
				)
			)
		);
	}

	/**
	 * Deletes a snapshot.
	 */
	public static function delete_snapshot(): void {
		$id    = isset( $_POST['snapshot'] ) ? sanitize_key( wp_unslash( $_POST['snapshot'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifies the nonce before anything is changed.
		$entry = Store::get( $id );
		self::guard( 'delete_snapshot', $entry ? (string) $entry['kind'] : '' );
		Store::delete( $id );
		self::back( __( 'Deleted.', 'update-airlock' ), 'success', Labels::url( array( 'tab' => 'snapshots' ) ) );
	}

	/**
	 * Downloads the history as CSV or JSON.
	 */
	public static function export(): void {
		self::guard( 'export' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$format = isset( $_POST['format'] ) && 'json' === $_POST['format'] ? 'json' : 'csv';
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$risk   = isset( $_POST['risk'] ) ? sanitize_key( wp_unslash( $_POST['risk'] ) ) : '';
		$state  = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$kind = in_array( $kind, array( 'plugin', 'theme' ), true ) ? $kind : '';
		$risk = in_array( $risk, array( 'low', 'review', 'high' ), true ) ? $risk : '';

		$rows = array();
		$page = 0;
		$more = false;
		do {
			$batch = Events::list( $kind, $risk, $state, 200, $page * 200 );
			foreach ( $batch as $event ) {
				$finds  = Events::findings( (int) $event['id'] );
				$rows[] = array(
					'id'       => (int) $event['id'],
					'date'     => (string) $event['created_at'],
					'kind'     => (string) $event['kind'],
					'name'     => (string) $event['name'],
					'slug'     => (string) $event['slug'],
					'from'     => (string) $event['from_ver'],
					'to'       => (string) $event['to_ver'],
					'how'      => (string) $event['trigger_by'],
					'risk'     => (string) $event['risk'],
					'result'   => (string) $event['state'],
					'findings' => count( $finds ),
					'rules'    => implode( ' ', array_values( array_unique( array_column( $finds, 'rule' ) ) ) ),
				);
			}
			++$page;
			$more = 200 === count( $batch ) && $page < 50;
		} while ( $more );

		if ( 'json' === $format ) {
			$body = (string) wp_json_encode( $rows, JSON_PRETTY_PRINT );
			$mime = 'application/json';
		} else {
			$body = self::csv( $rows );
			$mime = 'text/csv';
		}
		$tmp = wp_tempnam( 'updlock-export' );
		$fs  = Fs::get();
		if ( ! $tmp || ! $fs || ! $fs->put_contents( $tmp, $body, FS_CHMOD_FILE ) ) {
			self::back( __( 'The export could not be prepared.', 'update-airlock' ), 'error' );
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="update-airlock-history.' . $format . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the generated temp file as a download.
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * CSV text; cells that start like a formula are prefixed so spreadsheets do not run them.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return string
	 */
	public static function csv( array $rows ): string {
		$out  = '';
		$cols = array( 'id', 'date', 'kind', 'name', 'slug', 'from', 'to', 'how', 'risk', 'result', 'findings', 'rules' );
		$out .= implode( ',', $cols ) . "\n";
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $cols as $col ) {
				$cell = (string) $row[ $col ];
				if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
					$cell = "'" . $cell;
				}
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$out .= implode( ',', $cells ) . "\n";
		}
		return $out;
	}
}

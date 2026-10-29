<?php
/**
 * Text and markup helpers for risk levels and states.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Labels shared by the screens.
 */
class Labels {

	/**
	 * Risk level label.
	 *
	 * @param string $risk low, review or high.
	 * @return string
	 */
	public static function risk( string $risk ): string {
		$map = array(
			'low'    => __( 'Low', 'update-airlock' ),
			'review' => __( 'Review', 'update-airlock' ),
			'high'   => __( 'High', 'update-airlock' ),
		);
		return $map[ $risk ] ?? __( 'Not inspected', 'update-airlock' );
	}

	/**
	 * Event state label.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	public static function state( string $state ): string {
		$map = array(
			'new'                   => __( 'Started', 'update-airlock' ),
			'inspecting'            => __( 'Inspecting', 'update-airlock' ),
			'inspected'             => __( 'Report only', 'update-airlock' ),
			'snapshotting'          => __( 'Making a snapshot', 'update-airlock' ),
			'installing'            => __( 'Installing', 'update-airlock' ),
			'verifying'             => __( 'Checking the site', 'update-airlock' ),
			'committed'             => __( 'Kept (checks passed)', 'update-airlock' ),
			'rolling_back'          => __( 'Rolling back', 'update-airlock' ),
			'rolled_back'           => __( 'Rolled back', 'update-airlock' ),
			'held'                  => __( 'Held for review', 'update-airlock' ),
			'awaiting_confirmation' => __( 'Waiting for your OK', 'update-airlock' ),
			'cancelled'             => __( 'Cancelled (no snapshot)', 'update-airlock' ),
			'install_failed'        => __( 'Install failed', 'update-airlock' ),
			'failed_checks'         => __( 'Kept (checks failed)', 'update-airlock' ),
			'unverified'            => __( 'Kept (checks inconclusive)', 'update-airlock' ),
			'failed'                => __( 'Rollback failed', 'update-airlock' ),
		);
		return $map[ $state ] ?? $state;
	}

	/**
	 * Badge markup for a risk level.
	 *
	 * @param string $risk low, review, high or ''.
	 * @return string HTML, escaped.
	 */
	public static function risk_badge( string $risk ): string {
		$class = in_array( $risk, array( 'low', 'review', 'high' ), true ) ? $risk : 'none';
		return '<span class="updlock-badge updlock-badge-' . esc_attr( $class ) . '">' . esc_html( self::risk( $risk ) ) . '</span>';
	}

	/**
	 * Badge markup for a state.
	 *
	 * @param string $state State key.
	 * @return string HTML, escaped.
	 */
	public static function state_badge( string $state ): string {
		$tone = 'neutral';
		if ( in_array( $state, array( 'rolled_back', 'held', 'awaiting_confirmation', 'failed', 'failed_checks', 'cancelled', 'install_failed' ), true ) ) {
			$tone = 'warn';
		} elseif ( 'committed' === $state ) {
			$tone = 'good';
		}
		return '<span class="updlock-state updlock-state-' . esc_attr( $tone ) . '">' . esc_html( self::state( $state ) ) . '</span>';
	}

	/**
	 * Severity label.
	 *
	 * @param string $severity high, medium or low.
	 * @return string
	 */
	public static function severity( string $severity ): string {
		$map = array(
			'high'   => __( 'High', 'update-airlock' ),
			'medium' => __( 'Review', 'update-airlock' ),
			'low'    => __( 'Note', 'update-airlock' ),
		);
		return $map[ $severity ] ?? $severity;
	}

	/**
	 * Link to the report page.
	 *
	 * @param array<string,string|int> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );
		return add_query_arg( array_merge( array( 'page' => 'update-airlock' ), $args ), $base );
	}

	/**
	 * Capability that allows managing updates; on multisite, the network-level one.
	 *
	 * @param string $kind plugin or theme.
	 * @return string
	 */
	public static function cap( string $kind = 'plugin' ): string {
		if ( is_multisite() ) {
			return 'theme' === $kind ? 'manage_network_themes' : 'manage_network_plugins';
		}
		return 'theme' === $kind ? 'update_themes' : 'update_plugins';
	}

	/**
	 * Link to one event's report.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	public static function event_url( int $event_id ): string {
		return self::url(
			array(
				'view' => 'event',
				'id'   => $event_id,
			)
		);
	}
}

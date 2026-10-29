<?php
/**
 * Dashboard widget: the last updates and anything held.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Core\Events;
use Updlock\Upgrade\Holds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget registration and output.
 */
class Dashboard {

	/**
	 * Registers the widget.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'setup' ) );
	}

	/**
	 * Adds the widget for users who can update plugins.
	 */
	public static function setup(): void {
		if ( current_user_can( 'update_plugins' ) ) {
			wp_add_dashboard_widget( 'updlock_dashboard', __( 'Update Airlock', 'update-airlock' ), array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Widget body.
	 */
	public static function render(): void {
		$holds = Holds::all();
		if ( $holds ) {
			echo '<p><strong>' . esc_html__( 'Held back:', 'update-airlock' ) . '</strong> ';
			$names = array();
			foreach ( $holds as $key => $hold ) {
				list( , $slug ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
				$names[]        = $slug . ' ' . $hold['version'];
			}
			echo esc_html( implode( ', ', $names ) ) . '</p>';
		}
		$events = Events::list( '', '', '', 5, 0 );
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No updates recorded yet.', 'update-airlock' ) . '</p>';
		} else {
			echo '<ul class="updlock-widget">';
			foreach ( $events as $event ) {
				echo '<li>' . wp_kses_post( Labels::risk_badge( (string) $event['risk'] ) ) . ' <a href="' . esc_url(
					Labels::url(
						array(
							'view' => 'event',
							'id'   => (int) $event['id'],
						)
					)
				) . '">' . esc_html( (string) $event['name'] . ' ' . $event['to_ver'] ) . '</a> ' . wp_kses_post( Labels::state_badge( (string) $event['state'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( Labels::url() ) . '">' . esc_html__( 'Open Update Airlock', 'update-airlock' ) . '</a></p>';
	}
}

<?php
/**
 * Adds to core's update rows and the Updates screen without touching what is there.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Check\Runner;
use Updlock\Core\Events;
use Updlock\Upgrade\Holds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Risk badges, inspect links and hold notes.
 */
class Rows {

	/**
	 * Registers screen hooks.
	 */
	public static function register(): void {
		add_action( 'load-plugins.php', array( __CLASS__, 'plugins_screen' ) );
		add_action( 'load-themes.php', array( __CLASS__, 'themes_screen' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'updates_screen' ) );
	}

	/**
	 * Attaches a message to each pending plugin update row.
	 */
	public static function plugins_screen(): void {
		self::maybe_baseline();
		$upd = get_site_transient( 'update_plugins' );
		if ( is_object( $upd ) && ! empty( $upd->response ) ) {
			foreach ( array_keys( (array) $upd->response ) as $file ) {
				add_action( 'in_plugin_update_message-' . $file, array( __CLASS__, 'plugin_message' ), 20, 2 );
			}
		}
	}

	/**
	 * Attaches a message to each pending theme update.
	 */
	public static function themes_screen(): void {
		$upd = get_site_transient( 'update_themes' );
		if ( is_object( $upd ) && ! empty( $upd->response ) ) {
			foreach ( array_keys( (array) $upd->response ) as $slug ) {
				add_action( 'in_theme_update_message-' . $slug, array( __CLASS__, 'theme_message' ), 20, 2 );
			}
		}
	}

	/**
	 * The Updates screen panel.
	 */
	public static function updates_screen(): void {
		self::maybe_baseline();
		add_action( 'core_upgrade_preamble', array( __CLASS__, 'panel' ) );
	}

	/**
	 * Takes the daily baseline when it is stale (at most once per page load).
	 */
	private static function maybe_baseline(): void {
		if ( current_user_can( Labels::cap() ) ) {
			Runner::maybe_refresh_baseline();
		}
	}

	/**
	 * Row text for a plugin.
	 *
	 * @param mixed $data     Plugin data.
	 * @param mixed $response Update offer.
	 */
	public static function plugin_message( $data, $response ): void {
		$file = is_object( $response ) && isset( $response->plugin ) ? (string) $response->plugin : ( is_array( $data ) && isset( $data['plugin'] ) ? (string) $data['plugin'] : '' );
		$slug = '.' === dirname( $file ) ? basename( $file ) : dirname( $file );
		self::message( 'plugin', $slug, is_object( $response ) && isset( $response->new_version ) ? (string) $response->new_version : '' );
	}

	/**
	 * Row text for a theme.
	 *
	 * @param mixed $theme    Theme object.
	 * @param mixed $response Update offer.
	 */
	public static function theme_message( $theme, $response ): void {
		$slug = is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? (string) $theme->get_stylesheet() : '';
		$to   = is_array( $response ) && isset( $response['new_version'] ) ? (string) $response['new_version'] : '';
		self::message( 'theme', $slug, $to );
	}

	/**
	 * Prints the badge, hold note and inspect link.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 * @param string $to   Offered version.
	 */
	private static function message( string $kind, string $slug, string $to ): void {
		if ( '' === $slug || ! current_user_can( Labels::cap( $kind ) ) ) {
			return;
		}
		$event = Events::find( $kind, $slug, $to );
		echo ' <span class="updlock-row">';
		if ( $event && '' !== $event['risk'] ) {
			echo wp_kses_post( Labels::risk_badge( (string) $event['risk'] ) ) . ' ';
			echo '<a href="' . esc_url(
				Labels::url(
					array(
						'view' => 'event',
						'id'   => (int) $event['id'],
					)
				)
			) . '">' . esc_html__( 'Report', 'update-airlock' ) . '</a> ';
		}
		if ( Holds::is_held( $kind, $slug, $to ) ) {
			echo '<span class="updlock-state updlock-state-warn">' . esc_html__( 'Held by Update Airlock', 'update-airlock' ) . '</span> ';
		}
		echo '<a href="' . esc_url( Views::inspect_url( $kind, $slug ) ) . '">' . esc_html__( 'Inspect this update', 'update-airlock' ) . '</a></span>';
	}

	/**
	 * Panel above the update lists.
	 */
	public static function panel(): void {
		if ( ! current_user_can( Labels::cap() ) ) {
			return;
		}
		echo '<div class="updlock-panel"><h2>' . esc_html__( 'Update Airlock', 'update-airlock' ) . '</h2>';
		echo '<p>' . esc_html__( 'Inspect the code changes of an update before you install it. It reads what changed; it is not a malware scanner.', 'update-airlock' ) . '</p>';
		Views::pending_table();
		echo '</div>';
	}
}

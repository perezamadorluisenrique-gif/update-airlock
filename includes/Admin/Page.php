<?php
/**
 * The Tools > Update Airlock screen: menu, assets, settings and routing.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Check\Runner;
use Updlock\Core\Opt;
use Updlock\Core\Settings;
use Updlock\Snapshot\Restorer;
use Updlock\Upgrade\Journal;
use Updlock\Upgrade\Verifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin wiring.
 */
class Page {

	/**
	 * Hook suffix of the plugin's screen.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registers admin hooks.
	 */
	public static function register(): void {
		// On multisite the screen lives only in the network admin; sites get no menu.
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'init' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( ( is_multisite() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_' ) . UPDLOCK_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'network_admin_edit_updlock_settings', array( __CLASS__, 'save_network_settings' ) );
	}

	/**
	 * Adds the Tools menu entry.
	 */
	public static function menu(): void {
		$args       = array(
			__( 'Update Airlock', 'update-airlock' ),
			__( 'Update Airlock', 'update-airlock' ),
			Labels::cap(),
			'update-airlock',
			array( __CLASS__, 'render' ),
		);
		self::$hook = (string) ( is_multisite() ? add_submenu_page( 'settings.php', ...$args ) : add_management_page( ...$args ) );
	}

	/**
	 * Saves the settings form posted from the network admin (options.php does not exist there).
	 */
	public static function save_network_settings(): void {
		check_admin_referer( 'updlock_settings_group-options' );
		if ( ! current_user_can( 'manage_network_options' ) || ! current_user_can( Labels::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'update-airlock' ), 403 );
		}
		$input = isset( $_POST[ Settings::OPTION ] ) ? map_deep( wp_unslash( $_POST[ Settings::OPTION ] ), 'sanitize_textarea_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep sanitizes every value; Settings::sanitize() then validates each field.
		Opt::set( Settings::OPTION, Settings::sanitize( $input ) );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Labels::url( array( 'tab' => 'settings' ) ) ) );
		exit;
	}

	/**
	 * Settings registration, first-run redirect and recovery work.
	 */
	public static function init(): void {
		register_setting(
			'updlock_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		if ( wp_doing_ajax() ) {
			return;
		}
		if ( get_transient( 'updlock_welcome' ) && current_user_can( Labels::cap() ) && ! is_network_admin() ) {
			delete_transient( 'updlock_welcome' );
			wp_safe_redirect( Labels::url( array( 'tab' => 'welcome' ) ) );
			exit;
		}
		if ( ! current_user_can( Labels::cap() ) ) {
			return;
		}
		Restorer::recover();
		if ( Journal::pending() ) {
			Verifier::run_pending();
		}
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( Labels::url() ) . '">' . esc_html__( 'Open', 'update-airlock' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads the stylesheet and script where they are used.
	 *
	 * @param string $hook Current screen hook suffix.
	 */
	public static function assets( string $hook ): void {
		$ours = self::$hook === $hook;
		$core = in_array( $hook, array( 'plugins.php', 'themes.php', 'update-core.php', 'index.php' ), true );
		if ( ! $ours && ! $core ) {
			return;
		}
		wp_enqueue_style( 'updlock-admin', UPDLOCK_URL . 'assets/admin.css', array(), UPDLOCK_VERSION );
		wp_enqueue_script( 'updlock-admin', UPDLOCK_URL . 'assets/admin.js', array(), UPDLOCK_VERSION, true );
		wp_localize_script(
			'updlock-admin',
			'updlockAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'updlock_inspect' ),
				'recheck' => wp_create_nonce( 'updlock_recheck' ),
				'pending' => (bool) Journal::pending(),
				'strings' => array(
					'working'  => __( 'Working...', 'update-airlock' ),
					'download' => __( 'Downloading the package WordPress would install...', 'update-airlock' ),
					'reading'  => __( 'Reading files...', 'update-airlock' ),
					'failed'   => __( 'The inspection could not finish.', 'update-airlock' ),
					'done'     => __( 'Done', 'update-airlock' ),
					'checking' => __( 'Checking the site...', 'update-airlock' ),
				),
			)
		);
	}

	/**
	 * Admin notices about held and rolled-back updates.
	 */
	public static function notices(): void {
		if ( ! current_user_can( Labels::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'tools_page_update-airlock', 'settings_page_update-airlock-network' ), true ) ) {
			return;
		}
		$lines = array();
		foreach ( \Updlock\Upgrade\Holds::all() as $key => $hold ) {
			list( , $slug ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
			/* translators: 1: folder name, 2: version */
			$lines[] = sprintf( esc_html__( '%1$s %2$s is held back from automatic updates.', 'update-airlock' ), '<strong>' . esc_html( $slug ) . '</strong>', esc_html( (string) $hold['version'] ) );
		}
		if ( ! $lines ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . wp_kses_post( implode( ' ', $lines ) ) . ' <a href="' . esc_url( Labels::url( array( 'tab' => 'overview' ) ) ) . '">' . esc_html__( 'Review in Update Airlock', 'update-airlock' ) . '</a></p></div>';
	}

	/**
	 * Draws the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Labels::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'update-airlock' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; every action is a nonce-checked admin-post request.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap updlock-root"><h1>' . esc_html__( 'Update Airlock', 'update-airlock' ) . '</h1>';
		Views::message();
		if ( 'event' === $view ) {
			Views::event();
		} elseif ( 'diff' === $view ) {
			Views::diff();
		} elseif ( 'inspect' === $view ) {
			Views::inspect();
		} else {
			self::tabs( $tab );
			switch ( $tab ) {
				case 'welcome':
					Views::welcome();
					break;
				case 'history':
					Views::history();
					break;
				case 'snapshots':
					Views::snapshots();
					break;
				case 'settings':
					Views::settings();
					break;
				default:
					Views::overview();
			}
		}
		echo '</div>';
	}

	/**
	 * Tab bar.
	 *
	 * @param string $current Current tab.
	 */
	private static function tabs( string $current ): void {
		$tabs = array(
			'overview'  => __( 'Overview', 'update-airlock' ),
			'history'   => __( 'History', 'update-airlock' ),
			'snapshots' => __( 'Snapshots', 'update-airlock' ),
			'settings'  => __( 'Settings', 'update-airlock' ),
		);
		if ( 'welcome' === $current ) {
			$tabs = array( 'welcome' => __( 'Welcome', 'update-airlock' ) ) + $tabs;
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Labels::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Re-runs checks for entries that could not be verified (called from the browser ping).
	 */
	public static function recheck(): void {
		check_ajax_referer( 'updlock_recheck' );
		if ( ! current_user_can( Labels::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		Runner::maybe_refresh_baseline();
		Verifier::run_pending();
		wp_send_json_success( array( 'pending' => (bool) Journal::pending() ) );
	}
}

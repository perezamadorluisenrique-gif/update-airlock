<?php
/**
 * Hooks into the core upgrader: snapshot, inspect, hold, and schedule the checks.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Upgrade;

use Updlock\Check\Error_Log;
use Updlock\Core\Events;
use Updlock\Core\Fs;
use Updlock\Core\Settings;
use Updlock\Inspect\Engine;
use Updlock\Inspect\Reports;
use Updlock\Notify\Mailer;
use Updlock\Snapshot\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upgrader integration. Hold-back filters only withhold updates; this plugin never adds an update source.
 */
class Hooks {

	/**
	 * Event ids of runs started in this request, keyed "kind|slug".
	 *
	 * @var array<string,int>
	 */
	private static $runs = array();

	/**
	 * Whether the current package may replace an existing folder.
	 *
	 * @var bool
	 */
	private static $clear = false;

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_filter( 'upgrader_package_options', array( __CLASS__, 'package_options' ), 99 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'pre_install' ), 5, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 20, 4 );
		add_filter( 'upgrader_install_package_result', array( __CLASS__, 'package_result' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'process_complete' ), 20, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'activated' ), 20 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'withhold_plugin' ), 30, 2 );
		add_filter( 'auto_update_theme', array( __CLASS__, 'withhold_theme' ), 30, 2 );
		add_action( 'updlock_resume', array( Verifier::class, 'run_pending' ) );
	}

	/**
	 * Which plugin or theme an upgrader call is about.
	 *
	 * @param mixed $extra Hook extra data.
	 * @return array{0:string,1:string,2:string}|null Kind, folder name, plugin basename.
	 */
	private static function item( $extra ) {
		if ( ! is_array( $extra ) ) {
			return null;
		}
		$type = $extra['type'] ?? '';
		if ( '' === $type ) {
			// Bulk upgrades (WP-CLI, auto-updates) pass only the item key.
			$type = ! empty( $extra['plugin'] ) ? 'plugin' : ( ! empty( $extra['theme'] ) ? 'theme' : '' );
		}
		if ( 'plugin' === $type && ! empty( $extra['plugin'] ) ) {
			$plugin = (string) $extra['plugin'];
			$slug   = '.' === dirname( $plugin ) ? basename( $plugin ) : dirname( $plugin );
			// Never snapshot or roll back this plugin's own folder while it is running.
			return Fs::valid_slug( $slug ) && 'update-airlock' !== $slug ? array( 'plugin', $slug, $plugin ) : null;
		}
		if ( 'theme' === $type && ! empty( $extra['theme'] ) && Fs::valid_slug( (string) $extra['theme'] ) ) {
			return array( 'theme', (string) $extra['theme'], '' );
		}
		return null;
	}

	/**
	 * Starts a run: event, journal entry and snapshot.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    Folder name.
	 * @param string $plugin  Plugin basename ('' for themes).
	 * @param string $trigger manual, auto or upload.
	 * @return int|\WP_Error Event id, or an error that cancels the update.
	 */
	private static function begin_run( string $kind, string $slug, string $plugin, string $trigger ) {
		$from = '';
		$name = $slug;
		$to   = '';
		if ( 'plugin' === $kind ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
			$from = (string) ( $data['Version'] ?? '' );
			$name = '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : $slug;
			$upd  = get_site_transient( 'update_plugins' );
			$to   = ( is_object( $upd ) && isset( $upd->response[ $plugin ]->new_version ) ) ? (string) $upd->response[ $plugin ]->new_version : '';
		} else {
			$theme = wp_get_theme( $slug );
			$from  = (string) $theme->get( 'Version' );
			$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $slug;
			$upd   = get_site_transient( 'update_themes' );
			$to    = ( is_object( $upd ) && isset( $upd->response[ $slug ]['new_version'] ) ) ? (string) $upd->response[ $slug ]['new_version'] : '';
		}
		$active  = 'plugin' === $kind ? ( is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin ) ) : ( get_stylesheet() === $slug || get_template() === $slug );
		$network = 'plugin' === $kind && is_plugin_active_for_network( $plugin );

		$event_id                          = Events::create(
			array(
				'kind'       => $kind,
				'slug'       => $slug,
				'name'       => $name,
				'from_ver'   => $from,
				'to_ver'     => $to,
				'trigger_by' => $trigger,
				'state'      => 'snapshotting',
				'user_id'    => get_current_user_id(),
			)
		);
		self::$runs[ $kind . '|' . $slug ] = $event_id;
		Journal::put(
			$event_id,
			array(
				'kind'             => $kind,
				'slug'             => $slug,
				'plugin'           => $plugin,
				'from'             => $from,
				'to'               => $to,
				'state'            => 'snapshotting',
				'trigger'          => $trigger,
				'created'          => time(),
				'was_active'       => $active,
				'network'          => $network,
				'snapshot'         => '',
				'log_offset'       => Error_Log::offset(),
				'await_activation' => false,
			)
		);

		$snap = Store::create(
			$kind,
			$slug,
			array(
				'version' => $from,
				'name'    => $name,
				'plugin'  => $plugin,
				'active'  => $active,
				'network' => $network,
				'user'    => get_current_user_id(),
			)
		);
		if ( is_wp_error( $snap ) ) {
			if ( Settings::get( 'cancel_no_snapshot' ) ) {
				Journal::set( $event_id, array( 'state' => 'cancelled' ) );
				Events::update(
					$event_id,
					array(
						'state'   => 'cancelled',
						'summary' => array( 'error' => $snap->get_error_message() ),
					)
				);
				return new \WP_Error(
					'updlock_no_snapshot',
					/* translators: %s: reason */
					sprintf( __( 'Update Airlock cancelled this update because it could not make a snapshot first: %s', 'update-airlock' ), $snap->get_error_message() )
				);
			}
			Events::update( $event_id, array( 'summary' => array( 'snapshot_error' => $snap->get_error_message() ) ) );
		} else {
			Journal::set( $event_id, array( 'snapshot' => $snap['id'] ) );
			Events::update( $event_id, array( 'summary' => array( 'snapshot' => $snap['id'] ) ) );
		}
		return $event_id;
	}

	/**
	 * Remembers whether the install may replace an existing folder (an upload over an installed item).
	 *
	 * @param mixed $options Package options.
	 * @return mixed
	 */
	public static function package_options( $options ) {
		self::$clear = is_array( $options ) && ! empty( $options['clear_destination'] );
		return $options;
	}

	/**
	 * Before core touches the files: takes the snapshot.
	 *
	 * @param mixed $response Filter value (true, or an error from an earlier filter).
	 * @param mixed $extra    Hook extra data.
	 * @return mixed
	 */
	public static function pre_install( $response, $extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$item = self::item( $extra );
		if ( ! $item || ! file_exists( Fs::root( $item[0] ) . '/' . $item[1] ) ) {
			return $response;
		}
		$trigger = wp_doing_cron() ? 'auto' : 'manual';
		$result  = self::begin_run( $item[0], $item[1], $item[2], $trigger );
		return is_wp_error( $result ) ? $result : $response;
	}

	/**
	 * With the new files unpacked: inspect them, apply the policy, then let the install go on.
	 *
	 * @param mixed $source        Unpacked source folder.
	 * @param mixed $remote_source Remote source (unused).
	 * @param mixed $upgrader      The upgrader.
	 * @param mixed $extra         Hook extra data.
	 * @return mixed
	 */
	public static function source_selection( $source, $remote_source, $upgrader, $extra ) {
		unset( $remote_source );
		if ( is_wp_error( $source ) || ! is_string( $source ) ) {
			return $source;
		}
		$item = self::item( $extra );
		if ( ! $item && is_array( $extra ) && 'install' === ( $extra['action'] ?? '' ) && in_array( $extra['type'] ?? '', array( 'plugin', 'theme' ), true ) && ( self::$clear || ! empty( $upgrader->skin->overwrite ) ) ) {
			$item = self::uploaded_item( (string) $extra['type'], $source );
			if ( $item ) {
				$result = self::begin_run( $item[0], $item[1], $item[2], 'upload' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		if ( ! $item ) {
			return $source;
		}
		$event_id = self::$runs[ $item[0] . '|' . $item[1] ] ?? 0;
		$entry    = Journal::get( $event_id );
		if ( ! $entry ) {
			return $source;
		}
		return self::inspect( $source, $item, $event_id, $entry );
	}

	/**
	 * Matches an uploaded package that replaces an installed one.
	 *
	 * @param string $kind   plugin or theme.
	 * @param string $source Unpacked folder.
	 * @return array{0:string,1:string,2:string}|null
	 */
	private static function uploaded_item( string $kind, string $source ) {
		$slug = basename( untrailingslashit( $source ) );
		if ( ! Fs::valid_slug( $slug ) || 'update-airlock' === $slug || ! is_dir( Fs::root( $kind ) . '/' . $slug ) ) {
			return null;
		}
		$plugin = '';
		if ( 'plugin' === $kind ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( array_keys( get_plugins() ) as $file ) {
				if ( dirname( $file ) === $slug ) {
					$plugin = $file;
					break;
				}
			}
			if ( '' === $plugin ) {
				return null;
			}
		}
		return array( $kind, $slug, $plugin );
	}

	/**
	 * Inspection and policy.
	 *
	 * @param string              $source   New files.
	 * @param array               $item     Kind, slug, plugin.
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $entry    Journal entry.
	 * @return string|\WP_Error
	 */
	private static function inspect( string $source, array $item, int $event_id, array $entry ) {
		list( $kind, $slug ) = $item;
		$old                 = Fs::root( $kind ) . '/' . $slug;
		$level               = 'low';
		if ( is_dir( $old ) ) {
			try {
				$report = Engine::run(
					$old,
					$source,
					array(
						'kind' => $kind,
						'slug' => $slug,
					),
					(float) apply_filters( 'updlock_inspect_budget', 20.0 )
				);
				Reports::persist( $event_id, $report );
				$level = $report['level'];
			} catch ( \Throwable $e ) {
				Events::update( $event_id, array( 'summary' => array( 'inspect_error' => $e->getMessage() ) ) );
			}
		} else {
			Events::update( $event_id, array( 'summary' => array( 'single_file' => true ) ) );
		}
		$event = Events::get( $event_id );
		$to    = $event ? (string) $event['to_ver'] : (string) $entry['to'];

		if ( 'high' === $level ) {
			$trigger = (string) $entry['trigger'];
			if ( 'auto' === $trigger && Settings::get( 'hold_auto_high' ) ) {
				Holds::add( $kind, $slug, $to, $event_id, __( 'The code changes were rated High.', 'update-airlock' ) );
				return self::stop( $event_id, $entry, 'held', __( 'Update Airlock held this automatic update because its code changes were rated High. Review the report and release it if it looks right.', 'update-airlock' ), true );
			}
			if ( 'auto' !== $trigger && Settings::get( 'confirm_manual_high' ) && ! Holds::is_confirmed( $kind, $slug, $to ) ) {
				return self::stop( $event_id, $entry, 'awaiting_confirmation', __( 'Update Airlock stopped this update because its code changes were rated High. Review the report, then choose Allow this update and run the update again.', 'update-airlock' ), false );
			}
		}
		Journal::set( $event_id, array( 'state' => 'installing' ) );
		Events::update( $event_id, array( 'state' => 'installing' ) );
		return $source;
	}

	/**
	 * Stops an install: puts the old copy back in service, drops the snapshot and returns the error that core shows.
	 *
	 * @param int                 $event_id Event id.
	 * @param array<string,mixed> $entry    Journal entry.
	 * @param string              $state    End state.
	 * @param string              $message  Message for the user.
	 * @param bool                $mail     Whether to email.
	 * @return \WP_Error
	 */
	private static function stop( int $event_id, array $entry, string $state, string $message, bool $mail ): \WP_Error {
		Journal::set( $event_id, array( 'state' => $state ) );
		Events::update( $event_id, array( 'state' => $state ) );
		if ( ! empty( $entry['snapshot'] ) ) {
			Store::delete( (string) $entry['snapshot'] );
		}
		if ( 'plugin' === $entry['kind'] && ! empty( $entry['was_active'] ) && '' !== $entry['plugin'] ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_plugin_active( (string) $entry['plugin'] ) ) {
				activate_plugin( (string) $entry['plugin'], '', ! empty( $entry['network'] ), true );
			}
		}
		$link = admin_url( 'tools.php?page=update-airlock&view=event&id=' . $event_id );
		if ( $mail ) {
			$event = Events::get( $event_id );
			if ( $event ) {
				Mailer::alert( __( 'Held an update for review', 'update-airlock' ), $event, array( $message ) );
			}
		}
		return new \WP_Error( 'updlock_' . $state, $message . ' ' . $link );
	}

	/**
	 * Records an install that core itself reported as failed.
	 *
	 * @param mixed $result Install result.
	 * @param mixed $extra  Hook extra data.
	 * @return mixed
	 */
	public static function package_result( $result, $extra ) {
		$item = self::item( $extra );
		if ( $item && is_wp_error( $result ) && isset( self::$runs[ $item[0] . '|' . $item[1] ] ) ) {
			$event_id = self::$runs[ $item[0] . '|' . $item[1] ];
			$entry    = Journal::get( $event_id );
			if ( $entry && 'installing' === $entry['state'] ) {
				Journal::set( $event_id, array( 'state' => 'install_failed' ) );
				Events::update(
					$event_id,
					array(
						'state'   => 'install_failed',
						'summary' => array( 'error' => $result->get_error_message() ),
					)
				);
			}
		}
		return $result;
	}

	/**
	 * After the install: queue the checks for every run this request started.
	 *
	 * @param mixed $upgrader The upgrader.
	 * @param mixed $extra    Hook extra data.
	 */
	public static function process_complete( $upgrader, $extra ): void {
		unset( $upgrader, $extra );
		$queued = false;
		foreach ( self::$runs as $event_id ) {
			$entry = Journal::get( (int) $event_id );
			if ( ! $entry || 'installing' !== $entry['state'] ) {
				continue;
			}
			$plugin = (string) $entry['plugin'];
			$wait   = 'plugin' === $entry['kind'] && ! empty( $entry['was_active'] ) && ! wp_doing_cron() && ! is_plugin_active( $plugin ) && ! is_plugin_active_for_network( $plugin );
			Journal::set(
				(int) $event_id,
				array(
					'state'            => 'verifying',
					'await_activation' => $wait,
				)
			);
			Events::update( (int) $event_id, array( 'state' => 'verifying' ) );
			$queued = true;
		}
		if ( $queued ) {
			Verifier::schedule( 60 );
			add_action( 'shutdown', array( Verifier::class, 'run_pending' ), 5 );
		}
	}

	/**
	 * A plugin was activated: if it was waiting for that, verify it at the end of this request.
	 */
	public static function activated(): void {
		if ( Journal::pending() ) {
			add_action( 'shutdown', array( Verifier::class, 'run_pending' ), 5 );
		}
	}

	/**
	 * Withholds a held plugin version from automatic updates (never adds an update).
	 *
	 * @param mixed $update Whether to update.
	 * @param mixed $item   Update offer.
	 * @return mixed
	 */
	public static function withhold_plugin( $update, $item ) {
		if ( $update && is_object( $item ) && isset( $item->plugin, $item->new_version ) ) {
			$slug = '.' === dirname( (string) $item->plugin ) ? basename( (string) $item->plugin ) : dirname( (string) $item->plugin );
			if ( Holds::is_held( 'plugin', $slug, (string) $item->new_version ) ) {
				return false;
			}
		}
		return $update;
	}

	/**
	 * Withholds a held theme version from automatic updates (never adds an update).
	 *
	 * @param mixed $update Whether to update.
	 * @param mixed $item   Update offer.
	 * @return mixed
	 */
	public static function withhold_theme( $update, $item ) {
		if ( $update && is_object( $item ) && isset( $item->theme, $item->new_version ) && Holds::is_held( 'theme', (string) $item->theme, (string) $item->new_version ) ) {
			return false;
		}
		return $update;
	}
}

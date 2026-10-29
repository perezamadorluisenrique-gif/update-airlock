<?php
/**
 * Screens of the Update Airlock page.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Admin;

use Updlock\Check\Runner;
use Updlock\Core\Events;
use Updlock\Core\Settings;
use Updlock\Inspect\Rules\Catalog;
use Updlock\Inspect\Scorer;
use Updlock\Snapshot\Store;
use Updlock\Upgrade\Holds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output for each tab and view. Everything is escaped where it is printed.
 */
class Views {

	/**
	 * Prints a one-time notice after an action.
	 */
	public static function message(): void {
		$key = 'updlock_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! is_array( $msg ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === ( $msg['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $msg['text'] ) . '</p></div>';
	}

	/**
	 * Stores a notice for the next page load.
	 *
	 * @param string $text Message.
	 * @param string $type success or error.
	 */
	public static function flash( string $text, string $type = 'success' ): void {
		set_transient(
			'updlock_notice_' . get_current_user_id(),
			array(
				'text' => $text,
				'type' => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * A small POST form for one action.
	 *
	 * @param string                   $action  Action name (without the prefix).
	 * @param array<string,string|int> $args    Hidden fields.
	 * @param string                   $label   Button label.
	 * @param string                   $confirm Confirmation text ('' for none).
	 * @param bool                     $primary Whether the button is the main one.
	 */
	public static function form( string $action, array $args, string $label, string $confirm = '', bool $primary = false ): void {
		echo '<form class="updlock-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'updlock_' . $action ) . '" />';
		wp_nonce_field( 'updlock_' . $action );
		foreach ( $args as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( '' !== $confirm ? ' data-updlock-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * Updates waiting on this site.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function pending_updates(): array {
		$out = array();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$upd = get_site_transient( 'update_plugins' );
		if ( is_object( $upd ) && ! empty( $upd->response ) ) {
			$all = get_plugins();
			foreach ( (array) $upd->response as $file => $offer ) {
				$slug  = '.' === dirname( (string) $file ) ? basename( (string) $file ) : dirname( (string) $file );
				$out[] = array(
					'kind'    => 'plugin',
					'slug'    => $slug,
					'name'    => (string) ( $all[ $file ]['Name'] ?? $slug ),
					'from'    => (string) ( $all[ $file ]['Version'] ?? '' ),
					'to'      => (string) ( $offer->new_version ?? '' ),
					'package' => (string) ( $offer->package ?? '' ),
				);
			}
		}
		$upd = get_site_transient( 'update_themes' );
		if ( is_object( $upd ) && ! empty( $upd->response ) ) {
			foreach ( (array) $upd->response as $slug => $offer ) {
				$theme = wp_get_theme( (string) $slug );
				$out[] = array(
					'kind'    => 'theme',
					'slug'    => (string) $slug,
					'name'    => $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $slug,
					'from'    => (string) $theme->get( 'Version' ),
					'to'      => (string) ( $offer['new_version'] ?? '' ),
					'package' => (string) ( $offer['package'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Overview tab.
	 */
	public static function overview(): void {
		echo '<p>' . esc_html__( 'Update Airlock reads the code changes in each plugin and theme update, keeps a snapshot, checks your pages afterward and rolls back when a check fails. It reads what changed. It is not a malware scanner and can miss code that was already there or is hidden well. It helps you decide.', 'update-airlock' ) . '</p>';

		$base = Runner::baseline();
		echo '<p>';
		if ( $base['ts'] ) {
			/* translators: %s: how long ago */
			echo esc_html( sprintf( __( 'Page baseline taken %s ago.', 'update-airlock' ), human_time_diff( $base['ts'] ) ) );
		} else {
			echo esc_html__( 'No page baseline yet. It is taken the first time you open the Updates or Plugins screen, and once a day.', 'update-airlock' );
		}
		echo '</p>';

		$holds = Holds::all();
		if ( $holds ) {
			echo '<h2>' . esc_html__( 'Held back', 'update-airlock' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'update-airlock' ) . '</th><th>' . esc_html__( 'Version', 'update-airlock' ) . '</th><th>' . esc_html__( 'Why', 'update-airlock' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $holds as $key => $hold ) {
				list( $kind, $slug ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
				echo '<tr><td>' . esc_html( $slug ) . '</td><td>' . esc_html( (string) $hold['version'] ) . '</td><td>' . esc_html( (string) $hold['reason'] ) . '</td><td>';
				echo '<a class="button" href="' . esc_url(
					Labels::url(
						array(
							'view' => 'event',
							'id'   => (int) $hold['event_id'],
						)
					)
				) . '">' . esc_html__( 'Report', 'update-airlock' ) . '</a> ';
				self::form(
					'release',
					array(
						'kind' => $kind,
						'slug' => $slug,
					),
					__( 'Release', 'update-airlock' ),
					__( 'Release this version for automatic updates?', 'update-airlock' )
				);
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Updates waiting', 'update-airlock' ) . '</h2>';
		self::pending_table();

		echo '<h2>' . esc_html__( 'Recent activity', 'update-airlock' ) . '</h2>';
		self::events_table( Events::list( '', '', '', 5, 0 ), false );
		echo '<p><a href="' . esc_url( Labels::url( array( 'tab' => 'history' ) ) ) . '">' . esc_html__( 'Open the full history', 'update-airlock' ) . '</a></p>';
	}

	/**
	 * Table of pending updates with inspect links.
	 */
	public static function pending_table(): void {
		$pending = self::pending_updates();
		if ( ! $pending ) {
			echo '<p>' . esc_html__( 'No updates are waiting.', 'update-airlock' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'update-airlock' ) . '</th><th>' . esc_html__( 'Update', 'update-airlock' ) . '</th><th>' . esc_html__( 'Risk', 'update-airlock' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $pending as $item ) {
			$event = Events::find( $item['kind'], $item['slug'], $item['to'] );
			echo '<tr><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['from'] . ' > ' . $item['to'] ) . '</td><td>';
			echo wp_kses_post( Labels::risk_badge( $event ? (string) $event['risk'] : '' ) ) . '</td><td>';
			if ( $event && '' !== $event['risk'] ) {
				echo '<a class="button" href="' . esc_url(
					Labels::url(
						array(
							'view' => 'event',
							'id'   => (int) $event['id'],
						)
					)
				) . '">' . esc_html__( 'View report', 'update-airlock' ) . '</a> ';
			}
			echo '<a class="button" href="' . esc_url( self::inspect_url( $item['kind'], $item['slug'] ) ) . '">' . esc_html( $event && '' !== $event['risk'] ? __( 'Inspect again', 'update-airlock' ) : __( 'Inspect this update', 'update-airlock' ) ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * URL of the inspection progress page.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 * @return string
	 */
	public static function inspect_url( string $kind, string $slug ): string {
		return Labels::url(
			array(
				'view' => 'inspect',
				'kind' => $kind,
				'slug' => $slug,
			)
		);
	}

	/**
	 * Table of events.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @param bool                           $detail Whether to show the trigger column.
	 */
	public static function events_table( array $events, bool $detail = true ): void {
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'update-airlock' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'update-airlock' ) . '</th><th>' . esc_html__( 'Item', 'update-airlock' ) . '</th><th>' . esc_html__( 'Versions', 'update-airlock' ) . '</th>';
		if ( $detail ) {
			echo '<th>' . esc_html__( 'How', 'update-airlock' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Risk', 'update-airlock' ) . '</th><th>' . esc_html__( 'Result', 'update-airlock' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$when = strtotime( (string) $event['created_at'] . ' UTC' );
			echo '<tr><td>' . esc_html( $when ? human_time_diff( $when ) . ' ' . __( 'ago', 'update-airlock' ) : '' ) . '</td><td>' . esc_html( (string) $event['name'] ) . '</td><td>' . esc_html( $event['from_ver'] . ' > ' . $event['to_ver'] ) . '</td>';
			if ( $detail ) {
				echo '<td>' . esc_html( self::trigger_label( (string) $event['trigger_by'] ) ) . '</td>';
			}
			echo '<td>' . wp_kses_post( Labels::risk_badge( (string) $event['risk'] ) ) . '</td><td>' . wp_kses_post( Labels::state_badge( (string) $event['state'] ) ) . '</td>';
			echo '<td><a href="' . esc_url(
				Labels::url(
					array(
						'view' => 'event',
						'id'   => (int) $event['id'],
					)
				)
			) . '">' . esc_html__( 'Open', 'update-airlock' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * How an update was started.
	 *
	 * @param string $trigger Trigger key.
	 * @return string
	 */
	public static function trigger_label( string $trigger ): string {
		$map = array(
			'manual'  => __( 'By a user', 'update-airlock' ),
			'auto'    => __( 'Automatic', 'update-airlock' ),
			'upload'  => __( 'Uploaded zip', 'update-airlock' ),
			'inspect' => __( 'Report only', 'update-airlock' ),
		);
		return $map[ $trigger ] ?? $trigger;
	}

	/**
	 * The three-step welcome.
	 */
	public static function welcome(): void {
		echo '<h2>' . esc_html__( 'Set up in three steps', 'update-airlock' ) . '</h2>';
		echo '<h3>' . esc_html__( '1. Environment check', 'update-airlock' ) . '</h3><ul class="updlock-checklist">';
		foreach ( Site_Health::environment() as $row ) {
			echo '<li class="updlock-' . esc_attr( $row['ok'] ? 'ok' : 'bad' ) . '"><strong>' . esc_html( $row['label'] ) . '</strong>: ' . esc_html( $row['text'] ) . '</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( '2. Capture a baseline of your pages', 'update-airlock' ) . '</h3>';
		echo '<p>' . esc_html__( 'Update Airlock requests your own pages (home page, latest post, a page, a category, the login page and any you add in Settings) and remembers how they look, so it can tell afterward whether an update broke one.', 'update-airlock' ) . '</p>';
		echo '<p><button type="button" class="button button-primary" id="updlock-baseline-start">' . esc_html__( 'Capture baseline now', 'update-airlock' ) . '</button></p>';
		echo '<ul class="updlock-checklist" id="updlock-baseline-list" data-updlock-baseline="' . esc_attr( wp_create_nonce( 'updlock_baseline' ) ) . '">';
		foreach ( Runner::urls() as $url => $label ) {
			echo '<li data-url="' . esc_attr( $url ) . '"><strong>' . esc_html( $label ) . '</strong> <span class="updlock-muted">' . esc_html( $url ) . '</span> <span class="updlock-result"></span></li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( '3. Inspect the updates already waiting', 'update-airlock' ) . '</h3>';
		$pending = self::pending_updates();
		if ( ! $pending ) {
			echo '<p>' . esc_html__( 'No updates are waiting right now. Reports appear here and in each update row when they are.', 'update-airlock' ) . '</p>';
			return;
		}
		echo '<p><button type="button" class="button button-primary" id="updlock-inspect-all">' . esc_html__( 'Inspect all waiting updates', 'update-airlock' ) . '</button></p>';
		echo '<ul class="updlock-checklist" id="updlock-inspect-list">';
		foreach ( $pending as $item ) {
			echo '<li data-kind="' . esc_attr( $item['kind'] ) . '" data-slug="' . esc_attr( $item['slug'] ) . '"><strong>' . esc_html( $item['name'] ) . '</strong> ' . esc_html( $item['from'] . ' > ' . $item['to'] ) . ' <span class="updlock-result"></span></li>';
		}
		echo '</ul>';
	}

	/**
	 * History tab with filters and export.
	 */
	public static function history(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$kind  = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$risk  = isset( $_GET['risk'] ) ? sanitize_key( wp_unslash( $_GET['risk'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$kind  = in_array( $kind, array( 'plugin', 'theme' ), true ) ? $kind : '';
		$risk  = in_array( $risk, array( 'low', 'review', 'high' ), true ) ? $risk : '';
		$per   = 20;
		$total = Events::count( $kind, $risk, $state );

		echo '<form method="get" class="updlock-filters"><input type="hidden" name="page" value="update-airlock" /><input type="hidden" name="tab" value="history" />';
		echo '<select name="kind"><option value="">' . esc_html__( 'Plugins and themes', 'update-airlock' ) . '</option><option value="plugin"' . selected( $kind, 'plugin', false ) . '>' . esc_html__( 'Plugins', 'update-airlock' ) . '</option><option value="theme"' . selected( $kind, 'theme', false ) . '>' . esc_html__( 'Themes', 'update-airlock' ) . '</option></select> ';
		echo '<select name="risk"><option value="">' . esc_html__( 'Any risk', 'update-airlock' ) . '</option>';
		foreach ( array( 'low', 'review', 'high' ) as $level ) {
			echo '<option value="' . esc_attr( $level ) . '"' . selected( $risk, $level, false ) . '>' . esc_html( Labels::risk( $level ) ) . '</option>';
		}
		echo '</select> <select name="state"><option value="">' . esc_html__( 'Any result', 'update-airlock' ) . '</option>';
		foreach ( array( 'committed', 'rolled_back', 'held', 'awaiting_confirmation', 'unverified', 'failed_checks', 'cancelled', 'inspected' ) as $key ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $state, $key, false ) . '>' . esc_html( Labels::state( $key ) ) . '</option>';
		}
		echo '</select> <button class="button" type="submit">' . esc_html__( 'Filter', 'update-airlock' ) . '</button></form>';

		self::events_table( Events::list( $kind, $risk, $state, $per, ( $paged - 1 ) * $per ) );
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p class="updlock-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Export', 'update-airlock' ) . '</h2><p>';
		foreach ( array(
			'csv'  => 'CSV',
			'json' => 'JSON',
		) as $format => $label ) {
			self::form(
				'export',
				array(
					'format' => $format,
					'kind'   => $kind,
					'risk'   => $risk,
					'state'  => $state,
				),
				/* translators: %s: file format */
				sprintf( __( 'Download %s', 'update-airlock' ), $label )
			);
		}
		echo '</p>';
	}

	/**
	 * Snapshots tab.
	 */
	public static function snapshots(): void {
		$all = array_reverse( Store::all() );
		/* translators: %s: size such as 3 MB */
		echo '<p>' . esc_html( sprintf( __( 'Snapshots use %s on disk.', 'update-airlock' ), size_format( Store::total_size() ) ) ) . ' ' . esc_html__( 'If WordPress itself will not load, unzip the file from the wp-content/updlock-snapshots folder over the plugin folder.', 'update-airlock' ) . '</p>';
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No snapshots yet. One is made before every plugin or theme update.', 'update-airlock' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'update-airlock' ) . '</th><th>' . esc_html__( 'Version', 'update-airlock' ) . '</th><th>' . esc_html__( 'Taken', 'update-airlock' ) . '</th><th>' . esc_html__( 'Size', 'update-airlock' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $all as $entry ) {
			echo '<tr><td>' . esc_html( (string) $entry['name'] ) . '</td><td>' . esc_html( (string) $entry['version'] ) . '</td><td>' . esc_html( human_time_diff( (int) $entry['ts'] ) . ' ' . __( 'ago', 'update-airlock' ) ) . '</td><td>' . esc_html( size_format( (int) $entry['size'] ) ) . '</td><td>';
			self::form( 'rollback', array( 'snapshot' => (string) $entry['id'] ), __( 'Restore this version', 'update-airlock' ), __( 'Restore this snapshot? The current copy is replaced.', 'update-airlock' ) );
			if ( ! empty( $entry['options'] ) ) {
				self::form(
					'rollback',
					array(
						'snapshot' => (string) $entry['id'],
						'options'  => 1,
					),
					__( 'Restore with settings', 'update-airlock' ),
					__( 'Restore this snapshot and the option values saved with it? Settings changed since then are lost.', 'update-airlock' )
				);
			}
			self::form( 'delete_snapshot', array( 'snapshot' => (string) $entry['id'] ), __( 'Delete', 'update-airlock' ), __( 'Delete this snapshot?', 'update-airlock' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Settings tab.
	 */
	public static function settings(): void {
		$s = Settings::all();
		echo '<form method="post" action="options.php">';
		settings_fields( 'updlock_settings_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$flags = array(
			'hold_auto_high'      => __( 'Hold automatic updates rated High for review', 'update-airlock' ),
			'confirm_manual_high' => __( 'Ask for confirmation before a manual update rated High', 'update-airlock' ),
			'cancel_no_snapshot'  => __( 'Cancel an update when a snapshot cannot be made', 'update-airlock' ),
			'run_checks'          => __( 'Check the site after each update', 'update-airlock' ),
			'auto_rollback'       => __( 'Roll back automatically when a blocking check fails', 'update-airlock' ),
			'keep_data'           => __( 'Keep snapshots and history when the plugin is deleted', 'update-airlock' ),
		);
		foreach ( $flags as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="checkbox" name="' . esc_attr( Settings::OPTION . '[' . $key . ']' ) . '" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . ' /></td></tr>';
		}
		echo '<tr><th scope="row"><label for="updlock-keep">' . esc_html__( 'Snapshots to keep per plugin or theme', 'update-airlock' ) . '</label></th><td><input type="number" min="1" id="updlock-keep" name="' . esc_attr( Settings::OPTION . '[keep_snapshots]' ) . '" value="' . esc_attr( (string) $s['keep_snapshots'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="updlock-urls">' . esc_html__( 'More pages to check', 'update-airlock' ) . '</label></th><td><textarea id="updlock-urls" rows="5" cols="60" name="' . esc_attr( Settings::OPTION . '[extra_urls]' ) . '">' . esc_textarea( (string) $s['extra_urls'] ) . '</textarea><p class="description">' . esc_html__( 'One address per line, on this site. There is no limit.', 'update-airlock' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="updlock-mail">' . esc_html__( 'Alert emails', 'update-airlock' ) . '</label></th><td><input type="text" class="regular-text" id="updlock-mail" name="' . esc_attr( Settings::OPTION . '[emails]' ) . '" value="' . esc_attr( (string) $s['emails'] ) . '" /><p class="description">' . esc_html__( 'Comma separated. Empty means the site admin address.', 'update-airlock' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * The report for one event.
	 */
	public static function event(): void {
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$event = $id ? Events::get( $id ) : null;
		if ( ! $event ) {
			echo '<p>' . esc_html__( 'That report was not found.', 'update-airlock' ) . '</p>';
			return;
		}
		$sum      = $event['summary'];
		$findings = Events::findings( $id );
		echo '<p><a href="' . esc_url( Labels::url() ) . '">&larr; ' . esc_html__( 'Back', 'update-airlock' ) . '</a></p>';
		echo '<h2>' . esc_html( (string) $event['name'] ) . ' <span class="updlock-muted">' . esc_html( $event['from_ver'] . ' > ' . $event['to_ver'] ) . '</span></h2>';
		echo '<p>' . wp_kses_post( Labels::risk_badge( (string) $event['risk'] ) ) . ' ' . wp_kses_post( Labels::state_badge( (string) $event['state'] ) ) . ' <span class="updlock-muted">' . esc_html( self::trigger_label( (string) $event['trigger_by'] ) ) . '</span></p>';

		$reasons = Scorer::reasons( $findings );
		if ( $reasons ) {
			echo '<ul class="updlock-reasons">';
			foreach ( $reasons as $reason ) {
				echo '<li>' . esc_html( ucfirst( $reason ) ) . '</li>';
			}
			echo '</ul>';
		} elseif ( '' !== $event['risk'] ) {
			echo '<p>' . esc_html__( 'Nothing in the added code raised a concern.', 'update-airlock' ) . '</p>';
		}
		echo '<p class="updlock-muted">' . esc_html__( 'This report reads what changed between the two versions. It is not a malware scanner and can miss code that was already there or is hidden well.', 'update-airlock' ) . '</p>';

		self::event_actions( $event );

		if ( ! empty( $sum['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $sum['error'] ) . '</p></div>';
		}
		self::event_findings( $findings );
		self::event_files( $id, $sum );
		self::event_checks( $id, $sum );
		self::event_provenance( $sum );
	}

	/**
	 * Buttons that depend on the state of an event.
	 *
	 * @param array<string,mixed> $event Event.
	 */
	private static function event_actions( array $event ): void {
		$kind = (string) $event['kind'];
		$slug = (string) $event['slug'];
		echo '<p>';
		if ( 'awaiting_confirmation' === $event['state'] || ( 'high' === $event['risk'] && 'inspected' === $event['state'] ) ) {
			self::form(
				'confirm',
				array(
					'kind'    => $kind,
					'slug'    => $slug,
					'version' => (string) $event['to_ver'],
					'event'   => (int) $event['id'],
				),
				__( 'Allow this update', 'update-airlock' ),
				__( 'You reviewed the report and want this version to be allowed?', 'update-airlock' ),
				true
			);
		}
		if ( Holds::is_held( $kind, $slug, (string) $event['to_ver'] ) ) {
			self::form(
				'release',
				array(
					'kind' => $kind,
					'slug' => $slug,
				),
				__( 'Release hold', 'update-airlock' ),
				__( 'Release this version for automatic updates?', 'update-airlock' )
			);
			self::form(
				'keep',
				array(
					'kind' => $kind,
					'slug' => $slug,
				),
				__( 'Keep holding', 'update-airlock' )
			);
		}
		if ( ! empty( $event['summary']['snapshot'] ) && Store::get( (string) $event['summary']['snapshot'] ) && in_array( $event['state'], array( 'committed', 'unverified', 'failed_checks', 'failed' ), true ) ) {
			self::form( 'rollback', array( 'snapshot' => (string) $event['summary']['snapshot'] ), __( 'Roll back to the previous version', 'update-airlock' ), __( 'Restore the snapshot taken before this update?', 'update-airlock' ) );
		}
		echo '<a class="button" href="' . esc_url( self::inspect_url( $kind, $slug ) ) . '">' . esc_html__( 'Inspect again', 'update-airlock' ) . '</a></p>';
	}

	/**
	 * Findings table.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 */
	private static function event_findings( array $findings ): void {
		if ( ! $findings ) {
			return;
		}
		$catalog = Catalog::all();
		echo '<h3>' . esc_html__( 'Findings', 'update-airlock' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Level', 'update-airlock' ) . '</th><th>' . esc_html__( 'What', 'update-airlock' ) . '</th><th>' . esc_html__( 'Where', 'update-airlock' ) . '</th><th>' . esc_html__( 'Code', 'update-airlock' ) . '</th></tr></thead><tbody>';
		foreach ( $findings as $f ) {
			$info  = $catalog[ $f['rule'] ] ?? array( 'low', (string) $f['rule'], '' );
			$where = (string) $f['file'] . ( (int) $f['line'] > 0 ? ':' . (int) $f['line'] : '' );
			echo '<tr><td><span class="updlock-sev updlock-sev-' . esc_attr( (string) $f['severity'] ) . '">' . esc_html( Labels::severity( (string) $f['severity'] ) ) . '</span></td><td><strong>' . esc_html( ucfirst( $info[1] ) ) . '</strong>' . ( '' !== $f['detail'] ? ' <code>' . esc_html( (string) $f['detail'] ) . '</code>' : '' ) . '<br /><span class="updlock-muted">' . esc_html( $info[2] ) . '</span></td><td><code>' . esc_html( $where ) . '</code></td><td><code>' . esc_html( (string) $f['excerpt'] ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * File changes with links to the diffs.
	 *
	 * @param int                 $id  Event id.
	 * @param array<string,mixed> $sum Summary.
	 */
	private static function event_files( int $id, array $sum ): void {
		if ( empty( $sum['files'] ) ) {
			return;
		}
		$files    = $sum['files'];
		$per_file = $sum['per_file'] ?? array();
		$diffs    = array_flip( Events::diff_paths( $id ) );
		echo '<h3>' . esc_html__( 'Files', 'update-airlock' ) . '</h3>';
		$stats = $sum['stats'] ?? array();
		/* translators: 1: number of added lines, 2: number of removed lines */
		echo '<p>' . esc_html( sprintf( __( '%1$d lines added and %2$d removed in changed files.', 'update-airlock' ), (int) ( $stats['added_lines'] ?? 0 ), (int) ( $stats['removed_lines'] ?? 0 ) ) ) . '</p>';
		echo '<ul class="updlock-files">';
		$groups = array(
			'added'   => __( 'Added', 'update-airlock' ),
			'changed' => __( 'Changed', 'update-airlock' ),
			'removed' => __( 'Removed', 'update-airlock' ),
		);
		foreach ( $groups as $key => $label ) {
			foreach ( $files[ $key ] ?? array() as $path ) {
				$counts = $per_file[ $path ] ?? null;
				echo '<li><span class="updlock-tag">' . esc_html( $label ) . '</span> <code>' . esc_html( (string) $path ) . '</code>';
				if ( $counts ) {
					echo ' <span class="updlock-muted">+' . (int) $counts[0] . ' -' . (int) $counts[1] . '</span>';
				}
				if ( isset( $diffs[ $path ] ) ) {
					echo ' <a href="' . esc_url(
						Labels::url(
							array(
								'view' => 'diff',
								'id'   => $id,
								'file' => $path,
							)
						)
					) . '">' . esc_html__( 'Diff', 'update-airlock' ) . '</a>';
				}
				echo '</li>';
			}
			$more = (int) ( $files[ $key . '_total' ] ?? 0 ) - count( $files[ $key ] ?? array() );
			if ( $more > 0 ) {
				/* translators: %d: number of files not listed */
				echo '<li class="updlock-muted">' . esc_html( sprintf( __( '%d more not listed', 'update-airlock' ), $more ) ) . '</li>';
			}
		}
		foreach ( $files['renamed'] ?? array() as $from => $to ) {
			echo '<li><span class="updlock-tag">' . esc_html__( 'Renamed', 'update-airlock' ) . '</span> <code>' . esc_html( (string) $from ) . '</code> &rarr; <code>' . esc_html( (string) $to ) . '</code></li>';
		}
		echo '</ul>';
		if ( ! empty( $sum['skipped'] ) ) {
			echo '<p class="updlock-muted">' . esc_html__( 'Compared by hash only (too large or out of time):', 'update-airlock' ) . ' ';
			echo esc_html(
				implode(
					', ',
					array_map(
						static function ( $row ) {
							return $row[0];
						},
						$sum['skipped']
					)
				)
			) . '</p>';
		}
	}

	/**
	 * Check results.
	 *
	 * @param int                 $id  Event id.
	 * @param array<string,mixed> $sum Summary.
	 */
	private static function event_checks( int $id, array $sum ): void {
		$checks = Events::checks( $id );
		if ( ! $checks ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Site checks', 'update-airlock' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'update-airlock' ) . '</th><th>' . esc_html__( 'When', 'update-airlock' ) . '</th><th>' . esc_html__( 'Status', 'update-airlock' ) . '</th><th>' . esc_html__( 'Result', 'update-airlock' ) . '</th></tr></thead><tbody>';
		foreach ( $checks as $row ) {
			$reasons = isset( $row['metrics']['reasons'] ) ? implode( ' ', (array) $row['metrics']['reasons'] ) : '';
			echo '<tr><td><code>' . esc_html( (string) $row['url'] ) . '</code></td><td>' . esc_html( 'retry' === $row['phase'] ? __( 'After rollback', 'update-airlock' ) : __( 'After update', 'update-airlock' ) ) . '</td><td>' . esc_html( (string) $row['status'] ) . '</td><td>' . esc_html( ucfirst( (string) $row['verdict'] ) . ( '' !== $reasons ? ': ' . $reasons : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $sum['log']['fatal'] ) ) {
			echo '<p><strong>' . esc_html__( 'New PHP errors from this code:', 'update-airlock' ) . '</strong></p><pre class="updlock-pre">' . esc_html( implode( "\n", (array) $sum['log']['fatal'] ) ) . '</pre>';
		}
	}

	/**
	 * Header comparison.
	 *
	 * @param array<string,mixed> $sum Summary.
	 */
	private static function event_provenance( array $sum ): void {
		if ( empty( $sum['headers'] ) ) {
			return;
		}
		$old    = (array) ( $sum['headers']['old'] ?? array() );
		$new    = (array) ( $sum['headers']['new'] ?? array() );
		$fields = array(
			'author'     => __( 'Author', 'update-airlock' ),
			'author_uri' => __( 'Author link', 'update-airlock' ),
			'plugin_uri' => __( 'Plugin or theme link', 'update-airlock' ),
			'update_uri' => __( 'Update URI', 'update-airlock' ),
		);
		echo '<h3>' . esc_html__( 'Who publishes it', 'update-airlock' ) . '</h3><table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Installed', 'update-airlock' ) . '</th><th>' . esc_html__( 'New', 'update-airlock' ) . '</th></tr></thead><tbody>';
		foreach ( $fields as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) ( $old[ $key ] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $new[ $key ] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Side-by-side diff of one file.
	 */
	public static function diff(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$hunks = $id && '' !== $file ? Events::diff( $id, $file ) : array();
		echo '<p><a href="' . esc_url(
			Labels::url(
				array(
					'view' => 'event',
					'id'   => $id,
				)
			)
		) . '">&larr; ' . esc_html__( 'Back to the report', 'update-airlock' ) . '</a></p>';
		echo '<h2><code>' . esc_html( $file ) . '</code></h2>';
		if ( ! $hunks ) {
			echo '<p>' . esc_html__( 'No stored diff for this file.', 'update-airlock' ) . '</p>';
			return;
		}
		$flagged = array();
		foreach ( Events::findings( $id ) as $f ) {
			if ( $f['file'] === $file && (int) $f['line'] > 0 ) {
				$flagged[ (int) $f['line'] ] = (string) $f['rule'];
			}
		}
		$catalog = Catalog::all();
		echo '<table class="updlock-diff"><tbody>';
		foreach ( $hunks as $hunk ) {
			echo '<tr class="updlock-diff-gap"><td colspan="4">&hellip;</td></tr>';
			$dels = array();
			$adds = array();
			foreach ( array_merge(
				$hunk,
				array(
					array(
						't' => 'end',
						'a' => 0,
						'b' => 0,
						's' => '',
					),
				)
			) as $row ) {
				if ( '-' === $row['t'] ) {
					$dels[] = $row;
					continue;
				}
				if ( '+' === $row['t'] ) {
					$adds[] = $row;
					continue;
				}
				$n = max( count( $dels ), count( $adds ) );
				for ( $i = 0; $i < $n; $i++ ) {
					self::diff_row( $dels[ $i ] ?? null, $adds[ $i ] ?? null, $flagged, $catalog );
				}
				$dels = array();
				$adds = array();
				if ( '=' === $row['t'] ) {
					self::diff_row( $row, $row, $flagged, $catalog );
				}
			}
		}
		echo '</tbody></table>';
	}

	/**
	 * One row of the side-by-side diff.
	 *
	 * @param array<string,mixed>|null $left    Old side.
	 * @param array<string,mixed>|null $right   New side.
	 * @param array<int,string>        $flagged Flagged line numbers on the new side.
	 * @param array<string,array>      $catalog Rule table.
	 */
	private static function diff_row( $left, $right, array $flagged, array $catalog ): void {
		$lc   = $left && $right && $left === $right ? '' : ( $left ? ' updlock-del' : '' );
		$rc   = $left && $right && $left === $right ? '' : ( $right ? ' updlock-add' : '' );
		$note = '';
		if ( $right && '+' === $right['t'] && isset( $flagged[ $right['b'] ] ) ) {
			$info = $catalog[ $flagged[ $right['b'] ] ] ?? null;
			$note = $info ? ' <span class="updlock-flag">' . esc_html( ucfirst( $info[1] ) ) . '</span>' : '';
		}
		echo '<tr><td class="updlock-ln">' . ( $left && $left['a'] ? (int) $left['a'] : '' ) . '</td><td class="updlock-code' . esc_attr( $lc ) . '"><code>' . ( $left ? esc_html( (string) $left['s'] ) : '' ) . '</code></td>';
		echo '<td class="updlock-ln">' . ( $right && $right['b'] ? (int) $right['b'] : '' ) . '</td><td class="updlock-code' . esc_attr( $rc ) . '"><code>' . ( $right ? esc_html( (string) $right['s'] ) : '' ) . '</code>' . wp_kses_post( $note ) . '</td></tr>';
	}

	/**
	 * Progress page that runs an inspection in small steps.
	 */
	public static function inspect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; the AJAX calls carry a nonce.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$slug = isset( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( $_GET['slug'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<h2>' . esc_html__( 'Inspecting the update', 'update-airlock' ) . '</h2>';
		echo '<p>' . esc_html__( 'Update Airlock downloads the same package WordPress would install, unpacks it in a temporary folder, reads the files (nothing is run) and deletes the folder when done.', 'update-airlock' ) . '</p>';
		echo '<div id="updlock-job" data-kind="' . esc_attr( $kind ) . '" data-slug="' . esc_attr( $slug ) . '"><progress max="100" value="0"></progress> <span class="updlock-status">' . esc_html__( 'Starting...', 'update-airlock' ) . '</span></div>';
		echo '<noscript><p>' . esc_html__( 'This page needs JavaScript. You can also run a normal update; Update Airlock inspects it at install time.', 'update-airlock' ) . '</p></noscript>';
	}
}

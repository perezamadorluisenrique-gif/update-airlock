<?php
/**
 * Plugin settings (one array option).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defaults, reading and sanitizing.
 */
class Settings {

	const OPTION = 'updlock_settings';

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'hold_auto_high'      => 1,
			'confirm_manual_high' => 1,
			'cancel_no_snapshot'  => 1,
			'auto_rollback'       => 1,
			'run_checks'          => 1,
			'keep_snapshots'      => 3,
			'extra_urls'          => '',
			'emails'              => '',
			'keep_data'           => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitizes the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( array( 'hold_auto_high', 'confirm_manual_high', 'cancel_no_snapshot', 'auto_rollback', 'run_checks', 'keep_data' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}
		$out['keep_snapshots'] = max( 1, absint( $input['keep_snapshots'] ?? 3 ) );
		$out['extra_urls']     = self::sanitize_urls( (string) ( $input['extra_urls'] ?? '' ) );
		$out['emails']         = self::sanitize_emails( (string) ( $input['emails'] ?? '' ) );
		return $out;
	}

	/**
	 * Keeps one same-site URL per line.
	 *
	 * @param string $text Raw textarea value.
	 * @return string
	 */
	public static function sanitize_urls( string $text ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$keep = array();
		foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '/' === $line[0] ) {
				$line = home_url( $line );
			}
			$url = esc_url_raw( $line );
			if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
				$keep[ $url ] = $url;
			}
		}
		return implode( "\n", $keep );
	}

	/**
	 * Keeps valid, comma separated addresses.
	 *
	 * @param string $text Raw value.
	 * @return string
	 */
	public static function sanitize_emails( string $text ): string {
		$keep = array();
		foreach ( explode( ',', $text ) as $mail ) {
			$mail = sanitize_email( trim( $mail ) );
			if ( '' !== $mail && is_email( $mail ) ) {
				$keep[ $mail ] = $mail;
			}
		}
		return implode( ', ', $keep );
	}

	/**
	 * Extra URLs to check, one per entry.
	 *
	 * @return string[]
	 */
	public static function extra_urls(): array {
		$text = (string) self::get( 'extra_urls' );
		return '' === trim( $text ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
	}

	/**
	 * Addresses that receive alerts.
	 *
	 * @return string[]
	 */
	public static function recipients(): array {
		$text = trim( (string) self::get( 'emails' ) );
		if ( '' === $text ) {
			return array( (string) get_option( 'admin_email' ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $text ) ) ) );
	}
}

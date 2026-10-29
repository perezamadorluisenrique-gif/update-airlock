<?php
/**
 * Reads new lines from the PHP error log and attributes them to a plugin or theme.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tail-diff of a readable log file.
 */
class Error_Log {

	/**
	 * Path of a readable error log, or an empty string.
	 *
	 * @return string
	 */
	public static function path(): string {
		$path = '';
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$path = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		} else {
			$ini  = ini_get( 'error_log' );
			$path = is_string( $ini ) ? $ini : '';
		}
		return '' !== $path && is_file( $path ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Current size of the log, to remember before an update.
	 *
	 * @return int -1 when no readable log exists.
	 */
	public static function offset(): int {
		$path = self::path();
		return '' === $path ? -1 : (int) filesize( $path );
	}

	/**
	 * Lines added since an offset that mention a folder.
	 *
	 * @param int    $offset Size recorded before the update.
	 * @param string $needle Folder fragment such as /plugins/my-plugin/.
	 * @return array{fatal:string[],other:string[]}
	 */
	public static function since( int $offset, string $needle ): array {
		$out  = array(
			'fatal' => array(),
			'other' => array(),
		);
		$path = self::path();
		if ( $offset < 0 || '' === $path ) {
			return $out;
		}
		$size = (int) filesize( $path );
		if ( $size <= $offset ) {
			return $out;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the tail of the error log.
		if ( ! $handle ) {
			return $out;
		}
		fseek( $handle, max( $offset, $size - 524288 ) );
		$text = (string) fread( $handle, 524288 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads the tail of the error log.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Reads the tail of the error log.
		$needle = str_replace( '\\', '/', $needle );
		foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $line ) {
			if ( '' === $line || false === strpos( str_replace( '\\', '/', $line ), $needle ) ) {
				continue;
			}
			if ( preg_match( '/(Fatal error|Parse error)/i', $line ) ) {
				$out['fatal'][] = substr( $line, 0, 400 );
			} else {
				$out['other'][] = substr( $line, 0, 400 );
			}
		}
		return $out;
	}
}

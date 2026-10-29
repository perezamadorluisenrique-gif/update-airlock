<?php
/**
 * Rules for persistence, privilege changes and update hijacking in added PHP.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect\Rules;

use Updlock\Inspect\Php_Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks at calls whose line is part of the change.
 */
class Persistence {

	/**
	 * Functions and methods that write files.
	 *
	 * @var string[]
	 */
	const WRITERS = array( 'file_put_contents', 'fwrite', 'fputs', 'put_contents', 'copy', 'rename', 'move', 'insert_with_markers' );

	/**
	 * Prefixes of the hooks that filter stored update offers (followed by update_plugins or update_themes).
	 *
	 * @var string[]
	 */
	const UPDATE_HOOK_PREFIXES = array( 'site_transient_', 'pre_set_site_transient_', 'pre_site_transient_', 'transient_' );

	/**
	 * Hooks that change where packages come from.
	 *
	 * @var string[]
	 */
	const SOURCE_HOOKS = array( 'upgrader_pre_download', 'upgrader_package_options', 'plugins_api', 'themes_api' );

	/**
	 * Scans one file.
	 *
	 * @param Php_Scan             $s     Token scan.
	 * @param array<int,bool>|null $added Added line numbers as keys, or null for a new file.
	 * @param string[]             $lines Source lines.
	 * @param string               $file  Relative path.
	 * @return array<int,array<string,mixed>>
	 */
	public static function scan( Php_Scan $s, $added, array $lines, string $file ): array {
		$out = array();
		foreach ( $s->calls() as $call ) {
			if ( ! Catalog::is_added( $added, $call['line'] ) || 'new' === $call['kind'] ) {
				continue;
			}
			$name = $call['name'];
			$line = $call['line'];
			$args = $s->args( $call['open'] );
			$all  = $args ? $s->strings_deep( $args[0][0], $args[ count( $args ) - 1 ][1] ) : array();
			$text = strtolower( implode( ' ', $all ) );

			if ( in_array( $name, self::WRITERS, true ) ) {
				$out = array_merge( $out, self::writes( $s, $call, $args, $text, $file, $lines ) );
			} elseif ( in_array( $name, array( 'wp_insert_user', 'wp_create_user', 'wp_update_user', 'set_role', 'add_role', 'add_cap' ), true ) ) {
				if ( false !== strpos( $text, 'administrator' ) ) {
					$out[] = Catalog::make( 'priv.admin_user', $file, $line, $lines );
				} elseif ( in_array( $name, array( 'wp_insert_user', 'wp_create_user' ), true ) ) {
					$out[] = Catalog::make( 'priv.user_create', $file, $line, $lines );
				}
			} elseif ( in_array( $name, array( 'add_filter', 'add_action' ), true ) && $args ) {
				$out = array_merge( $out, self::hooks( $s, $args, $file, $line, $lines ) );
			} elseif ( 'register_rest_route' === $name && false !== strpos( $text, '__return_true' ) ) {
				$out[] = Catalog::make( 'priv.rest_open', $file, $line, $lines );
			} elseif ( in_array( $name, array( 'wp_schedule_event', 'wp_schedule_single_event' ), true ) ) {
				$out[] = Catalog::make( 'persist.cron', $file, $line, $lines );
			}
		}
		foreach ( $s->toks as $i => $tok ) {
			if ( T_UNSET === $tok['id'] && Catalog::is_added( $added, $tok['line'] ) ) {
				$stmt = $s->statement( $i );
				for ( $j = $i; $j <= $stmt[1]; ++$j ) {
					if ( T_STRING === $s->toks[ $j ]['id'] && 'response' === $s->toks[ $j ]['text'] ) {
						$out[] = Catalog::make( 'hijack.update_hide', $file, $tok['line'], $lines );
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Findings for a file-writing call.
	 *
	 * @param Php_Scan                      $s     Token scan.
	 * @param array<string,mixed>           $call  The call.
	 * @param array<int,array{0:int,1:int}> $args  Argument ranges.
	 * @param string                        $text  Lower-cased literal text of the arguments.
	 * @param string                        $file  Relative path.
	 * @param string[]                      $lines Source lines.
	 * @return array<int,array<string,mixed>>
	 */
	private static function writes( Php_Scan $s, array $call, array $args, string $text, string $file, array $lines ): array {
		$out  = array();
		$line = (int) $call['line'];
		$stmt = $s->statement( (int) $call['i'] );
		$have = array();
		for ( $i = $stmt[0]; $i <= $stmt[1]; ++$i ) {
			if ( T_STRING === $s->toks[ $i ]['id'] ) {
				$have[ $s->toks[ $i ]['text'] ] = true;
			}
		}
		if ( false !== strpos( $text, 'wp-config' ) ) {
			$out[] = Catalog::make( 'persist.wp_config', $file, $line, $lines );
		}
		if ( false !== strpos( $text, '.htaccess' ) || 'insert_with_markers' === $call['name'] ) {
			$out[] = Catalog::make( 'persist.htaccess', $file, $line, $lines );
		}
		if ( false !== strpos( $text, 'mu-plugins' ) || isset( $have['WPMU_PLUGIN_DIR'] ) ) {
			$out[] = Catalog::make( 'persist.mu_plugin', $file, $line, $lines );
		}
		if ( isset( $have['WP_PLUGIN_DIR'] ) || isset( $have['get_theme_root'] ) || isset( $have['get_theme_roots'] ) ) {
			$out[] = Catalog::make( 'persist.other_plugin', $file, $line, $lines );
		}
		if ( $args && 'copy' !== $call['name'] && preg_match( '/\.(php|phtml|php5)\b/', strtolower( implode( ' ', $s->strings_deep( $args[0][0], $args[0][1] ) ) ) ) ) {
			$out[] = Catalog::make( 'persist.write_php', $file, $line, $lines );
		}
		return $out;
	}

	/**
	 * Findings for add_filter() and add_action() calls.
	 *
	 * @param Php_Scan                      $s     Token scan.
	 * @param array<int,array{0:int,1:int}> $args  Argument ranges.
	 * @param string                        $file  Relative path.
	 * @param int                           $line  Line.
	 * @param string[]                      $lines Source lines.
	 * @return array<int,array<string,mixed>>
	 */
	private static function hooks( Php_Scan $s, array $args, string $file, int $line, array $lines ): array {
		$hook = $s->strings( $args[0][0], $args[0][1] );
		$hook = $hook ? $hook[0] : '';
		if ( in_array( $hook, array( 'pre_user_query', 'users_list_table_query_args' ), true ) ) {
			return array( Catalog::make( 'priv.hide_users', $file, $line, $lines, $hook ) );
		}
		if ( 'all_plugins' === $hook ) {
			return array( Catalog::make( 'priv.hide_self', $file, $line, $lines, $hook ) );
		}
		if ( self::is_update_hook( $hook ) ) {
			return array( Catalog::make( 'hijack.update_filter', $file, $line, $lines, $hook ) );
		}
		if ( in_array( $hook, self::SOURCE_HOOKS, true ) ) {
			return array( Catalog::make( 'hijack.update_source', $file, $line, $lines, $hook ) );
		}
		return array();
	}

	/**
	 * Whether a hook name filters the stored update offers (prefix plus update_plugins or update_themes).
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private static function is_update_hook( string $hook ): bool {
		foreach ( self::UPDATE_HOOK_PREFIXES as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) && in_array( substr( $hook, strlen( $prefix ) ), array( 'update_plugins', 'update_themes' ), true ) ) {
				return true;
			}
		}
		return false;
	}
}

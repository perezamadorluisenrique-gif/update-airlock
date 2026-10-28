<?php
/**
 * Finds outbound hosts in added code.
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
 * Host extraction and the new-host rules.
 */
class Network {

	/**
	 * PHP functions whose arguments name a remote location.
	 *
	 * @var string[]
	 */
	const FUNCS = array(
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'wp_remote_head',
		'wp_remote_fopen',
		'wp_safe_remote_get',
		'wp_safe_remote_post',
		'wp_safe_remote_request',
		'wp_safe_remote_head',
		'curl_init',
		'curl_setopt',
		'curl_setopt_array',
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'file_get_contents',
		'fopen',
		'file',
		'copy',
		'download_url',
		'get_headers',
		'simplexml_load_file',
		'fetch_feed',
		'wp_oembed_get',
		'getimagesize',
		'readfile',
		'soapclient',
	);

	/**
	 * Hosts ignored everywhere (documentation, namespaces, licences).
	 *
	 * @var string[]
	 */
	const IGNORE = array( 'w3.org', 'schema.org', 'example.com', 'example.org', 'example.net', 'gnu.org', 'opensource.org', 'apache.org', 'creativecommons.org', 'php.net' );

	/**
	 * Hosts that belong to the WordPress project or its avatar service.
	 *
	 * @var string[]
	 */
	const KNOWN = array( 'wordpress.org', 'w.org', 'gravatar.com' );

	/**
	 * Pulls host names out of a piece of text.
	 *
	 * @param string $text   Text.
	 * @param bool   $scheme Also accept protocol-relative links.
	 * @return string[]
	 */
	public static function extract( string $text, bool $scheme = false ): array {
		$out     = array();
		$pattern = $scheme ? '#(?:(?:https?|wss?|ftp):)?//(\[[0-9a-f:]+\]|[a-z0-9][a-z0-9.\-]*)(?::\d+)?(?=[/?\#"\'\s)>]|$)#i' : '#(?:https?|wss?|ftp)://(\[[0-9a-f:]+\]|[a-z0-9][a-z0-9.\-]*)(?::\d+)?(?=[/?\#"\'\s)>]|$)#i';
		if ( preg_match_all( $pattern, $text, $m ) ) {
			foreach ( $m[1] as $host ) {
				$host = strtolower( rtrim( $host, '.' ) );
				if ( self::ignored( $host ) ) {
					continue;
				}
				$out[ $host ] = $host;
			}
		}
		return array_values( $out );
	}

	/**
	 * Whether a host is on the ignore list or has no dot (and is not an address).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function ignored( string $host ): bool {
		if ( '' === $host || '$' === $host[0] ) {
			return true;
		}
		foreach ( self::IGNORE as $ignored ) {
			if ( $host === $ignored || substr( $host, -strlen( $ignored ) - 1 ) === '.' . $ignored ) {
				return true;
			}
		}
		if ( self::is_private_ip( $host ) ) {
			return true;
		}
		return false === strpos( $host, '.' ) && '[' !== $host[0];
	}

	/**
	 * Whether a host is a loopback or private IPv4 address.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_private_ip( string $host ): bool {
		return (bool) preg_match( '/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/', $host );
	}

	/**
	 * Whether a host is a bare IP address.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_ip( string $host ): bool {
		return (bool) preg_match( '/^(\d{1,3}\.){3}\d{1,3}$/', $host ) || '[' === substr( $host, 0, 1 );
	}

	/**
	 * Hosts named in a PHP file, tagged by where they appear.
	 *
	 * @param Php_Scan             $s     Token scan.
	 * @param array<int,bool>|null $added Added line numbers as keys, or null for a whole file.
	 * @return array<int,array{host:string,line:int,tier:string}>
	 */
	public static function php_hosts( Php_Scan $s, $added ): array {
		$out     = array();
		$in_call = array();
		foreach ( $s->calls() as $call ) {
			if ( ! in_array( $call['name'], self::FUNCS, true ) || ! Catalog::is_added( $added, $call['line'] ) ) {
				continue;
			}
			if ( 'method' === $call['kind'] || 'static' === $call['kind'] ) {
				continue;
			}
			foreach ( $s->args( $call['open'] ) as $arg ) {
				$strings = $s->strings( $arg[0], $arg[1] );
				for ( $i = $arg[0]; $i <= $arg[1]; ++$i ) {
					if ( T_VARIABLE === $s->toks[ $i ]['id'] ) {
						$src = $s->assignment_of( $s->toks[ $i ]['text'], $i );
						if ( $src ) {
							$strings = array_merge( $strings, $s->strings( $src[0], $src[1] ) );
						}
					}
				}
				foreach ( self::extract( implode( ' ', $strings ) ) as $host ) {
					$out[]                                  = array(
						'host' => $host,
						'line' => $call['line'],
						'tier' => 'call',
					);
					$in_call[ $host . '|' . $call['line'] ] = true;
				}
			}
		}
		foreach ( $s->toks as $tok ) {
			if ( T_CONSTANT_ENCAPSED_STRING !== $tok['id'] || ! Catalog::is_added( $added, $tok['line'] ) ) {
				continue;
			}
			foreach ( self::extract( Php_Scan::unquote( $tok['text'] ) ) as $host ) {
				if ( ! isset( $in_call[ $host . '|' . $tok['line'] ] ) ) {
					$out[] = array(
						'host' => $host,
						'line' => $tok['line'],
						'tier' => 'literal',
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Hosts used by browser code (JavaScript, or script tags written in PHP/HTML).
	 *
	 * @param string[]             $lines Source lines.
	 * @param array<int,bool>|null $added Added line numbers as keys, or null for a whole file.
	 * @param bool                 $js    Whether the file is JavaScript.
	 * @return array<int,array{host:string,line:int,tier:string}>
	 */
	public static function text_hosts( array $lines, $added, bool $js ): array {
		$out = array();
		foreach ( $lines as $idx => $text ) {
			$line = $idx + 1;
			if ( ! Catalog::is_added( $added, $line ) ) {
				continue;
			}
			$trim = ltrim( $text );
			if ( '//' === substr( $trim, 0, 2 ) || '*' === substr( $trim, 0, 1 ) || '/*' === substr( $trim, 0, 2 ) ) {
				continue;
			}
			$hit = false;
			if ( $js && preg_match( '/\b(fetch|XMLHttpRequest|sendBeacon|importScripts|WebSocket|EventSource|import|\.open|\.ajax|\.get|\.post|\.getScript|\.src|src|href)\b/', $text ) ) {
				$hit = true;
			}
			if ( preg_match( '/<\s*(script|link|iframe)\b[^>]*\b(src|href)\s*=/i', $text ) ) {
				$hit = true;
			}
			if ( ! $hit ) {
				continue;
			}
			foreach ( self::extract( $text, true ) as $host ) {
				$out[] = array(
					'host' => $host,
					'line' => $line,
					'tier' => 'browser',
				);
			}
		}
		return $out;
	}

	/**
	 * Turns hosts that are not in the installed version into findings.
	 *
	 * @param array<int,array{host:string,line:int,tier:string}> $hosts Hosts from php_hosts() or text_hosts().
	 * @param array<string,bool>                                 $old   Hosts present in the installed version.
	 * @param string[]                                           $lines Source lines.
	 * @param string                                             $file  Relative path.
	 * @return array<int,array<string,mixed>>
	 */
	public static function findings( array $hosts, array $old, array $lines, string $file ): array {
		$out = array();
		foreach ( $hosts as $item ) {
			$host = $item['host'];
			if ( isset( $old[ $host ] ) ) {
				continue;
			}
			if ( self::is_ip( $host ) ) {
				$rule = 'net.ip_host';
			} elseif ( 'literal' === $item['tier'] ) {
				$rule = 'net.url_literal';
			} elseif ( self::is_known( $host ) ) {
				$rule = 'net.new_host_low';
			} elseif ( 'browser' === $item['tier'] ) {
				$rule = 'js.new_host';
			} else {
				$rule = 'net.new_host';
			}
			$out[] = Catalog::make( $rule, $file, $item['line'], $lines, $host );
		}
		return $out;
	}

	/**
	 * Whether a host belongs to the WordPress project sites.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_known( string $host ): bool {
		foreach ( self::KNOWN as $known ) {
			if ( $host === $known || substr( $host, -strlen( $known ) - 1 ) === '.' . $known ) {
				return true;
			}
		}
		return false;
	}
}

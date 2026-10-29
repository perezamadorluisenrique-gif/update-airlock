<?php
/**
 * Rules for code execution, deserialization and obfuscation in added PHP.
 *
 * The function names below are strings in tables; this plugin never calls them.
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
class Code {

	/**
	 * Functions that run a shell command.
	 *
	 * @var string[]
	 */
	const SHELL = array( 'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec' );

	/**
	 * Functions that decode a packed value.
	 *
	 * @var string[]
	 */
	const DECODE = array( 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'strrev', 'hex2bin', 'convert_uudecode', 'bzdecompress' );

	/**
	 * Decode functions that are harmless alone (listed only inside a chain).
	 *
	 * @var string[]
	 */
	const CHAIN_ONLY = array( 'strrev', 'str_rot13' );

	/**
	 * Functions that call a callable passed as a value.
	 *
	 * @var string[]
	 */
	const CALLERS = array( 'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array' );

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
		$out   = array();
		$seen  = array();
		$stmts = array();
		foreach ( $s->calls() as $call ) {
			if ( ! Catalog::is_added( $added, $call['line'] ) ) {
				continue;
			}
			$name = $call['name'];
			$args = $s->args( $call['open'] );
			$line = $call['line'];
			if ( 'method' === $call['kind'] || 'static' === $call['kind'] || 'new' === $call['kind'] ) {
				continue;
			}

			if ( 'eval' === $name ) {
				$out[] = Catalog::make( 'exec.eval', $file, $line, $lines );
			} elseif ( 'assert' === $name && ( ! $args || ! $s->is_literal( $args[0][0], $args[0][1] ) ) ) {
				$out[] = Catalog::make( 'exec.assert_dynamic', $file, $line, $lines );
			} elseif ( 'create_function' === $name ) {
				$out[] = Catalog::make( 'exec.create_function', $file, $line, $lines );
			} elseif ( 'preg_replace' === $name && $args && self::has_e_modifier( $s, $args[0] ) ) {
				$out[] = Catalog::make( 'exec.preg_e', $file, $line, $lines );
			} elseif ( in_array( $name, self::CALLERS, true ) && $args && $s->tainted( $args[0][0], $args[0][1] ) ) {
				$out[] = Catalog::make( 'exec.request_call', $file, $line, $lines );
			} elseif ( in_array( $name, self::SHELL, true ) ) {
				$tainted = $args && $s->tainted( $args[0][0], $args[0][1] );
				$out[]   = Catalog::make( $tainted ? 'exec.shell_request' : 'exec.shell', $file, $line, $lines );
			} elseif ( 'unserialize' === $name || 'maybe_unserialize' === $name ) {
				if ( $args && ! $s->is_literal( $args[0][0], $args[0][1] ) ) {
					$out[] = Catalog::make( $s->tainted( $args[0][0], $args[0][1] ) ? 'deser.remote' : 'deser.dynamic', $file, $line, $lines );
				}
			}

			$stmt = $s->statement( $call['i'] );
			$key  = $stmt[0];
			if ( in_array( $name, self::DECODE, true ) ) {
				$stmts[ $key ]['decode'][] = $name;
				$stmts[ $key ]['line']     = $stmts[ $key ]['line'] ?? $line;
			}
			if ( 'chr' === $name ) {
				$stmts[ $key ]['chr']  = ( $stmts[ $key ]['chr'] ?? 0 ) + 1;
				$stmts[ $key ]['line'] = $stmts[ $key ]['line'] ?? $line;
			}
		}

		foreach ( $stmts as $info ) {
			$decode = $info['decode'] ?? array();
			if ( count( $decode ) >= 2 ) {
				$out[] = Catalog::make( 'obf.decode_chain', $file, $info['line'], $lines, implode( ' > ', $decode ) );
			} elseif ( 1 === count( $decode ) && ! in_array( $decode[0], self::CHAIN_ONLY, true ) ) {
				$out[] = Catalog::make( 'obf.decode', $file, $info['line'], $lines, $decode[0] );
			}
			if ( ( $info['chr'] ?? 0 ) >= 6 ) {
				$out[] = Catalog::make( 'obf.chr_concat', $file, $info['line'], $lines );
			}
		}

		$count = count( $s->toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$tok = $s->toks[ $i ];
			if ( ! Catalog::is_added( $added, $tok['line'] ) ) {
				continue;
			}
			if ( '`' === $tok['id'] ) {
				$out[] = Catalog::make( 'exec.shell', $file, $tok['line'], $lines );
			} elseif ( in_array( $tok['id'], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$stmt = $s->statement( $i );
				$text = implode( ' ', $s->strings( $i, $stmt[1] ) );
				if ( preg_match( '#(https?|ftp|data|php)://#i', $text ) || $s->reads_request( $i, $stmt[1] ) ) {
					$out[] = Catalog::make( 'exec.include_remote', $file, $tok['line'], $lines );
				}
			} elseif ( T_VARIABLE === $tok['id'] && in_array( $tok['text'], array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE' ), true ) && self::called_after_index( $s, $i ) ) {
				$out[] = Catalog::make( 'exec.request_call', $file, $tok['line'], $lines );
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $tok['id'] && strlen( $tok['text'] ) >= 400 && self::packed( Php_Scan::unquote( $tok['text'] ) ) ) {
				$key = $tok['line'];
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$out[]        = Catalog::make( 'obf.long_string', $file, $tok['line'], $lines );
				}
			}
		}
		return $out;
	}

	/**
	 * Whether `$_X['key'](...)` follows a superglobal at an index.
	 *
	 * @param Php_Scan $s Token scan.
	 * @param int      $i Index of the superglobal.
	 * @return bool
	 */
	private static function called_after_index( Php_Scan $s, int $i ): bool {
		if ( ! isset( $s->toks[ $i + 1 ] ) || '[' !== $s->toks[ $i + 1 ]['id'] ) {
			return false;
		}
		$depth = 0;
		$n     = count( $s->toks );
		for ( $j = $i + 1; $j < $n; ++$j ) {
			if ( '[' === $s->toks[ $j ]['id'] ) {
				++$depth;
			} elseif ( ']' === $s->toks[ $j ]['id'] ) {
				--$depth;
				if ( 0 === $depth ) {
					return isset( $s->toks[ $j + 1 ] ) && '(' === $s->toks[ $j + 1 ]['id'];
				}
			}
		}
		return false;
	}

	/**
	 * Whether a regex literal ends with a modifier set that includes the code-running one.
	 *
	 * @param Php_Scan           $s   Token scan.
	 * @param array{0:int,1:int} $arg Argument range.
	 * @return bool
	 */
	private static function has_e_modifier( Php_Scan $s, array $arg ): bool {
		if ( ! $s->is_literal( $arg[0], $arg[1] ) ) {
			return false;
		}
		$pattern = Php_Scan::unquote( $s->toks[ $arg[0] ]['text'] );
		if ( strlen( $pattern ) < 3 ) {
			return false;
		}
		$delimiter = $pattern[0];
		$end       = strrpos( $pattern, $delimiter );
		if ( false === $end || 0 === $end ) {
			return false;
		}
		return false !== strpos( substr( $pattern, $end + 1 ), 'e' );
	}

	/**
	 * Long text with high entropy and almost no spaces looks packed.
	 *
	 * @param string $text String content.
	 * @return bool
	 */
	public static function packed( string $text ): bool {
		$len = strlen( $text );
		if ( $len < 400 || substr_count( $text, ' ' ) > $len * 0.03 ) {
			return false;
		}
		$freq = count_chars( $text, 1 );
		$bits = 0.0;
		foreach ( $freq as $count ) {
			$p     = $count / $len;
			$bits -= $p * log( $p, 2 );
		}
		return $bits >= 3.9;
	}
}

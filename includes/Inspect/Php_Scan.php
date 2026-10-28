<?php
/**
 * Reads PHP source as tokens. Nothing here ever includes or runs the code it reads.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Significant tokens (no whitespace or comments) with helpers for calls, arguments and statements.
 */
class Php_Scan {

	/**
	 * Tokens as arrays: id (int or the character), text and line.
	 *
	 * @var array<int,array{id:int|string,text:string,line:int}>
	 */
	public $toks = array();

	/**
	 * Calls found in the source.
	 *
	 * @var array<int,array{name:string,line:int,i:int,kind:string,open:int}>
	 */
	private $calls = null;

	/**
	 * Builds the token list.
	 *
	 * @param string $code PHP source.
	 */
	public function __construct( string $code ) {
		$skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML );
		$line = 1;
		foreach ( @token_get_all( $code ) as $tok ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Tokenizer notices on broken source must not surface.
			if ( is_array( $tok ) ) {
				$line = (int) $tok[2];
				if ( in_array( $tok[0], $skip, true ) ) {
					$line += substr_count( $tok[1], "\n" );
					continue;
				}
				$this->toks[] = array(
					'id'   => $tok[0],
					'text' => $tok[1],
					'line' => $line,
				);
				$line        += substr_count( $tok[1], "\n" );
			} else {
				$this->toks[] = array(
					'id'   => $tok,
					'text' => $tok,
					'line' => $line,
				);
			}
		}
	}

	/**
	 * Whether a token is a (possibly qualified) name.
	 *
	 * @param int $i Token index.
	 * @return bool
	 */
	private function is_name( int $i ): bool {
		if ( ! isset( $this->toks[ $i ] ) ) {
			return false;
		}
		$id = $this->toks[ $i ]['id'];
		if ( T_STRING === $id ) {
			return true;
		}
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $const ) {
			if ( defined( $const ) && constant( $const ) === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lower-cased last segment of a name token.
	 *
	 * @param int $i Token index.
	 * @return string
	 */
	private function base_name( int $i ): string {
		$parts = explode( '\\', $this->toks[ $i ]['text'] );
		return strtolower( (string) end( $parts ) );
	}

	/**
	 * Every call-like construct: functions, methods, static calls, `new`, and language constructs.
	 *
	 * @return array<int,array{name:string,line:int,i:int,kind:string,open:int}>
	 */
	public function calls(): array {
		if ( null !== $this->calls ) {
			return $this->calls;
		}
		$this->calls = array();
		$n           = count( $this->toks );
		for ( $i = 0; $i < $n; ++$i ) {
			$id = $this->toks[ $i ]['id'];
			if ( T_EVAL === $id && isset( $this->toks[ $i + 1 ] ) && '(' === $this->toks[ $i + 1 ]['id'] ) {
				$this->calls[] = array(
					'name' => strtolower( $this->toks[ $i ]['text'] ),
					'line' => $this->toks[ $i ]['line'],
					'i'    => $i,
					'kind' => 'construct',
					'open' => $i + 1,
				);
				continue;
			}
			if ( ! $this->is_name( $i ) || ! isset( $this->toks[ $i + 1 ] ) || '(' !== $this->toks[ $i + 1 ]['id'] ) {
				continue;
			}
			$prev = $i > 0 ? $this->toks[ $i - 1 ]['id'] : '';
			if ( T_FUNCTION === $prev || T_CONST === $prev ) {
				continue;
			}
			$kind = 'func';
			if ( T_OBJECT_OPERATOR === $prev || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) === $prev ) ) {
				$kind = 'method';
			} elseif ( T_DOUBLE_COLON === $prev ) {
				$kind = 'static';
			} elseif ( T_NEW === $prev ) {
				$kind = 'new';
			}
			$this->calls[] = array(
				'name' => $this->base_name( $i ),
				'line' => $this->toks[ $i ]['line'],
				'i'    => $i,
				'kind' => $kind,
				'open' => $i + 1,
			);
		}
		return $this->calls;
	}

	/**
	 * Top-level arguments of a call as token index ranges.
	 *
	 * @param int $open Index of the opening parenthesis.
	 * @return array<int,array{0:int,1:int}> Inclusive [from, to] ranges.
	 */
	public function args( int $open ): array {
		$depth = 0;
		$start = $open + 1;
		$out   = array();
		$n     = count( $this->toks );
		for ( $i = $open; $i < $n; ++$i ) {
			$id = $this->toks[ $i ]['id'];
			if ( '(' === $id || '[' === $id || '{' === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
			} elseif ( ')' === $id || ']' === $id || '}' === $id ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( $i > $start ) {
						$out[] = array( $start, $i - 1 );
					}
					return $out;
				}
			} elseif ( ',' === $id && 1 === $depth ) {
				$out[] = array( $start, $i - 1 );
				$start = $i + 1;
			}
		}
		return $out;
	}

	/**
	 * Index range [first, last] of the statement holding a token.
	 *
	 * @param int $i Token index.
	 * @return array{0:int,1:int}
	 */
	public function statement( int $i ): array {
		$n     = count( $this->toks );
		$first = $i;
		$depth = 0;
		for ( $j = $i; $j >= 0; --$j ) {
			$id = $this->toks[ $j ]['id'];
			if ( ')' === $id || ']' === $id ) {
				++$depth;
			} elseif ( '(' === $id || '[' === $id ) {
				--$depth;
			} elseif ( $depth <= 0 && ( ';' === $id || '{' === $id || '}' === $id ) ) {
				break;
			}
			$first = $j;
		}
		$last  = $i;
		$depth = 0;
		for ( $j = $i; $j < $n; ++$j ) {
			$id = $this->toks[ $j ]['id'];
			if ( '(' === $id || '[' === $id ) {
				++$depth;
			} elseif ( ')' === $id || ']' === $id ) {
				--$depth;
			}
			$last = $j;
			if ( $depth <= 0 && ';' === $id ) {
				break;
			}
		}
		return array( $first, $last );
	}

	/**
	 * Decoded text of the string literals inside an index range.
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return string[]
	 */
	public function strings( int $from, int $to ): array {
		$out = array();
		for ( $i = $from; $i <= $to && isset( $this->toks[ $i ] ); ++$i ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $this->toks[ $i ]['id'] ) {
				$out[] = self::unquote( $this->toks[ $i ]['text'] );
			} elseif ( T_ENCAPSED_AND_WHITESPACE === $this->toks[ $i ]['id'] ) {
				$out[] = $this->toks[ $i ]['text'];
			}
		}
		return $out;
	}

	/**
	 * Strips the quotes from a string literal token.
	 *
	 * @param string $text Token text.
	 * @return string
	 */
	public static function unquote( string $text ): string {
		$q = substr( $text, 0, 1 );
		if ( ( "'" === $q || '"' === $q ) && strlen( $text ) >= 2 ) {
			return substr( $text, 1, -1 );
		}
		return $text;
	}

	/**
	 * Whether a range is a plain literal (one string or number, or true/false/null).
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return bool
	 */
	public function is_literal( int $from, int $to ): bool {
		if ( $from !== $to || ! isset( $this->toks[ $from ] ) ) {
			return false;
		}
		$tok = $this->toks[ $from ];
		if ( in_array( $tok['id'], array( T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER ), true ) ) {
			return true;
		}
		return T_STRING === $tok['id'] && in_array( strtolower( $tok['text'] ), array( 'true', 'false', 'null' ), true );
	}

	/**
	 * Names of the functions called inside an index range.
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return string[]
	 */
	public function call_names( int $from, int $to ): array {
		$out = array();
		foreach ( $this->calls() as $call ) {
			if ( $call['i'] >= $from && $call['i'] <= $to ) {
				$out[] = $call['name'];
			}
		}
		return $out;
	}

	/**
	 * Whether a range reads request data directly.
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return bool
	 */
	public function reads_request( int $from, int $to ): bool {
		for ( $i = $from; $i <= $to && isset( $this->toks[ $i ] ); ++$i ) {
			if ( T_VARIABLE === $this->toks[ $i ]['id'] && in_array( $this->toks[ $i ]['text'], array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a range traces back to remote data or request data: directly, or through variables assigned earlier.
	 *
	 * @param int $from  First index.
	 * @param int $to    Last index.
	 * @param int $depth Remaining hops through variables.
	 * @return bool
	 */
	public function tainted( int $from, int $to, int $depth = 3 ): bool {
		$remote = array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request', 'wp_remote_retrieve_body', 'curl_exec', 'stream_get_contents', 'fgets', 'fread', 'file_get_contents', 'file', 'get_headers' );
		if ( $this->reads_request( $from, $to ) ) {
			return true;
		}
		foreach ( $this->call_names( $from, $to ) as $name ) {
			if ( in_array( $name, $remote, true ) ) {
				return true;
			}
		}
		if ( $depth <= 0 ) {
			return false;
		}
		for ( $i = $from; $i <= $to; ++$i ) {
			if ( T_VARIABLE !== $this->toks[ $i ]['id'] ) {
				continue;
			}
			$src = $this->assignment_of( $this->toks[ $i ]['text'], $from );
			if ( $src && $this->tainted( $src[0], $src[1], $depth - 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Right-hand side range of the closest earlier `$var = ...;` before an index.
	 *
	 * @param string $variable Variable including the dollar sign.
	 * @param int    $before Search backward from here.
	 * @return array{0:int,1:int}|null
	 */
	public function assignment_of( string $variable, int $before ) {
		for ( $i = $before - 1; $i >= 0; --$i ) {
			if ( T_VARIABLE === $this->toks[ $i ]['id'] && $variable === $this->toks[ $i ]['text'] && isset( $this->toks[ $i + 1 ] ) && in_array( $this->toks[ $i + 1 ]['id'], array( '=', T_CONCAT_EQUAL ), true ) ) {
				$stmt = $this->statement( $i );
				return array( $i + 2, $stmt[1] );
			}
		}
		return null;
	}

	/**
	 * String literals in a range, plus those of the variables used there (one hop back).
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return string[]
	 */
	public function strings_deep( int $from, int $to ): array {
		$out = $this->strings( $from, $to );
		for ( $i = $from; $i <= $to && isset( $this->toks[ $i ] ); ++$i ) {
			if ( T_VARIABLE === $this->toks[ $i ]['id'] ) {
				$src = $this->assignment_of( $this->toks[ $i ]['text'], $i );
				if ( $src ) {
					$out = array_merge( $out, $this->strings( $src[0], $src[1] ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Source text of a token range, for excerpts.
	 *
	 * @param int $from First index.
	 * @param int $to   Last index.
	 * @return string
	 */
	public function text( int $from, int $to ): string {
		$out = '';
		for ( $i = $from; $i <= $to && isset( $this->toks[ $i ] ); ++$i ) {
			$out .= $this->toks[ $i ]['text'] . ' ';
		}
		return trim( $out );
	}
}

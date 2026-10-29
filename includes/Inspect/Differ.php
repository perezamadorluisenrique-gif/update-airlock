<?php
/**
 * File-level and line-level diffing (pure PHP, no WordPress calls).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares two file lists and two texts.
 */
class Differ {

	/**
	 * Largest edit distance computed exactly; beyond it the middle is shown as replaced.
	 */
	const MAX_EDIT = 2500;

	/**
	 * Classifies files by hash.
	 *
	 * @param array<string,string> $old_hashes Relative path => hash for the installed version.
	 * @param array<string,string> $new_hashes Relative path => hash for the new version.
	 * @return array{added:string[],removed:string[],changed:string[],renamed:array<string,string>}
	 */
	public static function files( array $old_hashes, array $new_hashes ): array {
		$added   = array_values( array_diff( array_keys( $new_hashes ), array_keys( $old_hashes ) ) );
		$removed = array_values( array_diff( array_keys( $old_hashes ), array_keys( $new_hashes ) ) );
		$changed = array();
		foreach ( $new_hashes as $path => $hash ) {
			if ( isset( $old_hashes[ $path ] ) && $old_hashes[ $path ] !== $hash ) {
				$changed[] = $path;
			}
		}
		$renamed = array();
		$by_hash = array();
		foreach ( $removed as $path ) {
			$by_hash[ $old_hashes[ $path ] ][] = $path;
		}
		foreach ( $added as $path ) {
			$hash = $new_hashes[ $path ];
			if ( ! empty( $by_hash[ $hash ] ) ) {
				$renamed[ array_shift( $by_hash[ $hash ] ) ] = $path;
			}
		}
		if ( $renamed ) {
			$added   = array_values( array_diff( $added, array_values( $renamed ) ) );
			$removed = array_values( array_diff( $removed, array_keys( $renamed ) ) );
		}
		sort( $added );
		sort( $removed );
		sort( $changed );
		return array(
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'renamed' => $renamed,
		);
	}

	/**
	 * Splits text into lines without the line ending.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function split( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		return preg_split( '/\r\n|\n|\r/', $text );
	}

	/**
	 * Line diff (Myers) after trimming the common head and tail.
	 *
	 * @param string[] $a Old lines.
	 * @param string[] $b New lines.
	 * @return array<int,array{t:string,a:int,b:int,s:string}> Rows: t is '=', '-' or '+'; a and b are 1-based line numbers (0 when absent).
	 */
	public static function lines( array $a, array $b ): array {
		$n    = count( $a );
		$m    = count( $b );
		$head = 0;
		while ( $head < $n && $head < $m && $a[ $head ] === $b[ $head ] ) {
			++$head;
		}
		$tail = 0;
		while ( $tail < $n - $head && $tail < $m - $head && $a[ $n - 1 - $tail ] === $b[ $m - 1 - $tail ] ) {
			++$tail;
		}
		$rows = array();
		for ( $i = 0; $i < $head; ++$i ) {
			$rows[] = array(
				't' => '=',
				'a' => $i + 1,
				'b' => $i + 1,
				's' => $a[ $i ],
			);
		}
		$mid_a = array_slice( $a, $head, $n - $head - $tail );
		$mid_b = array_slice( $b, $head, $m - $head - $tail );
		foreach ( self::myers( $mid_a, $mid_b ) as $op ) {
			$rows[] = array(
				't' => $op[0],
				'a' => '+' === $op[0] ? 0 : $head + $op[1] + 1,
				'b' => '-' === $op[0] ? 0 : $head + $op[2] + 1,
				's' => $op[3],
			);
		}
		for ( $i = 0; $i < $tail; ++$i ) {
			$rows[] = array(
				't' => '=',
				'a' => $n - $tail + $i + 1,
				'b' => $m - $tail + $i + 1,
				's' => $a[ $n - $tail + $i ],
			);
		}
		return $rows;
	}

	/**
	 * Core of the diff: returns [type, aIndex, bIndex, text] tuples (0-based indexes).
	 *
	 * @param string[] $a Old lines.
	 * @param string[] $b New lines.
	 * @return array<int,array{0:string,1:int,2:int,3:string}>
	 */
	private static function myers( array $a, array $b ): array {
		$n = count( $a );
		$m = count( $b );
		if ( 0 === $n && 0 === $m ) {
			return array();
		}
		if ( 0 === $n || 0 === $m ) {
			return self::replace_all( $a, $b );
		}
		$v     = array( 1 => 0 );
		$trace = array();
		$found = false;
		$max   = min( $n + $m, self::MAX_EDIT );
		for ( $d = 0; $d <= $max && ! $found; ++$d ) {
			$trace[] = $v;
			for ( $k = -$d; $k <= $d; $k += 2 ) {
				if ( -$d === $k || ( $d !== $k && $v[ $k - 1 ] < $v[ $k + 1 ] ) ) {
					$x = $v[ $k + 1 ];
				} else {
					$x = $v[ $k - 1 ] + 1;
				}
				$y = $x - $k;
				while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
					++$x;
					++$y;
				}
				$v[ $k ] = $x;
				if ( $x >= $n && $y >= $m ) {
					$found = true;
					break;
				}
			}
		}
		if ( ! $found ) {
			return self::replace_all( $a, $b );
		}
		$ops = array();
		$x   = $n;
		$y   = $m;
		for ( $d = count( $trace ) - 1; $d >= 0; --$d ) {
			$vv = $trace[ $d ];
			$k  = $x - $y;
			if ( -$d === $k || ( $d !== $k && $vv[ $k - 1 ] < $vv[ $k + 1 ] ) ) {
				$prev_k = $k + 1;
			} else {
				$prev_k = $k - 1;
			}
			$prev_x = $vv[ $prev_k ];
			$prev_y = $prev_x - $prev_k;
			while ( $x > $prev_x && $y > $prev_y ) {
				--$x;
				--$y;
				$ops[] = array( '=', $x, $y, $a[ $x ] );
			}
			if ( $d > 0 ) {
				if ( $x === $prev_x ) {
					$ops[] = array( '+', $prev_x, $prev_y, $b[ $prev_y ] );
				} else {
					$ops[] = array( '-', $prev_x, $prev_y, $a[ $prev_x ] );
				}
			}
			$x = $prev_x;
			$y = $prev_y;
		}
		return array_reverse( $ops );
	}

	/**
	 * Shows every old line removed and every new line added.
	 *
	 * @param string[] $a Old lines.
	 * @param string[] $b New lines.
	 * @return array<int,array{0:string,1:int,2:int,3:string}>
	 */
	private static function replace_all( array $a, array $b ): array {
		$ops = array();
		foreach ( $a as $i => $line ) {
			$ops[] = array( '-', $i, 0, $line );
		}
		foreach ( $b as $j => $line ) {
			$ops[] = array( '+', count( $a ), $j, $line );
		}
		return $ops;
	}

	/**
	 * Numbers (1-based, in the new text) of the added lines.
	 *
	 * @param array<int,array{t:string,a:int,b:int,s:string}> $rows Rows from lines().
	 * @return int[]
	 */
	public static function added_numbers( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( '+' === $row['t'] ) {
				$out[] = $row['b'];
			}
		}
		return $out;
	}

	/**
	 * Groups rows into hunks with context lines and skips long unchanged stretches.
	 *
	 * @param array<int,array{t:string,a:int,b:int,s:string}> $rows    Rows from lines().
	 * @param int                                             $context Context lines around a change.
	 * @return array<int,array<int,array{t:string,a:int,b:int,s:string}>>
	 */
	public static function hunks( array $rows, int $context = 3 ): array {
		$keep  = array();
		$count = count( $rows );
		foreach ( $rows as $i => $row ) {
			if ( '=' === $row['t'] ) {
				continue;
			}
			$to = min( $count - 1, $i + $context );
			for ( $j = max( 0, $i - $context ); $j <= $to; ++$j ) {
				$keep[ $j ] = true;
			}
		}
		$hunks   = array();
		$current = array();
		$prev    = -2;
		ksort( $keep );
		foreach ( array_keys( $keep ) as $i ) {
			if ( $i !== $prev + 1 && $current ) {
				$hunks[] = $current;
				$current = array();
			}
			$current[] = $rows[ $i ];
			$prev      = $i;
		}
		if ( $current ) {
			$hunks[] = $current;
		}
		return $hunks;
	}
}

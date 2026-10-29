<?php
/**
 * Rules for added JavaScript.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Line-based checks for scripts.
 */
class Js {

	/**
	 * Scans a script.
	 *
	 * @param string[]             $lines Source lines.
	 * @param array<int,bool>|null $added Added line numbers as keys, or null for a new file.
	 * @param string               $file  Relative path.
	 * @return array<int,array<string,mixed>>
	 */
	public static function scan( array $lines, $added, string $file ): array {
		$out = array();
		foreach ( $lines as $idx => $text ) {
			$line = $idx + 1;
			if ( ! Catalog::is_added( $added, $line ) || strlen( $text ) > 20000 ) {
				continue;
			}
			$trim = ltrim( $text );
			if ( '//' === substr( $trim, 0, 2 ) || '*' === substr( $trim, 0, 1 ) ) {
				continue;
			}
			if ( preg_match( '/(?<![\w.$])eval\s*\(|\bnew\s+Function\s*\(/', $text ) ) {
				$out[] = Catalog::make( 'js.eval', $file, $line, $lines );
			}
			if ( preg_match( '/[A-Za-z0-9+\/=]{400,}/', $text, $m ) && Code::packed( $m[0] ) ) {
				$out[] = Catalog::make( 'obf.long_string', $file, $line, $lines );
			}
		}
		return $out;
	}
}

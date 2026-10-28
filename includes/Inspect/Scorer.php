<?php
/**
 * Turns findings into a risk level and plain-language reasons.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect;

use Updlock\Inspect\Rules\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low, Review or High.
 */
class Scorer {

	/**
	 * Level for a list of findings: High on any high finding, Review on any medium, otherwise Low.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return string 'low', 'review' or 'high'.
	 */
	public static function level( array $findings ): string {
		$level = 'low';
		foreach ( $findings as $finding ) {
			if ( 'high' === $finding['severity'] ) {
				return 'high';
			}
			if ( 'medium' === $finding['severity'] ) {
				$level = 'review';
			}
		}
		return $level;
	}

	/**
	 * Plain-language reasons for findings that raise the level.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return string[]
	 */
	public static function reasons( array $findings ): array {
		$catalog = Catalog::all();
		$groups  = array();
		foreach ( $findings as $finding ) {
			if ( ! in_array( $finding['severity'], array( 'high', 'medium' ), true ) ) {
				continue;
			}
			$groups[ $finding['rule'] ][] = $finding;
		}
		$order = array(
			'high'   => 0,
			'medium' => 1,
		);
		uasort(
			$groups,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a[0]['severity'] ] <=> $order[ $b[0]['severity'] ];
			}
		);
		$out = array();
		foreach ( $groups as $rule => $items ) {
			$phrase = $catalog[ $rule ][1] ?? $rule;
			$count  = count( $items );
			if ( in_array( $rule, array( 'net.new_host', 'net.ip_host', 'js.new_host' ), true ) ) {
				$hosts = array_values( array_unique( array_column( $items, 'detail' ) ) );
				/* translators: 1: number of hosts, 2: comma separated host names */
				$out[] = sprintf( _n( 'adds %1$d outbound host: %2$s', 'adds %1$d outbound hosts: %2$s', count( $hosts ), 'update-airlock' ), count( $hosts ), implode( ', ', $hosts ) );
			} elseif ( 0 === strpos( $rule, 'prov.' ) || 0 === strpos( $rule, 'hijack.update_uri' ) ) {
				$out[] = $phrase;
			} elseif ( 1 === $count ) {
				$out[] = $phrase;
			} else {
				/* translators: 1: what the code does, 2: number of places */
				$out[] = sprintf( __( '%1$s (%2$d places)', 'update-airlock' ), $phrase, $count );
			}
		}
		return $out;
	}
}

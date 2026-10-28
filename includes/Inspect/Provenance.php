<?php
/**
 * Signals from headers, readme and changelog (local only, no lookups).
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
 * Compares plugin or theme headers and readme between two versions.
 */
class Provenance {

	/**
	 * Reads the header fields we compare.
	 *
	 * @param string $source Start of the main plugin file or style.css.
	 * @return array<string,string>
	 */
	public static function headers( string $source ): array {
		$map = array(
			'plugin name' => 'name',
			'theme name'  => 'name',
			'plugin uri'  => 'plugin_uri',
			'theme uri'   => 'plugin_uri',
			'author'      => 'author',
			'author uri'  => 'author_uri',
			'update uri'  => 'update_uri',
			'version'     => 'version',
		);
		$out = array();
		$src = substr( $source, 0, 8192 );
		if ( preg_match_all( '/^[ \t\/*#@]*(Plugin Name|Theme Name|Plugin URI|Theme URI|Author URI|Author|Update URI|Version)[ \t]*:[ \t]*(.*)$/mi', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$key = $map[ strtolower( $row[1] ) ];
				if ( ! isset( $out[ $key ] ) ) {
					$out[ $key ] = trim( preg_replace( '/\s*\*\/.*$/', '', $row[2] ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Reads contributors and the changelog from a readme.txt.
	 *
	 * @param string $text Readme text.
	 * @return array{contributors:string[],changelog:array<string,string[]>}
	 */
	public static function readme( string $text ): array {
		$out = array(
			'contributors' => array(),
			'changelog'    => array(),
		);
		if ( preg_match( '/^Contributors:[ \t]*(.*)$/mi', $text, $m ) ) {
			$list = array_filter( array_map( 'trim', explode( ',', strtolower( $m[1] ) ) ) );
			sort( $list );
			$out['contributors'] = array_values( $list );
		}
		if ( preg_match( '/^==[ \t]*Changelog[ \t]*==\s*$(.*?)(?=^==[^=]|\z)/msi', $text, $m ) ) {
			$version = '';
			foreach ( Differ::split( $m[1] ) as $line ) {
				if ( preg_match( '/^=+[ \t]*v?([0-9][^\s=]*)[ \t]*(?:-.*)?=+[ \t]*$/', $line, $h ) ) {
					$version = $h[1];
					if ( ! isset( $out['changelog'][ $version ] ) ) {
						$out['changelog'][ $version ] = array();
					}
				} elseif ( '' !== $version && preg_match( '/^\s*[*\-]\s*(.+)$/', $line, $b ) ) {
					$out['changelog'][ $version ][] = trim( $b[1] );
				}
			}
		}
		return $out;
	}

	/**
	 * Whether every changelog line talks only about compatibility or housekeeping.
	 *
	 * @param string[] $bullets Changelog lines.
	 * @return bool
	 */
	public static function compat_only( array $bullets ): bool {
		if ( ! $bullets ) {
			return false;
		}
		foreach ( $bullets as $bullet ) {
			if ( ! preg_match( '/\b(compatib|tested\s+(up\s+)?(to|with)|WordPress\s*\d|wp\s*\d|php\s*\d|readme|text\s*domain|translation|version\s+bump|bump|header|typo|minor)/i', $bullet ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Builds provenance findings.
	 *
	 * @param array<string,string> $old_head   Installed headers.
	 * @param array<string,string> $new_head   New headers.
	 * @param string               $old_readme Installed readme text ('' if none).
	 * @param string               $new_readme New readme text ('' if none).
	 * @param int                  $added_code Added lines of code (PHP and JavaScript).
	 * @param string               $file       Path used for the findings (the main file).
	 * @return array<int,array<string,mixed>>
	 */
	public static function compare( array $old_head, array $new_head, string $old_readme, string $new_readme, int $added_code, string $file ): array {
		$out          = array();
		$lines        = array();
		$norm         = static function ( $value ): string {
			$value = strtolower( trim( (string) $value ) );
			$value = preg_replace( '#^https?://#', '', $value );
			return rtrim( (string) $value, '/' );
		};
		$changed      = static function ( string $key ) use ( $old_head, $new_head, $norm ): bool {
			return $norm( $old_head[ $key ] ?? '' ) !== $norm( $new_head[ $key ] ?? '' );
		};
		$name_changed = $changed( 'author' );
		$uri_changed  = $changed( 'author_uri' );
		if ( $name_changed && $uri_changed ) {
			$out[] = Catalog::make( 'prov.author_changed', $file, 0, $lines, ( $old_head['author'] ?? '' ) . ' > ' . ( $new_head['author'] ?? '' ) );
		} elseif ( $name_changed ) {
			$out[] = Catalog::make( 'prov.author_name', $file, 0, $lines, ( $old_head['author'] ?? '' ) . ' > ' . ( $new_head['author'] ?? '' ) );
		} elseif ( $uri_changed ) {
			$out[] = Catalog::make( 'prov.author_uri', $file, 0, $lines, ( $old_head['author_uri'] ?? '' ) . ' > ' . ( $new_head['author_uri'] ?? '' ) );
		}
		if ( $changed( 'plugin_uri' ) ) {
			$out[] = Catalog::make( 'prov.plugin_uri', $file, 0, $lines );
		}
		$new_update = trim( $new_head['update_uri'] ?? '' );
		if ( '' !== $new_update && 'false' !== strtolower( $new_update ) && $changed( 'update_uri' ) ) {
			$out[] = Catalog::make( 'hijack.update_uri', $file, 0, $lines, $new_update );
		}
		$old_r = self::readme( $old_readme );
		$new_r = self::readme( $new_readme );
		if ( $old_r['contributors'] && $new_r['contributors'] && $old_r['contributors'] !== $new_r['contributors'] ) {
			$out[] = Catalog::make( 'prov.contributors', 'readme.txt', 0, $lines, implode( ', ', $old_r['contributors'] ) . ' > ' . implode( ', ', $new_r['contributors'] ) );
		}
		$version = $new_head['version'] ?? '';
		$bullets = $new_r['changelog'][ $version ] ?? ( $new_r['changelog'] ? reset( $new_r['changelog'] ) : array() );
		if ( self::compat_only( $bullets ) ) {
			if ( $added_code >= 150 ) {
				$out[] = Catalog::make( 'prov.compat_only_big', 'readme.txt', 0, $lines, (string) $added_code );
			} elseif ( $added_code >= 60 ) {
				$out[] = Catalog::make( 'prov.compat_only', 'readme.txt', 0, $lines, (string) $added_code );
			}
		}
		return $out;
	}
}

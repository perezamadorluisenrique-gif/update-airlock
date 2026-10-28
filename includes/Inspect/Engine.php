<?php
/**
 * Compares an installed plugin or theme with a new version. Reads files only; nothing is included or run.
 *
 * The work is split in begin(), step() and finish() so long packages can be processed in small
 * requests; run() calls the same three steps in one go.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect;

use Updlock\Inspect\Rules\Catalog;
use Updlock\Inspect\Rules\Code;
use Updlock\Inspect\Rules\Js;
use Updlock\Inspect\Rules\Network;
use Updlock\Inspect\Rules\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The inspection engine.
 */
class Engine {

	/**
	 * Files above this size are compared by hash only.
	 */
	const BIG = 1048576;

	/**
	 * Upper bound on stored diff data per report, in bytes.
	 */
	const DIFF_BUDGET = 2000000;

	/**
	 * Files read as text.
	 *
	 * @var string[]
	 */
	const TEXT = array( 'php', 'phtml', 'inc', 'js', 'mjs', 'css', 'html', 'htm', 'txt', 'md', 'json', 'xml', 'svg', 'yml', 'yaml', 'ini', 'twig' );

	/**
	 * Extensions of files that are not code but could hide it.
	 *
	 * @var string[]
	 */
	const MEDIA = array( 'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'md', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'pdf', 'css' );

	/**
	 * Folder names that normally hold assets, not PHP.
	 *
	 * @var string[]
	 */
	const ASSET_DIRS = array( 'assets', 'images', 'img', 'css', 'js', 'fonts', 'uploads', 'static', 'media' );

	/**
	 * Starts an inspection.
	 *
	 * @param string              $old_dir Installed folder.
	 * @param string              $new_dir New folder.
	 * @param array<string,mixed> $meta    Anything the caller wants back (kind, slug, versions).
	 * @return array<string,mixed> State to pass to step() and finish().
	 */
	public static function begin( string $old_dir, string $new_dir, array $meta = array() ): array {
		$old   = self::list_files( $old_dir );
		$new   = self::list_files( $new_dir );
		$paths = array_values( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) );
		sort( $paths );
		return array(
			'old_dir'   => rtrim( $old_dir, '/\\' ),
			'new_dir'   => rtrim( $new_dir, '/\\' ),
			'meta'      => $meta,
			'paths'     => $paths,
			'pos'       => 0,
			'phase'     => 'index',
			'old_h'     => array(),
			'new_h'     => array(),
			'old_hosts' => array(),
			'todo'      => array(),
			'findings'  => array(),
			'diffs'     => array(),
			'diff_size' => 0,
			'ins'       => 0,
			'del'       => 0,
			'code_ins'  => 0,
			'skipped'   => array(),
			'per_file'  => array(),
		);
	}

	/**
	 * Lists regular files under a folder (no symlinks) as relative path => size.
	 *
	 * @param string $dir Folder.
	 * @return array<string,int>
	 */
	private static function list_files( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$base = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $item ) {
			if ( $item->isLink() || ! $item->isFile() ) {
				continue;
			}
			$path = str_replace( '\\', '/', $item->getPathname() );
			if ( 0 !== strpos( $path, $base ) ) {
				continue;
			}
			$out[ substr( $path, strlen( $base ) ) ] = (int) $item->getSize();
			if ( count( $out ) >= 20000 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Runs work until the deadline.
	 *
	 * @param array<string,mixed> $state    State from begin(), updated in place.
	 * @param float               $deadline Unix time (microtime) to stop at.
	 * @return bool True once everything is processed.
	 */
	public static function step( array &$state, float $deadline ): bool {
		if ( 'index' === $state['phase'] ) {
			$count = count( $state['paths'] );
			while ( $state['pos'] < $count ) {
				self::index_file( $state, $state['paths'][ $state['pos'] ] );
				++$state['pos'];
				if ( microtime( true ) >= $deadline && $state['pos'] < $count ) {
					return false;
				}
			}
			$state['phase'] = 'analyze';
			$state['pos']   = 0;
			$diff           = Differ::files( $state['old_h'], $state['new_h'] );
			$state['todo']  = array_merge( $diff['added'], $diff['changed'] );
			sort( $state['todo'] );
		}
		if ( 'analyze' === $state['phase'] ) {
			$count = count( $state['todo'] );
			while ( $state['pos'] < $count ) {
				if ( microtime( true ) >= $deadline && $state['pos'] > 0 && ! empty( $state['meta']['budget'] ) ) {
					for ( $i = $state['pos']; $i < $count; ++$i ) {
						$state['skipped'][] = array( $state['todo'][ $i ], 'time' );
					}
					$state['pos'] = $count;
					break;
				}
				self::analyze_file( $state, $state['todo'][ $state['pos'] ] );
				++$state['pos'];
				if ( microtime( true ) >= $deadline && $state['pos'] < $count && empty( $state['meta']['budget'] ) ) {
					return false;
				}
			}
			$state['phase'] = 'done';
		}
		return true;
	}

	/**
	 * Hashes a file in both versions and collects the hosts of the installed version.
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $path  Relative path.
	 */
	private static function index_file( array &$state, string $path ): void {
		$old = $state['old_dir'] . '/' . $path;
		$new = $state['new_dir'] . '/' . $path;
		if ( is_file( $old ) && ! is_link( $old ) ) {
			$state['old_h'][ $path ] = hash_file( 'sha256', $old );
			$ext                     = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'php', 'phtml', 'inc', 'js', 'mjs', 'html', 'htm' ), true ) && filesize( $old ) <= self::BIG ) {
				$text  = self::read( $old );
				$lines = Differ::split( $text );
				if ( in_array( $ext, array( 'php', 'phtml', 'inc' ), true ) ) {
					$hosts = Network::php_hosts( new Php_Scan( $text ), null );
					$hosts = array_merge( $hosts, Network::text_hosts( $lines, null, false ) );
				} else {
					$hosts = Network::text_hosts( $lines, null, 'js' === $ext || 'mjs' === $ext );
					$hosts = array_merge( $hosts, self::literal_hosts( $text ) );
				}
				foreach ( $hosts as $item ) {
					$state['old_hosts'][ $item['host'] ] = true;
				}
			}
		}
		if ( is_file( $new ) && ! is_link( $new ) ) {
			$state['new_h'][ $path ] = hash_file( 'sha256', $new );
		}
	}

	/**
	 * Hosts of every URL in a text (used for the installed version of scripts).
	 *
	 * @param string $text Text.
	 * @return array<int,array{host:string,line:int,tier:string}>
	 */
	private static function literal_hosts( string $text ): array {
		$out = array();
		foreach ( Network::extract( $text, true ) as $host ) {
			$out[] = array(
				'host' => $host,
				'line' => 0,
				'tier' => 'literal',
			);
		}
		return $out;
	}

	/**
	 * Reads a local file to inspect it; the content is never executed.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read( string $path ): string {
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file to inspect it; never executed.
		return false === $text ? '' : $text;
	}

	/**
	 * Diffs and scans one added or changed file.
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $path  Relative path.
	 */
	private static function analyze_file( array &$state, string $path ): void {
		$new_file                   = $state['new_dir'] . '/' . $path;
		$old_file                   = $state['old_dir'] . '/' . $path;
		$is_new                     = ! isset( $state['old_h'][ $path ] );
		$ext                        = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$size                       = (int) filesize( $new_file );
		$state['per_file'][ $path ] = array( 0, 0 );

		if ( ! in_array( $ext, self::TEXT, true ) && ! in_array( $ext, self::MEDIA, true ) ) {
			if ( $is_new && $size <= self::BIG && false !== strpos( self::read( $new_file ), '<?php' ) ) {
				$state['findings'][] = Catalog::make( 'obf.php_in_nonphp', $path, 0, array() );
			}
			return;
		}
		if ( $size > self::BIG ) {
			$state['skipped'][] = array( $path, 'size' );
			if ( $is_new && in_array( $ext, array( 'js', 'css', 'mjs' ), true ) ) {
				$state['findings'][] = Catalog::make( 'obf.new_minified', $path, 0, array() );
			}
			return;
		}

		$text   = self::read( $new_file );
		$lines  = Differ::split( $text );
		$is_php = in_array( $ext, array( 'php', 'phtml', 'inc' ), true );

		if ( ! $is_php && in_array( $ext, self::MEDIA, true ) && preg_match( '/<\?php|<\?=/', $text ) ) {
			$state['findings'][] = Catalog::make( 'obf.php_in_nonphp', $path, 0, array() );
		}
		if ( $is_php && $is_new ) {
			$parts = explode( '/', $path );
			array_pop( $parts );
			if ( array_intersect( array_map( 'strtolower', $parts ), self::ASSET_DIRS ) ) {
				$state['findings'][] = Catalog::make( 'obf.php_in_assets', $path, 0, array() );
			}
		}

		// Added lines.
		if ( $is_new ) {
			$added = null;
			$ins   = count( $lines );
			$del   = 0;
			$rows  = null;
		} else {
			$rows  = Differ::lines( Differ::split( self::read( $old_file ) ), $lines );
			$added = array_fill_keys( Differ::added_numbers( $rows ), true );
			$ins   = count( $added );
			$del   = 0;
			foreach ( $rows as $row ) {
				if ( '-' === $row['t'] ) {
					++$del;
				}
			}
		}
		$state['per_file'][ $path ] = array( $ins, $del );
		$state['ins']              += $ins;
		$state['del']              += $del;
		if ( null !== $rows && $state['diff_size'] < self::DIFF_BUDGET ) {
			$hunks = Differ::hunks( $rows );
			$json  = wp_json_encode( $hunks );
			if ( is_string( $json ) && strlen( $json ) <= 300000 && $state['diff_size'] + strlen( $json ) <= self::DIFF_BUDGET ) {
				$state['diffs'][ $path ] = $hunks;
				$state['diff_size']     += strlen( $json );
			}
		}

		$minified = self::is_minified( $lines, $path );
		if ( in_array( $ext, array( 'js', 'mjs', 'css' ), true ) && $minified ) {
			$state['findings'][] = Catalog::make( 'obf.new_minified', $path, 0, array() );
			return;
		}

		$found = array();
		if ( $is_php ) {
			$scan               = new Php_Scan( $text );
			$found              = array_merge(
				Code::scan( $scan, $added, $lines, $path ),
				Persistence::scan( $scan, $added, $lines, $path ),
				Network::findings( array_merge( Network::php_hosts( $scan, $added ), Network::text_hosts( $lines, $added, false ) ), $state['old_hosts'], $lines, $path )
			);
			$state['code_ins'] += $ins;
		} elseif ( 'js' === $ext || 'mjs' === $ext ) {
			$found              = array_merge(
				Js::scan( $lines, $added, $path ),
				Network::findings( Network::text_hosts( $lines, $added, true ), $state['old_hosts'], $lines, $path )
			);
			$state['code_ins'] += $ins;
		} elseif ( 'html' === $ext || 'htm' === $ext ) {
			$found = Network::findings( Network::text_hosts( $lines, $added, false ), $state['old_hosts'], $lines, $path );
		}
		foreach ( $found as $finding ) {
			$state['findings'][] = $finding;
		}
	}

	/**
	 * Whether a script or stylesheet looks minified.
	 *
	 * @param string[] $lines Lines.
	 * @param string   $path  Relative path.
	 * @return bool
	 */
	private static function is_minified( array $lines, string $path ): bool {
		if ( preg_match( '/\.min\.(js|css)$/i', $path ) ) {
			return true;
		}
		foreach ( $lines as $line ) {
			if ( strlen( $line ) > 1000 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the report once step() has returned true.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function finish( array $state ): array {
		$files    = Differ::files( $state['old_h'], $state['new_h'] );
		$meta     = $state['meta'];
		$findings = $state['findings'];

		$old_main = self::main_file( $state['old_dir'], (string) ( $meta['kind'] ?? 'plugin' ) );
		$new_main = self::main_file( $state['new_dir'], (string) ( $meta['kind'] ?? 'plugin' ) );
		$old_head = $old_main ? Provenance::headers( self::read( $state['old_dir'] . '/' . $old_main ) ) : array();
		$new_head = $new_main ? Provenance::headers( self::read( $state['new_dir'] . '/' . $new_main ) ) : array();
		$old_read = is_file( $state['old_dir'] . '/readme.txt' ) ? self::read( $state['old_dir'] . '/readme.txt' ) : '';
		$new_read = is_file( $state['new_dir'] . '/readme.txt' ) ? self::read( $state['new_dir'] . '/readme.txt' ) : '';
		foreach ( Provenance::compare( $old_head, $new_head, $old_read, $new_read, (int) $state['code_ins'], (string) ( $new_main ? $new_main : '' ) ) as $finding ) {
			$findings[] = $finding;
		}

		$hosts = array();
		foreach ( $findings as $finding ) {
			if ( in_array( $finding['rule'], array( 'net.new_host', 'net.ip_host', 'js.new_host', 'net.new_host_low' ), true ) ) {
				$hosts[ $finding['detail'] ] = $finding['detail'];
			}
		}

		return array(
			'meta'     => $meta,
			'headers'  => array(
				'old' => $old_head,
				'new' => $new_head,
			),
			'files'    => $files,
			'per_file' => $state['per_file'],
			'stats'    => array(
				'added_lines'   => $state['ins'],
				'removed_lines' => $state['del'],
				'code_lines'    => $state['code_ins'],
			),
			'skipped'  => $state['skipped'],
			'findings' => $findings,
			'hosts'    => array_values( $hosts ),
			'level'    => Scorer::level( $findings ),
			'diffs'    => $state['diffs'],
		);
	}

	/**
	 * Finds the main plugin file or style.css in a folder.
	 *
	 * @param string $dir  Folder.
	 * @param string $kind 'plugin' or 'theme'.
	 * @return string Relative file name, or '' when not found.
	 */
	public static function main_file( string $dir, string $kind ): string {
		if ( 'theme' === $kind ) {
			return is_file( $dir . '/style.css' ) ? 'style.css' : '';
		}
		$files = glob( rtrim( $dir, '/' ) . '/*.php' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( preg_match( '/^[ \t\/*#@]*Plugin Name[ \t]*:/mi', substr( self::read( $file ), 0, 8192 ) ) ) {
				return basename( $file );
			}
		}
		return '';
	}

	/**
	 * One-shot inspection.
	 *
	 * @param string              $old_dir Installed folder.
	 * @param string              $new_dir New folder.
	 * @param array<string,mixed> $meta    Context passed through to the report.
	 * @param float               $budget  Seconds allowed; 0 for no limit. Files not reached are listed as skipped.
	 * @return array<string,mixed>
	 */
	public static function run( string $old_dir, string $new_dir, array $meta = array(), float $budget = 0.0 ): array {
		if ( $budget > 0 ) {
			$meta['budget'] = true;
		}
		$state    = self::begin( $old_dir, $new_dir, $meta );
		$deadline = $budget > 0 ? microtime( true ) + $budget : INF;
		while ( ! self::step( $state, $deadline ) ) {
			continue;
		}
		return self::finish( $state );
	}
}

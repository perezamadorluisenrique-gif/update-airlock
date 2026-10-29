<?php
/**
 * Snapshots: a zip of the installed folder plus a manifest, kept in a protected folder.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Snapshot;

use Updlock\Core\Fs;
use Updlock\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating, listing and pruning snapshots.
 */
class Store {

	const OPTION = 'updlock_snapshots';

	/**
	 * Absolute path of the protected storage folder (created on first use).
	 *
	 * @return string|\WP_Error
	 */
	public static function root() {
		$sub = (string) get_option( 'updlock_snap_dir', '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $sub ) ) {
			$sub = bin2hex( random_bytes( 8 ) );
			update_option( 'updlock_snap_dir', $sub, false );
		}
		$base = WP_CONTENT_DIR . '/updlock-snapshots';
		$root = $base . '/' . $sub;
		$fs   = Fs::get();
		if ( ! $fs ) {
			return new \WP_Error( 'updlock_fs', __( 'WordPress cannot write files here without credentials.', 'update-airlock' ) );
		}
		if ( ! is_dir( $root ) ) {
			$fs->mkdir( Fs::remote( $base ), FS_CHMOD_DIR );
			$fs->mkdir( Fs::remote( $root ), FS_CHMOD_DIR );
			$silence = "<?php\n// Silence is golden.\n";
			$fs->put_contents( Fs::remote( $base ) . '/index.php', $silence, FS_CHMOD_FILE );
			$fs->put_contents( Fs::remote( $root ) . '/index.php', $silence, FS_CHMOD_FILE );
			$deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\nOptions -Indexes\n";
			$fs->put_contents( Fs::remote( $base ) . '/.htaccess', $deny, FS_CHMOD_FILE );
			$fs->put_contents( Fs::remote( $root ) . '/.htaccess', $deny, FS_CHMOD_FILE );
			$web = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';
			$fs->put_contents( Fs::remote( $base ) . '/web.config', $web, FS_CHMOD_FILE );
			$fs->put_contents( Fs::remote( $root ) . '/web.config', $web, FS_CHMOD_FILE );
		}
		return is_dir( $root ) ? $root : new \WP_Error( 'updlock_mkdir', __( 'The snapshot folder could not be created.', 'update-airlock' ) );
	}

	/**
	 * Relative file list of a folder: path => absolute path (no symlinks).
	 *
	 * @param string $dir Folder.
	 * @return array<string,string>
	 */
	public static function files( string $dir ): array {
		$out  = array();
		$base = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $item ) {
			if ( $item->isLink() || ! $item->isFile() ) {
				continue;
			}
			$path = str_replace( '\\', '/', $item->getPathname() );
			if ( 0 === strpos( $path, $base ) ) {
				$out[ substr( $path, strlen( $base ) ) ] = $path;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Creates a snapshot of an installed plugin or theme.
	 *
	 * @param string              $kind  plugin or theme.
	 * @param string              $slug  Folder name (or file name for a single-file plugin).
	 * @param array<string,mixed> $extra Version, name, plugin basename, active flags, user id.
	 * @return array<string,mixed>|\WP_Error The snapshot entry.
	 */
	public static function create( string $kind, string $slug, array $extra ) {
		if ( ! Fs::valid_slug( $slug ) ) {
			return new \WP_Error( 'updlock_slug', __( 'Invalid folder name.', 'update-airlock' ) );
		}
		$src       = Fs::root( $kind ) . '/' . $slug;
		$is_single = is_file( $src );
		if ( ! is_dir( $src ) && ! $is_single ) {
			return new \WP_Error( 'updlock_missing', __( 'The installed copy was not found.', 'update-airlock' ) );
		}
		$list = $is_single ? array( $slug => $src ) : self::files( $src );
		$size = 0;
		foreach ( $list as $abs ) {
			$size += (int) filesize( $abs );
		}
		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host; a missing value is treated as unknown.
		if ( false !== $free && $free < $size * 2 + 5 * MB_IN_BYTES ) {
			return new \WP_Error( 'updlock_space', __( 'There is not enough free disk space for a snapshot.', 'update-airlock' ) );
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$fs = Fs::get();
		$id = bin2hex( random_bytes( 6 ) );

		$hashes = array();
		foreach ( $list as $rel => $abs ) {
			$hashes[ $rel ] = hash_file( 'sha256', $abs );
		}
		$tmp = wp_tempnam( 'updlock-snap' );
		if ( ! $tmp ) {
			return new \WP_Error( 'updlock_tmp', __( 'A temporary file could not be created.', 'update-airlock' ) );
		}
		$ok = self::zip( $tmp, $list, $is_single ? '' : $slug );
		if ( ! $ok ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'updlock_zip', __( 'The snapshot zip could not be written.', 'update-airlock' ) );
		}
		$zip_name = $id . '.zip';
		if ( ! $fs->move( $tmp, Fs::remote( $root ) . '/' . $zip_name, true ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'updlock_move', __( 'The snapshot could not be stored.', 'update-airlock' ) );
		}

		$options = array();
		foreach ( Owned_Options::from_dir( $is_single ? dirname( $src ) . '/__none__' : $src ) as $name ) {
			$options[] = self::read_option( $name );
		}
		$options  = array_values( array_filter( $options ) );
		$manifest = array(
			'id'      => $id,
			'kind'    => $kind,
			'slug'    => $slug,
			'single'  => $is_single,
			'version' => (string) ( $extra['version'] ?? '' ),
			'name'    => (string) ( $extra['name'] ?? $slug ),
			'plugin'  => (string) ( $extra['plugin'] ?? '' ),
			'ts'      => time(),
			'user'    => (int) ( $extra['user'] ?? 0 ),
			'active'  => ! empty( $extra['active'] ),
			'network' => ! empty( $extra['network'] ),
			'wp'      => get_bloginfo( 'version' ),
			'php'     => PHP_VERSION,
			'files'   => $hashes,
			'options' => $options,
		);
		$fs->put_contents( Fs::remote( $root ) . '/' . $id . '.json', wp_json_encode( $manifest ), FS_CHMOD_FILE );

		$entry = array(
			'id'      => $id,
			'kind'    => $kind,
			'slug'    => $slug,
			'plugin'  => $manifest['plugin'],
			'name'    => $manifest['name'],
			'version' => $manifest['version'],
			'ts'      => $manifest['ts'],
			'size'    => (int) filesize( $root . '/' . $zip_name ),
			'files'   => count( $hashes ),
			'options' => count( $options ),
			'active'  => $manifest['active'],
			'network' => $manifest['network'],
		);
		$all   = self::all();
		$all[] = $entry;
		update_option( self::OPTION, $all, false );
		self::enforce_retention( $kind, $slug );
		return $entry;
	}

	/**
	 * Reads one option with its autoload flag.
	 *
	 * @param string $name Option name.
	 * @return array<string,mixed>|null
	 */
	private static function read_option( string $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the autoload flag, which the options API does not expose.
		if ( ! $row ) {
			return null;
		}
		return array(
			'name'     => $name,
			'value'    => get_option( $name ),
			'autoload' => in_array( $row['autoload'], array( 'yes', 'on', 'auto', 'auto-on' ), true ) ? 'yes' : 'no',
		);
	}

	/**
	 * Writes a zip with ZipArchive, or with PclZip when the extension is missing.
	 *
	 * @param string               $zip_path Zip file to write.
	 * @param array<string,string> $entries  Relative path => absolute path.
	 * @param string               $top      Top-level folder name inside the zip ('' for none).
	 * @return bool
	 */
	public static function zip( string $zip_path, array $entries, string $top ): bool {
		$prefix = '' === $top ? '' : $top . '/';
		if ( class_exists( 'ZipArchive' ) && ! defined( 'UPDLOCK_FORCE_PCLZIP' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE ) ) {
				return false;
			}
			foreach ( $entries as $rel => $abs ) {
				$zip->addFile( $abs, $prefix . $rel );
			}
			return $zip->close();
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$pcl   = new \PclZip( $zip_path );
		$files = array_values( $entries );
		if ( ! $files ) {
			return false;
		}
		$strip = '' === $top ? dirname( reset( $entries ) ) : substr( reset( $entries ), 0, -strlen( (string) key( $entries ) ) - 1 );
		$res   = $pcl->create( $files, PCLZIP_OPT_REMOVE_PATH, $strip, PCLZIP_OPT_ADD_PATH, $top );
		return 0 !== $res;
	}

	/**
	 * All snapshot entries, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * Snapshot entries for one item, newest first.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_item( string $kind, string $slug ): array {
		$out = array();
		foreach ( self::all() as $entry ) {
			if ( $entry['kind'] === $kind && $entry['slug'] === $slug ) {
				$out[] = $entry;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);
		return $out;
	}

	/**
	 * One snapshot entry by id.
	 *
	 * @param string $id Snapshot id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ) {
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
			return null;
		}
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * The manifest of a snapshot.
	 *
	 * @param string $id Snapshot id.
	 * @return array<string,mixed>|null
	 */
	public static function manifest( string $id ) {
		$root = self::root();
		if ( is_wp_error( $root ) || ! preg_match( '/^[a-f0-9]{12}$/', $id ) || ! is_readable( $root . '/' . $id . '.json' ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $root . '/' . $id . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this plugin's own manifest.
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Absolute path of a snapshot zip.
	 *
	 * @param string $id Snapshot id.
	 * @return string Empty when unavailable.
	 */
	public static function zip_path( string $id ): string {
		$root = self::root();
		if ( is_wp_error( $root ) || ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
			return '';
		}
		return is_readable( $root . '/' . $id . '.zip' ) ? $root . '/' . $id . '.zip' : '';
	}

	/**
	 * Deletes a snapshot and its files.
	 *
	 * @param string $id Snapshot id.
	 */
	public static function delete( string $id ): void {
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
			return;
		}
		$root = self::root();
		if ( ! is_wp_error( $root ) ) {
			wp_delete_file( $root . '/' . $id . '.zip' );
			wp_delete_file( $root . '/' . $id . '.json' );
		}
		$keep = array();
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] !== $id ) {
				$keep[] = $entry;
			}
		}
		update_option( self::OPTION, $keep, false );
	}

	/**
	 * Keeps the newest snapshots for an item, as set in the settings.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Folder name.
	 */
	public static function enforce_retention( string $kind, string $slug ): void {
		$keep = max( 1, (int) Settings::get( 'keep_snapshots' ) );
		foreach ( array_slice( self::for_item( $kind, $slug ), $keep ) as $entry ) {
			self::delete( $entry['id'] );
		}
	}

	/**
	 * Total size of all snapshots in bytes.
	 *
	 * @return int
	 */
	public static function total_size(): int {
		$sum = 0;
		foreach ( self::all() as $entry ) {
			$sum += (int) $entry['size'];
		}
		return $sum;
	}
}

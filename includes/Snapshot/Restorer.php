<?php
/**
 * Restores a snapshot through WP_Filesystem, keeping the current copy until the restored one is verified.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Snapshot;

use Updlock\Core\Fs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hash-verified restore with a recoverable swap record.
 */
class Restorer {

	const SWAP = 'updlock_swap';

	/**
	 * Restores a snapshot.
	 *
	 * @param string $id           Snapshot id.
	 * @param bool   $with_options Also put back the option values saved in the snapshot.
	 * @return array<string,mixed>|\WP_Error Result details.
	 */
	public static function restore( string $id, bool $with_options = false ) {
		$entry    = Store::get( $id );
		$manifest = Store::manifest( $id );
		$zip      = Store::zip_path( $id );
		if ( ! $entry || ! $manifest || '' === $zip ) {
			return new \WP_Error( 'updlock_nosnap', __( 'That snapshot is not available any more.', 'update-airlock' ) );
		}
		$kind = (string) $manifest['kind'];
		$slug = (string) $manifest['slug'];
		if ( ! Fs::valid_slug( $slug ) || ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return new \WP_Error( 'updlock_slug', __( 'The snapshot names an invalid folder.', 'update-airlock' ) );
		}
		$fs = Fs::get();
		if ( ! $fs ) {
			return new \WP_Error( 'updlock_fs', __( 'WordPress needs file access credentials to restore. Restore by hand from the snapshot folder.', 'update-airlock' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! \WP_Upgrader::create_lock( 'updlock', 600 ) ) {
			return new \WP_Error( 'updlock_locked', __( 'Another update or restore is running. Try again in a few minutes.', 'update-airlock' ) );
		}
		$result = self::swap( $fs, $entry, $manifest, $zip, $kind, $slug );
		\WP_Upgrader::release_lock( 'updlock' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$notes = array();
		if ( 'plugin' === $kind ) {
			wp_clean_plugins_cache();
			$plugin = (string) $manifest['plugin'];
			if ( '' !== $plugin && ! empty( $manifest['active'] ) && ! is_plugin_active( $plugin ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$act = activate_plugin( $plugin, '', ! empty( $manifest['network'] ), true );
				if ( is_wp_error( $act ) ) {
					$notes[] = $act->get_error_message();
				}
			}
		} else {
			wp_clean_themes_cache();
		}
		if ( $with_options ) {
			foreach ( (array) $manifest['options'] as $opt ) {
				if ( isset( $opt['name'] ) && \Updlock\Snapshot\Owned_Options::owned( (string) $opt['name'] ) ) {
					$autoload = isset( $opt['autoload'] ) && 'yes' === $opt['autoload'];
					update_option( (string) $opt['name'], $opt['value'] ?? '', $autoload );
				}
			}
		}
		return array(
			'snapshot' => $id,
			'files'    => count( (array) $manifest['files'] ),
			'notes'    => $notes,
		);
	}

	/**
	 * Unzips, verifies, swaps and verifies again.
	 *
	 * @param \WP_Filesystem_Base $fs       Filesystem.
	 * @param array               $entry    Snapshot entry.
	 * @param array               $manifest Manifest.
	 * @param string              $zip      Zip path.
	 * @param string              $kind     plugin or theme.
	 * @param string              $slug     Folder or file name.
	 * @return true|\WP_Error
	 */
	private static function swap( $fs, array $entry, array $manifest, string $zip, string $kind, string $slug ) {
		$target = Fs::root( $kind ) . '/' . $slug;
		$token  = bin2hex( random_bytes( 6 ) );
		$work   = Fs::upgrade_dir() . '/updlock-restore-' . $token;
		$trash  = Fs::upgrade_dir() . '/updlock-trash-' . $token;
		$fs->mkdir( Fs::remote( Fs::upgrade_dir() ), FS_CHMOD_DIR );
		$fs->mkdir( Fs::remote( $work ), FS_CHMOD_DIR );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$un = unzip_file( $zip, Fs::remote( $work ) );
		if ( is_wp_error( $un ) ) {
			$fs->delete( Fs::remote( $work ), true );
			return $un;
		}
		$expected = (array) $manifest['files'];
		if ( ! self::matches( $work . '/' . $slug, $expected, ! empty( $manifest['single'] ) ) ) {
			$fs->delete( Fs::remote( $work ), true );
			return new \WP_Error( 'updlock_verify', __( 'The snapshot failed its integrity check, so nothing was changed.', 'update-airlock' ) );
		}

		update_option(
			self::SWAP,
			array(
				'target' => $target,
				'trash'  => $trash,
				'work'   => $work,
				'id'     => $entry['id'],
				'time'   => time(),
			),
			false
		);
		if ( file_exists( $target ) && ! $fs->move( Fs::remote( $target ), Fs::remote( $trash ), true ) ) {
			$fs->delete( Fs::remote( $work ), true );
			delete_option( self::SWAP );
			return new \WP_Error( 'updlock_move', __( 'The current copy could not be moved aside, so nothing was changed.', 'update-airlock' ) );
		}
		if ( ! $fs->move( Fs::remote( $work . '/' . $slug ), Fs::remote( $target ), true ) ) {
			return self::put_back( $fs, $target, $trash, $work );
		}
		if ( ! self::matches( $target, $expected, ! empty( $manifest['single'] ) ) ) {
			$fs->delete( Fs::remote( $target ), true );
			return self::put_back( $fs, $target, $trash, $work );
		}
		$fs->delete( Fs::remote( $trash ), true );
		$fs->delete( Fs::remote( $work ), true );
		delete_option( self::SWAP );
		// Renames do not refresh the code cache; without this the old files can run for a few more seconds.
		if ( is_dir( $target ) ) {
			wp_opcache_invalidate_directory( $target );
		}
		return true;
	}

	/**
	 * Moves the old copy back after a failed swap; if that fails too, keeps it and says where it is.
	 *
	 * @param \WP_Filesystem_Base $fs     Filesystem.
	 * @param string              $target Target path.
	 * @param string              $trash  Where the old copy was moved.
	 * @param string              $work   Work folder.
	 * @return \WP_Error
	 */
	private static function put_back( $fs, string $target, string $trash, string $work ) {
		$fs->delete( Fs::remote( $work ), true );
		if ( ! file_exists( $trash ) ) {
			delete_option( self::SWAP );
			return new \WP_Error( 'updlock_swap', __( 'The restore failed.', 'update-airlock' ) );
		}
		if ( $fs->move( Fs::remote( $trash ), Fs::remote( $target ), true ) ) {
			delete_option( self::SWAP );
			return new \WP_Error( 'updlock_swap', __( 'The restore failed and the previous copy was put back.', 'update-airlock' ) );
		}
		/* translators: %s: folder path */
		return new \WP_Error( 'updlock_swap_kept', sprintf( __( 'The restore failed and the previous copy could not be moved back. It is kept at %s.', 'update-airlock' ), $trash ) );
	}

	/**
	 * Finishes or undoes a swap that was interrupted. Safe to call on every admin load.
	 */
	public static function recover(): void {
		$swap = get_option( self::SWAP );
		if ( ! is_array( $swap ) || empty( $swap['target'] ) || time() - (int) ( $swap['time'] ?? 0 ) < 120 ) {
			return;
		}
		$fs = Fs::get();
		if ( ! $fs ) {
			return;
		}
		$manifest = Store::manifest( (string) ( $swap['id'] ?? '' ) );
		$ok       = $manifest && file_exists( $swap['target'] ) && self::matches( $swap['target'], (array) $manifest['files'], ! empty( $manifest['single'] ) );
		if ( $ok ) {
			if ( file_exists( $swap['trash'] ) ) {
				$fs->delete( Fs::remote( $swap['trash'] ), true );
			}
		} elseif ( file_exists( $swap['trash'] ) ) {
			if ( file_exists( $swap['target'] ) ) {
				$fs->delete( Fs::remote( $swap['target'] ), true );
			}
			if ( ! $fs->move( Fs::remote( $swap['trash'] ), Fs::remote( $swap['target'] ), true ) ) {
				return;
			}
		}
		if ( ! empty( $swap['work'] ) && file_exists( $swap['work'] ) ) {
			$fs->delete( Fs::remote( $swap['work'] ), true );
		}
		delete_option( self::SWAP );
	}

	/**
	 * Whether a folder (or file) holds exactly the files in a hash list.
	 *
	 * @param string               $path     Folder, or file for a single-file item.
	 * @param array<string,string> $expected Relative path => sha256.
	 * @param bool                 $single   Whether the item is one file.
	 * @return bool
	 */
	public static function matches( string $path, array $expected, bool $single ): bool {
		if ( $single ) {
			$name = (string) key( $expected );
			return is_file( $path ) && 1 === count( $expected ) && hash_file( 'sha256', $path ) === $expected[ $name ];
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		$have = Store::files( $path );
		if ( array_keys( $have ) !== array_keys( $expected ) ) {
			return false;
		}
		foreach ( $have as $rel => $abs ) {
			if ( hash_file( 'sha256', $abs ) !== $expected[ $rel ] ) {
				return false;
			}
		}
		return true;
	}
}

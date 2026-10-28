<?php
/**
 * Finds the option names a plugin's own code names literally (pure logic).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Snapshot;

use Updlock\Inspect\Php_Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option names read from source.
 */
class Owned_Options {

	/**
	 * Option functions whose first argument is an option name.
	 *
	 * @var string[]
	 */
	const FUNCS = array( 'get_option', 'update_option', 'add_option', 'delete_option' );

	/**
	 * Option names that belong to WordPress itself.
	 */
	const CORE = '/^(siteurl|home|blog\w*|admin_email|active_plugins|template|stylesheet|current_theme|permalink_structure|date_format|time_format|timezone_string|gmt_offset|WPLANG|db_version|initial_db_version|users_can_register|default_\w+|comment_\w+|posts_per_page|show_on_front|page_on_front|page_for_posts|upload\w*|widget_\w+|sidebars_widgets|theme_mods_\w+|cron|rewrite_rules|category_base|tag_base|use_\w+|ping_sites|moderation_\w+|avatar_\w+|recently_activated|fresh_site|auto_update_\w+|wp_user_roles|uninstall_plugins|rss_\w+|links_\w+|medium_\w+|large_\w+|thumbnail_\w+|image_default_\w+|mailserver_\w+|embed_\w+|close_comments_\w+|page_comments|html_type|require_name_email|sticky_posts|show_avatars|admin_email_lastchecked|can_compress_scripts|finished_splitting_shared_terms|site_icon|nav_menu_options|wp_page_for_privacy_policy|show_comments_cookies_opt_in)$/';

	/**
	 * Literal option names used by PHP source.
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	public static function from_source( string $code ): array {
		$scan = new Php_Scan( $code );
		$out  = array();
		foreach ( $scan->calls() as $call ) {
			if ( 'func' !== $call['kind'] || ! in_array( $call['name'], self::FUNCS, true ) ) {
				continue;
			}
			$args = $scan->args( $call['open'] );
			if ( ! $args || $args[0][0] !== $args[0][1] || T_CONSTANT_ENCAPSED_STRING !== $scan->toks[ $args[0][0] ]['id'] ) {
				continue;
			}
			$name = Php_Scan::unquote( $scan->toks[ $args[0][0] ]['text'] );
			if ( self::owned( $name ) ) {
				$out[ $name ] = $name;
			}
		}
		return array_values( $out );
	}

	/**
	 * Whether a name could belong to a plugin (not core, not a transient).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function owned( string $name ): bool {
		return '' !== $name && strlen( $name ) <= 191 && '_' !== $name[0] && ! preg_match( self::CORE, $name );
	}

	/**
	 * Literal option names in every PHP file under a folder.
	 *
	 * @param string $dir Plugin folder.
	 * @return string[]
	 */
	public static function from_dir( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $item ) {
			if ( $item->isLink() || ! $item->isFile() || 'php' !== strtolower( $item->getExtension() ) || $item->getSize() > 1048576 ) {
				continue;
			}
			$code = file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file to inspect it; never executed.
			if ( false !== $code ) {
				$out = array_merge( $out, self::from_source( $code ) );
			}
		}
		return array_values( array_unique( $out ) );
	}
}

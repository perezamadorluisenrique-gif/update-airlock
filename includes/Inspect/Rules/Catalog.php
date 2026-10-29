<?php
/**
 * The rule table: ids, severities and plain-language explanations.
 *
 * Dangerous function names appear in this plugin only as strings in tables like this one.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Inspect\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule metadata and finding construction.
 */
class Catalog {

	/**
	 * Rules by id: severity (info, low, medium, high), short phrase, explanation.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function all(): array {
		return array(
			'exec.eval'            => array( 'high', __( 'runs code built at run time (eval)', 'update-airlock' ), __( 'Code built as a string and executed hides what really runs.', 'update-airlock' ) ),
			'exec.assert_dynamic'  => array( 'high', __( 'asserts a non-literal expression', 'update-airlock' ), __( 'On older PHP, an assert call with a string runs it as code.', 'update-airlock' ) ),
			'exec.create_function' => array( 'high', __( 'builds a function from a string', 'update-airlock' ), __( 'create_function() compiles a string as code.', 'update-airlock' ) ),
			'exec.preg_e'          => array( 'high', __( 'uses a regex replace that runs code (/e)', 'update-airlock' ), __( 'The /e modifier executes the replacement as code.', 'update-airlock' ) ),
			'exec.request_call'    => array( 'high', __( 'calls a function chosen by request data', 'update-airlock' ), __( 'A callable that comes from the request lets a visitor pick what runs.', 'update-airlock' ) ),
			'exec.shell'           => array( 'medium', __( 'runs a system command', 'update-airlock' ), __( 'Shell commands run outside WordPress with the web server user.', 'update-airlock' ) ),
			'exec.shell_request'   => array( 'high', __( 'runs a system command built from request data', 'update-airlock' ), __( 'Request data reaching a shell command is a classic takeover path.', 'update-airlock' ) ),
			'exec.include_remote'  => array( 'high', __( 'includes a file from a URL or from request data', 'update-airlock' ), __( 'Including remote or request-chosen files executes them.', 'update-airlock' ) ),
			'deser.remote'         => array( 'high', __( 'unserializes remote or request data', 'update-airlock' ), __( 'Unserializing data from outside can instantiate attacker-chosen objects.', 'update-airlock' ) ),
			'deser.dynamic'        => array( 'low', __( 'unserializes a value that is not a literal', 'update-airlock' ), __( 'Check where the serialized value comes from.', 'update-airlock' ) ),
			'obf.decode_chain'     => array( 'high', __( 'chains decoding functions', 'update-airlock' ), __( 'Several decode steps in a row are a common way to hide code.', 'update-airlock' ) ),
			'obf.decode'           => array( 'low', __( 'decodes a value (base64 and similar)', 'update-airlock' ), __( 'Decoding is common, check what is decoded.', 'update-airlock' ) ),
			'obf.chr_concat'       => array( 'medium', __( 'builds text from many character codes', 'update-airlock' ), __( 'Spelling words one character code at a time hides strings.', 'update-airlock' ) ),
			'obf.long_string'      => array( 'medium', __( 'adds a long high-entropy string', 'update-airlock' ), __( 'Long random-looking strings often hold packed or encoded code.', 'update-airlock' ) ),
			'obf.php_in_nonphp'    => array( 'high', __( 'PHP code inside a non-PHP file', 'update-airlock' ), __( 'A picture or text file that contains PHP is a hiding place.', 'update-airlock' ) ),
			'obf.php_in_assets'    => array( 'medium', __( 'new PHP file in an asset folder', 'update-airlock' ), __( 'Asset folders normally hold no PHP.', 'update-airlock' ) ),
			'obf.new_minified'     => array( 'low', __( 'new minified script or stylesheet', 'update-airlock' ), __( 'Minified code is hard to review; compare it with its source.', 'update-airlock' ) ),
			'net.new_host'         => array( 'medium', __( 'adds an outbound host', 'update-airlock' ), __( 'The code can now contact a server it did not before.', 'update-airlock' ) ),
			'net.new_host_low'     => array( 'low', __( 'adds a host used in a network call to a known project site', 'update-airlock' ), __( 'The code contacts a wordpress.org or gravatar.com host.', 'update-airlock' ) ),
			'net.ip_host'          => array( 'high', __( 'adds a raw IP address as a host', 'update-airlock' ), __( 'Contacting a bare IP address is unusual for a plugin.', 'update-airlock' ) ),
			'net.url_literal'      => array( 'low', __( 'adds a URL', 'update-airlock' ), __( 'A new URL appears in the code.', 'update-airlock' ) ),
			'persist.wp_config'    => array( 'high', __( 'writes to wp-config.php', 'update-airlock' ), __( 'Changing wp-config.php changes how the whole site loads.', 'update-airlock' ) ),
			'persist.htaccess'     => array( 'medium', __( 'writes to .htaccess', 'update-airlock' ), __( 'Server rules written by a plugin can redirect or open the site.', 'update-airlock' ) ),
			'persist.mu_plugin'    => array( 'high', __( 'writes to mu-plugins', 'update-airlock' ), __( 'Must-use plugins load first and cannot be deactivated from the admin.', 'update-airlock' ) ),
			'persist.other_plugin' => array( 'medium', __( 'writes into another plugin or theme folder', 'update-airlock' ), __( 'A plugin changing other code on disk is unusual.', 'update-airlock' ) ),
			'persist.write_php'    => array( 'medium', __( 'writes a PHP file', 'update-airlock' ), __( 'Code that writes PHP files creates code that was not reviewed.', 'update-airlock' ) ),
			'persist.cron'         => array( 'low', __( 'schedules a recurring task', 'update-airlock' ), __( 'A new scheduled task will run in the background.', 'update-airlock' ) ),
			'priv.admin_user'      => array( 'high', __( 'creates or promotes an administrator', 'update-airlock' ), __( 'A new administrator account gives full control.', 'update-airlock' ) ),
			'priv.user_create'     => array( 'medium', __( 'creates a user account', 'update-airlock' ), __( 'The code can create accounts.', 'update-airlock' ) ),
			'priv.hide_users'      => array( 'high', __( 'hides users from the user list', 'update-airlock' ), __( 'Hiding users hides who can log in.', 'update-airlock' ) ),
			'priv.hide_self'       => array( 'medium', __( 'hides plugins from the plugin list', 'update-airlock' ), __( 'Hiding plugins hides what is installed.', 'update-airlock' ) ),
			'priv.rest_open'       => array( 'medium', __( 'adds an open REST route', 'update-airlock' ), __( 'A route that always allows access can be called by anyone.', 'update-airlock' ) ),
			'hijack.update_uri'    => array( 'high', __( 'adds or changes the Update URI header', 'update-airlock' ), __( 'Update URI decides where this plugin gets its updates.', 'update-airlock' ) ),
			'hijack.update_filter' => array( 'medium', __( 'changes update information', 'update-airlock' ), __( 'Filters on update data can redirect or hide updates.', 'update-airlock' ) ),
			'hijack.update_hide'   => array( 'high', __( 'removes other plugins from the update list', 'update-airlock' ), __( 'Hiding updates keeps other plugins stuck on old versions.', 'update-airlock' ) ),
			'hijack.update_source' => array( 'medium', __( 'changes where packages come from', 'update-airlock' ), __( 'Package filters can swap what gets installed.', 'update-airlock' ) ),
			'js.eval'              => array( 'medium', __( 'runs code built at run time in JavaScript', 'update-airlock' ), __( 'eval() and new Function() execute strings.', 'update-airlock' ) ),
			'js.new_host'          => array( 'medium', __( 'loads or calls a new outside host from JavaScript', 'update-airlock' ), __( 'Scripts can now talk to another server.', 'update-airlock' ) ),
			'prov.author_changed'  => array( 'high', __( 'changed author name and author link', 'update-airlock' ), __( 'Both the author and the author link changed.', 'update-airlock' ) ),
			'prov.author_name'     => array( 'medium', __( 'changed the author', 'update-airlock' ), __( 'The author header is different from the installed version.', 'update-airlock' ) ),
			'prov.author_uri'      => array( 'medium', __( 'changed the author link', 'update-airlock' ), __( 'The author link is different from the installed version.', 'update-airlock' ) ),
			'prov.plugin_uri'      => array( 'low', __( 'changed the plugin link', 'update-airlock' ), __( 'The plugin or theme link is different from the installed version.', 'update-airlock' ) ),
			'prov.contributors'    => array( 'medium', __( 'changed the readme contributors', 'update-airlock' ), __( 'The list of contributors is different from the installed version.', 'update-airlock' ) ),
			'prov.compat_only'     => array( 'medium', __( 'the changelog mentions only compatibility but the code changed a lot', 'update-airlock' ), __( 'The changelog does not describe the size of the code change.', 'update-airlock' ) ),
			'prov.compat_only_big' => array( 'high', __( 'the changelog mentions only compatibility but a lot of code was added', 'update-airlock' ), __( 'A compatibility-only changelog next to a large code change is a known pattern.', 'update-airlock' ) ),
		);
	}

	/**
	 * Builds a finding.
	 *
	 * @param string   $rule   Rule id.
	 * @param string   $file   Relative file path.
	 * @param int      $line   Line number (0 when not tied to a line).
	 * @param string[] $lines  Source lines (0-based) for the excerpt.
	 * @param string   $detail Extra detail, such as a host name.
	 * @return array{rule:string,severity:string,file:string,line:int,excerpt:string,detail:string}
	 */
	public static function make( string $rule, string $file, int $line, array $lines, string $detail = '' ): array {
		$all      = self::all();
		$severity = isset( $all[ $rule ] ) ? $all[ $rule ][0] : 'low';
		$excerpt  = '';
		if ( $line > 0 && isset( $lines[ $line - 1 ] ) ) {
			$excerpt = trim( $lines[ $line - 1 ] );
			if ( strlen( $excerpt ) > 160 ) {
				$excerpt = substr( $excerpt, 0, 157 ) . '...';
			}
		}
		return array(
			'rule'     => $rule,
			'severity' => $severity,
			'file'     => $file,
			'line'     => $line,
			'excerpt'  => $excerpt,
			'detail'   => $detail,
		);
	}

	/**
	 * Whether a line number counts as added code.
	 *
	 * @param array<int,bool>|null $added Added line numbers as keys, or null when the whole file is new.
	 * @param int                  $line  Line number.
	 * @return bool
	 */
	public static function is_added( $added, int $line ): bool {
		return null === $added || isset( $added[ $line ] );
	}
}

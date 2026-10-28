<?php
/**
 * Unit test bootstrap: loads the pure classes and a few stand-ins for WordPress functions.
 * The whole plugin is exercised on a real WordPress by tests/e2e.
 *
 * @package UpdateAirlock
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === $number ? $single : $plural;
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function absint( $value ) {
	return abs( (int) $value );
}

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Updlock\\' ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/includes/' . str_replace( '\\', '/', substr( $class_name, 8 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// The HTML API decoder reads this table as a global, so declare it before loading it.
global $html5_named_character_references;
$updlock_core = dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes/';
if ( is_readable( $updlock_core . 'class-wp-token-map.php' ) ) {
	require_once $updlock_core . 'class-wp-token-map.php';
	foreach ( array( 'html5-named-character-references', 'class-wp-html-decoder', 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-tag-processor' ) as $updlock_file ) {
		require_once $updlock_core . 'html-api/' . $updlock_file . '.php';
	}
}

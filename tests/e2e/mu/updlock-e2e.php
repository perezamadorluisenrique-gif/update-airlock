<?php
/**
 * Plugin Name: Update Airlock e2e helpers (test code, never shipped)
 */

$updlock_e2e_dir = WP_CONTENT_DIR . '/e2e/';

function updlock_e2e_json( $name ) {
	$path = WP_CONTENT_DIR . '/e2e/' . $name;
	return is_readable( $path ) ? (array) json_decode( file_get_contents( $path ), true ) : array();
}

if ( ! empty( updlock_e2e_json( 'flags.json' )['pclzip'] ) ) {
	define( 'UPDLOCK_FORCE_PCLZIP', true );
}

add_filter( 'http_request_host_is_external', '__return_true' );

// Fixture update server: updates.json maps "kind:slug" => { file, version, package }.
add_filter(
	'site_transient_update_plugins',
	function ( $value ) {
		$updates = updlock_e2e_json( 'updates.json' );
		if ( ! is_object( $value ) ) {
			$value = new stdClass();
		}
		if ( ! isset( $value->response ) ) {
			$value->response = array();
		}
		foreach ( $updates as $key => $u ) {
			if ( 0 !== strpos( $key, 'plugin:' ) || ! file_exists( WP_PLUGIN_DIR . '/' . $u['file'] ) ) {
				continue;
			}
			$data = get_file_data( WP_PLUGIN_DIR . '/' . $u['file'], array( 'v' => 'Version' ) );
			if ( version_compare( $data['v'], $u['version'], '<' ) ) {
				$value->response[ $u['file'] ] = (object) array(
					'id'          => 'fixture/' . $u['file'],
					'slug'        => dirname( $u['file'] ),
					'plugin'      => $u['file'],
					'new_version' => $u['version'],
					'package'     => $u['package'],
					'url'         => 'https://fixture.test/',
				);
			}
		}
		return $value;
	}
);
add_filter(
	'site_transient_update_themes',
	function ( $value ) {
		$updates = updlock_e2e_json( 'updates.json' );
		if ( ! is_object( $value ) ) {
			$value = new stdClass();
		}
		if ( ! isset( $value->response ) ) {
			$value->response = array();
		}
		foreach ( $updates as $key => $u ) {
			if ( 0 !== strpos( $key, 'theme:' ) ) {
				continue;
			}
			$theme = wp_get_theme( $u['slug'] );
			if ( $theme->exists() && version_compare( (string) $theme->get( 'Version' ), $u['version'], '<' ) ) {
				$value->response[ $u['slug'] ] = array(
					'theme'       => $u['slug'],
					'new_version' => $u['version'],
					'package'     => $u['package'],
					'url'         => 'https://fixture.test/',
				);
			}
		}
		return $value;
	}
);

// Loopback blocked on purpose (only registered while the flag is set; the site config already blocks external hosts).
if ( ! empty( updlock_e2e_json( 'flags.json' )['block_loopback'] ) ) {
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'updlock_check' ) ) {
				return new WP_Error( 'e2e_loopback', 'loopback blocked by the test' );
			}
			return $pre;
		},
		10,
		3
	);
}

// Mail capture.
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/e2e/mail.log', wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] ) ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );

add_filter( 'automatic_updater_disabled', '__return_false' );

// The throwaway site sits inside a git checkout; core would refuse automatic updates there.
add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );

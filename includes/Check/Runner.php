<?php
/**
 * Loopback checks: requests to the site's own pages, with a baseline to compare against.
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Check;

use Updlock\Core\Fs;
use Updlock\Core\Opt;
use Updlock\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the URL list, fetches pages and keeps baselines.
 */
class Runner {

	const BASELINE = 'updlock_baselines';

	/**
	 * Pages worth checking: the suggested ones plus the user's own, without limit.
	 *
	 * @return array<string,string> URL => label.
	 */
	public static function urls(): array {
		$out = array( home_url( '/' ) => __( 'Home page', 'update-airlock' ) );

		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);
		if ( $posts ) {
			$out[ get_permalink( $posts[0] ) ] = __( 'Latest post', 'update-airlock' );
		}
		$pages = get_pages( array( 'number' => 1 ) );
		if ( $pages ) {
			$out[ get_permalink( $pages[0] ) ] = __( 'A page', 'update-airlock' );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'number'     => 1,
				'hide_empty' => true,
			)
		);
		if ( is_array( $terms ) && $terms && ! is_wp_error( get_term_link( $terms[0] ) ) ) {
			$out[ get_term_link( $terms[0] ) ] = __( 'A category archive', 'update-airlock' );
		}
		$out[ rest_url() ]     = __( 'REST API index', 'update-airlock' );
		$out[ wp_login_url() ] = __( 'Login page', 'update-airlock' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$out[ wc_get_page_permalink( 'shop' ) ]     = __( 'Shop', 'update-airlock' );
			$out[ wc_get_page_permalink( 'cart' ) ]     = __( 'Cart', 'update-airlock' );
			$out[ wc_get_page_permalink( 'checkout' ) ] = __( 'Checkout', 'update-airlock' );
		}
		foreach ( Settings::extra_urls() as $url ) {
			$out[ $url ] = __( 'Added by you', 'update-airlock' );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array_keys( $out ) as $url ) {
			if ( ! is_string( $url ) || '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				unset( $out[ $url ] );
			}
		}
		return $out;
	}

	/**
	 * The admin check URL (needs a signed-in user, so it is skipped in cron and the command line).
	 *
	 * @return string Empty when not available.
	 */
	public static function admin_url(): string {
		return ( is_user_logged_in() && ! wp_doing_cron() && ! defined( 'WP_CLI' ) ) ? admin_url( 'index.php' ) : '';
	}

	/**
	 * Request arguments shared by all loopback requests.
	 *
	 * @param bool $admin Whether to send the signed-in user's cookies.
	 * @return array<string,mixed>
	 */
	private static function args( bool $admin ): array {
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the same way core does for loopback requests.
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Connection'    => 'close',
			),
		);
		if ( $admin ) {
			$cookies = array();
			foreach ( array( LOGGED_IN_COOKIE, AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $name ) {
				if ( isset( $_COOKIE[ $name ] ) ) {
					$cookies[] = new \WP_Http_Cookie(
						array(
							'name'  => $name,
							'value' => sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ),
						)
					);
				}
			}
			$args['cookies'] = $cookies;
		}
		return $args;
	}

	/**
	 * Fetches pages. Pages are requested in parallel; any that fail are retried one at a time.
	 *
	 * @param string[] $urls         URLs on this site.
	 * @param string   $asset_prefix URL prefix of the updated plugin or theme (for the missing-asset check).
	 * @return array<string,array<string,mixed>> URL => metrics.
	 */
	public static function fetch( array $urls, string $asset_prefix = '' ): array {
		$admin_url = self::admin_url();
		$results   = array();
		$todo      = array();
		foreach ( $urls as $url ) {
			$todo[ $url ] = add_query_arg( 'updlock_check', wp_rand( 1000, 999999 ), $url );
		}
		$parallel = array();
		// Requests bypasses the HTTP API filters, so a site that intercepts requests there gets the sequential path.
		if ( count( $todo ) > 1 && class_exists( '\WpOrg\Requests\Requests' ) && ! has_filter( 'pre_http_request' ) ) {
			$parallel = self::parallel( $todo, $admin_url );
		}
		foreach ( $todo as $url => $request_url ) {
			$start = microtime( true );
			if ( isset( $parallel[ $url ] ) && null !== $parallel[ $url ]['response'] ) {
				$results[ $url ] = self::metrics( $parallel[ $url ]['response'], (int) round( $parallel[ $url ]['time'] * 1000 ), $asset_prefix, $url );
				continue;
			}
			$response        = wp_remote_get( $request_url, self::args( $url === $admin_url ) );
			$results[ $url ] = self::metrics( $response, (int) round( ( microtime( true ) - $start ) * 1000 ), $asset_prefix, $url );
		}
		// A burst of requests can make a small host answer 5xx once; look again alone before judging.
		foreach ( $results as $url => $metrics ) {
			if ( (int) ( $metrics['status'] ?? 0 ) >= 500 && empty( $metrics['maint'] ) ) {
				usleep( 700000 );
				$start           = microtime( true );
				$response        = wp_remote_get( $todo[ $url ], self::args( $url === $admin_url ) );
				$results[ $url ] = self::metrics( $response, (int) round( ( microtime( true ) - $start ) * 1000 ), $asset_prefix, $url );
			}
		}
		return $results;
	}

	/**
	 * Parallel fetch through the Requests library bundled with WordPress.
	 *
	 * @param array<string,string> $todo      URL => request URL.
	 * @param string               $admin_url Admin URL (gets cookies) or ''.
	 * @return array<string,array{response:mixed,time:float}> Entries with a null response failed.
	 */
	private static function parallel( array $todo, string $admin_url ): array {
		$out   = array();
		$start = microtime( true );
		$times = array();
		$ids   = array();
		try {
			$requests = array();
			foreach ( $todo as $url => $request_url ) {
				$args    = self::args( $url === $admin_url );
				$headers = $args['headers'];
				if ( ! empty( $args['cookies'] ) ) {
					$headers['Cookie'] = implode(
						'; ',
						array_map(
							static function ( $c ) {
								return $c->name . '=' . $c->value;
							},
							$args['cookies']
						)
					);
				}
				$requests[ $url ] = array(
					'url'     => $request_url,
					'headers' => $headers,
					'type'    => 'GET',
					'options' => array(
						'timeout'          => 10,
						'connect_timeout'  => 10,
						'follow_redirects' => false,
						'verify'           => (bool) $args['sslverify'],
					),
				);
			}
			$hooks = new \WpOrg\Requests\Hooks();
			$hooks->register(
				'multiple.request.complete',
				static function ( $response, $id ) use ( &$times, $start ) {
					$times[ $id ] = microtime( true ) - $start;
				}
			);
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, array( 'hooks' => $hooks ) );
			foreach ( $responses as $id => $response ) {
				$ok         = $response instanceof \WpOrg\Requests\Response;
				$out[ $id ] = array(
					'response' => $ok ? $response : null,
					'time'     => $times[ $id ] ?? ( microtime( true ) - $start ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * Reduces a response to the numbers and flags we compare.
	 *
	 * @param mixed  $response     wp_remote_get() result, or a Requests response.
	 * @param int    $ms           Elapsed milliseconds.
	 * @param string $asset_prefix URL prefix for the asset check.
	 * @param string $url          The page URL.
	 * @return array<string,mixed>
	 */
	private static function metrics( $response, int $ms, string $asset_prefix, string $url ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'ms'     => $ms,
				'error'  => $response->get_error_message(),
			);
		}
		if ( $response instanceof \WpOrg\Requests\Response ) {
			$status = (int) $response->status_code;
			$body   = (string) $response->body;
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );
		}
		if ( 0 === $status ) {
			return array(
				'status' => 0,
				'ms'     => $ms,
				'error'  => __( 'No response.', 'update-airlock' ),
			);
		}
		$is_html = false !== strpos( $body, '<' );
		$out     = array(
			'status' => $status,
			'ms'     => $ms,
			'bytes'  => strlen( $body ),
			'fatal'  => Fingerprint::fatal( $body ),
			'maint'  => 503 === $status && Fingerprint::maintenance( $body ),
		);
		if ( $is_html && strlen( $body ) < 3000000 ) {
			$out['fp'] = Fingerprint::of( $body );
			if ( '' !== $asset_prefix ) {
				$out['assets'] = Fingerprint::assets( $body, $asset_prefix );
			}
		}
		unset( $url );
		return $out;
	}

	/**
	 * HEAD-checks asset URLs.
	 *
	 * @param string[] $assets Asset URLs.
	 * @return string[] URLs that did not load.
	 */
	public static function missing_assets( array $assets ): array {
		$missing = array();
		foreach ( $assets as $asset ) {
			// Follow redirects: a missing file on a site with pretty permalinks is often redirected to a guessed page.
			$res = wp_remote_head( $asset, array_merge( self::args( false ), array( 'redirection' => 3 ) ) );
			if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
				$missing[] = $asset;
				continue;
			}
			// With pretty permalinks a missing file can fall through to WordPress and come back as an HTML page.
			$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
			if ( false !== strpos( $type, 'text/html' ) && preg_match( '/\.(js|css)(\?|$)/i', $asset ) ) {
				$missing[] = $asset;
			}
		}
		return $missing;
	}

	/**
	 * The stored baseline.
	 *
	 * @return array{ts:int,urls:array<string,array<string,mixed>>}
	 */
	public static function baseline(): array {
		$base = Opt::get( self::BASELINE, array() );
		return is_array( $base ) && isset( $base['urls'] ) ? $base : array(
			'ts'   => 0,
			'urls' => array(),
		);
	}

	/**
	 * Captures a new baseline of every suggested page.
	 *
	 * @return array{ts:int,urls:array<string,array<string,mixed>>}
	 */
	public static function refresh_baseline(): array {
		if ( Fs::maintenance() ) {
			return self::baseline();
		}
		$urls  = array_keys( self::urls() );
		$admin = self::admin_url();
		if ( '' !== $admin ) {
			$urls[] = $admin;
		}
		$data = array(
			'ts'   => time(),
			'urls' => self::fetch( $urls ),
		);
		Opt::set( self::BASELINE, $data, false );
		return $data;
	}

	/**
	 * Refreshes the baseline when it is older than a day.
	 */
	public static function maybe_refresh_baseline(): void {
		$base = self::baseline();
		if ( time() - $base['ts'] > DAY_IN_SECONDS ) {
			self::refresh_baseline();
		}
	}
}

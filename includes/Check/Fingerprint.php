<?php
/**
 * Page structure fingerprint and failure markers (pure logic over an HTML string).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts what a page is made of, so a before/after comparison can tell a broken page from a changed one.
 */
class Fingerprint {

	/**
	 * Counts structural elements of an HTML document.
	 *
	 * @param string $html Page HTML.
	 * @return array<string,int|string>
	 */
	public static function of( string $html ): array {
		$out      = array(
			'title'     => '',
			'headings'  => 0,
			'forms'     => 0,
			'buttons'   => 0,
			'inputs'    => 0,
			'scripts'   => 0,
			'links'     => 0,
			'images'    => 0,
			'landmarks' => 0,
			'tags'      => 0,
			'bytes'     => strlen( $html ),
		);
		$p        = new \WP_HTML_Tag_Processor( $html );
		$in_title = false;
		while ( $p->next_token() ) {
			$type = $p->get_token_type();
			if ( '#text' === $type && $in_title ) {
				$out['title'] = trim( $p->get_modifiable_text() );
				$in_title     = false;
				continue;
			}
			if ( '#tag' !== $type || $p->is_tag_closer() ) {
				continue;
			}
			$tag = strtolower( (string) $p->get_tag() );
			++$out['tags'];
			if ( 'title' === $tag ) {
				$in_title = true;
			} elseif ( preg_match( '/^h[1-6]$/', $tag ) ) {
				++$out['headings'];
			} elseif ( 'form' === $tag ) {
				++$out['forms'];
			} elseif ( 'button' === $tag ) {
				++$out['buttons'];
			} elseif ( in_array( $tag, array( 'input', 'select', 'textarea' ), true ) ) {
				++$out['inputs'];
			} elseif ( 'script' === $tag ) {
				++$out['scripts'];
			} elseif ( 'a' === $tag ) {
				++$out['links'];
			} elseif ( 'img' === $tag ) {
				++$out['images'];
			} elseif ( in_array( $tag, array( 'main', 'nav', 'header', 'footer', 'aside' ), true ) ) {
				++$out['landmarks'];
			}
		}
		return $out;
	}

	/**
	 * Size of the visible structure: elements a visitor sees.
	 *
	 * @param array<string,int|string> $fp Fingerprint.
	 * @return int
	 */
	private static function weight( array $fp ): int {
		return (int) $fp['headings'] + (int) $fp['forms'] + (int) $fp['buttons'] + (int) $fp['inputs'] + (int) $fp['links'] + (int) $fp['images'] + (int) $fp['landmarks'];
	}

	/**
	 * Whether a page lost most of its structure compared with the baseline.
	 *
	 * @param array<string,int|string> $before Baseline fingerprint.
	 * @param array<string,int|string> $after  Fingerprint after the update.
	 * @return bool
	 */
	public static function collapsed( array $before, array $after ): bool {
		$was = self::weight( $before );
		$now = self::weight( $after );
		if ( (int) $before['bytes'] > 2000 && $was >= 8 && $now < $was * 0.4 ) {
			return true;
		}
		return '' !== (string) $before['title'] && '' === (string) $after['title'] && (int) $after['tags'] < (int) $before['tags'] * 0.5 && $was >= 8;
	}

	/**
	 * Whether a body shows a PHP failure.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	public static function fatal( string $body ): bool {
		return false !== stripos( $body, 'There has been a critical error' )
			|| (bool) preg_match( '/(<b>)?\s*(Fatal error|Parse error)\s*(<\/b>)?\s*:/i', $body )
			|| (bool) preg_match( '/Uncaught\s+(Error|Exception|TypeError|ParseError|ArgumentCountError)/', $body );
	}

	/**
	 * Whether a body is core's maintenance page.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	public static function maintenance( string $body ): bool {
		return false !== stripos( $body, 'Briefly unavailable for scheduled maintenance' );
	}

	/**
	 * Script and stylesheet URLs that live under a prefix (the updated plugin's or theme's folder URL).
	 *
	 * @param string $html   Page HTML.
	 * @param string $prefix URL prefix.
	 * @return string[]
	 */
	public static function assets( string $html, string $prefix ): array {
		$out = array();
		$p   = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = strtolower( (string) $p->get_tag() );
			$url = null;
			if ( 'script' === $tag ) {
				$url = $p->get_attribute( 'src' );
			} elseif ( 'link' === $tag && false !== stripos( (string) $p->get_attribute( 'rel' ), 'stylesheet' ) ) {
				$url = $p->get_attribute( 'href' );
			}
			if ( is_string( $url ) && 0 === strpos( $url, $prefix ) ) {
				$out[ $url ] = $url;
			}
		}
		return array_values( $out );
	}
}

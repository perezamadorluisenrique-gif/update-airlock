<?php
/**
 * Alert emails (wp_mail only).
 *
 * @package UpdateAirlock
 */

declare(strict_types=1);

namespace Updlock\Notify;

use Updlock\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-text emails with a link to the evidence.
 */
class Mailer {

	/**
	 * Sends an alert about an event.
	 *
	 * @param string   $headline One line, such as "Rolled back".
	 * @param array    $event    Event row.
	 * @param string[] $lines    Evidence lines.
	 */
	public static function alert( string $headline, array $event, array $lines ): void {
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: short headline, 3: plugin or theme name, 4: version */
		$subject = sprintf( __( '[%1$s] Update Airlock: %2$s - %3$s %4$s', 'update-airlock' ), $site, $headline, (string) $event['name'], (string) $event['to_ver'] );
		$link    = \Updlock\Admin\Labels::event_url( (int) $event['id'] );
		$body    = $headline . "\n\n" . implode( "\n", $lines ) . "\n\n" . __( 'Full evidence:', 'update-airlock' ) . ' ' . $link . "\n";
		wp_mail( Settings::recipients(), $subject, $body );
	}
}

<?php
/**
 * Tests for Updlock\Core\Settings::sanitize.
 *
 * @package UpdateAirlock
 */

use PHPUnit\Framework\TestCase;
use Updlock\Core\Settings;

class SettingsTest extends TestCase {

	public function test_flags_are_strict_booleans() {
		$out = Settings::sanitize(
			array(
				'hold_auto_high'      => 'false',
				'confirm_manual_high' => '0',
				'cancel_no_snapshot'  => '',
				'auto_rollback'       => '1',
				'run_checks'          => 'true',
			)
		);
		$this->assertSame( 0, $out['hold_auto_high'] );
		$this->assertSame( 0, $out['confirm_manual_high'] );
		$this->assertSame( 0, $out['cancel_no_snapshot'] );
		$this->assertSame( 1, $out['auto_rollback'] );
		$this->assertSame( 1, $out['run_checks'] );
		$this->assertSame( 0, $out['keep_data'] );
	}

	public function test_keep_snapshots_is_at_least_one_and_emails_are_filtered() {
		$out = Settings::sanitize( array( 'keep_snapshots' => '-5', 'emails' => 'a@example.org, nope, a@example.org' ) );
		$this->assertSame( 5, $out['keep_snapshots'] );
		$this->assertSame( 'a@example.org', $out['emails'] );
		$this->assertSame( 1, Settings::sanitize( array( 'keep_snapshots' => 0 ) )['keep_snapshots'] );
		$this->assertSame( 0, Settings::sanitize( 'junk' )['run_checks'] );
	}
}

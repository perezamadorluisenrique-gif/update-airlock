<?php
/**
 * Tests for Updlock\Inspect\Differ.
 *
 * @package UpdateAirlock
 */

use PHPUnit\Framework\TestCase;
use Updlock\Inspect\Differ;

class DifferTest extends TestCase {

	/**
	 * Applies the rows to the old lines and returns the new lines.
	 */
	private function rebuild( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( '-' !== $row['t'] ) {
				$out[] = $row['s'];
			}
		}
		return $out;
	}

	public function test_files_classifies_added_removed_changed_and_renamed() {
		$old = array( 'a.php' => 'h1', 'b.php' => 'h2', 'c.php' => 'h3', 'old-name.php' => 'h4' );
		$new = array( 'a.php' => 'h1', 'b.php' => 'h9', 'd.php' => 'h5', 'new-name.php' => 'h4' );
		$res = Differ::files( $old, $new );
		$this->assertSame( array( 'd.php' ), $res['added'] );
		$this->assertSame( array( 'c.php' ), $res['removed'] );
		$this->assertSame( array( 'b.php' ), $res['changed'] );
		$this->assertSame( array( 'old-name.php' => 'new-name.php' ), $res['renamed'] );
	}

	public function test_lines_reconstruct_the_new_text() {
		$a    = array( 'one', 'two', 'three', 'four', 'five' );
		$b    = array( 'one', 'TWO', 'three', 'five', 'six' );
		$rows = Differ::lines( $a, $b );
		$this->assertSame( $b, $this->rebuild( $rows ) );
		$this->assertSame( array( 2, 5 ), Differ::added_numbers( $rows ) );
	}

	public function test_identical_input_has_no_changes() {
		$rows = Differ::lines( array( 'a', 'b' ), array( 'a', 'b' ) );
		$this->assertSame( array(), Differ::added_numbers( $rows ) );
		$this->assertSame( array(), Differ::hunks( $rows ) );
	}

	public function test_empty_to_text_and_text_to_empty() {
		$this->assertSame( array( 1, 2 ), Differ::added_numbers( Differ::lines( array(), array( 'x', 'y' ) ) ) );
		$rows = Differ::lines( array( 'x' ), array() );
		$this->assertSame( array(), Differ::added_numbers( $rows ) );
		$this->assertSame( '-', $rows[0]['t'] );
	}

	public function test_large_file_with_known_changes_has_exact_add_count() {
		$a = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$a[] = 'line ' . $i;
		}
		$b = $a;
		for ( $i = 0; $i < 40; $i++ ) {
			array_splice( $b, 70 * $i + 5, 0, array( 'added ' . $i ) );
		}
		$rows = Differ::lines( $a, $b );
		$this->assertCount( 40, Differ::added_numbers( $rows ) );
		$this->assertSame( $b, $this->rebuild( $rows ) );
	}

	public function test_hunks_collapse_long_unchanged_stretches() {
		$a = range( 1, 100 );
		$b = $a;
		$b[10] = 'x';
		$b[90] = 'y';
		$hunks = Differ::hunks( Differ::lines( array_map( 'strval', $a ), array_map( 'strval', $b ) ), 2 );
		$this->assertCount( 2, $hunks );
	}

	public function test_huge_edit_distance_falls_back_to_replacement() {
		$a = array();
		$b = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$a[] = 'a' . $i;
			$b[] = 'b' . $i;
		}
		$rows = Differ::lines( $a, $b );
		$this->assertCount( 3000, Differ::added_numbers( $rows ) );
	}
}

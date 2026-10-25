<?php
/**
 * Tests for moving admin notices below the hero on the plugin's screens.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class AdminNoticesTest extends TestCase {

	private $admin;

	protected function setUp(): void {
		remove_all_test_filters();
		$this->admin = new HTMLPP_Admin();
		$this->admin->menu();
		$GLOBALS['htmlpp_test_actions'] = array();
		$this->printed(); // Clear anything a previous test left.
	}

	private function printed() {
		ob_start();
		HTMLPP_Admin::print_notices();
		return ob_get_clean();
	}

	private function screen( $id ) {
		return (object) array( 'id' => $id );
	}

	public function test_notices_are_captured_on_the_plugin_screens() {
		foreach ( array( 'toplevel_page_html-page-publisher', 'html-pages_page_html-page-publisher-settings' ) as $id ) {
			$GLOBALS['htmlpp_test_actions'] = array();
			$this->admin->maybe_capture_notices( $this->screen( $id ) );
			$this->assertSame( array( array( array( $this->admin, 'start_notice_capture' ), PHP_INT_MIN ) ), $GLOBALS['htmlpp_test_actions']['admin_notices'], $id );
			$this->assertSame( array( array( array( $this->admin, 'end_notice_capture' ), PHP_INT_MAX ) ), $GLOBALS['htmlpp_test_actions']['all_admin_notices'], $id );
		}
	}

	public function test_other_screens_keep_wordpress_notice_placement() {
		// Includes an add-on screen whose ID contains the plugin slug: it renders its own page.
		foreach ( array( 'dashboard', 'plugins', 'html-pages_page_html-page-publisher-forms', 'html-pages_page_htmlpp-detect-forms' ) as $id ) {
			$this->admin->maybe_capture_notices( $this->screen( $id ) );
		}
		$this->admin->maybe_capture_notices( null );
		$this->assertSame( array(), $GLOBALS['htmlpp_test_actions'] );
	}

	public function test_captured_notices_print_once_below_the_hero() {
		$level = ob_get_level();
		$this->admin->start_notice_capture();
		echo '<div class="update-nag notice notice-warning inline">WordPress 7.1.3 is available!</div>';
		echo '<div class="acme-banner">Custom banner</div>';
		$this->admin->end_notice_capture();

		$this->assertSame( $level, ob_get_level(), 'The capture buffer is closed.' );
		$this->assertSame( '<div class="update-nag notice notice-warning inline">WordPress 7.1.3 is available!</div><div class="acme-banner">Custom banner</div>', $this->printed() );
		$this->assertSame( '', $this->printed(), 'Printed only once.' );
	}

	public function test_a_buffer_a_notice_leaves_open_is_folded_in() {
		$level = ob_get_level();
		$this->admin->start_notice_capture();
		echo 'A';
		ob_start(); // A notice callback that forgets to close its buffer.
		echo 'B';
		$this->admin->end_notice_capture();

		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 'AB', $this->printed() );
	}

	public function test_a_buffer_closed_by_a_notice_is_left_alone() {
		$level = ob_get_level();
		$this->admin->start_notice_capture();
		ob_end_clean(); // A notice callback that closes our buffer.
		$this->admin->end_notice_capture();

		$this->assertSame( $level, ob_get_level(), 'No other buffer was closed.' );
		$this->assertSame( '', $this->printed() );
	}

	public function test_nothing_is_printed_when_there_are_no_notices() {
		$this->admin->start_notice_capture();
		echo "\n  ";
		$this->admin->end_notice_capture();
		$this->assertSame( '', $this->printed() );
	}

	public function test_ending_without_starting_does_nothing() {
		$level = ob_get_level();
		$this->admin->end_notice_capture();
		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( '', $this->printed() );
	}
}

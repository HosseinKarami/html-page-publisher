<?php
/**
 * Tests for the init priority pages are served at (htmlpp_serve_priority).
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class RendererServePriorityTest extends TestCase {

	protected function setUp(): void {
		remove_all_test_filters();
		$GLOBALS['htmlpp_test_actions'] = array();
	}

	public function test_constructing_the_renderer_does_not_hook_serving_yet() {
		new HTMLPP_Renderer();
		$this->assertArrayNotHasKey( 'init', $GLOBALS['htmlpp_test_actions'], 'Serving is hooked after htmlpp_loaded, not in the constructor.' );
	}

	public function test_pages_are_served_at_init_priority_0_by_default() {
		$renderer = new HTMLPP_Renderer();
		$renderer->hook_serving();
		$this->assertSame( array( array( array( $renderer, 'maybe_serve' ), 0 ) ), $GLOBALS['htmlpp_test_actions']['init'] );
	}

	public function test_serve_priority_is_filterable() {
		add_filter(
			'htmlpp_serve_priority',
			static function () {
				return '20'; // Cast to int.
			}
		);
		$renderer = new HTMLPP_Renderer();
		$renderer->hook_serving();
		$this->assertSame( array( array( array( $renderer, 'maybe_serve' ), 20 ) ), $GLOBALS['htmlpp_test_actions']['init'] );
	}
}

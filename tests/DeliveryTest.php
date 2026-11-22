<?php
/**
 * Tests for HTMLPP_Delivery: the host check, the mode it picks, and the
 * page output on hosts that answer file requests themselves.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class DeliveryTest extends TestCase {

	const FILES = 'https://example.com/wp-content/uploads/html-page-publisher/deck/';

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		remove_all_test_filters();
	}

	protected function tearDown(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		remove_all_test_filters();
	}

	/**
	 * A check result for one file.
	 */
	private static function r( $code, $marker ) {
		return array(
			'code'   => $code,
			'marker' => $marker,
		);
	}

	/**
	 * A trimmed-down Keynote HTML export, as Keynote writes it.
	 */
	private static function keynote_html() {
		return "<!DOCTYPE html>\n<html xmlns=\"http://www.w3.org/1999/xhtml\">\n    <head>\n        <title>Keynote</title>\n"
			. "        <link rel=\"stylesheet\" type=\"text/css\" href=\"assets/player/styles.css\">\n    </head>\n"
			. "    <body id=\"body\" bgcolor=\"black\">\n        <div id=\"stage\"></div>\n"
			. "        <script type=\"text/javascript\" src=\"assets/player/pdfjs/bcmaps.js\"></script></body>\n"
			. "        <script type=\"text/javascript\" src=\"assets/player/pdfjs/pdf.js\"></script></body>\n"
			. "        <script type=\"text/javascript\" src=\"assets/player/main.js\"></script></body>\n"
			. "    </body>\n</html>";
	}

	/*
	 * Check
	 */

	public function test_classify_needs_the_control_to_reach_the_plugin() {
		// A security plugin, maintenance mode or a blocked loopback: nothing
		// reached the renderer, so nothing can be concluded.
		$this->assertSame(
			'unknown',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 404, false ),
					'probe.css' => self::r( 404, false ),
					'probe.js'  => self::r( 404, false ),
				)
			)
		);
		$this->assertSame(
			'unknown',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 0, false ),
					'probe.css' => self::r( 0, false ),
				)
			)
		);
		$this->assertSame( 'unknown', HTMLPP_Delivery::classify( array() ) );
	}

	public function test_classify_normal_host() {
		$this->assertSame(
			'normal',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 200, true ),
					'probe.css' => self::r( 200, true ),
					'probe.js'  => self::r( 200, true ),
				)
			)
		);
	}

	public function test_classify_intercepting_host() {
		$this->assertSame(
			'intercepted',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 200, true ),
					'probe.css' => self::r( 404, false ),
					'probe.js'  => self::r( 404, false ),
				)
			)
		);
		$this->assertSame(
			'intercepted',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 200, true ),
					'probe.css' => self::r( 200, true ),
					'probe.js'  => self::r( 403, false ),
				)
			)
		);
	}

	public function test_classify_other_failures_are_inconclusive() {
		$this->assertSame(
			'unknown',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 200, true ),
					'probe.css' => self::r( 500, false ),
					'probe.js'  => self::r( 200, true ),
				)
			)
		);
		$this->assertSame(
			'unknown',
			HTMLPP_Delivery::classify(
				array(
					'probe'     => self::r( 200, true ),
					'probe.css' => self::r( 0, false ),
				)
			)
		);
		$this->assertSame( 'unknown', HTMLPP_Delivery::classify( array( 'probe' => self::r( 200, true ) ) ) );
	}

	public function test_is_probe() {
		$this->assertTrue( HTMLPP_Delivery::is_probe( 'htmlpp-probe', 'probe.css' ) );
		$this->assertTrue( HTMLPP_Delivery::is_probe( 'htmlpp-probe', 'probe' ) );
		$this->assertFalse( HTMLPP_Delivery::is_probe( 'htmlpp-probe', 'style.css' ) );
		$this->assertFalse( HTMLPP_Delivery::is_probe( 'deck', 'probe.css' ) );
	}

	/*
	 * Mode
	 */

	public function test_automatic_switches_only_on_a_proven_intercepting_host() {
		$this->assertSame( 'uploads', HTMLPP_Delivery::resolve_mode( 'auto', 'intercepted', 'open' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'auto', 'intercepted', 'blocked' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'auto', 'intercepted', 'unknown' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'auto', 'normal', 'open' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'auto', 'unknown', 'open' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'auto', '', '' ) );
	}

	public function test_an_explicit_choice_wins() {
		$this->assertSame( 'uploads', HTMLPP_Delivery::resolve_mode( 'uploads', 'unknown', 'unknown' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::resolve_mode( 'page', 'intercepted', 'open' ) );
	}

	public function test_mode_defaults_to_the_page_url_until_checked() {
		$this->assertSame( 'auto', HTMLPP_Delivery::choice() );
		$this->assertSame( 'page', HTMLPP_Delivery::mode() );
	}

	public function test_mode_reads_the_stored_check_and_the_setting() {
		update_option(
			HTMLPP_Delivery::STATUS_OPTION,
			array(
				'status'  => 'intercepted',
				'storage' => 'open',
				'checked' => time(),
			)
		);
		$this->assertSame( 'uploads', HTMLPP_Delivery::mode() );

		update_option( HTMLPP_Settings::OPTION, array( 'file_delivery' => 'page' ) );
		$this->assertSame( 'page', HTMLPP_Delivery::mode() );

		update_option( HTMLPP_Settings::OPTION, array( 'file_delivery' => 'nonsense' ) );
		$this->assertSame( 'auto', HTMLPP_Delivery::choice() );
	}

	public function test_mode_filter() {
		add_filter(
			'htmlpp_file_delivery',
			static function () {
				return 'uploads';
			}
		);
		$this->assertSame( 'uploads', HTMLPP_Delivery::mode() );
	}

	public function test_check_is_stale() {
		$this->assertTrue( HTMLPP_Delivery::check_is_stale() );
		update_option( HTMLPP_Delivery::STATUS_OPTION, array( 'status' => 'normal', 'checked' => time() ) );
		$this->assertFalse( HTMLPP_Delivery::check_is_stale() );
		update_option( HTMLPP_Delivery::STATUS_OPTION, array( 'status' => 'normal', 'checked' => time() - 8 * DAY_IN_SECONDS ) );
		$this->assertTrue( HTMLPP_Delivery::check_is_stale() );
	}

	public function test_same_host() {
		$this->assertTrue( HTMLPP_Delivery::same_host( self::FILES, 'example.com' ) );
		$this->assertTrue( HTMLPP_Delivery::same_host( self::FILES, 'EXAMPLE.com:443' ) );
		$this->assertTrue( HTMLPP_Delivery::same_host( 'http://localhost:8888/wp-content/uploads/x/', 'localhost:8888' ) );
		$this->assertFalse( HTMLPP_Delivery::same_host( 'http://localhost:8888/wp-content/uploads/x/', 'localhost' ) );
		$this->assertFalse( HTMLPP_Delivery::same_host( self::FILES, 'sales.example.com' ) );
		$this->assertFalse( HTMLPP_Delivery::same_host( 'https://cdn.example.net/uploads/x/', 'example.com' ) );
		$this->assertFalse( HTMLPP_Delivery::same_host( self::FILES, '' ) );
	}

	public function test_page_has_files() {
		$dir = sys_get_temp_dir() . '/htmlpp-tests/delivery-' . uniqid();
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/index.html', '<p>x</p>' );
		file_put_contents( $dir . '/.DS_Store', '' );
		$this->assertFalse( HTMLPP_Delivery::page_has_files( $dir ) );

		mkdir( $dir . '/assets' );
		$this->assertTrue( HTMLPP_Delivery::page_has_files( $dir ) );

		$this->assertFalse( HTMLPP_Delivery::page_has_files( $dir . '/missing' ) );

		rmdir( $dir . '/assets' );
		unlink( $dir . '/.DS_Store' );
		unlink( $dir . '/index.html' );
		rmdir( $dir );
	}

	/*
	 * Page output
	 */

	public function test_base_leads_the_head_before_any_relative_reference() {
		$html = '<!doctype html><html><head><link rel="stylesheet" href="assets/a.css"></head><body><a href="#top">x</a></body></html>';
		$out  = HTMLPP_Delivery::apply( $html, self::FILES, '/pages/deck/' );

		$base = strpos( $out, '<base href="' . self::FILES . '">' );
		$this->assertNotFalse( $base );
		$this->assertLessThan( strpos( $out, 'assets/a.css' ), $base );
		$this->assertGreaterThan( strpos( $out, '<head>' ), $base );
		$this->assertSame( 1, substr_count( $out, '<base ' ) );
		$this->assertStringContainsString( 'location.hash=h', $out );
		$this->assertStringContainsString( '"' . self::FILES . '","/pages/deck/"', $out );
	}

	public function test_a_page_with_its_own_base_keeps_it() {
		$html = '<html><head><base href="https://elsewhere.example/"></head><body></body></html>';
		$this->assertSame( $html, HTMLPP_Delivery::apply( $html, self::FILES, '/pages/deck/' ) );
	}

	public function test_nothing_changes_without_a_files_url() {
		$html = self::keynote_html();
		$this->assertSame( $html, HTMLPP_Delivery::apply( $html, '', '/pages/deck/' ) );
	}

	public function test_base_without_head_goes_after_html_or_doctype() {
		$out = HTMLPP_Delivery::apply( '<html lang="en"><p>x</p></html>', self::FILES, '' );
		$this->assertStringStartsWith( "<html lang=\"en\">\n<base href=", $out );
		$this->assertStringNotContainsString( '<script>', $out );

		$out = HTMLPP_Delivery::apply( "<!DOCTYPE html>\n<p>x</p>", self::FILES, '' );
		$this->assertStringStartsWith( "<!DOCTYPE html>\n<base href=", $out );

		$out = HTMLPP_Delivery::apply( '<p>x</p>', self::FILES, '' );
		$this->assertStringStartsWith( '<base href=', $out );
	}

	public function test_header_element_is_not_mistaken_for_head() {
		$out = HTMLPP_Delivery::apply( '<html><header>x</header></html>', self::FILES, '' );
		$this->assertStringStartsWith( "<html>\n<base href=", $out );
	}

	public function test_link_script_values_cannot_break_out_of_the_script() {
		$script = HTMLPP_Delivery::link_script( self::FILES, '/pages/</script><b>x/' );
		$this->assertStringNotContainsString( '</script>', $script );
		$this->assertStringNotContainsString( '<b>', $script );
	}

	public function test_keynote_export_is_detected_precisely() {
		$this->assertTrue( HTMLPP_Delivery::is_keynote_export( self::keynote_html() ) );

		// Minified exports.
		$this->assertTrue(
			HTMLPP_Delivery::is_keynote_export(
				'<head></head><body><script src=assets/player/pdfjs/pdf.js></script><script src="./assets/player/main.js"></script></body>'
			)
		);

		// pdf.js on its own (any other site using it) is not Keynote.
		$this->assertFalse( HTMLPP_Delivery::is_keynote_export( '<script src="assets/player/pdfjs/pdf.js"></script>' ) );
		$this->assertFalse( HTMLPP_Delivery::is_keynote_export( '<script src="js/pdf.js"></script><script src="js/main.js"></script>' ) );
		$this->assertFalse( HTMLPP_Delivery::is_keynote_export( '<p>assets/player/pdfjs/pdf.js assets/player/main.js</p>' ) );
	}

	public function test_keynote_fix_goes_right_after_pdfjs_and_before_the_player() {
		$out = HTMLPP_Delivery::add_keynote_fix( self::keynote_html() );

		$fix = strpos( $out, 'htmlppBaseFix' );
		$this->assertNotFalse( $fix );
		$this->assertGreaterThan( strpos( $out, 'pdfjs/pdf.js"></script>' ), $fix );
		$this->assertLessThan( strpos( $out, 'assets/player/main.js' ), $fix );
		$this->assertSame( 1, substr_count( $out, 'htmlppBaseFix=true' ) );
		$this->assertStringContainsString( 'document.baseURI', $out );
	}

	public function test_keynote_fix_leaves_other_pages_alone() {
		$html = '<html><head></head><body><script src="assets/player/pdfjs/pdf.js"></script></body></html>';
		$this->assertSame( $html, HTMLPP_Delivery::add_keynote_fix( $html ) );
	}

	public function test_apply_on_a_keynote_export() {
		$out = HTMLPP_Delivery::apply( self::keynote_html(), self::FILES, '/pages/deck/' );
		$this->assertLessThan( strpos( $out, 'assets/player/styles.css' ), strpos( $out, '<base href=' ) );
		$this->assertStringContainsString( 'htmlppBaseFix', $out );

		// A hand-added <base> (the documented workaround) is kept, and the
		// PDF fix is still added.
		$own = str_replace( '<head>', '<head><base href="' . self::FILES . '">', self::keynote_html() );
		$out = HTMLPP_Delivery::apply( $own, self::FILES, '/pages/deck/' );
		$this->assertSame( 1, substr_count( $out, '<base ' ) );
		$this->assertStringContainsString( 'htmlppBaseFix', $out );
	}

	public function test_decorate_page_puts_the_files_base_first_and_skips_the_slash_base() {
		$html = '<html><head><title>t</title></head><body></body></html>';
		$out  = HTMLPP_Renderer::decorate_page(
			$html,
			'deck',
			array(),
			array( 'canonical' => false ),
			false,
			'https://example.com/pages/deck/',
			array(
				'files' => self::FILES,
				'page'  => '/pages/deck/',
			)
		);
		$this->assertSame( 1, substr_count( $out, '<base ' ) );
		$this->assertStringContainsString( "<head>\n<base href=\"" . self::FILES . '">', $out );

		// Without delivery, no base is added (a page with no title gets no
		// link preview tags either, so it comes back unchanged).
		$untitled = '<html><head></head><body></body></html>';
		$this->assertSame( $untitled, HTMLPP_Renderer::decorate_page( $untitled, 'deck', array(), array( 'canonical' => false ) ) );
		$this->assertStringNotContainsString( '<base ', HTMLPP_Renderer::decorate_page( $html, 'deck', array(), array( 'canonical' => false ) ) );
	}

	public function test_settings_sanitize_file_delivery() {
		$GLOBALS['htmlpp_test_caps'] = array( 'unfiltered_html' => true );
		$settings                    = new HTMLPP_Settings();

		$out = $settings->sanitize( array( 'file_delivery' => 'uploads' ) );
		$this->assertSame( 'uploads', $out['file_delivery'] );

		$out = $settings->sanitize( array( 'file_delivery' => 'bogus' ) );
		$this->assertSame( 'auto', $out['file_delivery'] );

		// Other tabs don't post the field; the saved choice is kept.
		update_option( HTMLPP_Settings::OPTION, array( 'file_delivery' => 'page' ) );
		$out = $settings->sanitize( array( 'canonical' => '1' ) );
		$this->assertSame( 'page', $out['file_delivery'] );
		$GLOBALS['htmlpp_test_caps'] = array();
	}
}

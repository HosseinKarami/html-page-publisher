<?php
/**
 * Settings: each tab saves only its own fields.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	/**
	 * @var HTMLPP_Settings
	 */
	private $settings;

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options']         = array();
		$GLOBALS['htmlpp_test_settings_errors'] = array();
		$GLOBALS['htmlpp_test_caps']            = array( 'unfiltered_html' => true );
		remove_all_test_filters();
		$this->settings = new HTMLPP_Settings();
	}

	protected function tearDown(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		$GLOBALS['htmlpp_test_caps']    = array();
		remove_all_test_filters();
	}

	private function saved( $values ) {
		update_option( HTMLPP_Settings::OPTION, $values );
	}

	public function test_saving_one_tab_keeps_the_other_tabs_values() {
		$this->saved(
			array(
				'url_prefix'     => 'landing',
				'subdomain'      => 'sales.example.com',
				'head_snippet'   => '<script>ga()</script>',
				'footer_snippet' => '',
				'canonical'      => false,
				'ai_enabled'     => true,
			)
		);

		// Tracking & SEO tab.
		$out = $this->settings->sanitize(
			array(
				'head_snippet'   => '<script>gtm()</script>',
				'footer_snippet' => '',
				'canonical'      => '1',
			)
		);
		$this->assertSame( 'landing', $out['url_prefix'] );
		$this->assertSame( 'sales.example.com', $out['subdomain'] );
		$this->assertSame( '<script>gtm()</script>', $out['head_snippet'] );
		$this->assertTrue( $out['canonical'] );
		$this->assertTrue( $out['ai_enabled'] );

		// General tab.
		$out = $this->settings->sanitize(
			array(
				'url_prefix' => 'Promo Pages',
				'subdomain'  => '',
			)
		);
		$this->assertSame( 'promo-pages', $out['url_prefix'] );
		$this->assertSame( '', $out['subdomain'] );
		$this->assertSame( '<script>ga()</script>', $out['head_snippet'] );
		$this->assertFalse( $out['canonical'] );
	}

	public function test_unticked_checkboxes_arrive_as_zero_and_switch_off() {
		$this->saved( array( 'canonical' => true ) );

		$out = $this->settings->sanitize( array( 'ai_enabled' => '0' ) );
		$this->assertFalse( $out['ai_enabled'] );
		$this->assertTrue( $out['canonical'], 'The AI tab must not touch the canonical setting.' );

		$out = $this->settings->sanitize( array( 'canonical' => '0' ) );
		$this->assertFalse( $out['canonical'] );
	}

	public function test_url_rules_still_apply() {
		$out = $this->settings->sanitize( array( 'url_prefix' => 'wp-json' ) );
		$this->assertSame( 'pages', $out['url_prefix'] );
		$this->assertContains( 'htmlpp_reserved_prefix', $GLOBALS['htmlpp_test_settings_errors'] );

		$out = $this->settings->sanitize( array( 'subdomain' => 'HTTPS://Sales.Example.com/' ) );
		$this->assertSame( 'sales.example.com', $out['subdomain'] );

		$out = $this->settings->sanitize( array( 'subdomain' => 'not a host' ) );
		$this->assertSame( '', $out['subdomain'] );
		$this->assertContains( 'htmlpp_bad_subdomain', $GLOBALS['htmlpp_test_settings_errors'] );
	}

	public function test_a_full_settings_array_comes_back_unchanged() {
		// The upgrade routine and add_option() pass the whole saved array.
		$full = array(
			'url_prefix'     => 'pages',
			'subdomain'      => '',
			'head_snippet'   => 'x',
			'footer_snippet' => 'y',
			'canonical'      => true,
			'ai_enabled'     => false,
		);
		$this->assertSame( $full, $this->settings->sanitize( $full ) );
	}

	public function test_ai_is_on_unless_switched_off() {
		$this->assertTrue( HTMLPP_Settings::ai_enabled() );
		$this->saved( array( 'ai_enabled' => false ) );
		$this->assertFalse( HTMLPP_Settings::ai_enabled() );
	}
}

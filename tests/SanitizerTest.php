<?php
/**
 * Tests for HTMLPP_Sanitizer.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class SanitizerTest extends TestCase {

	protected function tearDown(): void {
		remove_all_test_filters();
	}

	public function test_plain_html_passes_through_unchanged() {
		$html = "<!doctype html>\n<html><head><title>Hi</title></head><body><p>Hello</p></body></html>";
		$this->assertSame( $html, HTMLPP_Sanitizer::sanitize( $html ) );
	}

	public function test_claude_design_wrappers_are_stripped() {
		$html = '<html><head>'
			. '<style data-omelette-injected="true">body{outline:1px solid red}</style>'
			. '<script data-omelette-injected="true">window.__omelette = 1;</script>'
			. '</head><body>'
			. '<div class="tweaks" id="tweaks"><div><div>panel</div></div></div>'
			. '<p>Real content</p>'
			. '<script data-cfasync="false">loader();</script>'
			. '<script>// ---------- Tweakable defaults ----------' . "\n" . 'var x = 1;' . "\n" . 'applyTweaks();</script>'
			. '</body></html>';

		$out = HTMLPP_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( 'omelette', $out );
		$this->assertStringNotContainsString( 'id="tweaks"', $out );
		$this->assertStringNotContainsString( 'loader();', $out );
		$this->assertStringNotContainsString( 'applyTweaks', $out );
		$this->assertStringNotContainsString( '<script></script>', $out );
		$this->assertStringContainsString( '<p>Real content</p>', $out );
	}

	public function test_rule_can_be_disabled_via_filter() {
		add_filter(
			'htmlpp_sanitizer_rules',
			static function ( $rules ) {
				unset( $rules['claude-design-cfasync-script'] );
				return $rules;
			}
		);

		$html = '<body><script data-cfasync="false" src="rocket.js"></script></body>';
		$this->assertSame( $html, HTMLPP_Sanitizer::sanitize( $html ) );
	}

	public function test_custom_rule_can_be_added_via_filter() {
		add_filter(
			'htmlpp_sanitizer_rules',
			static function ( $rules ) {
				$rules['made-with'] = array(
					'pattern'     => '/<div class="made-with">.*?<\/div>/is',
					'replacement' => '',
				);
				return $rules;
			}
		);

		$out = HTMLPP_Sanitizer::sanitize( '<p>a</p><div class="made-with">x</div><p>b</p>' );
		$this->assertSame( '<p>a</p><p>b</p>', $out );
	}

	public function test_pcre_failure_never_blanks_the_page() {
		// (*NO_START_OPT) disables PCRE2's "required last code unit" shortcut,
		// which would otherwise reject the subject (no 'b') before any
		// backtracking; with it the nested quantifier really hits the
		// backtrack limit and preg_replace() returns NULL.
		add_filter(
			'htmlpp_sanitizer_rules',
			static function ( $rules ) {
				$rules['catastrophic'] = array(
					'pattern'     => '/(*NO_START_OPT)(a+)+b/',
					'replacement' => '',
				);
				return $rules;
			}
		);

		$html = '<p>' . str_repeat( 'a', 5000 ) . '</p>';

		// Sanity check that the pattern really fails on this PHP build.
		$this->assertNull( @preg_replace( '/(*NO_START_OPT)(a+)+b/', '', $html ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->assertSame( PREG_BACKTRACK_LIMIT_ERROR, preg_last_error() );

		$this->assertSame( $html, HTMLPP_Sanitizer::sanitize( $html ), 'Input must survive a PCRE failure intact.' );
	}

	public function test_slug_and_context_reach_the_filters() {
		$seen = array();
		add_filter(
			'htmlpp_sanitizer_rules',
			static function ( $rules, $slug, $context ) use ( &$seen ) {
				$seen['rules'] = array( $slug, $context );
				return $rules;
			}
		);
		add_filter(
			'htmlpp_sanitize_html',
			static function ( $html, $slug, $context ) use ( &$seen ) {
				$seen['html'] = array( $slug, $context );
				return $html;
			}
		);

		HTMLPP_Sanitizer::sanitize( '<p>x</p>', 'my-page', 'upload' );

		$this->assertSame( array( 'my-page', 'upload' ), $seen['rules'] );
		$this->assertSame( array( 'my-page', 'upload' ), $seen['html'] );
	}

	public function test_invalid_pattern_is_ignored() {
		add_filter(
			'htmlpp_sanitizer_rules',
			static function ( $rules ) {
				$rules['broken'] = array(
					'pattern'     => '/[unclosed/',
					'replacement' => '',
				);
				return $rules;
			}
		);

		$this->assertSame( '<p>ok</p>', HTMLPP_Sanitizer::sanitize( '<p>ok</p>' ) );
	}

	public function test_sanitize_html_filter_runs_last() {
		add_filter(
			'htmlpp_sanitize_html',
			static function ( $html ) {
				return $html . '<!-- injected -->';
			}
		);
		$this->assertSame( '<p>x</p><!-- injected -->', HTMLPP_Sanitizer::sanitize( '<p>x</p>' ) );
	}

	public function test_non_string_from_filter_is_ignored() {
		add_filter(
			'htmlpp_sanitize_html',
			static function () {
				return null;
			}
		);
		$this->assertSame( '<p>x</p>', HTMLPP_Sanitizer::sanitize( '<p>x</p>' ) );
	}
}

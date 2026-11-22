<?php
/**
 * Tests for HTMLPP_Seo: link preview and search fields.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class SeoTest extends TestCase {

	const URL  = 'https://example.com/pages/promo/';
	const BASE = 'https://example.com/pages/promo/';

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		remove_all_test_filters();
	}

	private function prepare( $html, array $fields = array() ) {
		return HTMLPP_Seo::prepare(
			$html,
			$fields + array(
				'title'       => '',
				'description' => '',
				'image'       => '',
			),
			array(
				'url'  => self::URL,
				'base' => self::BASE,
			)
		);
	}

	public function test_fills_in_missing_preview_tags_from_the_page_itself() {
		$html   = '<html><head><title>Spring &amp; Sale</title><meta name="description" content="Half price."></head><body></body></html>';
		$result = $this->prepare( $html );

		$this->assertSame( $html, $result['html'], 'The page itself is not changed.' );
		$this->assertSame(
			'<meta property="og:type" content="website">' . "\n"
			. '<meta property="og:title" content="Spring &amp; Sale">' . "\n"
			. '<meta property="og:description" content="Half price.">' . "\n"
			. '<meta property="og:url" content="https://example.com/pages/promo/">' . "\n"
			. '<meta name="twitter:card" content="summary">',
			$result['head']
		);
	}

	public function test_a_page_with_complete_tags_gets_nothing() {
		$html = '<head><title>T</title><meta property="og:type" content="article"><meta property="og:title" content="T">'
			. '<meta property="og:description" content="D"><meta property="og:image" content="https://cdn.example.com/i.png">'
			. '<meta property="og:url" content="https://example.com/x"><meta name="twitter:card" content="summary_large_image"></head>';
		$result = $this->prepare( $html );
		$this->assertSame( '', $result['head'] );
		$this->assertSame( $html, $result['html'] );
	}

	public function test_a_page_with_no_title_description_or_image_gets_nothing() {
		$result = $this->prepare( '<html><head><meta charset="utf-8"></head><body><h1>Hi</h1></body></html>' );
		$this->assertSame( '', $result['head'] );
	}

	public function test_filled_in_fields_replace_the_pages_own_tags() {
		$html = "<html><head>\n<title>Old title</title>\n<meta name=\"description\" content=\"Old\">\n"
			. "<meta property=\"og:title\" content=\"Old\">\n<meta name='twitter:title' content='Old'>\n"
			. "<meta property=\"og:image\" content=\"old.png\">\n<meta property=\"og:image:width\" content=\"10\">\n"
			. "<meta property=\"og:site_name\" content=\"Acme\">\n</head><body><title>not this</title></body></html>";

		$result = $this->prepare(
			$html,
			array(
				'title'       => 'New "title" <b>',
				'description' => "  New\n description ",
				'image'       => 'https://example.com/pages/promo/assets/share.png',
			)
		);

		$this->assertSame(
			"<html><head>\n<title>New &quot;title&quot;</title>\n\n\n\n\n\n<meta property=\"og:site_name\" content=\"Acme\">\n</head><body><title>not this</title></body></html>",
			$result['html']
		);
		$this->assertSame(
			'<meta name="description" content="New description">' . "\n"
			. '<meta property="og:type" content="website">' . "\n"
			. '<meta property="og:title" content="New &quot;title&quot;">' . "\n"
			. '<meta property="og:description" content="New description">' . "\n"
			. '<meta property="og:image" content="https://example.com/pages/promo/assets/share.png">' . "\n"
			. '<meta property="og:url" content="https://example.com/pages/promo/">' . "\n"
			. '<meta name="twitter:card" content="summary_large_image">',
			$result['head']
		);
	}

	public function test_a_title_field_adds_a_title_to_a_page_without_one() {
		$result = $this->prepare( '<head></head>', array( 'title' => 'Promo' ) );
		$this->assertStringStartsWith( '<title>Promo</title>', $result['head'] );
	}

	public function test_makes_a_relative_preview_image_absolute() {
		$html   = '<head><title>T</title><meta property="og:image" content="assets/og.png"><meta name="twitter:image" content=\'/wp-content/uploads/x.png\'><meta property="og:image:url" content="../shared/o.png"></head>';
		$result = $this->prepare( $html );

		$this->assertStringContainsString( '<meta property="og:image" content="https://example.com/pages/promo/assets/og.png">', $result['html'] );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://example.com/wp-content/uploads/x.png">', $result['html'] );
		$this->assertStringContainsString( '<meta property="og:image:url" content="https://example.com/pages/shared/o.png">', $result['html'] );
		$this->assertStringContainsString( 'content="summary_large_image"', $result['head'] );
	}

	public function test_tags_inside_scripts_and_comments_are_left_alone() {
		$html   = '<head><title>T</title><!-- <meta property="og:title" content="c"> --><script>var m = \'<meta property="og:title" content="s">\';</script></head>';
		$result = $this->prepare( $html, array( 'title' => 'New' ) );

		$this->assertStringContainsString( '<!-- <meta property="og:title" content="c"> -->', $result['html'] );
		$this->assertStringContainsString( 'content="s">', $result['html'] );
		$this->assertStringContainsString( '<meta property="og:title" content="New">', $result['head'] );
	}

	public function test_a_page_without_a_head_is_read_up_to_its_body() {
		$result = $this->prepare( '<title>Bare</title><body><meta property="og:title" content="in body"></body>' );
		$this->assertStringContainsString( '<meta property="og:title" content="Bare">', $result['head'] );
	}

	public function test_page_values() {
		$values = HTMLPP_Seo::page_values( '<head><title> Hello  world </title><meta property="og:description" content="D"><meta property="og:image" content="a.png"></head>', self::BASE );
		$this->assertSame(
			array(
				'title'       => 'Hello world',
				'description' => 'D',
				'image'       => 'https://example.com/pages/promo/a.png',
			),
			$values
		);
	}

	public function test_clean() {
		$this->assertSame( 'a b', HTMLPP_Seo::clean( " <i>a</i>\n\t b ", 10 ) );
		$this->assertSame( 'ééé', HTMLPP_Seo::clean( 'éééé', 3 ) );
		$this->assertSame( '', HTMLPP_Seo::clean( array( 'x' ), 3 ) );
	}

	public function test_decorate_page_applies_the_fields_and_the_filter_can_remove_the_tags() {
		$html = '<html><head><title>Own</title></head><body></body></html>';
		$meta = array(
			'seo_title'       => 'Shared title',
			'seo_description' => 'Shared description',
			'seo_image'       => 'https://cdn.example.com/share.png',
		);

		$out = HTMLPP_Renderer::decorate_page( $html, 'promo', $meta, array( 'canonical' => false ) );
		$this->assertStringContainsString( '<title>Shared title</title>', $out );
		$this->assertStringContainsString( '<meta name="description" content="Shared description">', $out );
		$this->assertStringContainsString( '<meta property="og:image" content="https://cdn.example.com/share.png">', $out );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/pages/promo/">', $out );

		// Drafts: no og:url (the public URL does not work yet).
		$this->assertStringNotContainsString( 'og:url', HTMLPP_Renderer::decorate_page( $html, 'promo', $meta, array( 'canonical' => false ), true ) );

		add_filter(
			'htmlpp_link_preview_tags',
			static function () {
				return '';
			}
		);
		$out = HTMLPP_Renderer::decorate_page( $html, 'promo', array(), array( 'canonical' => false ) );
		$this->assertSame( $html, $out );
	}

	public function test_a_preview_image_file_that_is_gone_is_left_out() {
		$this->assertSame( '', HTMLPP_Renderer::seo_image_url( 'promo', 'assets/gone.png' ) );
		$this->assertSame( 'https://cdn.example.com/x.png', HTMLPP_Renderer::seo_image_url( 'promo', 'https://cdn.example.com/x.png' ) );
		$this->assertSame( '', HTMLPP_Renderer::seo_image_url( 'promo', '' ) );
	}
}

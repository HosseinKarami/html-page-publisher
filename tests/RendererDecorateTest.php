<?php
/**
 * Tests for HTMLPP_Renderer::decorate() and match_custom().
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class RendererDecorateTest extends TestCase {

	public function test_head_and_footer_go_before_closing_tags() {
		$html = "<html><head><title>x</title></head><body><p>hi</p></body></html>";
		$out  = HTMLPP_Renderer::decorate( $html, '<script>ga()</script>', '<script>chat()</script>' );
		$this->assertStringContainsString( "<script>ga()</script>\n</head>", $out );
		$this->assertStringContainsString( "<script>chat()</script>\n</body>", $out );
		$this->assertSame( 1, substr_count( $out, 'ga()' ) );
	}

	public function test_noindex_and_canonical_are_added_once() {
		$html = '<html><head></head><body></body></html>';
		$out  = HTMLPP_Renderer::decorate( $html, '', '', 'https://example.com/pages/x/', true );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $out );
		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/pages/x/">', $out );

		$already = '<html><head><link rel=canonical href="https://other/"></head><body></body></html>';
		$out     = HTMLPP_Renderer::decorate( $already, '', '', 'https://example.com/pages/x/' );
		$this->assertSame( 1, substr_count( $out, 'canonical' ) );
	}

	public function test_fallback_positions_without_head_or_body() {
		$out = HTMLPP_Renderer::decorate( '<p>fragment</p>', '<meta x>', '<!-- f -->' );
		$this->assertStringStartsWith( '<meta x>', $out );
		$this->assertStringEndsWith( "<!-- f -->\n", $out );

		$out = HTMLPP_Renderer::decorate( '<HTML><HEAD><meta charset=utf-8><BODY>x</BODY></HTML>', '<meta y>', '' );
		$this->assertStringContainsString( "<HEAD>\n<meta y>\n<meta charset=utf-8>", $out );
	}

	public function test_doctype_and_bom_stay_first_without_head_or_body() {
		$out = HTMLPP_Renderer::decorate( "<!DOCTYPE html>\n<p>x</p>", '<meta a>', '' );
		$this->assertStringStartsWith( "<!DOCTYPE html>\n<meta a>", $out );

		$out = HTMLPP_Renderer::decorate( "\xEF\xBB\xBF<!doctype html><p>x</p>", '<meta a>', '' );
		$this->assertStringStartsWith( "\xEF\xBB\xBF<!doctype html>\n<meta a>", $out );
	}

	public function test_closing_tags_inside_scripts_and_comments_are_ignored() {
		$html = "<html><head><script>var s = '</head>';</script><!-- </head> --></head><body><script>x='</body>'</script></body></html>";
		$out  = HTMLPP_Renderer::decorate( $html, '<meta h>', '<meta f>' );
		$this->assertStringContainsString( "<meta h>\n</head><body>", $out );
		$this->assertStringContainsString( "<meta f>\n</body></html>", $out );
		$this->assertSame( 1, substr_count( $out, '<meta h>' ) );
	}

	public function test_home_query_conflicts() {
		$this->assertFalse( HTMLPP_Renderer::home_query_conflicts( '' ) );
		$this->assertFalse( HTMLPP_Renderer::home_query_conflicts( 'utm_source=x&gclid=1' ) );
		$this->assertTrue( HTMLPP_Renderer::home_query_conflicts( 's=hello' ) );
		$this->assertTrue( HTMLPP_Renderer::home_query_conflicts( 'p=12&preview=true' ) );
		$this->assertTrue( HTMLPP_Renderer::home_query_conflicts( 'rest_route=/wp/v2/posts' ) );
		$this->assertTrue( HTMLPP_Renderer::home_query_conflicts( 'utm_source=x&feed=rss2' ) );
	}

	public function test_blocked_extension_helpers() {
		foreach ( array( 'php', 'PHP7', 'phtml', 'phar', 'phps', 'inc', 'shtml', 'htaccess' ) as $ext ) {
			$this->assertTrue( HTMLPP_Renderer::is_blocked_extension( $ext ), $ext );
		}
		$this->assertFalse( HTMLPP_Renderer::is_blocked_extension( 'png' ) );
		$this->assertTrue( HTMLPP_Renderer::has_blocked_extension_part( 'shell.php.png' ) );
		$this->assertTrue( HTMLPP_Renderer::has_blocked_extension_part( 'x.PhP7.js' ) );
		$this->assertFalse( HTMLPP_Renderer::has_blocked_extension_part( 'jquery.min.js' ) );
		$this->assertFalse( HTMLPP_Renderer::has_blocked_extension_part( 'vendor.chunk.js' ) );
	}

	public function test_empty_snippets_leave_html_untouched() {
		$html = "<html><head></head><body>a</body></html>";
		$this->assertSame( $html, HTMLPP_Renderer::decorate( $html, '', '  ', '', false ) );
	}

	public function test_match_custom_paths_and_home() {
		$settings = array( 'url_prefix' => 'pages', 'subdomain' => '' );
		$map      = array( 'promo' => 'spring', 'guides/spring' => 'guide', 'home' => 'landing' );

		$m = HTMLPP_Renderer::match_custom( 'example.com', '/promo/', $settings, '', $map );
		$this->assertSame( 'spring', $m['slug'] );
		$this->assertSame( '', $m['rel'] );
		$this->assertSame( '/promo/', $m['canonical'] );

		$m = HTMLPP_Renderer::match_custom( 'example.com', '/promo/assets/a.css', $settings, '', $map );
		$this->assertSame( 'spring', $m['slug'] );
		$this->assertSame( 'assets/a.css', $m['rel'] );

		// Longest path wins.
		$m = HTMLPP_Renderer::match_custom( 'example.com', '/guides/spring/x.png', $settings, '', $map );
		$this->assertSame( 'guide', $m['slug'] );
		$this->assertSame( 'x.png', $m['rel'] );

		// Front page and its relative assets.
		$m = HTMLPP_Renderer::match_custom( 'example.com', '/', $settings, '', $map );
		$this->assertSame( 'landing', $m['slug'] );
		$this->assertSame( '', $m['rel'] );
		$this->assertTrue( $m['trailing_slash'] );
		$this->assertTrue( $m['is_home'] );

		// Custom paths match case-insensitively.
		$m = HTMLPP_Renderer::match_custom( 'example.com', '/PROMO/', $settings, '', $map );
		$this->assertSame( 'spring', $m['slug'] );
		$m = HTMLPP_Renderer::match_custom( 'example.com', '/assets/hero.png', $settings, '', $map );
		$this->assertSame( 'landing', $m['slug'] );
		$this->assertSame( 'assets/hero.png', $m['rel'] );

		// Subdirectory install.
		$m = HTMLPP_Renderer::match_custom( 'example.com', '/blog/promo', $settings, '/blog', $map );
		$this->assertSame( 'spring', $m['slug'] );
		$this->assertFalse( $m['trailing_slash'] );
		$this->assertSame( '/blog/promo/', $m['canonical'] );
		$this->assertNull( HTMLPP_Renderer::match_custom( 'example.com', '/promo/', $settings, '/blog', $map ) );

		// Never on the subdomain host; never dotfiles; nothing without a map.
		$sub = array( 'url_prefix' => 'pages', 'subdomain' => 'sales.example.com' );
		$this->assertNull( HTMLPP_Renderer::match_custom( 'sales.example.com', '/promo/', $sub, '', $map ) );
		$this->assertNull( HTMLPP_Renderer::match_custom( 'example.com', '/promo/.env', $settings, '', $map ) );
		$this->assertNull( HTMLPP_Renderer::match_custom( 'example.com', '/promo/', $settings, '', array() ) );
		$this->assertNull( HTMLPP_Renderer::match_custom( 'example.com', '/other/', $settings, '', array( 'promo' => 'x' ) ) );
	}
}

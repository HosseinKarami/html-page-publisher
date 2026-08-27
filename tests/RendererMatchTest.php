<?php
/**
 * Tests for HTMLPP_Renderer::match() — the request router.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class RendererMatchTest extends TestCase {

	private $settings = array(
		'url_prefix' => 'pages',
		'subdomain'  => '',
	);

	public function test_prefix_match_with_trailing_slash() {
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/hello/', $this->settings );
		$this->assertSame( 'hello', $m['slug'] );
		$this->assertSame( '', $m['rel'] );
		$this->assertTrue( $m['trailing_slash'] );
		$this->assertSame( '/pages/hello', $m['path'] );
		$this->assertSame( '/pages/hello/', $m['canonical'] );
	}

	public function test_canonical_is_built_from_validated_parts_not_the_raw_path() {
		// Mixed case and a stray encoded char are normalised away.
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/Hello%20World', $this->settings );
		$this->assertSame( 'hello-world', $m['slug'] );
		$this->assertSame( '/pages/hello-world/', $m['canonical'] );

		$m = HTMLPP_Renderer::match( 'example.com', '/blog/pages/hello', $this->settings, '/blog' );
		$this->assertSame( '/blog/pages/hello/', $m['canonical'] );

		$settings = array(
			'url_prefix' => 'pages',
			'subdomain'  => 'sales.example.com',
		);
		$m = HTMLPP_Renderer::match( 'sales.example.com', '/hello', $settings );
		$this->assertSame( '/hello/', $m['canonical'] );
	}

	public function test_dotted_slugs_follow_wordpress_rules() {
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/v1.2/', $this->settings );
		$this->assertSame( 'v1-2', $m['slug'] );
	}

	public function test_prefix_match_without_trailing_slash_flags_redirect_need() {
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/hello?utm=1', $this->settings );
		$this->assertSame( 'hello', $m['slug'] );
		$this->assertFalse( $m['trailing_slash'] );
		$this->assertSame( 'utm=1', $m['query'] );
	}

	public function test_asset_path_is_returned_relative_to_page() {
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/hello/assets/img/hero.png', $this->settings );
		$this->assertSame( 'hello', $m['slug'] );
		$this->assertSame( 'assets/img/hero.png', $m['rel'] );
	}

	public function test_non_matching_paths_return_null() {
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pagesx/hello/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/blog/pages/hello/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/wp-admin/', $this->settings ) );
	}

	public function test_subdirectory_install_strips_home_path() {
		$m = HTMLPP_Renderer::match( 'example.com', '/blog/pages/hello/', $this->settings, '/blog' );
		$this->assertSame( 'hello', $m['slug'] );

		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/hello/', $this->settings, '/blog' ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/blogger/pages/hello/', $this->settings, '/blog' ) );
	}

	public function test_subdomain_mode_uses_root_path() {
		$settings = array(
			'url_prefix' => 'pages',
			'subdomain'  => 'sales.example.com',
		);
		$m = HTMLPP_Renderer::match( 'SALES.example.com:8443', '/hello/assets/a.css', $settings );
		$this->assertSame( 'hello', $m['slug'] );
		$this->assertSame( 'assets/a.css', $m['rel'] );

		// Main domain still needs the prefix.
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/hello/', $settings ) );
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/hello/', $settings );
		$this->assertSame( 'hello', $m['slug'] );
	}

	public function test_percent_encoded_paths_are_decoded() {
		$m = HTMLPP_Renderer::match( 'example.com', '/pages/my%2Dpage/assets/caf%C3%A9%20logo.png', $this->settings );
		$this->assertSame( 'my-page', $m['slug'] );
		$this->assertSame( 'assets/café logo.png', $m['rel'] );
	}

	public function test_dotfiles_and_dot_segments_are_rejected() {
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/hello/.htaccess', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/hello/../other/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/.hidden/', $this->settings ) );
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/pages/hello//x', $this->settings ) );
	}

	public function test_null_byte_is_rejected() {
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', "/pages/hello/%00", $this->settings ) );
	}

	public function test_empty_prefix_never_matches_on_main_domain() {
		$settings = array(
			'url_prefix' => '',
			'subdomain'  => '',
		);
		$this->assertNull( HTMLPP_Renderer::match( 'example.com', '/hello/', $settings ) );
	}
}

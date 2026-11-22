<?php
/**
 * Tests for HTMLPP_References: the missing-files check.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class ReferencesTest extends TestCase {

	const EXT = array( 'html', 'css', 'js', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'woff2', 'pdf', 'mp4' );

	private function missing( $html, array $existing = array(), array $css = array(), array $args = array() ) {
		$read = static function ( $path ) use ( $css ) {
			return isset( $css[ $path ] ) ? $css[ $path ] : false;
		};
		return HTMLPP_References::missing( $html, $existing, $read, $args + array( 'extensions' => self::EXT ) );
	}

	private function refs( array $missing ) {
		return array_column( $missing, 'reference' );
	}

	public function test_finds_attribute_style_and_srcset_references() {
		$html = '<!doctype html><html><head>'
			. '<link rel="stylesheet" href="css/site.css">'
			. '<link rel="icon" href=\'favicon.png\'>'
			. '<script src="js/app.js?v=3"></script>'
			. '<style>.hero{background:url("img/bg.jpg")} @import "css/extra.css";</style>'
			. '</head><body>'
			. '<img src=logo.svg alt=x>'
			. '<img srcset="hero-1x.webp 1x, hero-2x.webp 2x" src="hero.webp">'
			. '<div style="background-image:url(img/tile.png)"></div>'
			. '<video poster="poster.jpg"><source src="media/intro.mp4"></video>'
			. '<a href="files/brochure.pdf#page=2">Brochure</a>'
			. '<img data-src="lazy.png">'
			. '</body></html>';

		$this->assertSame(
			array( 'css/site.css', 'favicon.png', 'js/app.js', 'logo.svg', 'hero-1x.webp', 'hero-2x.webp', 'hero.webp', 'img/tile.png', 'poster.jpg', 'media/intro.mp4', 'files/brochure.pdf', 'lazy.png', 'img/bg.jpg', 'css/extra.css' ),
			$this->refs( $this->missing( $html ) )
		);
	}

	public function test_ignores_everything_that_is_not_a_file_in_the_page_folder() {
		$html = '<a href="https://example.com/a.png">x</a>'
			. '<img src="//cdn.example.com/b.png">'
			. '<img src="/wp-content/uploads/c.png">'
			. '<img src="data:image/png;base64,AAAA">'
			. '<img srcset="data:image/png;base64,AA, BB 2x">'
			. '<a href="#top">top</a><a href="?page=2">next</a>'
			. '<a href="mailto:a@b.c">m</a><a href="tel:123">t</a><a href="javascript:void(0)">j</a>'
			. '<a href="contact">no extension</a><a href="about/">folder</a><a href="form.php">php</a>'
			. '<img src="{{ image }}"><img src="${src}">'
			. '<img src="../other-page/d.png">'
			. '<img src="">';
		$this->assertSame( array(), $this->missing( $html ) );
	}

	public function test_skips_comments_and_script_bodies_but_not_script_src() {
		$html = '<!-- <img src="commented.png"> -->'
			. '<script src="real.js">var t = \'<img src="in-script.png">\';</script>'
			. '<script>document.write(\'<img src="written.png">\')</script>';
		$this->assertSame( array( 'real.js' ), $this->refs( $this->missing( $html ) ) );
	}

	public function test_existing_files_and_the_page_itself_are_not_missing() {
		$html = '<img src="./assets/hero.png"><img src="assets/../assets/hero.png"><a href="index.html">home</a><img src="assets/gone.png">';
		$this->assertSame( array( 'assets/gone.png' ), $this->refs( $this->missing( $html, array( 'assets/hero.png' ) ) ) );
	}

	public function test_each_missing_file_is_reported_once_with_its_raw_form() {
		$missing = $this->missing( '<img src="hero.png?v=1"><img src="./hero.png">' );
		$this->assertCount( 1, $missing );
		$this->assertSame( 'hero.png', $missing[0]['reference'] );
		$this->assertSame( 'hero.png?v=1', $missing[0]['raw'] );
		$this->assertSame( 'index.html', $missing[0]['found_in'] );
	}

	public function test_decodes_percent_encoding_and_entities() {
		$missing = $this->missing( '<img src="my%20photo.png"><img src="a.png?x=1&amp;y=2">' );
		$this->assertSame( array( 'my photo.png', 'a.png' ), $this->refs( $missing ) );
	}

	public function test_suggests_the_file_the_reference_probably_meant() {
		$existing = array( 'assets/hero.png', 'assets/My-Photo.jpg', 'Logo.svg', 'a/icon.png', 'b/icon.png' );
		$missing  = $this->missing( '<img src="images/hero.png"><img src="my photo.jpg"><img src="logo.svg"><img src="icon.png"><img src="nothing.png">', $existing );

		$this->assertSame(
			array(
				'images/hero.png' => 'assets/hero.png',
				'my photo.jpg'    => 'assets/My-Photo.jpg',
				'logo.svg'        => 'Logo.svg',
				'icon.png'        => '',
				'nothing.png'     => '',
			),
			array_column( $missing, 'suggestion', 'reference' )
		);
	}

	public function test_prefers_the_copy_in_assets_when_several_files_share_a_name() {
		$this->assertSame( 'assets/hero.png', HTMLPP_References::suggest( 'img/hero.png', array( 'old/hero.png', 'assets/hero.png' ) ) );
	}

	public function test_follows_stylesheets_relative_to_their_own_folder() {
		$html = '<link rel="stylesheet" href="css/site.css"><link rel="stylesheet" href="css/missing.css">';
		$css  = array(
			'css/site.css'  => '/* url(commented.png) */ @import url("parts/fonts.css"); .a{background:url(../img/bg.png)} .b{background:url(\'../img/here.png\')}',
			'css/parts/fonts.css' => '@font-face{src:url(../../fonts/inter.woff2) format("woff2"), url(https://fonts.example/x.woff2)}',
		);
		$missing  = $this->missing( $html, array( 'css/site.css', 'css/parts/fonts.css', 'img/here.png' ), $css );

		$this->assertSame(
			array(
				'css/missing.css' => 'index.html',
				'img/bg.png'      => 'css/site.css',
				'fonts/inter.woff2' => 'css/parts/fonts.css',
			),
			array_column( $missing, 'found_in', 'reference' )
		);
	}

	public function test_a_stylesheet_loop_is_read_once() {
		$reads = 0;
		$read  = static function ( $path ) use ( &$reads ) {
			++$reads;
			return 'a.css' === $path ? '@import "b.css";' : '@import "a.css";';
		};
		HTMLPP_References::missing( '<link rel="stylesheet" href="a.css">', array( 'a.css', 'b.css' ), $read, array( 'extensions' => self::EXT ) );
		$this->assertSame( 2, $reads );
	}

	public function test_a_base_tag_pointing_elsewhere_skips_the_html_check() {
		$html = '<head><base href="https://cdn.example.com/site/"></head><img src="hero.png">';
		$this->assertSame( array(), $this->missing( $html ) );

		$own = '<head><base href="https://example.com/wp-content/uploads/html-page-publisher/promo/"></head><img src="hero.png">';
		$this->assertSame(
			array( 'hero.png' ),
			$this->refs( $this->missing( $own, array(), array(), array( 'roots' => array( 'https://example.com/wp-content/uploads/html-page-publisher/promo/' ) ) ) )
		);
	}

	public function test_results_are_capped() {
		$html = '';
		for ( $i = 0; $i < 80; $i++ ) {
			$html .= '<img src="img' . $i . '.png">';
		}
		$this->assertCount( HTMLPP_References::MAX_RESULTS, $this->missing( $html ) );
	}

	public function test_without_an_extension_list_any_extension_counts() {
		$this->assertSame( 'deck/slides.key', HTMLPP_References::resolve( 'deck/slides.key' ) );
		$this->assertNull( HTMLPP_References::resolve( 'deck/slides.key', '', array( 'png' ) ) );
	}

	public function test_replace_points_every_markup_reference_at_the_new_file() {
		$html = '<link rel="icon" href="hero.png">'
			. '<img src="hero.png?v=2" alt="hero.png">'
			. '<img src=hero.png>'
			. '<img srcset="hero.png 1x, hero@2x.png 2x">'
			. '<div style="background:url(\'hero.png\')"></div>'
			. '<style>.a{background:url(hero.png#x)}</style>'
			. '<p>Upload hero.png first.</p>'
			. '<!-- <img src="hero.png"> -->'
			. '<script src="hero.png"></script><script>var s = "hero.png";</script>'
			. '<img src="Hero.png">';

		$result = HTMLPP_References::replace( $html, 'hero.png', 'assets/hero.png' );

		$this->assertSame( 7, $result['count'] );
		$this->assertSame(
			'<link rel="icon" href="assets/hero.png">'
			. '<img src="assets/hero.png?v=2" alt="hero.png">'
			. '<img src=assets/hero.png>'
			. '<img srcset="assets/hero.png 1x, hero@2x.png 2x">'
			. '<div style="background:url(\'assets/hero.png\')"></div>'
			. '<style>.a{background:url(assets/hero.png#x)}</style>'
			. '<p>Upload hero.png first.</p>'
			. '<!-- <img src="hero.png"> -->'
			. '<script src="assets/hero.png"></script><script>var s = "hero.png";</script>'
			. '<img src="Hero.png">',
			$result['html']
		);
	}

	public function test_segments_split_and_rejoin_the_html_unchanged() {
		$html     = '<p>a</p><!-- c --><script src="x.js" data-x=\'>\'>var s = "</p>";</script><STYLE>b{}</STYLE ><script>unclosed';
		$segments = HTMLPP_References::segments( $html );
		$this->assertSame( $html, implode( '', array_column( $segments, 1 ) ) );
		$this->assertSame( array( 'markup', 'comment', 'markup', 'script', 'markup', 'style', 'markup', 'script' ), array_column( $segments, 0 ) );
		$this->assertSame( '<script src="x.js" data-x=\'>\'>', $segments[2][1] );
	}

	public function test_a_large_inline_script_does_not_hide_references() {
		$script = '<script>var x = "' . str_repeat( 'a<img src=\"in-script.png\">', 70000 ) . '";</script>';
		$html   = '<html><head><title>Big</title>' . $script . '</head><body><img src="hero.png"><div style="background:url(bg.png)"></div></body></html>';
		$this->assertSame( array( 'hero.png', 'bg.png' ), $this->refs( $this->missing( $html ) ) );

		$result = HTMLPP_References::replace( $html, 'hero.png', 'assets/hero.png' );
		$this->assertSame( 1, $result['count'] );
		$this->assertStringContainsString( 'in-script.png', $result['html'] );

		$this->assertSame( strpos( $html, '</head>' ), HTMLPP_Renderer::find_tag_outside_scripts( $html, '</head>' ) );
		$this->assertSame( strrpos( $html, '</body>' ), HTMLPP_Renderer::find_tag_outside_scripts( $html, '</body>', true ) );
	}

	public function test_replace_with_nothing_to_change() {
		$result = HTMLPP_References::replace( '<img src="a.png">', 'b.png', 'assets/b.png' );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( '<img src="a.png">', $result['html'] );
	}
}

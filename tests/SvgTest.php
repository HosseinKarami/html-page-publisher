<?php
/**
 * Tests for HTMLPP_Svg: known SVG script-injection vectors must be removed,
 * and ordinary artwork must survive intact.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class SvgTest extends TestCase {

	const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';

	protected function tearDown(): void {
		remove_all_test_filters();
	}

	/**
	 * Sanitize and assert success.
	 *
	 * @param string $svg Input.
	 * @return string Output.
	 */
	private function clean( $svg ) {
		$r = HTMLPP_Svg::sanitize( $svg );
		$this->assertTrue( $r['ok'], 'Expected a valid SVG: ' . $r['error'] );
		return $r['svg'];
	}

	/**
	 * Whether the output could run script in a browser.
	 *
	 * @param string $out Sanitized SVG.
	 */
	private function assertInert( $out ) {
		// Read it the way a browser does: entities decoded, and the
		// whitespace/control characters a URL parser drops removed, so
		// "java&#9;script:" is seen as javascript:.
		$decoded = html_entity_decode( $out, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$lower   = strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $decoded ) );
		foreach ( array( '<script', 'foreignobject', 'javascript:', 'vbscript:', 'data:text/html', 'onload', 'onclick', 'onmouseover', 'onbegin', 'onerror', 'onfocus', '<iframe', '<handler', 'xml-stylesheet', 'data:image/svg' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $lower, "Output still contains {$needle}:\n{$out}" );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Benign artwork survives
	|--------------------------------------------------------------------------
	*/

	public function test_typical_design_tool_export_survives() {
		$svg = self::OPEN
			. '<defs><linearGradient id="g"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#1e1b4b"/></linearGradient>'
			. '<clipPath id="c"><rect width="10" height="10" rx="2"/></clipPath>'
			. '<symbol id="dot"><circle cx="1" cy="1" r="1"/></symbol></defs>'
			. '<style>.brand{fill:url(#g)}</style>'
			. '<g clip-path="url(#c)"><rect class="brand" width="10" height="10"/>'
			. '<use xlink:href="#dot" x="2" y="2"/><use href="#dot" x="5" y="5"/>'
			. '<path d="M1 1L9 9" stroke="#fff" stroke-width=".5" fill="none"/>'
			. '<text x="1" y="9" font-family="Inter" font-size="2">Hello &amp; welcome</text></g>'
			. '<g><animateTransform attributeName="transform" type="rotate" from="0 5 5" to="360 5 5" dur="1s" repeatCount="indefinite"/></g>'
			. '<a href="https://example.com"><rect width="1" height="1"/></a>'
			. '<image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>'
			. '<image href="assets/photo.jpg" width="1" height="1"/>'
			. '</svg>';

		$out = $this->clean( $svg );

		foreach ( array( 'linearGradient', 'clipPath', 'symbol', '.brand{fill:url(#g)}', 'xlink:href="#dot"', 'href="#dot"', 'animateTransform', 'Hello &amp; welcome', 'href="https://example.com"', 'data:image/png;base64', 'href="assets/photo.jpg"', 'stroke-width=".5"' ) as $kept ) {
			$this->assertStringContainsString( $kept, $out, "Lost {$kept}" );
		}
		$this->assertStringStartsWith( '<' . '?xml version="1.0" encoding="UTF-8"?' . '>', $out );
	}

	public function test_editor_metadata_is_dropped_but_drawing_kept() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" inkscape:version="1.3">'
			. '<sodipodi:namedview id="nv"/><g inkscape:label="Layer 1"><rect width="4" height="4" fill="red"/></g></svg>';
		$out = $this->clean( $svg );
		$this->assertStringContainsString( 'fill="red"', $out );
		$this->assertStringNotContainsString( 'namedview', $out );
		$this->assertStringNotContainsString( 'inkscape:label', $out );
	}

	/*
	|--------------------------------------------------------------------------
	| Script vectors are removed
	|--------------------------------------------------------------------------
	*/

	/**
	 * Every vector here has executed script in some browser when an SVG was
	 * opened directly. Each must come out inert.
	 *
	 * @return array
	 */
	public function vectors() {
		$o = self::OPEN;
		return array(
			'onload on root'                => array( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>' ),
			'upper-case handler'            => array( '<svg xmlns="http://www.w3.org/2000/svg" ONLOAD="alert(1)"><rect/></svg>' ),
			'script element'                => array( $o . '<script>alert(1)</script></svg>' ),
			'script with src'               => array( $o . '<script xlink:href="data:,alert(1)"/></svg>' ),
			'script in CDATA'               => array( $o . '<script><![CDATA[alert(1)]]></script></svg>' ),
			'prefixed svg script'           => array( '<s:svg xmlns:s="http://www.w3.org/2000/svg"><s:script>alert(1)</s:script></s:svg>' ),
			'xhtml-namespace script'        => array( $o . '<x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script></svg>' ),
			'javascript link'               => array( $o . '<a xlink:href="javascript:alert(1)"><text>x</text></a></svg>' ),
			'javascript link, plain href'   => array( $o . '<a href="javascript:alert(1)"><text>x</text></a></svg>' ),
			'entity-encoded scheme'         => array( $o . '<a href="&#106;avascript:alert(1)"><text>x</text></a></svg>' ),
			'tab inside scheme'             => array( $o . '<a href="java&#x09;script:alert(1)"><text>x</text></a></svg>' ),
			'newline inside scheme'         => array( $o . '<a href="java&#10;script:alert(1)"><text>x</text></a></svg>' ),
			'mixed-case scheme'             => array( $o . '<a href="JaVaScRiPt:alert(1)"><text>x</text></a></svg>' ),
			'data html link'                => array( $o . '<a href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"><text>x</text></a></svg>' ),
			'nested svg data link'          => array( $o . '<a href="data:image/svg+xml;base64,PHN2Zz4="><text>x</text></a></svg>' ),
			'animate sets href'             => array( $o . '<a><animate attributeName="href" to="javascript:alert(1)" begin="0s"/><text>x</text></a></svg>' ),
			'animate sets xlink:href'       => array( $o . '<a><animate attributeName="xlink:href" values="javascript:alert(1)"/><text>x</text></a></svg>' ),
			'set adds handler'              => array( $o . '<rect><set attributeName="onmouseover" to="alert(1)"/></rect></svg>' ),
			'animation handler'             => array( $o . '<animate onbegin="alert(1)" attributeName="x" dur="1s"/></svg>' ),
			'foreignObject iframe'          => array( $o . '<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="javascript:alert(1)"/></foreignObject></svg>' ),
			'xml-events handler'            => array( $o . '<handler xmlns:ev="http://www.w3.org/2001/xml-events" ev:event="load">alert(1)</handler></svg>' ),
			'image with javascript'         => array( $o . '<image href="javascript:alert(1)" width="1" height="1"/></svg>' ),
			'image with svg data'           => array( $o . '<image href="data:image/svg+xml,&lt;svg onload=alert(1)&gt;" width="1" height="1"/></svg>' ),
			'feImage with javascript'       => array( $o . '<filter id="f"><feImage xlink:href="javascript:alert(1)"/></filter></svg>' ),
			'external use'                  => array( $o . '<use href="https://evil.example/x.svg#a"/></svg>' ),
			'javascript in fill url'        => array( $o . '<rect fill="url(javascript:alert(1))"/></svg>' ),
			'javascript in style attribute' => array( $o . '<rect style="background:url(javascript:alert(1))"/></svg>' ),
			'stylesheet import and script'  => array( $o . '<style>@import url(https://evil.example/x.css); rect{background:url("javascript:alert(1)")}</style></svg>' ),
			'css expression'                => array( $o . '<rect style="width:expression(alert(1))"/></svg>' ),
			'css data:text url'             => array( $o . '<style>rect{background:url(data:text/html,x)}</style></svg>' ),
			'css data:svg url in attribute' => array( $o . '<rect style="background:url(\'data:image/svg+xml,x\')"/></svg>' ),
			'xml-stylesheet instruction'    => array( '<' . '?xml-stylesheet type="text/xsl" href="data:text/xml,x"?' . '>' . $o . '<rect/></svg>' ),
			'instruction inside svg'        => array( $o . '<' . '?xml-stylesheet href="x.css"?' . '><rect/></svg>' ),
			'xml:base redirect'             => array( $o . '<a xml:base="javascript:alert(1)//" href="x"><text>x</text></a></svg>' ),
			'non-css stylesheet'            => array( $o . '<style type="text/ecmascript">alert(1)</style></svg>' ),
		);
	}

	/**
	 * @dataProvider vectors
	 *
	 * @param string $svg Malicious SVG.
	 */
	public function test_vector_is_neutralized( $svg ) {
		$this->assertInert( $this->clean( $svg ) );
	}

	public function test_external_use_reference_is_removed() {
		$out = $this->clean( self::OPEN . '<use href="https://evil.example/x.svg#a"/><use href="#ok"/></svg>' );
		$this->assertStringNotContainsString( 'evil.example', $out );
		$this->assertStringContainsString( 'href="#ok"', $out );
	}

	public function test_css_escaped_scheme_is_removed() {
		// CSS allows \6a for "j"; decoded, it is javascript: and dropped.
		$out = $this->clean( self::OPEN . '<style>rect{background:url(\\6a avascript:alert(1))}</style></svg>' );
		$this->assertStringNotContainsString( '6a avascript', $out );
		$this->assertStringContainsString( 'background:none', $out );
	}

	public function test_only_svg_namespace_elements_survive() {
		$out = $this->clean( self::OPEN . '<o:rect xmlns:o="https://example.com/other" width="1"/><x:a xmlns:x="http://www.w3.org/1999/xhtml" href="https://ok.example">x</x:a><rect width="2"/></svg>' );
		$this->assertStringNotContainsString( 'o:rect', $out );
		$this->assertStringNotContainsString( 'ok.example', $out, 'An XHTML-namespace element is not an SVG element.' );
		$this->assertStringContainsString( '<rect width="2"/>', $out );
	}

	public function test_stylesheet_import_is_removed() {
		$out = $this->clean( self::OPEN . '<style>@import url(https://evil.example/x.css); .a{fill:red}</style></svg>' );
		$this->assertStringNotContainsString( '@import', $out );
		$this->assertStringContainsString( '.a{fill:red}', $out );
	}

	/*
	|--------------------------------------------------------------------------
	| Nothing loads from another site
	|--------------------------------------------------------------------------
	*/

	/**
	 * Off-site references, some hidden behind CSS escapes. None can run
	 * script, but each would let a file phone home when opened.
	 *
	 * @return array
	 */
	public function outside_references() {
		$o = self::OPEN;
		return array(
			'escaped @import'           => array( $o . '<style>@\69mport url(https://evil.example/x.css); .a{fill:red}</style></svg>' ),
			'six-digit escaped @import' => array( $o . '<style>@\000049 mport "https://evil.example/x.css"; .a{fill:red}</style></svg>' ),
			'escaped url function'      => array( $o . '<style>.a{background:u\72l(https://evil.example/b.png)}</style></svg>' ),
			'escaped url in attribute'  => array( $o . '<rect fill="u\72l(https://evil.example/p.svg#x)"/></svg>' ),
			'external image'            => array( $o . '<image href="https://evil.example/b.png" width="1" height="1"/></svg>' ),
			'protocol-relative image'   => array( $o . '<image href="//evil.example/b.png" width="1" height="1"/></svg>' ),
			'external feImage'          => array( $o . '<filter id="f"><feImage xlink:href="https://evil.example/b.png"/></filter></svg>' ),
			'external font'             => array( $o . '<style>@font-face{font-family:x;src:url(https://evil.example/f.woff2)}</style></svg>' ),
			'external paint server'     => array( $o . '<rect fill="url(https://evil.example/p.svg#g)"/></svg>' ),
			'external filter'           => array( $o . '<rect filter="url(//evil.example/f.svg#f)"/></svg>' ),
		);
	}

	/**
	 * @dataProvider outside_references
	 *
	 * @param string $svg SVG with an off-site reference.
	 */
	public function test_nothing_loads_from_another_site( $svg ) {
		$out = $this->clean( $svg );
		$this->assertStringNotContainsString( 'evil.example', $out, $out );
	}

	public function test_escaped_expression_is_neutralized() {
		$out = $this->clean( self::OPEN . '<rect style="width:expr\65 ssion(alert(1))"/></svg>' );
		$this->assertStringNotContainsString( 'ssion(', $out );
	}

	public function test_local_references_and_other_escapes_are_kept() {
		$out = $this->clean(
			self::OPEN
			. '<style>.a{background:url(assets/bg.png)} .q::before{content:"\201C"} .u::before{content:"\169"} .p::before{content:"\140"} .e{content:"\\\\69"}</style>'
			. '<rect fill="url(#g)" filter="url(#f)"/></svg>'
		);
		foreach ( array( 'url(assets/bg.png)', 'content:"\201C"', 'content:"\169"', 'content:"\140"', 'content:"\\\\69"', 'fill="url(#g)"', 'filter="url(#f)"' ) as $kept ) {
			$this->assertStringContainsString( $kept, $out, "Lost {$kept}" );
		}
	}

	/**
	 * Characters a reader would never see.
	 *
	 * @return array
	 */
	public function invisible_characters() {
		return array(
			'no-break space'   => array( "\u{00A0}" ),
			'soft hyphen'      => array( "\u{00AD}" ),
			'zero-width space' => array( "\u{200B}" ),
			'bidi override'    => array( "\u{202E}" ),
			'byte order mark'  => array( "\u{FEFF}" ),
		);
	}

	/**
	 * @dataProvider invisible_characters
	 *
	 * @param string $char Invisible character.
	 */
	public function test_invisible_characters_cannot_disguise_a_scheme( $char ) {
		$out = $this->clean( self::OPEN . '<a href="java' . $char . 'script:alert(1)"><text>x</text></a></svg>' );
		$this->assertStringNotContainsString( 'script:', $out );
	}

	/*
	|--------------------------------------------------------------------------
	| Rejected outright
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array
	 */
	public function rejected() {
		return array(
			'external entity (XXE)'     => array( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>' ),
			'entity expansion bomb'     => array( '<!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>' ),
			'doctype with odd spacing'  => array( '<!  doctype svg><svg xmlns="http://www.w3.org/2000/svg"/>' ),
			'utf-16 encoded'            => array( "\xFF\xFE<\0s\0v\0g\0>\0" ),
			'not xml'                   => array( '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>' ),
			'html root, not svg'        => array( '<html xmlns="http://www.w3.org/1999/xhtml"><body onload="alert(1)"/></html>' ),
			'svg root without namespace' => array( '<svg><rect/></svg>' ),
			'empty'                     => array( "  \n " ),
		);
	}

	/**
	 * @dataProvider rejected
	 *
	 * @param string $svg Input that must not be accepted.
	 */
	public function test_input_is_rejected( $svg ) {
		$r = HTMLPP_Svg::sanitize( $svg );
		$this->assertFalse( $r['ok'] );
		$this->assertNotSame( '', $r['error'] );
		$this->assertSame( '', $r['svg'] );
	}

	public function test_size_cap() {
		add_filter( 'htmlpp_svg_max_bytes', static function () {
			return 50;
		} );
		$r = HTMLPP_Svg::sanitize( self::OPEN . str_repeat( '<rect/>', 20 ) . '</svg>' );
		$this->assertFalse( $r['ok'] );
	}

	public function test_filter_cannot_re_allow_script() {
		add_filter( 'htmlpp_svg_allowed_elements', static function ( $elements ) {
			$elements[] = 'script';
			$elements[] = 'foreignObject';
			return $elements;
		} );
		$this->assertInert( $this->clean( self::OPEN . '<script>alert(1)</script><foreignObject/></svg>' ) );
	}

	public function test_output_is_itself_clean_on_a_second_pass() {
		$first  = $this->clean( self::OPEN . '<a href="javascript:alert(1)"><animate attributeName="href" to="javascript:alert(1)"/><rect onclick="x"/></a></svg>' );
		$second = HTMLPP_Svg::sanitize( $first );
		$this->assertTrue( $second['ok'] );
		$this->assertSame( 0, $second['removed'], 'A second pass should find nothing left to remove.' );
	}
}

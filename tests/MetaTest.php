<?php
/**
 * Tests for HTMLPP_Meta.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class MetaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		remove_all_test_filters();
	}

	public function test_sanitize_path_accepts_and_normalizes() {
		$this->assertSame( '', HTMLPP_Meta::sanitize_path( '' ) );
		$this->assertSame( '', HTMLPP_Meta::sanitize_path( '   ' ) );
		$this->assertSame( 'home', HTMLPP_Meta::sanitize_path( '/' ) );
		$this->assertSame( 'home', HTMLPP_Meta::sanitize_path( 'home' ) );
		$this->assertSame( 'promo', HTMLPP_Meta::sanitize_path( '/Promo/' ) );
		$this->assertSame( 'guides/spring-2026', HTMLPP_Meta::sanitize_path( 'Guides/Spring 2026' ) );
	}

	public function test_sanitize_path_rejects_reserved_and_unsafe() {
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'wp-admin' ) );
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'wp-json/x' ) );
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'wp-anything' ) );
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'feed' ) );
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'pages/anything' ), 'The URL prefix is reserved.' );
		$this->assertNull( HTMLPP_Meta::sanitize_path( 'a//b' ) );
		$this->assertNull( HTMLPP_Meta::sanitize_path( '../etc' ) );
	}

	public function test_update_get_and_path_map() {
		HTMLPP_Meta::update( 'one', array( 'path' => 'promo', 'status' => 'draft' ) );
		HTMLPP_Meta::update( 'two', array( 'path' => 'home' ) );
		HTMLPP_Meta::update( 'three', array( 'unknown_key' => 'x' ) );

		$one = HTMLPP_Meta::get( 'one' );
		$this->assertSame( 'draft', $one['status'] );
		$this->assertSame( 'promo', $one['path'] );
		$this->assertGreaterThan( 0, $one['created'] );
		$this->assertFalse( HTMLPP_Meta::is_public( $one ) );
		$this->assertTrue( HTMLPP_Meta::is_public( HTMLPP_Meta::get( 'missing' ) ) );
		$this->assertArrayNotHasKey( 'unknown_key', HTMLPP_Meta::get( 'three' ) );

		$this->assertSame( array( 'promo' => 'one', 'home' => 'two' ), HTMLPP_Meta::path_map() );
		$this->assertSame( 'one', HTMLPP_Meta::slug_for_path( 'promo' ) );
		$this->assertSame( '', HTMLPP_Meta::slug_for_path( 'nope' ) );
	}

	public function test_rename_moves_record_and_records_redirect_chain() {
		HTMLPP_Meta::update( 'a', array( 'noindex' => true ) );
		HTMLPP_Meta::rename( 'a', 'b' );
		HTMLPP_Meta::rename( 'b', 'c' );

		$this->assertTrue( HTMLPP_Meta::get( 'c' )['noindex'] );
		$this->assertSame( 'c', HTMLPP_Meta::get_redirect( 'a' ) );
		$this->assertSame( 'c', HTMLPP_Meta::get_redirect( 'b' ) );
		$this->assertSame( '', HTMLPP_Meta::get_redirect( 'c' ) );

		// Publishing a new page at an old slug clears its redirect.
		HTMLPP_Meta::remove_redirect( 'a' );
		$this->assertSame( '', HTMLPP_Meta::get_redirect( 'a' ) );

		// Deleting the destination drops every redirect that pointed at it.
		HTMLPP_Meta::delete( 'c' );
		$this->assertSame( '', HTMLPP_Meta::get_redirect( 'b' ) );
		$this->assertSame( array(), HTMLPP_Meta::redirects() );
	}

	public function test_preview_token_is_deterministic_and_resettable() {
		$t1 = HTMLPP_Meta::preview_token( 'p' );
		$t2 = HTMLPP_Meta::preview_token( 'p' );
		$this->assertSame( 24, strlen( $t1 ) );
		$this->assertSame( $t1, $t2 );
		$this->assertArrayNotHasKey( 'p', HTMLPP_Meta::all(), 'Reading a token must not write a record.' );

		HTMLPP_Meta::reset_preview( 'p' );
		$this->assertNotSame( $t1, HTMLPP_Meta::preview_token( 'p' ) );
		$this->assertNotSame( HTMLPP_Meta::preview_token( 'p' ), HTMLPP_Meta::preview_token( 'q' ) );
	}

	public function test_changing_a_custom_path_records_a_path_redirect() {
		HTMLPP_Meta::update( 'x', array( 'path' => 'old-promo' ) );
		HTMLPP_Meta::update( 'x', array( 'path' => 'new-promo' ) );
		$this->assertSame( array( 'old-promo' => 'x' ), HTMLPP_Meta::path_redirects() );

		// Claiming the old path again clears its redirect; the front page is never redirected.
		HTMLPP_Meta::update( 'y', array( 'path' => 'old-promo' ) );
		$this->assertSame( array(), HTMLPP_Meta::path_redirects() );
		HTMLPP_Meta::update( 'x', array( 'path' => 'home' ) );
		HTMLPP_Meta::update( 'x', array( 'path' => '' ) );
		$this->assertArrayNotHasKey( 'home', HTMLPP_Meta::path_redirects() );

		// Deleting the page drops its path redirects.
		HTMLPP_Meta::update( 'x', array( 'path' => 'a' ) );
		HTMLPP_Meta::update( 'x', array( 'path' => 'b' ) );
		HTMLPP_Meta::delete( 'x' );
		$this->assertSame( array(), HTMLPP_Meta::path_redirects() );
	}

	public function test_records_are_normalized_and_filter_extends_fields() {
		HTMLPP_Meta::update( 'n', array( 'status' => 'bogus', 'path' => 'WP-ADMIN', 'noindex' => 'yes' ) );
		$n = HTMLPP_Meta::get( 'n' );
		$this->assertSame( 'published', $n['status'] );
		$this->assertSame( '', $n['path'], 'Reserved paths are dropped.' );
		$this->assertTrue( $n['noindex'] );

		add_filter( 'htmlpp_page_meta_defaults', static function ( $d ) { $d['gate'] = 'none'; return $d; } );
		HTMLPP_Meta::update( 'n', array( 'gate' => 'password' ) );
		$this->assertSame( 'password', HTMLPP_Meta::get( 'n' )['gate'] );

		// A filtered path override routes too.
		add_filter( 'htmlpp_page_meta', static function ( $r, $slug ) { if ( 'n' === $slug ) { $r['path'] = 'override'; } return $r; } );
		$this->assertSame( 'n', HTMLPP_Meta::slug_for_path( 'override' ) );
	}
}

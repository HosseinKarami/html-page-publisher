<?php
/**
 * Page service: the missing-files check, storing a file at the path a page
 * expects, pointing a reference at another file, and the link preview
 * fields.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class PageFilesAndPreviewTest extends TestCase {

	private $service;

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options']   = array();
		$GLOBALS['htmlpp_test_post_urls'] = array();
		remove_all_test_filters();
		$base = wp_upload_dir()['basedir'];
		exec( 'rm -rf ' . escapeshellarg( dirname( $base ) ) );
		mkdir( $base, 0777, true );
		$this->service = new HTMLPP_Page_Service();
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( dirname( wp_upload_dir()['basedir'] ) ) );
	}

	private function dir( $slug ) {
		return wp_upload_dir()['basedir'] . '/html-page-publisher/' . $slug;
	}

	private function put( $slug, $reference, $content = 'x' ) {
		$path = $this->dir( $slug ) . '/' . $reference;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
	}

	private function tmp( $name, $content = "\x89PNG\r\n" ) {
		$path = sys_get_temp_dir() . '/htmlpp-tests/' . $name;
		file_put_contents( $path, $content );
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'type'     => '',
			'error'    => 0,
			'size'     => strlen( $content ),
		);
	}

	public function test_missing_files_reads_the_page_and_its_stylesheets() {
		$this->service->create( 'promo', array( 'html' => '<link rel="stylesheet" href="css/site.css"><img src="images/hero.png"><img src="assets/logo.png"><a href="contact">c</a>' ) );
		$this->put( 'promo', 'css/site.css', '.a{background:url(../img/bg.jpg)} .b{background:url(../assets/logo.png)}' );
		$this->put( 'promo', 'assets/logo.png' );
		$this->put( 'promo', 'assets/hero.png' );

		$missing = $this->service->missing_files( 'promo' );
		$this->assertSame(
			array(
				array(
					'reference'  => 'images/hero.png',
					'raw'        => 'images/hero.png',
					'found_in'   => 'index.html',
					'suggestion' => 'assets/hero.png',
				),
				array(
					'reference'  => 'img/bg.jpg',
					'raw'        => '../img/bg.jpg',
					'found_in'   => 'css/site.css',
					'suggestion' => '',
				),
			),
			$missing
		);

		$this->assertSame( 'htmlpp_not_found', $this->service->missing_files( 'nope' )->get_error_code() );

		add_filter(
			'htmlpp_missing_files',
			static function () {
				return array();
			}
		);
		$this->assertSame( array(), $this->service->missing_files( 'promo' ) );
	}

	public function test_a_file_can_be_stored_at_the_exact_path_the_page_expects() {
		$this->service->create( 'promo', array( 'html' => '<img src="images/hero.png">' ) );

		$stored = $this->service->store_file_at( 'promo', $this->tmp( 'Hero Final.png' ), './images/hero.png', 'sideload' );
		$this->assertSame( 'images/hero.png', $stored );
		$this->assertFileExists( $this->dir( 'promo' ) . '/images/hero.png' );
		$this->assertSame( array(), $this->service->missing_files( 'promo' ) );

		// Taken, wrong type, unsafe or unservable paths are refused.
		$this->assertSame( 'htmlpp_file_exists', $this->service->store_file_at( 'promo', $this->tmp( 'a.png' ), 'images/hero.png', 'sideload' )->get_error_code() );
		$this->assertSame( 'htmlpp_wrong_type', $this->service->store_file_at( 'promo', $this->tmp( 'a.jpg' ), 'images/other.png', 'sideload' )->get_error_code() );
		foreach ( array( '../escape.png', 'a/../../b.png', '.hidden/x.png', 'index.html', 'x.php', 'shell.php.png', 'my photo.png', 'a/b/c/d/e/f/g.png', 'noext', '' ) as $bad ) {
			$this->assertSame( 'htmlpp_bad_reference', $this->service->store_file_at( 'promo', $this->tmp( 'a.png' ), $bad, 'sideload' )->get_error_code(), $bad );
		}
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/html-page-publisher/escape.png' );

		// A jpeg file may fill a .jpg reference.
		$this->assertSame( 'photos/p.jpg', $this->service->store_file_at( 'promo', $this->tmp( 'p.jpeg' ), 'photos/p.jpg', 'sideload' ) );
	}

	public function test_storing_at_a_path_respects_the_editing_lock() {
		$this->service->create( 'promo', array( 'html' => '<img src="images/hero.png">' ) );
		add_filter( 'htmlpp_editing_disabled', '__return_true_test' );
		$this->assertSame( 'htmlpp_locked', $this->service->store_file_at( 'promo', $this->tmp( 'a.png' ), 'images/hero.png', 'sideload' )->get_error_code() );
		$this->assertSame( 'htmlpp_locked', $this->service->fix_reference( 'promo', 'images/hero.png', 'assets/hero.png' )->get_error_code() );
	}

	public function test_a_reference_can_be_pointed_at_an_existing_file() {
		$this->service->create( 'promo', array( 'html' => '<img src="hero.png"><img src="./hero.png?v=2"><img src="my%20photo.png"><p>hero.png</p>' ) );
		$this->put( 'promo', 'assets/hero.png' );
		$this->put( 'promo', 'assets/my-photo.png' );

		$page = $this->service->fix_reference( 'promo', 'hero.png', 'assets/hero.png' );
		$this->assertSame( 2, $page['replaced'] );
		$this->assertSame( '<img src="assets/hero.png"><img src="assets/hero.png?v=2"><img src="my%20photo.png"><p>hero.png</p>', $this->service->get( 'promo', true )['html'] );

		// The path as missing_files() reports it, decoded.
		$this->assertSame( 1, $this->service->fix_reference( 'promo', 'my photo.png', 'assets/my-photo.png' )['replaced'] );
		$this->assertStringContainsString( '<img src="assets/my-photo.png">', $this->service->get( 'promo', true )['html'] );
		$this->assertCount( 2, $this->service->versions( 'promo' ), 'Each fix keeps the previous version.' );

		$this->assertSame( 'htmlpp_reference_not_found', $this->service->fix_reference( 'promo', 'hero.png', 'assets/hero.png' )->get_error_code() );
		$this->assertSame( 'htmlpp_bad_reference', $this->service->fix_reference( 'promo', 'x.png', 'assets/missing.png' )->get_error_code() );
		$this->assertSame( 'htmlpp_bad_reference', $this->service->fix_reference( 'promo', 'x.png', 'assets/he"ro.png' )->get_error_code() );
	}

	public function test_in_the_dashboard_markup_and_script_files_need_markup_permission() {
		$this->service->create( 'promo', array( 'html' => '<p>p</p>' ) );
		$GLOBALS['htmlpp_test_did']  = array( 'admin_init' => 1 );
		$GLOBALS['htmlpp_test_caps'] = array( 'manage_options' => true );

		try {
			foreach ( array( 'evil.html', 'evil.htm', 'evil.xml', 'evil.js', 'evil.mjs', 'evil.svg' ) as $name ) {
				$stored = $this->service->store_file( 'promo', $this->tmp( $name, 'alert(1)' ), 'sideload' );
				$this->assertInstanceOf( 'WP_Error', $stored, $name );
				$this->assertFileDoesNotExist( $this->dir( 'promo' ) . '/assets/' . $name );
			}
			$this->assertSame( 'site.css', $this->service->store_file( 'promo', $this->tmp( 'site.css', 'a{}' ), 'sideload' ) );

			$GLOBALS['htmlpp_test_caps']['unfiltered_html'] = true;
			$this->assertSame( 'app.js', $this->service->store_file( 'promo', $this->tmp( 'app.js', 'alert(1)' ), 'sideload' ) );
		} finally {
			$GLOBALS['htmlpp_test_did']  = array();
			$GLOBALS['htmlpp_test_caps'] = array();
		}
	}

	public function test_link_preview_fields_are_cleaned_validated_and_copied() {
		$this->service->create( 'promo', array( 'html' => '<title>Promo</title>' ) );
		$this->put( 'promo', 'assets/share.png' );
		$this->put( 'promo', 'assets/notes.pdf' );

		$page = $this->service->set_meta(
			'promo',
			array(
				'seo_title'       => " <b>Spring</b>\n sale ",
				'seo_description' => str_repeat( 'a', 600 ),
				'seo_image'       => './assets/share.png',
			)
		);
		$this->assertSame( 'Spring sale', $page['seo_title'] );
		$this->assertSame( HTMLPP_Seo::DESCRIPTION_MAX, strlen( $page['seo_description'] ) );
		$this->assertSame( 'assets/share.png', $page['seo_image'] );

		$this->assertSame( 'https://cdn.example.com/s.png', $this->service->set_meta( 'promo', array( 'seo_image' => 'https://cdn.example.com/s.png' ) )['seo_image'] );
		foreach ( array( 'assets/missing.png', 'assets/notes.pdf', 'javascript:alert(1)', 'https://exa mple.com/x.png', '../other/x.png' ) as $bad ) {
			$this->assertSame( 'htmlpp_bad_image', $this->service->set_meta( 'promo', array( 'seo_image' => $bad ) )->get_error_code(), $bad );
		}
		$this->assertSame( '', $this->service->set_meta( 'promo', array( 'seo_image' => '' ) )['seo_image'] );

		$this->service->set_meta( 'promo', array( 'seo_image' => 'assets/share.png' ) );
		$copy = $this->service->duplicate( 'promo', 'promo-b' );
		$this->assertSame( 'Spring sale', $copy['seo_title'] );
		$this->assertSame( 'assets/share.png', $copy['seo_image'] );

		$this->assertSame( 'Spring sale', HTMLPP_Meta::get( 'promo' )['seo_title'] );
	}
}

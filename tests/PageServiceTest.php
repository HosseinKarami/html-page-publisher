<?php
/**
 * Tests for HTMLPP_Page_Service against a temporary uploads directory.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class PageServiceTest extends TestCase {

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

	public function test_create_get_list_and_delete() {
		$page = $this->service->create( 'Spring Promo', array( 'html' => '<html><head><title>Spring</title></head><body>hi</body></html>' ) );
		$this->assertIsArray( $page );
		$this->assertSame( 'spring-promo', $page['slug'] );
		$this->assertTrue( $page['created'] );
		$this->assertSame( 'published', $page['status'] );
		$this->assertSame( 'Spring', $page['title'] );
		$this->assertSame( 'https://example.com/pages/spring-promo/', $page['url'] );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/html-page-publisher/spring-promo/index.html' );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/html-page-publisher/.htaccess' );

		$this->assertCount( 1, $this->service->list_pages() );
		$this->assertSame( 7, $this->service->get( 'spring-promo' )['author'] );
		$this->assertStringContainsString( 'hi', $this->service->get( 'spring-promo', true )['html'] );

		$this->assertTrue( $this->service->delete( 'spring-promo' ) );
		$this->assertCount( 0, $this->service->list_pages() );
		$this->assertSame( 'htmlpp_not_found', $this->service->get( 'spring-promo' )->get_error_code() );
	}

	public function test_replacing_requires_overwrite_and_keeps_history() {
		$this->service->create( 'p', array( 'html' => '<p>v1</p>' ) );

		$again = $this->service->create( 'p', array( 'html' => '<p>v2</p>' ) );
		$this->assertSame( 'htmlpp_exists', $again->get_error_code() );

		$replaced = $this->service->create( 'p', array( 'html' => '<p>v2</p>', 'overwrite' => true, 'status' => 'draft' ) );
		$this->assertFalse( $replaced['created'] );
		$this->assertSame( 'published', $replaced['status'], 'Draft flag never applies to a replace.' );
		$this->assertStringContainsString( 'v2', $this->service->get( 'p', true )['html'] );
		$this->assertCount( 1, $this->service->versions( 'p' ) );

		$this->service->restore( 'p', $this->service->versions( 'p' )[0]['name'] );
		$this->assertStringContainsString( 'v1', $this->service->get( 'p', true )['html'] );
	}

	public function test_draft_status_meta_and_preview() {
		$page = $this->service->create( 'd', array( 'html' => '<p>x</p>', 'status' => 'draft' ) );
		$this->assertSame( 'draft', $page['status'] );
		$this->assertStringContainsString( 'htmlpp_preview=', $page['preview_url'] );

		$published = $this->service->set_meta( 'd', array( 'status' => 'published', 'noindex' => true ) );
		$this->assertSame( 'published', $published['status'] );
		$this->assertTrue( $published['noindex'] );
		$this->assertSame( '', $published['preview_url'] );
	}

	public function test_custom_path_validation() {
		$this->service->create( 'a', array( 'html' => '<p>a</p>' ) );
		$this->service->create( 'b', array( 'html' => '<p>b</p>' ) );

		$this->assertSame( 'promo', $this->service->set_meta( 'a', array( 'path' => '/Promo/' ) )['path'] );
		$this->assertSame( 'https://example.com/promo/', $this->service->get( 'a' )['url'] );

		$taken = $this->service->set_meta( 'b', array( 'path' => 'promo' ) );
		$this->assertSame( 'htmlpp_path_taken', $taken->get_error_code() );

		$this->assertSame( 'htmlpp_bad_path', $this->service->set_meta( 'b', array( 'path' => 'wp-admin' ) )->get_error_code() );

		$GLOBALS['htmlpp_test_post_urls']['https://example.com/about/'] = 42;
		$this->assertSame( 'htmlpp_path_taken', $this->service->set_meta( 'b', array( 'path' => 'about' ) )->get_error_code() );

		$moved = $this->service->set_meta( 'a', array( 'path' => 'deals' ) );
		$this->assertCount( 1, $moved['messages'] );
		$this->assertSame( array( 'promo' => 'a' ), HTMLPP_Meta::path_redirects() );
	}

	public function test_rename_and_duplicate() {
		$this->service->create( 'old', array( 'html' => '<p>o</p>' ) );
		mkdir( wp_upload_dir()['basedir'] . '/html-page-publisher/old/css', 0777, true );
		file_put_contents( wp_upload_dir()['basedir'] . '/html-page-publisher/old/css/a.css', 'x' );

		$renamed = $this->service->rename( 'old', 'new' );
		$this->assertSame( 'new', $renamed['slug'] );
		$this->assertFalse( $this->service->exists( 'old' ) );
		$this->assertSame( 'new', HTMLPP_Meta::get_redirect( 'old' ) );
		$this->assertSame( 'htmlpp_not_found', $this->service->rename( 'old', 'x' )->get_error_code() );

		$copy = $this->service->duplicate( 'new', 'copy' );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/html-page-publisher/copy/css/a.css' );
		$this->assertSame( 'htmlpp_exists', $this->service->duplicate( 'new', 'copy' )->get_error_code() );
		$this->assertCount( 1, $this->service->list_files( 'copy' ) );
	}

	public function test_zip_bundle_and_sideloaded_files() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available.' );
		}
		$tmp = sys_get_temp_dir() . '/htmlpp-tests/bundle.zip';
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::CREATE );
		$zip->addFromString( 'site/index.html', '<html><body>z</body></html>' );
		$zip->addFromString( 'site/css/s.css', 'p{}' );
		$zip->addFromString( 'site/bad.php', '<?php' );
		$zip->close();

		$asset = sys_get_temp_dir() . '/htmlpp-tests/extra.css';
		file_put_contents( $asset, 'a{}' );

		$page = $this->service->create(
			'z',
			array(
				'zip'   => $tmp,
				'mode'  => 'sideload',
				'files' => array(
					array( 'name' => 'extra.css', 'tmp_name' => $asset, 'type' => '', 'error' => 0, 'size' => 3 ),
					array( 'name' => 'evil.css', 'tmp_name' => $asset . '.missing', 'type' => '', 'error' => 0, 'size' => 0 ),
				),
			)
		);
		$this->assertTrue( $page['created'] );
		$this->assertCount( 1, $page['skipped'] );
		$this->assertSame( array( 'extra.css' ), $page['files_added'] );
		$this->assertCount( 1, $page['file_errors'] );
		$refs = wp_list_pluck_test( $this->service->list_files( 'z' ), 'reference' );
		sort( $refs );
		$this->assertSame( array( 'assets/extra.css', 'css/s.css' ), $refs );

		$deleted = $this->service->delete_file( 'z', 'css/s.css' );
		$this->assertSame( 'css/s.css', $deleted['deleted'] );
		$this->assertSame( 'htmlpp_not_found', $this->service->delete_file( 'z', 'css/s.css' )->get_error_code() );
	}

	public function test_locked_editing_blocks_mutations_but_not_new_pages() {
		add_filter( 'htmlpp_editing_disabled', '__return_true_test' );
		$this->assertIsArray( $this->service->create( 'n', array( 'html' => '<p>n</p>' ) ) );
		$this->assertSame( 'htmlpp_locked', $this->service->update_html( 'n', '<p>x</p>' )->get_error_code() );
		$this->assertSame( 'htmlpp_locked', $this->service->rename( 'n', 'm' )->get_error_code() );
		$this->assertSame( 'htmlpp_locked', $this->service->create( 'n', array( 'html' => '<p>y</p>', 'overwrite' => true ) )->get_error_code() );
	}
}

function wp_list_pluck_test( array $rows, $key ) {
	return array_map(
		static function ( $row ) use ( $key ) {
			return $row[ $key ];
		},
		$rows
	);
}

function __return_true_test() {
	return true;
}

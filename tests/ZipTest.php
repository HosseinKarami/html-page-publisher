<?php
/**
 * Tests for HTMLPP_Zip::import().
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class ZipTest extends TestCase {

	private $tmp;

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available.' );
		}
		remove_all_test_filters();
		$this->tmp = sys_get_temp_dir() . '/htmlpp-zip-' . uniqid();
		mkdir( $this->tmp, 0777, true );
		mkdir( $this->tmp . '/dest', 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->tmp ) );
	}

	private function make_zip( array $entries ) {
		$path = $this->tmp . '/bundle.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		return $path;
	}

	public function test_imports_nested_bundle_and_strips_common_folder() {
		$zip = $this->make_zip(
			array(
				'my-site/index.html'        => '<html><body><link href="css/a.css"></body></html>',
				'my-site/css/a.css'         => 'body{}',
				'my-site/img/hero.png'      => "\x89PNG",
				'my-site/__MACOSX/._x'      => 'junk',
				'my-site/.DS_Store'         => 'junk',
				'my-site/evil.php'          => '<?php echo 1;',
				'my-site/js/app.js'         => 'console.log(1)',
				'my-site/js/bad.js'         => 'x <?php system("id"); ?>',
			)
		);

		$r = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );

		$this->assertTrue( $r['ok'], $r['error'] );
		$this->assertStringContainsString( 'css/a.css', $r['index_html'] );
		$this->assertFileExists( $this->tmp . '/dest/css/a.css' );
		$this->assertFileExists( $this->tmp . '/dest/img/hero.png' );
		$this->assertFileExists( $this->tmp . '/dest/js/app.js' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/index.html', 'index is returned, not written' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/evil.php' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/js/bad.js' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/__MACOSX' );
		$this->assertCount( 2, $r['skipped'] );
		$this->assertSame( array( 'css/a.css', 'img/hero.png', 'js/app.js' ), array_values( array_intersect( $r['written'], array( 'css/a.css', 'img/hero.png', 'js/app.js' ) ) ) );
	}

	public function test_double_extensions_and_php_in_any_file_type_are_refused() {
		$zip = $this->make_zip(
			array(
				'index.html'           => '<html></html>',
				'assets/logo.php.png'  => "<?php system('id');",
				'assets/x.php7.js'     => 'ok',
				'assets/font.woff2'    => "binary<?php x",
				'assets/short.js'      => "<?= shell_exec('id') ?>",
				'assets/doc.pdf'       => "%PDF-1.4 <? echo 1; ?>",
				'js/jquery.min.js'     => 'fine',
				'js/vendor.chunk.js'   => 'fine',
				'img/photo.jpg'        => "\xFF\xD8\xFF",
				'data/config.xml'      => '<?xml version="1.0"?><a/>',
			)
		);
		$r = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertTrue( $r['ok'], $r['error'] );
		$written = $r['written'];
		sort( $written );
		$this->assertSame( array( 'data/config.xml', 'img/photo.jpg', 'js/jquery.min.js', 'js/vendor.chunk.js' ), $written );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/assets/logo.php.png' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/assets/font.woff2' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/assets/short.js' );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/assets/doc.pdf' );
		$this->assertCount( 5, $r['skipped'] );
	}

	public function test_size_cap_uses_actual_bytes_not_headers() {
		add_filter( 'htmlpp_zip_max_bytes', static function () { return 50; } );
		$zip = $this->make_zip( array( 'index.html' => '<p>ok</p>', 'a.css' => str_repeat( 'x', 40 ), 'b.css' => str_repeat( 'y', 40 ) ) );
		$r   = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertFalse( $r['ok'] );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/b.css', 'The entry that broke the cap must not be left behind.' );
	}

	public function test_archive_with_directory_entries_finds_the_index() {
		// `zip -r` and Finder write directory records, so the archive indices
		// of the real files are not 0,1,2… — the index lookup must survive that.
		$path = $this->tmp . '/dirs.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addEmptyDir( 'my-site' );
		$zip->addFromString( 'my-site/index.html', '<html><body>real</body></html>' );
		$zip->addEmptyDir( 'my-site/css' );
		$zip->addFromString( 'my-site/css/s.css', 'body{}' );
		$zip->close();

		$r = HTMLPP_Zip::import( $path, $this->tmp . '/dest' );

		$this->assertTrue( $r['ok'], $r['error'] );
		$this->assertSame( '<html><body>real</body></html>', $r['index_html'] );
		$this->assertSame( array( 'css/s.css' ), $r['written'] );
		$this->assertFileDoesNotExist( $this->tmp . '/dest/index.html', 'index.html is returned, never written' );
		$this->assertFileExists( $this->tmp . '/dest/css/s.css' );
	}

	public function test_traversal_and_absolute_entries_are_skipped() {
		$zip = $this->make_zip(
			array(
				'index.html'           => '<html></html>',
				'../../escape.css'     => 'x',
				'/abs/path.css'        => 'x',
				'ok/../../../oops.css' => 'x',
			)
		);
		$r = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 3, $r['skipped'] );
		$this->assertFileDoesNotExist( $this->tmp . '/escape.css' );
		$this->assertFileDoesNotExist( $this->tmp . '/oops.css' );
	}

	public function test_single_html_file_is_used_as_index() {
		$zip = $this->make_zip( array( 'landing.html' => '<p>x</p>', 'style.css' => '' ) );
		$r   = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '<p>x</p>', $r['index_html'] );
	}

	public function test_missing_index_is_an_error() {
		$zip = $this->make_zip( array( 'a.html' => '', 'b.html' => '' ) );
		$r   = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'index.html', $r['error'] );
	}

	public function test_size_cap_is_enforced() {
		add_filter( 'htmlpp_zip_max_bytes', static function () { return 10; } );
		$zip = $this->make_zip( array( 'index.html' => str_repeat( 'a', 100 ) ) );
		$r   = HTMLPP_Zip::import( $zip, $this->tmp . '/dest' );
		$this->assertFalse( $r['ok'] );
	}

	public function test_blocked_extensions_cannot_be_allowed_by_filter() {
		add_filter( 'htmlpp_zip_allowed_extensions', static function ( $e ) { $e[] = 'php'; return $e; } );
		$this->assertNotContains( 'php', HTMLPP_Zip::allowed_extensions() );
	}
}

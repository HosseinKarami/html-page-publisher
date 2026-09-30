<?php
/**
 * Tests for when the WordPress.org review request appears.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class ReviewPromptTest extends TestCase {

	const NOW = 1790000000;

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options'] = array();
		$base                           = wp_upload_dir()['basedir'];
		exec( 'rm -rf ' . escapeshellarg( dirname( $base ) ) );
		mkdir( $base . '/html-page-publisher', 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( dirname( wp_upload_dir()['basedir'] ) ) );
	}

	private function page( $slug, $age_days ) {
		$dir = wp_upload_dir()['basedir'] . '/html-page-publisher/' . $slug;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/index.html', '<p>x</p>' );
		touch( $dir . '/index.html', time() - $age_days * DAY_IN_SECONDS );
	}

	public function test_one_published_page_after_three_days_is_enough() {
		$this->assertTrue( HTMLPP_Admin::review_due( 1, self::NOW - 4 * DAY_IN_SECONDS, self::NOW, '' ) );
	}

	public function test_waits_until_the_plugin_has_been_used_for_a_few_days() {
		$this->assertFalse( HTMLPP_Admin::review_due( 5, self::NOW - DAY_IN_SECONDS, self::NOW, '' ) );
	}

	public function test_no_published_page_means_no_request() {
		$this->assertFalse( HTMLPP_Admin::review_due( 0, self::NOW - 90 * DAY_IN_SECONDS, self::NOW, '' ) );
	}

	public function test_dismissal_is_permanent_and_later_snoozes() {
		$old = self::NOW - 30 * DAY_IN_SECONDS;
		$this->assertFalse( HTMLPP_Admin::review_due( 3, $old, self::NOW, 'dismissed' ) );
		$this->assertFalse( HTMLPP_Admin::review_due( 3, $old, self::NOW, (string) ( self::NOW + 60 ) ) );
		$this->assertTrue( HTMLPP_Admin::review_due( 3, $old, self::NOW, (string) ( self::NOW - 60 ) ) );
	}

	public function test_page_stats_counts_published_pages_including_pre_1_4_pages() {
		// Two pages from 1.2.x (no metadata), one draft, one fresh page.
		$this->page( 'legacy-a', 40 );
		$this->page( 'legacy-b', 20 );
		$this->page( 'draft', 1 );
		$this->page( 'new', 0 );
		update_option(
			'htmlpp_pages',
			array(
				'draft' => array( 'status' => 'draft' ),
				'new'   => array(
					'status'  => 'published',
					'created' => time(),
				),
			)
		);

		$stats = HTMLPP_Storage::page_stats();

		$this->assertSame( 3, $stats['public'], 'The draft is not counted; pages with no record are.' );
		$this->assertEqualsWithDelta( time() - 40 * DAY_IN_SECONDS, $stats['oldest'], 5, 'An upgraded site counts from its oldest page.' );
	}

	public function test_oldest_uses_the_file_date_when_the_record_is_newer() {
		// A 1.2.x page whose metadata record was only written recently.
		$this->page( 'legacy', 60 );
		update_option( 'htmlpp_pages', array( 'legacy' => array( 'status' => 'published', 'created' => time() ) ) );
		$this->assertEqualsWithDelta( time() - 60 * DAY_IN_SECONDS, HTMLPP_Storage::page_stats()['oldest'], 5 );
	}

	public function test_upgraded_site_with_one_old_page_qualifies_immediately() {
		// The case the old trigger missed: a 1.2.x user with a single page,
		// who upgraded today and publishes nothing new.
		$this->page( 'only-page', 25 );
		$stats     = HTMLPP_Storage::page_stats();
		$installed = time(); // Upgrade stamps "installed" now.
		$first_use = min( $installed, $stats['oldest'] );

		$this->assertTrue( HTMLPP_Admin::review_due( $stats['public'], $first_use, time(), '' ) );
	}
}

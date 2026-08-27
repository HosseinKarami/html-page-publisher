<?php
/**
 * Core sitemap provider for published pages.
 *
 * Loaded lazily by HTMLPP_Sitemap because WP_Sitemaps_Provider only exists
 * when core sitemaps are enabled.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single-page sitemap listing every public, indexable page.
 */
class HTMLPP_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Set the provider name and object type.
	 */
	public function __construct() {
		$this->name        = 'htmlpp';
		$this->object_type = 'htmlpp';
	}

	/**
	 * URL list for a sitemap page.
	 *
	 * @param int    $page_num       Page number (only 1 is used).
	 * @param string $object_subtype Unused.
	 * @return array
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( 1 !== (int) $page_num ) {
			return array();
		}
		return HTMLPP_Sitemap::entries();
	}

	/**
	 * Number of sitemap pages.
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return empty( HTMLPP_Sitemap::entries() ) ? 0 : 1;
	}
}

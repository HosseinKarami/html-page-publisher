<?php
/**
 * Lists published pages in the WordPress core XML sitemap
 * (/wp-sitemap-htmlpp-1.xml).
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the sitemap provider once core sitemaps are available.
 */
class HTMLPP_Sitemap {

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'add_provider' ), 10, 2 );
	}

	/**
	 * Register the provider under the name "htmlpp".
	 *
	 * @param WP_Sitemaps_Provider $provider Provider being registered.
	 * @param string               $name     Provider name.
	 * @return WP_Sitemaps_Provider
	 */
	public function add_provider( $provider, $name ) {
		static $registered = false;
		if ( ! $registered && class_exists( 'WP_Sitemaps_Provider' ) ) {
			$registered = true;
			require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-sitemap-provider.php';
			wp_register_sitemap_provider( 'htmlpp', new HTMLPP_Sitemap_Provider() );
		}
		return $provider;
	}

	/**
	 * Pages that belong in the sitemap: published and not noindex.
	 *
	 * @return array<int, array{loc:string, lastmod:string}>
	 */
	public static function entries() {
		$entries = array();
		foreach ( HTMLPP_Storage::list_pages() as $page ) {
			$meta = HTMLPP_Meta::get( $page['slug'] );
			if ( ! HTMLPP_Meta::is_public( $meta ) || ! empty( $meta['noindex'] ) ) {
				continue;
			}
			$entries[] = array(
				'loc'     => $page['url'],
				'lastmod' => gmdate( 'c', (int) $page['modified'] ),
			);
		}

		/**
		 * Filter the sitemap entries for published pages.
		 *
		 * @param array $entries Each has 'loc' and 'lastmod'.
		 */
		return (array) apply_filters( 'htmlpp_sitemap_entries', $entries );
	}
}

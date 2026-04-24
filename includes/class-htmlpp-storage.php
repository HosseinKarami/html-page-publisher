<?php
/**
 * Filesystem and URL helpers for uploaded pages.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTMLPP_Storage {

	/**
	 * Absolute path to the storage base directory.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . HTMLPP_STORAGE_DIRNAME;
	}

	/**
	 * Natural URL to the storage base directory (uploads/html-page-publisher/).
	 *
	 * @return string
	 */
	public static function base_url() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . HTMLPP_STORAGE_DIRNAME;
	}

	/**
	 * Lazily initialize and return the WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	public static function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/**
	 * Create the storage directory if it doesn't exist. Drops in an empty
	 * index.html to prevent directory listings on misconfigured servers.
	 */
	public static function ensure_dir() {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			$fs = self::fs();
			if ( $fs ) {
				$fs->put_contents( $index, '' );
			}
		}
	}

	/**
	 * Path to a single page's directory.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function page_dir( $slug ) {
		return trailingslashit( self::base_dir() ) . $slug;
	}

	/**
	 * Configured public URL for a page, respecting subdomain + prefix settings.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function public_page_url( $slug ) {
		$settings = HTMLPP_Settings::get_settings();
		$slug_enc = rawurlencode( $slug );

		if ( ! empty( $settings['subdomain'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			return $scheme . '://' . $settings['subdomain'] . '/' . $slug_enc . '/';
		}

		return home_url( '/' . $settings['url_prefix'] . '/' . $slug_enc . '/' );
	}

	/**
	 * List all published pages, sorted by most-recently-modified first.
	 *
	 * @return array
	 */
	public static function list_pages() {
		$dir   = self::base_dir();
		$pages = array();

		if ( ! is_dir( $dir ) ) {
			return $pages;
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return $pages;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$index = trailingslashit( $path ) . 'index.html';
			if ( ! file_exists( $index ) ) {
				continue;
			}

			$images     = array();
			$assets_dir = trailingslashit( $path ) . 'assets';
			if ( is_dir( $assets_dir ) ) {
				$asset_entries = @scandir( $assets_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $asset_entries ) ) {
					foreach ( $asset_entries as $f ) {
						if ( preg_match( '/\.(png|jpe?g|gif|svg|webp|avif)$/i', $f ) ) {
							$images[] = $f;
						}
					}
				}
			}

			$pages[] = array(
				'slug'     => $entry,
				'url'      => self::public_page_url( $entry ),
				'html'     => $index,
				'images'   => $images,
				'modified' => filemtime( $index ),
			);
		}

		usort(
			$pages,
			static function ( $a, $b ) {
				return $b['modified'] - $a['modified'];
			}
		);

		return $pages;
	}

	/**
	 * Delete a page directory. Guards against path traversal.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return bool True on success.
	 */
	public static function delete_page( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$base = realpath( self::base_dir() );
		$path = realpath( self::page_dir( $slug ) );

		if ( ! $base || ! $path ) {
			return false;
		}
		if ( 0 !== strpos( $path, $base ) ) {
			return false;
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		return (bool) $fs->delete( $path, true );
	}

	/**
	 * Normalize a slug to URL/filesystem-safe form.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function sanitize_slug( $slug ) {
		return sanitize_title( $slug );
	}
}

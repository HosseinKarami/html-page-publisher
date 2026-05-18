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

		// Drop the page's saved backups along with the page itself.
		$backups = realpath( self::backups_dir( $slug ) );
		$bbase   = realpath( self::backups_base_dir() );
		if ( $backups && $bbase && 0 === strpos( $backups, $bbase ) && is_dir( $backups ) ) {
			$fs->delete( $backups, true );
		}

		return (bool) $fs->delete( $path, true );
	}

	/**
	 * Absolute path to the backups base directory.
	 *
	 * Deliberately a sibling of base_dir() (not inside it) so backups are
	 * never matched by the public renderer or listed as pages.
	 *
	 * @return string
	 */
	public static function backups_base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . HTMLPP_BACKUPS_DIRNAME;
	}

	/**
	 * Path to a single page's backups directory.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function backups_dir( $slug ) {
		return trailingslashit( self::backups_base_dir() ) . $slug;
	}

	/**
	 * Resolve and validate the absolute index.html path for a slug.
	 *
	 * Returns '' if the slug is empty, the page directory is missing, or the
	 * resolved path escapes the storage base (path-traversal guard, mirroring
	 * delete_page() and the renderer).
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return string Absolute path to index.html, or '' on failure.
	 */
	public static function index_file( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return '';
		}

		$base = realpath( self::base_dir() );
		$dir  = realpath( self::page_dir( $slug ) );

		if ( ! $base || ! $dir || 0 !== strpos( $dir, $base ) || ! is_dir( $dir ) ) {
			return '';
		}

		return trailingslashit( $dir ) . 'index.html';
	}

	/**
	 * Read a page's current HTML.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return string|false File contents, or false if unreadable/missing.
	 */
	public static function read_page( $slug ) {
		$file = self::index_file( $slug );
		if ( '' === $file || ! file_exists( $file ) ) {
			return false;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		return $fs->get_contents( $file );
	}

	/**
	 * Maximum number of backups retained per page.
	 *
	 * @return int
	 */
	public static function max_backups() {
		$max = (int) apply_filters( 'htmlpp_max_backups', 10 );
		return $max < 1 ? 1 : $max;
	}

	/**
	 * Write new HTML to a page's index.html, snapshotting the current
	 * version into the backups directory first.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @param string $html New HTML content.
	 * @return bool True on success.
	 */
	public static function write_page( $slug, $html ) {
		$file = self::index_file( $slug );
		if ( '' === $file ) {
			return false;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}

		// Snapshot the version we're about to overwrite.
		if ( file_exists( $file ) ) {
			self::backup_current( $slug, $fs );
		}

		return (bool) $fs->put_contents( $file, $html );
	}

	/**
	 * Copy a page's current index.html into its backups directory, then
	 * prune to the retention limit.
	 *
	 * @param string              $slug Sanitized slug.
	 * @param WP_Filesystem_Base $fs   Filesystem instance.
	 */
	private static function backup_current( $slug, $fs ) {
		$src = self::index_file( $slug );
		if ( '' === $src || ! file_exists( $src ) ) {
			return;
		}

		$content = $fs->get_contents( $src );
		if ( false === $content ) {
			return;
		}

		$dir = self::backups_dir( $slug );
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		// Guard the backups root against directory listings, mirroring
		// ensure_dir()'s treatment of the storage base.
		$root_index = trailingslashit( self::backups_base_dir() ) . 'index.html';
		if ( ! file_exists( $root_index ) ) {
			$fs->put_contents( $root_index, '' );
		}

		$stamp = gmdate( 'Ymd-His' );
		$dest  = trailingslashit( $dir ) . $stamp . '.html';
		$n     = 1;
		while ( file_exists( $dest ) ) {
			$dest = trailingslashit( $dir ) . $stamp . '-' . $n . '.html';
			++$n;
		}

		if ( $fs->put_contents( $dest, $content ) ) {
			self::prune_backups( $slug );
		}
	}

	/**
	 * List a page's backups, newest first.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return array<int, array{name:string,modified:int,size:int}>
	 */
	public static function list_backups( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return array();
		}

		$dir = self::backups_dir( $slug );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return array();
		}

		$backups = array();
		foreach ( $entries as $entry ) {
			if ( 'index.html' === $entry || ! preg_match( '/\.html$/i', $entry ) ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$backups[] = array(
				'name'     => $entry,
				'modified' => (int) filemtime( $path ),
				'size'     => (int) filesize( $path ),
			);
		}

		usort(
			$backups,
			static function ( $a, $b ) {
				return $b['modified'] - $a['modified'];
			}
		);

		return $backups;
	}

	/**
	 * Delete backups beyond the retention limit (oldest first).
	 *
	 * @param string $slug Sanitized slug.
	 */
	private static function prune_backups( $slug ) {
		$backups = self::list_backups( $slug );
		$max     = self::max_backups();
		if ( count( $backups ) <= $max ) {
			return;
		}

		$fs = self::fs();
		if ( ! $fs ) {
			return;
		}

		$dir   = self::backups_dir( $slug );
		$stale = array_slice( $backups, $max );
		foreach ( $stale as $b ) {
			// Names come from scandir() and are validated to end in .html.
			$fs->delete( trailingslashit( $dir ) . $b['name'] );
		}
	}

	/**
	 * Restore a named backup as the live index.html. The version being
	 * replaced is itself backed up first (so a restore is undoable).
	 *
	 * @param string $slug   Slug (will be sanitized).
	 * @param string $backup Backup filename (basename only).
	 * @return bool True on success.
	 */
	public static function restore_backup( $slug, $backup ) {
		$slug   = self::sanitize_slug( $slug );
		$backup = basename( (string) $backup );

		if ( '' === $slug
			|| 'index.html' === $backup
			|| ! preg_match( '/^[A-Za-z0-9._-]+\.html$/', $backup )
		) {
			return false;
		}

		$bbase = realpath( self::backups_base_dir() );
		$path  = realpath( trailingslashit( self::backups_dir( $slug ) ) . $backup );

		if ( ! $bbase || ! $path || 0 !== strpos( $path, $bbase ) || ! is_file( $path ) ) {
			return false;
		}

		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}

		$content = $fs->get_contents( $path );
		if ( false === $content ) {
			return false;
		}

		// write_page() snapshots the current version before overwriting.
		return self::write_page( $slug, $content );
	}

	/**
	 * Resolve and validate a page's assets directory.
	 *
	 * Same path-traversal guard as index_file(); the assets/ subfolder itself
	 * may not exist yet (callers wp_mkdir_p() it before writing).
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return string Absolute path to the assets dir, or '' on failure.
	 */
	public static function assets_dir( $slug ) {
		$slug = self::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return '';
		}

		$base = realpath( self::base_dir() );
		$dir  = realpath( self::page_dir( $slug ) );

		if ( ! $base || ! $dir || 0 !== strpos( $dir, $base ) || ! is_dir( $dir ) ) {
			return '';
		}

		return trailingslashit( $dir ) . 'assets';
	}

	/**
	 * List a page's image assets, sorted by filename.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return array<int, array{name:string,reference:string,url:string,size:int,modified:int}>
	 */
	public static function list_assets( $slug ) {
		$dir = self::assets_dir( $slug );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return array();
		}

		$slug      = self::sanitize_slug( $slug );
		$asset_url = trailingslashit( self::base_url() ) . $slug . '/assets/';
		$assets    = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! preg_match( '/\.(png|jpe?g|gif|svg|webp|avif)$/i', $entry ) ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$assets[] = array(
				'name'      => $entry,
				'reference' => 'assets/' . $entry,
				'url'       => $asset_url . rawurlencode( $entry ),
				'size'      => (int) filesize( $path ),
				'modified'  => (int) filemtime( $path ),
			);
		}

		usort(
			$assets,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $assets;
	}

	/**
	 * Resolve and validate the path to a single existing asset.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @param string $name Asset filename (basename only).
	 * @return string Absolute path, or '' if invalid / missing / traversal.
	 */
	public static function asset_path( $slug, $name ) {
		$dir  = self::assets_dir( $slug );
		$name = basename( (string) $name );

		if ( '' === $dir
			|| '' === $name
			|| ! preg_match( '/^[A-Za-z0-9 ._-]+\.(png|jpe?g|gif|svg|webp|avif)$/i', $name )
		) {
			return '';
		}

		$real_dir = realpath( $dir );
		$real     = realpath( trailingslashit( $dir ) . $name );

		if ( ! $real_dir || ! $real || 0 !== strpos( $real, $real_dir ) || ! is_file( $real ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Delete a single asset. Guards against path traversal.
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @param string $name Asset filename (basename only).
	 * @return bool True on success.
	 */
	public static function delete_asset( $slug, $name ) {
		$path = self::asset_path( $slug, $name );
		if ( '' === $path ) {
			return false;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		return (bool) $fs->delete( $path );
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

<?php
/**
 * Filesystem and URL helpers for uploaded pages.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paths, URLs, file I/O and directory protection for stored pages.
 */
class HTMLPP_Storage {

	/**
	 * Transient that caches the direct-access protection probe.
	 */
	const PROTECTION_TRANSIENT = 'htmlpp_protection_status';

	/**
	 * First line of every .htaccess this plugin writes.
	 */
	const HTACCESS_HEADER = "# HTML Page Publisher\n";

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
	 * Direct requests to this URL are denied by the .htaccess written by
	 * ensure_dir(); pages are only ever served through HTMLPP_Renderer.
	 *
	 * @return string
	 */
	public static function base_url() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . HTMLPP_STORAGE_DIRNAME;
	}

	/*
	|--------------------------------------------------------------------------
	| Low-level file helpers
	|--------------------------------------------------------------------------
	|
	| The plugin only ever touches its own directories inside wp-content/
	| uploads, which PHP must be able to write for WordPress media uploads to
	| work at all. WP_Filesystem() is therefore not required: on hosts where
	| get_filesystem_method() is not 'direct' it would silently fail to
	| connect, so these helpers use native PHP the same way core's media
	| handling does.
	*/

	/**
	 * Lazily initialize and return the WP_Filesystem instance when the
	 * direct method is available.
	 *
	 * Kept for backwards compatibility with code that called this helper
	 * directly. Internal code uses put_contents()/get_contents()/delete()
	 * which fall back to native PHP when WP_Filesystem cannot connect.
	 *
	 * @return WP_Filesystem_Base|null Null when the direct method is unavailable.
	 */
	public static function fs() {
		global $wp_filesystem;
		static $usable = null;

		if ( null === $usable ) {
			$usable = false;
			if ( ! function_exists( 'get_filesystem_method' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( 'direct' === get_filesystem_method() && WP_Filesystem() && $wp_filesystem ) {
				$usable = ! ( is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() );
			}
		}

		return $usable ? $wp_filesystem : null;
	}

	/**
	 * File permission mode for files we create.
	 *
	 * @return int
	 */
	private static function file_mode() {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	}

	/**
	 * Write a file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function put_contents( $path, $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Writes only inside the plugin's own uploads directory; failures are reported to the user by the caller.
		$written = @file_put_contents( $path, (string) $contents );
		if ( false === $written ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort; some hosts disallow chmod.
		@chmod( $path, self::file_mode() );
		return true;
	}

	/**
	 * Read a file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false Contents, or false if missing/unreadable.
	 */
	public static function get_contents( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Local file inside the plugin's own uploads directory.
		return @file_get_contents( $path );
	}

	/**
	 * Delete a file or directory.
	 *
	 * @param string $path      Absolute path.
	 * @param bool   $recursive Delete directory contents too.
	 * @return bool
	 */
	public static function delete( $path, $recursive = false ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return ! file_exists( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		if ( $recursive ) {
			$entries = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					self::delete( trailingslashit( $path ) . $entry, true );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Directory inside the plugin's own uploads directory.
		return @rmdir( $path );
	}

	/**
	 * Move (rename) a file, overwriting the destination.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	public static function move( $from, $to ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Both paths are inside the plugin's own uploads directory.
		return @rename( $from, $to );
	}

	/*
	|--------------------------------------------------------------------------
	| Directory protection
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apache rules that deny direct HTTP access to a storage directory.
	 *
	 * Pages must only be reachable through the renderer (so URL prefix,
	 * subdomain, caching headers and every future per-page control apply)
	 * and version-history snapshots must never be public.
	 *
	 * @return string
	 */
	public static function htaccess_rules() {
		$rules = self::HTACCESS_HEADER
			. "# Files in this directory are served by WordPress at their public page URL.\n"
			. "# Direct access is denied so the plugin's URL, caching and access rules always apply.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder deny,allow\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		/**
		 * Filter the .htaccess contents written into the storage directories.
		 *
		 * @param string $rules Apache directives.
		 */
		return (string) apply_filters( 'htmlpp_htaccess_rules', $rules );
	}

	/**
	 * Create a storage directory if needed and drop in the protection files
	 * (an empty index.html against directory listings and an .htaccess that
	 * denies direct access on Apache/LiteSpeed).
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool True if the directory exists (or was created).
	 */
	public static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			self::put_contents( $index, '' );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		$rules    = self::htaccess_rules();
		if ( '' === $rules ) {
			// Opt-out via the filter: remove the file this plugin wrote (it is
			// identified by its header) so server-level rules take over. A
			// hand-managed .htaccess without the header is left alone.
			if ( file_exists( $htaccess ) && 0 === strpos( (string) self::get_contents( $htaccess ), self::HTACCESS_HEADER ) ) {
				self::delete( $htaccess );
			}
			return true;
		}
		if ( ! file_exists( $htaccess ) || self::get_contents( $htaccess ) !== $rules ) {
			self::put_contents( $htaccess, $rules );
		}

		return true;
	}

	/**
	 * Create the storage and backups directories with their protection files.
	 */
	public static function ensure_dir() {
		self::protect_dir( self::base_dir() );
		self::protect_dir( self::backups_base_dir() );
	}

	/**
	 * Probe whether the web server actually blocks direct access to the
	 * storage directory (nginx ignores .htaccess, for example).
	 *
	 * Result is cached for an hour. Returns 'blocked', 'open' or 'unknown'
	 * plus the HTTP status code observed.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array{status:string, code:int, canary:int}
	 */
	public static function direct_access_status( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::PROTECTION_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return $cached;
			}
		}

		self::ensure_dir();

		$code   = self::probe_code( trailingslashit( self::base_url() ) . 'index.html' );
		$status = 'unknown';
		$canary = 0;

		if ( 403 === $code ) {
			$status = 'blocked';
		} elseif ( $code >= 200 && $code < 300 ) {
			$status = 'open';
		} elseif ( 404 === $code ) {
			// A 404 only proves protection if the uploads URL actually maps to
			// the uploads directory (offload/CDN plugins and custom upload
			// paths can make everything 404). Check with a throwaway file
			// outside the protected folders.
			$uploads = wp_upload_dir();
			$name    = 'htmlpp-probe-' . wp_generate_password( 12, false ) . '.txt';
			$path    = trailingslashit( $uploads['basedir'] ) . $name;
			if ( self::put_contents( $path, 'ok' ) ) {
				$canary = self::probe_code( trailingslashit( $uploads['baseurl'] ) . $name );
				wp_delete_file( $path );
			}
			$status = ( $canary >= 200 && $canary < 300 ) ? 'blocked' : 'unknown';
		}

		$result = array(
			'status' => $status,
			'code'   => $code,
			'canary' => $canary,
		);
		set_transient( self::PROTECTION_TRANSIENT, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * HTTP status observed for a URL (0 when the request failed).
	 *
	 * HTTPS loopback commonly fails on hosts with self-signed or SNI-bound
	 * certificates; the web server enforces the same rules over plain HTTP,
	 * so that is retried before giving up.
	 *
	 * @param string $url URL to request.
	 * @return int
	 */
	private static function probe_code( $url ) {
		$response = self::probe( $url );
		if ( is_wp_error( $response ) && 0 === strpos( $url, 'https://' ) ) {
			$response = self::probe( set_url_scheme( $url, 'http' ) );
		}
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Issue a HEAD request for the protection probe.
	 *
	 * @param string $url URL to request.
	 * @return array|WP_Error
	 */
	private static function probe( $url ) {
		return wp_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
				'user-agent'  => 'HTML Page Publisher/' . HTMLPP_VERSION . ' protection probe',
			)
		);
	}

	/**
	 * Regex fragment matching the extensions accepted as page assets, built
	 * from the same (filterable) list the uploader enforces.
	 *
	 * @return string e.g. "(?:png|jpg|jpeg|gif|svg|webp|avif)".
	 */
	public static function asset_extension_pattern() {
		$mimes = class_exists( 'HTMLPP_Uploader' )
			? HTMLPP_Uploader::allowed_image_mimes()
			: array(
				'png'      => 'image/png',
				'jpg|jpeg' => 'image/jpeg',
				'gif'      => 'image/gif',
				'svg'      => 'image/svg+xml',
				'webp'     => 'image/webp',
				'avif'     => 'image/avif',
			);

		$keys = array();
		foreach ( array_keys( $mimes ) as $key ) {
			// Keys are wp_handle_upload() style alternations ("jpg|jpeg").
			if ( preg_match( '/^[a-z0-9|]+$/i', (string) $key ) ) {
				$keys[] = (string) $key;
			}
		}
		if ( empty( $keys ) ) {
			$keys[] = 'png';
		}

		return '(?:' . implode( '|', $keys ) . ')';
	}

	/*
	|--------------------------------------------------------------------------
	| Pages
	|--------------------------------------------------------------------------
	*/

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
	 * Whether a page with this slug exists (has an index.html).
	 *
	 * @param string $slug Slug (will be sanitized).
	 * @return bool
	 */
	public static function page_exists( $slug ) {
		$file = self::index_file( $slug );
		return '' !== $file && is_file( $file );
	}

	/**
	 * Configured public URL for a page, respecting subdomain + prefix settings.
	 *
	 * @param string $slug        Page slug.
	 * @param bool   $main_domain Force the main-domain prefix URL even when a
	 *                            subdomain is configured (used for admin
	 *                            previews, which must work before DNS does).
	 * @return string
	 */
	public static function public_page_url( $slug, $main_domain = false ) {
		$settings = HTMLPP_Settings::get_settings();
		$slug_enc = rawurlencode( $slug );

		if ( ! $main_domain && ! empty( $settings['subdomain'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			return $scheme . '://' . $settings['subdomain'] . '/' . $slug_enc . '/';
		}

		return home_url( '/' . $settings['url_prefix'] . '/' . $slug_enc . '/' );
	}

	/**
	 * Public URL of one of a page's assets (served through the renderer).
	 *
	 * @param string $slug        Page slug.
	 * @param string $name        Asset filename (basename).
	 * @param bool   $main_domain See public_page_url().
	 * @return string
	 */
	public static function public_asset_url( $slug, $name, $main_domain = false ) {
		return self::public_page_url( $slug, $main_domain ) . 'assets/' . rawurlencode( $name );
	}

	/**
	 * Extract the <title> from a page's HTML without loading the whole file.
	 *
	 * @param string $index Absolute path to index.html.
	 * @return string Decoded title, or '' if none.
	 */
	public static function extract_title( $index ) {
		if ( ! is_readable( $index ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Local file; only the head is read.
		$head = @file_get_contents( $index, false, null, 0, 65536 );
		if ( ! is_string( $head ) || ! preg_match( '/<title[^>]*>(.*?)<\/title>/is', $head, $m ) ) {
			return '';
		}
		$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return preg_replace( '/\s+/', ' ', $title );
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

		$asset_pattern = self::asset_extension_pattern();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || '' === $entry || '.' === $entry[0] ) {
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
						if ( preg_match( '/\.' . $asset_pattern . '$/i', $f ) ) {
							$images[] = $f;
						}
					}
				}
			}

			$pages[] = array(
				'slug'     => $entry,
				'title'    => self::extract_title( $index ),
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
		if ( 0 !== strpos( $path, trailingslashit( $base ) ) ) {
			return false;
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}

		// Drop the page's saved backups along with the page itself.
		$backups = realpath( self::backups_dir( $slug ) );
		$bbase   = realpath( self::backups_base_dir() );
		if ( $backups && $bbase && 0 === strpos( $backups, trailingslashit( $bbase ) ) && is_dir( $backups ) ) {
			self::delete( $backups, true );
		}

		$deleted = self::delete( $path, true );

		if ( $deleted ) {
			/**
			 * Fires after a page has been deleted.
			 *
			 * @param string $slug Page slug.
			 */
			do_action( 'htmlpp_page_deleted', $slug );
		}

		return $deleted;
	}

	/*
	|--------------------------------------------------------------------------
	| Version history
	|--------------------------------------------------------------------------
	*/

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

		if ( ! $base || ! $dir || 0 !== strpos( $dir, trailingslashit( $base ) ) || ! is_dir( $dir ) ) {
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
		if ( '' === $file ) {
			return false;
		}
		return self::get_contents( $file );
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
		$slug = self::sanitize_slug( $slug );
		$file = self::index_file( $slug );
		if ( '' === $file ) {
			return false;
		}

		// Snapshot the version we're about to overwrite.
		if ( file_exists( $file ) ) {
			self::backup_current( $slug );
		}

		$ok = self::put_contents( $file, $html );

		if ( $ok ) {
			/**
			 * Fires after a page's HTML has been written (upload overwrite,
			 * editor save or restore).
			 *
			 * @param string $slug Page slug.
			 * @param string $html The HTML that was written.
			 */
			do_action( 'htmlpp_page_updated', $slug, $html );
		}

		return $ok;
	}

	/**
	 * Copy a page's current index.html into its backups directory, then
	 * prune to the retention limit.
	 *
	 * @param string $slug Sanitized slug.
	 */
	private static function backup_current( $slug ) {
		$src = self::index_file( $slug );
		if ( '' === $src || ! file_exists( $src ) ) {
			return;
		}

		$content = self::get_contents( $src );
		if ( false === $content ) {
			return;
		}

		// Guard the backups root and this page's folder against direct
		// access and listings.
		self::protect_dir( self::backups_base_dir() );

		$dir = self::backups_dir( $slug );
		if ( ! self::protect_dir( $dir ) ) {
			return;
		}

		// Sortable timestamp plus a random token so snapshot URLs cannot be
		// guessed on servers that ignore .htaccess.
		$stamp = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false ) );
		$dest  = trailingslashit( $dir ) . $stamp . '.html';
		$n     = 1;
		while ( file_exists( $dest ) ) {
			$dest = trailingslashit( $dir ) . $stamp . '-' . $n . '.html';
			++$n;
		}

		if ( self::put_contents( $dest, $content ) ) {
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
				if ( $b['modified'] === $a['modified'] ) {
					return strcmp( $b['name'], $a['name'] );
				}
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

		$dir   = self::backups_dir( $slug );
		$stale = array_slice( $backups, $max );
		foreach ( $stale as $b ) {
			// Names come from scandir() and are validated to end in .html.
			self::delete( trailingslashit( $dir ) . $b['name'] );
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

		if ( ! $bbase || ! $path || 0 !== strpos( $path, trailingslashit( $bbase ) ) || ! is_file( $path ) ) {
			return false;
		}

		$content = self::get_contents( $path );
		if ( false === $content ) {
			return false;
		}

		// write_page() snapshots the current version before overwriting.
		return self::write_page( $slug, $content );
	}

	/*
	|--------------------------------------------------------------------------
	| Assets
	|--------------------------------------------------------------------------
	*/

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

		if ( ! $base || ! $dir || 0 !== strpos( $dir, trailingslashit( $base ) ) || ! is_dir( $dir ) ) {
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

		$slug    = self::sanitize_slug( $slug );
		$assets  = array();
		$pattern = '/\.' . self::asset_extension_pattern() . '$/i';

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! preg_match( $pattern, $entry ) ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$assets[] = array(
				'name'      => $entry,
				'reference' => 'assets/' . $entry,
				// Served through the renderer on the main domain so previews
				// work in wp-admin even before a subdomain's DNS is live.
				'url'       => self::public_asset_url( $slug, $entry, true ),
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
			|| ! preg_match( '/^[A-Za-z0-9 ._-]+\.' . self::asset_extension_pattern() . '$/i', $name )
		) {
			return '';
		}

		$real_dir = realpath( $dir );
		$real     = realpath( trailingslashit( $dir ) . $name );

		if ( ! $real_dir || ! $real || 0 !== strpos( $real, trailingslashit( $real_dir ) ) || ! is_file( $real ) ) {
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
		return self::delete( $path );
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

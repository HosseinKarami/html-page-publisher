<?php
/**
 * Intercepts incoming requests that match a configured page URL and serves
 * the uploaded HTML / asset file directly.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end request router and static file streamer.
 */
class HTMLPP_Renderer {

	/**
	 * Extensions that are never served, whatever ends up in a page folder.
	 *
	 * @var string[]
	 */
	const BLOCKED_EXTENSIONS = array( 'php', 'phtml', 'phar', 'pl', 'py', 'sh', 'cgi', 'htaccess', 'htpasswd' );

	/**
	 * Hook into init at priority 0 — early enough to short-circuit WP's
	 * main query when we're going to serve a static file.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Entry point. If the current request matches a configured page URL,
	 * serve the corresponding file and exit. Otherwise, return and let
	 * WordPress handle the request normally.
	 */
	public function maybe_serve() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';
		$uri  = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and validated segment-by-segment in match().
			: '';

		$settings = HTMLPP_Settings::get_settings();
		$match    = null;
		foreach ( self::home_path_candidates() as $home_path ) {
			$match = self::match( $host, $uri, $settings, $home_path );
			if ( null !== $match ) {
				break;
			}
		}
		if ( null === $match ) {
			return;
		}

		$slug     = $match['slug'];
		$page_dir = HTMLPP_Storage::page_dir( $slug );
		if ( ! is_dir( $page_dir ) ) {
			return;
		}

		$is_page   = '' === $match['rel'];
		$candidate = trailingslashit( $page_dir ) . ( $is_page ? 'index.html' : $match['rel'] );
		$real_file = realpath( $candidate );
		$real_dir  = realpath( $page_dir );
		if ( ! $real_file || ! $real_dir || 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) ) {
			return;
		}
		$file = $real_file;

		if ( ! is_file( $file ) ) {
			return;
		}

		// Refuse to serve anything with a dangerous extension.
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
			return;
		}

		/**
		 * Fires just before a page file is streamed to the client.
		 *
		 * Hook here to require authentication, log a view, or exit early. It
		 * fires for every request that would reveal the page exists — including
		 * the non-canonical /prefix/slug form just before its 301 redirect.
		 *
		 * @param string $slug Page slug.
		 * @param string $file Absolute path of the file about to be served.
		 * @param string $rel  Path relative to the page directory ('' for index.html).
		 */
		do_action( 'htmlpp_before_serve', $slug, $file, $match['rel'] );

		// Canonical URL for the page itself is the trailing-slash form: the
		// AI-generated HTML references "assets/…" relatively, which only
		// resolves under /prefix/slug/ — not /prefix/slug.
		if ( $is_page && ! $match['trailing_slash'] && self::is_safe_method() ) {
			$target = $match['canonical'];
			if ( '' !== $match['query'] ) {
				$target .= '?' . $match['query'];
			}
			if ( has_action( 'htmlpp_before_serve' ) ) {
				// An access gate is attached: keep shared caches from
				// remembering the redirect (and thus the page's existence).
				nocache_headers();
			}
			wp_safe_redirect( $target, 301, 'HTML Page Publisher' );
			exit;
		}

		self::stream( $file, $ext, $is_page, $slug );
	}

	/**
	 * Home-path candidates to try when routing, most specific first: the
	 * filtered home URL (what public_page_url() advertises — e.g. WPML's
	 * /de/ prefix) and the raw home option (so links that predate a filter
	 * keep working).
	 *
	 * @return string[]
	 */
	public static function home_path_candidates() {
		$candidates = array( self::home_path() );

		$raw = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$raw = trim( $raw, '/' );
		$raw = '' === $raw ? '' : '/' . $raw;
		if ( ! in_array( $raw, $candidates, true ) ) {
			$candidates[] = $raw;
		}

		/**
		 * Filter the home-path candidates used for routing.
		 *
		 * @param string[] $candidates Paths such as '' or '/blog'.
		 */
		return (array) apply_filters( 'htmlpp_home_path_candidates', $candidates );
	}

	/**
	 * Path component of the home URL ('' for root installs, '/blog' for a
	 * subdirectory install).
	 *
	 * @return string
	 */
	public static function home_path() {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = trim( $path, '/' );
		return '' === $path ? '' : '/' . $path;
	}

	/**
	 * Resolve a request to a page slug and a relative file path.
	 *
	 * Pure function of its inputs (no filesystem access) so routing can be
	 * unit-tested. Returns null when the request is not for a page.
	 *
	 * @param string $host      Lowercased HTTP host (may include a port).
	 * @param string $uri       Raw REQUEST_URI.
	 * @param array  $settings  Plugin settings (url_prefix, subdomain).
	 * @param string $home_path Path component of the home URL ('' or '/sub').
	 * @return array{slug:string, rel:string, path:string, canonical:string, query:string, trailing_slash:bool}|null
	 */
	public static function match( $host, $uri, $settings, $home_path = '' ) {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$path = rawurldecode( $path );
		if ( false !== strpos( $path, "\0" ) ) {
			return null;
		}

		$trailing_slash = '/' === substr( $path, -1 );
		$request_path   = rtrim( $path, '/' );

		// Strip the port from host (e.g. "sales.example.com:8080").
		$host_no_port = preg_replace( '/:\d+$/', '', (string) $host );

		$subdomain = isset( $settings['subdomain'] ) ? (string) $settings['subdomain'] : '';
		$prefix    = isset( $settings['url_prefix'] ) ? trim( (string) $settings['url_prefix'], '/' ) : '';

		if ( '' !== $subdomain && 0 === strcasecmp( $host_no_port, $subdomain ) ) {
			// On the configured subdomain the whole path is <slug>/<rest>.
			$match = trim( $request_path, '/' );
		} else {
			if ( '' === $prefix ) {
				return null;
			}
			$rel_path = $request_path;
			// Subdirectory install: the home path precedes the prefix.
			if ( '' !== $home_path ) {
				if ( 0 !== strpos( $rel_path, $home_path . '/' ) ) {
					return null;
				}
				$rel_path = substr( $rel_path, strlen( $home_path ) );
			}
			$rel_path = trim( $rel_path, '/' );
			$needle   = $prefix . '/';
			if ( 0 !== strpos( $rel_path, $needle ) ) {
				return null;
			}
			$match = substr( $rel_path, strlen( $needle ) );
		}

		if ( '' === $match ) {
			return null;
		}

		$parts = explode( '/', $match );
		foreach ( $parts as $part ) {
			// Reject empty segments, dot-segments and dotfiles (.htaccess).
			if ( '' === $part || '.' === $part[0] ) {
				return null;
			}
		}

		$slug = HTMLPP_Storage::sanitize_slug( array_shift( $parts ) );
		if ( '' === $slug ) {
			return null;
		}

		if ( '' !== $subdomain && 0 === strcasecmp( $host_no_port, $subdomain ) ) {
			$canonical = '/' . rawurlencode( $slug ) . '/';
		} else {
			$canonical = $home_path . '/' . $prefix . '/' . rawurlencode( $slug ) . '/';
		}

		return array(
			'slug'           => $slug,
			'rel'            => implode( '/', $parts ),
			'path'           => $request_path,
			'canonical'      => $canonical,
			'query'          => $query,
			'trailing_slash' => $trailing_slash,
		);
	}

	/**
	 * Whether the request method allows a redirect without losing a body.
	 *
	 * @return bool
	 */
	private static function is_safe_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return 'GET' === $method || 'HEAD' === $method;
	}

	/**
	 * Content types for the file extensions the plugin serves.
	 *
	 * @return array<string,string>
	 */
	public static function mime_map() {
		$map = array(
			'html'        => 'text/html; charset=UTF-8',
			'htm'         => 'text/html; charset=UTF-8',
			'css'         => 'text/css; charset=UTF-8',
			'js'          => 'application/javascript; charset=UTF-8',
			'mjs'         => 'application/javascript; charset=UTF-8',
			'json'        => 'application/json; charset=UTF-8',
			'map'         => 'application/json; charset=UTF-8',
			'xml'         => 'application/xml; charset=UTF-8',
			'txt'         => 'text/plain; charset=UTF-8',
			'md'          => 'text/plain; charset=UTF-8',
			'png'         => 'image/png',
			'jpg'         => 'image/jpeg',
			'jpeg'        => 'image/jpeg',
			'gif'         => 'image/gif',
			'svg'         => 'image/svg+xml',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'ico'         => 'image/x-icon',
			'bmp'         => 'image/bmp',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			'ttf'         => 'font/ttf',
			'otf'         => 'font/otf',
			'eot'         => 'application/vnd.ms-fontobject',
			'mp4'         => 'video/mp4',
			'webm'        => 'video/webm',
			'ogv'         => 'video/ogg',
			'mp3'         => 'audio/mpeg',
			'ogg'         => 'audio/ogg',
			'wav'         => 'audio/wav',
			'm4a'         => 'audio/mp4',
			'pdf'         => 'application/pdf',
			'wasm'        => 'application/wasm',
			'vtt'         => 'text/vtt; charset=UTF-8',
			'webmanifest' => 'application/manifest+json',
		);

		/**
		 * Filter the extension → Content-Type map used when serving files.
		 *
		 * @param array $map Lowercase extension => MIME type.
		 */
		return (array) apply_filters( 'htmlpp_mime_map', $map );
	}

	/**
	 * Stream the file to the client with content-type and caching headers,
	 * honouring conditional (If-None-Match / If-Modified-Since) and HEAD
	 * requests.
	 *
	 * @param string $file    Absolute path.
	 * @param string $ext     Lowercased extension.
	 * @param bool   $is_page True for the page's index.html, false for assets.
	 * @param string $slug    Page slug.
	 */
	private static function stream( $file, $ext, $is_page, $slug = '' ) {
		$map  = self::mime_map();
		$mime = isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';

		$size  = (int) filesize( $file );
		$mtime = (int) filemtime( $file );
		$etag  = '"' . md5( $file . '|' . $mtime . '|' . $size . '|' . HTMLPP_VERSION ) . '"';

		/**
		 * Filter how long (seconds) browsers and CDNs may cache a served file.
		 *
		 * Defaults: 0 for the page HTML (always revalidated via ETag, so edits
		 * show immediately) and one hour for assets.
		 *
		 * @param int    $max_age Seconds.
		 * @param string $file    Absolute path being served.
		 * @param string $ext     File extension.
		 * @param bool   $is_page Whether this is the page's index.html.
		 * @param string $slug    Page slug.
		 */
		$max_age = (int) apply_filters( 'htmlpp_cache_max_age', $is_page ? 0 : HOUR_IN_SECONDS, $file, $ext, $is_page, $slug );

		$cache_control = $max_age > 0
			? 'public, max-age=' . $max_age
			: 'public, max-age=0, must-revalidate';

		/**
		 * Filter the full Cache-Control header value.
		 *
		 * @param string $cache_control Header value.
		 * @param string $file          Absolute path being served.
		 * @param string $ext           File extension.
		 * @param bool   $is_page       Whether this is the page's index.html.
		 * @param string $slug          Page slug.
		 */
		$cache_control = (string) apply_filters( 'htmlpp_cache_control', $cache_control, $file, $ext, $is_page, $slug );

		// Drop any buffering another plugin started so the raw bytes and our
		// Content-Length go out untouched. Bounded like wp_ob_end_flush_all():
		// a buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be
		// closed, so stop at the first one we cannot remove.
		$levels = ob_get_level();
		for ( $i = 0; $i < $levels; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-removable buffers emit a notice we intentionally tolerate.
			if ( ! @ob_end_clean() ) {
				break;
			}
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Cache-Control: ' . $cache_control );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'ETag: ' . $etag );
		header( 'X-Content-Type-Options: nosniff' );
		header_remove( 'X-Pingback' );

		/**
		 * Fires after the standard headers are sent and before the body.
		 *
		 * Use header() here to add X-Robots-Tag, CSP, etc.
		 *
		 * @param string $slug    Page slug.
		 * @param string $file    Absolute path being served.
		 * @param bool   $is_page Whether this is the page's index.html.
		 */
		do_action( 'htmlpp_serve_headers', $slug, $file, $is_page );

		if ( self::not_modified( $etag, $mtime ) ) {
			status_header( 304 );
			exit;
		}

		// Content-Length is wrong if PHP compresses the output itself.
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . $size );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method ) {
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		exit;
	}

	/**
	 * Evaluate conditional request headers.
	 *
	 * @param string $etag  Quoted ETag for the file.
	 * @param int    $mtime File modification time.
	 * @return bool True if the client's copy is current.
	 */
	private static function not_modified( $etag, $mtime ) {
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			$client = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );
			foreach ( explode( ',', $client ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( 0 === strpos( $candidate, 'W/' ) ) {
					$candidate = substr( $candidate, 2 );
				}
				// Apache mod_deflate / mod_brotli rewrite "<tag>" to "<tag>-gzip"
				// (or "<tag>"-gzip on older builds) on the way out.
				$candidate = preg_replace( '/-(gzip|br|deflate)("?)$/', '$2', $candidate );
				if ( '"' !== substr( $candidate, -1 ) ) {
					$candidate .= '"';
				}
				if ( '*' === $candidate || $candidate === $etag ) {
					return true;
				}
			}
			return false;
		}

		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );
			return false !== $since && $since >= $mtime;
		}

		return false;
	}
}

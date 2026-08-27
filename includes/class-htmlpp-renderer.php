<?php
/**
 * Intercepts incoming requests that match a page URL and serves the
 * uploaded HTML / asset file directly.
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
	const BLOCKED_EXTENSIONS = array( 'php', 'phtml', 'phtm', 'pht', 'phps', 'phar', 'inc', 'shtml', 'shtm', 'pl', 'py', 'sh', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd' );

	/**
	 * Whether an extension may never be served or stored (covers php3…php8
	 * and similar variants beyond the literal list).
	 *
	 * @param string $ext Extension without the dot.
	 * @return bool
	 */
	public static function is_blocked_extension( $ext ) {
		$ext = strtolower( (string) $ext );
		return in_array( $ext, self::BLOCKED_EXTENSIONS, true ) || (bool) preg_match( '/^ph(p\d*|t|tml?|ar|ps)$/', $ext );
	}

	/**
	 * Whether any dotted part of a filename after the first is a blocked
	 * extension ("shell.php.png" on AddHandler-style servers).
	 *
	 * @param string $basename File name.
	 * @return bool
	 */
	public static function has_blocked_extension_part( $basename ) {
		$parts = explode( '.', strtolower( (string) $basename ) );
		array_shift( $parts );
		foreach ( $parts as $part ) {
			if ( self::is_blocked_extension( $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hook into init at priority 0 — early enough to short-circuit WP's
	 * main query when we're going to serve a static file.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve' ), 0 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_subdomain_redirects' ) );
	}

	/**
	 * Let wp_safe_redirect() send visitors to the configured subdomain.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_subdomain_redirects( $hosts ) {
		$settings = HTMLPP_Settings::get_settings();
		if ( ! empty( $settings['subdomain'] ) ) {
			$hosts[] = (string) $settings['subdomain'];
		}
		return $hosts;
	}

	/**
	 * Entry point. If the current request matches a page URL, serve the
	 * corresponding file and exit. Otherwise return and let WordPress
	 * handle the request normally.
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

		$settings   = HTMLPP_Settings::get_settings();
		$home_paths = self::home_path_candidates();
		$match      = null;

		// Prefix URLs first, then custom paths (incl. the front page).
		foreach ( $home_paths as $home_path ) {
			$match = self::match( $host, $uri, $settings, $home_path );
			if ( null !== $match ) {
				break;
			}
		}
		if ( null === $match ) {
			foreach ( $home_paths as $home_path ) {
				$match = self::match_custom( $host, $uri, $settings, $home_path, HTMLPP_Meta::path_map() );
				if ( null !== $match ) {
					break;
				}
			}
		}
		if ( null === $match ) {
			$this->maybe_redirect_moved_path( $host, $uri, $settings, $home_paths );
			return;
		}

		// The front page must not swallow WordPress's own query-string
		// routes (/?s=, /?p=, /?rest_route=, /?feed=, /?wc-ajax=, …).
		if ( ! empty( $match['is_home'] ) && '' === $match['rel'] && self::home_query_conflicts( $match['query'] ) ) {
			return;
		}

		$slug     = $match['slug'];
		$page_dir = HTMLPP_Storage::page_dir( $slug );

		if ( ! is_dir( $page_dir ) ) {
			$this->maybe_redirect_renamed( $slug, $match );
			return;
		}

		$meta    = HTMLPP_Meta::get( $slug );
		$preview = false;
		if ( ! HTMLPP_Meta::is_public( $meta ) ) {
			$token = HTMLPP_Meta::preview_token( $slug );
			if ( ! self::can_preview( $slug, $token ) ) {
				return; // Drafts 404 for everyone else.
			}
			$preview = true;
			// A shared preview link only carries the token on the page URL;
			// remember it in a scoped cookie so the page's CSS/JS/images load.
			if ( '' === $match['rel'] && self::token_from_query( $token ) && ! headers_sent() ) {
				setcookie( self::preview_cookie_name( $slug ), $token, time() + HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			}
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
			if ( $preview || has_action( 'htmlpp_before_serve' ) ) {
				nocache_headers();
			}
			wp_safe_redirect( $target, 301, 'HTML Page Publisher' );
			exit;
		}

		if ( $is_page ) {
			self::stream_page( $file, $slug, $meta, $settings, $preview );
		}

		self::stream( $file, $ext, $slug, $preview, ! empty( $meta['noindex'] ) );
	}

	/**
	 * 301 a request for a page's previous custom path to its current URL.
	 *
	 * @param string   $host       Lowercased host.
	 * @param string   $uri        Raw REQUEST_URI.
	 * @param array    $settings   Plugin settings.
	 * @param string[] $home_paths Home-path candidates.
	 */
	private function maybe_redirect_moved_path( $host, $uri, array $settings, array $home_paths ) {
		if ( ! self::is_safe_method() ) {
			return;
		}
		$map = HTMLPP_Meta::path_redirects();
		if ( empty( $map ) ) {
			return;
		}
		foreach ( $home_paths as $home_path ) {
			$matched = self::match_custom( $host, $uri, $settings, $home_path, $map );
			if ( null === $matched || ! empty( $matched['is_home'] ) ) {
				continue;
			}
			$slug = $matched['slug'];
			if ( ! is_dir( HTMLPP_Storage::page_dir( $slug ) ) ) {
				return;
			}
			$target = HTMLPP_Storage::public_page_url( $slug );
			if ( '' !== $matched['rel'] ) {
				$target .= implode( '/', array_map( 'rawurlencode', explode( '/', $matched['rel'] ) ) );
			}
			if ( '' !== $matched['query'] ) {
				$target .= '?' . $matched['query'];
			}
			wp_safe_redirect( $target, 301, 'HTML Page Publisher' );
			exit;
		}
	}

	/**
	 * Whether a front-page request's query string belongs to WordPress
	 * (search, shortlinks, previews, REST, feeds, ajax endpoints …) rather
	 * than to the mapped page (utm_*, gclid, fbclid and the like).
	 *
	 * @param string $query Raw query string.
	 * @return bool
	 */
	public static function home_query_conflicts( $query ) {
		if ( '' === (string) $query ) {
			return false;
		}
		parse_str( (string) $query, $params );
		if ( empty( $params ) ) {
			return false;
		}

		$reserved = array(
			'rest_route',
			'wc-ajax',
			'action',
			'preview',
			'preview_id',
			'preview_nonce',
			'p',
			'page_id',
			'attachment_id',
			's',
			'feed',
			'customize_changeset_uuid',
			'customize_theme',
			'wp_customize',
			'customize_messenger_channel',
			'elementor-preview',
			'et_fb',
			'fl_builder',
			'brizy-edit',
			'brizy-edit-iframe',
			'vc_editable',
			'ct_builder',
			'bricks',
			'doing_wp_cron',
			'wp_lang',
			'sitemap',
			'sitemap-subtype',
			'robots',
			'favicon',
			'nocache',
		);
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
			if ( ! empty( $GLOBALS['wp']->public_query_vars ) ) {
				$reserved = array_merge( $reserved, (array) $GLOBALS['wp']->public_query_vars );
			}
			if ( ! empty( $GLOBALS['wp']->private_query_vars ) ) {
				$reserved = array_merge( $reserved, (array) $GLOBALS['wp']->private_query_vars );
			}
		}

		/**
		 * Filter the query variables that keep a request away from a page
		 * mapped to the front page.
		 *
		 * @param string[] $reserved Query variable names.
		 * @param array    $params   Parsed query parameters of the request.
		 */
		$reserved = (array) apply_filters( 'htmlpp_home_reserved_query_vars', $reserved, $params );

		foreach ( array_keys( $params ) as $key ) {
			if ( in_array( (string) $key, $reserved, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cookie that carries a draft's preview token to its asset requests.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private static function preview_cookie_name( $slug ) {
		return 'htmlpp_preview_' . md5( $slug );
	}

	/**
	 * Whether the request URL carries this exact preview token.
	 *
	 * @param string $token Expected token.
	 * @return bool
	 */
	private static function token_from_query( $token ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview token, compared in constant time.
		if ( ! isset( $_GET['htmlpp_preview'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview token.
		return hash_equals( $token, sanitize_text_field( wp_unslash( $_GET['htmlpp_preview'] ) ) );
	}

	/**
	 * 301 an old slug to its renamed page.
	 *
	 * @param string $slug    Requested (old) slug.
	 * @param array  $matched Match array.
	 */
	private function maybe_redirect_renamed( $slug, array $matched ) {
		if ( ! self::is_safe_method() ) {
			return;
		}
		$to = HTMLPP_Meta::get_redirect( $slug );
		if ( '' === $to || ! is_dir( HTMLPP_Storage::page_dir( $to ) ) ) {
			return;
		}
		$target = HTMLPP_Storage::public_page_url( $to );
		if ( '' !== $matched['rel'] ) {
			$target .= implode( '/', array_map( 'rawurlencode', explode( '/', $matched['rel'] ) ) );
		}
		if ( '' !== $matched['query'] ) {
			$target .= '?' . $matched['query'];
		}
		wp_safe_redirect( $target, 301, 'HTML Page Publisher' );
		exit;
	}

	/**
	 * Whether the current visitor may see a draft: a valid token on the URL
	 * or in the preview cookie, or an administrator session.
	 *
	 * @param string $slug  Page slug.
	 * @param string $token The page's current preview token.
	 * @return bool
	 */
	private static function can_preview( $slug, $token ) {
		if ( self::token_from_query( $token ) ) {
			return true;
		}
		$cookie = self::preview_cookie_name( $slug );
		if ( isset( $_COOKIE[ $cookie ] ) && hash_equals( $token, sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) ) ) {
			return true;
		}
		$allowed = is_user_logged_in() && current_user_can( 'manage_options' );

		/**
		 * Filter whether the current visitor may view a draft page.
		 *
		 * @param bool   $allowed Default: valid token or administrator.
		 * @param string $slug    Page slug.
		 */
		return (bool) apply_filters( 'htmlpp_can_preview', $allowed, $slug );
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
	 * Split a request into its decoded path, trailing-slash flag and query.
	 *
	 * @param string $uri Raw REQUEST_URI.
	 * @return array{path:string,trailing_slash:bool,query:string}|null
	 */
	private static function parse_request( $uri ) {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}
		$path = rawurldecode( $path );
		if ( false !== strpos( $path, "\0" ) ) {
			return null;
		}
		return array(
			'path'           => rtrim( $path, '/' ),
			'trailing_slash' => '/' === substr( $path, -1 ),
			'query'          => (string) wp_parse_url( $uri, PHP_URL_QUERY ),
		);
	}

	/**
	 * Strip the home path from a request path.
	 *
	 * @param string $path      Decoded request path without trailing slash.
	 * @param string $home_path '' or '/sub'.
	 * @return string|null Relative path (no leading/trailing slash) or null when outside the home path.
	 */
	private static function relative_to_home( $path, $home_path ) {
		if ( '' !== $home_path ) {
			if ( $path !== $home_path && 0 !== strpos( $path, $home_path . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $home_path ) );
		}
		return trim( (string) $path, '/' );
	}

	/**
	 * Reject empty segments, dot-segments and dotfiles.
	 *
	 * @param string[] $parts Path segments.
	 * @return bool
	 */
	private static function segments_ok( array $parts ) {
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part[0] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve a request to a page slug and a relative file path using the
	 * URL prefix (or the subdomain root).
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
		$request = self::parse_request( $uri );
		if ( null === $request ) {
			return null;
		}

		$subdomain = isset( $settings['subdomain'] ) ? (string) $settings['subdomain'] : '';
		$prefix    = isset( $settings['url_prefix'] ) ? trim( (string) $settings['url_prefix'], '/' ) : '';
		$on_sub    = self::on_subdomain( $host, $subdomain );

		if ( $on_sub ) {
			// On the configured subdomain the whole path is <slug>/<rest>.
			$match = trim( $request['path'], '/' );
		} else {
			if ( '' === $prefix ) {
				return null;
			}
			$rel_path = self::relative_to_home( $request['path'], $home_path );
			if ( null === $rel_path || 0 !== strpos( $rel_path, $prefix . '/' ) ) {
				return null;
			}
			$match = substr( $rel_path, strlen( $prefix ) + 1 );
		}

		if ( '' === $match ) {
			return null;
		}

		$parts = explode( '/', $match );
		if ( ! self::segments_ok( $parts ) ) {
			return null;
		}

		$slug = HTMLPP_Storage::sanitize_slug( array_shift( $parts ) );
		if ( '' === $slug ) {
			return null;
		}

		$canonical = $on_sub
			? '/' . rawurlencode( $slug ) . '/'
			: $home_path . '/' . $prefix . '/' . rawurlencode( $slug ) . '/';

		return array(
			'slug'           => $slug,
			'rel'            => implode( '/', $parts ),
			'path'           => $request['path'],
			'canonical'      => $canonical,
			'query'          => $request['query'],
			'trailing_slash' => $request['trailing_slash'],
		);
	}

	/**
	 * Resolve a request against custom page paths (including the front
	 * page, whose relative assets are matched as a last resort).
	 *
	 * @param string $host      Lowercased HTTP host.
	 * @param string $uri       Raw REQUEST_URI.
	 * @param array  $settings  Plugin settings.
	 * @param string $home_path '' or '/sub'.
	 * @param array  $path_map  Custom path => slug (see HTMLPP_Meta::path_map()).
	 * @return array|null Same shape as match().
	 */
	public static function match_custom( $host, $uri, $settings, $home_path, array $path_map ) {
		if ( empty( $path_map ) ) {
			return null;
		}
		$subdomain = isset( $settings['subdomain'] ) ? (string) $settings['subdomain'] : '';
		if ( self::on_subdomain( $host, $subdomain ) ) {
			return null; // Custom paths live on the main domain only.
		}

		$request = self::parse_request( $uri );
		if ( null === $request ) {
			return null;
		}
		$rel_path = self::relative_to_home( $request['path'], $home_path );
		if ( null === $rel_path ) {
			return null;
		}

		$parts = '' === $rel_path ? array() : explode( '/', $rel_path );
		if ( ! self::segments_ok( $parts ) ) {
			return null;
		}
		$lower = strtolower( $rel_path );

		$build = static function ( $slug, $rel, $canonical, $is_home = false ) use ( $request ) {
			return array(
				'slug'           => $slug,
				'rel'            => $rel,
				'path'           => $request['path'],
				'canonical'      => $canonical,
				'query'          => $request['query'],
				'trailing_slash' => $request['trailing_slash'],
				'is_home'        => $is_home,
			);
		};

		// Longest custom path first so "guides/spring" beats "guides".
		$paths = array_keys( $path_map );
		usort(
			$paths,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $paths as $path ) {
			if ( HTMLPP_Meta::HOME === $path ) {
				continue;
			}
			if ( $lower === $path ) {
				return $build( $path_map[ $path ], '', $home_path . '/' . $path . '/' );
			}
			if ( 0 === strpos( $lower, $path . '/' ) ) {
				return $build( $path_map[ $path ], substr( $rel_path, strlen( $path ) + 1 ), $home_path . '/' . $path . '/' );
			}
		}

		if ( isset( $path_map[ HTMLPP_Meta::HOME ] ) ) {
			// The front page: '' is the page itself; anything else is only
			// served if such a file exists in its folder (checked by the caller).
			return $build( $path_map[ HTMLPP_Meta::HOME ], $rel_path, $home_path . '/', true );
		}

		return null;
	}

	/**
	 * Whether the request host is the configured subdomain.
	 *
	 * @param string $host      Lowercased host (may include a port).
	 * @param string $subdomain Configured subdomain ('' if none).
	 * @return bool
	 */
	private static function on_subdomain( $host, $subdomain ) {
		if ( '' === $subdomain ) {
			return false;
		}
		$host_no_port = preg_replace( '/:\d+$/', '', (string) $host );
		return 0 === strcasecmp( $host_no_port, $subdomain );
	}

	/**
	 * Whether the request method allows a redirect without losing a body.
	 *
	 * @return bool
	 */
	private static function is_safe_method() {
		return in_array( self::method(), array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Uppercased request method.
	 *
	 * @return string
	 */
	private static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
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

	/*
	|--------------------------------------------------------------------------
	| Page decoration (snippets, noindex, canonical)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Inject markup into a page's <head> and before </body>.
	 *
	 * Pure string function (unit-tested). Head additions go just before
	 * the first </head>; without one, after the opening <head>; without
	 * that, before the first <body>; otherwise at the very start. Footer
	 * additions go before the last </body>, or at the end.
	 *
	 * @param string $html          Page HTML.
	 * @param string $head          Markup for the head ('' to skip).
	 * @param string $footer        Markup for the footer ('' to skip).
	 * @param string $canonical_url Canonical URL to add when the page has none ('' to skip).
	 * @param bool   $noindex       Add a robots noindex meta tag.
	 * @return string
	 */
	public static function decorate( $html, $head, $footer, $canonical_url = '', $noindex = false ) {
		$html = (string) $html;

		$extra = '';
		if ( $noindex ) {
			$extra .= "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
		}
		if ( '' !== $canonical_url && ! preg_match( '/<link[^>]+rel=["\']?canonical/i', $html ) ) {
			$extra .= '<link rel="canonical" href="' . esc_url( $canonical_url ) . "\">\n";
		}
		if ( '' !== trim( (string) $head ) ) {
			$extra .= trim( $head ) . "\n";
		}

		if ( '' !== $extra ) {
			$extra = "\n" . $extra;
			$pos   = self::find_tag_outside_scripts( $html, '</head>' );
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos ) . $extra . substr( $html, $pos );
			} elseif ( preg_match( '/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$at   = $m[0][1] + strlen( $m[0][0] );
				$html = substr( $html, 0, $at ) . $extra . substr( $html, $at );
			} elseif ( preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$html = substr( $html, 0, $m[0][1] ) . $extra . substr( $html, $m[0][1] );
			} else {
				// Keep a BOM and the doctype first so the page stays in standards mode.
				$at = 0;
				if ( 0 === strpos( $html, "\xEF\xBB\xBF" ) ) {
					$at = 3;
				}
				if ( preg_match( '/^\s*<!doctype[^>]*>/i', substr( $html, $at ), $m ) ) {
					$at += strlen( $m[0] );
				}
				$html = substr( $html, 0, $at ) . ( $at > 0 ? $extra : ltrim( $extra ) ) . substr( $html, $at );
			}
		}

		if ( '' !== trim( (string) $footer ) ) {
			$footer = "\n" . trim( $footer ) . "\n";
			$pos    = self::find_tag_outside_scripts( $html, '</body>', true );
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos ) . $footer . substr( $html, $pos );
			} else {
				$html .= $footer;
			}
		}

		return $html;
	}

	/**
	 * Position of a closing tag that is not inside a <script>, <style> or
	 * HTML comment (an inline script may legitimately contain "</head>").
	 *
	 * @param string $html HTML.
	 * @param string $tag  Tag to find, e.g. '</head>'.
	 * @param bool   $last Return the last occurrence instead of the first.
	 * @return int|false
	 */
	private static function find_tag_outside_scripts( $html, $tag, $last = false ) {
		$found = array();
		$depth = 0;
		$re    = '/<!--.*?-->|<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|' . preg_quote( $tag, '/' ) . '/is';
		if ( preg_match_all( $re, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $m ) {
				if ( 0 === strcasecmp( $m[0], $tag ) ) {
					$found[] = $m[1];
				}
			}
		}
		unset( $depth );
		if ( empty( $found ) ) {
			return false;
		}
		return $last ? end( $found ) : $found[0];
	}

	/**
	 * Apply the site's snippets and the page's SEO flags to its HTML.
	 *
	 * @param string $html     Page HTML.
	 * @param string $slug     Page slug.
	 * @param array  $meta     Page metadata.
	 * @param array  $settings Plugin settings.
	 * @param bool   $preview  Whether this is a draft preview.
	 * @return string
	 */
	public static function decorate_page( $html, $slug, array $meta, array $settings, $preview = false ) {
		$snippets = empty( $meta['no_snippets'] ) && ! $preview;
		$head     = $snippets && ! empty( $settings['head_snippet'] ) ? (string) $settings['head_snippet'] : '';
		$footer   = $snippets && ! empty( $settings['footer_snippet'] ) ? (string) $settings['footer_snippet'] : '';
		$noindex  = ! empty( $meta['noindex'] ) || $preview;
		$canon    = ! empty( $settings['canonical'] ) && ! $preview ? HTMLPP_Storage::public_page_url( $slug ) : '';

		$html = self::decorate( $html, $head, $footer, $canon, $noindex );

		/**
		 * Filter a page's HTML right before it is sent.
		 *
		 * @param string $html     Decorated HTML.
		 * @param string $slug     Page slug.
		 * @param array  $meta     Page metadata.
		 * @param bool   $preview  Whether this is a draft preview.
		 */
		return (string) apply_filters( 'htmlpp_page_html', $html, $slug, $meta, $preview );
	}

	/*
	|--------------------------------------------------------------------------
	| Streaming
	|--------------------------------------------------------------------------
	*/

	/**
	 * Cache-Control header value for a response.
	 *
	 * @param string $file    Absolute path.
	 * @param string $ext     Extension.
	 * @param bool   $is_page Whether this is the page HTML.
	 * @param string $slug    Page slug.
	 * @param bool   $no_store Force a non-cacheable response (drafts).
	 * @return string
	 */
	private static function cache_control( $file, $ext, $is_page, $slug, $no_store ) {
		if ( $no_store ) {
			return 'private, no-store';
		}

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
		return (string) apply_filters( 'htmlpp_cache_control', $cache_control, $file, $ext, $is_page, $slug );
	}

	/**
	 * Send the headers shared by page and asset responses and answer
	 * conditional requests. Returns only when a body should follow.
	 *
	 * @param string $mime          Content-Type.
	 * @param string $etag          Quoted ETag.
	 * @param int    $mtime         Last-modified timestamp.
	 * @param string $cache_control Cache-Control value.
	 * @param string $slug          Page slug.
	 * @param string $file          Absolute path.
	 * @param bool   $is_page       Whether this is the page HTML.
	 * @param bool   $noindex       Send X-Robots-Tag: noindex.
	 * @param bool   $no_store      Draft preview: add Referrer-Policy: no-referrer.
	 */
	private static function send_headers( $mime, $etag, $mtime, $cache_control, $slug, $file, $is_page, $noindex, $no_store = false ) {
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
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( $no_store ) {
			// Previews: keep the tokenised URL out of Referer headers.
			header( 'Referrer-Policy: no-referrer' );
		}

		/**
		 * Fires after the standard headers are sent and before the body.
		 *
		 * Use header() here to add CSP or other headers.
		 *
		 * @param string $slug    Page slug.
		 * @param string $file    Absolute path being served.
		 * @param bool   $is_page Whether this is the page's index.html.
		 */
		do_action( 'htmlpp_serve_headers', $slug, $file, $is_page );

		// Page HTML depends on settings and filters, not only the file's
		// mtime, so it is revalidated by ETag alone.
		if ( self::not_modified( $etag, $is_page ? 0 : $mtime ) ) {
			status_header( 304 );
			exit;
		}
	}

	/**
	 * Serve the page HTML with snippets and SEO flags applied.
	 *
	 * @param string $file     Absolute path to index.html.
	 * @param string $slug     Page slug.
	 * @param array  $meta     Page metadata.
	 * @param array  $settings Plugin settings.
	 * @param bool   $preview  Whether this is a draft preview.
	 */
	private static function stream_page( $file, $slug, array $meta, array $settings, $preview ) {
		$html = HTMLPP_Storage::get_contents( $file );
		if ( false === $html ) {
			return;
		}
		$html = self::decorate_page( $html, $slug, $meta, $settings, $preview );

		$mtime = (int) filemtime( $file );
		// The final output (snippets, filters, canonical) is what the client
		// caches, so hash exactly that.
		$etag = '"' . md5( $html . '|' . ( $preview ? 'p' : '' ) ) . '"';
		$mime = 'text/html; charset=UTF-8';
		$map  = self::mime_map();
		if ( isset( $map['html'] ) ) {
			$mime = $map['html'];
		}

		self::send_headers(
			$mime,
			$etag,
			$mtime,
			self::cache_control( $file, 'html', true, $slug, $preview ),
			$slug,
			$file,
			true,
			! empty( $meta['noindex'] ) || $preview,
			$preview
		);

		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . strlen( $html ) );
		}
		if ( 'HEAD' === self::method() ) {
			exit;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw page markup is the payload; only administrators can publish it.
		exit;
	}

	/**
	 * Stream an asset file to the client.
	 *
	 * @param string $file    Absolute path.
	 * @param string $ext     Lowercased extension.
	 * @param string $slug     Page slug.
	 * @param bool   $no_store Non-cacheable (assets of a draft).
	 * @param bool   $noindex  The page is noindex; its files inherit the header.
	 */
	private static function stream( $file, $ext, $slug = '', $no_store = false, $noindex = false ) {
		$map  = self::mime_map();
		$mime = isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';

		$size  = (int) filesize( $file );
		$mtime = (int) filemtime( $file );
		$etag  = '"' . md5( $file . '|' . $mtime . '|' . $size . '|' . HTMLPP_VERSION ) . '"';

		self::send_headers( $mime, $etag, $mtime, self::cache_control( $file, $ext, false, $slug, $no_store ), $slug, $file, false, $no_store || $noindex, $no_store );

		// Content-Length is wrong if PHP compresses the output itself.
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . $size );
		}
		if ( 'HEAD' === self::method() ) {
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

		if ( $mtime > 0 && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );
			return false !== $since && $since >= $mtime;
		}

		return false;
	}
}

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

class HTMLPP_Renderer {

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

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! $path ) {
			return;
		}
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return;
		}

		$settings = HTMLPP_Settings::get_settings();
		$match    = null;

		// Strip the port from host (e.g. "sales.example.com:8080" -> "sales.example.com").
		$host_no_port = preg_replace( '/:\d+$/', '', $host );

		if ( ! empty( $settings['subdomain'] ) && 0 === strcasecmp( $host_no_port, $settings['subdomain'] ) ) {
			// On configured subdomain, treat the full path as <slug>/<rest>.
			$match = $path;
		} else {
			// Main domain: require <prefix>/ at the start of the path.
			$prefix = $settings['url_prefix'];
			if ( '' !== $prefix ) {
				$needle = $prefix . '/';
				if ( 0 === strpos( $path, $needle ) ) {
					$match = substr( $path, strlen( $needle ) );
				}
			}
		}

		if ( null === $match || '' === $match ) {
			return;
		}

		$parts = explode( '/', $match );
		$slug  = HTMLPP_Storage::sanitize_slug( array_shift( $parts ) );
		if ( '' === $slug ) {
			return;
		}

		$page_dir = HTMLPP_Storage::page_dir( $slug );
		if ( ! is_dir( $page_dir ) ) {
			return;
		}

		$rel = implode( '/', $parts );
		if ( '' === $rel ) {
			$file = trailingslashit( $page_dir ) . 'index.html';
		} else {
			$candidate = trailingslashit( $page_dir ) . ltrim( $rel, '/' );
			$real_file = realpath( $candidate );
			$real_dir  = realpath( $page_dir );
			if ( ! $real_file || ! $real_dir || 0 !== strpos( $real_file, $real_dir ) ) {
				return;
			}
			$file = $real_file;
		}

		if ( ! file_exists( $file ) || ! is_file( $file ) ) {
			return;
		}

		// Refuse to serve anything with a dangerous extension.
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'php', 'phtml', 'phar', 'pl', 'py', 'sh', 'cgi' ), true ) ) {
			return;
		}

		self::stream( $file, $ext );
	}

	/**
	 * Stream the file to the client with an appropriate Content-Type.
	 *
	 * @param string $file Absolute path.
	 * @param string $ext  Lowercased extension.
	 */
	private static function stream( $file, $ext ) {
		$map = array(
			'html'  => 'text/html; charset=UTF-8',
			'htm'   => 'text/html; charset=UTF-8',
			'css'   => 'text/css; charset=UTF-8',
			'js'    => 'application/javascript; charset=UTF-8',
			'json'  => 'application/json; charset=UTF-8',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			'ico'   => 'image/x-icon',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'txt'   => 'text/plain; charset=UTF-8',
			'pdf'   => 'application/pdf',
		);
		$mime = isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		exit;
	}
}

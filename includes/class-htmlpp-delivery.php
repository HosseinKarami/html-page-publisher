<?php
/**
 * How a page's own files (CSS, JavaScript, images, fonts…) reach the browser.
 *
 * Normally every file is served by the renderer at the page's URL
 * (/pages/slug/assets/style.css). Some hosts, WordPress.com among them,
 * answer requests for static file types in the web server itself and return
 * their own 404 when no such file exists at that exact path, so the request
 * never reaches WordPress. On those hosts the page loads its files straight
 * from the uploads folder instead: a <base> tag is added when the page is
 * served, never written into the stored HTML.
 *
 * Nothing changes on a host unless a check proves it intercepts those
 * requests, or the site owner chooses it in Settings.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects hosts that intercept static file requests and adapts page output.
 */
class HTMLPP_Delivery {

	/**
	 * Option holding the result of the last check (autoloaded: read on every
	 * page view).
	 */
	const STATUS_OPTION = 'htmlpp_file_check';

	/**
	 * WP-Cron hook that runs the check in the background.
	 */
	const CRON_HOOK = 'htmlpp_check_file_delivery';

	/**
	 * Slug under the URL prefix that the renderer answers check requests on.
	 */
	const PROBE_SLUG = 'htmlpp-probe';

	/**
	 * Response header that proves a check request reached the renderer.
	 */
	const PROBE_HEADER = 'X-HTMLPP-Probe';

	/**
	 * Settings choices.
	 */
	const AUTO     = 'auto';
	const PAGE_URL = 'page';
	const UPLOADS  = 'uploads';

	/**
	 * Files requested by the check. The extensionless control must reach the
	 * renderer for the result to mean anything; the others are file types
	 * that intercepting hosts answer themselves.
	 *
	 * @return string[]
	 */
	public static function probe_files() {
		return array( 'probe', 'probe.css', 'probe.js' );
	}

	/**
	 * Register the background check.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
	}

	/**
	 * Queue a background check (no-op if one is already queued).
	 */
	public static function schedule_check() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Whether the stored result is missing or older than a week.
	 *
	 * @return bool
	 */
	public static function check_is_stale() {
		$record = self::status();
		return empty( $record['checked'] ) || ( time() - (int) $record['checked'] ) > 7 * DAY_IN_SECONDS;
	}

	/*
	|--------------------------------------------------------------------------
	| Check
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether a matched request is one of the check's requests.
	 *
	 * @param string $slug Matched slug.
	 * @param string $rel  Matched path inside the page.
	 * @return bool
	 */
	public static function is_probe( $slug, $rel ) {
		return self::PROBE_SLUG === $slug && in_array( $rel, self::probe_files(), true );
	}

	/**
	 * Answer a check request and exit.
	 *
	 * @param string $rel One of probe_files().
	 */
	public static function serve_probe( $rel ) {
		$ext  = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );
		$mime = 'js' === $ext ? 'application/javascript' : ( 'css' === $ext ? 'text/css' : 'text/plain' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $mime . '; charset=UTF-8' );
		header( self::PROBE_HEADER . ': 1' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '/* HTML Page Publisher file check */';
		exit;
	}

	/**
	 * Run the check now and store the result.
	 *
	 * Requests the check files under the URL prefix, and confirms the uploads
	 * folder serves files (it has to, for pages to load from it).
	 *
	 * @return array{status:string,storage:string,checked:int,results:array}
	 */
	public static function run_check() {
		$results = array();
		foreach ( self::probe_files() as $file ) {
			$results[ $file ] = self::probe( self::probe_url( $file ) );
		}

		$storage = HTMLPP_Storage::direct_access_status( true );

		$record = array(
			'status'  => self::classify( $results ),
			'storage' => isset( $storage['status'] ) ? (string) $storage['status'] : 'unknown',
			'checked' => time(),
			'results' => $results,
		);
		update_option( self::STATUS_OPTION, $record, true );

		return $record;
	}

	/**
	 * URL of one check file, with a throwaway query string so no cache
	 * answers it.
	 *
	 * @param string $file One of probe_files().
	 * @return string
	 */
	public static function probe_url( $file ) {
		$settings = HTMLPP_Settings::get_settings();
		$prefix   = trim( (string) $settings['url_prefix'], '/' );
		$url      = home_url( '/' . $prefix . '/' . self::PROBE_SLUG . '/' . $file );
		return add_query_arg( 'htmlpp_check', wp_generate_password( 10, false ), $url );
	}

	/**
	 * Request one check file.
	 *
	 * @param string $url Check URL.
	 * @return array{code:int,marker:bool}
	 */
	private static function probe( $url ) {
		$args = array(
			'timeout'     => 5,
			'redirection' => 3,
			'sslverify'   => false,
			'user-agent'  => 'HTML Page Publisher/' . HTMLPP_VERSION . ' file check',
		);

		$response = wp_remote_get( $url, $args );
		// HTTPS loopback often fails on self-signed or SNI-bound certificates;
		// the web server routes plain HTTP the same way.
		if ( is_wp_error( $response ) && 0 === strpos( $url, 'https://' ) ) {
			$response = wp_remote_get( 'http://' . substr( $url, 8 ), $args );
		}
		if ( is_wp_error( $response ) ) {
			return array(
				'code'   => 0,
				'marker' => false,
			);
		}

		return array(
			'code'   => (int) wp_remote_retrieve_response_code( $response ),
			'marker' => '' !== (string) wp_remote_retrieve_header( $response, self::PROBE_HEADER ),
		);
	}

	/**
	 * Interpret the check's responses.
	 *
	 * 'intercepted' only when the extensionless control reached the plugin
	 * and a CSS or JS request was refused without ever reaching it. Anything
	 * inconclusive (blocked loopback, errors, a security plugin, maintenance
	 * mode) is 'unknown', which changes nothing.
	 *
	 * @param array $results File => array{code:int,marker:bool}.
	 * @return string 'normal', 'intercepted' or 'unknown'.
	 */
	public static function classify( array $results ) {
		$control = isset( $results['probe'] ) ? $results['probe'] : null;
		if ( ! is_array( $control ) || empty( $control['marker'] ) ) {
			return 'unknown';
		}

		$tested = 0;
		foreach ( $results as $file => $result ) {
			if ( 'probe' === $file ) {
				continue;
			}
			++$tested;
			if ( ! empty( $result['marker'] ) ) {
				continue;
			}
			$code = isset( $result['code'] ) ? (int) $result['code'] : 0;
			if ( 403 === $code || 404 === $code ) {
				return 'intercepted';
			}
			return 'unknown';
		}

		return $tested > 0 ? 'normal' : 'unknown';
	}

	/**
	 * Stored result of the last check.
	 *
	 * @return array{status:string,storage:string,checked:int}
	 */
	public static function status() {
		$record = get_option( self::STATUS_OPTION, array() );
		$record = is_array( $record ) ? $record : array();
		return array(
			'status'  => isset( $record['status'] ) ? (string) $record['status'] : '',
			'storage' => isset( $record['storage'] ) ? (string) $record['storage'] : '',
			'checked' => isset( $record['checked'] ) ? (int) $record['checked'] : 0,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Mode
	|--------------------------------------------------------------------------
	*/

	/**
	 * The site owner's choice in Settings.
	 *
	 * @return string self::AUTO, self::PAGE_URL or self::UPLOADS.
	 */
	public static function choice() {
		$settings = HTMLPP_Settings::get_settings();
		$choice   = isset( $settings['file_delivery'] ) ? (string) $settings['file_delivery'] : self::AUTO;
		return in_array( $choice, array( self::AUTO, self::PAGE_URL, self::UPLOADS ), true ) ? $choice : self::AUTO;
	}

	/**
	 * Resolve how page files are served from a choice and a check result.
	 *
	 * Pure function. Automatic switches to the uploads folder only when the
	 * check proved the host intercepts file requests and the uploads folder
	 * serves files.
	 *
	 * @param string $choice  self::AUTO, self::PAGE_URL or self::UPLOADS.
	 * @param string $status  Check status ('normal', 'intercepted', 'unknown' or '').
	 * @param string $storage Uploads-folder status from HTMLPP_Storage::direct_access_status().
	 * @return string self::PAGE_URL or self::UPLOADS.
	 */
	public static function resolve_mode( $choice, $status, $storage ) {
		if ( self::UPLOADS === $choice ) {
			return self::UPLOADS;
		}
		if ( self::PAGE_URL === $choice ) {
			return self::PAGE_URL;
		}
		return ( 'intercepted' === $status && 'open' === $storage ) ? self::UPLOADS : self::PAGE_URL;
	}

	/**
	 * How page files are served on this site.
	 *
	 * @return string self::PAGE_URL or self::UPLOADS.
	 */
	public static function mode() {
		$choice = self::choice();
		$record = self::status();
		$mode   = self::resolve_mode( $choice, $record['status'], $record['storage'] );

		/**
		 * Filter how a page's own files reach the browser.
		 *
		 * 'page' serves them at the page's URL through the plugin (the
		 * default). 'uploads' makes pages load them straight from the uploads
		 * folder, for hosts whose web server answers requests for static file
		 * types itself.
		 *
		 * @param string $mode   'page' or 'uploads'.
		 * @param string $choice The Settings choice: 'auto', 'page' or 'uploads'.
		 * @param array  $record Last check result (status, storage, checked).
		 */
		$mode = apply_filters( 'htmlpp_file_delivery', $mode, $choice, $record );

		return self::UPLOADS === $mode ? self::UPLOADS : self::PAGE_URL;
	}

	/**
	 * Uploads-folder URL of a page's directory (with a trailing slash).
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function files_url( $slug ) {
		return trailingslashit( HTMLPP_Storage::base_url() ) . rawurlencode( $slug ) . '/';
	}

	/**
	 * Base URL a page served on this request should load its files from, or
	 * '' to leave the page as it is.
	 *
	 * @param string $slug Page slug.
	 * @param string $host Lowercased request host (may include a port).
	 * @return string
	 */
	public static function files_base_for_request( $slug, $host ) {
		if ( self::UPLOADS !== self::mode() ) {
			return '';
		}
		if ( ! self::page_has_files( HTMLPP_Storage::page_dir( $slug ) ) ) {
			return '';
		}

		$url = self::files_url( $slug );
		// A different origin (a subdomain, or uploads on a CDN) would need
		// CORS for fonts, modules and fetch(); leave those pages alone.
		if ( ! self::same_host( $url, $host ) ) {
			return '';
		}

		return (string) preg_replace( '#^https?://#i', ( is_ssl() ? 'https' : 'http' ) . '://', $url );
	}

	/**
	 * Whether a page directory holds anything besides its index.html.
	 *
	 * @param string $dir Absolute page directory.
	 * @return bool
	 */
	public static function page_has_files( $dir ) {
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( ! is_array( $entries ) ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] || 0 === strcasecmp( $entry, 'index.html' ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Whether a URL is on the request's host (default ports ignored).
	 *
	 * @param string $url  Absolute URL.
	 * @param string $host Request host, optionally with a port.
	 * @return bool
	 */
	public static function same_host( $url, $host ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) || '' === (string) $host ) {
			return false;
		}
		$url_host = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$strip    = '/:(?:80|443)$/';
		return preg_replace( $strip, '', $url_host ) === preg_replace( $strip, '', strtolower( (string) $host ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Page output (pure string functions, unit-tested)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Make a page load its files from the uploads folder.
	 *
	 * Adds a <base> tag first thing in the head (it has to come before any
	 * relative reference), plus a small script that keeps in-page links
	 * (#section) on the page and sends links to the bundle's other HTML files
	 * to their page URL. A page that sets its own <base> keeps it. Keynote
	 * exports also get a fix for their PDF reader.
	 *
	 * @param string $html       Page HTML.
	 * @param string $files_base Uploads-folder URL of the page directory.
	 * @param string $page_base  The page's own URL or path, for links.
	 * @return string
	 */
	public static function apply( $html, $files_base, $page_base ) {
		$html = (string) $html;
		if ( '' === (string) $files_base ) {
			return $html;
		}

		if ( ! preg_match( '/<base\b[^>]*\bhref\s*=/i', $html ) ) {
			$markup = '<base href="' . esc_url( $files_base ) . '">' . "\n";
			if ( '' !== (string) $page_base ) {
				$markup .= '<script>' . self::link_script( $files_base, $page_base ) . '</script>' . "\n";
			}
			$html = self::insert_at_head_start( $html, $markup );
		}

		return self::add_keynote_fix( $html );
	}

	/**
	 * Whether the HTML is an Apple Keynote HTML export.
	 *
	 * @param string $html Page HTML.
	 * @return bool
	 */
	public static function is_keynote_export( $html ) {
		return (bool) preg_match( self::keynote_pdfjs_pattern(), (string) $html )
			&& (bool) preg_match( '/<script\b[^>]*\bsrc\s*=\s*["\']?(?:\.\/)?assets\/player\/main\.js/i', (string) $html );
	}

	/**
	 * Insert the PDF reader fix right after a Keynote export's pdf.js tag.
	 *
	 * Keynote's player draws slides from PDFs with a bundled pdf.js, which
	 * resolves the PDF's address against the address bar instead of the
	 * document's base URL, so <base> alone does not reach the slides. The
	 * fix resolves relative addresses against document.baseURI; without a
	 * <base> tag that is the page address, so it changes nothing.
	 *
	 * @param string $html Page HTML.
	 * @return string HTML, unchanged unless it is a Keynote export.
	 */
	public static function add_keynote_fix( $html ) {
		$html = (string) $html;
		if ( ! self::is_keynote_export( $html ) ) {
			return $html;
		}
		if ( ! preg_match( self::keynote_pdfjs_pattern(), $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$at = $m[0][1] + strlen( $m[0][0] );
		return substr( $html, 0, $at ) . "\n<script>" . self::pdfjs_script() . '</script>' . substr( $html, $at );
	}

	/**
	 * Regex for a Keynote export's pdf.js script tag.
	 *
	 * @return string
	 */
	private static function keynote_pdfjs_pattern() {
		return '/<script\b[^>]*\bsrc\s*=\s*["\']?(?:\.\/)?assets\/player\/pdfjs\/pdf\.js["\']?[^>]*>\s*<\/script>/i';
	}

	/**
	 * Script that resolves pdf.js document addresses against the base URL.
	 *
	 * @return string
	 */
	public static function pdfjs_script() {
		return '(function(){'
			. 'var p=window.PDFJS;'
			. 'if(!p||typeof p.getDocument!=="function"||p.htmlppBaseFix){return;}'
			. 'var g=p.getDocument;'
			. 'p.getDocument=function(s){'
			. 'var a=Array.prototype.slice.call(arguments);'
			. 'if(typeof s==="string"){try{a[0]=new URL(s,document.baseURI).href;}catch(e){}}'
			. 'return g.apply(this,a);'
			. '};'
			. 'p.htmlppBaseFix=true;'
			. '})();';
	}

	/**
	 * Script that keeps links working once <base> points at the uploads
	 * folder: "#section" scrolls this page, and links to the bundle's own
	 * HTML files (or its folder) open at the page URL rather than the raw
	 * file. Runs last (on window) and steps aside if the page handled the
	 * click itself.
	 *
	 * @param string $files_base Uploads-folder URL of the page directory.
	 * @param string $page_base  The page's own URL or path.
	 * @return string
	 */
	public static function link_script( $files_base, $page_base ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		return '(function(f,p){'
			. 'f=new URL(f,location.href).href;p=new URL(p,location.href).href;'
			. 'window.addEventListener("click",function(e){'
			. 'if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey){return;}'
			. 'var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;'
			. 'if(!a||typeof a.href!=="string"||a.hasAttribute("download")||(a.target&&a.target!=="_self")){return;}'
			. 'var h=a.getAttribute("href")||"";'
			. 'if(h.charAt(0)==="#"){e.preventDefault();location.hash=h;return;}'
			. 'if(a.href.indexOf(f)!==0){return;}'
			. 'var r=a.href.slice(f.length),x=r.split(/[?#]/)[0];'
			. 'if(x!==""&&!/(^|\/)$|\.html?$/i.test(x)){return;}'
			. 'e.preventDefault();'
			. 'location.href=p+r.replace(/^index\.html?(?=$|[?#])/i,"");'
			. '});'
			. '})(' . wp_json_encode( (string) $files_base, $flags ) . ',' . wp_json_encode( (string) $page_base, $flags ) . ');';
	}

	/**
	 * Insert markup right after the opening <head> (or <html>, or the
	 * doctype), so it precedes every relative reference in the page.
	 *
	 * @param string $html   Page HTML.
	 * @param string $markup Markup to insert.
	 * @return string
	 */
	private static function insert_at_head_start( $html, $markup ) {
		foreach ( array( '/<head\b[^>]*>/i', '/<html\b[^>]*>/i' ) as $pattern ) {
			if ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1] + strlen( $m[0][0] );
				return substr( $html, 0, $at ) . "\n" . $markup . substr( $html, $at );
			}
		}

		// Keep a BOM and the doctype first so the page stays in standards mode.
		$at = 0;
		if ( 0 === strpos( $html, "\xEF\xBB\xBF" ) ) {
			$at = 3;
		}
		if ( preg_match( '/^\s*<!doctype[^>]*>/i', substr( $html, $at ), $m ) ) {
			$at += strlen( $m[0] );
		}
		return substr( $html, 0, $at ) . ( $at > 0 ? "\n" : '' ) . $markup . substr( $html, $at );
	}
}

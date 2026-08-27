<?php
/**
 * Minimal, WordPress-free bootstrap for unit tests.
 *
 * Only the handful of WordPress functions the pure-logic classes call are
 * stubbed here, so the sanitizer and the request router can be tested in
 * milliseconds without a database or a WordPress install.
 *
 * @package HTMLPP
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HTMLPP_VERSION', 'test' );
define( 'HTMLPP_STORAGE_DIRNAME', 'html-page-publisher' );
define( 'HTMLPP_BACKUPS_DIRNAME', 'html-page-publisher-backups' );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Registered test filters: hook => callable[].
 *
 * @var array
 */
$GLOBALS['htmlpp_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['htmlpp_test_filters'][ $hook ][] = $callback;
	return true;
}

function remove_all_test_filters() {
	$GLOBALS['htmlpp_test_filters'] = array();
}

function apply_filters( $hook, $value ) {
	$args = func_get_args();
	array_shift( $args );
	if ( empty( $GLOBALS['htmlpp_test_filters'][ $hook ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['htmlpp_test_filters'][ $hook ] as $callback ) {
		$args[0] = call_user_func_array( $callback, $args );
	}
	return $args[0];
}

function do_action( $hook ) {}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function home_url( $path = '' ) {
	return rtrim( $GLOBALS['htmlpp_test_home'] ?? 'https://example.com', '/' ) . $path;
}

function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir() . '/htmlpp-tests/uploads',
		'baseurl' => home_url( '/wp-content/uploads' ),
	);
}

/**
 * ASCII-path port of WordPress core's sanitize_title_with_dashes() so the
 * router is tested with the same slug rules production uses (dots become
 * dashes, stray '%' is dropped, escaped octets survive).
 *
 * @param string $title Raw slug.
 * @return string
 */
function sanitize_title( $title ) {
	$title = strip_tags( (string) $title );
	$title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
	$title = str_replace( '%', '', $title );
	$title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );
	$title = strtolower( $title );
	$title = preg_replace( '/&.+?;/', '', $title );
	$title = str_replace( '.', '-', $title );
	$title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = preg_replace( '/\s+/', '-', $title );
	$title = preg_replace( '|-+|', '-', $title );
	return trim( $title, '-' );
}

function get_option( $name, $default_value = false ) {
	return $GLOBALS['htmlpp_test_options'][ $name ] ?? $default_value;
}

function has_action( $hook ) {
	return ! empty( $GLOBALS['htmlpp_test_filters'][ $hook ] );
}

function wp_strip_all_tags( $string ) {
	return trim( strip_tags( (string) $string ) );
}

function is_ssl() {
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-htmlpp-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-sanitizer.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-renderer.php';

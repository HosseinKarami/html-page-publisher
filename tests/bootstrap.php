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

define( 'MB_IN_BYTES', 1048576 );

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['htmlpp_test_options'][ $name ] = $value;
	return true;
}

function wp_generate_password( $length = 12, $special = true ) {
	return substr( str_repeat( 'abc123', 10 ), 0, $length );
}

function add_query_arg( $key, $value = '', $url = '' ) {
	if ( is_array( $key ) ) {
		$url = $value;
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $key );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function size_format( $bytes ) {
	return round( $bytes / MB_IN_BYTES ) . ' MB';
}

function __( $text, $domain = null ) { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	return $text;
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
}

function wp_delete_file( $file ) {
	@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function get_post_types( $args = array(), $output = 'names' ) {
	return array();
}

function wp_json_encode( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_current_user_id() {
	return 7;
}

function url_to_postid( $url ) {
	return isset( $GLOBALS['htmlpp_test_post_urls'][ $url ] ) ? $GLOBALS['htmlpp_test_post_urls'][ $url ] : 0;
}

function get_the_title( $post_id ) {
	return 'Post ' . $post_id;
}

function admin_url( $path = '' ) {
	return home_url( '/wp-admin/' . ltrim( $path, '/' ) );
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}

function wp_unique_filename( $dir, $filename ) {
	$name = $filename;
	$i    = 1;
	while ( file_exists( rtrim( $dir, '/' ) . '/' . $name ) ) {
		$name = preg_replace( '/(\.[^.]+)$/', '-' . $i . '$1', $filename );
		++$i;
	}
	return $name;
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $name );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_upload_dir_base() {
	return sys_get_temp_dir() . '/htmlpp-tests/uploads';
}

function htmlpp() {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin        = new stdClass();
		$plugin->pages = new HTMLPP_Page_Service();
	}
	return $plugin;
}

define( 'HTMLPP_PLUGIN_FILE', dirname( __DIR__ ) . '/html-page-publisher.php' );

require_once dirname( __DIR__ ) . '/includes/class-htmlpp-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-sanitizer.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-zip.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-uploader.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-htmlpp-page-service.php';

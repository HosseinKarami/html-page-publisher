<?php
/**
 * Admin menus and page renderers.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTMLPP_Admin {

	/**
	 * Wire admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin CSS/JS — only on this plugin's pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'html-page-publisher' ) ) {
			return;
		}

		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );

		wp_enqueue_style(
			'htmlpp-admin',
			HTMLPP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			HTMLPP_VERSION
		);

		wp_enqueue_script(
			'htmlpp-admin',
			HTMLPP_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			HTMLPP_VERSION,
			true
		);
	}

	/**
	 * Add a scoping class to <body> on plugin pages so our CSS can safely
	 * apply overflow / layout rules without affecting other admin screens.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function add_body_class( $classes ) {
		return $classes . ' htmlpp-active';
	}

	/**
	 * Register the admin menu + submenus.
	 */
	public function menu() {
		add_menu_page(
			__( 'HTML Page Publisher', 'html-page-publisher' ),
			__( 'HTML Pages', 'html-page-publisher' ),
			'manage_options',
			'html-page-publisher',
			array( $this, 'render_upload_page' ),
			'dashicons-media-document',
			30
		);

		add_submenu_page(
			'html-page-publisher',
			__( 'HTML Pages', 'html-page-publisher' ),
			__( 'All Pages', 'html-page-publisher' ),
			'manage_options',
			'html-page-publisher',
			array( $this, 'render_upload_page' )
		);

		add_submenu_page(
			'html-page-publisher',
			__( 'HTML Page Publisher — Settings', 'html-page-publisher' ),
			__( 'Settings', 'html-page-publisher' ),
			'manage_options',
			'html-page-publisher-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the upload / list page.
	 */
	public function render_upload_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'html-page-publisher' ) );
		}

		$notice = HTMLPP_Uploader::handle_request();
		$pages  = HTMLPP_Storage::list_pages();

		include HTMLPP_PLUGIN_DIR . 'views/admin-upload.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'html-page-publisher' ) );
		}

		include HTMLPP_PLUGIN_DIR . 'views/admin-settings.php';
	}
}

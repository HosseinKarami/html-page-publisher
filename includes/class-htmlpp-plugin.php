<?php
/**
 * Main plugin bootstrap (singleton).
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HTMLPP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var HTMLPP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return HTMLPP_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks and instantiate subsystems.
	 */
	private function __construct() {
		// Renderer runs on all requests so it can intercept page serving.
		new HTMLPP_Renderer();

		if ( is_admin() ) {
			new HTMLPP_Settings();
			new HTMLPP_Admin();
		}
	}

	/**
	 * Activation: create storage directory and seed default settings.
	 */
	public static function activate() {
		HTMLPP_Storage::ensure_dir();

		if ( false === get_option( 'htmlpp_settings' ) ) {
			add_option(
				'htmlpp_settings',
				array(
					'url_prefix' => 'pages',
					'subdomain'  => '',
				)
			);
		}
	}

	/**
	 * Deactivation: intentionally a no-op. User data is preserved.
	 */
	public static function deactivate() {
		// No destructive cleanup on deactivation. See uninstall.php.
	}
}

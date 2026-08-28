<?php
/**
 * Main plugin bootstrap (singleton).
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap: wires subsystems, runs upgrades, exposes the singleton.
 */
final class HTMLPP_Plugin {

	/**
	 * Option that records the last plugin version whose upgrade routine ran.
	 */
	const VERSION_OPTION = 'htmlpp_version';

	/**
	 * Last release that did not record its version. An in-place update from
	 * it (or any earlier release) reports this as the "from" version.
	 */
	const LEGACY_VERSION = '1.2.1';

	/**
	 * Singleton instance.
	 *
	 * @var HTMLPP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Page service — the API add-ons, REST and WP-CLI use.
	 *
	 * @var HTMLPP_Page_Service
	 */
	public $pages;

	/**
	 * Renderer instance.
	 *
	 * @var HTMLPP_Renderer
	 */
	public $renderer;

	/**
	 * REST controller.
	 *
	 * @var HTMLPP_REST
	 */
	public $rest;

	/**
	 * Settings instance (admin only).
	 *
	 * @var HTMLPP_Settings|null
	 */
	public $settings = null;

	/**
	 * Admin instance (admin only).
	 *
	 * @var HTMLPP_Admin|null
	 */
	public $admin = null;

	/**
	 * Get the shared instance, booting the plugin on first call.
	 *
	 * @return HTMLPP_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();

			/**
			 * Fires once the plugin has finished bootstrapping.
			 *
			 * Add-ons should hook here (rather than `plugins_loaded`) to be
			 * sure every HTMLPP_* class is loaded and `htmlpp()` is usable.
			 * It fires before the upgrade routine, so listeners registered
			 * here also see `htmlpp_upgraded` and `htmlpp_htaccess_rules`.
			 *
			 * @param HTMLPP_Plugin $plugin The plugin instance.
			 */
			do_action( 'htmlpp_loaded', self::$instance );

			self::$instance->maybe_upgrade();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks and instantiate subsystems.
	 */
	private function __construct() {
		$this->pages = new HTMLPP_Page_Service();

		// Renderer runs on all requests so it can intercept page serving.
		$this->renderer = new HTMLPP_Renderer();
		$this->rest     = new HTMLPP_REST();
		new HTMLPP_Sitemap();

		if ( is_admin() ) {
			$this->settings = new HTMLPP_Settings();
			$this->admin    = new HTMLPP_Admin();
		}
	}

	/**
	 * Run one-time upgrade steps when the stored version differs from the
	 * running version. Cheap enough to check on every load (one option read).
	 *
	 * Because activate() records the version on a fresh install, an empty
	 * stored value means an in-place update from a release older than 1.3.0.
	 */
	private function maybe_upgrade() {
		$stored = (string) get_option( self::VERSION_OPTION, '' );
		if ( HTMLPP_VERSION === $stored ) {
			return;
		}

		$from = '' === $stored ? self::LEGACY_VERSION : $stored;

		// 1.3.0: storage directories gained .htaccess deny rules so pages are
		// only ever served through the renderer. Re-run on every upgrade so a
		// missing protection file is restored.
		HTMLPP_Storage::ensure_dir();

		if ( false === get_option( 'htmlpp_installed_at' ) ) {
			add_option( 'htmlpp_installed_at', time() );
		}

		// Pre-1.4.0 installs never stored a canonical preference. Only opt them
		// in where the canonical can point at the host their pages already
		// serve from: a subdomain may have been saved without the DNS and
		// vhost work ever being finished, and pointing a live page's canonical
		// at a host that does not resolve is a de-indexing instruction.
		if ( '' === $stored ) {
			$settings = get_option( HTMLPP_Settings::OPTION, array() );
			if ( is_array( $settings ) && ! array_key_exists( 'canonical', $settings ) ) {
				$settings['canonical'] = empty( $settings['subdomain'] );
				update_option( HTMLPP_Settings::OPTION, $settings );
			}
		}

		update_option( self::VERSION_OPTION, HTMLPP_VERSION );

		/**
		 * Fires after the upgrade routine has run.
		 *
		 * @param string $from Previously stored version ('1.2.1' for any pre-1.3.0 install).
		 * @param string $to   Current plugin version.
		 */
		do_action( 'htmlpp_upgraded', $from, HTMLPP_VERSION );
	}

	/**
	 * Capability required to reach the plugin's screens and API.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required to manage HTML pages.
		 *
		 * @param string $capability Defaults to 'manage_options'.
		 */
		return (string) apply_filters( 'htmlpp_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may write raw page markup.
	 *
	 * A published page is unfiltered HTML — it can carry <script> — so writing
	 * one is the same trust level WordPress calls `unfiltered_html`. On
	 * multisite that is a super-admin capability, so a site administrator has
	 * `manage_options` without it; requiring both keeps the plugin inside
	 * WordPress's own boundary instead of widening it.
	 *
	 * @return bool
	 */
	public static function can_write_markup() {
		/**
		 * Filter whether writing page markup additionally requires
		 * `unfiltered_html`. Return false on a hardened single-site install
		 * that sets DISALLOW_UNFILTERED_HTML but still publishes pages here.
		 *
		 * @param bool $require Defaults to true.
		 */
		$require = (bool) apply_filters( 'htmlpp_require_unfiltered_html', true );

		return current_user_can( self::capability() )
			&& ( ! $require || current_user_can( 'unfiltered_html' ) );
	}

	/**
	 * Message shown when a user may manage pages but not write markup.
	 *
	 * @return string
	 */
	public static function markup_denied_message() {
		return __( 'Your account cannot publish raw HTML on this site: that needs the unfiltered_html capability, which on a multisite network only network administrators have. Ask a network administrator to publish the page, or to grant the capability.', 'html-page-publisher' );
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

		if ( false === get_option( 'htmlpp_installed_at' ) ) {
			add_option( 'htmlpp_installed_at', time() );
		}

		// Fresh install: nothing to migrate, so the upgrade routine is a no-op.
		if ( false === get_option( self::VERSION_OPTION ) ) {
			add_option( self::VERSION_OPTION, HTMLPP_VERSION );
		}
	}

	/**
	 * Deactivation: intentionally a no-op. User data is preserved.
	 */
	public static function deactivate() {
		// No destructive cleanup on deactivation. See uninstall.php.
	}
}

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
	 * Author website URL (linked from the admin footer attribution).
	 */
	const AUTHOR_URL = 'https://hosseinkarami.com/';

	/**
	 * Support / contact form URL used throughout the plugin.
	 */
	const SUPPORT_URL = 'https://hosseinkarami.com/contact';

	/**
	 * Wire admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'current_screen', array( $this, 'maybe_filter_footer' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( HTMLPP_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Build a Buy Me a Coffee donate URL with location-specific UTM tracking.
	 *
	 * UTM scheme follows Google Analytics conventions:
	 *   - source:   the specific plugin (which plugin sent the traffic)
	 *   - medium:   "plugin" (groups all plugin-driven traffic together)
	 *   - campaign: "donate" (the goal of the click)
	 *   - content:  placement (admin-header-donate / admin-footer-donate / plugin-row-donate)
	 *
	 * @param string $content utm_content tag identifying click source.
	 * @return string
	 */
	public static function donate_url( $content = 'admin' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'html-page-publisher',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'donate',
				'utm_content'  => $content,
			),
			'https://buymeacoffee.com/hosseinkarami'
		);
	}

	/**
	 * Build a support / contact URL with location-specific UTM tracking.
	 *
	 * @param string $content utm_content tag identifying click source.
	 * @return string
	 */
	public static function support_url( $content = 'admin' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'html-page-publisher',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'support',
				'utm_content'  => $content,
			),
			self::SUPPORT_URL
		);
	}

	/**
	 * On plugin admin screens: replace WP's default admin footer
	 * and register contextual help tabs (Overview / FAQ / Support).
	 */
	public function maybe_filter_footer() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'html-page-publisher' ) ) {
			return;
		}
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
		add_filter( 'update_footer', array( $this, 'footer_version' ), 11 );
		$this->register_help_tabs( $screen );
	}

	/**
	 * Register WP help tabs (the slide-down panel under the screen title).
	 *
	 * @param WP_Screen $screen Current admin screen.
	 */
	private function register_help_tabs( $screen ) {
		$base_url    = HTMLPP_Storage::public_page_url( 'your-slug' );
		$storage     = ltrim( wp_make_link_relative( HTMLPP_Storage::base_url() ), '/' );
		$donate_url  = self::donate_url( 'help-tab-donate' );
		$support_url = self::support_url( 'help-tab-support' );

		$screen->add_help_tab(
			array(
				'id'      => 'htmlpp-overview',
				'title'   => __( 'Overview', 'html-page-publisher' ),
				'content' =>
					'<p>' . esc_html__( 'HTML Page Publisher serves standalone HTML files (including output from Claude Design, ChatGPT, Gemini, v0, and Bolt) at clean, configurable URLs.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Publish in three steps:', 'html-page-publisher' ) . '</strong></p>' .
					'<ol>' .
					'<li>' . esc_html__( 'Enter a URL-friendly slug (lowercase letters, numbers, and hyphens).', 'html-page-publisher' ) . '</li>' .
					'<li>' . esc_html__( 'Upload your HTML file. AI-export runtime wrappers are stripped automatically.', 'html-page-publisher' ) . '</li>' .
					'<li>' . esc_html__( '(Optional) Upload any images referenced by the HTML. They go into the page’s /assets/ folder.', 'html-page-publisher' ) . '</li>' .
					'</ol>' .
					'<p>' . sprintf(
						/* translators: %s: example URL */
						esc_html__( 'Your page will be served at %s. Change the prefix or wire up a subdomain in Settings.', 'html-page-publisher' ),
						'<code>' . esc_html( $base_url ) . '</code>'
					) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'htmlpp-faq',
				'title'   => __( 'FAQ', 'html-page-publisher' ),
				'content' =>
					'<p><strong>' . esc_html__( 'Where are uploaded pages stored?', 'html-page-publisher' ) . '</strong><br>' .
					sprintf(
						/* translators: %s: storage directory path */
						esc_html__( 'In %s. Each page has its own folder with an index.html and an assets/ subfolder.', 'html-page-publisher' ),
						'<code>/' . esc_html( $storage ) . '/&lt;slug&gt;/</code>'
					) . '</p>' .
					'<p><strong>' . esc_html__( 'How does the subdomain feature work?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Point a subdomain at the same server (DNS + host config), then enter the hostname in Settings → Subdomain. Pages will also be served at https://your-subdomain/your-slug/.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Is it safe to upload arbitrary HTML?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Only administrators (manage_options) can upload. HTML and SVG files are served as-is, including any <script> tags, so only upload files you trust.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Will uninstalling delete my pages?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'No. Uninstall removes settings but leaves uploaded files intact. Delete the uploads folder manually if you want them gone.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Does it work with caching / CDNs?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Pages are served via direct PHP readfile before WP’s main query runs. Most page caches won’t catch them by default. Add the URL pattern to your CDN’s cache rules for edge caching.', 'html-page-publisher' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'htmlpp-support',
				'title'   => __( 'Support', 'html-page-publisher' ),
				'content' =>
					'<p>' . esc_html__( 'Found a bug, have a feature request, or just need a hand? I read every message.', 'html-page-publisher' ) . '</p>' .
					'<p>' .
						'<strong>' . esc_html__( 'Contact:', 'html-page-publisher' ) . '</strong> ' .
						'<a href="' . esc_url( $support_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open contact form', 'html-page-publisher' ) . '</a>' .
					'</p>' .
					'<p>' . esc_html__( 'When reaching out, please include:', 'html-page-publisher' ) . '</p>' .
					'<ul style="list-style:disc;padding-left:20px;">' .
					'<li>' . esc_html__( 'WordPress version and PHP version', 'html-page-publisher' ) . '</li>' .
					'<li>' . sprintf(
						/* translators: %s: plugin version number */
						esc_html__( 'Plugin version (%s)', 'html-page-publisher' ),
						esc_html( HTMLPP_VERSION )
					) . '</li>' .
					'<li>' . esc_html__( 'Steps to reproduce the issue', 'html-page-publisher' ) . '</li>' .
					'<li>' . esc_html__( 'Any error messages or screenshots', 'html-page-publisher' ) . '</li>' .
					'</ul>' .
					'<p>' . sprintf(
						/* translators: 1: opening <a> for donate link, 2: closing </a> */
						esc_html__( 'Enjoying the plugin? Consider %1$sbuying me a coffee%2$s. It keeps the project going.', 'html-page-publisher' ),
						'<a href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener">',
						'</a>'
					) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information', 'html-page-publisher' ) . '</strong></p>' .
			'<p><a href="https://wordpress.org/plugins/html-page-publisher/" target="_blank" rel="noopener">' . esc_html__( 'Plugin homepage', 'html-page-publisher' ) . '</a></p>' .
			'<p><a href="https://wordpress.org/support/plugin/html-page-publisher/" target="_blank" rel="noopener">' . esc_html__( 'Community forum', 'html-page-publisher' ) . '</a></p>' .
			'<p><a href="' . esc_url( self::support_url( 'help-sidebar-support' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Contact support', 'html-page-publisher' ) . '</a></p>' .
			'<p><a href="' . esc_url( self::donate_url( 'help-sidebar-donate' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Donate ☕', 'html-page-publisher' ) . '</a></p>'
		);
	}

	/**
	 * Left-side admin footer text — attribution + support + donate.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public function footer_text( $text ) {
		$author_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::AUTHOR_URL ),
			esc_html__( 'Hossein Karami', 'html-page-publisher' )
		);
		$support_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::support_url( 'admin-footer-support' ) ),
			esc_html__( 'Contact support', 'html-page-publisher' )
		);
		$donate_link = sprintf(
			'<a class="htmlpp-footer-donate" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::donate_url( 'admin-footer-donate' ) ),
			esc_html__( 'Donate', 'html-page-publisher' )
		);

		return sprintf(
			/* translators: 1: author link (Hossein Karami), 2: support email link, 3: donate link */
			__( 'HTML Page Publisher plugin developed by %1$s. Need help? %2$s. Enjoying the plugin? %3$s.', 'html-page-publisher' ),
			$author_link,
			$support_link,
			$donate_link
		);
	}

	/**
	 * Right-side admin footer text — plugin version (overrides WP version).
	 *
	 * @param string $text Default version text.
	 * @return string
	 */
	public function footer_version( $text ) {
		return sprintf(
			/* translators: %s: plugin version number */
			esc_html__( 'Version %s', 'html-page-publisher' ),
			esc_html( HTMLPP_VERSION )
		);
	}

	/**
	 * Add Settings + Donate links to the plugin's action-links row
	 * (the line next to Activate / Deactivate / Edit / Delete).
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=html-page-publisher-settings' ) ),
			esc_html__( 'Settings', 'html-page-publisher' )
		);
		$donate = sprintf(
			'<a href="%s" target="_blank" rel="noopener" style="color:#b45309;font-weight:600;">%s</a>',
			esc_url( self::donate_url( 'plugin-action-donate' ) ),
			esc_html__( 'Donate', 'html-page-publisher' )
		);
		// Prepend Settings (functional, expected first), append Donate (CTA).
		array_unshift( $links, $settings );
		$links['htmlpp-donate'] = $donate;
		return $links;
	}

	/**
	 * Add Support + Donate links to this plugin's row in the WP plugins list.
	 *
	 * @param array  $links Existing meta links.
	 * @param string $file  Plugin file path being filtered.
	 * @return array
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( HTMLPP_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$extra = array(
			'htmlpp-support' => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( self::support_url( 'plugin-row-support' ) ),
				esc_html__( 'Support', 'html-page-publisher' )
			),
		);
		return array_merge( $links, $extra );
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

		// On the editor screen, layer WordPress' built-in CodeMirror over
		// the textarea. wp_enqueue_code_editor() loads its own assets and
		// returns the settings (or false if the user disabled syntax
		// highlighting in their profile — the plain textarea still works).
		if ( $this->is_edit_request() && ! HTMLPP_Uploader::file_editing_disabled() ) {
			$cm = wp_enqueue_code_editor(
				array(
					'type'       => 'text/html',
					// Linting runs htmlhint on every keystroke and is the
					// single worst offender for large AI-exported HTML.
					'codemirror' => array(
						'lineNumbers'      => true,
						'indentUnit'       => 2,
						'tabSize'          => 2,
						// Wrapping a 100k-char minified line is catastrophic
						// in CodeMirror; horizontal scroll is far cheaper.
						'lineWrapping'     => false,
						'matchBrackets'    => false,
						'autoCloseBrackets' => false,
						'autoCloseTags'    => false,
						'matchTags'        => false,
						'lint'             => false,
						'gutters'          => array( 'CodeMirror-linenumbers' ),
					),
				)
			);
			if ( false !== $cm ) {
				wp_add_inline_script(
					'htmlpp-admin',
					'window.htmlppCodeEditorSettings = ' . wp_json_encode( $cm ) . ';',
					'before'
				);
			}
		}
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
			__( 'HTML Page Publisher Settings', 'html-page-publisher' ),
			__( 'Settings', 'html-page-publisher' ),
			'manage_options',
			'html-page-publisher-settings',
			array( $this, 'render_settings_page' )
		);

		// The editor is *not* a separate menu page. It's served by the
		// top-level page via ?action=edit so it reuses that page's
		// already-registered capability — registering a hidden submenu and
		// remove_submenu_page()'ing it breaks WP's user_can_access_admin_page().
	}

	/**
	 * Whether the current request targets the contextual editor screen.
	 *
	 * @return bool
	 */
	private function is_edit_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen selector only; mutations are nonce-verified in HTMLPP_Uploader.
		if ( isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return true;
		}
		// After a save/restore/asset round-trip the action arrives via POST.
		foreach ( array( 'htmlpp_edit', 'htmlpp_restore', 'htmlpp_asset_upload', 'htmlpp_asset_replace', 'htmlpp_asset_delete' ) as $htmlpp_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Screen selector only; verified in the matching HTMLPP_Uploader handler.
			if ( isset( $_POST[ $htmlpp_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the upload / list page.
	 */
	public function render_upload_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'html-page-publisher' ) );
		}

		$notice = HTMLPP_Uploader::handle_request();

		if ( $this->is_edit_request() ) {
			$this->render_edit_screen( $notice );
			return;
		}

		$pages = HTMLPP_Storage::list_pages();

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

	/**
	 * Render the per-page HTML editor screen.
	 *
	 * Invoked from render_upload_page() (the top-level page), so the
	 * capability has already been checked and the save/restore submission
	 * already processed into $notice.
	 *
	 * @param array|null $notice Notice from HTMLPP_Uploader::handle_request().
	 */
	private function render_edit_screen( $notice ) {
		// Determine which page we're editing. The slug arrives via GET when
		// arriving from the list, or via POST after a save/restore round-trip.
		// The matching HTMLPP_Uploader handler verifies the nonce before any
		// mutation; here the value is only a read-only screen selector.
		$slug = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selector; verified in HTMLPP_Uploader.
		if ( isset( $_GET['slug'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selector; verified in HTMLPP_Uploader.
			$slug = HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_GET['slug'] ) ) );
		}

		foreach ( array( 'htmlpp_edit', 'htmlpp_restore', 'htmlpp_asset_upload', 'htmlpp_asset_replace', 'htmlpp_asset_delete' ) as $htmlpp_key ) {
			if ( '' !== $slug ) {
				break;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Screen selector only; verified in the matching HTMLPP_Uploader handler.
			if ( isset( $_POST[ $htmlpp_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Screen selector only; verified in the matching HTMLPP_Uploader handler.
				$slug = HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST[ $htmlpp_key ] ) ) );
			}
		}

		$content = '' !== $slug ? HTMLPP_Storage::read_page( $slug ) : false;

		// Unknown / missing page: fall back to the list with an error notice.
		if ( '' === $slug || false === $content ) {
			if ( ! $notice ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'That page could not be found.', 'html-page-publisher' ),
				);
			}
			$pages = HTMLPP_Storage::list_pages();
			include HTMLPP_PLUGIN_DIR . 'views/admin-upload.php';
			return;
		}

		$editing_disabled = HTMLPP_Uploader::file_editing_disabled();
		$backups          = HTMLPP_Storage::list_backups( $slug );
		$assets           = HTMLPP_Storage::list_assets( $slug );
		$page_url         = HTMLPP_Storage::public_page_url( $slug );
		$list_url         = admin_url( 'admin.php?page=html-page-publisher' );

		include HTMLPP_PLUGIN_DIR . 'views/admin-edit.php';
	}
}

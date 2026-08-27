<?php
/**
 * Admin menus, request handling and page renderers.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menus, request handling (post/redirect/get) and screen renderers.
 */
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
	 * WordPress.org review URL.
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/html-page-publisher/reviews/#new-post';

	/**
	 * Top-level menu slug.
	 */
	const PAGE_SLUG = 'html-page-publisher';

	/**
	 * User meta key storing the review-notice state.
	 */
	const REVIEW_META = 'htmlpp_review_notice';

	/**
	 * Wire admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'current_screen', array( $this, 'maybe_filter_footer' ) );
		add_action( 'admin_notices', array( $this, 'maybe_review_notice' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( HTMLPP_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Build a Buy Me a Coffee donate URL with location-specific UTM tracking.
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
	 * Admin URL of the pages list.
	 *
	 * @return string
	 */
	public static function list_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Admin URL of a page's editor screen.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function edit_url( $slug ) {
		return add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'edit',
				'slug'   => $slug,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Admin URL of the settings screen.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-settings' );
	}

	/*
	|--------------------------------------------------------------------------
	| Request handling (Post → Redirect → Get)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Process form submissions and one-click actions on admin_init — before
	 * any output — then redirect so a browser refresh never re-submits.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen selector only; each action verifies its own nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && self::PAGE_SLUG . '-settings' !== $page ) {
			return;
		}

		// Review-notice choices.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( isset( $_GET['htmlpp_review'] ) ) {
			check_admin_referer( 'htmlpp_review' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
			$choice = sanitize_key( wp_unslash( $_GET['htmlpp_review'] ) );
			$value  = 'later' === $choice ? (string) ( time() + 30 * DAY_IN_SECONDS ) : 'dismissed';
			update_user_meta( get_current_user_id(), self::REVIEW_META, $value );
			wp_safe_redirect( remove_query_arg( array( 'htmlpp_review', '_wpnonce' ) ) );
			exit;
		}

		// Re-run the direct-access protection probe.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( isset( $_GET['htmlpp_recheck'] ) ) {
			check_admin_referer( 'htmlpp_recheck' );
			HTMLPP_Storage::direct_access_status( true );
			wp_safe_redirect( remove_query_arg( array( 'htmlpp_recheck', '_wpnonce' ) ) );
			exit;
		}

		// A body larger than post_max_size reaches PHP with $_POST and $_FILES
		// already emptied (nonce included), so no handler could run and the
		// edit or upload would silently vanish. Detect it from Content-Length.
		if ( $this->post_body_was_dropped() ) {
			self::set_notice(
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: maximum POST size, e.g. 8 MB */
						__( 'Nothing was saved: the submission was larger than this server’s post_max_size (%s) and PHP discarded it. Ask your host to raise post_max_size and upload_max_filesize, or upload a smaller file.', 'html-page-publisher' ),
						size_format( wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) )
					),
				)
			);
			wp_safe_redirect( $this->current_screen_url() );
			exit;
		}

		// Mutations: every form carries htmlpp_nonce; handlers verify it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; verified inside HTMLPP_Uploader handlers.
		if ( ! isset( $_POST['htmlpp_nonce'] ) ) {
			return;
		}

		$notice = HTMLPP_Uploader::handle_request();
		if ( ! $notice ) {
			return;
		}

		self::set_notice( $notice );

		if ( headers_sent() ) {
			// Extremely unlikely on admin_init; fall through and let the
			// screen render the notice from the transient.
			return;
		}

		wp_safe_redirect( $this->redirect_target( $notice ) );
		exit;
	}

	/**
	 * Whether this is a POST whose body PHP discarded for exceeding
	 * post_max_size (the same check core's media uploader performs).
	 *
	 * @return bool
	 */
	private function post_body_was_dropped() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method || ! empty( $_POST ) || ! empty( $_FILES ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only.
			return false;
		}
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$max    = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		return $length > 0 && $max > 0 && $length > $max;
	}

	/**
	 * URL of the plugin screen the current request targets (page/action/slug).
	 *
	 * @return string
	 */
	private function current_screen_url() {
		$args = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		foreach ( array( 'page', 'action', 'slug' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		if ( empty( $args['page'] ) ) {
			$args['page'] = self::PAGE_SLUG;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Where to send the browser after a mutation.
	 *
	 * @param array $notice The notice produced by the handler.
	 * @return string
	 */
	private function redirect_target( $notice ) {
		// Handlers that moved or created a page tell us where it lives now.
		if ( ! empty( $notice['slug'] ) && 'success' === $notice['type'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only routing; the mutation was already nonce-verified.
			if ( isset( $_POST['htmlpp_upload'] ) ) {
				return self::list_url();
			}
			return self::edit_url( $notice['slug'] );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only routing; the mutation was already nonce-verified.
		foreach ( array( 'htmlpp_edit', 'htmlpp_restore', 'htmlpp_asset_upload', 'htmlpp_asset_replace', 'htmlpp_asset_delete', 'htmlpp_page_settings', 'htmlpp_duplicate', 'htmlpp_reset_preview' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$slug = HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				return '' !== $slug ? self::edit_url( $slug ) : self::list_url();
			}
		}

		// A failed upload sends the user back with the slug pre-filled so
		// only the file(s) need to be picked again.
		if ( isset( $_POST['htmlpp_upload'], $_POST['page_slug'] ) && 'error' === $notice['type'] ) {
			$slug = HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['page_slug'] ) ) );
			if ( '' !== $slug ) {
				return add_query_arg( 'prefill_slug', $slug, self::list_url() );
			}
		}
		// phpcs:enable

		return self::list_url();
	}

	/**
	 * Stash a notice for the current user until the next page load.
	 *
	 * @param array $notice Notice (type, message, raw_html).
	 */
	public static function set_notice( $notice ) {
		set_transient( 'htmlpp_notice_' . get_current_user_id(), $notice, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieve and clear the stashed notice for the current user.
	 *
	 * @return array|null
	 */
	public static function take_notice() {
		$key    = 'htmlpp_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( false !== $notice ) {
			delete_transient( $key );
		}
		return is_array( $notice ) ? $notice : null;
	}

	/*
	|--------------------------------------------------------------------------
	| Review prompt
	|--------------------------------------------------------------------------
	*/

	/**
	 * A single, dismissible "leave a review" notice — shown only on the
	 * plugin's own screens, only after the site has published at least three
	 * pages and used the plugin for a week, and never again once dismissed.
	 */
	public function maybe_review_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}

		if ( (int) get_option( 'htmlpp_publish_count', 0 ) < 3 ) {
			return;
		}

		$installed = (int) get_option( 'htmlpp_installed_at', 0 );
		if ( $installed > 0 && ( time() - $installed ) < 7 * DAY_IN_SECONDS ) {
			return;
		}

		$state = get_user_meta( get_current_user_id(), self::REVIEW_META, true );
		if ( 'dismissed' === $state ) {
			return;
		}
		if ( is_numeric( $state ) && time() < (int) $state ) {
			return;
		}

		$done_url  = wp_nonce_url( add_query_arg( 'htmlpp_review', 'done' ), 'htmlpp_review' );
		$later_url = wp_nonce_url( add_query_arg( 'htmlpp_review', 'later' ), 'htmlpp_review' );
		?>
		<div class="notice notice-info is-dismissible htmlpp-review-notice" data-htmlpp-later="<?php echo esc_url( $later_url ); ?>">
			<p>
				<strong><?php esc_html_e( 'Enjoying HTML Page Publisher?', 'html-page-publisher' ); ?></strong>
				<?php esc_html_e( 'A short review on WordPress.org helps other people find it and keeps the plugin going.', 'html-page-publisher' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener" data-htmlpp-dismiss="<?php echo esc_url( $done_url ); ?>">
					<?php esc_html_e( 'Leave a review', 'html-page-publisher' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'html-page-publisher' ); ?></span>
				</a>
				<a class="button" href="<?php echo esc_url( $later_url ); ?>">
					<?php esc_html_e( 'Maybe later', 'html-page-publisher' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $done_url ); ?>">
					<?php esc_html_e( 'I already did / don’t ask again', 'html-page-publisher' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG );
	}

	/*
	|--------------------------------------------------------------------------
	| Footer, help tabs, plugin-list links
	|--------------------------------------------------------------------------
	*/

	/**
	 * On plugin admin screens: replace WP's default admin footer
	 * and register contextual help tabs (Overview / FAQ / Support).
	 */
	public function maybe_filter_footer() {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
		add_filter( 'update_footer', array( $this, 'footer_version' ), 11 );
		$this->register_help_tabs( get_current_screen() );
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
					'<p>' . esc_html__( 'HTML Page Publisher serves standalone HTML files — Claude Design exports, pages from ChatGPT or Gemini, or hand-written HTML — at clean, configurable URLs on your own site. Upload a single file or a ZIP bundle, keep drafts private with a preview link, and serve a page at a custom path or as the front page.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Publish in three steps:', 'html-page-publisher' ) . '</strong></p>' .
					'<ol>' .
					'<li>' . esc_html__( 'Enter a URL-friendly slug (lowercase letters, numbers, and hyphens).', 'html-page-publisher' ) . '</li>' .
					'<li>' . esc_html__( 'Upload your HTML file, or a ZIP of the exported folder. Claude Design’s export-time runtime wrappers are stripped automatically.', 'html-page-publisher' ) . '</li>' .
					'<li>' . esc_html__( '(Optional) Upload any images or other files referenced by the HTML. They go into the page’s /assets/ folder.', 'html-page-publisher' ) . '</li>' .
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
						esc_html__( 'In %s. Each page has its own folder with an index.html and an assets/ subfolder. Direct access to that folder is blocked; pages are only served at their public URL.', 'html-page-publisher' ),
						'<code>/' . esc_html( $storage ) . '/&lt;slug&gt;/</code>'
					) . '</p>' .
					'<p><strong>' . esc_html__( 'How does the subdomain feature work?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Point a subdomain at the same server (DNS + host config), then enter the hostname in Settings → Subdomain. Pages will also be served at https://your-subdomain/your-slug/.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Is it safe to upload arbitrary HTML?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Only administrators (manage_options) can upload. HTML and SVG files are served as-is, including any <script> tags, so only upload files you trust.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Will uninstalling delete my pages?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'No. Uninstall removes settings but leaves uploaded files intact. Delete the uploads folder manually if you want them gone.', 'html-page-publisher' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Does it work with caching / CDNs?', 'html-page-publisher' ) . '</strong><br>' .
					esc_html__( 'Yes. Pages are served with ETag and Last-Modified headers and answer conditional requests with 304, so browsers and CDNs revalidate cheaply. Edits show immediately. Use the htmlpp_cache_max_age filter to let CDNs cache the HTML itself.', 'html-page-publisher' ) . '</p>',
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
		$author_link  = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::AUTHOR_URL ),
			esc_html__( 'Hossein Karami', 'html-page-publisher' )
		);
		$support_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::support_url( 'admin-footer-support' ) ),
			esc_html__( 'Contact support', 'html-page-publisher' )
		);
		$donate_link  = sprintf(
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
	 * Add Settings + Donate links to the plugin's action-links row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Settings', 'html-page-publisher' )
		);
		$donate   = sprintf(
			'<a href="%s" target="_blank" rel="noopener" style="color:#b45309;font-weight:600;">%s</a>',
			esc_url( self::donate_url( 'plugin-action-donate' ) ),
			esc_html__( 'Donate', 'html-page-publisher' )
		);
		array_unshift( $links, $settings );
		$links['htmlpp-donate'] = $donate;
		return $links;
	}

	/**
	 * Add Support link to this plugin's row in the WP plugins list.
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

	/*
	|--------------------------------------------------------------------------
	| Assets and menus
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enqueue admin CSS/JS — only on this plugin's pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
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

		wp_localize_script(
			'htmlpp-admin',
			'htmlppL10n',
			array(
				/* translators: %d: number of selected files */
				'filesSelected' => __( '%d files selected', 'html-page-publisher' ),
				/* translators: %s: combined size of the selected files, e.g. 1.2 MB */
				'total'         => __( '%s total', 'html-page-publisher' ),
				'unsaved'       => __( 'You have unsaved changes to this page’s HTML.', 'html-page-publisher' ),
				'noMatches'     => __( 'No pages match your search.', 'html-page-publisher' ),
			)
		);

		// On the editor screen, layer WordPress' built-in CodeMirror over
		// the textarea. wp_enqueue_code_editor() loads its own assets and
		// returns the settings (or false if the user disabled syntax
		// highlighting in their profile — the plain textarea still works).
		if ( $this->is_edit_request() && ! HTMLPP_Uploader::file_editing_disabled() ) {
			$cm = wp_enqueue_code_editor(
				array(
					'type'       => 'text/html',
					'codemirror' => array(
						'lineNumbers'       => true,
						'indentUnit'        => 2,
						'tabSize'           => 2,
						'lineWrapping'      => false,
						'matchBrackets'     => false,
						'autoCloseBrackets' => false,
						'autoCloseTags'     => false,
						'matchTags'         => false,
						'lint'              => false,
						'gutters'           => array( 'CodeMirror-linenumbers' ),
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
	 * Add a scoping class to <body> on plugin pages.
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
			self::PAGE_SLUG,
			array( $this, 'render_upload_page' ),
			'dashicons-media-document',
			30
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'HTML Pages', 'html-page-publisher' ),
			__( 'All Pages', 'html-page-publisher' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_upload_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'HTML Page Publisher Settings', 'html-page-publisher' ),
			__( 'Settings', 'html-page-publisher' ),
			'manage_options',
			self::PAGE_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);

		// The editor is *not* a separate menu page. It's served by the
		// top-level page via ?action=edit so it reuses that page's
		// already-registered capability.
	}

	/**
	 * Whether the current request targets the contextual editor screen.
	 *
	 * @return bool
	 */
	private function is_edit_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen selector only; mutations are nonce-verified in HTMLPP_Uploader.
		return isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
	}

	/**
	 * Render the upload / list page.
	 */
	public function render_upload_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'html-page-publisher' ) );
		}

		$notice = self::take_notice();

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

		$protection  = HTMLPP_Storage::direct_access_status();
		$recheck_url = wp_nonce_url( add_query_arg( 'htmlpp_recheck', '1', self::settings_url() ), 'htmlpp_recheck' );

		include HTMLPP_PLUGIN_DIR . 'views/admin-settings.php';
	}

	/**
	 * Render the per-page HTML editor screen.
	 *
	 * @param array|null $notice Notice stashed by handle_actions().
	 */
	private function render_edit_screen( $notice ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selector; mutations are verified in HTMLPP_Uploader.
		$slug = isset( $_GET['slug'] ) ? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_GET['slug'] ) ) ) : '';

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
		$assets           = HTMLPP_Storage::list_files( $slug );
		$page_url         = HTMLPP_Storage::public_page_url( $slug );
		$list_url         = self::list_url();
		$meta             = HTMLPP_Meta::get( $slug );
		$preview_url      = HTMLPP_Meta::preview_url( $slug );
		$accept           = HTMLPP_Uploader::accept_attribute();

		include HTMLPP_PLUGIN_DIR . 'views/admin-edit.php';
	}
}

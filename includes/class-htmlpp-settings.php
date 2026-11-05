<?php
/**
 * Settings API integration: page URLs, tracking code, SEO and AI access.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API registration and sanitization.
 */
class HTMLPP_Settings {

	const OPTION = 'htmlpp_settings';

	/**
	 * Settings API page ids, one per Settings tab that holds fields. Add-ons
	 * that add sections to the original 'htmlpp_settings' page show on the
	 * General tab.
	 */
	const PAGE_GENERAL  = 'htmlpp_settings';
	const PAGE_TRACKING = 'htmlpp_settings_tracking';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		// The storage .htaccess redirects legacy links to the prefix URL, so
		// it has to be rewritten whenever that prefix changes.
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'refresh_protection' ) );
	}

	/**
	 * Rewrite the storage protection files after a settings change.
	 */
	public static function refresh_protection() {
		HTMLPP_Storage::ensure_dir();
		delete_transient( HTMLPP_Storage::PROTECTION_TRANSIENT );
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'url_prefix'     => 'pages',
			'subdomain'      => '',
			'head_snippet'   => '',
			'footer_snippet' => '',
			'canonical'      => true,
			'ai_enabled'     => true,
		);
		$saved    = get_option( self::OPTION, array() );
		return wp_parse_args( (array) $saved, $defaults );
	}

	/**
	 * Whether the site owner lets AI assistants use the plugin's abilities.
	 *
	 * @return bool
	 */
	public static function ai_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['ai_enabled'] );
	}

	/**
	 * Register the setting + sections + fields.
	 */
	public function register() {
		register_setting(
			'htmlpp_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(
					'url_prefix' => 'pages',
					'subdomain'  => '',
				),
			)
		);

		add_settings_section( 'htmlpp_url_section', __( 'Page URLs', 'html-page-publisher' ), null, self::PAGE_GENERAL );

		add_settings_field(
			'url_prefix',
			__( 'URL prefix', 'html-page-publisher' ),
			array( $this, 'field_prefix' ),
			self::PAGE_GENERAL,
			'htmlpp_url_section',
			array( 'label_for' => 'htmlpp-url_prefix' )
		);

		add_settings_field(
			'subdomain',
			__( 'Subdomain', 'html-page-publisher' ),
			array( $this, 'field_subdomain' ),
			self::PAGE_GENERAL,
			'htmlpp_url_section',
			array( 'label_for' => 'htmlpp-subdomain' )
		);

		add_settings_section( 'htmlpp_snippets_section', __( 'Code on every page', 'html-page-publisher' ), null, self::PAGE_TRACKING );

		add_settings_field(
			'head_snippet',
			__( 'Head snippet', 'html-page-publisher' ),
			array( $this, 'field_head_snippet' ),
			self::PAGE_TRACKING,
			'htmlpp_snippets_section',
			array( 'label_for' => 'htmlpp-head_snippet' )
		);

		add_settings_field(
			'footer_snippet',
			__( 'Footer snippet', 'html-page-publisher' ),
			array( $this, 'field_footer_snippet' ),
			self::PAGE_TRACKING,
			'htmlpp_snippets_section',
			array( 'label_for' => 'htmlpp-footer_snippet' )
		);

		add_settings_section( 'htmlpp_seo_section', __( 'Search engines', 'html-page-publisher' ), null, self::PAGE_TRACKING );

		add_settings_field(
			'canonical',
			__( 'Canonical link', 'html-page-publisher' ),
			array( $this, 'field_canonical' ),
			self::PAGE_TRACKING,
			'htmlpp_seo_section'
		);
	}

	/**
	 * One-line description shown under a section's title.
	 *
	 * @param string $id Section id.
	 * @return string
	 */
	private static function section_description( $id ) {
		switch ( $id ) {
			case 'htmlpp_url_section':
				return __( 'Where your pages are served on this site.', 'html-page-publisher' );
			case 'htmlpp_snippets_section':
				return __( 'Analytics (GA4, GTM, Meta Pixel), fonts, a consent banner or a chat widget, added to every published page. A page can opt out in its own settings.', 'html-page-publisher' );
			case 'htmlpp_seo_section':
				return __( 'How search engines see your pages.', 'html-page-publisher' );
		}
		return '';
	}

	/**
	 * Print a Settings API page's sections as cards, one row per field.
	 *
	 * Replaces do_settings_sections() so the screen matches the rest of the
	 * plugin, while sections and fields that add-ons register still show.
	 *
	 * @param string $page Settings API page id.
	 */
	public static function render_sections( $page ) {
		global $wp_settings_sections, $wp_settings_fields;

		if ( empty( $wp_settings_sections[ $page ] ) ) {
			return;
		}

		foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
			$description = self::section_description( $section['id'] );
			$fields      = isset( $wp_settings_fields[ $page ][ $section['id'] ] ) ? (array) $wp_settings_fields[ $page ][ $section['id'] ] : array();
			?>
			<section class="htmlpp-card htmlpp-settings-card" id="<?php echo esc_attr( $section['id'] ); ?>">
				<div class="htmlpp-card__header">
					<div>
						<h2 class="htmlpp-card__title"><?php echo esc_html( $section['title'] ); ?></h2>
						<?php if ( '' !== $description ) : ?>
							<p class="htmlpp-card__subtitle"><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="htmlpp-card__body">
					<?php
					if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
						call_user_func( $section['callback'], $section );
					}
					foreach ( $fields as $field ) :
						?>
						<div class="htmlpp-setting">
							<div class="htmlpp-setting__label">
								<?php if ( ! empty( $field['args']['label_for'] ) ) : ?>
									<label for="<?php echo esc_attr( $field['args']['label_for'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
								<?php else : ?>
									<span><?php echo esc_html( $field['title'] ); ?></span>
								<?php endif; ?>
							</div>
							<div class="htmlpp-setting__control">
								<?php call_user_func( $field['callback'], $field['args'] ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}
	}

	/**
	 * Render a snippet textarea.
	 *
	 * @param string $key   Setting key.
	 * @param string $where Description of where it is inserted.
	 */
	private function snippet_field( $key, $where ) {
		$settings = self::get_settings();
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
			id="htmlpp-<?php echo esc_attr( $key ); ?>"
			class="large-text code"
			rows="6"
			spellcheck="false"
		><?php echo esc_textarea( $settings[ $key ] ); ?></textarea>
		<p class="description"><?php echo esc_html( $where ); ?></p>
		<?php
	}

	/**
	 * Head snippet field.
	 */
	public function field_head_snippet() {
		$this->snippet_field( 'head_snippet', __( 'Inserted just before </head>. Paste full tags, e.g. the GA4 or GTM <script> block.', 'html-page-publisher' ) );
	}

	/**
	 * Footer snippet field.
	 */
	public function field_footer_snippet() {
		$this->snippet_field( 'footer_snippet', __( 'Inserted just before </body>.', 'html-page-publisher' ) );
	}

	/**
	 * Canonical toggle.
	 */
	public function field_canonical() {
		$settings = self::get_settings();
		?>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[canonical]" value="0" />
		<label class="htmlpp-checkbox">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[canonical]" value="1" <?php checked( ! empty( $settings['canonical'] ) ); ?> />
			<span><?php esc_html_e( 'Add a <link rel="canonical"> to pages that do not already have one', 'html-page-publisher' ); ?></span>
		</label>
		<p class="description"><?php esc_html_e( 'Points search engines at the page’s public URL so the prefix URL, a custom path and a subdomain never compete with each other.', 'html-page-publisher' ); ?></p>
		<?php
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * Each Settings tab submits only its own fields, so anything missing from
	 * the input keeps its saved value. Checkboxes send a hidden "0" first,
	 * so an unticked box still arrives.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::get_settings();

		// Snippets are raw markup by design (same trust model as uploading a
		// page). Users without unfiltered_html (multisite site admins) are
		// limited to what wp_kses_post() allows.
		foreach ( array( 'head_snippet', 'footer_snippet' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = (string) $input[ $key ];
			if ( '' !== $raw && ! current_user_can( 'unfiltered_html' ) ) {
				$raw = wp_kses_post( $raw );
				if ( trim( $raw ) !== trim( (string) $input[ $key ] ) ) {
					add_settings_error(
						self::OPTION,
						'htmlpp_snippet_filtered',
						__( 'Some markup was removed from a snippet because your account does not have the unfiltered_html capability (script tags and similar are not allowed).', 'html-page-publisher' )
					);
				}
			}
			$out[ $key ] = trim( $raw );
		}

		foreach ( array( 'canonical', 'ai_enabled' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = ! empty( $input[ $key ] );
			}
		}

		if ( array_key_exists( 'url_prefix', $input ) ) {
			$prefix = sanitize_title( $input['url_prefix'] );
			if ( '' === $prefix ) {
				$prefix = 'pages';
			}

			// Reserved prefixes that would collide with WordPress core routing.
			$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'comments', 'trackback', 'xmlrpc.php' );
			if ( in_array( $prefix, $reserved, true ) ) {
				add_settings_error(
					self::OPTION,
					'htmlpp_reserved_prefix',
					sprintf(
						/* translators: %s: attempted prefix */
						__( '"%s" is reserved and cannot be used as a URL prefix. Falling back to "pages".', 'html-page-publisher' ),
						$prefix
					)
				);
				$prefix = 'pages';
			}
			$out['url_prefix'] = $prefix;
		}

		if ( array_key_exists( 'subdomain', $input ) ) {
			$sub = strtolower( trim( (string) $input['subdomain'] ) );
			// Strip protocol if user pasted a URL.
			$sub = rtrim( (string) preg_replace( '#^https?://#', '', $sub ), '/' );

			if ( '' === $sub || preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/', $sub ) ) {
				$out['subdomain'] = $sub;
			} else {
				add_settings_error(
					self::OPTION,
					'htmlpp_bad_subdomain',
					__( 'Subdomain must be a valid hostname like sales.example.com.', 'html-page-publisher' )
				);
				$out['subdomain'] = '';
			}
		}

		return $out;
	}

	/**
	 * Render the URL prefix field.
	 */
	public function field_prefix() {
		$settings = self::get_settings();
		$home     = trailingslashit( home_url() );
		?>
		<input type="text"
			id="htmlpp-url_prefix"
			name="<?php echo esc_attr( self::OPTION ); ?>[url_prefix]"
			value="<?php echo esc_attr( $settings['url_prefix'] ); ?>"
			class="regular-text"
			pattern="[a-z0-9\-]+" />
		<p class="description">
			<?php
			printf(
				/* translators: %s: example URL showing prefix */
				esc_html__( 'Pages will be served at: %s', 'html-page-publisher' ),
				'<code>' . esc_html( $home ) . '<strong>' . esc_html( $settings['url_prefix'] ) . '</strong>/your-slug/</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the subdomain field.
	 */
	public function field_subdomain() {
		$settings = self::get_settings();
		?>
		<input type="text"
			id="htmlpp-subdomain"
			name="<?php echo esc_attr( self::OPTION ); ?>[subdomain]"
			value="<?php echo esc_attr( $settings['subdomain'] ); ?>"
			class="regular-text"
			placeholder="sales.example.com" />
		<p class="description"><?php esc_html_e( 'Optional. Also serves every page at the root of another hostname, such as https://sales.example.com/your-slug/.', 'html-page-publisher' ); ?></p>
		<details class="htmlpp-details">
			<summary><?php esc_html_e( 'How to set up a subdomain', 'html-page-publisher' ); ?></summary>
			<ol>
				<li><?php esc_html_e( 'Create a DNS record that points the subdomain at this server.', 'html-page-publisher' ); ?></li>
				<li><?php esc_html_e( 'In your hosting panel or web server, serve the subdomain from this WordPress install.', 'html-page-publisher' ); ?></li>
				<li><?php esc_html_e( 'Enter the hostname here and save. The URL prefix isn’t used on the subdomain.', 'html-page-publisher' ); ?></li>
			</ol>
		</details>
		<?php
	}
}

<?php
/**
 * Settings API integration (URL prefix and subdomain).
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
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
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
		);
		$saved    = get_option( self::OPTION, array() );
		return wp_parse_args( (array) $saved, $defaults );
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

		add_settings_section(
			'htmlpp_url_section',
			__( 'URL Structure', 'html-page-publisher' ),
			array( $this, 'section_intro' ),
			'htmlpp_settings'
		);

		add_settings_field(
			'url_prefix',
			__( 'URL prefix', 'html-page-publisher' ),
			array( $this, 'field_prefix' ),
			'htmlpp_settings',
			'htmlpp_url_section'
		);

		add_settings_field(
			'subdomain',
			__( 'Subdomain (optional)', 'html-page-publisher' ),
			array( $this, 'field_subdomain' ),
			'htmlpp_settings',
			'htmlpp_url_section'
		);

		add_settings_section(
			'htmlpp_snippets_section',
			__( 'Global snippets & SEO', 'html-page-publisher' ),
			array( $this, 'snippets_intro' ),
			'htmlpp_settings'
		);

		add_settings_field(
			'head_snippet',
			__( 'Head snippet', 'html-page-publisher' ),
			array( $this, 'field_head_snippet' ),
			'htmlpp_settings',
			'htmlpp_snippets_section'
		);

		add_settings_field(
			'footer_snippet',
			__( 'Footer snippet', 'html-page-publisher' ),
			array( $this, 'field_footer_snippet' ),
			'htmlpp_settings',
			'htmlpp_snippets_section'
		);

		add_settings_field(
			'canonical',
			__( 'Canonical link', 'html-page-publisher' ),
			array( $this, 'field_canonical' ),
			'htmlpp_settings',
			'htmlpp_snippets_section'
		);
	}

	/**
	 * Snippets section intro.
	 */
	public function snippets_intro() {
		echo '<p>' . esc_html__( 'Code added to every published page when it is served — analytics (GA4, GTM, Meta Pixel), fonts, a consent banner, a chat widget. Pages can opt out individually from their Page settings.', 'html-page-publisher' ) . '</p>';
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
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[canonical]" value="1" <?php checked( ! empty( $settings['canonical'] ) ); ?> />
			<?php esc_html_e( 'Add a <link rel="canonical"> to pages that do not already have one', 'html-page-publisher' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Points search engines at the page’s public URL so the prefix URL, a custom path and a subdomain never compete with each other.', 'html-page-publisher' ); ?></p>
		<?php
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out      = array();
		$existing = self::get_settings();

		// Snippets are raw markup by design (same trust model as uploading a
		// page). Users without unfiltered_html (multisite site admins) are
		// limited to what wp_kses_post() allows.
		foreach ( array( 'head_snippet', 'footer_snippet' ) as $key ) {
			$raw = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
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
		$out['canonical'] = ! empty( $input['canonical'] );
		unset( $existing );

		$prefix = isset( $input['url_prefix'] ) ? sanitize_title( $input['url_prefix'] ) : 'pages';
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

		$sub = isset( $input['subdomain'] ) ? trim( (string) $input['subdomain'] ) : '';
		if ( '' !== $sub ) {
			$sub = strtolower( $sub );
			// Strip protocol if user pasted a URL.
			$sub = preg_replace( '#^https?://#', '', $sub );
			$sub = rtrim( $sub, '/' );

			if ( preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/', $sub ) ) {
				$out['subdomain'] = $sub;
			} else {
				add_settings_error(
					self::OPTION,
					'htmlpp_bad_subdomain',
					__( 'Subdomain must be a valid hostname like sales.example.com.', 'html-page-publisher' )
				);
				$out['subdomain'] = '';
			}
		} else {
			$out['subdomain'] = '';
		}

		return $out;
	}

	/**
	 * Section intro text.
	 */
	public function section_intro() {
		echo '<p>' . esc_html__( 'Configure where your HTML pages are served from.', 'html-page-publisher' ) . '</p>';
	}

	/**
	 * Render the URL prefix field.
	 */
	public function field_prefix() {
		$settings = self::get_settings();
		$home     = trailingslashit( home_url() );
		?>
		<input type="text"
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
			name="<?php echo esc_attr( self::OPTION ); ?>[subdomain]"
			value="<?php echo esc_attr( $settings['subdomain'] ); ?>"
			class="regular-text"
			placeholder="sales.example.com" />
		<p class="description">
			<?php
			esc_html_e(
				'Optional. If set, pages are also served at the root of this hostname (e.g. https://sales.example.com/your-slug/). You must (1) create a DNS record pointing the subdomain at this server, and (2) configure your web server / hosting control panel to serve the subdomain from this WordPress install. The URL prefix above is ignored on the subdomain.',
				'html-page-publisher'
			);
			?>
		</p>
		<?php
	}
}

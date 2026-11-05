<?php
/**
 * Abilities API integration (WordPress 6.9+).
 *
 * Registers the plugin's page operations as WordPress abilities, so an AI
 * assistant connected to the site can publish and edit pages. The official
 * MCP Adapter and other MCP plugins offer public abilities to Claude, ChatGPT,
 * Cursor and similar clients, and core serves them at /wp-json/wp-abilities/v1.
 *
 * Every ability runs through HTMLPP_Page_Service with the same capability
 * checks as the plugin's REST API. Deleting pages or files is deliberately
 * not offered: an agent can unpublish a page, and every HTML change it makes
 * keeps the previous version in the page's history.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and runs the plugin's abilities.
 */
class HTMLPP_Abilities {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'html-page-publisher';

	/**
	 * Namespace every ability name starts with.
	 */
	const PREFIX = 'html-page-publisher/';

	/**
	 * Most files one publish-page call may attach.
	 */
	const MAX_FILES = 20;

	/**
	 * MCP plugins known to offer public abilities to AI assistants, by
	 * plugin file. Shown on the Settings screen when one is active.
	 */
	const MCP_PLUGINS = array(
		'mcp-adapter/mcp-adapter.php' => 'MCP Adapter',
		'vibe-ai/vibe-ai.php'         => 'WPVibe',
		'easy-mcp-ai/easy-mcp-ai.php' => 'Easy MCP AI',
	);

	/**
	 * Route of the MCP Adapter's default server, under the REST root.
	 */
	const MCP_ADAPTER_ROUTE = 'mcp/mcp-adapter-default-server';

	/**
	 * Hook into the Abilities API registries.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Whether this WordPress has the Abilities API (core since 6.9).
	 *
	 * @return bool
	 */
	public static function supported() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether the abilities are registered: on in Settings and not turned off
	 * by code.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether HTML Page Publisher registers its abilities.
		 *
		 * @param bool $register Default: the "Offer page tools to AI
		 *                       assistants" setting (on unless switched off).
		 */
		return (bool) apply_filters( 'htmlpp_register_abilities', HTMLPP_Settings::ai_enabled() );
	}

	/**
	 * What the Settings screen shows about connecting an AI assistant.
	 *
	 * @return array{supported:bool,allowed:bool,enabled:bool,connectors:string[],active:string[],installed:string[]}
	 */
	public static function status() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_files = function_exists( 'get_plugins' ) ? array_keys( get_plugins() ) : array();
		$connectors      = array();
		$active          = array();
		$installed       = array();
		foreach ( self::MCP_PLUGINS as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$connectors[] = $name;
				$active[]     = $file;
			} elseif ( in_array( $file, $installed_files, true ) ) {
				$installed[] = $file;
			}
		}
		return array(
			'supported'  => self::supported(),
			'allowed'    => HTMLPP_Settings::ai_enabled(),
			'enabled'    => self::enabled(),
			'connectors' => $connectors,
			'active'     => $active,
			'installed'  => $installed,
		);
	}

	/**
	 * One word for the overall state, used for badges.
	 *
	 * @param array $status Result of status().
	 * @return string unsupported, off, setup or ready.
	 */
	public static function state( $status ) {
		if ( empty( $status['supported'] ) ) {
			return 'unsupported';
		}
		if ( empty( $status['enabled'] ) ) {
			return 'off';
		}
		return empty( $status['connectors'] ) ? 'setup' : 'ready';
	}

	/**
	 * Whether a site address can't be reached from the internet, so web apps
	 * such as claude.ai and ChatGPT can't connect to it.
	 *
	 * @param string $url         Site address.
	 * @param string $environment wp_get_environment_type() value.
	 * @return bool
	 */
	public static function is_local_site( $url, $environment = 'production' ) {
		if ( 'local' === $environment ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|localhost|test|invalid|example|internal|lan|home\.arpa)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Short name for this site in an AI app's server list, from its host.
	 *
	 * @param string $url Site address.
	 * @return string e.g. "example-com".
	 */
	public static function server_name( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$name = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( preg_replace( '/^www\./i', '', $host ) ) ), '-' );
		return '' !== $name ? $name : 'my-site';
	}

	/**
	 * Setup text for desktop and coding apps that sign in with an application
	 * password through the MCP Adapter. {{AUTH}} stands for the base64 of
	 * "username:password" and {{PASSWORD}} for the password itself; the
	 * Settings screen fills them in once a password is created.
	 *
	 * @param string $endpoint MCP Adapter server URL.
	 * @param string $username WordPress username the app signs in as.
	 * @param string $name     Server name for the app's list.
	 * @return array<string,string> Keyed by app: claude-code, claude-desktop, cursor.
	 */
	public static function connect_snippets( $endpoint, $username, $name ) {
		$config = wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'command' => 'npx',
						'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
						'env'     => array(
							'WP_API_URL'      => $endpoint,
							'WP_API_USERNAME' => $username,
							'WP_API_PASSWORD' => '{{PASSWORD}}',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return array(
			'claude-code'    => sprintf( 'claude mcp add --transport http %s %s --header "Authorization: Basic {{AUTH}}"', $name, $endpoint ),
			'claude-desktop' => $config,
			'cursor'         => $config,
		);
	}

	/**
	 * Service instance.
	 *
	 * @return HTMLPP_Page_Service
	 */
	private function service() {
		return htmlpp()->pages;
	}

	/*
	|--------------------------------------------------------------------------
	| Registration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Register the ability category.
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'HTML Pages', 'html-page-publisher' ),
				'description' => __( 'Standalone HTML landing pages published with HTML Page Publisher, served at their own URL outside the theme.', 'html-page-publisher' ),
			)
		);
	}

	/**
	 * Register every ability.
	 */
	public function register_abilities() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( $this->definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Full ability definitions, keyed by ability name.
	 *
	 * @return array<string, array>
	 */
	public function definitions() {
		$read  = array( $this, 'can_read' );
		$write = array( $this, 'can_write' );

		$abilities = array(
			'list-pages'      => array(
				'label'               => __( 'List HTML pages', 'html-page-publisher' ),
				'description'         => __( 'Lists the standalone HTML pages published with HTML Page Publisher on this site: slug, title, status (published or draft), public URL and when each was last changed. Use it to find a page before reading or changing it.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'published', 'draft' ),
							'description' => __( 'Only list pages with this status. Leave it out to list all pages.', 'html-page-publisher' ),
						),
					)
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pages' => array(
							'type'  => 'array',
							'items' => $this->page_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_pages' ),
				'permission_callback' => $read,
				'annotations'         => $this->annotations( true, false, true ),
			),
			'get-page'        => array(
				'label'               => __( 'Get an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Returns one HTML page’s details: public URL, status, private preview link for drafts, custom path and SEO settings. Set include_html to also get the page’s full HTML, which you need before changing it with html-page-publisher/update-page, and include_files to list its images, CSS, JavaScript and other files.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug'          => $this->slug_property(),
						'include_html'  => array(
							'type'        => 'boolean',
							'description' => __( 'Also return the page’s HTML source. Default false.', 'html-page-publisher' ),
						),
						'include_files' => array(
							'type'        => 'boolean',
							'description' => __( 'Also list the page’s files (everything except index.html). Default false.', 'html-page-publisher' ),
						),
					),
					array( 'slug' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page'  => $this->page_schema(),
						'html'  => array(
							'type'        => 'string',
							'description' => __( 'The page’s HTML source (only when include_html is true).', 'html-page-publisher' ),
						),
						'files' => array(
							'type'        => 'array',
							'description' => __( 'The page’s files (only when include_files is true). Reference one from the HTML by its reference, e.g. assets/hero.png.', 'html-page-publisher' ),
							'items'       => $this->file_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_page' ),
				'permission_callback' => $read,
				'annotations'         => $this->annotations( true, false, true ),
			),
			'publish-page'    => array(
				'label'               => __( 'Publish an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Publishes a new standalone landing page from a complete HTML document (its own <html>, <head>, CSS and JavaScript). The page is served exactly as written at its own URL, outside the WordPress theme, so its design stays intact. Use it for pages designed outside WordPress, such as Claude Design exports or AI-generated landing pages; use regular WordPress posts or pages for content that should use the site’s theme. Creates new pages only: to change an existing page, use html-page-publisher/update-page. Set status to draft to get a private preview link before going live. Images and other files can be attached in files and referenced from the HTML as assets/<name>. The response includes the page’s public URL.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug'    => $this->slug_property(),
						'html'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The complete HTML document, from <!doctype html> to </html>, with its CSS and JavaScript inline or in attached files.', 'html-page-publisher' ),
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'published', 'draft' ),
							'description' => __( 'published (default) makes the page live; draft hides it from visitors and search engines and returns a private preview link.', 'html-page-publisher' ),
						),
						'path'    => $this->path_property(),
						'noindex' => array(
							'type'        => 'boolean',
							'description' => __( 'Ask search engines not to index the page. Default false.', 'html-page-publisher' ),
						),
						'files'   => array(
							'type'        => 'array',
							'maxItems'    => self::MAX_FILES,
							'description' => __( 'Files to store in the page’s assets folder, e.g. images or a stylesheet. Reference each from the HTML as assets/<name>.', 'html-page-publisher' ),
							'items'       => $this->file_input_schema(),
						),
					),
					array( 'slug', 'html' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page'        => $this->page_schema(),
						'files_added' => array(
							'type'        => 'array',
							'description' => __( 'Names of the files stored in assets/.', 'html-page-publisher' ),
							'items'       => array( 'type' => 'string' ),
						),
						'file_errors' => array(
							'type'        => 'array',
							'description' => __( 'Files that were refused, with the reason. The page was published without them.', 'html-page-publisher' ),
							'items'       => array( 'type' => 'string' ),
						),
						'messages'    => $this->messages_schema(),
					),
				),
				'execute_callback'    => array( $this, 'publish_page' ),
				'permission_callback' => $write,
				'annotations'         => $this->annotations( false, false, false ),
			),
			'update-page'     => array(
				'label'               => __( 'Update an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Changes an existing HTML page. To change its content, send either edits (exact find-and-replace pairs, best for small changes) or html (the complete new document). You can also change its settings: status (publish a draft, or unpublish a live page), a custom path, noindex, no_snippets, or new_slug to rename it (the old URL redirects). Every change to the HTML saves the previous version in the page’s history, so it can be undone with html-page-publisher/restore-version.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug'        => $this->slug_property(),
						'edits'       => array(
							'type'        => 'array',
							'minItems'    => 1,
							'description' => __( 'Find-and-replace pairs applied in order. Each find text must appear exactly once in the current HTML (get it with html-page-publisher/get-page and include_html). If any edit does not match, nothing is changed. Do not combine with html.', 'html-page-publisher' ),
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'find'    => array(
										'type'        => 'string',
										'minLength'   => 1,
										'description' => __( 'Exact text to find, copied from the current HTML.', 'html-page-publisher' ),
									),
									'replace' => array(
										'type'        => 'string',
										'description' => __( 'Text to put in its place (empty to remove it).', 'html-page-publisher' ),
									),
								),
								'required'             => array( 'find', 'replace' ),
								'additionalProperties' => false,
							),
						),
						'html'        => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The complete new HTML document, replacing the current one. Do not combine with edits.', 'html-page-publisher' ),
						),
						'status'      => array(
							'type'        => 'string',
							'enum'        => array( 'published', 'draft' ),
							'description' => __( 'published makes the page live; draft takes it offline (visitors get a 404).', 'html-page-publisher' ),
						),
						'path'        => $this->path_property(),
						'noindex'     => array(
							'type'        => 'boolean',
							'description' => __( 'Ask search engines not to index the page.', 'html-page-publisher' ),
						),
						'no_snippets' => array(
							'type'        => 'boolean',
							'description' => __( 'Leave out the site-wide head and footer snippets (analytics and similar) on this page.', 'html-page-publisher' ),
						),
						'new_slug'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Rename the page to this slug. The old URL redirects to the new one.', 'html-page-publisher' ),
						),
					),
					array( 'slug' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page'     => $this->page_schema(),
						'messages' => $this->messages_schema(),
					),
				),
				'execute_callback'    => array( $this, 'update_page' ),
				'permission_callback' => $write,
				'annotations'         => $this->annotations( false, true, false ),
			),
			'duplicate-page'  => array(
				'label'               => __( 'Duplicate an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Copies a page and all its files to a new slug as a draft. Use it to make a variant without touching the live page, then change the copy with html-page-publisher/update-page and publish it.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug'     => $this->slug_property(),
						'new_slug' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Slug for the copy. It must not be in use.', 'html-page-publisher' ),
						),
					),
					array( 'slug', 'new_slug' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page' => $this->page_schema(),
					),
				),
				'execute_callback'    => array( $this, 'duplicate_page' ),
				'permission_callback' => $write,
				'annotations'         => $this->annotations( false, false, false ),
			),
			'list-versions'   => array(
				'label'               => __( 'List versions of an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Lists the saved earlier versions of a page’s HTML, newest first. Each change to the HTML saves the version before it. Pass a version’s name to html-page-publisher/restore-version to bring it back.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug' => $this->slug_property(),
					),
					array( 'slug' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'versions' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'     => array(
										'type'        => 'string',
										'description' => __( 'Version name, used to restore it.', 'html-page-publisher' ),
									),
									'modified' => array(
										'type'        => 'integer',
										'description' => __( 'When this version was saved (Unix time).', 'html-page-publisher' ),
									),
									'size'     => array(
										'type'        => 'integer',
										'description' => __( 'Size in bytes.', 'html-page-publisher' ),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_versions' ),
				'permission_callback' => $read,
				'annotations'         => $this->annotations( true, false, true ),
			),
			'restore-version' => array(
				'label'               => __( 'Restore a version of an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Restores an earlier version of a page’s HTML, by its name from html-page-publisher/list-versions. The current HTML is saved as a version first, so a restore can itself be undone.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array(
						'slug'    => $this->slug_property(),
						'version' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Version name from html-page-publisher/list-versions.', 'html-page-publisher' ),
						),
					),
					array( 'slug', 'version' )
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page' => $this->page_schema(),
					),
				),
				'execute_callback'    => array( $this, 'restore_version' ),
				'permission_callback' => $write,
				'annotations'         => $this->annotations( false, true, false ),
			),
			'add-page-file'   => array(
				'label'               => __( 'Add a file to an HTML page', 'html-page-publisher' ),
				'description'         => __( 'Adds an image, stylesheet, script, font or other file to a page’s assets folder. Send text files (SVG, CSS, JavaScript) as text and binary files (PNG, JPEG, WebP, fonts) as base64. Reference the file from the page’s HTML as assets/<name>, using the name in the response: if the name is taken, a number is added. SVG files are cleaned of scripts; PHP and other executable files are refused.', 'html-page-publisher' ),
				'input_schema'        => $this->object_schema(
					array_merge(
						array( 'slug' => $this->slug_property() ),
						$this->file_input_schema()['properties']
					),
					array( 'slug', 'name', 'content' )
				),
				'output_schema'       => $this->file_schema(),
				'execute_callback'    => array( $this, 'add_page_file' ),
				'permission_callback' => $write,
				'annotations'         => $this->annotations( false, false, false ),
			),
		);

		$definitions = array();
		foreach ( $abilities as $key => $args ) {
			$name = self::PREFIX . $key;

			/**
			 * Filter whether an ability is offered to clients: AI assistants
			 * through MCP, and core's abilities REST API. Return false to keep
			 * it for PHP use only.
			 *
			 * @param bool   $public Default true.
			 * @param string $name   Ability name, e.g. html-page-publisher/publish-page.
			 */
			$public = (bool) apply_filters( 'htmlpp_abilities_public', true, $name );

			$args['category'] = self::CATEGORY;
			$args['meta']     = array(
				'annotations'  => $args['annotations'],
				// WordPress 7.1+ and the MCP Adapter read `public`; 6.9 and
				// 7.0 read `show_in_rest`; `mcp.public` is the MCP Adapter's
				// own flag, which other MCP plugins follow too.
				'public'       => $public,
				'show_in_rest' => $public,
				'mcp'          => array(
					'public' => $public,
					'type'   => 'tool',
				),
			);
			unset( $args['annotations'] );

			$definitions[ $name ] = $args;
		}
		return $definitions;
	}

	/*
	|--------------------------------------------------------------------------
	| Permissions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read abilities: the capability that manages pages (manage_options).
	 *
	 * @param mixed $input Ability input (unused).
	 * @return bool
	 */
	public function can_read( $input = null ) {
		return current_user_can( HTMLPP_Plugin::capability() );
	}

	/**
	 * Write abilities: managing pages plus permission to write raw markup.
	 *
	 * @param mixed $input Ability input (unused).
	 * @return bool
	 */
	public function can_write( $input = null ) {
		return HTMLPP_Plugin::can_write_markup();
	}

	/*
	|--------------------------------------------------------------------------
	| Execute callbacks
	|--------------------------------------------------------------------------
	*/

	/**
	 * List pages.
	 *
	 * @param mixed $input { status? }.
	 * @return array
	 */
	public function list_pages( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$pages = $this->service()->list_pages();
		if ( isset( $input['status'] ) && in_array( $input['status'], array( 'published', 'draft' ), true ) ) {
			$status = $input['status'];
			$pages  = array_values(
				array_filter(
					$pages,
					static function ( $page ) use ( $status ) {
						return $status === $page['status'];
					}
				)
			);
		}
		return array( 'pages' => $pages );
	}

	/**
	 * Get one page.
	 *
	 * @param mixed $input { slug, include_html?, include_files? }.
	 * @return array|WP_Error
	 */
	public function get_page( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$page  = $this->service()->get( self::text( $input, 'slug' ), self::flag( $input, 'include_html' ) );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$result = array();
		if ( array_key_exists( 'html', $page ) ) {
			$result['html'] = $page['html'];
			unset( $page['html'] );
		}
		$result = array( 'page' => $page ) + $result;

		if ( self::flag( $input, 'include_files' ) ) {
			$files = $this->service()->list_files( $page['slug'] );
			if ( is_wp_error( $files ) ) {
				return $files;
			}
			$result['files'] = array_map( array( $this, 'file_entry' ), $files );
		}
		return $result;
	}

	/**
	 * Publish a new page.
	 *
	 * @param mixed $input { slug, html, status?, path?, noindex?, files? }.
	 * @return array|WP_Error
	 */
	public function publish_page( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$service = $this->service();
		$slug    = HTMLPP_Storage::sanitize_slug( self::text( $input, 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'htmlpp_bad_slug', __( 'Please give the page a slug: lowercase letters, numbers and hyphens.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( $service->exists( $slug ) ) {
			return new WP_Error(
				'htmlpp_exists',
				sprintf(
					/* translators: %s: page slug */
					__( 'A page with the slug %s already exists. To change it, use html-page-publisher/update-page; to publish a new page, choose another slug.', 'html-page-publisher' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}

		// Check the custom path before anything is written, so a refused
		// path does not leave a half-configured page behind.
		$path = null;
		if ( isset( $input['path'] ) && '' !== trim( (string) $input['path'] ) ) {
			$path = $service->validate_path( $slug, (string) $input['path'] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
		}

		$files = self::list_value( $input, 'files' );
		if ( count( $files ) > self::MAX_FILES ) {
			return new WP_Error(
				'htmlpp_too_many_files',
				sprintf(
					/* translators: %d: maximum number of files */
					__( 'Attach at most %d files when publishing; add more afterwards with html-page-publisher/add-page-file.', 'html-page-publisher' ),
					self::MAX_FILES
				),
				array( 'status' => 400 )
			);
		}

		$tmps    = array();
		$uploads = array();
		$errors  = array();
		foreach ( $files as $file ) {
			$prepared = $this->prepare_file( is_array( $file ) ? $file : array() );
			if ( is_wp_error( $prepared ) ) {
				$errors[] = ( is_array( $file ) && isset( $file['name'] ) ? (string) $file['name'] . ': ' : '' ) . $prepared->get_error_message();
				continue;
			}
			$tmps[]    = $prepared['tmp_name'];
			$uploads[] = $prepared;
		}

		$created = $service->create(
			$slug,
			array(
				'html'   => self::raw( $input, 'html' ),
				'status' => 'draft' === self::text( $input, 'status' ) ? 'draft' : 'published',
				'files'  => $uploads,
				'mode'   => 'sideload',
			)
		);
		self::cleanup( $tmps );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$messages = array();
		$settings = array();
		if ( null !== $path ) {
			$settings['path'] = $path;
		}
		if ( array_key_exists( 'noindex', $input ) ) {
			$settings['noindex'] = self::flag( $input, 'noindex' );
		}
		if ( ! empty( $settings ) ) {
			$updated = $service->set_meta( $slug, $settings );
			if ( is_wp_error( $updated ) ) {
				$messages[] = sprintf(
					/* translators: %s: error message */
					__( 'The page was published, but its settings could not be saved: %s', 'html-page-publisher' ),
					$updated->get_error_message()
				);
			}
		}

		return array(
			'page'        => $service->get( $slug ),
			'files_added' => array_values( (array) $created['files_added'] ),
			'file_errors' => array_merge( $errors, array_values( (array) $created['file_errors'] ) ),
			'messages'    => $messages,
		);
	}

	/**
	 * Update a page's HTML and/or settings, or rename it.
	 *
	 * @param mixed $input { slug, edits?|html?, status?, path?, noindex?, no_snippets?, new_slug? }.
	 * @return array|WP_Error
	 */
	public function update_page( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$service = $this->service();
		$page    = $service->get( self::text( $input, 'slug' ) );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$slug = $page['slug'];

		$has_edits = array_key_exists( 'edits', $input );
		$has_html  = array_key_exists( 'html', $input );
		$settings  = array();
		foreach ( array( 'status', 'path' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$settings[ $key ] = self::text( $input, $key );
			}
		}
		foreach ( array( 'noindex', 'no_snippets' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$settings[ $key ] = self::flag( $input, $key );
			}
		}
		$new_slug = self::text( $input, 'new_slug' );

		if ( $has_edits && $has_html ) {
			return new WP_Error( 'htmlpp_edits_and_html', __( 'Send either edits or html, not both. Nothing was changed.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( ! $has_edits && ! $has_html && empty( $settings ) && '' === $new_slug ) {
			return new WP_Error( 'htmlpp_nothing_to_update', __( 'Nothing to update: send edits, html, status, path, noindex, no_snippets or new_slug.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		if ( $has_edits ) {
			$result = $service->edit_html( $slug, self::list_value( $input, 'edits' ) );
		} elseif ( $has_html ) {
			$result = $service->update_html( $slug, self::raw( $input, 'html' ) );
		}
		if ( isset( $result ) && is_wp_error( $result ) ) {
			return $result;
		}

		$messages = array();
		if ( ! empty( $settings ) ) {
			$result = $service->set_meta( $slug, $settings );
			if ( is_wp_error( $result ) ) {
				return self::partial( $result, $has_edits || $has_html );
			}
			$messages = array_merge( $messages, (array) $result['messages'] );
		}

		if ( '' !== $new_slug ) {
			$result = $service->rename( $slug, $new_slug );
			if ( is_wp_error( $result ) ) {
				return self::partial( $result, $has_edits || $has_html || ! empty( $settings ) );
			}
			if ( $result['slug'] !== $slug ) {
				$messages[] = sprintf(
					/* translators: %s: previous page slug */
					__( 'The old slug "%s" now redirects to the page.', 'html-page-publisher' ),
					$slug
				);
			}
			$slug = $result['slug'];
		}

		return array(
			'page'     => $service->get( $slug ),
			'messages' => $messages,
		);
	}

	/**
	 * Duplicate a page as a draft.
	 *
	 * @param mixed $input { slug, new_slug }.
	 * @return array|WP_Error
	 */
	public function duplicate_page( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$page  = $this->service()->duplicate( self::text( $input, 'slug' ), self::text( $input, 'new_slug' ) );
		return is_wp_error( $page ) ? $page : array( 'page' => $page );
	}

	/**
	 * List a page's saved versions.
	 *
	 * @param mixed $input { slug }.
	 * @return array|WP_Error
	 */
	public function list_versions( $input = null ) {
		$input    = is_array( $input ) ? $input : array();
		$versions = $this->service()->versions( self::text( $input, 'slug' ) );
		return is_wp_error( $versions ) ? $versions : array( 'versions' => array_values( $versions ) );
	}

	/**
	 * Restore a saved version.
	 *
	 * @param mixed $input { slug, version }.
	 * @return array|WP_Error
	 */
	public function restore_version( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$page  = $this->service()->restore( self::text( $input, 'slug' ), basename( self::text( $input, 'version' ) ) );
		return is_wp_error( $page ) ? $page : array( 'page' => $page );
	}

	/**
	 * Add one file to a page's assets folder.
	 *
	 * @param mixed $input { slug, name, content, encoding? }.
	 * @return array|WP_Error
	 */
	public function add_page_file( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$service = $this->service();
		$page    = $service->get( self::text( $input, 'slug' ) );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$prepared = $this->prepare_file( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$stored = $service->store_file( $page['slug'], $prepared, 'sideload' );
		self::cleanup( array( $prepared['tmp_name'] ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'name'      => $stored,
			'reference' => 'assets/' . $stored,
			'url'       => HTMLPP_Storage::public_asset_url( $page['slug'], $stored ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Write a file sent as text or base64 to a temporary file, in the
	 * $_FILES shape the page service stores from.
	 *
	 * @param array $file { name, content, encoding? }.
	 * @return array|WP_Error File array for HTMLPP_Page_Service::store_file().
	 */
	private function prepare_file( array $file ) {
		$name = isset( $file['name'] ) ? sanitize_file_name( basename( str_replace( '\\', '/', (string) $file['name'] ) ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'htmlpp_bad_file', __( 'Each file needs a name, such as hero.png.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$content = isset( $file['content'] ) && is_string( $file['content'] ) ? $file['content'] : '';
		if ( isset( $file['encoding'] ) && 'base64' === $file['encoding'] ) {
			$content = base64_decode( preg_replace( '/\s+/', '', $content ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary files arrive base64-encoded in JSON.
			if ( false === $content ) {
				return new WP_Error( 'htmlpp_bad_file', __( 'The file content is not valid base64.', 'html-page-publisher' ), array( 'status' => 400 ) );
			}
		}
		if ( '' === $content ) {
			return new WP_Error( 'htmlpp_bad_file', __( 'The file is empty.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$max = (int) wp_max_upload_size();
		if ( $max > 0 && strlen( $content ) > $max ) {
			return new WP_Error(
				'htmlpp_file_too_large',
				sprintf(
					/* translators: %s: maximum file size, e.g. "8 MB" */
					__( 'The file is larger than this site’s upload limit of %s.', 'html-page-publisher' ),
					size_format( $max )
				),
				array( 'status' => 413 )
			);
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( $name );
		if ( ! $tmp || ! HTMLPP_Storage::put_contents( $tmp, $content ) ) {
			if ( $tmp ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'htmlpp_fs', __( 'The file could not be saved to a temporary folder.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}

		return array(
			'name'     => $name,
			'type'     => '',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * Delete temporary files the sideload did not move.
	 *
	 * @param string[] $paths Temporary file paths.
	 */
	private static function cleanup( array $paths ) {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Say that earlier parts of an update were already saved when a later
	 * part failed, so the agent does not resend the whole request blindly.
	 *
	 * @param WP_Error $error   The failure.
	 * @param bool     $partial Whether something was already saved.
	 * @return WP_Error
	 */
	private static function partial( WP_Error $error, $partial ) {
		if ( ! $partial ) {
			return $error;
		}
		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: %s: error message */
				__( '%s The changes listed before this one in the request were already saved.', 'html-page-publisher' ),
				$error->get_error_message()
			),
			$error->get_error_data()
		);
	}

	/**
	 * Public shape of one file from HTMLPP_Storage::list_files().
	 *
	 * @param array $file File row.
	 * @return array
	 */
	private function file_entry( array $file ) {
		return array(
			'name'      => isset( $file['name'] ) ? (string) $file['name'] : '',
			'reference' => isset( $file['reference'] ) ? (string) $file['reference'] : '',
			'url'       => isset( $file['url'] ) ? (string) $file['url'] : '',
			'size'      => isset( $file['size'] ) ? (int) $file['size'] : 0,
		);
	}

	/**
	 * String input value ('' when missing or not a string).
	 *
	 * @param array  $input Input.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function text( array $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
	}

	/**
	 * String input value exactly as sent, for HTML ('' when missing).
	 *
	 * @param array  $input Input.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function raw( array $input, $key ) {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';
	}

	/**
	 * Boolean input value. Core validates input against the schema but does
	 * not convert it, and GET requests send "false" as a string.
	 *
	 * @param array  $input Input.
	 * @param string $key   Key.
	 * @return bool
	 */
	private static function flag( array $input, $key ) {
		return isset( $input[ $key ] ) && rest_sanitize_boolean( $input[ $key ] );
	}

	/**
	 * List input value (empty array when missing).
	 *
	 * @param array  $input Input.
	 * @param string $key   Key.
	 * @return array
	 */
	private static function list_value( array $input, $key ) {
		return isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? array_values( $input[ $key ] ) : array();
	}

	/*
	|--------------------------------------------------------------------------
	| Schemas
	|--------------------------------------------------------------------------
	*/

	/**
	 * Object input schema that rejects unknown keys. The default lets an
	 * ability with no required input run without any.
	 *
	 * @param array    $properties Properties.
	 * @param string[] $required   Required property names.
	 * @return array
	 */
	private function object_schema( array $properties, array $required = array() ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( empty( $required ) ) {
			$schema['default'] = array();
		} else {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * The slug input property.
	 *
	 * @return array
	 */
	private function slug_property() {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'description' => __( 'The page’s slug: lowercase letters, numbers and hyphens, e.g. spring-promo. Other characters are converted.', 'html-page-publisher' ),
		);
	}

	/**
	 * The custom path input property.
	 *
	 * @return array
	 */
	private function path_property() {
		return array(
			'type'        => 'string',
			'description' => __( 'Optional custom URL path: "promo" serves the page at /promo/ instead of its usual URL, "/" makes it the site’s front page, and an empty string clears it. Paths already used by WordPress content are refused.', 'html-page-publisher' ),
		);
	}

	/**
	 * One file in an input.
	 *
	 * @return array
	 */
	private function file_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'File name with its extension, e.g. hero.png or styles.css.', 'html-page-publisher' ),
				),
				'content'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The file’s content: plain text, or base64 when encoding is base64.', 'html-page-publisher' ),
				),
				'encoding' => array(
					'type'        => 'string',
					'enum'        => array( 'text', 'base64' ),
					'description' => __( 'text (default) for SVG, CSS and JavaScript; base64 for images, fonts and other binary files.', 'html-page-publisher' ),
				),
			),
			'required'             => array( 'name', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * One file in an output.
	 *
	 * @return array
	 */
	private function file_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'      => array( 'type' => 'string' ),
				'reference' => array(
					'type'        => 'string',
					'description' => __( 'Path to use in the page’s HTML, e.g. assets/hero.png.', 'html-page-publisher' ),
				),
				'url'       => array(
					'type'        => 'string',
					'description' => __( 'Public URL of the file.', 'html-page-publisher' ),
				),
				'size'      => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Notes about side effects, such as redirects created.
	 *
	 * @return array
	 */
	private function messages_schema() {
		return array(
			'type'        => 'array',
			'description' => __( 'Notes about the change, such as a redirect that was created. Tell the user about them.', 'html-page-publisher' ),
			'items'       => array( 'type' => 'string' ),
		);
	}

	/**
	 * A page, as HTMLPP_Page_Service::describe() returns it. Add-on fields
	 * may appear too, so other properties are allowed.
	 *
	 * @return array
	 */
	private function page_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug'        => array( 'type' => 'string' ),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'The page’s <title>.', 'html-page-publisher' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'published or draft.', 'html-page-publisher' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'Public URL. Visitors can open it only while the page is published.', 'html-page-publisher' ),
				),
				'preview_url' => array(
					'type'        => 'string',
					'description' => __( 'Private preview link for a draft, which works without logging in. Empty when published.', 'html-page-publisher' ),
				),
				'edit_url'    => array(
					'type'        => 'string',
					'description' => __( 'Where an administrator edits the page in the dashboard.', 'html-page-publisher' ),
				),
				'path'        => array(
					'type'        => 'string',
					'description' => __( 'Custom URL path, "/" for the front page, or empty.', 'html-page-publisher' ),
				),
				'noindex'     => array( 'type' => 'boolean' ),
				'no_snippets' => array( 'type' => 'boolean' ),
				'files'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of files besides index.html.', 'html-page-publisher' ),
				),
				'modified'    => array(
					'type'        => 'integer',
					'description' => __( 'Last change (Unix time).', 'html-page-publisher' ),
				),
				'created'     => array(
					'type'        => 'integer',
					'description' => __( 'When the page was created (Unix time).', 'html-page-publisher' ),
				),
			),
		);
	}

	/**
	 * Behaviour hints for clients (MCP readOnlyHint, destructiveHint, …).
	 *
	 * @param bool $read_only   Changes nothing.
	 * @param bool $destructive May overwrite something (a previous version is kept).
	 * @param bool $idempotent  Repeating the call changes nothing more.
	 * @return array
	 */
	private function annotations( $read_only, $destructive, $idempotent ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		);
	}
}

<?php
/**
 * Tests for HTMLPP_Abilities: what is registered, how it is exposed, and
 * what each ability does against a temporary uploads directory.
 *
 * @package HTMLPP
 */

use PHPUnit\Framework\TestCase;

class AbilitiesTest extends TestCase {

	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/**
	 * Abilities under test.
	 *
	 * @var HTMLPP_Abilities
	 */
	private $abilities;

	protected function setUp(): void {
		$GLOBALS['htmlpp_test_options']            = array();
		$GLOBALS['htmlpp_test_post_urls']          = array();
		$GLOBALS['htmlpp_test_abilities']          = array();
		$GLOBALS['htmlpp_test_ability_categories'] = array();
		$GLOBALS['htmlpp_test_caps']               = array(
			'manage_options'  => true,
			'unfiltered_html' => true,
		);
		unset( $GLOBALS['htmlpp_test_max_upload'] );
		remove_all_test_filters();
		$base = wp_upload_dir()['basedir'];
		exec( 'rm -rf ' . escapeshellarg( dirname( $base ) ) );
		mkdir( $base, 0777, true );
		$this->abilities = new HTMLPP_Abilities();
	}

	protected function tearDown(): void {
		$GLOBALS['htmlpp_test_caps'] = array();
		exec( 'rm -rf ' . escapeshellarg( dirname( wp_upload_dir()['basedir'] ) ) );
	}

	/**
	 * Run an ability's execute callback the way core does (input passed through).
	 *
	 * @param string $name  Ability name without the namespace.
	 * @param array  $input Input.
	 * @return mixed
	 */
	private function run_ability( $name, array $input = array() ) {
		$args = $this->abilities->definitions()[ HTMLPP_Abilities::PREFIX . $name ];
		return call_user_func( $args['execute_callback'], $input );
	}

	private function html( $body, $title = 'Spring' ) {
		return '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $body . '</body></html>';
	}

	/*
	|--------------------------------------------------------------------------
	| Registration and exposure
	|--------------------------------------------------------------------------
	*/

	public function test_registers_the_category_and_every_ability_as_public_tools() {
		$this->abilities->register_category();
		$this->abilities->register_abilities();

		$this->assertArrayHasKey( 'html-page-publisher', $GLOBALS['htmlpp_test_ability_categories'] );
		$this->assertSame(
			array(
				'html-page-publisher/list-pages',
				'html-page-publisher/get-page',
				'html-page-publisher/publish-page',
				'html-page-publisher/update-page',
				'html-page-publisher/duplicate-page',
				'html-page-publisher/list-versions',
				'html-page-publisher/restore-version',
				'html-page-publisher/add-page-file',
			),
			array_keys( $GLOBALS['htmlpp_test_abilities'] )
		);

		foreach ( $GLOBALS['htmlpp_test_abilities'] as $name => $args ) {
			$this->assertMatchesRegularExpression( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name, 'Core only accepts lowercase, dashes and one slash.' );
			$this->assertStringNotContainsString( 'delete', $name, 'Deleting is never offered to agents.' );
			$this->assertSame( 'html-page-publisher', $args['category'] );
			$this->assertNotEmpty( $args['label'] );
			$this->assertGreaterThan( 60, strlen( $args['description'] ), "$name needs a description an agent can choose by." );
			$this->assertIsCallable( $args['execute_callback'] );
			$this->assertIsCallable( $args['permission_callback'] );
			$this->assertTrue( $args['meta']['public'], 'WordPress 7.1 and the MCP Adapter read meta.public.' );
			$this->assertTrue( $args['meta']['show_in_rest'], 'WordPress 6.9 and 7.0 read meta.show_in_rest.' );
			$this->assertSame(
				array(
					'public' => true,
					'type'   => 'tool',
				),
				$args['meta']['mcp']
			);
			$this->assertArrayNotHasKey( 'annotations', $args, 'Annotations belong under meta.' );
			$hints = $args['meta']['annotations'];
			$this->assertFalse( $hints['destructive'] && $hints['idempotent'], "$name would need DELETE on core's run route." );
		}
	}

	public function test_annotations_match_what_each_ability_does() {
		$defs     = $this->abilities->definitions();
		$readonly = array();
		$destroy  = array();
		foreach ( $defs as $name => $args ) {
			if ( $args['meta']['annotations']['readonly'] ) {
				$readonly[] = $name;
			}
			if ( $args['meta']['annotations']['destructive'] ) {
				$destroy[] = $name;
			}
		}
		$this->assertSame( array( 'html-page-publisher/list-pages', 'html-page-publisher/get-page', 'html-page-publisher/list-versions' ), $readonly );
		$this->assertSame( array( 'html-page-publisher/update-page', 'html-page-publisher/restore-version' ), $destroy );
	}

	public function test_input_schemas_are_strict_objects_and_outputs_are_objects() {
		foreach ( $this->abilities->definitions() as $name => $args ) {
			$in = $args['input_schema'];
			$this->assertSame( 'object', $in['type'], $name );
			$this->assertFalse( $in['additionalProperties'], "$name should refuse unknown keys." );
			if ( isset( $in['required'] ) ) {
				$this->assertIsArray( $in['required'], 'JSON Schema needs an object-level required list, not per-property flags.' );
				$this->assertEmpty( array_diff( $in['required'], array_keys( $in['properties'] ) ), $name );
			} else {
				$this->assertSame( array(), $in['default'], "$name must run without any input." );
			}
			foreach ( $in['properties'] as $prop => $schema ) {
				$this->assertArrayNotHasKey( 'required', $schema, "$name.$prop: use the object-level list." );
				$this->assertNotEmpty( $schema['description'] ?? ( 'array' === $schema['type'] ? 'items' : '' ), "$name.$prop" );
			}
			$this->assertSame( 'object', $args['output_schema']['type'], "$name: MCP structured output must be an object." );
		}
	}

	public function test_filters_can_hide_abilities_or_skip_registering() {
		add_filter(
			'htmlpp_abilities_public',
			static function ( $public, $name ) {
				return 'html-page-publisher/publish-page' !== $name;
			}
		);
		$defs = $this->abilities->definitions();
		$this->assertFalse( $defs['html-page-publisher/publish-page']['meta']['public'] );
		$this->assertFalse( $defs['html-page-publisher/publish-page']['meta']['show_in_rest'] );
		$this->assertFalse( $defs['html-page-publisher/publish-page']['meta']['mcp']['public'] );
		$this->assertTrue( $defs['html-page-publisher/get-page']['meta']['public'] );

		add_filter(
			'htmlpp_register_abilities',
			static function () {
				return false;
			}
		);
		$this->abilities->register_abilities();
		$this->assertSame( array(), $GLOBALS['htmlpp_test_abilities'] );
	}

	public function test_the_settings_switch_turns_registration_off() {
		$GLOBALS['htmlpp_test_abilities']                         = array();
		$GLOBALS['htmlpp_test_options'][ HTMLPP_Settings::OPTION ] = array( 'ai_enabled' => false );
		$this->assertFalse( HTMLPP_Abilities::enabled() );
		$this->abilities->register_abilities();
		$this->assertSame( array(), $GLOBALS['htmlpp_test_abilities'] );

		// Code can still turn it back on.
		add_filter(
			'htmlpp_register_abilities',
			static function () {
				return true;
			}
		);
		$this->assertTrue( HTMLPP_Abilities::enabled() );
		unset( $GLOBALS['htmlpp_test_options'][ HTMLPP_Settings::OPTION ] );
	}

	public function test_state_summarises_the_status() {
		$base = array(
			'supported'  => true,
			'enabled'    => true,
			'connectors' => array(),
		);
		$this->assertSame( 'setup', HTMLPP_Abilities::state( $base ) );
		$this->assertSame( 'ready', HTMLPP_Abilities::state( array( 'connectors' => array( 'MCP Adapter' ) ) + $base ) );
		$this->assertSame( 'off', HTMLPP_Abilities::state( array( 'enabled' => false ) + $base ) );
		$this->assertSame( 'unsupported', HTMLPP_Abilities::state( array( 'supported' => false ) + $base ) );
	}

	public function test_local_sites_are_detected() {
		$this->assertTrue( HTMLPP_Abilities::is_local_site( 'https://example.com', 'local' ) );
		foreach ( array( 'http://localhost:8888', 'https://plugins-dev.local', 'http://shop.test', 'http://192.168.1.20', 'http://127.0.0.1', 'http://intranet' ) as $url ) {
			$this->assertTrue( HTMLPP_Abilities::is_local_site( $url ), $url );
		}
		foreach ( array( 'https://example.com', 'https://staging.example.co.uk', 'http://93.184.216.34' ) as $url ) {
			$this->assertFalse( HTMLPP_Abilities::is_local_site( $url ), $url );
		}
	}

	public function test_server_name_comes_from_the_host() {
		$this->assertSame( 'example-com', HTMLPP_Abilities::server_name( 'https://www.example.com/blog' ) );
		$this->assertSame( 'plugins-dev-local', HTMLPP_Abilities::server_name( 'https://plugins-dev.local' ) );
		$this->assertSame( 'my-site', HTMLPP_Abilities::server_name( '' ) );
	}

	public function test_connect_snippets_carry_the_address_user_and_placeholders() {
		$endpoint = 'https://example.com/wp-json/mcp/mcp-adapter-default-server';
		$out      = HTMLPP_Abilities::connect_snippets( $endpoint, 'editor', 'example-com' );

		$this->assertSame(
			'claude mcp add --transport http example-com ' . $endpoint . ' --header "Authorization: Basic {{AUTH}}"',
			$out['claude-code']
		);

		$config = json_decode( $out['claude-desktop'], true );
		$server = $config['mcpServers']['example-com'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( $endpoint, $server['env']['WP_API_URL'] );
		$this->assertSame( 'editor', $server['env']['WP_API_USERNAME'] );
		$this->assertSame( '{{PASSWORD}}', $server['env']['WP_API_PASSWORD'] );
		$this->assertStringNotContainsString( '\/', $out['cursor'], 'URLs stay readable in the JSON.' );
	}

	public function test_reads_need_manage_options_and_writes_also_need_unfiltered_html() {
		$defs  = $this->abilities->definitions();
		$check = static function ( $name ) use ( $defs ) {
			return call_user_func( $defs[ HTMLPP_Abilities::PREFIX . $name ]['permission_callback'], array() );
		};

		$this->assertTrue( $check( 'get-page' ) );
		$this->assertTrue( $check( 'publish-page' ) );

		$GLOBALS['htmlpp_test_caps'] = array( 'manage_options' => true );
		$this->assertTrue( $check( 'list-pages' ) );
		$this->assertTrue( $check( 'list-versions' ) );
		foreach ( array( 'publish-page', 'update-page', 'duplicate-page', 'restore-version', 'add-page-file' ) as $write ) {
			$this->assertFalse( $check( $write ), "$write must require unfiltered_html, like the REST API." );
		}

		$GLOBALS['htmlpp_test_caps'] = array();
		$this->assertFalse( $check( 'list-pages' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Publish
	|--------------------------------------------------------------------------
	*/

	public function test_publish_creates_a_live_page() {
		$out = $this->run_ability(
			'publish-page',
			array(
				'slug' => 'Spring Promo',
				'html' => $this->html( '<h1>Hello</h1>' ),
			)
		);
		$this->assertSame( 'spring-promo', $out['page']['slug'] );
		$this->assertSame( 'published', $out['page']['status'] );
		$this->assertSame( 'https://example.com/pages/spring-promo/', $out['page']['url'] );
		$this->assertIsInt( $out['page']['created'], 'Page details, not the create() flag.' );
		$this->assertSame( array(), $out['files_added'] );
		$this->assertSame( array(), $out['file_errors'] );
		$this->assertSame( array(), $out['messages'] );
	}

	public function test_publish_as_draft_with_path_noindex_and_files() {
		$out = $this->run_ability(
			'publish-page',
			array(
				'slug'    => 'launch',
				'html'    => $this->html( '<img src="assets/hero.png">' ),
				'status'  => 'draft',
				'path'    => 'launch-day',
				'noindex' => 'true',
				'files'   => array(
					array(
						'name'     => 'hero.png',
						'content'  => self::PNG,
						'encoding' => 'base64',
					),
					array(
						'name'    => 'site.css',
						'content' => 'body{margin:0}',
					),
					array(
						'name'    => 'shell.php',
						'content' => '<?php echo 1;',
					),
				),
			)
		);
		$this->assertSame( 'draft', $out['page']['status'] );
		$this->assertStringContainsString( 'htmlpp_preview=', $out['page']['preview_url'] );
		$this->assertSame( 'launch-day', $out['page']['path'] );
		$this->assertTrue( $out['page']['noindex'], 'The string "true" from a GET-style client counts as true.' );
		$this->assertSame( array( 'hero.png', 'site.css' ), $out['files_added'] );
		$this->assertCount( 1, $out['file_errors'] );
		$this->assertStringStartsWith( 'shell.php: ', $out['file_errors'][0] );

		$assets = wp_upload_dir()['basedir'] . '/html-page-publisher/launch/assets/';
		$this->assertSame( base64_decode( self::PNG ), file_get_contents( $assets . 'hero.png' ) );
		$this->assertSame( 'body{margin:0}', file_get_contents( $assets . 'site.css' ) );
		$this->assertFileDoesNotExist( $assets . 'shell.php' );
	}

	public function test_publish_stores_the_html_as_an_upload_would() {
		$html = $this->html( "\n  <pre>  indented\n    code</pre>\n" );
		$this->run_ability(
			'publish-page',
			array(
				'slug' => 'exact',
				'html' => $html,
			)
		);
		$this->assertSame( HTMLPP_Sanitizer::sanitize( $html, 'exact', 'upload' ), htmlpp()->pages->get( 'exact', true )['html'] );
		$this->assertStringContainsString( "<pre>  indented\n    code</pre>", htmlpp()->pages->get( 'exact', true )['html'] );
	}

	public function test_publish_never_overwrites_and_points_to_update() {
		$this->run_ability(
			'publish-page',
			array(
				'slug' => 'p',
				'html' => $this->html( 'v1' ),
			)
		);
		$again = $this->run_ability(
			'publish-page',
			array(
				'slug' => 'p',
				'html' => $this->html( 'v2' ),
			)
		);
		$this->assertSame( 'htmlpp_exists', $again->get_error_code() );
		$this->assertStringContainsString( 'html-page-publisher/update-page', $again->get_error_message() );
		$this->assertStringContainsString( 'v1', htmlpp()->pages->get( 'p', true )['html'] );
	}

	public function test_publish_with_a_refused_path_writes_nothing() {
		$GLOBALS['htmlpp_test_post_urls']['https://example.com/about/'] = 12;

		$out = $this->run_ability(
			'publish-page',
			array(
				'slug' => 'about-promo',
				'html' => $this->html( 'x' ),
				'path' => 'about',
			)
		);
		$this->assertSame( 'htmlpp_path_taken', $out->get_error_code() );
		$this->assertFalse( htmlpp()->pages->exists( 'about-promo' ) );
	}

	public function test_publish_limits_attached_files() {
		$files = array_fill(
			0,
			HTMLPP_Abilities::MAX_FILES + 1,
			array(
				'name'    => 'a.css',
				'content' => 'a{}',
			)
		);
		$out   = $this->run_ability(
			'publish-page',
			array(
				'slug'  => 'many',
				'html'  => $this->html( 'x' ),
				'files' => $files,
			)
		);
		$this->assertSame( 'htmlpp_too_many_files', $out->get_error_code() );
		$this->assertFalse( htmlpp()->pages->exists( 'many' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Read
	|--------------------------------------------------------------------------
	*/

	public function test_list_pages_filters_by_status() {
		htmlpp()->pages->create( 'live', array( 'html' => $this->html( 'a' ) ) );
		htmlpp()->pages->create(
			'hidden',
			array(
				'html'   => $this->html( 'b' ),
				'status' => 'draft',
			)
		);

		$this->assertCount( 2, $this->run_ability( 'list-pages' )['pages'] );
		$drafts = $this->run_ability( 'list-pages', array( 'status' => 'draft' ) )['pages'];
		$this->assertSame( array( 'hidden' ), array_column( $drafts, 'slug' ) );
		$live = $this->run_ability( 'list-pages', array( 'status' => 'published' ) )['pages'];
		$this->assertSame( array( 'live' ), array_column( $live, 'slug' ) );
	}

	public function test_get_page_returns_html_and_files_only_when_asked() {
		htmlpp()->pages->create( 'g', array( 'html' => $this->html( '<p>body</p>' ) ) );
		$this->run_ability(
			'add-page-file',
			array(
				'slug'    => 'g',
				'name'    => 'a.css',
				'content' => 'a{}',
			)
		);

		$plain = $this->run_ability(
			'get-page',
			array(
				'slug'         => 'g',
				'include_html' => 'false',
			)
		);
		$this->assertSame( array( 'page' ), array_keys( $plain ), 'The string "false" is false.' );
		$this->assertArrayNotHasKey( 'html', $plain['page'] );

		$full = $this->run_ability(
			'get-page',
			array(
				'slug'          => 'g',
				'include_html'  => true,
				'include_files' => true,
			)
		);
		$this->assertSame( array( 'page', 'html', 'files' ), array_keys( $full ) );
		$this->assertStringContainsString( '<p>body</p>', $full['html'] );
		$this->assertSame( 'assets/a.css', $full['files'][0]['reference'] );
		$this->assertSame( 'https://example.com/pages/g/assets/a.css', $full['files'][0]['url'] );

		$this->assertSame( 'htmlpp_not_found', $this->run_ability( 'get-page', array( 'slug' => 'nope' ) )->get_error_code() );
	}

	/*
	|--------------------------------------------------------------------------
	| Update
	|--------------------------------------------------------------------------
	*/

	public function test_update_with_edits_changes_only_the_matched_text() {
		htmlpp()->pages->create( 'e', array( 'html' => $this->html( '<h1>Old headline</h1><p>Keep me</p>' ) ) );

		$out = $this->run_ability(
			'update-page',
			array(
				'slug'  => 'e',
				'edits' => array(
					array(
						'find'    => 'Old headline',
						'replace' => 'New headline',
					),
					array(
						'find'    => '<p>Keep me</p>',
						'replace' => '<p>Keep me</p><p>Added</p>',
					),
				),
			)
		);
		$this->assertSame( 'e', $out['page']['slug'] );
		$html = htmlpp()->pages->get( 'e', true )['html'];
		$this->assertStringContainsString( '<h1>New headline</h1><p>Keep me</p><p>Added</p>', $html );
		$this->assertCount( 1, htmlpp()->pages->versions( 'e' ), 'The previous version is kept.' );
	}

	public function test_update_edits_are_all_or_nothing() {
		htmlpp()->pages->create( 'e', array( 'html' => $this->html( '<li>a</li><li>a</li><b>x</b>' ) ) );
		$before = htmlpp()->pages->get( 'e', true )['html'];

		$ambiguous = $this->run_ability(
			'update-page',
			array(
				'slug'  => 'e',
				'edits' => array(
					array(
						'find'    => '<b>x</b>',
						'replace' => '<b>y</b>',
					),
					array(
						'find'    => '<li>a</li>',
						'replace' => '<li>b</li>',
					),
				),
			)
		);
		$this->assertSame( 'htmlpp_edit_ambiguous', $ambiguous->get_error_code() );
		$this->assertStringContainsString( 'Edit 2', $ambiguous->get_error_message() );
		$this->assertStringContainsString( '2 times', $ambiguous->get_error_message() );

		$missing = $this->run_ability(
			'update-page',
			array(
				'slug'  => 'e',
				'edits' => array(
					array(
						'find'    => 'not there',
						'replace' => '',
					),
				),
			)
		);
		$this->assertSame( 'htmlpp_edit_not_found', $missing->get_error_code() );

		$this->assertSame( $before, htmlpp()->pages->get( 'e', true )['html'] );
		$this->assertCount( 0, htmlpp()->pages->versions( 'e' ) );
	}

	public function test_update_refuses_html_with_edits_and_empty_requests() {
		htmlpp()->pages->create( 'e', array( 'html' => $this->html( 'x' ) ) );

		$both = $this->run_ability(
			'update-page',
			array(
				'slug'  => 'e',
				'html'  => $this->html( 'y' ),
				'edits' => array(
					array(
						'find'    => 'x',
						'replace' => 'z',
					),
				),
			)
		);
		$this->assertSame( 'htmlpp_edits_and_html', $both->get_error_code() );
		$this->assertSame( 'htmlpp_nothing_to_update', $this->run_ability( 'update-page', array( 'slug' => 'e' ) )->get_error_code() );
		$this->assertSame( 'htmlpp_not_found', $this->run_ability( 'update-page', array( 'slug' => 'nope' ) )->get_error_code() );
	}

	public function test_update_replaces_html_changes_settings_and_renames() {
		htmlpp()->pages->create( 'old', array( 'html' => $this->html( 'v1' ) ) );

		$out = $this->run_ability(
			'update-page',
			array(
				'slug'        => 'old',
				'html'        => $this->html( 'v2' ),
				'status'      => 'draft',
				'noindex'     => true,
				'no_snippets' => '1',
				'new_slug'    => 'new',
			)
		);
		$this->assertSame( 'new', $out['page']['slug'] );
		$this->assertSame( 'draft', $out['page']['status'] );
		$this->assertTrue( $out['page']['noindex'] );
		$this->assertTrue( $out['page']['no_snippets'] );
		$this->assertStringContainsString( 'redirects', implode( ' ', $out['messages'] ) );
		$this->assertStringContainsString( 'v2', htmlpp()->pages->get( 'new', true )['html'] );
		$this->assertFalse( htmlpp()->pages->exists( 'old' ) );
	}

	public function test_update_says_what_was_already_saved_when_a_later_step_fails() {
		htmlpp()->pages->create( 'a', array( 'html' => $this->html( 'v1' ) ) );
		htmlpp()->pages->create( 'b', array( 'html' => $this->html( 'b' ) ) );

		$out = $this->run_ability(
			'update-page',
			array(
				'slug'     => 'a',
				'html'     => $this->html( 'v2' ),
				'new_slug' => 'b',
			)
		);
		$this->assertSame( 'htmlpp_exists', $out->get_error_code() );
		$this->assertStringContainsString( 'already saved', $out->get_error_message() );
		$this->assertStringContainsString( 'v2', htmlpp()->pages->get( 'a', true )['html'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Duplicate, versions, files
	|--------------------------------------------------------------------------
	*/

	public function test_duplicate_makes_a_draft_copy() {
		htmlpp()->pages->create( 'src', array( 'html' => $this->html( 'x' ) ) );
		$out = $this->run_ability(
			'duplicate-page',
			array(
				'slug'     => 'src',
				'new_slug' => 'src-b',
			)
		);
		$this->assertSame( 'src-b', $out['page']['slug'] );
		$this->assertSame( 'draft', $out['page']['status'] );
		$this->assertSame( 'published', htmlpp()->pages->get( 'src' )['status'] );
	}

	public function test_list_and_restore_versions() {
		htmlpp()->pages->create( 'v', array( 'html' => $this->html( 'first' ) ) );
		htmlpp()->pages->update_html( 'v', $this->html( 'second' ) );

		$versions = $this->run_ability( 'list-versions', array( 'slug' => 'v' ) )['versions'];
		$this->assertCount( 1, $versions );
		$this->assertSame( array( 'name', 'modified', 'size' ), array_keys( $versions[0] ) );

		$out = $this->run_ability(
			'restore-version',
			array(
				'slug'    => 'v',
				'version' => $versions[0]['name'],
			)
		);
		$this->assertSame( 'v', $out['page']['slug'] );
		$this->assertStringContainsString( 'first', htmlpp()->pages->get( 'v', true )['html'] );
		$this->assertCount( 2, htmlpp()->pages->versions( 'v' ), 'The replaced version is kept, so a restore can be undone.' );

		$bad = $this->run_ability(
			'restore-version',
			array(
				'slug'    => 'v',
				'version' => '../../wp-config.php',
			)
		);
		$this->assertTrue( is_wp_error( $bad ) );
	}

	public function test_add_page_file_stores_text_and_base64_and_reports_its_name() {
		htmlpp()->pages->create( 'f', array( 'html' => $this->html( 'x' ) ) );

		$first = $this->run_ability(
			'add-page-file',
			array(
				'slug'    => 'f',
				'name'    => 'img/logo.svg',
				'content' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>',
			)
		);
		$this->assertSame( 'logo.svg', $first['name'], 'Only the base name is used.' );
		$this->assertSame( 'assets/logo.svg', $first['reference'] );
		$this->assertSame( 'https://example.com/pages/f/assets/logo.svg', $first['url'] );
		$stored = file_get_contents( wp_upload_dir()['basedir'] . '/html-page-publisher/f/assets/logo.svg' );
		$this->assertStringNotContainsString( 'script', $stored );

		$second = $this->run_ability(
			'add-page-file',
			array(
				'slug'     => 'f',
				'name'     => 'logo.svg',
				'content'  => base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><rect width="2" height="2"/></svg>' ),
				'encoding' => 'base64',
			)
		);
		$this->assertSame( 'logo-1.svg', $second['name'], 'A taken name gets a number; the response says which.' );
	}

	public function test_add_page_file_refuses_bad_input() {
		htmlpp()->pages->create( 'f', array( 'html' => $this->html( 'x' ) ) );
		$temp_files = static function () {
			return array_filter( (array) glob( sys_get_temp_dir() . '/htmlpp*' ), 'is_file' );
		};
		$before     = $temp_files();
		$add        = function ( array $file ) {
			return $this->run_ability( 'add-page-file', array( 'slug' => 'f' ) + $file );
		};

		$this->assertSame(
			'htmlpp_bad_file',
			$add(
				array(
					'name'     => 'a.png',
					'content'  => '***',
					'encoding' => 'base64',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'htmlpp_bad_file',
			$add(
				array(
					'name'    => '',
					'content' => 'x',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'htmlpp_file_rejected',
			$add(
				array(
					'name'    => 'x.php',
					'content' => '<?php',
				)
			)->get_error_code()
		);

		$GLOBALS['htmlpp_test_max_upload'] = 4;
		$this->assertSame(
			'htmlpp_file_too_large',
			$add(
				array(
					'name'    => 'big.css',
					'content' => 'a{color:red}',
				)
			)->get_error_code()
		);

		$this->assertSame( 0, htmlpp()->pages->get( 'f' )['files'] );
		$this->assertSame( array_values( $before ), array_values( $temp_files() ), 'No temporary files are left behind.' );
	}
}

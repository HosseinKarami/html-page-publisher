<?php
/**
 * Settings → AI assistants: guided setup for Claude, ChatGPT and other apps.
 *
 * @package HTMLPP
 *
 * @var array  $ai              Result of HTMLPP_Abilities::status().
 * @var string $htmlpp_ai_state unsupported, off, setup or ready.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$htmlpp_user      = wp_get_current_user();
$htmlpp_endpoint  = rest_url( HTMLPP_Abilities::MCP_ADAPTER_ROUTE );
$htmlpp_snippets  = HTMLPP_Abilities::connect_snippets( $htmlpp_endpoint, $htmlpp_user->user_login, HTMLPP_Abilities::server_name( home_url() ) );
$htmlpp_is_local  = HTMLPP_Abilities::is_local_site( home_url(), wp_get_environment_type() );
$htmlpp_app_pw    = function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( $htmlpp_user );
$htmlpp_adapter   = 'mcp-adapter/mcp-adapter.php';
$htmlpp_web_ready = (bool) array_intersect( array( 'easy-mcp-ai/easy-mcp-ai.php', 'vibe-ai/vibe-ai.php' ), $ai['active'] );
$htmlpp_desktop   = 'claude-code claude-desktop cursor';
$htmlpp_web       = 'claude-web chatgpt';
$htmlpp_guide_url = 'https://github.com/HosseinKarami/html-page-publisher/blob/main/docs/connect-ai-assistants.md';
$htmlpp_profile   = admin_url( 'profile.php#application-passwords-section' );
$htmlpp_apps      = array(
	'claude-code'    => array( 'Claude Code', __( 'Terminal', 'html-page-publisher' ) ),
	'claude-desktop' => array( 'Claude Desktop', __( 'Mac or Windows app', 'html-page-publisher' ) ),
	'cursor'         => array( 'Cursor', __( 'Code editor', 'html-page-publisher' ) ),
	'claude-web'     => array( 'Claude', __( 'Web and phone', 'html-page-publisher' ) ),
	'chatgpt'        => array( 'ChatGPT', __( 'Web', 'html-page-publisher' ) ),
);
$htmlpp_badges    = array(
	'ready'       => __( 'Ready', 'html-page-publisher' ),
	'setup'       => __( 'Needs setup', 'html-page-publisher' ),
	'off'         => __( 'Off', 'html-page-publisher' ),
	'unsupported' => __( 'Not available', 'html-page-publisher' ),
);
$htmlpp_prompts   = array(
	__( 'List my HTML pages.', 'html-page-publisher' ),
	__( 'Publish this page on my site as a draft at the slug spring-promo.', 'html-page-publisher' ),
	__( 'Change the headline to “Spring sale ends Friday”, then publish it.', 'html-page-publisher' ),
	__( 'Undo your last change to spring-promo.', 'html-page-publisher' ),
);
$htmlpp_can       = array(
	__( 'List pages and read their HTML and files', 'html-page-publisher' ),
	__( 'Publish new pages, or save them as drafts', 'html-page-publisher' ),
	__( 'Change part of a page, or replace it', 'html-page-publisher' ),
	__( 'Publish, unpublish and rename pages', 'html-page-publisher' ),
	__( 'Set a custom path or noindex', 'html-page-publisher' ),
	__( 'Add images and other files', 'html-page-publisher' ),
	__( 'Duplicate pages', 'html-page-publisher' ),
	__( 'Restore an earlier version', 'html-page-publisher' ),
);
$htmlpp_check     = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
$htmlpp_svg_kses  = array(
	'svg'      => array(
		'viewbox'         => true,
		'width'           => true,
		'height'          => true,
		'fill'            => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linecap'  => true,
		'stroke-linejoin' => true,
		'aria-hidden'     => true,
	),
	'polyline' => array( 'points' => true ),
);
?>

<section class="htmlpp-card htmlpp-connect">
	<div class="htmlpp-card__header">
		<div>
			<h2 class="htmlpp-card__title"><?php esc_html_e( 'Connect an AI assistant', 'html-page-publisher' ); ?></h2>
			<p class="htmlpp-card__subtitle"><?php esc_html_e( 'Claude, ChatGPT and other AI apps can publish and edit your pages. Pick your app and follow the steps.', 'html-page-publisher' ); ?></p>
		</div>
		<span class="htmlpp-pill htmlpp-pill--<?php echo esc_attr( $htmlpp_ai_state ); ?>"><?php echo esc_html( $htmlpp_badges[ $htmlpp_ai_state ] ); ?></span>
	</div>

	<div class="htmlpp-card__body">

	<?php if ( 'unsupported' === $htmlpp_ai_state ) : ?>

		<div class="htmlpp-alert htmlpp-alert--info">
			<div class="htmlpp-alert__body">
				<p class="htmlpp-alert__title"><?php esc_html_e( 'Needs WordPress 6.9 or later', 'html-page-publisher' ); ?></p>
				<p>
					<?php
					printf(
						/* translators: %s: WordPress version */
						esc_html__( 'This site runs WordPress %s. AI assistants connect through the Abilities API, which WordPress added in 6.9. Until you update, a script or agent can still publish through the plugin’s REST API or WP-CLI.', 'html-page-publisher' ),
						esc_html( get_bloginfo( 'version' ) )
					);
					?>
				</p>
				<?php if ( current_user_can( 'update_core' ) ) : ?>
					<p class="htmlpp-alert__actions"><a class="htmlpp-button-ghost htmlpp-button--sm" href="<?php echo esc_url( self_admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Update WordPress', 'html-page-publisher' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'off' === $htmlpp_ai_state ) : ?>

		<div class="htmlpp-alert htmlpp-alert--info">
			<div class="htmlpp-alert__body">
				<p class="htmlpp-alert__title"><?php esc_html_e( 'Page tools are turned off', 'html-page-publisher' ); ?></p>
				<p>
					<?php
					if ( $ai['allowed'] ) {
						esc_html_e( 'Code on this site turns them off with the htmlpp_register_abilities filter.', 'html-page-publisher' );
					} else {
						esc_html_e( 'AI assistants can’t see or use this plugin’s tools. Turn them on below to connect an app.', 'html-page-publisher' );
					}
					?>
				</p>
			</div>
		</div>

	<?php else : ?>

		<ul class="htmlpp-checks">
			<li class="is-ok">
				<?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?>
				<?php
				/* translators: %s: WordPress version */
				echo esc_html( sprintf( __( 'WordPress %s', 'html-page-publisher' ), get_bloginfo( 'version' ) ) );
				?>
			</li>
			<li class="is-ok">
				<?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?>
				<?php esc_html_e( 'Page tools offered', 'html-page-publisher' ); ?>
			</li>
			<li class="<?php echo $ai['connectors'] ? 'is-ok' : 'is-todo'; ?>">
				<?php if ( $ai['connectors'] ) : ?>
					<?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?>
					<?php
					/* translators: %s: names of active MCP plugins */
					echo esc_html( sprintf( __( '%s active', 'html-page-publisher' ), implode( ', ', $ai['connectors'] ) ) );
					?>
				<?php else : ?>
					<span class="htmlpp-checks__todo" aria-hidden="true"></span>
					<?php esc_html_e( 'No MCP plugin yet', 'html-page-publisher' ); ?>
				<?php endif; ?>
			</li>
		</ul>

		<fieldset class="htmlpp-apps">
			<legend class="htmlpp-apps__legend"><?php esc_html_e( 'Which app do you use?', 'html-page-publisher' ); ?></legend>
			<div class="htmlpp-apps__grid">
				<?php foreach ( $htmlpp_apps as $htmlpp_key => $htmlpp_app ) : ?>
					<label class="htmlpp-app">
						<input type="radio" name="htmlpp-app" value="<?php echo esc_attr( $htmlpp_key ); ?>" data-name="<?php echo esc_attr( $htmlpp_app[0] ); ?>" <?php checked( 'claude-code', $htmlpp_key ); ?> />
						<span class="htmlpp-app__name"><?php echo esc_html( $htmlpp_app[0] ); ?></span>
						<span class="htmlpp-app__where"><?php echo esc_html( $htmlpp_app[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<ol class="htmlpp-steps">

			<?php /* Desktop and coding apps: MCP Adapter + application password. */ ?>
			<li class="htmlpp-step<?php echo in_array( $htmlpp_adapter, $ai['active'], true ) ? ' is-done' : ''; ?>" data-apps="<?php echo esc_attr( $htmlpp_desktop ); ?>">
				<span class="htmlpp-step__marker" aria-hidden="true"><?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?></span>
				<div class="htmlpp-step__body">
					<div class="htmlpp-step__head">
						<h3 class="htmlpp-step__title"><?php esc_html_e( 'Install the MCP Adapter plugin', 'html-page-publisher' ); ?></h3>
						<?php HTMLPP_Admin::mcp_plugin_action( $htmlpp_adapter, 'mcp-adapter', 'MCP Adapter', $ai ); ?>
					</div>
					<p><?php esc_html_e( 'The official WordPress plugin that lets AI apps connect to your site.', 'html-page-publisher' ); ?></p>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="<?php echo esc_attr( $htmlpp_desktop ); ?>" data-htmlpp-password-step>
				<span class="htmlpp-step__marker" aria-hidden="true"><?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title">
						<?php
						printf(
							/* translators: %s: AI app name, e.g. Claude Code */
							esc_html__( 'Create a password for %s', 'html-page-publisher' ),
							'<span data-htmlpp-app-name>Claude Code</span>'
						);
						?>
					</h3>
					<p>
						<?php
						printf(
							/* translators: %s: WordPress username */
							esc_html__( 'The app signs in to WordPress as you (%s) with an application password. You can revoke it any time under Users → Profile.', 'html-page-publisher' ),
							'<strong>' . esc_html( $htmlpp_user->user_login ) . '</strong>'
						);
						?>
					</p>
					<?php if ( $htmlpp_app_pw ) : ?>
						<div class="htmlpp-credentials">
							<button type="button" class="htmlpp-button-primary" data-htmlpp-create-password><?php esc_html_e( 'Create password', 'html-page-publisher' ); ?></button>
							<span class="htmlpp-credentials__or"><?php esc_html_e( 'or paste one you already have', 'html-page-publisher' ); ?></span>
							<input type="text" class="htmlpp-credentials__input" data-htmlpp-paste-password autocomplete="off" spellcheck="false" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" aria-label="<?php esc_attr_e( 'Existing application password', 'html-page-publisher' ); ?>" />
						</div>
						<p class="htmlpp-credentials__status" data-htmlpp-password-status role="status" aria-live="polite"></p>
					<?php else : ?>
						<p class="htmlpp-muted">
							<?php
							printf(
								/* translators: %s: link to the user profile */
								esc_html__( 'Application passwords aren’t available for your account. WordPress turns them off on sites without HTTPS, and some security plugins turn them off too. When they’re on, create one under %s and paste it into the setup text below.', 'html-page-publisher' ),
								'<a href="' . esc_url( $htmlpp_profile ) . '">' . esc_html__( 'Users → Profile', 'html-page-publisher' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="claude-code">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Add your site to Claude Code', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'Run this in your terminal. Then start Claude Code again and type /mcp to check the connection.', 'html-page-publisher' ); ?></p>
					<?php HTMLPP_Admin::code_block( __( 'Terminal', 'html-page-publisher' ), $htmlpp_snippets['claude-code'], 'htmlpp-snippet-claude-code' ); ?>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="claude-desktop">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Add your site to Claude Desktop', 'html-page-publisher' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: link to nodejs.org */
							esc_html__( 'This needs %s on your computer. In Claude Desktop open Settings → Developer → Edit Config, add the entry below inside "mcpServers" (or use it as the whole file), save, and restart Claude.', 'html-page-publisher' ),
							'<a href="https://nodejs.org" target="_blank" rel="noopener">Node.js</a>'
						);
						?>
					</p>
					<?php HTMLPP_Admin::code_block( 'claude_desktop_config.json', $htmlpp_snippets['claude-desktop'], 'htmlpp-snippet-claude-desktop' ); ?>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="cursor">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Add your site to Cursor', 'html-page-publisher' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: link to nodejs.org */
							esc_html__( 'This needs %s on your computer. Add the entry below to ~/.cursor/mcp.json (inside "mcpServers" if the file already has servers), then reload Cursor.', 'html-page-publisher' ),
							'<a href="https://nodejs.org" target="_blank" rel="noopener">Node.js</a>'
						);
						?>
					</p>
					<?php HTMLPP_Admin::code_block( 'mcp.json', $htmlpp_snippets['cursor'], 'htmlpp-snippet-cursor' ); ?>
				</div>
			</li>

			<?php /* Web apps: an MCP plugin with an OAuth sign-in. */ ?>
			<?php if ( $htmlpp_is_local ) : ?>
				<li class="htmlpp-step htmlpp-step--note" data-apps="<?php echo esc_attr( $htmlpp_web ); ?>">
					<div class="htmlpp-alert htmlpp-alert--warning">
						<div class="htmlpp-alert__body">
							<p class="htmlpp-alert__title"><?php esc_html_e( 'This site isn’t on the internet', 'html-page-publisher' ); ?></p>
							<p><?php esc_html_e( 'Claude on the web and ChatGPT connect from their own servers, so they can’t reach a site that runs on your computer. Pick Claude Code, Claude Desktop or Cursor to try it here, or set this up on your live or staging site.', 'html-page-publisher' ); ?></p>
						</div>
					</div>
				</li>
			<?php endif; ?>

			<li class="htmlpp-step<?php echo $htmlpp_web_ready ? ' is-done' : ''; ?>" data-apps="<?php echo esc_attr( $htmlpp_web ); ?>">
				<span class="htmlpp-step__marker" aria-hidden="true"><?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Install an MCP plugin with sign-in', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'These apps only accept a sign-in through your site (OAuth), which the MCP Adapter doesn’t offer. Either of these plugins adds it.', 'html-page-publisher' ); ?></p>
					<ul class="htmlpp-options">
						<li>
							<div>
								<strong>Easy MCP AI</strong>
								<span><?php esc_html_e( 'Runs the connection on your own site.', 'html-page-publisher' ); ?></span>
							</div>
							<?php HTMLPP_Admin::mcp_plugin_action( 'easy-mcp-ai/easy-mcp-ai.php', 'easy-mcp-ai', 'Easy MCP AI', $ai ); ?>
						</li>
						<li>
							<div>
								<strong>WPVibe</strong>
								<span><?php esc_html_e( 'Connects through wpvibe.ai. Its free plan has a daily limit.', 'html-page-publisher' ); ?></span>
							</div>
							<?php HTMLPP_Admin::mcp_plugin_action( 'vibe-ai/vibe-ai.php', 'vibe-ai', 'WPVibe', $ai ); ?>
						</li>
					</ul>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="<?php echo esc_attr( $htmlpp_web ); ?>">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Turn on this plugin’s tools', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'In Easy MCP AI’s tool settings, switch on the tools whose names start with html-page-publisher/. It leaves tools that change pages, such as update-page and restore-version, off until you turn them on. WPVibe offers them without any setting.', 'html-page-publisher' ); ?></p>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="claude-web">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Connect Claude', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'Click Connect to Claude in Easy MCP AI’s dashboard. Or, in Claude, open Customize → Connectors → Add custom connector and paste the server URL the plugin shows. Sign in to WordPress and approve. Claude’s Free plan allows one custom connector.', 'html-page-publisher' ); ?></p>
				</div>
			</li>

			<li class="htmlpp-step" data-apps="chatgpt">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Connect ChatGPT', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'This needs ChatGPT Plus, Pro, Business, Enterprise or Education on the web. Click Connect to ChatGPT in Easy MCP AI’s dashboard, which opens Developer mode. Paste the URL, keep Authentication on OAuth, click Create and approve on your site. With WPVibe, follow the steps it shows.', 'html-page-publisher' ); ?></p>
				</div>
			</li>

			<?php /* Every app. */ ?>
			<li class="htmlpp-step">
				<span class="htmlpp-step__marker" aria-hidden="true"></span>
				<div class="htmlpp-step__body">
					<h3 class="htmlpp-step__title"><?php esc_html_e( 'Try it', 'html-page-publisher' ); ?></h3>
					<p><?php esc_html_e( 'Start a new chat and ask, for example:', 'html-page-publisher' ); ?></p>
					<ul class="htmlpp-prompts">
						<?php foreach ( $htmlpp_prompts as $htmlpp_prompt ) : ?>
							<li>
								<span>“<?php echo esc_html( $htmlpp_prompt ); ?>”</span>
								<?php HTMLPP_Admin::copy_button( '', $htmlpp_prompt ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</li>
		</ol>

		<div class="htmlpp-connect__footer">
			<p data-apps="<?php echo esc_attr( $htmlpp_desktop ); ?>">
				<?php esc_html_e( 'Server address:', 'html-page-publisher' ); ?>
				<code id="htmlpp-mcp-endpoint"><?php echo esc_html( $htmlpp_endpoint ); ?></code>
				<?php HTMLPP_Admin::copy_button( '#htmlpp-mcp-endpoint' ); ?>
			</p>
			<?php if ( $htmlpp_is_local && 0 === strpos( $htmlpp_endpoint, 'https://' ) ) : ?>
				<p class="htmlpp-muted" data-apps="<?php echo esc_attr( $htmlpp_desktop ); ?>"><?php esc_html_e( 'If the app can’t connect to this local site because of its HTTPS certificate, use the site’s http:// address in the setup text instead.', 'html-page-publisher' ); ?></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $htmlpp_guide_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full setup guide and troubleshooting', 'html-page-publisher' ); ?> <span aria-hidden="true">↗</span></a></p>
		</div>

	<?php endif; ?>

	</div>
</section>

<?php if ( 'unsupported' !== $htmlpp_ai_state ) : ?>
	<section class="htmlpp-card" id="htmlpp-ai-access">
		<div class="htmlpp-card__header">
			<div>
				<h2 class="htmlpp-card__title"><?php esc_html_e( 'What assistants can do', 'html-page-publisher' ); ?></h2>
				<p class="htmlpp-card__subtitle"><?php esc_html_e( 'An assistant acts as the WordPress user it signs in as, which must be an administrator.', 'html-page-publisher' ); ?></p>
			</div>
		</div>
		<div class="htmlpp-card__body">
			<ul class="htmlpp-can">
				<?php foreach ( $htmlpp_can as $htmlpp_item ) : ?>
					<li><?php echo wp_kses( $htmlpp_check, $htmlpp_svg_kses ); ?><?php echo esc_html( $htmlpp_item ); ?></li>
				<?php endforeach; ?>
				<li class="is-never">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					<?php esc_html_e( 'Delete pages or files (never offered)', 'html-page-publisher' ); ?>
				</li>
			</ul>
			<p class="htmlpp-muted"><?php esc_html_e( 'Every change to a page’s HTML keeps the previous version, so you can always undo it from the page’s history.', 'html-page-publisher' ); ?></p>

			<form method="post" action="options.php" class="htmlpp-access-form">
				<?php settings_fields( 'htmlpp_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( HTMLPP_Settings::OPTION ); ?>[ai_enabled]" value="0" />
				<label class="htmlpp-switch">
					<input type="checkbox" name="<?php echo esc_attr( HTMLPP_Settings::OPTION ); ?>[ai_enabled]" value="1" role="switch" <?php checked( $ai['allowed'] ); ?> />
					<span class="htmlpp-switch__track" aria-hidden="true"></span>
					<span class="htmlpp-switch__text">
						<strong><?php esc_html_e( 'Offer page tools to AI assistants', 'html-page-publisher' ); ?></strong>
						<span><?php esc_html_e( 'When off, MCP plugins no longer list this plugin’s tools. The REST API and WP-CLI keep working.', 'html-page-publisher' ); ?></span>
					</span>
				</label>
				<button type="submit" class="htmlpp-button-ghost"><?php esc_html_e( 'Save', 'html-page-publisher' ); ?></button>
			</form>
		</div>
	</section>
<?php endif; ?>

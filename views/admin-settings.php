<?php
/**
 * Settings page template.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$htmlpp_back_url = admin_url( 'admin.php?page=html-page-publisher' );
?>
<div class="wrap htmlpp-page">

	<div class="htmlpp-hero">
		<div class="htmlpp-hero__icon" aria-hidden="true">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<line x1="21" y1="4" x2="14" y2="4"/>
				<line x1="10" y1="4" x2="3" y2="4"/>
				<line x1="21" y1="12" x2="12" y2="12"/>
				<line x1="8" y1="12" x2="3" y2="12"/>
				<line x1="21" y1="20" x2="16" y2="20"/>
				<line x1="12" y1="20" x2="3" y2="20"/>
				<line x1="14" y1="2" x2="14" y2="6"/>
				<line x1="8" y1="10" x2="8" y2="14"/>
				<line x1="16" y1="18" x2="16" y2="22"/>
			</svg>
		</div>
		<div class="htmlpp-hero__body">
			<h1 class="htmlpp-hero__title"><?php esc_html_e( 'Settings', 'html-page-publisher' ); ?></h1>
			<p class="htmlpp-hero__subtitle">
				<?php esc_html_e( 'URL structure, optional subdomain routing, and storage protection.', 'html-page-publisher' ); ?>
			</p>
		</div>
		<div class="htmlpp-hero__actions">
			<a href="<?php echo esc_url( $htmlpp_back_url ); ?>" class="htmlpp-button htmlpp-button-ghost">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<line x1="19" y1="12" x2="5" y2="12"/>
					<polyline points="12 19 5 12 12 5"/>
				</svg>
				<?php esc_html_e( 'Back to Pages', 'html-page-publisher' ); ?>
			</a>
			<a href="<?php echo esc_url( HTMLPP_Admin::donate_url( 'admin-header-donate' ) ); ?>" class="htmlpp-button htmlpp-button-donate" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M17 8h1a4 4 0 0 1 0 8h-1"/>
					<path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/>
					<line x1="6" y1="2" x2="6" y2="4"/>
					<line x1="10" y1="2" x2="10" y2="4"/>
					<line x1="14" y1="2" x2="14" y2="4"/>
				</svg>
				<?php esc_html_e( 'Donate', 'html-page-publisher' ); ?>
			</a>
		</div>
	</div>

	<div class="htmlpp-content">

		<?php // WordPress relocates admin_notices to just after .wp-header-end; keep them below the hero. ?>
		<div class="wp-header-end"></div>

		<?php settings_errors(); ?>

		<?php
		$htmlpp_status = isset( $protection['status'] ) ? $protection['status'] : 'unknown';
		$htmlpp_code   = isset( $protection['code'] ) ? (int) $protection['code'] : 0;
		$htmlpp_nginx  = 'location ~* ^' . wp_parse_url( HTMLPP_Storage::base_url(), PHP_URL_PATH ) . "(-backups)?/ {\n\tdeny all;\n}";
		?>
		<div class="htmlpp-card htmlpp-protection htmlpp-protection--<?php echo esc_attr( $htmlpp_status ); ?>">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Storage protection', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle"><?php esc_html_e( 'Pages should only be reachable at their public URL, never directly from the uploads folder.', 'html-page-publisher' ); ?></p>
				</div>
				<a href="<?php echo esc_url( $recheck_url ); ?>" class="htmlpp-button htmlpp-button-ghost"><?php esc_html_e( 'Re-check', 'html-page-publisher' ); ?></a>
			</div>
			<div class="htmlpp-card__body">
				<?php if ( 'blocked' === $htmlpp_status ) : ?>
					<p class="htmlpp-protection__status">
						<strong><?php esc_html_e( 'Direct access is blocked.', 'html-page-publisher' ); ?></strong>
						<?php
						printf(
							/* translators: %d: HTTP status code */
							esc_html__( 'Requests to the uploads folder return HTTP %d.', 'html-page-publisher' ),
							(int) $htmlpp_code
						);
						?>
					</p>
				<?php elseif ( 'open' === $htmlpp_status ) : ?>
					<p class="htmlpp-protection__status">
						<strong><?php esc_html_e( 'Direct access is open.', 'html-page-publisher' ); ?></strong>
						<?php esc_html_e( 'Your web server ignores the .htaccess rules the plugin writes (this is normal on nginx). Pages still work at their public URL, but the raw files can also be fetched from the uploads folder, bypassing the plugin’s caching and any access rules. Add this to your nginx server block and reload nginx:', 'html-page-publisher' ); ?>
					</p>
					<pre class="htmlpp-code-block"><?php echo esc_html( $htmlpp_nginx ); ?></pre>
				<?php else : ?>
					<p class="htmlpp-protection__status">
						<strong><?php esc_html_e( 'Could not verify.', 'html-page-publisher' ); ?></strong>
						<?php
						if ( 0 === $htmlpp_code ) {
							esc_html_e( 'The site could not reach its own uploads URL (loopback requests appear to be blocked on this host).', 'html-page-publisher' );
						} elseif ( 401 === $htmlpp_code ) {
							esc_html_e( 'The uploads URL is behind HTTP authentication (HTTP 401), so the plugin’s own rule could not be observed.', 'html-page-publisher' );
						} elseif ( 404 === $htmlpp_code ) {
							esc_html_e( 'The uploads URL returned HTTP 404 and a control file could not be reached either, so the uploads URL may not point at this site’s uploads folder (custom upload path, CDN or offload plugin).', 'html-page-publisher' );
						} elseif ( $htmlpp_code >= 300 && $htmlpp_code < 400 ) {
							printf(
								/* translators: %d: HTTP status code */
								esc_html__( 'The uploads URL redirected (HTTP %d), so the plugin could not observe its own rule.', 'html-page-publisher' ),
								(int) $htmlpp_code
							);
						} else {
							printf(
								/* translators: %d: HTTP status code */
								esc_html__( 'The uploads URL returned HTTP %d.', 'html-page-publisher' ),
								(int) $htmlpp_code
							);
						}
						?>
						<?php esc_html_e( 'On Apache and LiteSpeed the plugin’s .htaccess rules apply automatically; on nginx add this to your server block:', 'html-page-publisher' ); ?>
					</p>
					<pre class="htmlpp-code-block"><?php echo esc_html( $htmlpp_nginx ); ?></pre>
				<?php endif; ?>
			</div>
		</div>

		<div class="htmlpp-card">
			<div class="htmlpp-card__body">
				<form method="post" action="options.php">
					<?php
					settings_fields( 'htmlpp_settings_group' );
					do_settings_sections( 'htmlpp_settings' );
					submit_button( __( 'Save Settings', 'html-page-publisher' ) );
					?>
				</form>
			</div>
		</div>

	</div><!-- /.htmlpp-content -->
</div>

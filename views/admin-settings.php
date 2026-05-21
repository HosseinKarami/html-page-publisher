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
				<?php esc_html_e( 'Configure URL structure and optional subdomain routing.', 'html-page-publisher' ); ?>
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

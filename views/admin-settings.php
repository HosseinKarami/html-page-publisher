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
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="3"/>
				<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
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
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<line x1="19" y1="12" x2="5" y2="12"/>
					<polyline points="12 19 5 12 12 5"/>
				</svg>
				<?php esc_html_e( 'Back to Pages', 'html-page-publisher' ); ?>
			</a>
		</div>
	</div>

	<div class="htmlpp-content">

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

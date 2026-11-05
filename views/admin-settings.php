<?php
/**
 * Settings page template.
 *
 * @package HTMLPP
 *
 * @var string $tab         Current tab: general, tracking or ai.
 * @var array  $protection  Result of HTMLPP_Storage::direct_access_status().
 * @var string $recheck_url URL that runs the storage protection check again.
 * @var array  $ai          Result of HTMLPP_Abilities::status().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$htmlpp_back_url = admin_url( 'admin.php?page=html-page-publisher' );
$htmlpp_ai_state = HTMLPP_Abilities::state( $ai );
$htmlpp_tab_icon = array(
	'general'  => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
	'tracking' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
	'ai'       => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9Z"/>',
);
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
				<?php esc_html_e( 'Page URLs, tracking code and AI assistants.', 'html-page-publisher' ); ?>
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

		<?php
		// Notices WordPress would print above the hero (update nag, other plugins'
		// banners) print here; WordPress then moves standard ones after .wp-header-end.
		HTMLPP_Admin::print_notices();
		?>
		<div class="wp-header-end"></div>

		<?php settings_errors(); ?>

		<nav class="htmlpp-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'html-page-publisher' ); ?>">
			<?php foreach ( HTMLPP_Admin::settings_tabs() as $htmlpp_slug => $htmlpp_label ) : ?>
				<a href="<?php echo esc_url( HTMLPP_Admin::settings_url( $htmlpp_slug ) ); ?>" class="htmlpp-tab<?php echo $htmlpp_slug === $tab ? ' is-active' : ''; ?>"<?php echo $htmlpp_slug === $tab ? ' aria-current="page"' : ''; ?>>
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $htmlpp_tab_icon[ $htmlpp_slug ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG markup defined above. ?></svg>
					<?php echo esc_html( $htmlpp_label ); ?>
					<?php if ( 'ai' === $htmlpp_slug && in_array( $htmlpp_ai_state, array( 'ready', 'setup' ), true ) ) : ?>
						<span class="htmlpp-dot htmlpp-dot--<?php echo esc_attr( $htmlpp_ai_state ); ?>" aria-hidden="true"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="htmlpp-tab-panel">
			<?php require HTMLPP_PLUGIN_DIR . 'views/settings/' . $tab . '.php'; ?>
		</div>

	</div><!-- /.htmlpp-content -->
</div>

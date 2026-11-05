<?php
/**
 * Settings → General: page URLs and storage protection.
 *
 * @package HTMLPP
 *
 * @var array  $protection  Result of HTMLPP_Storage::direct_access_status().
 * @var string $recheck_url URL that runs the storage protection check again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$htmlpp_status = isset( $protection['status'] ) ? $protection['status'] : 'unknown';
$htmlpp_code   = isset( $protection['code'] ) ? (int) $protection['code'] : 0;
?>

<?php if ( 'blocked' !== $htmlpp_status ) : ?>
	<div class="htmlpp-alert htmlpp-alert--<?php echo 'open' === $htmlpp_status ? 'warning' : 'info'; ?>">
		<span class="htmlpp-alert__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>
				<line x1="12" y1="8" x2="12" y2="12"/>
				<line x1="12" y1="16" x2="12.01" y2="16"/>
			</svg>
		</span>
		<div class="htmlpp-alert__body">
			<?php if ( 'open' === $htmlpp_status ) : ?>
				<p class="htmlpp-alert__title"><?php esc_html_e( 'Page files can be opened directly', 'html-page-publisher' ); ?></p>
				<p><?php esc_html_e( 'Your web server doesn’t apply the .htaccess rules the plugin writes. That’s usual on nginx, and on Apache or LiteSpeed set to AllowOverride None. Pages still work at their public URL, but anyone who guesses a page’s folder can open its files directly, drafts included.', 'html-page-publisher' ); ?></p>
				<p><?php esc_html_e( 'On nginx, add this to your server block and reload. On Apache, allow .htaccess overrides for the uploads folder or add the same rule to your virtual host.', 'html-page-publisher' ); ?></p>
			<?php else : ?>
				<p class="htmlpp-alert__title"><?php esc_html_e( 'Couldn’t check whether page files are protected', 'html-page-publisher' ); ?></p>
				<p>
					<?php
					if ( 0 === $htmlpp_code ) {
						esc_html_e( 'The site couldn’t reach its own uploads folder. This host seems to block requests a site makes to itself.', 'html-page-publisher' );
					} elseif ( 401 === $htmlpp_code ) {
						esc_html_e( 'The uploads folder is behind a password (HTTP 401), so the plugin couldn’t see its own rule working.', 'html-page-publisher' );
					} elseif ( 404 === $htmlpp_code ) {
						esc_html_e( 'The uploads address returned HTTP 404, and so did a test file. It may not point at this site’s uploads folder (a custom upload path, a CDN or an offload plugin).', 'html-page-publisher' );
					} elseif ( $htmlpp_code >= 300 && $htmlpp_code < 400 ) {
						printf(
							/* translators: %d: HTTP status code */
							esc_html__( 'The uploads address redirected (HTTP %d), so the plugin couldn’t see its own rule working.', 'html-page-publisher' ),
							(int) $htmlpp_code
						);
					} else {
						printf(
							/* translators: %d: HTTP status code */
							esc_html__( 'The uploads address returned HTTP %d.', 'html-page-publisher' ),
							(int) $htmlpp_code
						);
					}
					?>
					<?php esc_html_e( 'On Apache and LiteSpeed the plugin’s .htaccess rules apply by themselves. On nginx, add this to your server block:', 'html-page-publisher' ); ?>
				</p>
			<?php endif; ?>
			<?php HTMLPP_Admin::code_block( __( 'nginx', 'html-page-publisher' ), HTMLPP_Storage::nginx_rule(), 'htmlpp-nginx-rule' ); ?>
			<p class="htmlpp-alert__actions">
				<a href="<?php echo esc_url( $recheck_url ); ?>" class="htmlpp-button-ghost htmlpp-button--sm"><?php esc_html_e( 'Check again', 'html-page-publisher' ); ?></a>
			</p>
		</div>
	</div>
<?php endif; ?>

<form method="post" action="options.php" class="htmlpp-settings-form">
	<?php
	settings_fields( 'htmlpp_settings_group' );
	HTMLPP_Settings::render_sections( HTMLPP_Settings::PAGE_GENERAL );
	?>
	<div class="htmlpp-savebar">
		<button type="submit" class="htmlpp-button-primary"><?php esc_html_e( 'Save changes', 'html-page-publisher' ); ?></button>
	</div>
</form>

<?php if ( 'blocked' === $htmlpp_status ) : ?>
	<div class="htmlpp-statusline">
		<span class="htmlpp-statusline__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>
				<polyline points="9 12 11 14 15 10"/>
			</svg>
		</span>
		<p>
			<strong><?php esc_html_e( 'Page files are protected.', 'html-page-publisher' ); ?></strong>
			<?php
			printf(
				/* translators: %d: HTTP status code */
				esc_html__( 'Requests straight to the uploads folder are refused (HTTP %d), so pages open only at their public URL.', 'html-page-publisher' ),
				(int) ( $htmlpp_code ? $htmlpp_code : 403 )
			);
			?>
		</p>
		<a href="<?php echo esc_url( $recheck_url ); ?>"><?php esc_html_e( 'Check again', 'html-page-publisher' ); ?></a>
	</div>
<?php endif; ?>

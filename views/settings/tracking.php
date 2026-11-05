<?php
/**
 * Settings → Tracking & SEO: code on every page and the canonical link.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="options.php" class="htmlpp-settings-form">
	<?php
	settings_fields( 'htmlpp_settings_group' );
	HTMLPP_Settings::render_sections( HTMLPP_Settings::PAGE_TRACKING );
	?>
	<div class="htmlpp-savebar">
		<button type="submit" class="htmlpp-button-primary"><?php esc_html_e( 'Save changes', 'html-page-publisher' ); ?></button>
	</div>
</form>

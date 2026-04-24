<?php
/**
 * HTML sanitization for uploaded files.
 *
 * Strips runtime/tooling artifacts injected by AI export tools (e.g. Claude
 * artifacts via the "omelette" runtime) so the published page is clean static
 * HTML. Extensible via the `htmlpp_sanitize_html` filter.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTMLPP_Sanitizer {

	/**
	 * Sanitize HTML before storing to disk.
	 *
	 * @param string $html Raw HTML from the uploaded file.
	 * @return string
	 */
	public static function sanitize( $html ) {
		$html = (string) $html;

		// Strip style/script tags injected by Claude artifact export runtime.
		$html = preg_replace(
			'/<style\s+data-omelette-injected[^>]*>.*?<\/style>/is',
			'',
			$html
		);
		$html = preg_replace(
			'/<script\s+data-omelette-injected[^>]*>.*?<\/script>/is',
			'',
			$html
		);

		// Strip the "tweaks" dev-only control panel and its wrappers.
		$html = preg_replace(
			'/<div\s+class="tweaks"[^>]*id="tweaks"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>/is',
			'',
			$html
		);
		$html = preg_replace(
			'/<script\s+data-cfasync[^>]*>.*?<\/script>/is',
			'',
			$html
		);

		// Strip inline applyTweaks() runtime block.
		$html = preg_replace(
			'/\/\/ ---------- Tweakable defaults ----------.*?applyTweaks\(\);\s*<\/script>/is',
			'</script>',
			$html
		);
		$html = preg_replace( '/<script>\s*<\/script>/', '', (string) $html );

		$html = trim( (string) $html );

		/**
		 * Filter the sanitized HTML before it is written to disk.
		 *
		 * Add custom cleanup rules here (e.g. to strip other export runtimes).
		 *
		 * @param string $html Sanitized HTML.
		 */
		return apply_filters( 'htmlpp_sanitize_html', $html );
	}
}

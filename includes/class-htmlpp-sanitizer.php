<?php
/**
 * HTML sanitization for uploaded files.
 *
 * Built-in rules strip the runtime wrappers that Claude Design injects into
 * its "export as standalone HTML" output (its tweaks panel and the helper
 * script/style blocks that drive it), producing clean static HTML. HTML from
 * other sources passes through unchanged. The rule set is filterable so other
 * export formats can be handled — and individual rules disabled — without
 * forking the plugin.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips export-time runtime wrappers from uploaded HTML.
 */
class HTMLPP_Sanitizer {

	/**
	 * Built-in cleanup rules, keyed by a stable name so they can be removed
	 * or overridden via the `htmlpp_sanitizer_rules` filter.
	 *
	 * Each rule is `array( 'pattern' => <PCRE>, 'replacement' => <string> )`.
	 *
	 * @param string $slug    Page slug being written ('' if unknown).
	 * @param string $context 'upload', 'edit' or '' (unknown).
	 * @return array<string, array{pattern:string, replacement:string}>
	 */
	public static function rules( $slug = '', $context = '' ) {
		$rules = array(
			// Claude Design: injected runtime <style> and <script> blocks.
			'claude-design-injected-style'  => array(
				'pattern'     => '/<style\s+data-omelette-injected[^>]*>.*?<\/style>/is',
				'replacement' => '',
			),
			'claude-design-injected-script' => array(
				'pattern'     => '/<script\s+data-omelette-injected[^>]*>.*?<\/script>/is',
				'replacement' => '',
			),
			// Claude Design: the "tweaks" dev-only control panel and its wrappers.
			'claude-design-tweaks-panel'    => array(
				'pattern'     => '/<div\s+class="tweaks"[^>]*id="tweaks"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>/is',
				'replacement' => '',
			),
			// Claude Design: the runtime loader emitted with a data-cfasync attribute.
			'claude-design-cfasync-script'  => array(
				'pattern'     => '/<script\s+data-cfasync[^>]*>.*?<\/script>/is',
				'replacement' => '',
			),
			// Claude Design: the inline applyTweaks() block.
			'claude-design-apply-tweaks'    => array(
				'pattern'     => '/\/\/ ---------- Tweakable defaults ----------.*?applyTweaks\(\);\s*<\/script>/is',
				'replacement' => '</script>',
			),
			// Leftover empty <script></script> pairs from the rules above.
			'empty-script-tags'             => array(
				'pattern'     => '/<script>\s*<\/script>/',
				'replacement' => '',
			),
		);

		/**
		 * Filter the sanitizer rule set.
		 *
		 * Add rules for other AI export formats, or unset a built-in rule by
		 * its key (e.g. `unset( $rules['claude-design-cfasync-script'] )`
		 * if your pages legitimately use Cloudflare Rocket Loader attributes).
		 *
		 * @param array  $rules   Rules keyed by name; each has 'pattern' and 'replacement'.
		 * @param string $slug    Page slug being written ('' if unknown).
		 * @param string $context 'upload', 'edit' or ''.
		 */
		$rules = apply_filters( 'htmlpp_sanitizer_rules', $rules, $slug, $context );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Sanitize HTML before storing to disk.
	 *
	 * @param string $html    Raw HTML from the uploaded file.
	 * @param string $slug    Page slug being written ('' if unknown).
	 * @param string $context 'upload', 'edit' or ''.
	 * @return string
	 */
	public static function sanitize( $html, $slug = '', $context = '' ) {
		$html = (string) $html;

		foreach ( self::rules( $slug, $context ) as $name => $rule ) {
			if ( empty( $rule['pattern'] ) ) {
				continue;
			}
			$replacement = isset( $rule['replacement'] ) ? (string) $rule['replacement'] : '';
			$html        = self::replace( $rule['pattern'], $replacement, $html );
		}

		$html = trim( $html );

		/**
		 * Filter the sanitized HTML before it is written to disk.
		 *
		 * Runs after the rule set. Useful for one-off transformations such as
		 * injecting a snippet or rewriting asset paths.
		 *
		 * @param string $html    Sanitized HTML.
		 * @param string $slug    Page slug being written ('' if unknown).
		 * @param string $context 'upload', 'edit' or ''.
		 */
		$filtered = apply_filters( 'htmlpp_sanitize_html', $html, $slug, $context );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * A preg_replace() that never loses content.
	 *
	 * PHP's preg_replace() returns NULL when PCRE fails (backtrack/recursion limits
	 * on very large exports, invalid UTF-8, …). Feeding that NULL into the
	 * next rule would silently turn the page into an empty string, so on any
	 * failure the input is returned unchanged.
	 *
	 * @param string $pattern     PCRE pattern.
	 * @param string $replacement Replacement string.
	 * @param string $subject     Input HTML.
	 * @return string
	 */
	private static function replace( $pattern, $replacement, $subject ) {
		$result = @preg_replace( $pattern, $replacement, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid user-supplied patterns must not emit warnings; the failure is handled below.

		if ( null === $result || PREG_NO_ERROR !== preg_last_error() ) {
			return $subject;
		}

		return $result;
	}
}

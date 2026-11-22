<?php
/**
 * Link preview and search fields: a page's title, description and preview
 * image, as search engines and apps such as LinkedIn, Slack and WhatsApp
 * read them.
 *
 * Applied when the page is served; the stored HTML file never changes.
 * A field that is filled in wins over the page's own tag. With the fields
 * empty, the page keeps its own tags, and any preview tags it lacks
 * (og:title, og:description, og:url, og:type, twitter:card) are filled in
 * from its own <title> and description.
 *
 * Pure string logic, unit-tested without WordPress.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a page's link preview tags.
 */
class HTMLPP_Seo {

	/**
	 * Longest title kept, in characters.
	 */
	const TITLE_MAX = 200;

	/**
	 * Longest description kept, in characters.
	 */
	const DESCRIPTION_MAX = 500;

	/**
	 * Tags a field replaces: field => meta keys removed from the page.
	 */
	const REPLACES = array(
		'title'       => array( 'og:title', 'twitter:title' ),
		'description' => array( 'description', 'og:description', 'twitter:description' ),
		'image'       => array( 'og:image', 'og:image:url', 'og:image:secure_url', 'og:image:width', 'og:image:height', 'og:image:type', 'og:image:alt', 'twitter:image', 'twitter:image:src', 'twitter:image:alt' ),
	);

	/**
	 * Image tags whose relative URL is made absolute (apps that fetch a
	 * preview cannot resolve a relative one).
	 */
	const IMAGE_KEYS = array( 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src' );

	/**
	 * Apply the fields to a page's HTML.
	 *
	 * @param string $html    Page HTML.
	 * @param array  $fields  'title', 'description', 'image' (absolute URL): '' leaves the page's own.
	 * @param array  $context 'url': the page's public URL for og:url ('' to skip).
	 *                        'base': URL a relative og:image in the page resolves against ('' to leave it).
	 * @return array{html:string, head:string} The HTML with replaced tags
	 *               removed and the title changed, and the tags to add to its head.
	 */
	public static function prepare( $html, array $fields, array $context = array() ) {
		$html  = (string) $html;
		$title = isset( $fields['title'] ) ? self::clean( $fields['title'], self::TITLE_MAX ) : '';
		$desc  = isset( $fields['description'] ) ? self::clean( $fields['description'], self::DESCRIPTION_MAX ) : '';
		$image = isset( $fields['image'] ) ? trim( (string) $fields['image'] ) : '';
		$url   = isset( $context['url'] ) ? trim( (string) $context['url'] ) : '';
		$base  = isset( $context['base'] ) ? trim( (string) $context['base'] ) : '';

		$end  = self::head_end( $html );
		$head = substr( $html, 0, $end );
		$page = self::read( $head );

		$remove = array();
		if ( '' !== $title ) {
			$remove = array_merge( $remove, self::REPLACES['title'] );
		}
		if ( '' !== $desc ) {
			$remove = array_merge( $remove, self::REPLACES['description'] );
		}
		if ( '' !== $image ) {
			$remove = array_merge( $remove, self::REPLACES['image'] );
		}

		// Edit the head from the last change to the first, so earlier
		// offsets stay valid.
		$edits = array();
		foreach ( $page['metas'] as $meta ) {
			if ( in_array( $meta['key'], $remove, true ) ) {
				$edits[] = array( $meta['offset'], strlen( $meta['tag'] ), '' );
			} elseif ( '' !== $base && in_array( $meta['key'], self::IMAGE_KEYS, true ) && '' !== $meta['content'] && ! self::is_absolute( $meta['content'] ) ) {
				$edits[] = array( $meta['offset'], strlen( $meta['tag'] ), self::with_content( $meta['tag'], self::absolute( $meta['content'], $base ) ) );
			}
		}
		if ( '' !== $title && null !== $page['title_tag'] ) {
			$edits[] = array( $page['title_tag'][0], $page['title_tag'][1], '<title>' . self::esc( $title ) . '</title>' );
		}
		usort(
			$edits,
			static function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $edits as $edit ) {
			$head = substr_replace( $head, $edit[2], $edit[0], $edit[1] );
		}

		$has = static function ( $key ) use ( $page, $remove ) {
			return isset( $page['keys'][ $key ] ) && ! in_array( $key, $remove, true );
		};

		$title_value = '' !== $title ? $title : $page['title'];
		$desc_value  = '' !== $desc ? $desc : $page['description'];

		$tags = array();
		if ( '' !== $title && null === $page['title_tag'] ) {
			$tags[] = '<title>' . self::esc( $title ) . '</title>';
		}
		if ( '' !== $desc ) {
			$tags[] = self::meta( 'name', 'description', $desc );
		}

		$og = array();
		if ( ! $has( 'og:title' ) && '' !== $title_value ) {
			$og[] = self::meta( 'property', 'og:title', $title_value );
		}
		if ( ! $has( 'og:description' ) && '' !== $desc_value ) {
			$og[] = self::meta( 'property', 'og:description', $desc_value );
		}
		if ( '' !== $image ) {
			$og[] = self::meta( 'property', 'og:image', $image );
		}

		// The rest only completes a preview: a page with no title, no
		// description and no image gets nothing.
		$has_image   = '' !== $image || $has( 'og:image' ) || $has( 'twitter:image' );
		$has_preview = ! empty( $og ) || $has( 'og:title' ) || $has( 'og:description' ) || $has_image;
		if ( $has_preview ) {
			if ( ! $has( 'og:type' ) ) {
				array_unshift( $og, self::meta( 'property', 'og:type', 'website' ) );
			}
			if ( '' !== $url && ! $has( 'og:url' ) ) {
				$og[] = self::meta( 'property', 'og:url', $url );
			}
			if ( ! $has( 'twitter:card' ) ) {
				$og[] = self::meta( 'name', 'twitter:card', $has_image ? 'summary_large_image' : 'summary' );
			}
		}

		return array(
			'html' => $head . substr( $html, $end ),
			'head' => implode( "\n", array_merge( $tags, $og ) ),
		);
	}

	/**
	 * The title, description and preview image a page sets itself.
	 *
	 * @param string $html Page HTML.
	 * @param string $base URL a relative image resolves against ('' to leave it).
	 * @return array{title:string, description:string, image:string}
	 */
	public static function page_values( $html, $base = '' ) {
		$html = (string) $html;
		$page = self::read( substr( $html, 0, self::head_end( $html ) ) );

		$image = '';
		foreach ( array( 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src' ) as $key ) {
			if ( ! empty( $page['keys'][ $key ] ) ) {
				$image = $page['keys'][ $key ];
				break;
			}
		}
		if ( '' !== $image && '' !== $base && ! self::is_absolute( $image ) ) {
			$image = self::absolute( $image, $base );
		}

		$description = $page['description'];
		if ( '' === $description && ! empty( $page['keys']['og:description'] ) ) {
			$description = $page['keys']['og:description'];
		}

		return array(
			'title'       => $page['title'],
			'description' => $description,
			'image'       => $image,
		);
	}

	/**
	 * Tidy a field value: plain text on one line, at most $max characters.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length.
	 * @return string
	 */
	public static function clean( $value, $max ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $value, 0, $max, 'UTF-8' ) );
		}
		return trim( substr( $value, 0, $max ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Where the page's head ends: its </head>, else its <body>, else the end.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	private static function head_end( $html ) {
		$pos = HTMLPP_Renderer::find_tag_outside_scripts( $html, '</head>' );
		if ( false !== $pos ) {
			return $pos;
		}
		if ( preg_match( '/<body\b/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $m[0][1];
		}
		return strlen( $html );
	}

	/**
	 * Title and meta tags in a head.
	 *
	 * @param string $head Head HTML.
	 * @return array{title:string, title_tag:array|null, description:string, keys:array<string,string>, metas:array}
	 */
	private static function read( $head ) {
		// Blank out comments, scripts and styles with spaces, so tags in
		// them are not read and offsets still line up.
		$masked = '';
		foreach ( HTMLPP_References::segments( $head ) as $segment ) {
			$masked .= 'markup' === $segment[0] ? $segment[1] : str_repeat( ' ', strlen( $segment[1] ) );
		}

		$result = array(
			'title'       => '',
			'title_tag'   => null,
			'description' => '',
			'keys'        => array(),
			'metas'       => array(),
		);

		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title\s*>/is', $masked, $m, PREG_OFFSET_CAPTURE ) ) {
			$result['title_tag'] = array( $m[0][1], strlen( $m[0][0] ) );
			$result['title']     = self::clean( $m[1][0], 1000 );
		}

		if ( preg_match_all( '/<meta\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $masked, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$attributes = HTMLPP_References::attributes( $m[1][0] );
				$key        = isset( $attributes['property'] ) ? $attributes['property'] : ( isset( $attributes['name'] ) ? $attributes['name'] : '' );
				$key        = strtolower( trim( $key ) );
				if ( '' === $key ) {
					continue;
				}
				$content = isset( $attributes['content'] ) ? trim( $attributes['content'] ) : '';

				$result['metas'][] = array(
					'key'     => $key,
					'content' => $content,
					'tag'     => $m[0][0],
					'offset'  => $m[0][1],
				);
				if ( ! isset( $result['keys'][ $key ] ) ) {
					$result['keys'][ $key ] = $content;
				}
				if ( 'description' === $key && '' === $result['description'] ) {
					$result['description'] = self::clean( $content, 1000 );
				}
			}
		}

		return $result;
	}

	/**
	 * A meta tag.
	 *
	 * @param string $attribute name or property.
	 * @param string $key       Its value.
	 * @param string $content   Content.
	 * @return string
	 */
	private static function meta( $attribute, $key, $content ) {
		return '<meta ' . $attribute . '="' . self::esc( $key ) . '" content="' . self::esc( $content ) . '">';
	}

	/**
	 * A meta tag with its content attribute changed.
	 *
	 * @param string $tag     Tag.
	 * @param string $content New content.
	 * @return string
	 */
	private static function with_content( $tag, $content ) {
		$new = preg_replace( '/(\scontent\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i', '$1"' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), self::esc( $content ) ) . '"', $tag, 1 );
		return null === $new ? $tag : $new;
	}

	/**
	 * Whether a URL is absolute (has a scheme, or starts with //).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_absolute( $url ) {
		return (bool) preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|//)#i', (string) $url );
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $url  Relative URL.
	 * @param string $base Absolute base URL.
	 * @return string
	 */
	public static function absolute( $url, $base ) {
		$url = trim( (string) $url );
		if ( self::is_absolute( $url ) ) {
			return $url;
		}
		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*)([^?\#]*)#i', (string) $base, $b ) ) {
			return $url;
		}
		$origin = $b[1];
		if ( '/' === substr( $url, 0, 1 ) ) {
			return $origin . $url;
		}
		$dir = substr( $b[2], 0, (int) strrpos( $b[2], '/' ) );

		$parts = array();
		foreach ( explode( '/', $dir . '/' . $url ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return $origin . '/' . implode( '/', $parts );
	}

	/**
	 * Escape text for an HTML attribute or element.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
	}
}

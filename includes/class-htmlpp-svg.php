<?php
/**
 * SVG sanitizer.
 *
 * An SVG is an XML document, not a picture: it can carry <script>, event
 * handlers and javascript: links, and when opened directly from the site it
 * runs with the site's origin. That is why WordPress refuses SVG uploads.
 *
 * This class keeps what an SVG needs to draw and removes everything that can
 * execute or reach outside the file. It works on an allowlist: an element or
 * attribute survives only if it is known to be harmless. The renderer adds a
 * second, independent layer by serving every SVG with a sandboxing
 * Content-Security-Policy (see HTMLPP_Renderer::svg_csp()).
 *
 * Uses PHP's built-in DOM extension, so there is no third-party library to
 * bundle or keep up to date.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowlist-based SVG sanitizer.
 */
class HTMLPP_Svg {

	const SVG_NS   = 'http://www.w3.org/2000/svg';
	const XLINK_NS = 'http://www.w3.org/1999/xlink';
	const XML_NS   = 'http://www.w3.org/XML/1998/namespace';

	/**
	 * Elements that are never allowed, whatever a filter adds. Compared in
	 * lower case.
	 *
	 * @var string[]
	 */
	const NEVER = array( 'script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener', 'audio', 'video', 'canvas', 'html', 'body', 'head', 'meta', 'link', 'base', 'form', 'input', 'button' );

	/**
	 * Animation elements, which can rewrite another element's attributes.
	 *
	 * @var string[]
	 */
	const ANIMATIONS = array( 'animate', 'set', 'animatetransform', 'animatemotion', 'animatecolor' );

	/**
	 * Elements whose href may only point inside the same file (#id).
	 * Everything except links and images falls in this group.
	 *
	 * @var string[]
	 */
	const LINKS = array( 'a' );

	/**
	 * Elements whose href loads an image.
	 *
	 * @var string[]
	 */
	const IMAGES = array( 'image', 'feimage' );

	/**
	 * SVG elements allowed to survive (compared in lower case).
	 *
	 * @return string[]
	 */
	public static function allowed_elements() {
		$elements = array(
			'svg',
			'g',
			'defs',
			'symbol',
			'use',
			'title',
			'desc',
			'switch',
			'view',
			'a',
			'path',
			'rect',
			'circle',
			'ellipse',
			'line',
			'polyline',
			'polygon',
			'text',
			'tspan',
			'textPath',
			'image',
			'linearGradient',
			'radialGradient',
			'stop',
			'pattern',
			'clipPath',
			'mask',
			'marker',
			'filter',
			'feBlend',
			'feColorMatrix',
			'feComponentTransfer',
			'feComposite',
			'feConvolveMatrix',
			'feDiffuseLighting',
			'feDisplacementMap',
			'feDistantLight',
			'feDropShadow',
			'feFlood',
			'feFuncA',
			'feFuncB',
			'feFuncG',
			'feFuncR',
			'feGaussianBlur',
			'feImage',
			'feMerge',
			'feMergeNode',
			'feMorphology',
			'feOffset',
			'fePointLight',
			'feSpecularLighting',
			'feSpotLight',
			'feTile',
			'feTurbulence',
			'style',
			'animate',
			'animateTransform',
			'animateMotion',
			'set',
			'mpath',
		);

		/**
		 * Filter the SVG elements that survive sanitizing.
		 *
		 * Elements in HTMLPP_Svg::NEVER (script, foreignObject, iframe, …) are
		 * removed regardless of this list.
		 *
		 * @param string[] $elements Element names.
		 */
		$elements = (array) apply_filters( 'htmlpp_svg_allowed_elements', $elements );

		$allowed = array();
		foreach ( $elements as $element ) {
			$name = strtolower( (string) $element );
			if ( '' !== $name && ! in_array( $name, self::NEVER, true ) ) {
				$allowed[ $name ] = true;
			}
		}
		return $allowed;
	}

	/**
	 * Largest SVG accepted, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		/**
		 * Filter the largest SVG file the sanitizer will accept.
		 *
		 * @param int $bytes Default 5 MB.
		 */
		return (int) apply_filters( 'htmlpp_svg_max_bytes', 5 * MB_IN_BYTES );
	}

	/**
	 * Sanitize an SVG document.
	 *
	 * @param string $svg Raw file contents.
	 * @return array{ok:bool,svg:string,error:string,removed:int}
	 */
	public static function sanitize( $svg ) {
		$svg = (string) $svg;

		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::fail( __( 'SVG files need PHP’s DOM extension, which this server does not have.', 'html-page-publisher' ) );
		}
		if ( '' === trim( $svg ) ) {
			return self::fail( __( 'The SVG file is empty.', 'html-page-publisher' ) );
		}
		if ( strlen( $svg ) > self::max_bytes() ) {
			return self::fail(
				sprintf(
					/* translators: %s: size limit, e.g. 5 MB */
					__( 'The SVG file is larger than %s.', 'html-page-publisher' ),
					size_format( self::max_bytes() )
				)
			);
		}
		// UTF-16 and other encodings could hide the checks below in NUL bytes.
		if ( false !== strpos( $svg, "\0" ) ) {
			return self::fail( __( 'The SVG file is not UTF-8 text.', 'html-page-publisher' ) );
		}
		if ( 0 === strpos( $svg, "\xEF\xBB\xBF" ) ) {
			$svg = substr( $svg, 3 );
		}
		// A DOCTYPE is how XML external-entity and entity-expansion attacks
		// are delivered. Real-world SVGs do not need one.
		if ( preg_match( '/<!\s*(DOCTYPE|ENTITY)/i', $svg ) ) {
			return self::fail( __( 'SVG files with a DOCTYPE or entity declaration are not accepted. Re-export the file without one.', 'html-page-publisher' ) );
		}

		$previous                = libxml_use_internal_errors( true );
		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$dom->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// LIBXML_NONET: never fetch anything over the network while parsing.
		$loaded = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null !== $dom->doctype ) {
			return self::fail( __( 'The file is not a valid SVG image.', 'html-page-publisher' ) );
		}

		$root = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $root || self::SVG_NS !== $root->namespaceURI || 'svg' !== strtolower( (string) $root->localName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return self::fail( __( 'The file is not an SVG image (its root element must be <svg xmlns="http://www.w3.org/2000/svg">).', 'html-page-publisher' ) );
		}

		$removed = 0;

		// Processing instructions and comments outside the root element,
		// such as an xml-stylesheet instruction, which can pull in XSLT.
		$top = array();
		foreach ( $dom->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$top[] = $node;
		}
		foreach ( $top as $node ) {
			if ( $node !== $root ) {
				$dom->removeChild( $node );
				++$removed;
			}
		}

		$allowed = self::allowed_elements();
		self::clean_element( $root, $allowed, $removed );

		$out = $dom->saveXML( $root );
		if ( false === $out || '' === $out ) {
			return self::fail( __( 'The SVG image could not be processed.', 'html-page-publisher' ) );
		}

		return array(
			'ok'      => true,
			// Split so no parser mistakes the declaration for a PHP tag.
			'svg'     => '<' . '?xml version="1.0" encoding="UTF-8"?' . ">\n" . $out . "\n",
			'error'   => '',
			'removed' => $removed,
		);
	}

	/**
	 * Failure result.
	 *
	 * @param string $message Reason.
	 * @return array
	 */
	private static function fail( $message ) {
		return array(
			'ok'      => false,
			'svg'     => '',
			'error'   => $message,
			'removed' => 0,
		);
	}

	/**
	 * Clean an element's attributes and children, recursively.
	 *
	 * @param DOMElement $el      Element to clean (already known to be allowed).
	 * @param array      $allowed Allowed element names (lower case => true).
	 * @param int        $removed Counter of removed nodes and attributes.
	 */
	private static function clean_element( DOMElement $el, array $allowed, &$removed ) {
		self::clean_attributes( $el, $removed );

		$tag = strtolower( (string) $el->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Collect first: removing while iterating a live list skips nodes.
		$children = array();
		foreach ( $el->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( self::element_allowed( $child, $allowed ) ) {
					self::clean_element( $child, $allowed, $removed );
				} else {
					$el->removeChild( $child );
					++$removed;
				}
			} elseif ( $child instanceof DOMText ) {
				// Text and CDATA. Only a stylesheet's text can do anything.
				if ( 'style' === $tag ) {
					$clean = self::clean_css( $child->data );
					if ( $clean !== $child->data ) {
						$child->data = $clean;
						++$removed;
					}
				}
			} else {
				// Comments, processing instructions, entity references.
				$el->removeChild( $child );
				++$removed;
			}
		}
	}

	/**
	 * Whether an element may stay.
	 *
	 * @param DOMElement $el      Element.
	 * @param array      $allowed Allowed element names (lower case => true).
	 * @return bool
	 */
	private static function element_allowed( DOMElement $el, array $allowed ) {
		// Only SVG-namespace elements: an <x:script> in the XHTML namespace
		// is a real script inside an SVG document.
		if ( self::SVG_NS !== $el->namespaceURI ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return false;
		}

		$tag = strtolower( (string) $el->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( in_array( $tag, self::NEVER, true ) || ! isset( $allowed[ $tag ] ) ) {
			return false;
		}

		// An animation can set an href to javascript: or add an event
		// handler at runtime, so it may not target those attributes.
		if ( in_array( $tag, self::ANIMATIONS, true ) ) {
			$target = strtolower( trim( (string) $el->getAttribute( 'attributeName' ) ) );
			$local  = false === strpos( $target, ':' ) ? $target : substr( $target, strpos( $target, ':' ) + 1 );
			if ( 'href' === $local || 0 === strpos( $local, 'on' ) || 'style' === $local ) {
				return false;
			}
		}

		// Stylesheets must be CSS.
		if ( 'style' === $tag && $el->hasAttribute( 'type' ) ) {
			$type = strtolower( trim( (string) $el->getAttribute( 'type' ) ) );
			if ( '' !== $type && 'text/css' !== $type ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove or clean unsafe attributes.
	 *
	 * @param DOMElement $el      Element.
	 * @param int        $removed Counter.
	 */
	private static function clean_attributes( DOMElement $el, &$removed ) {
		$tag   = strtolower( (string) $el->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$attrs = array();
		foreach ( $el->attributes as $attr ) {
			$attrs[] = $attr;
		}

		foreach ( $attrs as $attr ) {
			$ns    = (string) $attr->namespaceURI; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name  = strtolower( (string) $attr->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$value = (string) $attr->value;
			$keep  = true;

			if ( self::XLINK_NS === $ns ) {
				$keep = ( 'href' === $name && self::href_ok( $tag, $value ) ) || 'title' === $name;
			} elseif ( self::XML_NS === $ns ) {
				// xml:base would change what every relative link points at.
				$keep = in_array( $name, array( 'space', 'lang' ), true );
			} elseif ( '' !== $ns ) {
				// Editor metadata such as Inkscape or Sodipodi attributes, and any other namespace.
				$keep = false;
			} elseif ( 0 === strpos( $name, 'on' ) ) {
				// Event handlers such as onload, onclick and onbegin.
				$keep = false;
			} elseif ( 'href' === $name ) {
				$keep = self::href_ok( $tag, $value );
			} elseif ( 'style' === $name ) {
				$clean = self::clean_css( $value );
				if ( $clean !== $value ) {
					$el->setAttribute( 'style', $clean );
					++$removed;
				}
			} elseif ( self::has_script_url( $value ) ) {
				// e.g. an animation's to="javascript:…", fill="url(javascript:…)".
				$keep = false;
			}

			if ( ! $keep ) {
				$el->removeAttributeNode( $attr );
				++$removed;
			}
		}
	}

	/**
	 * Normalize a URL the way a browser reads it: case-insensitive, with
	 * the whitespace and control characters it ignores removed
	 * ("java&#9;script:" is still javascript:).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		return strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', (string) $url ) );
	}

	/**
	 * Whether a value contains a URL that could run code or render a
	 * document.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function has_script_url( $value ) {
		$v = self::normalize_url( $value );
		foreach ( array( 'javascript:', 'vbscript:', 'livescript:', 'data:text/', 'data:application/', 'data:image/svg' ) as $bad ) {
			if ( false !== strpos( $v, $bad ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a raster image data: URL.
	 *
	 * @param string $v Normalized URL.
	 * @return bool
	 */
	private static function is_raster_data( $v ) {
		return (bool) preg_match( '#^data:image/(png|jpe?g|gif|webp|avif|bmp)[;,]#', $v );
	}

	/**
	 * Whether an href is safe for the element that carries it.
	 *
	 * @param string $tag   Element name (lower case).
	 * @param string $value Raw href.
	 * @return bool
	 */
	private static function href_ok( $tag, $value ) {
		$v = self::normalize_url( $value );
		if ( '' === $v || '#' === $v[0] ) {
			return true; // Same-file reference.
		}

		$has_scheme = (bool) preg_match( '#^[a-z][a-z0-9+.\-]*:#', $v );

		if ( in_array( $tag, self::LINKS, true ) ) {
			return ! $has_scheme || (bool) preg_match( '#^(https?|mailto|tel):#', $v );
		}
		if ( in_array( $tag, self::IMAGES, true ) ) {
			return ! $has_scheme || (bool) preg_match( '#^https?:#', $v ) || self::is_raster_data( $v );
		}

		// <use>, gradients, patterns, filters, textPath, mpath: an external
		// reference would pull another document into this one.
		return false;
	}

	/**
	 * Remove the parts of a stylesheet that can load or run something.
	 *
	 * Modern browsers never execute script from CSS, and the served SVG is
	 * sandboxed anyway; this strips the legacy vectors and external imports
	 * so the file is clean on its own.
	 *
	 * @param string $css Stylesheet text or a style attribute.
	 * @return string
	 */
	public static function clean_css( $css ) {
		$css = (string) $css;

		$css = (string) preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = (string) preg_replace( '/expression\s*\(|-moz-binding\s*:|behavior\s*:/i', 'x-removed:', $css );

		$css = (string) preg_replace_callback(
			'/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is',
			static function ( $m ) {
				$v = self::normalize_url( $m[2] );
				if ( false !== strpos( $m[2], '\\' ) || self::has_script_url( $v ) ) {
					return 'none';
				}
				if ( '' === $v || '#' === $v[0] || self::is_raster_data( $v ) ) {
					return $m[0];
				}
				// Relative paths and http(s) are fine; any other scheme is not.
				if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#', $v ) || preg_match( '#^https?:#', $v ) ) {
					return $m[0];
				}
				return 'none';
			},
			$css
		);

		return (string) preg_replace( '/(java|vb|live)script\s*:/i', '', $css );
	}
}

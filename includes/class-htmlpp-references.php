<?php
/**
 * Missing-files check: find the files a page refers to that are not in its
 * folder, so a broken image or a missing stylesheet shows up in the
 * dashboard (and in the reply an AI assistant gets) instead of on the live
 * page.
 *
 * Pure string logic: callers pass the page HTML, the list of files that
 * exist and a reader for stylesheets, so it is unit-tested without a
 * filesystem.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds a page's references to files in its own folder.
 */
class HTMLPP_References {

	/**
	 * Most stylesheets followed for url() and @import references.
	 */
	const MAX_CSS_FILES = 20;

	/**
	 * Largest stylesheet read, in bytes.
	 */
	const MAX_CSS_BYTES = 2097152;

	/**
	 * Most missing files reported.
	 */
	const MAX_RESULTS = 50;

	/**
	 * Attributes whose value is one URL.
	 */
	const URL_ATTRIBUTES = array( 'src', 'href', 'poster', 'data', 'data-src', 'xlink:href' );

	/**
	 * Attributes whose value is a list of image candidates.
	 */
	const SRCSET_ATTRIBUTES = array( 'srcset', 'data-srcset', 'imagesrcset' );

	/**
	 * Files a page refers to that are not in its folder.
	 *
	 * Checked: src, href, poster, srcset and similar attributes, url() and
	 * CSS imports in <style> blocks and style="" attributes, and the same in
	 * every stylesheet of the page the HTML links to. Ignored: links to
	 * other sites, root-relative paths (/contact), data: URIs, #anchors,
	 * template placeholders, paths that leave the page folder, and paths
	 * whose extension the plugin never serves (a link to "about" or
	 * "contact.php" is not a page file).
	 *
	 * @param string        $html     Page HTML.
	 * @param string[]      $existing References of the files that exist, relative to the page folder (e.g. "assets/hero.png").
	 * @param callable|null $read_css Returns a stylesheet's contents from its reference, or false.
	 * @param array         $args     Optional. 'extensions' (string[]): extensions a page file can have.
	 *                                'roots' (string[]): URLs of the page folder, so a <base> pointing
	 *                                there is understood; any other <base> skips the HTML's references.
	 * @return array<int, array{reference:string, raw:string, found_in:string, suggestion:string}>
	 */
	public static function missing( $html, array $existing, $read_css = null, array $args = array() ) {
		$extensions = isset( $args['extensions'] ) ? (array) $args['extensions'] : array();
		$roots      = isset( $args['roots'] ) ? (array) $args['roots'] : array();

		$present = array( 'index.html' => true );
		foreach ( $existing as $reference ) {
			$present[ (string) $reference ] = true;
		}

		$missing    = array();
		$seen       = array();
		$queue      = array();
		$queue_done = array();

		$report = static function ( $path, $raw, $found_in ) use ( &$missing, &$seen, $present, $existing ) {
			if ( isset( $present[ $path ] ) || isset( $seen[ $path ] ) ) {
				return;
			}
			$seen[ $path ] = true;
			$missing[]     = array(
				'reference'  => $path,
				'raw'        => $raw,
				'found_in'   => $found_in,
				'suggestion' => self::suggest( $path, $existing ),
			);
		};

		$base = self::base_href( $html );
		if ( null === $base || self::is_page_root( $base, $roots ) ) {
			foreach ( self::from_html( $html ) as $raw ) {
				$path = self::resolve( $raw, '', $extensions );
				if ( null === $path ) {
					continue;
				}
				$report( $path, $raw, 'index.html' );
				if ( self::is_css( $path ) && isset( $present[ $path ] ) ) {
					$queue[ $path ] = true;
				}
			}
		}

		// Stylesheets the page loads: their url() and @import references
		// resolve against the stylesheet's own folder.
		$read = 0;
		while ( null !== $read_css && ! empty( $queue ) && $read < self::MAX_CSS_FILES ) {
			$css_path = (string) array_key_first( $queue );
			unset( $queue[ $css_path ] );
			$queue_done[ $css_path ] = true;
			++$read;

			$css = call_user_func( $read_css, $css_path );
			if ( ! is_string( $css ) || strlen( $css ) > self::MAX_CSS_BYTES ) {
				continue;
			}
			$dir = self::dirname( $css_path );
			foreach ( self::from_css( $css ) as $raw ) {
				$path = self::resolve( $raw, $dir, $extensions );
				if ( null === $path ) {
					continue;
				}
				$report( $path, $raw, $css_path );
				if ( self::is_css( $path ) && isset( $present[ $path ] ) && empty( $queue_done[ $path ] ) ) {
					$queue[ $path ] = true;
				}
			}
		}

		return array_slice( $missing, 0, self::MAX_RESULTS );
	}

	/**
	 * Raw URLs referenced from a page's HTML (not yet resolved), in order.
	 *
	 * Script contents and HTML comments are skipped; a <script src> is not.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function from_html( $html ) {
		$markup = '';
		$styles = array();
		foreach ( self::segments( (string) $html ) as $segment ) {
			if ( 'markup' === $segment[0] ) {
				$markup .= $segment[1];
			} elseif ( 'style' === $segment[0] ) {
				$styles[] = $segment[1];
			}
		}
		$urls = array();

		if ( preg_match_all( '/<[a-z][a-z0-9:-]*\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $markup, $tags ) ) {
			foreach ( $tags[1] as $attributes ) {
				foreach ( self::attributes( $attributes ) as $name => $value ) {
					if ( in_array( $name, self::URL_ATTRIBUTES, true ) ) {
						$urls[] = $value;
					} elseif ( in_array( $name, self::SRCSET_ATTRIBUTES, true ) ) {
						array_push( $urls, ...self::srcset_urls( $value ) );
					} elseif ( 'style' === $name ) {
						array_push( $urls, ...self::from_css( $value ) );
					}
				}
			}
		}

		foreach ( $styles as $css ) {
			array_push( $urls, ...self::from_css( $css ) );
		}

		return array_values( array_filter( array_map( 'trim', $urls ), 'strlen' ) );
	}

	/**
	 * Raw URLs in CSS: url(...) and @import "...".
	 *
	 * @param string $css CSS.
	 * @return string[]
	 */
	public static function from_css( $css ) {
		$raw  = (string) $css;
		$css  = preg_replace( '/\/\*.*?\*\//s', '', $raw );
		$css  = null === $css ? $raw : $css;
		$urls = array();
		if ( preg_match_all( '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s"\']*))\s*\)/i', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$urls[] = self::first_group( $match );
			}
		}
		if ( preg_match_all( '/@import\s+(?:"([^"]+)"|\'([^\']+)\')/i', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$urls[] = self::first_group( $match );
			}
		}
		return array_values( array_filter( array_map( 'trim', $urls ), 'strlen' ) );
	}

	/**
	 * Resolve a raw URL to a path inside the page folder.
	 *
	 * @param string   $raw        Raw URL as written.
	 * @param string   $dir        Folder it is relative to ('' for the page folder).
	 * @param string[] $extensions Extensions a page file can have ([] accepts any).
	 * @return string|null Path relative to the page folder, or null when it is
	 *                     not something to check.
	 */
	public static function resolve( $raw, $dir = '', array $extensions = array() ) {
		$url = trim( (string) $raw );
		if ( '' === $url ) {
			return null;
		}
		// Anchors, queries, placeholders from templates ({{ }}, ${ }, <% %>).
		if ( preg_match( '/^[#?{$<\[]/', $url ) || preg_match( '/\{\{|\$\{|<%|%>/', $url ) ) {
			return null;
		}
		// Any scheme (https:, data:, mailto:, javascript:, blob:), protocol-
		// relative and root-relative URLs: not files in this page's folder.
		if ( preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $url ) || '/' === $url[0] || '\\' === $url[0] ) {
			return null;
		}

		$url = preg_replace( '/[?#].*$/s', '', $url );
		$url = rawurldecode( str_replace( '\\', '/', (string) $url ) );
		if ( '' === $url || '/' === substr( $url, -1 ) || preg_match( '/[\x00-\x1f]/', $url ) ) {
			return null; // A folder, or nothing left.
		}

		$parts = array();
		foreach ( explode( '/', ( '' === $dir ? '' : $dir . '/' ) . $url ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( empty( $parts ) ) {
					return null; // Leaves the page folder.
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		if ( empty( $parts ) ) {
			return null;
		}
		$path = implode( '/', $parts );

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext || ( ! empty( $extensions ) && ! in_array( $ext, $extensions, true ) ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * An existing file that a missing reference probably meant: the same
	 * path in another letter case, or the only file with the same name
	 * (an image uploaded to assets/ while the HTML says images/hero.png,
	 * or "My Photo.png" stored as My-Photo.png).
	 *
	 * @param string   $path     Missing path.
	 * @param string[] $existing Existing references.
	 * @return string The suggested reference, or ''.
	 */
	public static function suggest( $path, array $existing ) {
		$lower = strtolower( $path );
		foreach ( $existing as $reference ) {
			if ( strtolower( (string) $reference ) === $lower ) {
				return (string) $reference;
			}
		}

		$name    = self::name_key( basename( $path ) );
		$matches = array();
		foreach ( $existing as $reference ) {
			if ( self::name_key( basename( (string) $reference ) ) === $name ) {
				$matches[] = (string) $reference;
			}
		}
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}
		// Several files share the name: only the one in assets/ is a clear pick.
		$in_assets = array_values(
			array_filter(
				$matches,
				static function ( $reference ) {
					return 0 === strpos( $reference, 'assets/' ) && false === strpos( substr( $reference, 7 ), '/' );
				}
			)
		);
		return 1 === count( $in_assets ) ? $in_assets[0] : '';
	}

	/**
	 * Point a page's references to one file at another file, wherever the
	 * HTML names it: src, href, poster, srcset and similar attributes, and
	 * url() or @import in its styles. Text, scripts and comments are left
	 * alone. A ?query or #fragment after the path is kept.
	 *
	 * @param string $html HTML.
	 * @param string $from The reference exactly as the HTML writes it (the raw value from missing()).
	 * @param string $to   New reference, relative to the page folder.
	 * @return array{html:string, count:int}
	 */
	public static function replace( $html, $from, $to ) {
		$html  = (string) $html;
		$from  = preg_replace( '/[?#].*$/s', '', trim( (string) $from ) );
		$to    = str_replace( ' ', '%20', trim( (string) $to ) );
		$count = 0;
		if ( '' === $from || '' === $to ) {
			return array(
				'html'  => $html,
				'count' => 0,
			);
		}

		// The path itself is matched case-sensitively (servers are); the
		// attribute names around it are not.
		$quoted = '(?-i:' . preg_quote( $from, '/' ) . ')';
		$safe   = htmlspecialchars( $to, ENT_QUOTES, 'UTF-8' );
		$names  = implode( '|', array_map( 'preg_quote', self::URL_ATTRIBUTES ) );
		$sets   = implode( '|', array_map( 'preg_quote', self::SRCSET_ATTRIBUTES ) );

		$in_markup = static function ( $segment ) use ( $quoted, $safe, $names, $sets, $from, $to, &$count ) {
			// Attributes with one URL, quoted either way or unquoted, keeping
			// any query or fragment after the path.
			$segment = preg_replace_callback(
				'/(\s(?:' . $names . ')\s*=\s*)(?:(["\'])' . $quoted . '([?#][^"\']*)?\2|' . $quoted . '([?#][^\s>]*)?(?=[\s>]))/i',
				static function ( $m ) use ( $safe, &$count ) {
					++$count;
					if ( isset( $m[2] ) && '' !== $m[2] ) {
						return $m[1] . $m[2] . $safe . ( isset( $m[3] ) ? $m[3] : '' ) . $m[2];
					}
					return $m[1] . $safe . ( isset( $m[4] ) ? $m[4] : '' );
				},
				$segment
			);

			// Candidate lists: only the matching candidate changes.
			$segment = preg_replace_callback(
				'/(\s(?:' . $sets . ')\s*=\s*)(["\'])([^"\']*)\2/i',
				static function ( $m ) use ( $from, $safe, &$count ) {
					$candidates = explode( ',', $m[3] );
					foreach ( $candidates as $i => $candidate ) {
						if ( preg_match( '/^(\s*)(\S+)(.*)$/s', $candidate, $c ) && preg_replace( '/[?#].*$/s', '', $c[2] ) === $from ) {
							$candidates[ $i ] = $c[1] . $safe . substr( $c[2], strlen( $from ) ) . $c[3];
							++$count;
						}
					}
					return $m[1] . $m[2] . implode( ',', $candidates ) . $m[2];
				},
				$segment
			);

			// CSS: url() with or without quotes, and quoted imports.
			$segment = preg_replace_callback(
				'/(url\(\s*)(["\']?)' . $quoted . '([?#][^"\')]*)?\2(\s*\))|(@import\s+)(["\'])' . $quoted . '\6/i',
				static function ( $m ) use ( $to, &$count ) {
					++$count;
					if ( isset( $m[5] ) && '' !== $m[5] ) {
						return $m[5] . $m[6] . $to . $m[6];
					}
					return $m[1] . $m[2] . $to . ( isset( $m[3] ) ? $m[3] : '' ) . $m[2] . $m[4];
				},
				$segment
			);

			return $segment;
		};

		// Comments and script bodies are never touched; a script's own tag
		// (its src) and style blocks are.
		$out = '';
		foreach ( self::segments( $html ) as $segment ) {
			$out .= in_array( $segment[0], array( 'markup', 'style' ), true ) ? $in_markup( $segment[1] ) : $segment[1];
		}

		return array(
			'html'  => $out,
			'count' => $count,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Split HTML into markup and the parts no tag is read from: comments,
	 * and the contents of scripts and styles (with their closing tags).
	 * Opening tags stay in the markup, so a script's src is still seen.
	 *
	 * Uses plain string search rather than one regex over the document, so
	 * a large inline script cannot exhaust PCRE's backtracking limit.
	 * Joining the texts gives back the HTML unchanged.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{0:string, 1:string}> Pairs of type ('markup',
	 *         'comment', 'script' or 'style') and text.
	 */
	public static function segments( $html ) {
		$html = (string) $html;
		$len  = strlen( $html );
		$out  = array();
		$pos  = 0;
		$add  = static function ( $type, $text ) use ( &$out ) {
			if ( '' !== $text ) {
				$out[] = array( $type, $text );
			}
		};

		while ( $pos < $len && preg_match( '/<!--|<(script|style)\b/i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];
			if ( '<!--' === $m[0][0] ) {
				$end = strpos( $html, '-->', $start + 4 );
				$end = false === $end ? $len : $end + 3;
				$add( 'markup', substr( $html, $pos, $start - $pos ) );
				$add( 'comment', substr( $html, $start, $end - $start ) );
				$pos = $end;
				continue;
			}

			$tag = strtolower( $m[1][0] );
			if ( ! preg_match( '/\G<' . $tag . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $open, 0, $start ) ) {
				// Not a complete tag: keep it as markup and look further on.
				$add( 'markup', substr( $html, $pos, $start + 1 - $pos ) );
				$pos = $start + 1;
				continue;
			}
			$body  = $start + strlen( $open[0] );
			$close = stripos( $html, '</' . $tag, $body );
			$gt    = false === $close ? false : strpos( $html, '>', $close );
			$end   = false === $gt ? $len : $gt + 1;
			$add( 'markup', substr( $html, $pos, $body - $pos ) );
			$add( $tag, substr( $html, $body, $end - $body ) );
			$pos = $end;
		}
		$add( 'markup', (string) substr( $html, $pos ) );

		return $out;
	}

	/**
	 * Only the markup of a page: no comments, script contents or styles.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function markup_only( $html ) {
		$markup = '';
		foreach ( self::segments( $html ) as $segment ) {
			if ( 'markup' === $segment[0] ) {
				$markup .= $segment[1];
			}
		}
		return $markup;
	}

	/**
	 * Attributes of a tag, lowercase name => decoded value (the first one
	 * wins when a name repeats, as in browsers).
	 *
	 * @param string $attributes Everything between the tag name and ">".
	 * @return array<string,string>
	 */
	public static function attributes( $attributes ) {
		$found = array();
		if ( preg_match_all( '/(?:^|\s)([a-z_:][a-z0-9_:.\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i', $attributes, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$name = strtolower( $match[1] );
				if ( ! isset( $found[ $name ] ) ) {
					$found[ $name ] = html_entity_decode( self::first_group( $match, 2 ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
		}
		return $found;
	}

	/**
	 * URLs in a srcset value.
	 *
	 * @param string $value srcset value.
	 * @return string[]
	 */
	private static function srcset_urls( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 0 === stripos( $value, 'data:' ) ) {
			return array();
		}
		$urls = array();
		foreach ( explode( ',', $value ) as $candidate ) {
			$token = preg_split( '/\s+/', trim( $candidate ) );
			if ( ! empty( $token[0] ) ) {
				$urls[] = $token[0];
			}
		}
		return $urls;
	}

	/**
	 * The page's own <base href>, or null when it has none.
	 *
	 * @param string $html HTML.
	 * @return string|null
	 */
	private static function base_href( $html ) {
		$html = self::markup_only( (string) $html );
		if ( preg_match( '/<base\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $html, $m ) ) {
			$attributes = self::attributes( $m[1] );
			if ( isset( $attributes['href'] ) ) {
				return trim( $attributes['href'] );
			}
		}
		return null;
	}

	/**
	 * Whether a <base href> points at the page's own folder, so references
	 * still resolve against it.
	 *
	 * @param string   $base  Base href.
	 * @param string[] $roots URLs of the page folder.
	 * @return bool
	 */
	private static function is_page_root( $base, array $roots ) {
		if ( '' === $base || './' === $base || '.' === $base ) {
			return true;
		}
		$strip = static function ( $url ) {
			return rtrim( (string) preg_replace( '#^https?:#i', '', (string) $url ), '/' );
		};
		foreach ( $roots as $root ) {
			if ( '' !== (string) $root && $strip( $root ) === $strip( $base ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A file name for comparison: lowercase, with the characters WordPress
	 * replaces in uploaded file names folded to hyphens.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function name_key( $name ) {
		return trim( (string) preg_replace( '/[^a-z0-9._]+/', '-', strtolower( (string) $name ) ), '-' );
	}

	/**
	 * Folder part of a path ('' at the top).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function dirname( $path ) {
		$pos = strrpos( $path, '/' );
		return false === $pos ? '' : substr( $path, 0, $pos );
	}

	/**
	 * Whether a path is a stylesheet.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_css( $path ) {
		return 'css' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * First non-empty capture group from a match, starting at $from.
	 *
	 * @param array $groups Groups of a regex match.
	 * @param int   $from   First group to look at.
	 * @return string
	 */
	private static function first_group( array $groups, $from = 1 ) {
		$count = count( $groups );
		for ( $i = $from; $i < $count; $i++ ) {
			if ( isset( $groups[ $i ] ) && '' !== $groups[ $i ] ) {
				return $groups[ $i ];
			}
		}
		return '';
	}
}

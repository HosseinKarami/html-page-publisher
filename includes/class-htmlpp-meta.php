<?php
/**
 * Per-page metadata (status, custom path, SEO flags) and URL redirects.
 *
 * Page files live on disk; everything else about a page lives in a single
 * option keyed by slug, so no custom tables or post types are needed and the
 * whole record can be exported by copying one option.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options-backed metadata store for pages.
 */
class HTMLPP_Meta {

	/**
	 * Option holding all page records, keyed by slug.
	 */
	const OPTION = 'htmlpp_pages';

	/**
	 * Option holding slug redirects (old slug => new slug) created by renames.
	 */
	const REDIRECTS_OPTION = 'htmlpp_redirects';

	/**
	 * Option holding custom-path redirects (old path => slug) created when a
	 * page's custom path changes.
	 */
	const PATH_REDIRECTS_OPTION = 'htmlpp_path_redirects';

	/**
	 * Special custom-path value meaning "serve as the site's front page".
	 */
	const HOME = 'home';

	/**
	 * Request-scoped cache of the path map.
	 *
	 * @var array<string,string>|null
	 */
	private static $path_map = null;

	/**
	 * Default record.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			'status'       => 'published', // Either 'published' or 'draft'.
			'path'         => '',          // Empty for the prefix URL; the word home for the front page; otherwise a relative path.
			'noindex'      => false,
			'no_snippets'  => false,
			'preview_salt' => 0,           // Bumped to invalidate the preview link.
			'created'      => 0,
			'updated'      => 0,
			'author'       => 0,
		);

		/**
		 * Filter the default page record. Add keys here to let an add-on
		 * persist its own per-page settings through HTMLPP_Meta::update().
		 *
		 * @param array $defaults Field => default value.
		 */
		$filtered = apply_filters( 'htmlpp_page_meta_defaults', $defaults );

		return is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
	}

	/**
	 * All raw records.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$records = get_option( self::OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Record for a slug, merged with defaults.
	 *
	 * @param string $slug Page slug.
	 * @return array
	 */
	public static function get( $slug ) {
		$records = self::all();
		$record  = isset( $records[ $slug ] ) && is_array( $records[ $slug ] ) ? $records[ $slug ] : array();
		$record  = array_merge( self::defaults(), $record );

		/**
		 * Filter a page's metadata record.
		 *
		 * @param array  $record Record merged with defaults.
		 * @param string $slug   Page slug.
		 */
		$filtered = apply_filters( 'htmlpp_page_meta', $record, $slug );

		return is_array( $filtered ) ? self::normalize( array_merge( $record, $filtered ) ) : $record;
	}

	/**
	 * Coerce a record's core fields to their expected types and values.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private static function normalize( array $record ) {
		$record['status'] = isset( $record['status'] ) && 'draft' === $record['status'] ? 'draft' : 'published';

		$path           = isset( $record['path'] ) ? self::sanitize_path( $record['path'] ) : '';
		$record['path'] = null === $path ? '' : $path;

		$record['noindex']      = ! empty( $record['noindex'] );
		$record['no_snippets']  = ! empty( $record['no_snippets'] );
		$record['preview_salt'] = isset( $record['preview_salt'] ) ? (int) $record['preview_salt'] : 0;
		$record['created']      = isset( $record['created'] ) ? (int) $record['created'] : 0;
		$record['updated']      = isset( $record['updated'] ) ? (int) $record['updated'] : 0;
		$record['author']       = isset( $record['author'] ) ? (int) $record['author'] : 0;

		return $record;
	}

	/**
	 * Merge fields into a record and save.
	 *
	 * @param string $slug   Page slug.
	 * @param array  $fields Fields to set (unknown keys are ignored).
	 * @return array The saved record.
	 */
	public static function update( $slug, array $fields ) {
		$records = self::all();
		$current = isset( $records[ $slug ] ) && is_array( $records[ $slug ] ) ? $records[ $slug ] : array();
		$before  = array_merge( self::defaults(), $current );

		$record = self::normalize( array_merge( $before, array_intersect_key( $fields, self::defaults() ) ) );
		if ( empty( $record['created'] ) ) {
			$record['created'] = time();
		}
		$record['updated'] = time();

		// Remember the old custom path so it can keep redirecting.
		if ( '' !== $before['path'] && $before['path'] !== $record['path'] ) {
			self::add_path_redirect( $before['path'], $slug );
		}
		if ( '' !== $record['path'] ) {
			self::remove_path_redirect( $record['path'] );
		}

		$records[ $slug ] = $record;
		self::save( $records );

		/**
		 * Fires after a page's metadata changes.
		 *
		 * @param string $slug   Page slug.
		 * @param array  $record Saved record.
		 * @param array  $before Record before the change.
		 */
		do_action( 'htmlpp_page_meta_updated', $slug, $record, $before );

		if ( $before['status'] !== $record['status'] ) {
			/**
			 * Fires when a page switches between draft and published.
			 *
			 * @param string $slug Page slug.
			 * @param string $from Previous status.
			 * @param string $to   New status.
			 */
			do_action( 'htmlpp_page_status_changed', $slug, $before['status'], $record['status'] );
		}

		return $record;
	}

	/**
	 * Remove a record and every redirect that referenced the slug.
	 *
	 * @param string $slug Page slug.
	 */
	public static function delete( $slug ) {
		$records = self::all();
		if ( isset( $records[ $slug ] ) ) {
			unset( $records[ $slug ] );
			self::save( $records );
		}

		// Redirects to a page that no longer exists would 404 anyway; drop
		// them so a future page at the old slug/path is reachable again.
		$map     = self::redirects();
		$changed = false;
		foreach ( $map as $from => $to ) {
			if ( $to === $slug || $from === $slug ) {
				unset( $map[ $from ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::REDIRECTS_OPTION, $map, false );
		}

		$paths   = self::path_redirects();
		$changed = false;
		foreach ( $paths as $path => $to ) {
			if ( $to === $slug ) {
				unset( $paths[ $path ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::PATH_REDIRECTS_OPTION, $paths, false );
		}
	}

	/**
	 * Move a record to a new slug and remember the old slug for redirects.
	 *
	 * @param string $old_slug Old slug.
	 * @param string $new_slug New slug.
	 */
	public static function rename( $old_slug, $new_slug ) {
		$records = self::all();
		if ( isset( $records[ $old_slug ] ) ) {
			$records[ $new_slug ] = $records[ $old_slug ];
			unset( $records[ $old_slug ] );
			self::save( $records );
		}
		self::add_redirect( $old_slug, $new_slug );
		self::remove_redirect( $new_slug );

		$paths   = self::path_redirects();
		$changed = false;
		foreach ( $paths as $path => $to ) {
			if ( $to === $old_slug ) {
				$paths[ $path ] = $new_slug;
				$changed        = true;
			}
		}
		if ( $changed ) {
			update_option( self::PATH_REDIRECTS_OPTION, $paths, false );
		}
	}

	/**
	 * Persist all records. Autoloaded: the renderer reads it on every
	 * front-end request and it stays small.
	 *
	 * @param array $records Records keyed by slug.
	 */
	private static function save( array $records ) {
		update_option( self::OPTION, $records, true );
		self::$path_map = null;
	}

	/*
	|--------------------------------------------------------------------------
	| Status / preview
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether a record is publicly visible.
	 *
	 * @param array $meta Record.
	 * @return bool
	 */
	public static function is_public( array $meta ) {
		return 'draft' !== $meta['status'];
	}

	/**
	 * Preview token for a slug.
	 *
	 * Derived from the site's auth salt and a per-page counter, so it needs
	 * no storage (nothing is written when an admin merely opens a screen)
	 * and can be invalidated by bumping the counter.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function preview_token( $slug ) {
		$meta = self::get( $slug );
		return substr( hash_hmac( 'sha256', $slug . '|' . (int) $meta['preview_salt'], wp_salt( 'auth' ) ), 0, 24 );
	}

	/**
	 * Invalidate a page's current preview link.
	 *
	 * @param string $slug Page slug.
	 */
	public static function reset_preview( $slug ) {
		$meta = self::get( $slug );
		self::update( $slug, array( 'preview_salt' => (int) $meta['preview_salt'] + 1 ) );
	}

	/**
	 * Shareable preview URL (works for drafts without logging in).
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function preview_url( $slug ) {
		return add_query_arg( 'htmlpp_preview', self::preview_token( $slug ), HTMLPP_Storage::public_page_url( $slug ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Custom paths
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize a user-supplied custom path.
	 *
	 * Accepts "/", "home", "/promo/", "guides/spring" …; returns '' for
	 * "use the normal prefix URL", 'home' for the front page, or a clean
	 * relative path. Returns null when the value is unusable.
	 *
	 * @param string $raw Raw input.
	 * @return string|null
	 */
	public static function sanitize_path( $raw ) {
		$path = strtolower( trim( (string) $raw ) );
		if ( '/' === $path || self::HOME === $path ) {
			return self::HOME;
		}
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segment = sanitize_title( $segment );
			if ( '' === $segment ) {
				return null;
			}
			$segments[] = $segment;
		}
		$path = implode( '/', $segments );

		if ( in_array( $segments[0], self::reserved_first_segments(), true ) || 0 === strpos( $segments[0], 'wp-' ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * First path segments that may never be used as a custom path: core
	 * entry points, rewrite bases, post-type archives and the plugin's own
	 * URL prefix.
	 *
	 * @return string[]
	 */
	public static function reserved_first_segments() {
		$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'feed', 'comments', 'trackback', 'xmlrpc', 'index', 'robots', 'favicon', 'sitemap', 'wp-sitemap', 'search', 'page', 'attachment', 'embed' );

		if ( class_exists( 'HTMLPP_Settings' ) ) {
			$settings   = HTMLPP_Settings::get_settings();
			$reserved[] = (string) $settings['url_prefix'];
		}

		if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) ) {
			$rewrite = $GLOBALS['wp_rewrite'];
			foreach ( array( 'category_base', 'tag_base', 'author_base', 'pagination_base', 'search_base', 'comments_base', 'feed_base', 'comments_pagination_base' ) as $prop ) {
				if ( ! empty( $rewrite->$prop ) ) {
					$reserved[] = trim( (string) $rewrite->$prop, '/' );
				}
			}
			$reserved[] = 'category';
			$reserved[] = 'tag';
			$reserved[] = 'author';
			if ( ! empty( $rewrite->front ) ) {
				$front = trim( (string) $rewrite->front, '/' );
				if ( '' !== $front ) {
					$reserved[] = explode( '/', $front )[0];
				}
			}
		}

		if ( function_exists( 'get_post_types' ) ) {
			foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $type ) {
				$archive = is_string( $type->has_archive ) ? $type->has_archive : $type->name;
				if ( '' !== $archive ) {
					$reserved[] = explode( '/', trim( $archive, '/' ) )[0];
				}
				if ( ! empty( $type->rewrite['slug'] ) ) {
					$reserved[] = explode( '/', trim( (string) $type->rewrite['slug'], '/' ) )[0];
				}
			}
		}

		/**
		 * Filter the first path segments that cannot be used as custom paths.
		 *
		 * @param string[] $reserved Lowercase segments.
		 */
		$reserved = (array) apply_filters( 'htmlpp_reserved_paths', $reserved );

		return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $reserved ) ) ) ) );
	}

	/**
	 * Map of custom path => slug for every page that has one (after the
	 * htmlpp_page_meta filter, so overrides route as well as link).
	 *
	 * @return array<string,string>
	 */
	public static function path_map() {
		if ( null !== self::$path_map ) {
			return self::$path_map;
		}
		$map = array();
		foreach ( array_keys( self::all() ) as $slug ) {
			$meta = self::get( (string) $slug );
			if ( '' !== $meta['path'] ) {
				$map[ $meta['path'] ] = (string) $slug;
			}
		}
		self::$path_map = $map;
		return $map;
	}

	/**
	 * Slug currently occupying a custom path, if any.
	 *
	 * @param string $path Normalized path.
	 * @return string '' when free.
	 */
	public static function slug_for_path( $path ) {
		$map = self::path_map();
		return isset( $map[ $path ] ) ? $map[ $path ] : '';
	}

	/**
	 * Public URL for a custom path.
	 *
	 * @param string $path 'home' or a relative path.
	 * @return string
	 */
	public static function path_url( $path ) {
		if ( self::HOME === $path ) {
			return home_url( '/' );
		}
		return home_url( '/' . $path . '/' );
	}

	/*
	|--------------------------------------------------------------------------
	| Redirects
	|--------------------------------------------------------------------------
	*/

	/**
	 * All slug redirects: old slug => new slug.
	 *
	 * @return array<string,string>
	 */
	public static function redirects() {
		$map = get_option( self::REDIRECTS_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Destination slug for an old slug, following chains, or ''.
	 *
	 * @param string $slug Old slug.
	 * @return string
	 */
	public static function get_redirect( $slug ) {
		$map  = self::redirects();
		$seen = array();
		$hops = 0;
		while ( isset( $map[ $slug ] ) && ! isset( $seen[ $slug ] ) && $hops < 10 ) {
			$seen[ $slug ] = true;
			$slug          = $map[ $slug ];
			++$hops;
		}
		return 0 === $hops ? '' : $slug;
	}

	/**
	 * Record a slug redirect.
	 *
	 * @param string $old_slug Old slug.
	 * @param string $new_slug New slug.
	 */
	public static function add_redirect( $old_slug, $new_slug ) {
		if ( '' === $old_slug || '' === $new_slug || $old_slug === $new_slug ) {
			return;
		}
		$map = self::redirects();
		// Repoint anything that used to redirect to the old slug.
		foreach ( $map as $from => $to ) {
			if ( $to === $old_slug ) {
				$map[ $from ] = $new_slug;
			}
		}
		unset( $map[ $new_slug ] );
		$map[ $old_slug ] = $new_slug;
		update_option( self::REDIRECTS_OPTION, $map, false );
	}

	/**
	 * Forget a slug redirect (e.g. when a new page is published at that slug).
	 *
	 * @param string $slug Slug that should no longer redirect.
	 */
	public static function remove_redirect( $slug ) {
		$map = self::redirects();
		if ( isset( $map[ $slug ] ) ) {
			unset( $map[ $slug ] );
			update_option( self::REDIRECTS_OPTION, $map, false );
		}
	}

	/**
	 * All custom-path redirects: old path => slug.
	 *
	 * @return array<string,string>
	 */
	public static function path_redirects() {
		$map = get_option( self::PATH_REDIRECTS_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Record that an old custom path should redirect to a page. The front
	 * page is never redirected (un-mapping it must restore the site's own
	 * front page).
	 *
	 * @param string $old_path Previous custom path.
	 * @param string $slug     Page slug.
	 */
	public static function add_path_redirect( $old_path, $slug ) {
		if ( '' === $old_path || self::HOME === $old_path || '' === $slug ) {
			return;
		}
		$map              = self::path_redirects();
		$map[ $old_path ] = $slug;
		update_option( self::PATH_REDIRECTS_OPTION, $map, false );
	}

	/**
	 * Forget a custom-path redirect (a page now lives at that path).
	 *
	 * @param string $path Path that should no longer redirect.
	 */
	public static function remove_path_redirect( $path ) {
		$map = self::path_redirects();
		if ( isset( $map[ $path ] ) ) {
			unset( $map[ $path ] );
			update_option( self::PATH_REDIRECTS_OPTION, $map, false );
		}
	}
}

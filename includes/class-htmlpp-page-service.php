<?php
/**
 * Page service: every operation on a page in one place, used by the admin
 * screens, the REST API and WP-CLI.
 *
 * Methods return plain arrays on success and WP_Error on failure; they never
 * read $_POST/$_FILES and never echo, so callers decide how to present the
 * result.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain layer for pages.
 */
class HTMLPP_Page_Service {
	/*
	|--------------------------------------------------------------------------
	| Read
	|--------------------------------------------------------------------------
	*/

	/**
	 * All pages, newest first.
	 *
	 * @return array[] See describe().
	 */
	public function list_pages() {
		$pages = array();
		foreach ( HTMLPP_Storage::list_pages() as $page ) {
			$pages[] = $this->describe( $page['slug'], $page );
		}
		return $pages;
	}

	/**
	 * Whether a page exists.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function exists( $slug ) {
		return HTMLPP_Storage::page_exists( $slug );
	}

	/**
	 * One page, or WP_Error when missing.
	 *
	 * @param string $slug      Slug.
	 * @param bool   $with_html Include the HTML source.
	 * @return array|WP_Error
	 */
	public function get( $slug, $with_html = false ) {
		$slug = HTMLPP_Storage::sanitize_slug( $slug );
		if ( '' === $slug || ! $this->exists( $slug ) ) {
			return new WP_Error( 'htmlpp_not_found', __( 'That page could not be found.', 'html-page-publisher' ), array( 'status' => 404 ) );
		}
		$page = $this->describe( $slug );
		if ( $with_html ) {
			$html         = HTMLPP_Storage::read_page( $slug );
			$page['html'] = is_string( $html ) ? $html : '';
		}
		return $page;
	}

	/**
	 * Public description of a page, followed by any add-on fields registered
	 * through `htmlpp_page_meta_defaults` (a page-detail key wins a clash).
	 *
	 * @param string     $slug Slug.
	 * @param array|null $row  Row from HTMLPP_Storage::list_pages(), if already at hand.
	 * @return array
	 */
	public function describe( $slug, $row = null ) {
		if ( null === $row ) {
			$index = HTMLPP_Storage::index_file( $slug );
			$row   = array(
				'title'    => HTMLPP_Storage::extract_title( $index ),
				'modified' => '' !== $index && file_exists( $index ) ? (int) filemtime( $index ) : 0,
				'files'    => count( HTMLPP_Storage::list_files( $slug ) ),
			);
		}
		$meta = HTMLPP_Meta::get( $slug );

		return array(
			'slug'            => $slug,
			'title'           => isset( $row['title'] ) ? $row['title'] : '',
			'status'          => $meta['status'],
			'url'             => HTMLPP_Storage::public_page_url( $slug ),
			'preview_url'     => HTMLPP_Meta::is_public( $meta ) ? '' : HTMLPP_Meta::preview_url( $slug ),
			'edit_url'        => HTMLPP_Admin::edit_url( $slug ),
			'path'            => $meta['path'],
			'noindex'         => (bool) $meta['noindex'],
			'no_snippets'     => (bool) $meta['no_snippets'],
			'seo_title'       => $meta['seo_title'],
			'seo_description' => $meta['seo_description'],
			'seo_image'       => $meta['seo_image'],
			'files'           => isset( $row['files'] ) ? (int) $row['files'] : 0,
			'modified'        => isset( $row['modified'] ) ? (int) $row['modified'] : 0,
			'created'         => (int) $meta['created'],
			'author'          => (int) $meta['author'],
		) + HTMLPP_Meta::addon_fields( $meta );
	}

	/*
	|--------------------------------------------------------------------------
	| Create / replace
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a page, or replace an existing one's HTML (and add files).
	 *
	 * Options ($args):
	 * - html      (string) Page HTML — required unless zip is given.
	 * - zip       (string) Absolute path to a ZIP bundle.
	 * - status    (string) 'published' (default) or 'draft'; new pages only.
	 * - overwrite (bool)   Allow replacing an existing page.
	 * - files     (array)  Files to add to assets/, each a $_FILES-style array.
	 * - mode      (string) 'upload' (default) or 'sideload' for those files.
	 *
	 * @param string $slug Slug (sanitized here).
	 * @param array  $args Options, see above.
	 * @return array|WP_Error Page description plus 'created', 'skipped', 'file_errors'.
	 */
	public function create( $slug, array $args ) {
		$slug = HTMLPP_Storage::sanitize_slug( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'htmlpp_bad_slug', __( 'Please enter a page slug.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'html'      => null,
				'zip'       => '',
				'status'    => 'published',
				'overwrite' => false,
				'files'     => array(),
				'mode'      => 'upload',
			)
		);

		if ( '' === $args['zip'] && ! is_string( $args['html'] ) ) {
			return new WP_Error( 'htmlpp_no_content', __( 'Please upload an HTML file or a ZIP bundle.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( '' === $args['zip'] && '' === trim( (string) $args['html'] ) ) {
			return new WP_Error( 'htmlpp_empty', __( 'The HTML is empty — nothing to publish.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$exists = $this->exists( $slug );
		if ( $exists ) {
			/** This filter is documented in includes/class-htmlpp-uploader.php */
			$overwrite = (bool) apply_filters( 'htmlpp_allow_overwrite', (bool) $args['overwrite'], $slug );
			if ( ! $overwrite ) {
				return new WP_Error(
					'htmlpp_exists',
					sprintf(
						/* translators: %s: page slug */
						__( 'A page with the slug %s already exists. Tick “Replace the existing page” to overwrite it (the current version is kept in its history), or choose a different slug.', 'html-page-publisher' ),
						$slug
					),
					array( 'status' => 409 )
				);
			}
			if ( HTMLPP_Uploader::file_editing_disabled() ) {
				return new WP_Error( 'htmlpp_locked', __( 'Replacing an existing page is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
			}
		}

		HTMLPP_Storage::ensure_dir();
		$page_dir   = HTMLPP_Storage::page_dir( $slug );
		$assets_dir = trailingslashit( $page_dir ) . 'assets';
		if ( ! wp_mkdir_p( $page_dir ) || ! wp_mkdir_p( $assets_dir ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'Could not create page directory. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}

		$skipped = array();
		$import  = null;
		if ( '' !== $args['zip'] ) {
			$import = HTMLPP_Zip::import( $args['zip'], $page_dir );
			if ( ! $import['ok'] ) {
				if ( ! $exists ) {
					HTMLPP_Storage::delete( $page_dir, true );
				}
				return new WP_Error( 'htmlpp_zip', $import['error'], array( 'status' => 400 ) );
			}
			$html    = $import['index_html'];
			$skipped = $import['skipped'];
		} else {
			$html = (string) $args['html'];
		}

		$html = HTMLPP_Sanitizer::sanitize( $html, $slug, 'upload' );

		$written = $exists
			? HTMLPP_Storage::write_page( $slug, $html )
			: HTMLPP_Storage::put_contents( trailingslashit( $page_dir ) . 'index.html', $html );
		if ( ! $written ) {
			return new WP_Error( 'htmlpp_fs', __( 'Could not write index.html. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}

		// Attaching a page's own assets during creation is part of the create
		// operation (which is allowed), so it is not blocked by the editing
		// lock the way editing an existing page's files is.
		$file_errors = array();
		$file_ok     = array();
		foreach ( (array) $args['files'] as $file ) {
			$stored = $this->store_file( $slug, $file, $args['mode'], true );
			if ( is_wp_error( $stored ) ) {
				$file_errors[] = ( isset( $file['name'] ) ? $file['name'] . ': ' : '' ) . $stored->get_error_message();
			} else {
				$file_ok[] = $stored;
			}
		}

		if ( null !== $import ) {
			/** This action is documented in includes/class-htmlpp-uploader.php */
			do_action( 'htmlpp_zip_imported', $slug, $import );
		}

		// "Save as draft" applies to new pages only: replacing a live page
		// never takes it offline as a side effect.
		$draft = ! $exists && 'draft' === $args['status'];

		if ( ! $exists ) {
			HTMLPP_Meta::update(
				$slug,
				array(
					'status' => $draft ? 'draft' : 'published',
					'author' => get_current_user_id(),
				)
			);
			HTMLPP_Meta::remove_redirect( $slug );

			/** This action is documented in includes/class-htmlpp-uploader.php */
			do_action( 'htmlpp_page_created', $slug, $html, $draft ? 'draft' : 'published' );
			if ( ! $draft ) {
				/** This action is documented in includes/class-htmlpp-uploader.php */
				do_action( 'htmlpp_page_published', $slug, $html );
			}
		}

		$page                = $this->describe( $slug );
		$page['created']     = ! $exists;
		$page['skipped']     = $skipped;
		$page['file_errors'] = $file_errors;
		$page['files_added'] = $file_ok;
		return $page;
	}

	/**
	 * Replace a page's HTML (previous version is kept in its history).
	 *
	 * @param string $slug Slug.
	 * @param string $html New HTML.
	 * @return array|WP_Error Page description.
	 */
	public function update_html( $slug, $html ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'HTML editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		if ( '' === trim( (string) $html ) ) {
			return new WP_Error( 'htmlpp_empty', __( 'Content is empty — nothing was saved.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		$clean = HTMLPP_Sanitizer::sanitize( (string) $html, $page['slug'], 'edit' );
		if ( ! HTMLPP_Storage::write_page( $page['slug'], $clean ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'Could not save changes. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		return $this->describe( $page['slug'] );
	}

	/**
	 * Change part of a page's HTML with exact find/replace pairs, so an AI
	 * agent can change a headline without resending the whole document.
	 *
	 * Each pair's `find` must occur exactly once in the HTML as it stands
	 * after the pairs before it. All pairs apply or none do; the result is
	 * saved like any other edit (the previous version goes to the history).
	 *
	 * @param string $slug  Slug.
	 * @param array  $edits List of [ 'find' => string, 'replace' => string ].
	 * @return array|WP_Error Page description.
	 */
	public function edit_html( $slug, array $edits ) {
		$page = $this->get( $slug, true );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( empty( $edits ) ) {
			return new WP_Error( 'htmlpp_no_edits', __( 'No edits were given — nothing was changed.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$html = $page['html'];
		foreach ( array_values( $edits ) as $i => $edit ) {
			$position = $i + 1;
			$find     = is_array( $edit ) && isset( $edit['find'] ) && is_string( $edit['find'] ) ? $edit['find'] : '';
			$replace  = is_array( $edit ) && isset( $edit['replace'] ) && is_scalar( $edit['replace'] ) ? (string) $edit['replace'] : null;
			if ( '' === $find || null === $replace ) {
				return new WP_Error(
					'htmlpp_bad_edit',
					sprintf(
						/* translators: %d: position of the edit in the list, starting at 1 */
						__( 'Edit %d needs a non-empty "find" text and a "replace" text. Nothing was changed.', 'html-page-publisher' ),
						$position
					),
					array( 'status' => 400 )
				);
			}
			$count = substr_count( $html, $find );
			if ( 1 !== $count ) {
				return new WP_Error(
					0 === $count ? 'htmlpp_edit_not_found' : 'htmlpp_edit_ambiguous',
					0 === $count
						? sprintf(
							/* translators: %d: position of the edit in the list, starting at 1 */
							__( 'Edit %d: the "find" text does not appear in the page. Copy it exactly from the current HTML, including spaces and line breaks. Nothing was changed.', 'html-page-publisher' ),
							$position
						)
						: sprintf(
							/* translators: 1: position of the edit in the list, starting at 1, 2: number of matches */
							__( 'Edit %1$d: the "find" text appears %2$d times. Include more of the surrounding HTML so it matches exactly once. Nothing was changed.', 'html-page-publisher' ),
							$position,
							$count
						),
					array( 'status' => 400 )
				);
			}
			$html = substr_replace( $html, $replace, strpos( $html, $find ), strlen( $find ) );
		}

		return $this->update_html( $page['slug'], $html );
	}

	/*
	|--------------------------------------------------------------------------
	| Metadata, rename, duplicate, delete
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update status, custom path, SEO flags and link preview fields.
	 *
	 * @param string $slug   Slug.
	 * @param array  $fields Any of status, path, noindex, no_snippets, seo_title,
	 *                       seo_description, seo_image (plus add-on fields).
	 * @return array|WP_Error Page description plus 'messages'.
	 */
	public function set_meta( $slug, array $fields ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$slug   = $page['slug'];
		$before = HTMLPP_Meta::get( $slug );
		$update = array();

		if ( array_key_exists( 'status', $fields ) ) {
			$update['status'] = 'draft' === $fields['status'] ? 'draft' : 'published';
		}
		if ( array_key_exists( 'noindex', $fields ) ) {
			$update['noindex'] = ! empty( $fields['noindex'] );
		}
		if ( array_key_exists( 'no_snippets', $fields ) ) {
			$update['no_snippets'] = ! empty( $fields['no_snippets'] );
		}
		if ( array_key_exists( 'path', $fields ) ) {
			$path = $this->validate_path( $slug, $fields['path'] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			$update['path'] = $path;
		}
		if ( array_key_exists( 'seo_title', $fields ) ) {
			$update['seo_title'] = HTMLPP_Seo::clean( $fields['seo_title'], HTMLPP_Seo::TITLE_MAX );
		}
		if ( array_key_exists( 'seo_description', $fields ) ) {
			$update['seo_description'] = HTMLPP_Seo::clean( $fields['seo_description'], HTMLPP_Seo::DESCRIPTION_MAX );
		}
		if ( array_key_exists( 'seo_image', $fields ) ) {
			$image = $this->validate_seo_image( $slug, $fields['seo_image'] );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			$update['seo_image'] = $image;
		}
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $update[ $key ] ) && ! in_array( $key, HTMLPP_Meta::CORE_FIELDS, true ) ) {
				$update[ $key ] = $value; // Add-on fields registered via htmlpp_page_meta_defaults.
			}
		}

		$record   = HTMLPP_Meta::update( $slug, $update );
		$messages = array();

		if ( 'published' === $record['status'] && 'draft' === $before['status'] ) {
			$html = HTMLPP_Storage::read_page( $slug );
			/** This action is documented in includes/class-htmlpp-uploader.php */
			do_action( 'htmlpp_page_published', $slug, is_string( $html ) ? $html : '' );
		}
		if ( '' !== $before['path'] && $before['path'] !== $record['path'] && HTMLPP_Meta::HOME !== $before['path'] ) {
			$messages[] = sprintf(
				/* translators: %s: previous custom path */
				__( 'The old path "%s" now redirects to the page.', 'html-page-publisher' ),
				$before['path']
			);
		}

		$page             = $this->describe( $slug );
		$page['messages'] = $messages;
		return $page;
	}

	/**
	 * Validate a custom path for a page.
	 *
	 * @param string $slug Slug the path is for.
	 * @param string $raw  Raw path.
	 * @return string|WP_Error Normalized path ('' to clear).
	 */
	public function validate_path( $slug, $raw ) {
		$path = HTMLPP_Meta::sanitize_path( $raw );
		if ( null === $path ) {
			return new WP_Error( 'htmlpp_bad_path', __( 'That custom path is not allowed. Use lowercase letters, numbers, hyphens and slashes (e.g. promo or guides/spring), "/" for the front page, or leave it empty. Paths reserved by WordPress or starting with the plugin’s URL prefix are refused.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( '' === $path ) {
			return '';
		}
		$owner = HTMLPP_Meta::slug_for_path( $path );
		if ( '' !== $owner && $owner !== $slug ) {
			return new WP_Error(
				'htmlpp_path_taken',
				sprintf(
					/* translators: 1: path, 2: page slug */
					__( 'The path "%1$s" is already used by the page "%2$s".', 'html-page-publisher' ),
					$path,
					$owner
				),
				array( 'status' => 409 )
			);
		}
		if ( HTMLPP_Meta::HOME !== $path ) {
			$post_id = url_to_postid( HTMLPP_Meta::path_url( $path ) );

			/**
			 * Filter whether a custom path collides with existing content.
			 *
			 * @param bool   $collides Default: true when url_to_postid() finds a post.
			 * @param string $path     Normalized custom path.
			 * @param int    $post_id  Post found at that URL, or 0.
			 */
			if ( apply_filters( 'htmlpp_path_collides', $post_id > 0, $path, $post_id ) ) {
				if ( $post_id > 0 ) {
					return new WP_Error(
						'htmlpp_path_taken',
						sprintf(
							/* translators: 1: path, 2: title of the WordPress post/page */
							__( 'The path "%1$s" belongs to an existing WordPress page or post (“%2$s”). Choose another path or change that content’s permalink first.', 'html-page-publisher' ),
							$path,
							get_the_title( $post_id )
						),
						array(
							'status'  => 409,
							'post_id' => $post_id,
						)
					);
				}
				return new WP_Error(
					'htmlpp_path_taken',
					sprintf(
						/* translators: %s: path */
						__( 'The path "%s" is already in use on this site. Choose another path.', 'html-page-publisher' ),
						$path
					),
					array( 'status' => 409 )
				);
			}
		}
		return $path;
	}

	/**
	 * Validate a preview image: one of the page's image files (by its
	 * reference, e.g. assets/share.png), a full http(s) link, or '' to clear.
	 *
	 * @param string $slug Slug the image is for.
	 * @param mixed  $raw  Raw value.
	 * @return string|WP_Error The value to store.
	 */
	public function validate_seo_image( $slug, $raw ) {
		$image = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $image ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $image ) ) {
			$url = filter_var( $image, FILTER_VALIDATE_URL );
			if ( false !== $url && ! preg_match( '/[\s"\'<>]/', $url ) ) {
				return $url;
			}
		} else {
			$reference = ltrim( (string) preg_replace( '#^\./#', '', str_replace( '\\', '/', $image ) ), '/' );
			$ext       = strtolower( (string) pathinfo( $reference, PATHINFO_EXTENSION ) );
			if ( HTMLPP_Storage::is_image_extension( $ext ) && '' !== HTMLPP_Storage::file_path( $slug, $reference ) ) {
				return $reference;
			}
		}
		return new WP_Error( 'htmlpp_bad_image', __( 'The preview image must be one of the page’s own image files, such as assets/share.png, or a full https:// link to an image.', 'html-page-publisher' ), array( 'status' => 400 ) );
	}

	/**
	 * Move a page to a new slug (files, history, metadata; old slug redirects).
	 *
	 * @param string $slug     Current slug.
	 * @param string $new_slug New slug.
	 * @return array|WP_Error Page description at the new slug.
	 */
	public function rename( $slug, $new_slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$slug     = $page['slug'];
		$new_slug = HTMLPP_Storage::sanitize_slug( $new_slug );
		if ( '' === $new_slug ) {
			return new WP_Error( 'htmlpp_bad_slug', __( 'Please enter a page slug.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( $new_slug === $slug ) {
			return $this->describe( $slug );
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'Renaming is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		if ( $this->exists( $new_slug ) ) {
			return new WP_Error(
				'htmlpp_exists',
				sprintf(
					/* translators: %s: page slug */
					__( 'A page with the slug "%s" already exists.', 'html-page-publisher' ),
					$new_slug
				),
				array( 'status' => 409 )
			);
		}
		if ( ! HTMLPP_Storage::rename_page( $slug, $new_slug ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'The page could not be renamed. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		HTMLPP_Meta::rename( $slug, $new_slug );
		return $this->describe( $new_slug );
	}

	/**
	 * Copy a page (all files) to a new slug as a draft.
	 *
	 * @param string $slug     Source slug.
	 * @param string $new_slug New slug.
	 * @return array|WP_Error Page description of the copy.
	 */
	public function duplicate( $slug, $new_slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$slug     = $page['slug'];
		$new_slug = HTMLPP_Storage::sanitize_slug( $new_slug );
		if ( '' === $new_slug ) {
			return new WP_Error( 'htmlpp_bad_slug', __( 'Please enter a slug for the copy.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		if ( $this->exists( $new_slug ) ) {
			return new WP_Error(
				'htmlpp_exists',
				sprintf(
					/* translators: %s: page slug */
					__( 'A page with the slug "%s" already exists.', 'html-page-publisher' ),
					$new_slug
				),
				array( 'status' => 409 )
			);
		}
		if ( ! HTMLPP_Storage::copy_page( $slug, $new_slug ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'The page could not be copied. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		$source = HTMLPP_Meta::get( $slug );
		HTMLPP_Meta::update(
			$new_slug,
			array(
				'status'          => 'draft',
				'path'            => '',
				'noindex'         => ! empty( $source['noindex'] ),
				'no_snippets'     => ! empty( $source['no_snippets'] ),
				'seo_title'       => $source['seo_title'],
				'seo_description' => $source['seo_description'],
				'seo_image'       => $source['seo_image'],
				'author'          => get_current_user_id(),
			)
		);
		HTMLPP_Meta::remove_redirect( $new_slug );

		/** This action is documented in includes/class-htmlpp-uploader.php */
		do_action( 'htmlpp_page_duplicated', $slug, $new_slug );

		return $this->describe( $new_slug );
	}

	/**
	 * Delete a page and its history.
	 *
	 * @param string $slug Slug.
	 * @return true|WP_Error
	 */
	public function delete( $slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( ! HTMLPP_Storage::delete_page( $page['slug'] ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'Could not delete page.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Invalidate a page's preview link.
	 *
	 * @param string $slug Slug.
	 * @return array|WP_Error Page description.
	 */
	public function reset_preview( $slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		HTMLPP_Meta::reset_preview( $page['slug'] );
		return $this->describe( $page['slug'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Files
	|--------------------------------------------------------------------------
	*/

	/**
	 * Files in a page's folder.
	 *
	 * @param string $slug Slug.
	 * @return array|WP_Error
	 */
	public function list_files( $slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return HTMLPP_Storage::list_files( $page['slug'] );
	}

	/**
	 * Add one file to a page's assets folder.
	 *
	 * @param string $slug Slug.
	 * @param array  $file $_FILES-style array (name, tmp_name, type, size, error).
	 * @param string $mode         'upload' for browser uploads, 'sideload' for local files.
	 * @param bool   $allow_locked  Skip the editing-lock check (used during page creation).
	 * @return string|WP_Error Stored file name.
	 */
	public function store_file( $slug, array $file, $mode = 'upload', $allow_locked = false ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( ! $allow_locked && HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		$assets_dir = HTMLPP_Storage::assets_dir( $page['slug'] );
		if ( '' === $assets_dir || ! wp_mkdir_p( $assets_dir ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'Could not create the assets folder. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		$moved = HTMLPP_Uploader::move_asset( HTMLPP_Uploader::normalize_file( $file ), $assets_dir, $mode );
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return new WP_Error( 'htmlpp_file_rejected', ! empty( $moved['error'] ) ? $moved['error'] : __( 'Upload failed.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		$name = basename( $moved['file'] );
		/** This action is documented in includes/class-htmlpp-uploader.php */
		do_action( 'htmlpp_assets_uploaded', $page['slug'], array( $name ) );
		return $name;
	}

	/**
	 * Delete one file from a page's folder.
	 *
	 * @param string $slug      Slug.
	 * @param string $reference Relative path (e.g. "assets/hero.png").
	 * @return array|WP_Error { deleted: reference, still_referenced: bool }
	 */
	public function delete_file( $slug, $reference ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		$html             = HTMLPP_Storage::read_page( $page['slug'] );
		$still_referenced = is_string( $html ) && false !== strpos( $html, (string) $reference );
		if ( ! HTMLPP_Storage::delete_asset( $page['slug'], $reference ) ) {
			return new WP_Error( 'htmlpp_not_found', __( 'That file could not be found.', 'html-page-publisher' ), array( 'status' => 404 ) );
		}
		/** This action is documented in includes/class-htmlpp-uploader.php */
		do_action( 'htmlpp_asset_deleted', $page['slug'], $reference );
		return array(
			'deleted'          => $reference,
			'still_referenced' => $still_referenced,
		);
	}

	/**
	 * Store an uploaded file at an exact path in the page's folder, such as
	 * the images/hero.png a page's HTML refers to, so the reference works
	 * without editing the HTML. Refuses a path that is already taken.
	 *
	 * @param string $slug      Slug.
	 * @param array  $file      $_FILES-style array (name, tmp_name, type, size, error).
	 * @param string $reference Path relative to the page folder, e.g. "images/hero.png".
	 * @param string $mode      'upload' for browser uploads, 'sideload' for local files.
	 * @return string|WP_Error The stored reference.
	 */
	public function store_file_at( $slug, array $file, $reference, $mode = 'upload' ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		$slug      = $page['slug'];
		$reference = self::clean_reference( $reference );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}

		$single = HTMLPP_Uploader::normalize_file( $file );
		$want   = self::comparable_extension( pathinfo( $reference, PATHINFO_EXTENSION ) );
		if ( self::comparable_extension( pathinfo( $single['name'], PATHINFO_EXTENSION ) ) !== $want ) {
			return new WP_Error(
				'htmlpp_wrong_type',
				sprintf(
					/* translators: %s: required file extension, e.g. .png */
					__( 'Choose a %s file: the page expects that type at this path.', 'html-page-publisher' ),
					'.' . strtolower( pathinfo( $reference, PATHINFO_EXTENSION ) )
				),
				array( 'status' => 400 )
			);
		}

		$root   = realpath( HTMLPP_Storage::page_dir( $slug ) );
		$target = trailingslashit( HTMLPP_Storage::page_dir( $slug ) ) . $reference;
		if ( ! $root || file_exists( $target ) ) {
			return new WP_Error(
				'htmlpp_file_exists',
				sprintf(
					/* translators: %s: file path, e.g. images/hero.png */
					__( '%s is already in the page’s folder. To change it, use Replace in the file list.', 'html-page-publisher' ),
					$reference
				),
				array( 'status' => 409 )
			);
		}
		$dir = dirname( $target );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'htmlpp_fs', __( 'The folder could not be created. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}
		$real_dir = realpath( $dir );
		if ( ! $real_dir || ( $real_dir !== $root && 0 !== strpos( $real_dir, trailingslashit( $root ) ) ) ) {
			return new WP_Error( 'htmlpp_bad_reference', __( 'That path is outside the page’s folder.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$moved = HTMLPP_Uploader::move_asset( $single, $real_dir, $mode );
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return new WP_Error( 'htmlpp_file_rejected', ! empty( $moved['error'] ) ? $moved['error'] : __( 'Upload failed.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}
		$final = trailingslashit( $real_dir ) . basename( $reference );
		if ( $moved['file'] !== $final && ! HTMLPP_Storage::move( $moved['file'], $final ) ) {
			wp_delete_file( $moved['file'] );
			return new WP_Error( 'htmlpp_fs', __( 'The file could not be saved under that name. Check uploads folder permissions.', 'html-page-publisher' ), array( 'status' => 500 ) );
		}

		/** This action is documented in includes/class-htmlpp-uploader.php */
		do_action( 'htmlpp_assets_uploaded', $slug, array( $reference ) );
		return $reference;
	}

	/**
	 * Files the page refers to that are not in its folder: broken images,
	 * missing stylesheets, scripts, fonts and linked files. See
	 * HTMLPP_References::missing() for what is checked.
	 *
	 * @param string $slug Slug.
	 * @return array|WP_Error List of { reference, raw, found_in, suggestion }.
	 */
	public function missing_files( $slug ) {
		$page = $this->get( $slug, true );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$slug     = $page['slug'];
		$existing = array_column( HTMLPP_Storage::list_files( $slug ), 'reference' );
		$read_css = static function ( $reference ) use ( $slug ) {
			$path = HTMLPP_Storage::file_path( $slug, $reference );
			return '' === $path ? false : HTMLPP_Storage::get_contents( $path );
		};

		$missing = HTMLPP_References::missing(
			$page['html'],
			$existing,
			$read_css,
			array(
				'extensions' => HTMLPP_Uploader::allowed_asset_extensions(),
				'roots'      => array(
					HTMLPP_Storage::public_page_url( $slug ),
					HTMLPP_Storage::public_page_url( $slug, true ),
					HTMLPP_Delivery::files_url( $slug ),
				),
			)
		);

		/**
		 * Filter the files a page refers to that are missing from its folder.
		 * Return an empty array to turn the check off.
		 *
		 * @param array  $missing List of { reference, raw, found_in, suggestion }.
		 * @param string $slug    Page slug.
		 */
		$filtered = apply_filters( 'htmlpp_missing_files', $missing, $slug );
		return is_array( $filtered ) ? array_values( $filtered ) : $missing;
	}

	/**
	 * Point the page's references to one file at another file in its
	 * folder: e.g. change images/hero.png to assets/hero.png after the
	 * image was uploaded to assets/. Every way the HTML writes that path
	 * (./images/hero.png, images/hero.png?v=2…) is changed, in markup only
	 * (src, href, srcset, url() in styles…), never in text or scripts. The
	 * previous version is kept in the page's history.
	 *
	 * @param string $slug      Slug.
	 * @param string $reference The missing file's path, as missing_files() reports it.
	 * @param string $to        Reference of an existing file, e.g. assets/hero.png.
	 * @return array|WP_Error Page description plus 'replaced' (how many references changed).
	 */
	public function fix_reference( $slug, $reference, $to ) {
		$page = $this->get( $slug, true );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'HTML editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		$to = trim( str_replace( '\\', '/', (string) $to ), '/' );
		if ( ! preg_match( '#^[A-Za-z0-9._~@+\-/]+$#', $to ) || '' === HTMLPP_Storage::file_path( $page['slug'], $to ) ) {
			return new WP_Error( 'htmlpp_bad_reference', __( 'That file isn’t in the page’s folder, so the page was not changed.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		// Every spelling of the path in the HTML.
		$spellings = array();
		foreach ( HTMLPP_References::from_html( $page['html'] ) as $raw ) {
			$path = (string) preg_replace( '/[?#].*$/s', '', $raw );
			if ( HTMLPP_References::resolve( $path ) === (string) $reference ) {
				$spellings[ $path ] = true;
			}
		}

		$html  = $page['html'];
		$count = 0;
		foreach ( array_keys( $spellings ) as $spelling ) {
			$result = HTMLPP_References::replace( $html, $spelling, $to );
			$html   = $result['html'];
			$count += $result['count'];
		}
		if ( 0 === $count ) {
			return new WP_Error(
				'htmlpp_reference_not_found',
				sprintf(
					/* translators: %s: file reference, e.g. images/hero.png */
					__( 'The page no longer refers to %s, so nothing was changed.', 'html-page-publisher' ),
					(string) $reference
				),
				array( 'status' => 400 )
			);
		}

		$saved = $this->update_html( $page['slug'], $html );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved['replaced'] = $count;
		return $saved;
	}

	/**
	 * Validate a path for a new file in a page's folder.
	 *
	 * @param string $reference Raw path.
	 * @return string|WP_Error Normalized path.
	 */
	private static function clean_reference( $reference ) {
		$reference = trim( str_replace( '\\', '/', (string) $reference ), '/' );
		$reference = (string) preg_replace( '#^(\./)+#', '', $reference );
		$segments  = explode( '/', $reference );
		$name      = end( $segments );
		$ext       = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$ok = '' !== $reference
			&& count( $segments ) <= 6
			&& 'index.html' !== $reference
			&& in_array( $ext, HTMLPP_Uploader::allowed_asset_extensions(), true )
			&& ! HTMLPP_Renderer::is_blocked_extension( $ext )
			&& ! HTMLPP_Renderer::has_blocked_extension_part( $name )
			&& sanitize_file_name( $name ) === $name;
		foreach ( $segments as $segment ) {
			if ( ! $ok ) {
				break;
			}
			$ok = (bool) preg_match( '/^[A-Za-z0-9_\-][A-Za-z0-9._\-]*$/', $segment );
		}

		if ( ! $ok ) {
			return new WP_Error(
				'htmlpp_bad_reference',
				__( 'That file path can’t be used. Use letters, numbers, dots, hyphens and underscores, a file type the plugin serves, and no more than five folders. To use a different name, change the reference in the HTML.', 'html-page-publisher' ),
				array( 'status' => 400 )
			);
		}
		return $reference;
	}

	/**
	 * An extension for comparing file types (jpeg and jpg are the same).
	 *
	 * @param string $ext Extension.
	 * @return string
	 */
	private static function comparable_extension( $ext ) {
		$ext = strtolower( (string) $ext );
		return 'jpeg' === $ext ? 'jpg' : $ext;
	}

	/*
	|--------------------------------------------------------------------------
	| Versions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Version history of a page, newest first.
	 *
	 * @param string $slug Slug.
	 * @return array|WP_Error
	 */
	public function versions( $slug ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return HTMLPP_Storage::list_backups( $page['slug'] );
	}

	/**
	 * Restore a version (the current one is backed up first).
	 *
	 * @param string $slug   Slug.
	 * @param string $backup Backup file name.
	 * @return array|WP_Error Page description.
	 */
	public function restore( $slug, $backup ) {
		$page = $this->get( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( HTMLPP_Uploader::file_editing_disabled() ) {
			return new WP_Error( 'htmlpp_locked', __( 'HTML editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ), array( 'status' => 403 ) );
		}
		if ( ! HTMLPP_Storage::restore_backup( $page['slug'], $backup ) ) {
			return new WP_Error( 'htmlpp_not_found', __( 'Could not restore that backup.', 'html-page-publisher' ), array( 'status' => 404 ) );
		}
		return $this->describe( $page['slug'] );
	}
}

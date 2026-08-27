<?php
/**
 * Handle upload, edit, restore, asset, page-settings and delete submissions.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form handlers for every admin mutation.
 */
class HTMLPP_Uploader {

	/**
	 * Image types accepted into a page's folder. Images are validated by
	 * wp_handle_upload() (real MIME sniffing); other file types go through
	 * move_plain_asset().
	 *
	 * @return array<string,string> wp_handle_upload() style mimes array.
	 */
	public static function allowed_image_mimes() {
		$mimes = array(
			'png'      => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'gif'      => 'image/gif',
			'svg'      => 'image/svg+xml',
			'webp'     => 'image/webp',
			'avif'     => 'image/avif',
			'ico'      => 'image/x-icon',
			'bmp'      => 'image/bmp',
		);

		/**
		 * Filter the image MIME types accepted for page assets.
		 *
		 * @param array $mimes Extension pattern => MIME type.
		 */
		return (array) apply_filters( 'htmlpp_allowed_asset_mimes', $mimes );
	}

	/**
	 * Every extension accepted as a page file (images + CSS/JS/fonts/media).
	 *
	 * @return string[]
	 */
	public static function allowed_asset_extensions() {
		$ext = HTMLPP_Zip::allowed_extensions();
		foreach ( array_keys( self::allowed_image_mimes() ) as $key ) {
			$ext = array_merge( $ext, explode( '|', (string) $key ) );
		}
		return array_values( array_unique( array_map( 'strtolower', $ext ) ) );
	}

	/**
	 * Comma-separated accept="" list for file inputs.
	 *
	 * @return string
	 */
	public static function accept_attribute() {
		return '.' . implode( ',.', self::allowed_asset_extensions() );
	}

	/**
	 * Route the request to the appropriate handler. Each handler calls
	 * check_admin_referer() as its first line.
	 *
	 * @return array|null Notice (type, message, raw_html, slug) or null if no action.
	 */
	public static function handle_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$handlers = array(
			'htmlpp_delete'        => 'handle_delete',
			'htmlpp_upload'        => 'handle_upload',
			'htmlpp_edit'          => 'handle_edit',
			'htmlpp_restore'       => 'handle_restore',
			'htmlpp_asset_upload'  => 'handle_asset_upload',
			'htmlpp_asset_replace' => 'handle_asset_replace',
			'htmlpp_asset_delete'  => 'handle_asset_delete',
			'htmlpp_page_settings' => 'handle_page_settings',
			'htmlpp_duplicate'     => 'handle_duplicate',
			'htmlpp_reset_preview' => 'handle_reset_preview',
		);

		foreach ( $handlers as $key => $method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside each handler.
			if ( isset( $_POST[ $key ] ) ) {
				return call_user_func( array( __CLASS__, $method ) );
			}
		}

		return null;
	}

	/**
	 * Shared "editing locked down" notice.
	 *
	 * @return array
	 */
	private static function editing_disabled_notice() {
		return array(
			'type'    => 'error',
			'message' => __( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ),
		);
	}

	/**
	 * Whether in-browser HTML editing is locked down.
	 *
	 * Mirrors WordPress core's file-editor lockdown: if an admin has set
	 * DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS, editing page HTML from the
	 * dashboard is disabled too (same trust surface as the core editor).
	 *
	 * @return bool
	 */
	public static function file_editing_disabled() {
		$disabled = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			|| ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );

		/**
		 * Filter whether in-dashboard editing of pages is disabled.
		 *
		 * @param bool $disabled Defaults to the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS state.
		 */
		return (bool) apply_filters( 'htmlpp_editing_disabled', $disabled );
	}

	/**
	 * Human-readable message for a PHP upload error code.
	 *
	 * @param int $code One of the UPLOAD_ERR_* constants.
	 * @return string '' when the code is UPLOAD_ERR_OK.
	 */
	public static function upload_error_message( $code ) {
		switch ( (int) $code ) {
			case UPLOAD_ERR_OK:
				return '';
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: maximum upload size, e.g. 8 MB */
					__( 'The file is larger than this server allows (%s). Ask your host to raise upload_max_filesize and post_max_size.', 'html-page-publisher' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partially uploaded. Please try again.', 'html-page-publisher' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'html-page-publisher' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not store the uploaded file (missing temp folder or disk not writable).', 'html-page-publisher' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension blocked the upload.', 'html-page-publisher' );
			default:
				return __( 'Upload failed.', 'html-page-publisher' );
		}
	}

	/**
	 * Read a slug from a POST field.
	 *
	 * @param string $key POST key.
	 * @return string Sanitized slug or ''.
	 */
	private static function post_slug( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		return HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
	}

	/**
	 * Read a relative file reference ("assets/hero.png") from POST.
	 *
	 * @return string
	 */
	private static function post_reference() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		if ( isset( $_POST['htmlpp_asset_ref'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['htmlpp_asset_ref'] ) );
		}
		if ( isset( $_POST['htmlpp_asset_name'] ) ) {
			return 'assets/' . sanitize_file_name( wp_unslash( $_POST['htmlpp_asset_name'] ) );
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Delete handler.
	 *
	 * @return array
	 */
	private static function handle_delete() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = self::post_slug( 'htmlpp_delete' );

		if ( '' !== $slug && HTMLPP_Storage::delete_page( $slug ) ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: page slug */
					__( 'Deleted "%s".', 'html-page-publisher' ),
					$slug
				),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Could not delete page.', 'html-page-publisher' ),
		);
	}

	/**
	 * Edit handler — save hand-edited HTML back to a page's index.html.
	 *
	 * @return array
	 */
	private static function handle_edit() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return array(
				'type'    => 'error',
				'message' => __( 'HTML editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ),
			);
		}

		$slug = self::post_slug( 'htmlpp_edit' );

		if ( '' === $slug || ! HTMLPP_Storage::page_exists( $slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}

		if ( ! isset( $_POST['htmlpp_content'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Nothing was saved: the submitted HTML was missing.', 'html-page-publisher' ),
			);
		}

		// The payload is raw page markup (same trust model as an uploaded
		// .html file: only manage_options users reach this). It is passed
		// through HTMLPP_Sanitizer before being written, exactly like uploads.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML is the payload; sanitized via HTMLPP_Sanitizer below.
		$raw = (string) wp_unslash( $_POST['htmlpp_content'] );
		if ( '' === trim( $raw ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Content is empty — nothing was saved.', 'html-page-publisher' ),
			);
		}

		$html = HTMLPP_Sanitizer::sanitize( $raw, $slug, 'edit' );

		if ( ! HTMLPP_Storage::write_page( $slug, $html ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not save changes. Check uploads folder permissions.', 'html-page-publisher' ),
			);
		}

		$url = HTMLPP_Storage::public_page_url( $slug );
		return array(
			'type'     => 'success',
			'message'  => sprintf(
				/* translators: %s: HTML anchor to the published page */
				__( 'Saved! The previous version was backed up. %s', 'html-page-publisher' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>'
			),
			'raw_html' => true,
		);
	}

	/**
	 * Restore handler — reinstate a previous backup as the live page.
	 *
	 * @return array
	 */
	private static function handle_restore() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return array(
				'type'    => 'error',
				'message' => __( 'HTML editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ),
			);
		}

		$slug   = self::post_slug( 'htmlpp_restore' );
		$backup = isset( $_POST['htmlpp_backup'] )
			? sanitize_file_name( wp_unslash( $_POST['htmlpp_backup'] ) )
			: '';

		if ( '' === $slug || '' === $backup ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Invalid restore request.', 'html-page-publisher' ),
			);
		}

		if ( HTMLPP_Storage::restore_backup( $slug, $backup ) ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Restored a previous version. The version it replaced was backed up.', 'html-page-publisher' ),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Could not restore that backup.', 'html-page-publisher' ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Page settings, rename, duplicate
	|--------------------------------------------------------------------------
	*/

	/**
	 * Save status, custom path, SEO flags and (optionally) a new slug.
	 *
	 * @return array
	 */
	private static function handle_page_settings() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = self::post_slug( 'htmlpp_page_settings' );
		if ( '' === $slug || ! HTMLPP_Storage::page_exists( $slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$status   = isset( $_POST['htmlpp_status'] ) && 'draft' === sanitize_key( wp_unslash( $_POST['htmlpp_status'] ) ) ? 'draft' : 'published';
		$raw_path = isset( $_POST['htmlpp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['htmlpp_path'] ) ) : '';
		$noindex  = ! empty( $_POST['htmlpp_noindex'] );
		$no_snip  = ! empty( $_POST['htmlpp_no_snippets'] );
		$new_slug = isset( $_POST['htmlpp_new_slug'] ) ? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_new_slug'] ) ) ) : '';
		// phpcs:enable

		$path = HTMLPP_Meta::sanitize_path( $raw_path );
		if ( null === $path ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That custom path is not allowed. Use lowercase letters, numbers, hyphens and slashes (e.g. promo or guides/spring), "/" for the front page, or leave it empty. Paths reserved by WordPress or starting with the plugin’s URL prefix are refused.', 'html-page-publisher' ),
			);
		}
		if ( '' !== $path ) {
			$owner = HTMLPP_Meta::slug_for_path( $path );
			if ( '' !== $owner && $owner !== $slug ) {
				return array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: 1: path, 2: page slug */
						__( 'The path "%1$s" is already used by the page "%2$s".', 'html-page-publisher' ),
						$path,
						$owner
					),
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
					if ( $post_id <= 0 ) {
						return array(
							'type'    => 'error',
							'message' => sprintf(
								/* translators: %s: path */
								__( 'The path "%s" is already in use on this site. Choose another path.', 'html-page-publisher' ),
								$path
							),
						);
					}
					return array(
						'type'     => 'error',
						'message'  => sprintf(
							/* translators: 1: path, 2: link to the WordPress post/page */
							__( 'The path "%1$s" belongs to an existing WordPress page or post (%2$s). Choose another path or change that content’s permalink first.', 'html-page-publisher' ),
							esc_html( $path ),
							'<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>'
						),
						'raw_html' => true,
					);
				}
			}
		}

		$messages = array();

		// Rename first so metadata lands on the new slug.
		if ( '' !== $new_slug && $new_slug !== $slug ) {
			if ( self::file_editing_disabled() ) {
				return self::editing_disabled_notice();
			}
			if ( HTMLPP_Storage::page_exists( $new_slug ) ) {
				return array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: page slug */
						__( 'A page with the slug "%s" already exists.', 'html-page-publisher' ),
						$new_slug
					),
				);
			}
			if ( ! HTMLPP_Storage::rename_page( $slug, $new_slug ) ) {
				return array(
					'type'    => 'error',
					'message' => __( 'The page could not be renamed. Check uploads folder permissions.', 'html-page-publisher' ),
				);
			}
			HTMLPP_Meta::rename( $slug, $new_slug );

			$messages[] = sprintf(
				/* translators: 1: old slug, 2: new slug */
				__( 'Renamed "%1$s" to "%2$s"; the old URL now redirects to the new one.', 'html-page-publisher' ),
				$slug,
				$new_slug
			);
			$slug = $new_slug;
		}

		$before = HTMLPP_Meta::get( $slug );
		HTMLPP_Meta::update(
			$slug,
			array(
				'status'      => $status,
				'path'        => $path,
				'noindex'     => $noindex,
				'no_snippets' => $no_snip,
			)
		);

		if ( 'published' === $status && 'draft' === $before['status'] ) {
			$html = HTMLPP_Storage::read_page( $slug );
			/** This action is documented in includes/class-htmlpp-uploader.php */
			do_action( 'htmlpp_page_published', $slug, is_string( $html ) ? $html : '' );
		}
		if ( '' !== $before['path'] && $before['path'] !== $path && HTMLPP_Meta::HOME !== $before['path'] ) {
			$messages[] = sprintf(
				/* translators: %s: previous custom path */
				__( 'The old path "%s" now redirects to the page.', 'html-page-publisher' ),
				$before['path']
			);
		}

		$messages[] = 'draft' === $status
			? __( 'Settings saved. The page is a draft: only administrators and anyone with the preview link can see it.', 'html-page-publisher' )
			: __( 'Settings saved.', 'html-page-publisher' );

		return array(
			'type'    => 'success',
			'message' => implode( ' ', $messages ),
			'slug'    => $slug,
		);
	}

	/**
	 * Invalidate a page's preview link.
	 *
	 * @return array
	 */
	private static function handle_reset_preview() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = self::post_slug( 'htmlpp_reset_preview' );
		if ( '' === $slug || ! HTMLPP_Storage::page_exists( $slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}
		HTMLPP_Meta::reset_preview( $slug );
		return array(
			'type'    => 'success',
			'message' => __( 'The old preview link no longer works. Share the new one from the Preview button.', 'html-page-publisher' ),
			'slug'    => $slug,
		);
	}

	/**
	 * Duplicate a page (files only) to a new slug as a draft.
	 *
	 * @return array
	 */
	private static function handle_duplicate() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug     = self::post_slug( 'htmlpp_duplicate' );
		$new_slug = self::post_slug( 'htmlpp_new_slug' );

		if ( '' === $slug || ! HTMLPP_Storage::page_exists( $slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}
		if ( '' === $new_slug ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please enter a slug for the copy.', 'html-page-publisher' ),
			);
		}
		if ( HTMLPP_Storage::page_exists( $new_slug ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: page slug */
					__( 'A page with the slug "%s" already exists.', 'html-page-publisher' ),
					$new_slug
				),
			);
		}
		if ( ! HTMLPP_Storage::copy_page( $slug, $new_slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'The page could not be copied. Check uploads folder permissions.', 'html-page-publisher' ),
			);
		}

		$source = HTMLPP_Meta::get( $slug );
		HTMLPP_Meta::update(
			$new_slug,
			array(
				'status'      => 'draft',
				'path'        => '',
				'noindex'     => ! empty( $source['noindex'] ),
				'no_snippets' => ! empty( $source['no_snippets'] ),
				'author'      => get_current_user_id(),
			)
		);
		HTMLPP_Meta::remove_redirect( $new_slug );

		/**
		 * Fires after a page has been duplicated (files copied and the new
		 * draft's metadata written).
		 *
		 * @param string $from Source slug.
		 * @param string $to   New slug.
		 */
		do_action( 'htmlpp_page_duplicated', $slug, $new_slug );

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: new page slug */
				__( 'Copied to "%s" as a draft. Edit it here, then publish it from Page settings.', 'html-page-publisher' ),
				$new_slug
			),
			'slug'    => $new_slug,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Assets
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add file(s) to an existing page's assets folder.
	 *
	 * @return array
	 */
	private static function handle_asset_upload() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return self::editing_disabled_notice();
		}

		$slug       = self::post_slug( 'htmlpp_asset_upload' );
		$assets_dir = '' !== $slug ? HTMLPP_Storage::assets_dir( $slug ) : '';
		if ( '' === $assets_dir ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}

		if ( ! wp_mkdir_p( $assets_dir ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not create the assets folder. Check uploads folder permissions.', 'html-page-publisher' ),
			);
		}

		if ( ! isset( $_FILES['image_files']['name'][0] ) || '' === $_FILES['image_files']['name'][0] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please choose at least one file to upload.', 'html-page-publisher' ),
			);
		}

		$result = self::handle_images( $assets_dir );

		if ( ! empty( $result['ok'] ) ) {
			/**
			 * Fires after files were added to a page's assets folder.
			 *
			 * @param string   $slug  Page slug.
			 * @param string[] $files Stored file names.
			 */
			do_action( 'htmlpp_assets_uploaded', $slug, $result['ok'] );
		}

		return self::images_notice(
			$result,
			__( 'Files uploaded to the page’s assets folder.', 'html-page-publisher' )
		);
	}

	/**
	 * Build a notice from a handle_images() result.
	 *
	 * @param array  $result  { ok: string[], errors: string[] }.
	 * @param string $success Message when everything succeeded.
	 * @return array
	 */
	private static function images_notice( $result, $success ) {
		if ( empty( $result['errors'] ) ) {
			return array(
				'type'    => 'success',
				'message' => $success,
			);
		}

		$message = empty( $result['ok'] )
			? __( 'No files were uploaded:', 'html-page-publisher' )
			: sprintf(
				/* translators: %d: number of files that uploaded fine */
				_n( '%d file uploaded, but there was a problem:', '%d files uploaded, but there were problems:', count( $result['ok'] ), 'html-page-publisher' ),
				count( $result['ok'] )
			);

		return array(
			'type'    => 'error',
			'message' => $message . ' ' . implode( ' ', $result['errors'] ),
		);
	}

	/**
	 * Replace one existing file in place (same name), so the HTML's
	 * existing references keep resolving.
	 *
	 * @return array
	 */
	private static function handle_asset_replace() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return self::editing_disabled_notice();
		}

		$slug      = self::post_slug( 'htmlpp_asset_replace' );
		$reference = self::post_reference();

		$target = ( '' !== $slug && '' !== $reference ) ? HTMLPP_Storage::file_path( $slug, $reference ) : '';
		if ( '' === $target ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That file could not be found.', 'html-page-publisher' ),
			);
		}

		if ( ! isset( $_FILES['htmlpp_asset_file']['name'] ) || '' === $_FILES['htmlpp_asset_file']['name'] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please choose a replacement file.', 'html-page-publisher' ),
			);
		}

		$single = self::normalize_file( $_FILES['htmlpp_asset_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field-by-field in normalize_file().

		$error = self::upload_error_message( $single['error'] );
		if ( '' !== $error ) {
			return array(
				'type'    => 'error',
				'message' => $error,
			);
		}

		$target_ext = self::normalize_ext( pathinfo( $target, PATHINFO_EXTENSION ) );
		$new_ext    = self::normalize_ext( pathinfo( $single['name'], PATHINFO_EXTENSION ) );
		if ( $target_ext !== $new_ext ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: required file extension, e.g. .png */
					__( 'The replacement must be the same file type (%s) so existing references keep working.', 'html-page-publisher' ),
					'.' . $target_ext
				),
			);
		}

		if ( '' === $single['tmp_name'] || ! is_uploaded_file( $single['tmp_name'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ),
			);
		}

		$moved = self::move_asset( $single, dirname( $target ) );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			$reason = is_array( $moved ) && ! empty( $moved['error'] ) ? ' ' . $moved['error'] : '';
			return array(
				'type'    => 'error',
				'message' => __( 'The replacement file was rejected (unsupported or invalid file).', 'html-page-publisher' ) . $reason,
			);
		}

		// The upload landed under a uniquified name; move it onto the
		// original filename so the page's existing references keep resolving.
		if ( $target !== $moved['file'] && ! HTMLPP_Storage::move( $moved['file'], $target ) ) {
			wp_delete_file( $moved['file'] );
			return array(
				'type'    => 'error',
				'message' => __( 'Could not overwrite the original file.', 'html-page-publisher' ),
			);
		}

		/**
		 * Fires after a page file was replaced in place.
		 *
		 * @param string $slug      Page slug.
		 * @param string $reference Relative file path.
		 */
		do_action( 'htmlpp_asset_replaced', $slug, $reference );

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: file reference */
				__( 'Replaced “%s”. If you still see the old file, clear your browser or CDN cache.', 'html-page-publisher' ),
				$reference
			),
		);
	}

	/**
	 * Delete one file from a page's folder.
	 *
	 * @return array
	 */
	private static function handle_asset_delete() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return self::editing_disabled_notice();
		}

		$slug      = self::post_slug( 'htmlpp_asset_delete' );
		$reference = self::post_reference();

		if ( '' === $slug || '' === $reference ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Invalid delete request.', 'html-page-publisher' ),
			);
		}

		$html             = HTMLPP_Storage::read_page( $slug );
		$still_referenced = is_string( $html ) && false !== strpos( $html, $reference );

		if ( ! HTMLPP_Storage::delete_asset( $slug, $reference ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not delete that file.', 'html-page-publisher' ),
			);
		}

		/**
		 * Fires after a page file was deleted.
		 *
		 * @param string $slug      Page slug.
		 * @param string $reference Relative file path.
		 */
		do_action( 'htmlpp_asset_deleted', $slug, $reference );

		if ( $still_referenced ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: file reference */
					__( 'Deleted “%s”, but it is still referenced in the page HTML — that file will be missing until you update the HTML.', 'html-page-publisher' ),
					$reference
				),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: file reference */
				__( 'Deleted “%s”.', 'html-page-publisher' ),
				$reference
			),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Upload (HTML file or ZIP bundle)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Upload handler.
	 *
	 * @return array
	 */
	private static function handle_upload() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = isset( $_POST['page_slug'] )
			? sanitize_title( wp_unslash( $_POST['page_slug'] ) )
			: '';

		if ( '' === $slug ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please enter a page slug.', 'html-page-publisher' ),
			);
		}

		if ( ! isset( $_FILES['html_file'] ) || ! is_array( $_FILES['html_file'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please upload an HTML file or a ZIP bundle.', 'html-page-publisher' ),
			);
		}

		$file  = self::normalize_file( $_FILES['html_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field-by-field in normalize_file().
		$error = self::upload_error_message( $file['error'] );
		if ( '' !== $error ) {
			return array(
				'type'    => 'error',
				'message' => $error,
			);
		}
		if ( '' === $file['name'] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please upload an HTML file or a ZIP bundle.', 'html-page-publisher' ),
			);
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'htm', 'zip' ), true ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Only .html, .htm or .zip files are allowed.', 'html-page-publisher' ),
			);
		}

		if ( '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ),
			);
		}

		// Overwriting an existing page is an explicit choice: it needs the
		// checkbox, respects the editing lockdown, and keeps the old version
		// in the page's history.
		$exists = HTMLPP_Storage::page_exists( $slug );
		if ( $exists ) {
			/**
			 * Filter whether an upload may replace an existing page.
			 *
			 * Defaults to the "Replace the existing page" checkbox. Return
			 * true to let automated re-publishing skip the checkbox.
			 *
			 * @param bool   $overwrite Whether the overwrite is allowed.
			 * @param string $slug      Page slug.
			 */
			$overwrite = (bool) apply_filters( 'htmlpp_allow_overwrite', ! empty( $_POST['htmlpp_overwrite'] ), $slug );
			if ( ! $overwrite ) {
				return array(
					'type'     => 'error',
					'message'  => sprintf(
						/* translators: %s: page slug */
						__( 'A page with the slug %s already exists. Tick “Replace the existing page” to overwrite it (the current version is kept in its history), or choose a different slug.', 'html-page-publisher' ),
						'<code>' . esc_html( $slug ) . '</code>'
					),
					'raw_html' => true,
				);
			}
			if ( self::file_editing_disabled() ) {
				return array(
					'type'    => 'error',
					'message' => __( 'Replacing an existing page is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ),
				);
			}
		}

		HTMLPP_Storage::ensure_dir();
		$page_dir   = HTMLPP_Storage::page_dir( $slug );
		$assets_dir = trailingslashit( $page_dir ) . 'assets';

		if ( ! wp_mkdir_p( $page_dir ) || ! wp_mkdir_p( $assets_dir ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not create page directory. Check uploads folder permissions.', 'html-page-publisher' ),
			);
		}

		$skipped = array();
		if ( 'zip' === $ext ) {
			$import = HTMLPP_Zip::import( $file['tmp_name'], $page_dir );
			if ( ! $import['ok'] ) {
				if ( ! $exists ) {
					HTMLPP_Storage::delete( $page_dir, true );
				}
				return array(
					'type'    => 'error',
					'message' => $import['error'],
				);
			}
			$html    = $import['index_html'];
			$skipped = $import['skipped'];
		} else {
			$html = HTMLPP_Storage::get_contents( $file['tmp_name'] );
			if ( false === $html ) {
				if ( ! $exists ) {
					HTMLPP_Storage::delete( $page_dir, true );
				}
				return array(
					'type'    => 'error',
					'message' => __( 'Could not read uploaded HTML.', 'html-page-publisher' ),
				);
			}
		}

		$html = HTMLPP_Sanitizer::sanitize( $html, $slug, 'upload' );

		if ( $exists ) {
			$written = HTMLPP_Storage::write_page( $slug, $html );
		} else {
			$written = HTMLPP_Storage::put_contents( trailingslashit( $page_dir ) . 'index.html', $html );
		}

		if ( ! $written ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not write index.html. Check uploads folder permissions.', 'html-page-publisher' ),
			);
		}

		$images = array(
			'ok'     => array(),
			'errors' => array(),
		);
		if ( isset( $_FILES['image_files']['name'][0] ) && '' !== $_FILES['image_files']['name'][0] ) {
			$images = self::handle_images( $assets_dir );
		}

		// "Save as draft" applies to new pages only: replacing a live page
		// never takes it offline as a side effect.
		$draft = ! $exists && ! empty( $_POST['htmlpp_draft'] );

		if ( 'zip' === $ext ) {
			/**
			 * Fires after a ZIP bundle has been unpacked into a page folder.
			 *
			 * @param string $slug   Page slug.
			 * @param array  $import Result of HTMLPP_Zip::import() (written, skipped).
			 */
			do_action( 'htmlpp_zip_imported', $slug, $import );
		}

		if ( ! $exists ) {
			HTMLPP_Meta::update(
				$slug,
				array(
					'status' => $draft ? 'draft' : 'published',
					'author' => get_current_user_id(),
				)
			);
			HTMLPP_Meta::remove_redirect( $slug );

			update_option( 'htmlpp_publish_count', (int) get_option( 'htmlpp_publish_count', 0 ) + 1, false );

			/**
			 * Fires after a brand-new page has been created (draft or published).
			 *
			 * @param string $slug   Page slug.
			 * @param string $html   The HTML that was written.
			 * @param string $status 'draft' or 'published'.
			 */
			do_action( 'htmlpp_page_created', $slug, $html, $draft ? 'draft' : 'published' );

			if ( ! $draft ) {
				/**
				 * Fires when a page becomes publicly visible: on creation as
				 * published, or when a draft is switched to published.
				 *
				 * @param string $slug Page slug.
				 * @param string $html The page HTML.
				 */
				do_action( 'htmlpp_page_published', $slug, $html );
			}
		}

		$meta = HTMLPP_Meta::get( $slug );
		$url  = HTMLPP_Meta::is_public( $meta ) ? HTMLPP_Storage::public_page_url( $slug ) : HTMLPP_Meta::preview_url( $slug );
		$link = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';

		if ( ! HTMLPP_Meta::is_public( $meta ) ) {
			/* translators: %s: HTML anchor to the preview URL */
			$message = sprintf( __( 'Saved as a draft. Preview it here (the link works without logging in): %s', 'html-page-publisher' ), $link );
		} elseif ( $exists ) {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Replaced! The previous version was saved to the page’s history. %s', 'html-page-publisher' ), $link );
		} else {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Published! %s', 'html-page-publisher' ), $link );
		}

		$problems = array();
		if ( ! empty( $skipped ) ) {
			$more       = count( $skipped ) - 10;
			$problems[] = sprintf(
				/* translators: %d: number of files skipped while unpacking a ZIP */
				_n( '%d file in the ZIP was skipped:', '%d files in the ZIP were skipped:', count( $skipped ), 'html-page-publisher' ),
				count( $skipped )
			) . ' ' . implode( ', ', array_slice( $skipped, 0, 10 ) ) . ( $more > 0 ? ' ' . sprintf(
				/* translators: %d: number of additional skipped files */
				__( '… and %d more.', 'html-page-publisher' ),
				$more
			) : '' );
		}
		if ( ! empty( $images['errors'] ) ) {
			$problems[] = sprintf(
				/* translators: %d: number of files that failed */
				_n( 'However, %d file could not be uploaded:', 'However, %d files could not be uploaded:', count( $images['errors'] ), 'html-page-publisher' ),
				count( $images['errors'] )
			) . ' ' . implode( ' ', $images['errors'] );
		}

		if ( ! empty( $problems ) ) {
			return array(
				// Skipped ZIP entries are informational; failed uploads are errors.
				'type'     => empty( $images['errors'] ) ? 'success' : 'error',
				'message'  => $message . ' ' . esc_html( implode( ' ', $problems ) ),
				'raw_html' => true,
				'slug'     => $slug,
			);
		}

		return array(
			'type'     => 'success',
			'message'  => $message,
			'raw_html' => true,
			'slug'     => $slug,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Lowercase an extension, folding jpeg → jpg.
	 *
	 * @param string $ext Extension.
	 * @return string
	 */
	private static function normalize_ext( $ext ) {
		$ext = strtolower( (string) $ext );
		return 'jpeg' === $ext ? 'jpg' : $ext;
	}

	/**
	 * Normalize one $_FILES entry into a sanitized single-file array.
	 *
	 * The tmp_name is deliberately NOT passed through wp_unslash(): it is a
	 * server-generated path (which contains backslashes on Windows), and it
	 * is validated with is_uploaded_file() before use.
	 *
	 * @param array $raw One $_FILES entry (single file).
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
	 */
	private static function normalize_file( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'name'     => isset( $raw['name'] ) ? sanitize_file_name( wp_unslash( (string) $raw['name'] ) ) : '',
			'type'     => isset( $raw['type'] ) ? sanitize_text_field( wp_unslash( (string) $raw['type'] ) ) : '',
			'tmp_name' => isset( $raw['tmp_name'] ) && is_string( $raw['tmp_name'] ) ? $raw['tmp_name'] : '',
			'error'    => isset( $raw['error'] ) ? (int) $raw['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $raw['size'] ) ? (int) $raw['size'] : 0,
		);
	}

	/**
	 * Move a normalized upload into a directory: images via
	 * wp_handle_upload() (MIME sniffing + image validation), other
	 * whitelisted types via move_plain_asset().
	 *
	 * @param array  $single Normalized file array.
	 * @param string $dir    Absolute destination directory.
	 * @return array wp_handle_upload() style result: 'file' or 'error'.
	 */
	private static function move_asset( $single, $dir ) {
		$ext = strtolower( pathinfo( $single['name'], PATHINFO_EXTENSION ) );

		$image_exts = array();
		foreach ( array_keys( self::allowed_image_mimes() ) as $key ) {
			$image_exts = array_merge( $image_exts, explode( '|', (string) $key ) );
		}

		if ( HTMLPP_Renderer::is_blocked_extension( $ext ) || HTMLPP_Renderer::has_blocked_extension_part( $single['name'] ) ) {
			return array( 'error' => __( 'Sorry, this file type is not allowed.', 'html-page-publisher' ) );
		}

		if ( in_array( $ext, $image_exts, true ) ) {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$filter = static function ( $dirs ) use ( $dir ) {
				$dirs['path']   = $dir;
				$dirs['url']    = '';
				$dirs['subdir'] = '';
				return $dirs;
			};

			add_filter( 'upload_dir', $filter );
			$result = wp_handle_upload(
				$single,
				array(
					'test_form' => false,
					'mimes'     => self::allowed_image_mimes(),
				)
			);
			remove_filter( 'upload_dir', $filter );

			return is_array( $result ) ? $result : array( 'error' => __( 'Upload failed.', 'html-page-publisher' ) );
		}

		return self::move_plain_asset( $single, $dir, $ext );
	}

	/**
	 * Store a non-image asset (CSS, JS, font, media, PDF …).
	 *
	 * Validated by extension whitelist (never anything the renderer refuses
	 * to serve) and, for text types, the absence of PHP open tags.
	 *
	 * @param array  $single Normalized file array.
	 * @param string $dir    Absolute destination directory.
	 * @param string $ext    Lowercase extension.
	 * @return array 'file' or 'error'.
	 */
	private static function move_plain_asset( $single, $dir, $ext ) {
		if ( ! in_array( $ext, HTMLPP_Zip::allowed_extensions(), true ) || HTMLPP_Renderer::is_blocked_extension( $ext ) ) {
			return array( 'error' => __( 'Sorry, this file type is not allowed.', 'html-page-publisher' ) );
		}

		// Every non-image file is scanned, whatever its extension claims.
		$content = HTMLPP_Storage::get_contents( $single['tmp_name'] );
		if ( false === $content || HTMLPP_Zip::looks_like_php( $content ) ) {
			return array( 'error' => __( 'The file contains PHP code and was refused.', 'html-page-publisher' ) );
		}
		unset( $content );

		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'error' => __( 'The destination folder could not be created.', 'html-page-publisher' ) );
		}

		$name = wp_unique_filename( $dir, $single['name'] );
		$dest = trailingslashit( $dir ) . $name;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
		if ( ! @move_uploaded_file( $single['tmp_name'], $dest ) ) {
			return array( 'error' => __( 'The file could not be moved into the page folder.', 'html-page-publisher' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		@chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		return array( 'file' => $dest );
	}

	/**
	 * Route each uploaded file into the page's assets dir.
	 * Nonce is verified by the caller in the same request.
	 *
	 * @param string $assets_dir Absolute path to the page's assets directory.
	 * @return array{ok:string[],errors:string[]} Filenames that landed, and per-file error messages.
	 */
	private static function handle_images( $assets_dir ) {
		// Re-verify nonce in this scope so static analyzers can trace it.
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$result = array(
			'ok'     => array(),
			'errors' => array(),
		);

		if ( ! isset( $_FILES['image_files']['name'] ) || ! is_array( $_FILES['image_files']['name'] ) ) {
			return $result;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry is sanitized in normalize_file().
		$names = (array) $_FILES['image_files']['name'];
		$types = isset( $_FILES['image_files']['type'] ) ? (array) $_FILES['image_files']['type'] : array();
		$tmps  = isset( $_FILES['image_files']['tmp_name'] ) ? (array) $_FILES['image_files']['tmp_name'] : array();
		$errs  = isset( $_FILES['image_files']['error'] ) ? (array) $_FILES['image_files']['error'] : array();
		$sizes = isset( $_FILES['image_files']['size'] ) ? (array) $_FILES['image_files']['size'] : array();
		// phpcs:enable

		foreach ( $names as $i => $raw_name ) {
			$single = self::normalize_file(
				array(
					'name'     => $raw_name,
					'type'     => isset( $types[ $i ] ) ? $types[ $i ] : '',
					'tmp_name' => isset( $tmps[ $i ] ) ? $tmps[ $i ] : '',
					'error'    => isset( $errs[ $i ] ) ? $errs[ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $sizes[ $i ] ) ? $sizes[ $i ] : 0,
				)
			);

			if ( '' === $single['name'] ) {
				continue;
			}

			$error = self::upload_error_message( $single['error'] );
			if ( '' !== $error ) {
				$result['errors'][] = $single['name'] . ': ' . $error;
				continue;
			}

			if ( '' === $single['tmp_name'] || ! is_uploaded_file( $single['tmp_name'] ) ) {
				$result['errors'][] = $single['name'] . ': ' . __( 'no valid temp file.', 'html-page-publisher' );
				continue;
			}

			$moved = self::move_asset( $single, $assets_dir );
			if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
				$result['errors'][] = $single['name'] . ': ' . ( ! empty( $moved['error'] ) ? $moved['error'] : __( 'rejected.', 'html-page-publisher' ) );
				continue;
			}

			$result['ok'][] = basename( $moved['file'] );
		}

		return $result;
	}
}

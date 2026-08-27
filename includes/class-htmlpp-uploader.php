<?php
/**
 * Handle upload, edit, restore, asset and delete form submissions.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form handlers for upload, edit, restore, delete and asset actions.
 */
class HTMLPP_Uploader {

	/**
	 * Image types accepted into a page's assets folder.
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
		);

		/**
		 * Filter the MIME types accepted for page assets.
		 *
		 * @param array $mimes Extension pattern => MIME type.
		 */
		return (array) apply_filters( 'htmlpp_allowed_asset_mimes', $mimes );
	}

	/**
	 * Route the request to the appropriate handler. Each handler calls
	 * check_admin_referer() as its first line, which the WordPress coding
	 * standards sniff recognizes as valid nonce verification.
	 *
	 * @return array|null Notice (type, message, raw_html, screen) or null if no action.
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

		// A POST larger than post_max_size arrives completely empty: refuse
		// rather than wipe the page.
		if ( ! isset( $_POST['htmlpp_content'] ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: maximum POST size, e.g. 8 MB */
					__( 'Nothing was saved: the submitted HTML exceeded the server’s post_max_size (%s) and was discarded by PHP.', 'html-page-publisher' ),
					size_format( wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) )
				),
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

	/**
	 * Add new image(s) to an existing page's assets folder.
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
				'message' => __( 'Please choose at least one image to upload.', 'html-page-publisher' ),
			);
		}

		$result = self::handle_images( $assets_dir );

		return self::images_notice(
			$result,
			__( 'Images uploaded to the page’s assets folder.', 'html-page-publisher' )
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
			? __( 'No images were uploaded:', 'html-page-publisher' )
			: sprintf(
				/* translators: %d: number of images that uploaded fine */
				_n( '%d image uploaded, but there was a problem:', '%d images uploaded, but there were problems:', count( $result['ok'] ), 'html-page-publisher' ),
				count( $result['ok'] )
			);

		return array(
			'type'    => 'error',
			'message' => $message . ' ' . implode( ' ', $result['errors'] ),
		);
	}

	/**
	 * Replace one existing asset in place (same filename), so the HTML's
	 * existing references keep resolving.
	 *
	 * @return array
	 */
	private static function handle_asset_replace() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return self::editing_disabled_notice();
		}

		$slug = self::post_slug( 'htmlpp_asset_replace' );
		$name = isset( $_POST['htmlpp_asset_name'] )
			? sanitize_file_name( wp_unslash( $_POST['htmlpp_asset_name'] ) )
			: '';

		$target = ( '' !== $slug && '' !== $name ) ? HTMLPP_Storage::asset_path( $slug, $name ) : '';
		if ( '' === $target ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That image could not be found.', 'html-page-publisher' ),
			);
		}

		if ( ! isset( $_FILES['htmlpp_asset_file']['name'] ) || '' === $_FILES['htmlpp_asset_file']['name'] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please choose a replacement image.', 'html-page-publisher' ),
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

		$target_ext = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		$new_ext    = strtolower( pathinfo( $single['name'], PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $new_ext ) {
			$new_ext = 'jpg';
		}
		if ( 'jpeg' === $target_ext ) {
			$target_ext = 'jpg';
		}
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

		$moved = self::move_upload( $single, HTMLPP_Storage::assets_dir( $slug ) );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			$reason = is_array( $moved ) && ! empty( $moved['error'] ) ? ' ' . $moved['error'] : '';
			return array(
				'type'    => 'error',
				'message' => __( 'The replacement image was rejected (unsupported or invalid file).', 'html-page-publisher' ) . $reason,
			);
		}

		// wp_handle_upload() uniquifies the name; move it onto the original
		// filename so the page's existing references keep resolving.
		if ( $target !== $moved['file'] && ! HTMLPP_Storage::move( $moved['file'], $target ) ) {
			wp_delete_file( $moved['file'] );
			return array(
				'type'    => 'error',
				'message' => __( 'Could not overwrite the original image.', 'html-page-publisher' ),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: image filename */
				__( 'Replaced “%s”. If you still see the old image, clear your browser or CDN cache.', 'html-page-publisher' ),
				$name
			),
		);
	}

	/**
	 * Delete one asset from a page's assets folder.
	 *
	 * @return array
	 */
	private static function handle_asset_delete() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( self::file_editing_disabled() ) {
			return self::editing_disabled_notice();
		}

		$slug = self::post_slug( 'htmlpp_asset_delete' );
		$name = isset( $_POST['htmlpp_asset_name'] )
			? sanitize_file_name( wp_unslash( $_POST['htmlpp_asset_name'] ) )
			: '';

		if ( '' === $slug || '' === $name ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Invalid delete request.', 'html-page-publisher' ),
			);
		}

		$html             = HTMLPP_Storage::read_page( $slug );
		$still_referenced = is_string( $html ) && false !== strpos( $html, 'assets/' . $name );

		if ( ! HTMLPP_Storage::delete_asset( $slug, $name ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not delete that image.', 'html-page-publisher' ),
			);
		}

		if ( $still_referenced ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: image filename */
					__( 'Deleted “%s”, but it is still referenced in the page HTML — that image will be broken until you update the HTML.', 'html-page-publisher' ),
					$name
				),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: image filename */
				__( 'Deleted “%s”.', 'html-page-publisher' ),
				$name
			),
		);
	}

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
				'message' => __( 'Please upload an HTML file.', 'html-page-publisher' ),
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
				'message' => __( 'Please upload an HTML file.', 'html-page-publisher' ),
			);
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'html' !== $ext && 'htm' !== $ext ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Only .html or .htm files are allowed.', 'html-page-publisher' ),
			);
		}

		if ( '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ),
			);
		}

		$html = HTMLPP_Storage::get_contents( $file['tmp_name'] );
		if ( false === $html ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not read uploaded HTML.', 'html-page-publisher' ),
			);
		}
		$html = HTMLPP_Sanitizer::sanitize( $html, $slug, 'upload' );

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

		if ( ! $exists ) {
			update_option( 'htmlpp_publish_count', (int) get_option( 'htmlpp_publish_count', 0 ) + 1, false );

			/**
			 * Fires after a brand-new page has been published.
			 *
			 * @param string $slug Page slug.
			 * @param string $html The HTML that was written.
			 */
			do_action( 'htmlpp_page_published', $slug, $html );
		}

		$url  = HTMLPP_Storage::public_page_url( $slug );
		$link = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';

		if ( $exists ) {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Replaced! The previous version was saved to the page’s history. %s', 'html-page-publisher' ), $link );
		} else {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Published! %s', 'html-page-publisher' ), $link );
		}

		if ( ! empty( $images['errors'] ) ) {
			return array(
				'type'     => 'error',
				'message'  => $message . ' ' . esc_html(
					sprintf(
						/* translators: %d: number of images that failed */
						_n( 'However, %d image could not be uploaded:', 'However, %d images could not be uploaded:', count( $images['errors'] ), 'html-page-publisher' ),
						count( $images['errors'] )
					) . ' ' . implode( ' ', $images['errors'] )
				),
				'raw_html' => true,
			);
		}

		return array(
			'type'     => 'success',
			'message'  => $message,
			'raw_html' => true,
		);
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
	 * Move a normalized upload into a page's assets directory via
	 * wp_handle_upload() (MIME sniffing + image validation included).
	 *
	 * @param array  $single     Normalized file array.
	 * @param string $assets_dir Absolute path to the assets directory.
	 * @return array wp_handle_upload() result.
	 */
	private static function move_upload( $single, $assets_dir ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filter = static function ( $dirs ) use ( $assets_dir ) {
			$dirs['path']   = $assets_dir;
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

	/**
	 * Route each image through wp_handle_upload into the page's assets dir.
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

			$moved = self::move_upload( $single, $assets_dir );
			if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
				$result['errors'][] = $single['name'] . ': ' . ( ! empty( $moved['error'] ) ? $moved['error'] : __( 'rejected.', 'html-page-publisher' ) );
				continue;
			}

			$result['ok'][] = basename( $moved['file'] );
		}

		return $result;
	}
}

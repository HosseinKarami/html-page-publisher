<?php
/**
 * Admin form handlers: parse the request, call HTMLPP_Page_Service, return a
 * notice. All page logic lives in the service so REST and WP-CLI share it.
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

	/*
	|--------------------------------------------------------------------------
	| Dispatch
	|--------------------------------------------------------------------------
	*/

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
	 * Service instance.
	 *
	 * @return HTMLPP_Page_Service
	 */
	private static function service() {
		return htmlpp()->pages;
	}

	/**
	 * Notice from a WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private static function error_notice( $error ) {
		return array(
			'type'    => 'error',
			'message' => $error->get_error_message(),
		);
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
	 * Link markup for a page URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function link( $url ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
	}

	/*
	|--------------------------------------------------------------------------
	| Handlers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete handler.
	 *
	 * @return array
	 */
	private static function handle_delete() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug   = self::post_slug( 'htmlpp_delete' );
		$result = self::service()->delete( $slug );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: page slug */
				__( 'Deleted "%s".', 'html-page-publisher' ),
				$slug
			),
		);
	}

	/**
	 * Edit handler — save hand-edited HTML back to a page's index.html.
	 *
	 * @return array
	 */
	private static function handle_edit() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = self::post_slug( 'htmlpp_edit' );

		if ( ! isset( $_POST['htmlpp_content'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Nothing was saved: the submitted HTML was missing.', 'html-page-publisher' ),
			);
		}

		// The payload is raw page markup (same trust model as an uploaded
		// .html file: only manage_options users reach this). The service
		// passes it through HTMLPP_Sanitizer before writing, exactly like uploads.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML is the payload; sanitized via HTMLPP_Sanitizer in the service.
		$result = self::service()->update_html( $slug, (string) wp_unslash( $_POST['htmlpp_content'] ) );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}

		return array(
			'type'     => 'success',
			'message'  => sprintf(
				/* translators: %s: HTML anchor to the published page */
				__( 'Saved! The previous version was backed up. %s', 'html-page-publisher' ),
				self::link( $result['url'] )
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

		$slug   = self::post_slug( 'htmlpp_restore' );
		$backup = isset( $_POST['htmlpp_backup'] ) ? sanitize_file_name( wp_unslash( $_POST['htmlpp_backup'] ) ) : '';
		$result = self::service()->restore( $slug, $backup );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
		return array(
			'type'    => 'success',
			'message' => __( 'Restored a previous version. The version it replaced was backed up.', 'html-page-publisher' ),
		);
	}

	/**
	 * Save status, custom path, SEO flags and (optionally) a new slug.
	 *
	 * @return array
	 */
	private static function handle_page_settings() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = self::post_slug( 'htmlpp_page_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$fields   = array(
			'status'      => isset( $_POST['htmlpp_status'] ) && 'draft' === sanitize_key( wp_unslash( $_POST['htmlpp_status'] ) ) ? 'draft' : 'published',
			'path'        => isset( $_POST['htmlpp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['htmlpp_path'] ) ) : '',
			'noindex'     => ! empty( $_POST['htmlpp_noindex'] ),
			'no_snippets' => ! empty( $_POST['htmlpp_no_snippets'] ),
		);
		$new_slug = isset( $_POST['htmlpp_new_slug'] ) ? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_new_slug'] ) ) ) : '';
		// phpcs:enable

		$messages = array();

		// Rename first so metadata lands on the new slug.
		if ( '' !== $new_slug && $new_slug !== $slug ) {
			$result = self::service()->rename( $slug, $new_slug );
			if ( is_wp_error( $result ) ) {
				return self::error_notice( $result );
			}
			$messages[] = sprintf(
				/* translators: 1: old slug, 2: new slug */
				__( 'Renamed "%1$s" to "%2$s"; the old URL now redirects to the new one.', 'html-page-publisher' ),
				$slug,
				$new_slug
			);
			$slug = $new_slug;
		}

		$result = self::service()->set_meta( $slug, $fields );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
		$messages   = array_merge( $messages, $result['messages'] );
		$messages[] = 'draft' === $result['status']
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

		$slug   = self::post_slug( 'htmlpp_reset_preview' );
		$result = self::service()->reset_preview( $slug );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
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

		$result = self::service()->duplicate( self::post_slug( 'htmlpp_duplicate' ), self::post_slug( 'htmlpp_new_slug' ) );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %s: new page slug */
				__( 'Copied to "%s" as a draft. Edit it here, then publish it from Page Settings.', 'html-page-publisher' ),
				$result['slug']
			),
			'slug'    => $result['slug'],
		);
	}

	/**
	 * Add file(s) to an existing page's assets folder.
	 *
	 * @return array
	 */
	private static function handle_asset_upload() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug  = self::post_slug( 'htmlpp_asset_upload' );
		$batch = self::posted_files();
		if ( empty( $batch ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please choose at least one file to upload.', 'html-page-publisher' ),
			);
		}

		$result = self::store_batch( $slug, $batch );
		return self::files_notice( $result, __( 'Files uploaded to the page’s assets folder.', 'html-page-publisher' ) );
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
			return array(
				'type'    => 'error',
				'message' => __( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ),
			);
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

		if ( self::normalize_ext( pathinfo( $target, PATHINFO_EXTENSION ) ) !== self::normalize_ext( pathinfo( $single['name'], PATHINFO_EXTENSION ) ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: required file extension, e.g. .png */
					__( 'The replacement must be the same file type (%s) so existing references keep working.', 'html-page-publisher' ),
					'.' . strtolower( pathinfo( $target, PATHINFO_EXTENSION ) )
				),
			);
		}

		$moved = self::move_asset( $single, dirname( $target ), 'upload' );
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'The replacement file was rejected (unsupported or invalid file).', 'html-page-publisher' ) . ( ! empty( $moved['error'] ) ? ' ' . $moved['error'] : '' ),
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

		$reference = self::post_reference();
		$result    = self::service()->delete_file( self::post_slug( 'htmlpp_asset_delete' ), $reference );
		if ( is_wp_error( $result ) ) {
			return self::error_notice( $result );
		}
		if ( $result['still_referenced'] ) {
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

	/**
	 * Upload handler: HTML file or ZIP bundle, optional files, draft flag.
	 *
	 * @return array
	 */
	private static function handle_upload() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = isset( $_POST['page_slug'] ) ? sanitize_title( wp_unslash( $_POST['page_slug'] ) ) : '';

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
		if ( '' === $file['name'] || '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please upload an HTML file or a ZIP bundle.', 'html-page-publisher' ),
			);
		}

		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$args = array(
			'status'    => empty( $_POST['htmlpp_draft'] ) ? 'published' : 'draft',
			'overwrite' => ! empty( $_POST['htmlpp_overwrite'] ),
			'files'     => self::posted_files(),
			'mode'      => 'upload',
		);
		if ( 'zip' === $ext ) {
			$args['zip'] = $file['tmp_name'];
		} elseif ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			$html = HTMLPP_Storage::get_contents( $file['tmp_name'] );
			if ( false === $html ) {
				return array(
					'type'    => 'error',
					'message' => __( 'Could not read uploaded HTML.', 'html-page-publisher' ),
				);
			}
			$args['html'] = $html;
		} else {
			return array(
				'type'    => 'error',
				'message' => __( 'Only .html, .htm or .zip files are allowed.', 'html-page-publisher' ),
			);
		}

		$page = self::service()->create( $slug, $args );
		if ( is_wp_error( $page ) ) {
			$notice = self::error_notice( $page );
			if ( 'htmlpp_exists' === $page->get_error_code() ) {
				$notice['message'] = sprintf(
					/* translators: %s: page slug */
					__( 'A page with the slug %s already exists. Tick “Replace the existing page” to overwrite it (the current version is kept in its history), or choose a different slug.', 'html-page-publisher' ),
					'<code>' . esc_html( $slug ) . '</code>'
				);
				$notice['raw_html'] = true;
			}
			return $notice;
		}

		$link = self::link( 'draft' === $page['status'] ? $page['preview_url'] : $page['url'] );
		if ( 'draft' === $page['status'] ) {
			/* translators: %s: HTML anchor to the preview URL */
			$message = sprintf( __( 'Saved as a draft. Preview it here (the link works without logging in): %s', 'html-page-publisher' ), $link );
		} elseif ( ! $page['created'] ) {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Replaced! The previous version was saved to the page’s history. %s', 'html-page-publisher' ), $link );
		} else {
			/* translators: %s: HTML anchor to the published page */
			$message = sprintf( __( 'Published! %s', 'html-page-publisher' ), $link );
		}

		$problems = array();
		if ( ! empty( $page['skipped'] ) ) {
			$more       = count( $page['skipped'] ) - 10;
			$problems[] = sprintf(
				/* translators: %d: number of files skipped while unpacking a ZIP */
				_n( '%d file in the ZIP was skipped:', '%d files in the ZIP were skipped:', count( $page['skipped'] ), 'html-page-publisher' ),
				count( $page['skipped'] )
			) . ' ' . implode( ', ', array_slice( $page['skipped'], 0, 10 ) ) . ( $more > 0 ? ' ' . sprintf(
				/* translators: %d: number of additional skipped files */
				__( '… and %d more.', 'html-page-publisher' ),
				$more
			) : '' );
		}
		if ( ! empty( $page['file_errors'] ) ) {
			$problems[] = sprintf(
				/* translators: %d: number of files that failed */
				_n( 'However, %d file could not be uploaded:', 'However, %d files could not be uploaded:', count( $page['file_errors'] ), 'html-page-publisher' ),
				count( $page['file_errors'] )
			) . ' ' . implode( ' ', $page['file_errors'] );
		}

		return array(
			// Skipped ZIP entries are informational; failed uploads are errors.
			'type'     => empty( $page['file_errors'] ) ? 'success' : 'error',
			'message'  => $message . ( $problems ? ' ' . esc_html( implode( ' ', $problems ) ) : '' ),
			'raw_html' => true,
			'slug'     => $page['slug'],
		);
	}

	/*
	|--------------------------------------------------------------------------
	| File helpers (shared with HTMLPP_Page_Service)
	|--------------------------------------------------------------------------
	*/

	/**
	 * The image_files[] input as a list of single-file arrays.
	 *
	 * @return array[]
	 */
	private static function posted_files() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers verify the nonce first; each entry is sanitized in normalize_file().
		if ( ! isset( $_FILES['image_files']['name'] ) || ! is_array( $_FILES['image_files']['name'] ) ) {
			return array();
		}
		$names = (array) $_FILES['image_files']['name'];
		$types = isset( $_FILES['image_files']['type'] ) ? (array) $_FILES['image_files']['type'] : array();
		$tmps  = isset( $_FILES['image_files']['tmp_name'] ) ? (array) $_FILES['image_files']['tmp_name'] : array();
		$errs  = isset( $_FILES['image_files']['error'] ) ? (array) $_FILES['image_files']['error'] : array();
		$sizes = isset( $_FILES['image_files']['size'] ) ? (array) $_FILES['image_files']['size'] : array();
		// phpcs:enable

		$batch = array();
		foreach ( $names as $i => $name ) {
			if ( '' === (string) $name ) {
				continue;
			}
			$batch[] = array(
				'name'     => $name,
				'type'     => isset( $types[ $i ] ) ? $types[ $i ] : '',
				'tmp_name' => isset( $tmps[ $i ] ) ? $tmps[ $i ] : '',
				'error'    => isset( $errs[ $i ] ) ? $errs[ $i ] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $sizes[ $i ] ) ? $sizes[ $i ] : 0,
			);
		}
		return $batch;
	}

	/**
	 * Store a batch of uploaded files on a page.
	 *
	 * @param string  $slug  Page slug.
	 * @param array[] $batch Single-file arrays.
	 * @return array{ok:string[],errors:string[]}
	 */
	private static function store_batch( $slug, array $batch ) {
		$result = array(
			'ok'     => array(),
			'errors' => array(),
		);
		foreach ( $batch as $file ) {
			$single = self::normalize_file( $file );
			$error  = self::upload_error_message( $single['error'] );
			if ( '' !== $error ) {
				$result['errors'][] = $single['name'] . ': ' . $error;
				continue;
			}
			$stored = self::service()->store_file( $slug, $single, 'upload' );
			if ( is_wp_error( $stored ) ) {
				$result['errors'][] = $single['name'] . ': ' . $stored->get_error_message();
			} else {
				$result['ok'][] = $stored;
			}
		}
		return $result;
	}

	/**
	 * Build a notice from a store_batch() result.
	 *
	 * @param array  $result  { ok: string[], errors: string[] }.
	 * @param string $success Message when everything succeeded.
	 * @return array
	 */
	private static function files_notice( $result, $success ) {
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
	 * is validated with is_uploaded_file() (or is_readable() for sideloads)
	 * before use.
	 *
	 * @param array $raw One $_FILES entry (single file).
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
	 */
	public static function normalize_file( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'name'     => isset( $raw['name'] ) ? sanitize_file_name( wp_unslash( (string) $raw['name'] ) ) : '',
			'type'     => isset( $raw['type'] ) ? sanitize_text_field( wp_unslash( (string) $raw['type'] ) ) : '',
			'tmp_name' => isset( $raw['tmp_name'] ) && is_string( $raw['tmp_name'] ) ? $raw['tmp_name'] : '',
			'error'    => isset( $raw['error'] ) ? (int) $raw['error'] : 0,
			'size'     => isset( $raw['size'] ) ? (int) $raw['size'] : 0,
		);
	}

	/**
	 * Move a file into a page directory: images via WordPress (real MIME
	 * sniffing + image validation), other whitelisted types via
	 * move_plain_asset(). Blocked extensions are refused whatever a filter says.
	 *
	 * @param array  $single Normalized file array.
	 * @param string $dir    Absolute destination directory.
	 * @param string $mode   'upload' (browser upload, is_uploaded_file) or 'sideload' (local file).
	 * @return array wp_handle_upload() style result: 'file' or 'error'.
	 */
	public static function move_asset( $single, $dir, $mode = 'upload' ) {
		$ext = strtolower( pathinfo( $single['name'], PATHINFO_EXTENSION ) );

		if ( '' === $single['name'] || '' === $single['tmp_name'] ) {
			return array( 'error' => __( 'No file was uploaded.', 'html-page-publisher' ) );
		}
		if ( 'upload' === $mode && ! is_uploaded_file( $single['tmp_name'] ) ) {
			return array( 'error' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ) );
		}
		if ( 'sideload' === $mode && ! is_readable( $single['tmp_name'] ) ) {
			return array( 'error' => __( 'The file could not be read.', 'html-page-publisher' ) );
		}
		if ( HTMLPP_Renderer::is_blocked_extension( $ext ) || HTMLPP_Renderer::has_blocked_extension_part( $single['name'] ) ) {
			return array( 'error' => __( 'Sorry, this file type is not allowed.', 'html-page-publisher' ) );
		}

		$image_exts = array();
		foreach ( array_keys( self::allowed_image_mimes() ) as $key ) {
			$image_exts = array_merge( $image_exts, explode( '|', (string) $key ) );
		}

		if ( in_array( $ext, $image_exts, true ) ) {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$filter    = static function ( $dirs ) use ( $dir ) {
				$dirs['path']   = $dir;
				$dirs['url']    = '';
				$dirs['subdir'] = '';
				return $dirs;
			};
			$overrides = array(
				'test_form' => false,
				'mimes'     => self::allowed_image_mimes(),
			);

			add_filter( 'upload_dir', $filter );
			$result = 'sideload' === $mode ? wp_handle_sideload( $single, $overrides ) : wp_handle_upload( $single, $overrides );
			remove_filter( 'upload_dir', $filter );

			return is_array( $result ) ? $result : array( 'error' => __( 'Upload failed.', 'html-page-publisher' ) );
		}

		return self::move_plain_asset( $single, $dir, $ext, $mode );
	}

	/**
	 * Store a non-image asset (CSS, JS, font, media, PDF …).
	 *
	 * Validated by extension whitelist (never anything the renderer refuses
	 * to serve) and a scan of the whole file for PHP open tags.
	 *
	 * @param array  $single Normalized file array.
	 * @param string $dir    Absolute destination directory.
	 * @param string $ext    Lowercase extension.
	 * @param string $mode   'upload' or 'sideload'.
	 * @return array 'file' or 'error'.
	 */
	private static function move_plain_asset( $single, $dir, $ext, $mode ) {
		if ( ! in_array( $ext, HTMLPP_Zip::allowed_extensions(), true ) ) {
			return array( 'error' => __( 'Sorry, this file type is not allowed.', 'html-page-publisher' ) );
		}

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
		$moved = 'sideload' === $mode ? HTMLPP_Storage::move( $single['tmp_name'], $dest ) : @move_uploaded_file( $single['tmp_name'], $dest );
		if ( ! $moved ) {
			return array( 'error' => __( 'The file could not be moved into the page folder.', 'html-page-publisher' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		@chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		return array( 'file' => $dest );
	}
}

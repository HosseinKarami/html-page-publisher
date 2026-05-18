<?php
/**
 * Handle upload and delete form submissions.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTMLPP_Uploader {

	/**
	 * Route the request to the appropriate handler. Each handler calls
	 * check_admin_referer() as its first line, which the WordPress coding
	 * standards sniff recognizes as valid nonce verification.
	 *
	 * @return array|null Notice (type, message, raw_html) or null if no action.
	 */
	public static function handle_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_delete'] ) ) {
			return self::handle_delete();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_upload'] ) ) {
			return self::handle_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_edit'] ) ) {
			return self::handle_edit();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_restore'] ) ) {
			return self::handle_restore();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_asset_upload'] ) ) {
			return self::handle_asset_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_asset_replace'] ) ) {
			return self::handle_asset_replace();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dispatch only; nonce verified inside handler.
		if ( isset( $_POST['htmlpp_asset_delete'] ) ) {
			return self::handle_asset_delete();
		}

		return null;
	}

	/**
	 * Shared "editing locked down" notice for asset handlers.
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
		return ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			|| ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	/**
	 * Delete handler.
	 *
	 * @return array
	 */
	private static function handle_delete() {
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		$slug = isset( $_POST['htmlpp_delete'] )
			? sanitize_file_name( wp_unslash( $_POST['htmlpp_delete'] ) )
			: '';

		if ( HTMLPP_Storage::delete_page( $slug ) ) {
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

		$slug = isset( $_POST['htmlpp_edit'] )
			? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_edit'] ) ) )
			: '';

		if ( '' === $slug || false === HTMLPP_Storage::read_page( $slug ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That page could not be found.', 'html-page-publisher' ),
			);
		}

		// The payload is raw page markup (same trust model as an uploaded
		// .html file: only manage_options users reach this). It is passed
		// through HTMLPP_Sanitizer before being written, exactly like uploads.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML is the payload; sanitized via HTMLPP_Sanitizer below.
		$raw = isset( $_POST['htmlpp_content'] ) ? (string) wp_unslash( $_POST['htmlpp_content'] ) : '';
		if ( '' === trim( $raw ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Content is empty — nothing was saved.', 'html-page-publisher' ),
			);
		}

		$html = HTMLPP_Sanitizer::sanitize( $raw );

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

		$slug = isset( $_POST['htmlpp_restore'] )
			? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_restore'] ) ) )
			: '';
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

		$slug = isset( $_POST['htmlpp_asset_upload'] )
			? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_asset_upload'] ) ) )
			: '';

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

		self::handle_images( $assets_dir );

		return array(
			'type'    => 'success',
			'message' => __( 'Images uploaded to the page’s assets folder.', 'html-page-publisher' ),
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

		$slug = isset( $_POST['htmlpp_asset_replace'] )
			? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_asset_replace'] ) ) )
			: '';
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_file_name().
		$new_name   = sanitize_file_name( wp_unslash( $_FILES['htmlpp_asset_file']['name'] ) );
		$target_ext = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		$new_ext    = strtolower( pathinfo( $new_name, PATHINFO_EXTENSION ) );

		if ( $new_ext !== $target_ext ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: required file extension, e.g. .png */
					__( 'The replacement must be the same file type (%s) so existing references keep working.', 'html-page-publisher' ),
					'.' . $target_ext
				),
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$single = array(
			'name'     => $new_name,
			'type'     => isset( $_FILES['htmlpp_asset_file']['type'] )
				? sanitize_text_field( wp_unslash( $_FILES['htmlpp_asset_file']['type'] ) )
				: '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_text_field().
			'tmp_name' => isset( $_FILES['htmlpp_asset_file']['tmp_name'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_text_field().
				? sanitize_text_field( wp_unslash( $_FILES['htmlpp_asset_file']['tmp_name'] ) )
				: '',
			'error'    => isset( $_FILES['htmlpp_asset_file']['error'] ) ? (int) $_FILES['htmlpp_asset_file']['error'] : 0,
			'size'     => isset( $_FILES['htmlpp_asset_file']['size'] ) ? (int) $_FILES['htmlpp_asset_file']['size'] : 0,
		);

		if ( '' === $single['tmp_name'] || ! is_uploaded_file( $single['tmp_name'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ),
			);
		}

		$allowed_mimes = array(
			'png'      => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'gif'      => 'image/gif',
			'svg'      => 'image/svg+xml',
			'webp'     => 'image/webp',
			'avif'     => 'image/avif',
		);

		$assets_dir = HTMLPP_Storage::assets_dir( $slug );
		$filter     = static function ( $dirs ) use ( $assets_dir ) {
			$dirs['path']   = $assets_dir;
			$dirs['url']    = '';
			$dirs['subdir'] = '';
			return $dirs;
		};

		add_filter( 'upload_dir', $filter );
		$moved = wp_handle_upload(
			$single,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);
		remove_filter( 'upload_dir', $filter );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'The replacement image was rejected (unsupported or invalid file).', 'html-page-publisher' ),
			);
		}

		$fs = HTMLPP_Storage::fs();
		if ( ! $fs ) {
			wp_delete_file( $moved['file'] );
			return array(
				'type'    => 'error',
				'message' => __( 'Filesystem is not writable.', 'html-page-publisher' ),
			);
		}

		// wp_handle_upload() uniquifies the name; move it onto the original
		// filename so the page's existing references keep resolving.
		if ( $moved['file'] !== $target && ! $fs->move( $moved['file'], $target, true ) ) {
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

		$slug = isset( $_POST['htmlpp_asset_delete'] )
			? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['htmlpp_asset_delete'] ) ) )
			: '';
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

		if ( ! isset( $_FILES['html_file']['name'] ) || '' === $_FILES['html_file']['name'] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please upload an HTML file.', 'html-page-publisher' ),
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_file_name().
		$uploaded_name = sanitize_file_name( wp_unslash( $_FILES['html_file']['name'] ) );
		$ext           = strtolower( pathinfo( $uploaded_name, PATHINFO_EXTENSION ) );
		if ( 'html' !== $ext && 'htm' !== $ext ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Only .html or .htm files are allowed.', 'html-page-publisher' ),
			);
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_text_field().
		$tmp = isset( $_FILES['html_file']['tmp_name'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_text_field().
			? sanitize_text_field( wp_unslash( $_FILES['html_file']['tmp_name'] ) )
			: '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Upload failed (no valid temp file).', 'html-page-publisher' ),
			);
		}

		$fs = HTMLPP_Storage::fs();
		if ( ! $fs ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Filesystem is not writable.', 'html-page-publisher' ),
			);
		}

		$html = $fs->get_contents( $tmp );
		if ( false === $html ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not read uploaded HTML.', 'html-page-publisher' ),
			);
		}

		$html       = HTMLPP_Sanitizer::sanitize( $html );
		$index_file = trailingslashit( $page_dir ) . 'index.html';

		if ( ! $fs->put_contents( $index_file, $html ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not write index.html.', 'html-page-publisher' ),
			);
		}

		if ( isset( $_FILES['image_files']['name'][0] ) && '' !== $_FILES['image_files']['name'][0] ) {
			self::handle_images( $assets_dir );
		}

		$url = HTMLPP_Storage::public_page_url( $slug );
		return array(
			'type'     => 'success',
			'message'  => sprintf(
				/* translators: %s: HTML anchor to the published page */
				__( 'Published! %s', 'html-page-publisher' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>'
			),
			'raw_html' => true,
		);
	}

	/**
	 * Route each image through wp_handle_upload into the page's assets dir.
	 * Nonce is verified by the caller (handle_upload) in the same request.
	 *
	 * @param string $assets_dir Absolute path to the page's assets directory.
	 */
	private static function handle_images( $assets_dir ) {
		// Re-verify nonce in this scope so static analyzers can trace it.
		check_admin_referer( 'htmlpp_action', 'htmlpp_nonce' );

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! isset( $_FILES['image_files']['name'] ) || ! is_array( $_FILES['image_files']['name'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized member-by-member below.
		$raw_names = (array) wp_unslash( $_FILES['image_files']['name'] );
		$raw_types = isset( $_FILES['image_files']['type'] ) && is_array( $_FILES['image_files']['type'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized member-by-member below.
			? (array) wp_unslash( $_FILES['image_files']['type'] )
			: array();
		$raw_tmps = isset( $_FILES['image_files']['tmp_name'] ) && is_array( $_FILES['image_files']['tmp_name'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized member-by-member below.
			? (array) wp_unslash( $_FILES['image_files']['tmp_name'] )
			: array();
		$raw_errs = isset( $_FILES['image_files']['error'] ) && is_array( $_FILES['image_files']['error'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int per-entry in the loop.
			? (array) $_FILES['image_files']['error']
			: array();
		$raw_sizes = isset( $_FILES['image_files']['size'] ) && is_array( $_FILES['image_files']['size'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int per-entry in the loop.
			? (array) $_FILES['image_files']['size']
			: array();

		$allowed_mimes = array(
			'png'      => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'gif'      => 'image/gif',
			'svg'      => 'image/svg+xml',
			'webp'     => 'image/webp',
			'avif'     => 'image/avif',
		);

		foreach ( $raw_names as $i => $raw_name ) {
			$name = sanitize_file_name( (string) $raw_name );
			if ( '' === $name ) {
				continue;
			}

			$single = array(
				'name'     => $name,
				'type'     => isset( $raw_types[ $i ] ) ? sanitize_text_field( (string) $raw_types[ $i ] ) : '',
				'tmp_name' => isset( $raw_tmps[ $i ] ) ? sanitize_text_field( (string) $raw_tmps[ $i ] ) : '',
				'error'    => isset( $raw_errs[ $i ] ) ? (int) $raw_errs[ $i ] : 0,
				'size'     => isset( $raw_sizes[ $i ] ) ? (int) $raw_sizes[ $i ] : 0,
			);

			$filter = static function ( $dirs ) use ( $assets_dir ) {
				$dirs['path']   = $assets_dir;
				$dirs['url']    = '';
				$dirs['subdir'] = '';
				return $dirs;
			};

			add_filter( 'upload_dir', $filter );
			wp_handle_upload(
				$single,
				array(
					'test_form' => false,
					'mimes'     => $allowed_mimes,
				)
			);
			remove_filter( 'upload_dir', $filter );
		}
	}
}

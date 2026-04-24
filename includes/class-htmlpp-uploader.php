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

		return null;
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

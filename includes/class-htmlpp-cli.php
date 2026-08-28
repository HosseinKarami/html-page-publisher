<?php
/**
 * WP-CLI: wp htmlpp <command>
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage HTML pages from the command line.
 */
class HTMLPP_CLI extends WP_CLI_Command {

	/**
	 * Service instance.
	 *
	 * @return HTMLPP_Page_Service
	 */
	private function service() {
		return htmlpp()->pages;
	}

	/**
	 * Exit with a WP_Error's message.
	 *
	 * @param mixed $result Result to check.
	 * @return mixed The result when it is not an error.
	 */
	private function check( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * List pages.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids. Default: table.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default: slug,status,url,title,files,modified.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp list
	 *     wp htmlpp list --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames -- WP-CLI convention.
		$pages = $this->service()->list_pages();
		foreach ( $pages as &$page ) {
			$page['modified'] = $page['modified'] ? gmdate( 'Y-m-d H:i', $page['modified'] ) : '';
		}
		unset( $page );
		$fields = isset( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : array( 'slug', 'status', 'url', 'title', 'files', 'modified' );
		if ( isset( $assoc_args['format'] ) && 'ids' === $assoc_args['format'] ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $pages, 'slug' ) ) );
			return;
		}
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $pages, $fields );
	}

	/**
	 * Show one page.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--html]
	 * : Print the page HTML instead of its details.
	 *
	 * [--format=<format>]
	 * : table, json, yaml. Default: table.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function get( $args, $assoc_args ) {
		$page = $this->check( $this->service()->get( $args[0], ! empty( $assoc_args['html'] ) ) );
		if ( ! empty( $assoc_args['html'] ) ) {
			WP_CLI::line( $page['html'] );
			return;
		}
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'table' === $format ) {
			$rows = array();
			foreach ( $page as $key => $value ) {
				$rows[] = array(
					'field' => $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
			return;
		}
		WP_CLI\Utils\format_items( $format, array( $page ), array_keys( $page ) );
	}

	/**
	 * Publish (or replace) a page from an HTML file or a ZIP bundle.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * <file>
	 * : Path to an .html/.htm file or a .zip bundle (index.html + assets).
	 *
	 * [--draft]
	 * : Save a new page as a draft (ignored when replacing).
	 *
	 * [--overwrite]
	 * : Replace the page if the slug already exists (previous version kept in history).
	 *
	 * [--asset=<paths>]
	 * : Add file(s) to the page's assets/ folder. Comma-separate several paths.
	 *
	 * [--porcelain]
	 * : Print only the page URL (or preview URL for drafts).
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp publish spring-promo ./index.html --asset=./hero.png,./logo.svg
	 *     wp htmlpp publish spring-promo ./site.zip --overwrite
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function publish( $args, $assoc_args ) {
		list( $slug, $file ) = $args;
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read {$file}." );
		}
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$opts = array(
			'status'    => empty( $assoc_args['draft'] ) ? 'published' : 'draft',
			'overwrite' => ! empty( $assoc_args['overwrite'] ),
			'files'     => array(),
			'mode'      => 'sideload',
		);
		if ( 'zip' === $ext ) {
			$opts['zip'] = realpath( $file );
		} elseif ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			$opts['html'] = HTMLPP_Storage::get_contents( $file );
		} else {
			WP_CLI::error( 'Only .html, .htm or .zip files are allowed.' );
		}

		$assets = array();
		if ( isset( $assoc_args['asset'] ) ) {
			foreach ( (array) $assoc_args['asset'] as $group ) {
				$assets = array_merge( $assets, array_filter( array_map( 'trim', explode( ',', (string) $group ) ) ) );
			}
		}
		$tmps = array();
		foreach ( $assets as $asset ) {
			if ( ! is_readable( $asset ) ) {
				array_map( 'wp_delete_file', $tmps );
				WP_CLI::error( "Cannot read {$asset}." );
			}
			// Sideloading moves the file; work on a copy so the source is kept.
			$tmp    = wp_tempnam( basename( $asset ) );
			$tmps[] = $tmp;
			copy( $asset, $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			$opts['files'][] = array(
				'name'     => basename( $asset ),
				'tmp_name' => $tmp,
				'type'     => '',
				'error'    => 0,
				'size'     => filesize( $asset ),
			);
		}

		$page = $this->service()->create( $slug, $opts );
		// Clean up any temp copies the sideload did not consume.
		foreach ( $tmps as $tmp ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
		$page = $this->check( $page );
		$url  = 'draft' === $page['status'] ? $page['preview_url'] : $page['url'];

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $url );
			return;
		}
		foreach ( $page['skipped'] as $skipped ) {
			WP_CLI::warning( 'Skipped: ' . $skipped );
		}
		foreach ( $page['file_errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success( ( $page['created'] ? 'Published ' : 'Replaced ' ) . $slug . ' → ' . $url );
	}

	/**
	 * Update a page's settings.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--status=<status>]
	 * : published or draft.
	 *
	 * [--url-path=<path>]
	 * : Custom path (e.g. promo), "/" for the front page, or "" to clear.
	 *   (Named --url-path because WP-CLI reserves the global --path flag.)
	 *
	 * [--noindex=<bool>]
	 * : true/false — hide from search engines and the sitemap.
	 *
	 * [--hide-snippets=<bool>]
	 * : true/false — skip the global head/footer snippets on this page.
	 *
	 * [--rename=<new-slug>]
	 * : Move the page to a new slug (old URL redirects).
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp update spring-promo --status=published --url-path=promo
	 *     wp htmlpp update spring-promo --rename=spring-sale
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function update( $args, $assoc_args ) {
		$slug = $args[0];
		$meta = array();
		if ( isset( $assoc_args['status'] ) ) {
			$meta['status'] = $assoc_args['status'];
		}
		if ( isset( $assoc_args['url-path'] ) ) {
			$meta['path'] = $assoc_args['url-path'];
		}
		if ( isset( $assoc_args['noindex'] ) ) {
			$meta['noindex'] = filter_var( $assoc_args['noindex'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $assoc_args['hide-snippets'] ) ) {
			$meta['no_snippets'] = filter_var( $assoc_args['hide-snippets'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( ! empty( $meta ) ) {
			$page = $this->check( $this->service()->set_meta( $slug, $meta ) );
			foreach ( $page['messages'] as $message ) {
				WP_CLI::log( $message );
			}
		}
		if ( ! empty( $assoc_args['rename'] ) ) {
			$page = $this->check( $this->service()->rename( $slug, $assoc_args['rename'] ) );
			$slug = $page['slug'];
		}
		$page = $this->check( $this->service()->get( $slug ) );
		WP_CLI::success( $slug . ' (' . $page['status'] . ') → ' . $page['url'] );
	}

	/**
	 * Delete a page and its version history.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function delete( $args, $assoc_args ) {
		WP_CLI::confirm( "Delete page '{$args[0]}' and its history?", $assoc_args );
		$this->check( $this->service()->delete( $args[0] ) );
		WP_CLI::success( "Deleted {$args[0]}." );
	}

	/**
	 * Copy a page to a new slug as a draft.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Source slug.
	 *
	 * <new-slug>
	 * : Slug of the copy.
	 *
	 * @param array $args Positional args.
	 */
	public function duplicate( $args ) {
		$page = $this->check( $this->service()->duplicate( $args[0], $args[1] ) );
		WP_CLI::success( "Copied {$args[0]} to {$page['slug']} (draft) → {$page['preview_url']}" );
	}

	/**
	 * Print a page's preview link, or reset it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--reset]
	 * : Invalidate the current link and print a new one.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function preview( $args, $assoc_args ) {
		$page = $this->check( $this->service()->get( $args[0] ) );
		if ( ! empty( $assoc_args['reset'] ) ) {
			$this->check( $this->service()->reset_preview( $page['slug'] ) );
		}
		WP_CLI::line( HTMLPP_Meta::preview_url( $page['slug'] ) );
	}

	/**
	 * List a page's files.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default: table.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function files( $args, $assoc_args ) {
		$files = $this->check( $this->service()->list_files( $args[0] ) );
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $files, array( 'reference', 'size', 'url' ) );
	}

	/**
	 * List a page's version history.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * @param array $args Positional args.
	 */
	public function versions( $args ) {
		$versions = $this->check( $this->service()->versions( $args[0] ) );
		foreach ( $versions as &$version ) {
			$version['modified'] = gmdate( 'Y-m-d H:i:s', $version['modified'] );
		}
		unset( $version );
		WP_CLI\Utils\format_items( 'table', $versions, array( 'name', 'modified', 'size' ) );
	}

	/**
	 * Restore a version from the page's history.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * <backup>
	 * : Backup file name from `wp htmlpp versions`.
	 *
	 * @param array $args Positional args.
	 */
	public function restore( $args ) {
		$this->check( $this->service()->restore( $args[0], $args[1] ) );
		WP_CLI::success( "Restored {$args[1]} as the live version of {$args[0]}." );
	}
}

WP_CLI::add_command( 'htmlpp', 'HTMLPP_CLI' );

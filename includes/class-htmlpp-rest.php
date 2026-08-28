<?php
/**
 * REST API: /wp-json/htmlpp/v1
 *
 * Authenticate with an application password (Users → Profile) or a logged-in
 * cookie + X-WP-Nonce. Every route requires the manage_options capability.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for pages.
 */
class HTMLPP_REST {

	/**
	 * Namespace.
	 */
	const NS = 'htmlpp/v1';

	/**
	 * Register routes on rest_api_init.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Service instance.
	 *
	 * @return HTMLPP_Page_Service
	 */
	private function service() {
		return htmlpp()->pages;
	}

	/**
	 * Capability check shared by every route.
	 *
	 * @return bool|WP_Error
	 */
	public function permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'htmlpp_forbidden', __( 'You need the manage_options capability to manage HTML pages.', 'html-page-publisher' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Slug argument definition.
	 *
	 * @return array
	 */
	private function slug_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => static function ( $value ) {
				return HTMLPP_Storage::sanitize_slug( $value );
			},
		);
	}

	/**
	 * Register all routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'slug'      => $this->slug_arg(),
						'html'      => array( 'type' => 'string' ),
						'status'    => array(
							'type'    => 'string',
							'enum'    => array( 'published', 'draft' ),
							'default' => 'published',
						),
						'overwrite' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'html' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'html'        => array( 'type' => 'string' ),
						'status'      => array(
							'type' => 'string',
							'enum' => array( 'published', 'draft' ),
						),
						'path'        => array( 'type' => 'string' ),
						'noindex'     => array( 'type' => 'boolean' ),
						'no_snippets' => array( 'type' => 'boolean' ),
						'new_slug'    => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_page' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'new_slug' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/preview-link',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'preview_link' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'reset_preview' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_files' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_files' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_file' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'reference' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// Path form for servers that accept encoded slashes (AllowEncodedSlashes On).
		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/files/(?P<reference>.+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_file' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'versions' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<slug>[a-z0-9_-]+)/versions/(?P<backup>[A-Za-z0-9._-]+\.html)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Turn a service result into a response.
	 *
	 * @param mixed $result Array or WP_Error.
	 * @param int   $status HTTP status on success.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result, $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * GET /pages
	 *
	 * @return WP_REST_Response
	 */
	public function list_pages() {
		return $this->respond( $this->service()->list_pages() );
	}

	/**
	 * GET /pages/{slug}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( WP_REST_Request $request ) {
		return $this->respond( $this->service()->get( $request['slug'], (bool) $request['html'] ) );
	}

	/**
	 * POST /pages — JSON { slug, html, status } or multipart with a "file"
	 * field (.html/.htm/.zip) and optional "files[]" assets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( WP_REST_Request $request ) {
		$args = array(
			'status'    => $request['status'],
			'overwrite' => (bool) $request['overwrite'],
			'files'     => array(),
			'mode'      => 'upload',
		);

		$uploads = $request->get_file_params();
		if ( ! empty( $uploads['file']['tmp_name'] ) && is_uploaded_file( $uploads['file']['tmp_name'] ) ) {
			$name = sanitize_file_name( (string) $uploads['file']['name'] );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'zip' === $ext ) {
				$args['zip'] = $uploads['file']['tmp_name'];
			} elseif ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
				$html = HTMLPP_Storage::get_contents( $uploads['file']['tmp_name'] );
				if ( false === $html ) {
					return new WP_Error( 'htmlpp_read', __( 'Could not read uploaded HTML.', 'html-page-publisher' ), array( 'status' => 400 ) );
				}
				$args['html'] = $html;
			} else {
				return new WP_Error( 'htmlpp_bad_type', __( 'Only .html, .htm or .zip files are allowed.', 'html-page-publisher' ), array( 'status' => 400 ) );
			}
		} elseif ( is_string( $request['html'] ) ) {
			$args['html'] = $request['html'];
		}

		if ( ! empty( $uploads['files'] ) && is_array( $uploads['files']['name'] ) ) {
			foreach ( $uploads['files']['name'] as $i => $file_name ) {
				$args['files'][] = array(
					'name'     => $file_name,
					'type'     => isset( $uploads['files']['type'][ $i ] ) ? $uploads['files']['type'][ $i ] : '',
					'tmp_name' => isset( $uploads['files']['tmp_name'][ $i ] ) ? $uploads['files']['tmp_name'][ $i ] : '',
					'error'    => isset( $uploads['files']['error'][ $i ] ) ? $uploads['files']['error'][ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $uploads['files']['size'][ $i ] ) ? $uploads['files']['size'][ $i ] : 0,
				);
			}
		}

		$result = $this->service()->create( $request['slug'], $args );
		return $this->respond( $result, is_wp_error( $result ) || empty( $result['created'] ) ? 200 : 201 );
	}

	/**
	 * PUT/PATCH /pages/{slug} — html and/or metadata and/or new_slug.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( WP_REST_Request $request ) {
		$service = $this->service();
		$slug    = $request['slug'];

		if ( is_string( $request['html'] ) ) {
			$result = $service->update_html( $slug, $request['html'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$meta = array();
		foreach ( array( 'status', 'path', 'noindex', 'no_snippets' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$meta[ $key ] = $request[ $key ];
			}
		}
		if ( ! empty( $meta ) ) {
			$result = $service->set_meta( $slug, $meta );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $request->has_param( 'new_slug' ) && '' !== (string) $request['new_slug'] ) {
			$result = $service->rename( $slug, $request['new_slug'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$slug = $result['slug'];
		}

		return $this->respond( $service->get( $slug ) );
	}

	/**
	 * DELETE /pages/{slug}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_page( WP_REST_Request $request ) {
		$result = $this->service()->delete( $request['slug'] );
		return $this->respond( is_wp_error( $result ) ? $result : array( 'deleted' => $request['slug'] ) );
	}

	/**
	 * POST /pages/{slug}/duplicate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_page( WP_REST_Request $request ) {
		return $this->respond( $this->service()->duplicate( $request['slug'], $request['new_slug'] ), 201 );
	}

	/**
	 * GET /pages/{slug}/preview-link
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_link( WP_REST_Request $request ) {
		$page = $this->service()->get( $request['slug'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return $this->respond( array( 'preview_url' => HTMLPP_Meta::preview_url( $page['slug'] ) ) );
	}

	/**
	 * DELETE /pages/{slug}/preview-link — invalidate and return a new one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_preview( WP_REST_Request $request ) {
		$page = $this->service()->reset_preview( $request['slug'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return $this->respond( array( 'preview_url' => HTMLPP_Meta::preview_url( $page['slug'] ) ) );
	}

	/**
	 * GET /pages/{slug}/files
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		return $this->respond( $this->service()->list_files( $request['slug'] ) );
	}

	/**
	 * POST /pages/{slug}/files — multipart "files[]" (or a single "file").
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_files( WP_REST_Request $request ) {
		$uploads = $request->get_file_params();
		$batch   = array();
		if ( ! empty( $uploads['files'] ) && is_array( $uploads['files']['name'] ) ) {
			foreach ( $uploads['files']['name'] as $i => $file_name ) {
				$batch[] = array(
					'name'     => $file_name,
					'type'     => isset( $uploads['files']['type'][ $i ] ) ? $uploads['files']['type'][ $i ] : '',
					'tmp_name' => isset( $uploads['files']['tmp_name'][ $i ] ) ? $uploads['files']['tmp_name'][ $i ] : '',
					'error'    => isset( $uploads['files']['error'][ $i ] ) ? $uploads['files']['error'][ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $uploads['files']['size'][ $i ] ) ? $uploads['files']['size'][ $i ] : 0,
				);
			}
		} elseif ( ! empty( $uploads['file'] ) ) {
			$batch[] = $uploads['file'];
		}
		if ( empty( $batch ) ) {
			return new WP_Error( 'htmlpp_no_files', __( 'Please choose at least one file to upload.', 'html-page-publisher' ), array( 'status' => 400 ) );
		}

		$added  = array();
		$errors = array();
		foreach ( $batch as $file ) {
			$stored = $this->service()->store_file( $request['slug'], $file, 'upload' );
			if ( is_wp_error( $stored ) ) {
				$errors[] = array(
					'name'  => isset( $file['name'] ) ? (string) $file['name'] : '',
					'error' => $stored->get_error_message(),
				);
			} else {
				$added[] = $stored;
			}
		}
		return $this->respond(
			array(
				'added'  => $added,
				'errors' => $errors,
			),
			empty( $added ) ? 400 : 201
		);
	}

	/**
	 * DELETE /pages/{slug}/files?reference=assets/hero.png (or JSON body),
	 * also DELETE /pages/{slug}/files/{reference}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_file( WP_REST_Request $request ) {
		return $this->respond( $this->service()->delete_file( $request['slug'], rawurldecode( (string) $request['reference'] ) ) );
	}

	/**
	 * GET /pages/{slug}/versions
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function versions( WP_REST_Request $request ) {
		return $this->respond( $this->service()->versions( $request['slug'] ) );
	}

	/**
	 * POST /pages/{slug}/versions/{backup}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		return $this->respond( $this->service()->restore( $request['slug'], (string) $request['backup'] ) );
	}
}

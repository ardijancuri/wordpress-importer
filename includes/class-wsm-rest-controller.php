<?php
/**
 * REST API controller.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_REST_Controller {
	/**
	 * Job store.
	 *
	 * @var WSM_Job_Store
	 */
	private $job_store;

	/**
	 * Logger.
	 *
	 * @var WSM_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WSM_Job_Store $job_store Job store.
	 * @param WSM_Logger    $logger Logger.
	 */
	public function __construct( WSM_Job_Store $job_store, WSM_Logger $logger ) {
		$this->job_store = $job_store;
		$this->logger    = $logger;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/preflight',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preflight' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/export/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_export' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/export/status/(?P<job_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'job_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/export/download/(?P<job_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_export' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/import/upload/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_import_upload' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/import/upload/chunk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_import_chunk' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/import/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/import/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/import/status/(?P<job_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'job_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/job/(?P<job_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_job' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permissions check for all routes.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Preflight route.
	 *
	 * @return WP_REST_Response
	 */
	public function preflight() {
		$preflight = new WSM_Preflight();
		return rest_ensure_response( $preflight->run() );
	}

	/**
	 * Start export.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_export() {
		$job = $this->job_store->create_job( 'export' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$archive = new WSM_Archive( $this->job_store, $this->logger );
		$result  = $archive->export( $job['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_job_response( $result ) );
	}

	/**
	 * Job status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function job_status( WP_REST_Request $request ) {
		$job = $this->job_store->get_job( $request['job_id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( $this->format_job_response( $job ) );
	}

	/**
	 * Download an export package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_export( WP_REST_Request $request ) {
		$job = $this->job_store->get_job( $request['job_id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['status'] ) || 'completed' !== $job['status'] ) {
			return new WP_Error( 'wsm_export_not_ready', __( 'Export package is not ready yet.', 'wp-site-migrator' ), array( 'status' => 409 ) );
		}

		$path = $this->job_store->get_package_path( $request['job_id'] );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'wsm_export_missing', __( 'Export package file was not found.', 'wp-site-migrator' ), array( 'status' => 404 ) );
		}

		$file_name = 'wp-site-migration-' . sanitize_file_name( home_url() ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Start chunked import upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_import_upload( WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$file_name = isset( $params['file_name'] ) ? sanitize_file_name( $params['file_name'] ) : 'package.zip';
		$file_size = isset( $params['file_size'] ) ? absint( $params['file_size'] ) : 0;

		$job = $this->job_store->create_job( 'import' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$this->job_store->update_job(
			$job['id'],
			array(
				'status'         => 'uploading',
				'phase'          => 'upload',
				'file_name'      => $file_name,
				'expected_size'  => $file_size,
				'uploaded_bytes' => 0,
				'chunk_count'    => 0,
			)
		);

		return rest_ensure_response(
			array(
				'job_id'     => $job['id'],
				'chunk_size' => 1024 * 1024,
			)
		);
	}

	/**
	 * Upload one base64 chunk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_import_chunk( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$job_id = isset( $params['job_id'] ) ? sanitize_text_field( $params['job_id'] ) : '';
		$index  = isset( $params['index'] ) ? absint( $params['index'] ) : 0;
		$total  = isset( $params['total'] ) ? absint( $params['total'] ) : 0;
		$chunk  = isset( $params['chunk'] ) ? (string) $params['chunk'] : '';

		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $chunk ) || $total < 1 ) {
			return new WP_Error( 'wsm_chunk_invalid', __( 'Upload chunk is invalid.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$data = base64_decode( $chunk, true );
		if ( false === $data ) {
			return new WP_Error( 'wsm_chunk_decode_failed', __( 'Upload chunk could not be decoded.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$package_path = $this->job_store->get_package_path( $job_id );
		$mode         = 0 === $index ? 'wb' : 'ab';
		$handle       = fopen( $package_path, $mode );
		if ( ! $handle ) {
			return new WP_Error( 'wsm_upload_write_failed', __( 'Could not write uploaded package chunk.', 'wp-site-migrator' ) );
		}

		fwrite( $handle, $data );
		fclose( $handle );

		$uploaded = filesize( $package_path );
		$complete = ( $index + 1 ) >= $total;

		$updated = $this->job_store->update_job(
			$job_id,
			array(
				'status'         => $complete ? 'uploaded' : 'uploading',
				'phase'          => $complete ? 'uploaded' : 'upload',
				'uploaded_bytes' => $uploaded,
				'chunk_count'    => $index + 1,
				'total_chunks'   => $total,
				'package_sha256' => $complete ? hash_file( 'sha256', $package_path ) : '',
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return rest_ensure_response( $this->format_job_response( $updated ) );
	}

	/**
	 * Validate uploaded import package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_import( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$job_id = isset( $params['job_id'] ) ? sanitize_text_field( $params['job_id'] ) : '';

		$archive  = new WSM_Archive( $this->job_store, $this->logger );
		$manifest = $archive->validate_import_package( $job_id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( $this->format_job_response( $job ) );
	}

	/**
	 * Start destructive import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_import( WP_REST_Request $request ) {
		$params       = $request->get_json_params();
		$job_id       = isset( $params['job_id'] ) ? sanitize_text_field( $params['job_id'] ) : '';
		$confirmation = isset( $params['confirmation'] ) ? strtoupper( trim( (string) $params['confirmation'] ) ) : '';
		$target_url   = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : home_url();

		if ( 'REPLACE SITE' !== $confirmation ) {
			return new WP_Error( 'wsm_confirmation_required', __( 'Type REPLACE SITE to confirm destructive import.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$archive = new WSM_Archive( $this->job_store, $this->logger );
		$result  = $archive->import( $job_id, $target_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_job_response( $result ) );
	}

	/**
	 * Delete a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_job( WP_REST_Request $request ) {
		$result = $this->job_store->delete_job( $request['job_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Format job for REST responses.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private function format_job_response( array $job ) {
		$job['log'] = $this->logger->read( $job['id'] );
		unset( $job['package_path'] );

		return $job;
	}
}

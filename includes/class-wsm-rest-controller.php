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
			'/export/download/(?P<job_id>[a-zA-Z0-9-]+)/(?P<part>[a-zA-Z0-9._-]+)',
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
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'job_status' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_job' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_job' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			WSM_Plugin::REST_NAMESPACE,
			'/job/(?P<job_id>[a-zA-Z0-9-]+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_job' ),
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

		$batch  = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$result = $batch->initialize_export( $job['id'] );

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
		$result = $this->stream_export_package( $request['job_id'], isset( $request['part'] ) ? $request['part'] : '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		exit;
	}

	/**
	 * Download an export package through admin-post.php.
	 *
	 * @return void
	 */
	public function download_export_admin_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this export.', 'wp-site-migrator' ), esc_html__( 'Export download failed', 'wp-site-migrator' ), array( 'response' => 403 ) );
		}

		check_admin_referer( 'wsm_download_export' );

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$part   = isset( $_GET['part'] ) ? sanitize_file_name( wp_unslash( $_GET['part'] ) ) : '';
		$result = $this->stream_export_package( $job_id, $part );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Export download failed', 'wp-site-migrator' ), array( 'response' => 400 ) );
		}

		exit;
	}

	/**
	 * Stream an export package to the current response.
	 *
	 * @param string $job_id Job id.
	 * @return true|WP_Error
	 */
	private function stream_export_package( $job_id, $part = '' ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['status'] ) || 'completed' !== $job['status'] ) {
			return new WP_Error( 'wsm_export_not_ready', __( 'Export package is not ready yet.', 'wp-site-migrator' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $job['package_format'] ) && WSM_Batch_Migrator::PACKAGE_FORMAT === $job['package_format'] ) {
			$batch = new WSM_Batch_Migrator( $this->job_store, $this->logger );
			$path  = $batch->get_export_artifact_path( $job_id, $part );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
		} else {
			$path = $this->job_store->get_package_path( $job_id );
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'wsm_export_missing', __( 'Export package file was not found.', 'wp-site-migrator' ), array( 'status' => 404 ) );
		}

		$file_name = $part ? sanitize_file_name( $part ) : $this->export_file_name();
		$file_size = filesize( $path );
		if ( false === $file_size ) {
			return new WP_Error( 'wsm_export_size_failed', __( 'Could not read export package size.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'wsm_export_open_failed', __( 'Could not open export package for download.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		if ( headers_sent() ) {
			fclose( $handle );
			return new WP_Error( 'wsm_headers_sent', __( 'Could not start the download because output was already sent.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		$this->prepare_download_response();

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: ' . ( preg_match( '/\.json$/i', $file_name ) ? 'application/json' : 'application/zip' ) );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1024 * 1024 );
			flush();
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Prepare PHP output handling for a file download.
	 *
	 * @return void
	 */
	private function prepare_download_response() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		@ini_set( 'zlib.output_compression', 'Off' );

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
	}

	/**
	 * Build a safe export file name.
	 *
	 * @return string
	 */
	private function export_file_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			$host = 'wordpress-site';
		}

		return sanitize_file_name( 'wp-site-migration-' . $host . '-' . gmdate( 'Ymd-His' ) . '.zip' );
	}

	/**
	 * Start chunked import upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_import_upload( WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$files    = isset( $params['files'] ) && is_array( $params['files'] ) ? $params['files'] : array();

		if ( empty( $files ) && ! empty( $params['file_name'] ) ) {
			$files[] = array(
				'name' => sanitize_file_name( $params['file_name'] ),
				'size' => isset( $params['file_size'] ) ? absint( $params['file_size'] ) : 0,
			);
		}

		$job = $this->job_store->create_job( 'import' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$batch  = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$result = $batch->initialize_import_upload( $job['id'], $files );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'job_id'     => $job['id'],
				'chunk_size' => WSM_Batch_Migrator::DEFAULT_UPLOAD_CHUNK_SIZE,
				'job'        => $this->format_job_response( $result ),
			)
		);
	}

	/**
	 * Upload one binary package chunk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_import_chunk( WP_REST_Request $request ) {
		$params     = $request->get_params();
		$file_params = $request->get_file_params();
		$job_id     = isset( $params['job_id'] ) ? sanitize_text_field( wp_unslash( $params['job_id'] ) ) : '';
		$file_index = isset( $params['file_index'] ) ? absint( $params['file_index'] ) : 0;
		$file_name  = isset( $params['file_name'] ) ? sanitize_file_name( wp_unslash( $params['file_name'] ) ) : '';
		$offset     = isset( $params['offset'] ) ? (int) $params['offset'] : 0;

		if ( empty( $file_params['chunk']['tmp_name'] ) ) {
			return new WP_Error( 'wsm_chunk_invalid', __( 'Upload chunk is invalid.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$batch  = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$result = $batch->receive_upload_chunk( $job_id, $file_index, $file_name, $offset, $file_params['chunk']['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_job_response( $result ) );
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

		$batch = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$job   = $batch->initialize_validation( $job_id );
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
		$target_url   = home_url();

		if ( 'REPLACE SITE' !== $confirmation ) {
			return new WP_Error( 'wsm_confirmation_required', __( 'Type REPLACE SITE to confirm destructive import.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$batch  = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$result = $batch->initialize_import( $job_id, $target_url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_job_response( $result ) );
	}

	/**
	 * Record an otherwise fatal runtime failure in the job log.
	 *
	 * @param string    $job_id Job id.
	 * @param Throwable $error Runtime error.
	 * @return void
	 */
	private function record_runtime_failure( $job_id, Throwable $error ) {
		$message = sprintf( '%s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine() );
		$this->logger->log( $job_id, $message, 'error' );
		$this->job_store->update_job(
			$job_id,
			array(
				'status' => 'failed',
				'errors' => array(
					array(
						'code'    => 'runtime_error',
						'message' => $message,
					),
				),
			)
		);
	}

	/**
	 * Run a resumable job batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ) {
		$batch = new WSM_Batch_Migrator( $this->job_store, $this->logger );
		$completed = false;
		$this->register_fatal_guard( $request['job_id'], $completed );
		$job = $batch->run_job( $request['job_id'] );
		$completed = true;
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( $this->format_job_response( $job ) );
	}

	/**
	 * Register a shutdown guard for fatal PHP errors.
	 *
	 * @param string $job_id Job id.
	 * @param bool   $completed Completion flag passed by reference.
	 * @return void
	 */
	private function register_fatal_guard( $job_id, &$completed ) {
		register_shutdown_function(
			function () use ( $job_id, &$completed ) {
				if ( $completed ) {
					return;
				}

				$error = error_get_last();
				if ( ! is_array( $error ) || empty( $error['type'] ) ) {
					return;
				}

				$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
				if ( ! in_array( $error['type'], $fatal_types, true ) ) {
					return;
				}

				$this->record_fatal_failure( $job_id, $error );
			}
		);
	}

	/**
	 * Record a fatal error captured during shutdown.
	 *
	 * @param string $job_id Job id.
	 * @param array  $error Error details.
	 * @return void
	 */
	private function record_fatal_failure( $job_id, array $error ) {
		$message = sprintf(
			'Fatal error: %s in %s:%d',
			isset( $error['message'] ) ? $error['message'] : __( 'Unknown fatal error', 'wp-site-migrator' ),
			isset( $error['file'] ) ? $error['file'] : __( 'unknown file', 'wp-site-migrator' ),
			isset( $error['line'] ) ? (int) $error['line'] : 0
		);

		$this->logger->log( $job_id, $message, 'error' );
		$this->job_store->update_job(
			$job_id,
			array(
				'status' => 'failed',
				'errors' => array(
					array(
						'code'    => 'fatal_error',
						'message' => $message,
					),
				),
			)
		);
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
		unset( $job['target_url'] );
		unset( $job['lock_token'] );
		unset( $job['cursor'] );
		unset( $job['database_tables'] );

		if ( ! empty( $job['upload_files'] ) && is_array( $job['upload_files'] ) ) {
			foreach ( $job['upload_files'] as $index => $file ) {
				unset( $job['upload_files'][ $index ]['path'] );
			}
		}

		return $job;
	}
}

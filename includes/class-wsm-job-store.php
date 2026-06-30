<?php
/**
 * Migration job storage.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Job_Store {
	const ROOT_DIR_NAME = 'wp-site-migrator';
	const JOBS_DIR_NAME = 'jobs';

	/**
	 * Upload directory data.
	 *
	 * @var array|null
	 */
	private $upload_dir = null;

	/**
	 * Ensure the protected base directory exists.
	 *
	 * @return string|WP_Error
	 */
	public function ensure_base_dir() {
		$base_dir = $this->get_base_dir();

		if ( ! wp_mkdir_p( $base_dir ) ) {
			return new WP_Error( 'wsm_storage_unwritable', __( 'Could not create the migration storage directory.', 'wp-site-migrator' ) );
		}

		$this->write_guards( $this->get_root_dir() );
		$this->write_guards( $base_dir );

		return $base_dir;
	}

	/**
	 * Create a new job.
	 *
	 * @param string $type Job type.
	 * @return array|WP_Error
	 */
	public function create_job( $type ) {
		$base_dir = $this->ensure_base_dir();
		if ( is_wp_error( $base_dir ) ) {
			return $base_dir;
		}

		$job_id  = gmdate( 'YmdHis' ) . '-' . $this->random_token( 10 );
		$job_dir = $this->get_job_dir( $job_id );

		if ( ! wp_mkdir_p( $job_dir ) ) {
			return new WP_Error( 'wsm_job_unwritable', __( 'Could not create the migration job directory.', 'wp-site-migrator' ) );
		}

		$this->write_guards( $job_dir );

		$job = array(
			'id'         => $job_id,
			'type'       => sanitize_key( $type ),
			'status'     => 'created',
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'errors'     => array(),
		);

		$saved = $this->save_job( $job_id, $job );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $job;
	}

	/**
	 * Get job metadata.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function get_job( $job_id ) {
		$job_id = $this->sanitize_job_id( $job_id );
		if ( ! $job_id ) {
			return new WP_Error( 'wsm_invalid_job_id', __( 'Invalid migration job id.', 'wp-site-migrator' ) );
		}

		$path = $this->get_job_dir( $job_id ) . '/meta.json';
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'wsm_job_not_found', __( 'Migration job was not found.', 'wp-site-migrator' ) );
		}

		$raw = file_get_contents( $path );
		$job = json_decode( $raw, true );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wsm_job_corrupt', __( 'Migration job metadata is corrupt.', 'wp-site-migrator' ) );
		}

		return $job;
	}

	/**
	 * Update job metadata.
	 *
	 * @param string $job_id Job id.
	 * @param array  $data Data to merge.
	 * @return array|WP_Error
	 */
	public function update_job( $job_id, array $data ) {
		$job = $this->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$job = array_merge( $job, $data );
		$job['updated_at'] = gmdate( 'c' );

		$saved = $this->save_job( $job_id, $job );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $job;
	}

	/**
	 * Delete a job directory.
	 *
	 * @param string $job_id Job id.
	 * @return bool|WP_Error
	 */
	public function delete_job( $job_id ) {
		$job_id = $this->sanitize_job_id( $job_id );
		if ( ! $job_id ) {
			return new WP_Error( 'wsm_invalid_job_id', __( 'Invalid migration job id.', 'wp-site-migrator' ) );
		}

		$dir = $this->get_job_dir( $job_id );
		if ( ! file_exists( $dir ) ) {
			return true;
		}

		if ( ! $this->is_inside_base( $dir ) ) {
			return new WP_Error( 'wsm_unsafe_delete', __( 'Refusing to delete a path outside migration storage.', 'wp-site-migrator' ) );
		}

		return $this->delete_tree( $dir );
	}

	/**
	 * Get job directory.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public function get_job_dir( $job_id ) {
		return $this->get_base_dir() . '/' . $this->sanitize_job_id( $job_id );
	}

	/**
	 * Get the final package path for a job.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public function get_package_path( $job_id ) {
		return $this->get_job_dir( $job_id ) . '/package.zip';
	}

	/**
	 * Get log path.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public function get_log_path( $job_id ) {
		return $this->get_job_dir( $job_id ) . '/job.log';
	}

	/**
	 * Get base jobs directory.
	 *
	 * @return string
	 */
	public function get_base_dir() {
		return $this->get_root_dir() . '/' . self::JOBS_DIR_NAME;
	}

	/**
	 * Get protected storage root.
	 *
	 * @return string
	 */
	public function get_root_dir() {
		$upload_dir = $this->get_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::ROOT_DIR_NAME;
	}

	/**
	 * Save job metadata.
	 *
	 * @param string $job_id Job id.
	 * @param array  $job Job metadata.
	 * @return true|WP_Error
	 */
	private function save_job( $job_id, array $job ) {
		$path = $this->get_job_dir( $job_id ) . '/meta.json';
		$json = wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
			return new WP_Error( 'wsm_job_save_failed', __( 'Could not save migration job metadata.', 'wp-site-migrator' ) );
		}

		return true;
	}

	/**
	 * Write public access guards.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function write_guards( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$web_config = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			file_put_contents(
				$web_config,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			);
		}
	}

	/**
	 * Get upload dir lazily.
	 *
	 * @return array
	 */
	private function get_upload_dir() {
		if ( null === $this->upload_dir ) {
			$this->upload_dir = wp_upload_dir();
		}

		return $this->upload_dir;
	}

	/**
	 * Sanitize a job id.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	private function sanitize_job_id( $job_id ) {
		$job_id = strtolower( (string) $job_id );
		return preg_match( '/^[a-z0-9-]+$/', $job_id ) ? $job_id : '';
	}

	/**
	 * Create a random lowercase token.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	private function random_token( $bytes ) {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( $bytes ) );
		}

		$token = '';
		for ( $i = 0; $i < $bytes * 2; $i++ ) {
			$token .= dechex( wp_rand( 0, 15 ) );
		}

		return $token;
	}

	/**
	 * Check a path is inside migration storage.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_inside_base( $path ) {
		$base = realpath( $this->get_base_dir() );
		$real = realpath( $path );

		return $base && $real && 0 === strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) );
	}

	/**
	 * Delete a file tree.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function delete_tree( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}

		if ( ! is_dir( $path ) ) {
			return true;
		}

		$items = scandir( $path );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( ! $this->delete_tree( $path . '/' . $item ) ) {
				return false;
			}
		}

		return @rmdir( $path );
	}
}

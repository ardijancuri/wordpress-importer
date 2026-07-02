<?php
/**
 * Resumable multipart migration engine.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Batch_Migrator {
	const PACKAGE_FORMAT = 'wsm-package-v2-multipart';
	const TARGET_PART_SIZE = 536870912;
	const DEFAULT_TICK_SECONDS = 8;
	const DEFAULT_UPLOAD_CHUNK_SIZE = 1048576;
	const LOCK_TTL = 45;

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
	 * Current tick deadline.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Open ZipArchive handles kept alive for active streams.
	 *
	 * @var array
	 */
	private $open_zips = array();

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
	 * Initialize a resumable export job.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function initialize_export( $job_id ) {
		$job_dir = $this->job_store->get_job_dir( $job_id );

		foreach ( array( 'database/data', 'package' ) as $dir ) {
			if ( ! wp_mkdir_p( $job_dir . '/' . $dir ) ) {
				return new WP_Error( 'wsm_export_prepare_failed', __( 'Could not prepare the export workspace.', 'wp-site-migrator' ), array( 'status' => 500 ) );
			}
		}

		$this->write_guards( $job_dir . '/package' );

		$job = $this->job_store->update_job(
			$job_id,
			array(
				'status'          => 'running',
				'phase'           => 'preflight',
				'package_format'  => self::PACKAGE_FORMAT,
				'part_size'       => self::TARGET_PART_SIZE,
				'progress'        => 1,
				'processed_bytes' => 0,
				'total_bytes'     => 0,
				'cursor'          => array(
					'export_phase' => 'preflight',
				),
				'warnings'        => array(),
			)
		);

		if ( ! is_wp_error( $job ) ) {
			$this->schedule_tick( $job_id );
		}

		return $job;
	}

	/**
	 * Initialize a multipart or legacy import upload job.
	 *
	 * @param string $job_id Job id.
	 * @param array  $files File metadata.
	 * @return array|WP_Error
	 */
	public function initialize_import_upload( $job_id, array $files ) {
		$job_dir    = $this->job_store->get_job_dir( $job_id );
		$upload_dir = $this->get_import_upload_dir( $job_id );

		if ( ! wp_mkdir_p( $upload_dir ) ) {
			return new WP_Error( 'wsm_import_upload_prepare_failed', __( 'Could not prepare the import upload workspace.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		$this->write_guards( $upload_dir );

		$upload_files = array();
		$expected     = 0;
		$legacy       = 1 === count( $files ) && ! empty( $files[0]['name'] ) && preg_match( '/\.zip$/i', $files[0]['name'] );

		foreach ( $files as $index => $file ) {
			$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
			$size = isset( $file['size'] ) ? max( 0, (int) $file['size'] ) : 0;

			if ( '' === $name || $size < 1 ) {
				return new WP_Error( 'wsm_import_file_invalid', __( 'Import file metadata is invalid.', 'wp-site-migrator' ), array( 'status' => 400 ) );
			}

			$role = 'part';
			if ( preg_match( '/\.manifest\.json$/i', $name ) ) {
				$role = 'manifest';
			} elseif ( $legacy ) {
				$role = 'legacy';
			}

			$path = 'legacy' === $role ? $this->job_store->get_package_path( $job_id ) : $upload_dir . '/' . $name;

			$upload_files[] = array(
				'index'    => (int) $index,
				'name'     => $name,
				'size'     => $size,
				'uploaded' => is_file( $path ) ? (int) filesize( $path ) : 0,
				'role'     => $role,
				'path'     => $path,
			);

			$expected += $size;
		}

		$manifest_count = 0;
		foreach ( $upload_files as $file ) {
			if ( 'manifest' === $file['role'] ) {
				$manifest_count++;
			}
		}

		if ( ! $legacy && 1 !== $manifest_count ) {
			return new WP_Error( 'wsm_import_manifest_required', __( 'Choose exactly one multipart manifest JSON file with its ZIP parts.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		if ( ! $legacy ) {
			$part_count = 0;
			foreach ( $upload_files as $file ) {
				if ( 'part' === $file['role'] ) {
					$part_count++;
				}
			}

			if ( $part_count < 1 ) {
				return new WP_Error( 'wsm_import_parts_required', __( 'Choose at least one multipart ZIP part.', 'wp-site-migrator' ), array( 'status' => 400 ) );
			}
		}

		$job = $this->job_store->update_job(
			$job_id,
			array(
				'status'         => 'uploading',
				'phase'          => 'upload',
				'package_format' => $legacy ? 'wsm-package-v1' : self::PACKAGE_FORMAT,
				'upload_files'   => $upload_files,
				'expected_size'  => $expected,
				'uploaded_bytes' => $this->sum_uploaded_bytes( $upload_files ),
				'progress'       => 1,
				'cursor'         => array(),
			)
		);

		return $job;
	}

	/**
	 * Store one binary upload chunk.
	 *
	 * @param string $job_id Job id.
	 * @param int    $file_index File index.
	 * @param string $file_name File name.
	 * @param int    $offset Expected byte offset.
	 * @param string $tmp_path Uploaded temporary chunk.
	 * @return array|WP_Error
	 */
	public function receive_upload_chunk( $job_id, $file_index, $file_name, $offset, $tmp_path ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['upload_files'] ) || ! is_array( $job['upload_files'] ) ) {
			return new WP_Error( 'wsm_upload_job_invalid', __( 'Import upload job is invalid.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$target_index = null;
		foreach ( $job['upload_files'] as $index => $file ) {
			if ( (int) $file['index'] === (int) $file_index ) {
				$target_index = $index;
				break;
			}
		}

		if ( null === $target_index ) {
			return new WP_Error( 'wsm_upload_file_unknown', __( 'Uploaded chunk references an unknown package file.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$file = $job['upload_files'][ $target_index ];
		if ( sanitize_file_name( $file_name ) !== $file['name'] ) {
			return new WP_Error( 'wsm_upload_file_mismatch', __( 'Uploaded chunk file name does not match the import job.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$path    = $file['path'];
		$current = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $offset !== $current ) {
			$job['upload_files'][ $target_index ]['uploaded'] = $current;
			$job['uploaded_bytes'] = $this->sum_uploaded_bytes( $job['upload_files'] );
			$updated = $this->job_store->update_job( $job_id, $job );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			return new WP_Error(
				'wsm_upload_offset_mismatch',
				__( 'Upload offset mismatch. Resume from the returned server offset.', 'wp-site-migrator' ),
				array(
					'status'          => 409,
					'expected_offset' => $current,
				)
			);
		}

		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'wsm_upload_dir_failed', __( 'Could not create the upload directory.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		$input  = fopen( $tmp_path, 'rb' );
		$output = fopen( $path, 'ab' );
		if ( ! $input || ! $output ) {
			if ( $input ) {
				fclose( $input );
			}
			if ( $output ) {
				fclose( $output );
			}
			return new WP_Error( 'wsm_upload_write_failed', __( 'Could not write uploaded package chunk.', 'wp-site-migrator' ), array( 'status' => 500 ) );
		}

		stream_copy_to_stream( $input, $output );
		fclose( $input );
		fclose( $output );

		$uploaded = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $uploaded > (int) $file['size'] ) {
			return new WP_Error( 'wsm_upload_too_large', __( 'Uploaded file is larger than expected.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$job['upload_files'][ $target_index ]['uploaded'] = $uploaded;
		$job['uploaded_bytes'] = $this->sum_uploaded_bytes( $job['upload_files'] );
		$job['progress']       = 1 + (int) floor( $this->ratio( $job['uploaded_bytes'], max( 1, (int) $job['expected_size'] ) ) * 59 );

		if ( $this->all_uploads_complete( $job['upload_files'] ) ) {
			$job['status'] = 'uploaded';
			$job['phase']  = 'uploaded';
			$job['progress'] = 60;
		}

		return $this->job_store->update_job( $job_id, $job );
	}

	/**
	 * Initialize package validation.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function initialize_validation( $job_id ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['status'] ) || ! in_array( $job['status'], array( 'uploaded', 'validated', 'failed' ), true ) ) {
			return new WP_Error( 'wsm_import_not_uploaded', __( 'Upload all package files before validation.', 'wp-site-migrator' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $job['package_format'] ) && 'wsm-package-v1' === $job['package_format'] ) {
			$archive  = new WSM_Archive( $this->job_store, $this->logger );
			$manifest = $archive->validate_import_package( $job_id );
			if ( is_wp_error( $manifest ) ) {
				return $this->fail_job( $job_id, $manifest );
			}

			$job = $this->job_store->get_job( $job_id );
			return is_wp_error( $job ) ? $job : $job;
		}

		if ( PHP_INT_SIZE < 8 ) {
			return $this->fail_job( $job_id, new WP_Error( 'wsm_php_32_bit_unsupported', __( 'Heavy multipart imports require a 64-bit PHP runtime.', 'wp-site-migrator' ) ) );
		}

		$job = $this->job_store->update_job(
			$job_id,
			array(
				'status'   => 'validating',
				'phase'    => 'validating',
				'progress' => max( 1, isset( $job['progress'] ) ? (int) $job['progress'] : 1 ),
				'cursor'   => array(
					'import_phase'        => 'validate_manifest',
					'validate_part_index' => 0,
				),
				'errors'   => array(),
			)
		);

		if ( ! is_wp_error( $job ) ) {
			$this->schedule_tick( $job_id );
		}

		return $job;
	}

	/**
	 * Initialize destructive import.
	 *
	 * @param string $job_id Job id.
	 * @param string $target_url Destination URL.
	 * @return array|WP_Error
	 */
	public function initialize_import( $job_id, $target_url ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['status'] ) || 'validated' !== $job['status'] ) {
			return new WP_Error( 'wsm_import_not_validated', __( 'Validate the package before import.', 'wp-site-migrator' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $job['package_format'] ) && 'wsm-package-v1' === $job['package_format'] ) {
			$job = $this->job_store->update_job(
				$job_id,
				array(
					'status'     => 'importing',
					'phase'      => 'legacy_import',
					'target_url' => $target_url,
					'cursor'     => array(
						'import_phase' => 'legacy_import',
					),
				)
			);

			if ( ! is_wp_error( $job ) ) {
				$this->schedule_tick( $job_id );
			}

			return $job;
		}

		$job = $this->job_store->update_job(
			$job_id,
			array(
				'status'     => 'importing',
				'phase'      => 'database',
				'target_url' => $target_url,
				'progress'   => 70,
				'cursor'     => array(
					'import_phase'    => 'database',
					'table_index'     => 0,
					'table_offset'    => 0,
					'table_started'   => false,
					'import_swaps'    => array(),
					'file_index'      => 0,
					'prepared_files'  => false,
				),
				'errors'     => array(),
			)
		);

		if ( ! is_wp_error( $job ) ) {
			$this->schedule_tick( $job_id );
		}

		return $job;
	}

	/**
	 * Run one short job tick.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function run_job( $job_id ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! $this->acquire_lock( $job ) ) {
			$job['locked'] = true;
			return $job;
		}

		$this->deadline = microtime( true ) + self::DEFAULT_TICK_SECONDS;

		try {
			if ( 'export' === $job['type'] ) {
				$job = $this->run_export_tick( $job );
			} elseif ( 'import' === $job['type'] ) {
				$job = $this->run_import_tick( $job );
			}
		} catch ( Throwable $e ) {
			$job = $this->fail_job(
				$job['id'],
				new WP_Error(
					'wsm_batch_runtime_error',
					sprintf( '%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ),
					array( 'status' => 500 )
				)
			);
		}

		$this->close_open_zips();

		if ( ! is_wp_error( $job ) ) {
			$job = $this->release_lock( $job['id'] );
			if ( ! is_wp_error( $job ) && $this->is_active_job( $job ) ) {
				$this->schedule_tick( $job['id'] );
			}
		}

		return $job;
	}

	/**
	 * Run one export tick.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_tick( array $job ) {
		if ( ! empty( $job['status'] ) && in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
			return $job;
		}

		$cursor = $this->get_cursor( $job );
		$phase  = isset( $cursor['export_phase'] ) ? $cursor['export_phase'] : 'preflight';

		switch ( $phase ) {
			case 'preflight':
				return $this->run_export_preflight( $job );
			case 'database':
				return $this->run_export_database( $job );
			case 'file_scan':
				return $this->run_export_file_scan( $job );
			case 'package_index':
				return $this->run_export_package_index( $job );
			case 'package_parts':
				return $this->run_export_package_parts( $job );
			case 'finalize':
				return $this->run_export_finalize( $job );
		}

		return $this->fail_job( $job['id'], new WP_Error( 'wsm_export_phase_invalid', __( 'Export job phase is invalid.', 'wp-site-migrator' ) ) );
	}

	/**
	 * Run export preflight.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_preflight( array $job ) {
		$preflight = new WSM_Preflight();
		$checks    = $preflight->run();
		if ( empty( $checks['ok'] ) || PHP_INT_SIZE < 8 ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_preflight_failed', __( 'Preflight checks failed. Heavy migrations require a 64-bit PHP runtime and writable storage.', 'wp-site-migrator' ) ) );
		}

		global $wpdb;

		$tables = $this->get_source_tables();
		if ( empty( $tables ) ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_no_tables', __( 'No WordPress tables were found for the current table prefix.', 'wp-site-migrator' ) ) );
		}

		$table_meta = array();
		$total_rows = 0;

		foreach ( $tables as $table ) {
			$create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->quote_identifier( $table ), ARRAY_N );
			if ( ! is_array( $create_row ) || empty( $create_row[1] ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_create_table_failed', sprintf( __( 'Could not read schema for table %s.', 'wp-site-migrator' ), $table ) ) );
			}

			$count      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $table ) );
			$total_rows += $count;
			$table_meta[] = array(
				'source_table'  => $table,
				'suffix'        => substr( $table, strlen( $wpdb->prefix ) ),
				'file'          => 'data/' . substr( md5( $table ), 0, 16 ) . '.jsonl',
				'rows'          => $count,
				'create_sql'    => $create_row[1],
				'primary_key'   => $this->get_single_primary_key( $table ),
				'exported_rows' => 0,
				'complete'      => false,
			);
		}

		$this->logger->log( $job['id'], sprintf( 'Prepared export for %d tables', count( $table_meta ) ) );

		return $this->job_store->update_job(
			$job['id'],
			array(
				'phase'          => 'database',
				'progress'       => 5,
				'database_rows'  => $total_rows,
				'database_tables' => $table_meta,
				'cursor'         => array(
					'export_phase'  => 'database',
					'table_index'   => 0,
					'table_offset'  => 0,
					'table_last_pk' => null,
				),
			)
		);
	}

	/**
	 * Run database export batches.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_database( array $job ) {
		global $wpdb;

		$tables = isset( $job['database_tables'] ) && is_array( $job['database_tables'] ) ? $job['database_tables'] : array();
		$cursor = $this->get_cursor( $job );
		$index  = isset( $cursor['table_index'] ) ? (int) $cursor['table_index'] : 0;

		while ( $index < count( $tables ) && $this->has_time() ) {
			$current_index = $index;
			$table = $tables[ $index ];
			$path  = $this->job_store->get_job_dir( $job['id'] ) . '/database/' . $table['file'];

			if ( empty( $table['started'] ) ) {
				wp_mkdir_p( dirname( $path ) );
				file_put_contents( $path, '' );
				$table['started'] = true;
				$this->logger->log( $job['id'], sprintf( 'Exporting table %s', $table['source_table'] ) );
			}

			$limit = 500;
			$rows  = $this->read_export_rows( $table, $cursor, $limit );
			if ( is_wp_error( $rows ) ) {
				return $this->fail_job( $job['id'], $rows );
			}

			$handle = fopen( $path, 'ab' );
			if ( ! $handle ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_table_file_failed', sprintf( __( 'Could not write export file for table %s.', 'wp-site-migrator' ), $table['source_table'] ) ) );
			}

			foreach ( $rows as $row ) {
				fwrite( $handle, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
				$table['exported_rows']++;
				if ( ! empty( $table['primary_key'] ) ) {
					$cursor['table_last_pk'] = isset( $row[ $table['primary_key'] ] ) ? $row[ $table['primary_key'] ] : $cursor['table_last_pk'];
				}
			}
			fclose( $handle );

			if ( empty( $table['primary_key'] ) ) {
				$cursor['table_offset'] = isset( $cursor['table_offset'] ) ? (int) $cursor['table_offset'] + count( $rows ) : count( $rows );
			}

			if ( count( $rows ) < $limit ) {
				$table['complete'] = true;
				$table['size']     = is_file( $path ) ? (int) filesize( $path ) : 0;
				$table['sha256']   = is_file( $path ) ? hash_file( 'sha256', $path ) : '';
				$job['total_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] + $table['size'] : $table['size'];
				$index++;
				$cursor['table_index']   = $index;
				$cursor['table_offset']  = 0;
				$cursor['table_last_pk'] = null;
			} else {
				$cursor['table_index'] = $index;
			}

			$tables[ $current_index ] = $table;
		}

		$exported_rows  = 0;
		$database_bytes = 0;
		foreach ( $tables as $table ) {
			$exported_rows += isset( $table['exported_rows'] ) ? (int) $table['exported_rows'] : 0;
			$database_bytes += isset( $table['size'] ) ? (int) $table['size'] : 0;
		}

		$progress = 5 + (int) floor( min( 1, $this->ratio( $exported_rows, isset( $job['database_rows'] ) ? (int) $job['database_rows'] : 0 ) ) * 25 );
		$data    = array(
			'database_tables' => $tables,
			'processed_rows'  => $exported_rows,
			'total_bytes'     => $database_bytes,
			'progress'        => $progress,
			'cursor'          => $cursor,
		);

		if ( $index >= count( $tables ) ) {
			$schema = array(
				'format'       => 'wsm-jsonl-v1',
				'table_prefix' => $wpdb->prefix,
				'tables'       => $tables,
			);
			$schema_path = $this->job_store->get_job_dir( $job['id'] ) . '/database/schema.json';
			file_put_contents( $schema_path, wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
			$schema_size = is_file( $schema_path ) ? (int) filesize( $schema_path ) : 0;

			$data['phase'] = 'file_scan';
			$data['progress'] = 32;
			$data['total_bytes'] = $database_bytes + $schema_size;
			$data['database_schema_sha256'] = hash_file( 'sha256', $schema_path );
			$data['cursor'] = array(
				'export_phase' => 'file_scan',
				'scan_started' => false,
			);
			$this->logger->log( $job['id'], 'Database export finished' );
		}

		return $this->job_store->update_job( $job['id'], $data );
	}

	/**
	 * Run file scan batches.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_file_scan( array $job ) {
		$cursor = $this->get_cursor( $job );
		$index_path = $this->get_file_index_path( $job['id'] );

		if ( empty( $cursor['scan_started'] ) ) {
			file_put_contents( $index_path, '' );
			$sources = $this->get_file_sources();
			$cursor = array(
				'export_phase' => 'file_scan',
				'scan_started' => true,
				'sources'      => $sources,
				'source_index' => 0,
				'stack'        => array(),
				'base'         => '',
				'section'      => '',
				'root_index'   => 0,
			);
			$this->logger->log( $job['id'], 'Scanning exportable files' );
		}

		$file_count = isset( $job['file_count'] ) ? (int) $job['file_count'] : 0;
		$file_bytes = isset( $job['file_bytes'] ) ? (int) $job['file_bytes'] : 0;

		while ( $this->has_time() ) {
			if ( ! empty( $cursor['root_files'] ) ) {
				if ( $cursor['root_index'] >= count( $cursor['root_files'] ) ) {
					unset( $cursor['root_files'] );
					$cursor['root_index'] = 0;
					continue;
				}

				$file = $cursor['root_files'][ $cursor['root_index'] ];
				$cursor['root_index']++;
				if ( is_file( $file['absolute_path'] ) && is_readable( $file['absolute_path'] ) ) {
					$entry = $this->build_file_index_entry( $file['section'], $file['absolute_path'], $file['relative_path'] );
					$this->append_json_line( $index_path, $entry );
					$file_count++;
					$file_bytes += $entry['size'];
				}
				continue;
			}

			if ( empty( $cursor['stack'] ) ) {
				if ( $cursor['source_index'] >= count( $cursor['sources'] ) ) {
					break;
				}

				$source = $cursor['sources'][ $cursor['source_index'] ];
				$cursor['source_index']++;

				if ( ! empty( $source['root_files'] ) ) {
					$cursor['root_files'] = $source['root_files'];
					$cursor['root_index'] = 0;
					continue;
				}

				if ( empty( $source['base'] ) || ! is_dir( $source['base'] ) || ! is_readable( $source['base'] ) ) {
					continue;
				}

				$cursor['section'] = $source['section'];
				$cursor['base']    = untrailingslashit( wp_normalize_path( $source['base'] ) );
				$cursor['stack']   = array( $cursor['base'] );
			}

			$dir = array_pop( $cursor['stack'] );
			if ( ! $dir ) {
				continue;
			}

			$items = @scandir( $dir );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = $dir . '/' . $item;
				if ( $this->should_skip_export_path( $cursor['section'], $path, $item ) ) {
					continue;
				}

				if ( is_dir( $path ) ) {
					$cursor['stack'][] = $path;
					continue;
				}

				if ( is_file( $path ) && is_readable( $path ) ) {
					$relative = ltrim( substr( wp_normalize_path( $path ), strlen( $cursor['base'] ) ), '/' );
					$entry    = $this->build_file_index_entry( $cursor['section'], $path, $relative );
					$this->append_json_line( $index_path, $entry );
					$file_count++;
					$file_bytes += $entry['size'];
				}

			}
		}

		$total_bytes = ( isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0 ) - ( isset( $job['file_bytes'] ) ? (int) $job['file_bytes'] : 0 ) + $file_bytes;
		$data        = array(
			'file_count'  => $file_count,
			'file_bytes'  => $file_bytes,
			'total_bytes' => $total_bytes,
			'progress'    => 35,
			'cursor'      => $cursor,
		);

		if ( empty( $cursor['stack'] ) && empty( $cursor['root_files'] ) && $cursor['source_index'] >= count( $cursor['sources'] ) ) {
			$required_space = (int) ceil( $total_bytes * 1.25 );
			$free_space     = $this->disk_free_space( $this->job_store->get_root_dir() );
			if ( null !== $free_space && $required_space > 0 && $free_space < $required_space ) {
				return $this->fail_job(
					$job['id'],
					new WP_Error(
						'wsm_export_disk_space_low',
						sprintf(
							__( 'Not enough disk space for export packaging. Required: %1$s. Available: %2$s.', 'wp-site-migrator' ),
							size_format( $required_space ),
							size_format( $free_space )
						)
					)
				);
			}

			$data['phase']    = 'package_index';
			$data['progress'] = 42;
			$data['cursor']   = array(
				'export_phase' => 'package_index',
				'index_stage'  => 'database_schema',
				'db_index'     => 0,
				'file_offset'  => 0,
			);
			$this->logger->log( $job['id'], sprintf( 'File scan finished with %d files', $file_count ) );
		}

		return $this->job_store->update_job( $job['id'], $data );
	}

	/**
	 * Build package entry index.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_package_index( array $job ) {
		$cursor      = $this->get_cursor( $job );
		$entries     = $this->get_entries_index_path( $job['id'] );
		$schema_path = $this->job_store->get_job_dir( $job['id'] ) . '/database/schema.json';

		if ( empty( $cursor['index_started'] ) ) {
			file_put_contents( $entries, '' );
			$cursor['index_started'] = true;
			$cursor['index_stage']   = 'database_schema';
			$cursor['db_index']      = 0;
			$cursor['file_offset']   = 0;
		}

		if ( 'database_schema' === $cursor['index_stage'] ) {
			$this->append_json_line(
				$entries,
				array(
					'kind'          => 'database_schema',
					'archive'       => 'database/schema.json',
					'absolute_path' => $schema_path,
					'size'          => is_file( $schema_path ) ? (int) filesize( $schema_path ) : 0,
					'sha256'        => is_file( $schema_path ) ? hash_file( 'sha256', $schema_path ) : '',
				)
			);
			$cursor['index_stage'] = 'database_tables';
		}

		$tables = isset( $job['database_tables'] ) && is_array( $job['database_tables'] ) ? $job['database_tables'] : array();
		while ( 'database_tables' === $cursor['index_stage'] && $cursor['db_index'] < count( $tables ) && $this->has_time() ) {
			$table = $tables[ $cursor['db_index'] ];
			$this->append_json_line(
				$entries,
				array(
					'kind'          => 'database_table',
					'archive'       => 'database/' . ltrim( $table['file'], '/' ),
					'absolute_path' => $this->job_store->get_job_dir( $job['id'] ) . '/database/' . $table['file'],
					'size'          => isset( $table['size'] ) ? (int) $table['size'] : 0,
					'sha256'        => isset( $table['sha256'] ) ? $table['sha256'] : '',
					'table_file'    => $table['file'],
				)
			);
			$cursor['db_index']++;
		}

		if ( 'database_tables' === $cursor['index_stage'] && $cursor['db_index'] >= count( $tables ) ) {
			$cursor['index_stage'] = 'files';
		}

		if ( 'files' === $cursor['index_stage'] ) {
			$file_index = $this->get_file_index_path( $job['id'] );
			$input      = fopen( $file_index, 'rb' );
			if ( ! $input ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_file_index_open_failed', __( 'Could not open the export file index.', 'wp-site-migrator' ) ) );
			}

			fseek( $input, (int) $cursor['file_offset'] );
			while ( $this->has_time() && false !== ( $line = fgets( $input ) ) ) {
				$cursor['file_offset'] += strlen( $line );
				$entry = json_decode( $line, true );
				if ( is_array( $entry ) ) {
					$entry['kind'] = 'file';
					$this->append_json_line( $entries, $entry );
				}
			}

			$done = feof( $input );
			fclose( $input );

			if ( $done ) {
				$cursor['index_stage'] = 'done';
			}
		}

		$data = array(
			'progress' => 48,
			'cursor'   => $cursor,
		);

		if ( 'done' === $cursor['index_stage'] ) {
			file_put_contents( $this->get_manifest_entries_path( $job['id'] ), '' );
			$data['phase']    = 'package_parts';
			$data['progress'] = 50;
			$data['cursor']   = array(
				'export_phase'      => 'package_parts',
				'entry_offset'      => 0,
				'part_index'        => 1,
				'packaged_bytes'    => 0,
				'package_part_list' => array(),
			);
			$this->logger->log( $job['id'], 'Package index built' );
		}

		return $this->job_store->update_job( $job['id'], $data );
	}

	/**
	 * Build one or more ZIP parts.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_package_parts( array $job ) {
		$cursor      = $this->get_cursor( $job );
		$entries     = $this->get_entries_index_path( $job['id'] );
		$manifest_ix = $this->get_manifest_entries_path( $job['id'] );
		$package_dir = $this->get_export_package_dir( $job['id'] );
		$site_slug   = $this->site_slug();
		$part_index  = isset( $cursor['part_index'] ) ? (int) $cursor['part_index'] : 1;
		$offset      = isset( $cursor['entry_offset'] ) ? (int) $cursor['entry_offset'] : 0;
		$part_name   = sprintf( '%s.part-%04d.zip', $site_slug, $part_index );
		$temp_path   = $package_dir . '/' . $part_name . '.tmp';
		$final_path  = $package_dir . '/' . $part_name;
		$part_manifest_temp = $package_dir . '/' . $part_name . '.entries.tmp';
		$part_bytes  = 0;
		$part_entries = 0;
		$part_start_offset = $offset;

		$this->delete_path( $temp_path );
		$this->delete_path( $final_path );
		$this->delete_path( $part_manifest_temp );
		$this->remove_manifest_entries_for_part( $manifest_ix, $part_name );

		$input = fopen( $entries, 'rb' );
		if ( ! $input ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_entries_open_failed', __( 'Could not open package entry index.', 'wp-site-migrator' ) ) );
		}

		fseek( $input, $offset );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			fclose( $input );
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_create_failed', __( 'Could not create a package part.', 'wp-site-migrator' ) ) );
		}

		while ( false !== ( $line = fgets( $input ) ) ) {
			$next_offset = ftell( $input );
			$entry       = json_decode( $line, true );

			if ( ! is_array( $entry ) || empty( $entry['absolute_path'] ) || empty( $entry['archive'] ) || ! is_file( $entry['absolute_path'] ) ) {
				$offset = $next_offset;
				continue;
			}

			if ( ! $zip->addFile( $entry['absolute_path'], $entry['archive'] ) ) {
				$zip->close();
				fclose( $input );
				$this->delete_path( $temp_path );
				$this->delete_path( $part_manifest_temp );
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_add_failed', sprintf( __( 'Could not add %s to a package part.', 'wp-site-migrator' ), $entry['archive'] ) ) );
			}

			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $entry['archive'], defined( 'ZipArchive::CM_STORE' ) ? ZipArchive::CM_STORE : 0 );
			}

			$entry['part'] = $part_name;
			$this->append_json_line( $part_manifest_temp, $entry );

			$part_entries++;
			$part_bytes += isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			$offset = $next_offset;

			if ( $part_bytes >= self::TARGET_PART_SIZE || ! $this->has_time() ) {
				break;
			}
		}

		$done = feof( $input );
		fclose( $input );
		$zip->close();

		if ( 0 === $part_entries ) {
			$this->delete_path( $temp_path );
			$this->delete_path( $part_manifest_temp );
			$data = array(
				'phase'    => 'finalize',
				'progress' => 92,
				'cursor'   => array(
					'export_phase' => 'finalize',
				),
			);
			return $this->job_store->update_job( $job['id'], $data );
		}

		if ( ! @rename( $temp_path, $final_path ) ) {
			$this->delete_path( $temp_path );
			$this->delete_path( $part_manifest_temp );
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_finalize_failed', __( 'Could not finalize a package part.', 'wp-site-migrator' ) ) );
		}

		if ( is_file( $part_manifest_temp ) ) {
			file_put_contents( $manifest_ix, file_get_contents( $part_manifest_temp ), FILE_APPEND | LOCK_EX );
			$this->delete_path( $part_manifest_temp );
		}

		$part = array(
			'name'         => $part_name,
			'size'         => (int) filesize( $final_path ),
			'sha256'       => hash_file( 'sha256', $final_path ),
			'entries'      => $part_entries,
			'entry_offset' => $part_start_offset,
		);

		$parts   = isset( $cursor['package_part_list'] ) && is_array( $cursor['package_part_list'] ) ? $cursor['package_part_list'] : array();
		$parts[] = $part;

		$packaged = isset( $cursor['packaged_bytes'] ) ? (int) $cursor['packaged_bytes'] + $part_bytes : $part_bytes;
		$progress = 50 + (int) floor( min( 1, $this->ratio( $packaged, isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0 ) ) * 40 );
		$cursor   = array(
			'export_phase'      => $done ? 'finalize' : 'package_parts',
			'entry_offset'      => $offset,
			'part_index'        => $part_index + 1,
			'packaged_bytes'    => $packaged,
			'package_part_list' => $parts,
		);

		$this->logger->log( $job['id'], sprintf( 'Created package part %s', $part_name ) );

		return $this->job_store->update_job(
			$job['id'],
			array(
				'phase'           => $done ? 'finalize' : 'package_parts',
				'progress'        => $done ? 92 : $progress,
				'processed_bytes' => $packaged,
				'package_parts'   => $parts,
				'cursor'          => $cursor,
			)
		);
	}

	/**
	 * Finalize export manifest.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_export_finalize( array $job ) {
		global $wpdb, $wp_version;

		$parts = isset( $job['package_parts'] ) && is_array( $job['package_parts'] ) ? $job['package_parts'] : array();
		if ( empty( $parts ) ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_no_package_parts', __( 'No package parts were created.', 'wp-site-migrator' ) ) );
		}

		$manifest_entries = $this->read_manifest_entries( $job['id'] );
		$tables           = isset( $job['database_tables'] ) && is_array( $job['database_tables'] ) ? $job['database_tables'] : array();
		$files            = array();
		$schema_part      = '';

		foreach ( $manifest_entries as $entry ) {
			if ( 'database_schema' === $entry['kind'] ) {
				$schema_part = $entry['part'];
				continue;
			}

			if ( 'database_table' === $entry['kind'] ) {
				foreach ( $tables as $index => $table ) {
					if ( ! empty( $entry['table_file'] ) && $entry['table_file'] === $table['file'] ) {
						$tables[ $index ]['archive'] = $entry['archive'];
						$tables[ $index ]['part']    = $entry['part'];
						break;
					}
				}
				continue;
			}

			if ( 'file' === $entry['kind'] ) {
				$files[] = array(
					'section' => $entry['section'],
					'path'    => $entry['path'],
					'archive' => $entry['archive'],
					'size'    => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
					'sha256'  => isset( $entry['sha256'] ) ? $entry['sha256'] : '',
					'part'    => $entry['part'],
				);
			}
		}

		$manifest = array(
			'package_format'    => self::PACKAGE_FORMAT,
			'package_type'      => 'single-site-content-database',
			'package_id'        => $job['id'],
			'created_at'        => gmdate( 'c' ),
			'plugin_version'    => WSM_VERSION,
			'wordpress_version' => $wp_version,
			'wordpress_db_version' => get_option( 'db_version' ),
			'php_version'       => PHP_VERSION,
			'source_url'        => home_url(),
			'home_url'          => home_url(),
			'site_url'          => site_url(),
			'table_prefix'      => $wpdb->prefix,
			'total_bytes'       => isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0,
			'part_size'         => self::TARGET_PART_SIZE,
			'parts'             => $parts,
			'database'          => array(
				'schema'        => 'database/schema.json',
				'schema_part'   => $schema_part,
				'schema_sha256' => isset( $job['database_schema_sha256'] ) ? $job['database_schema_sha256'] : '',
				'table_count'   => count( $tables ),
				'tables'        => $tables,
			),
			'files'             => $files,
		);

		$manifest_name = $this->site_slug() . '.manifest.json';
		$manifest_path = $this->get_export_package_dir( $job['id'] ) . '/' . $manifest_name;
		if ( false === file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_manifest_write_failed', __( 'Could not write package manifest.', 'wp-site-migrator' ) ) );
		}

		$downloads = array_merge(
			array(
				array(
					'name' => $manifest_name,
					'size' => (int) filesize( $manifest_path ),
				),
			),
			array_map(
				static function ( $part ) {
					return array(
						'name' => $part['name'],
						'size' => isset( $part['size'] ) ? (int) $part['size'] : 0,
					);
				},
				$parts
			)
		);

		$this->logger->log( $job['id'], sprintf( 'Multipart export completed with %d parts', count( $parts ) ) );

		return $this->job_store->update_job(
			$job['id'],
			array(
				'status'          => 'completed',
				'phase'           => 'done',
				'progress'        => 100,
				'completed_at'    => gmdate( 'c' ),
				'manifest_name'   => $manifest_name,
				'package_size'    => array_sum( wp_list_pluck( $downloads, 'size' ) ),
				'downloads'       => $downloads,
				'manifest_summary' => $this->manifest_summary( $manifest ),
				'cursor'          => array(
					'export_phase' => 'done',
				),
			)
		);
	}

	/**
	 * Run one import tick.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_import_tick( array $job ) {
		if ( ! empty( $job['status'] ) && in_array( $job['status'], array( 'completed', 'failed', 'uploaded', 'validated' ), true ) ) {
			return $job;
		}

		if ( ! empty( $job['package_format'] ) && 'wsm-package-v1' === $job['package_format'] ) {
			return $this->run_legacy_import_tick( $job );
		}

		$cursor = $this->get_cursor( $job );
		$phase  = isset( $cursor['import_phase'] ) ? $cursor['import_phase'] : '';

		switch ( $phase ) {
			case 'validate_manifest':
			case 'validate_parts':
				return $this->run_validate_multipart( $job );
			case 'database':
				return $this->run_import_database( $job );
			case 'swap_database':
				return $this->run_import_database_swap( $job );
			case 'files':
				return $this->run_import_files( $job );
			case 'swap_files':
				return $this->run_import_file_swap( $job );
		}

		return $job;
	}

	/**
	 * Run legacy single ZIP import in compatibility mode.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_legacy_import_tick( array $job ) {
		if ( empty( $job['cursor']['import_phase'] ) || 'legacy_import' !== $job['cursor']['import_phase'] ) {
			return $job;
		}

		$archive = new WSM_Archive( $this->job_store, $this->logger );
		$result  = $archive->import( $job['id'], isset( $job['target_url'] ) ? $job['target_url'] : home_url() );
		if ( is_wp_error( $result ) ) {
			return $this->fail_job( $job['id'], $result );
		}

		return $result;
	}

	/**
	 * Validate a multipart package.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_validate_multipart( array $job ) {
		$cursor = $this->get_cursor( $job );

		if ( 'validate_manifest' === $cursor['import_phase'] ) {
			$manifest_file = $this->find_upload_by_role( $job, 'manifest' );
			if ( ! $manifest_file || ! is_file( $manifest_file['path'] ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_manifest_missing', __( 'Multipart manifest is missing.', 'wp-site-migrator' ) ) );
			}

			$manifest = json_decode( file_get_contents( $manifest_file['path'] ), true );
			if ( ! is_array( $manifest ) || empty( $manifest['package_format'] ) || self::PACKAGE_FORMAT !== $manifest['package_format'] ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_manifest_invalid', __( 'Multipart manifest is invalid or unsupported.', 'wp-site-migrator' ) ) );
			}

			$expanded_size  = isset( $manifest['total_bytes'] ) ? (int) $manifest['total_bytes'] : 0;
			$uploaded_size  = isset( $job['expected_size'] ) ? (int) $job['expected_size'] : 0;
			$required_space = (int) ceil( $uploaded_size + ( $expanded_size * 1.25 ) );
			$free_space     = $this->disk_free_space( $this->job_store->get_root_dir() );
			if ( null !== $free_space && $required_space > 0 && $free_space < $required_space ) {
				return $this->fail_job(
					$job['id'],
					new WP_Error(
						'wsm_import_disk_space_low',
						sprintf(
							__( 'Not enough disk space for import staging. Required: %1$s. Available: %2$s.', 'wp-site-migrator' ),
							size_format( $required_space ),
							size_format( $free_space )
						)
					)
				);
			}

			$manifest_path = $this->get_import_manifest_path( $job['id'] );
			file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );

			$cursor['import_phase']        = 'validate_parts';
			$cursor['validate_part_index'] = 0;

			$job = $this->job_store->update_job(
				$job['id'],
				array(
					'manifest_name'    => $manifest_file['name'],
					'manifest_summary' => $this->manifest_summary( $manifest ),
					'phase'            => 'validating',
					'progress'         => 62,
					'cursor'           => $cursor,
				)
			);
			if ( is_wp_error( $job ) ) {
				return $job;
			}
		}

		$manifest = $this->load_import_manifest( $job['id'] );
		if ( is_wp_error( $manifest ) ) {
			return $this->fail_job( $job['id'], $manifest );
		}

		$parts = isset( $manifest['parts'] ) && is_array( $manifest['parts'] ) ? $manifest['parts'] : array();
		$index = isset( $cursor['validate_part_index'] ) ? (int) $cursor['validate_part_index'] : 0;

		while ( $index < count( $parts ) && $this->has_time() ) {
			$part = $parts[ $index ];
			if ( empty( $part['name'] ) || empty( $part['sha256'] ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_manifest_invalid', __( 'A package part is missing manifest data.', 'wp-site-migrator' ) ) );
			}

			$path = $this->get_uploaded_part_path( $job, $part['name'] );
			if ( ! $path || ! is_file( $path ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_missing', sprintf( __( 'Package part is missing: %s', 'wp-site-migrator' ), $part['name'] ) ) );
			}

			if ( hash_file( 'sha256', $path ) !== $part['sha256'] ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_part_checksum_failed', sprintf( __( 'Checksum failed for %s.', 'wp-site-migrator' ), $part['name'] ) ) );
			}

			$zip_result = $this->validate_part_zip( $path, $part['name'], $manifest );
			if ( is_wp_error( $zip_result ) ) {
				return $this->fail_job( $job['id'], $zip_result );
			}

			$index++;
			$cursor['validate_part_index'] = $index;
		}

		if ( $index >= count( $parts ) ) {
			$this->logger->log( $job['id'], 'Multipart package validated' );
			return $this->job_store->update_job(
				$job['id'],
				array(
					'status'   => 'validated',
					'phase'    => 'ready',
					'progress' => 70,
					'cursor'   => array(
						'import_phase' => 'ready',
					),
				)
			);
		}

		$progress = 62 + (int) floor( $this->ratio( $index, count( $parts ) ) * 8 );
		return $this->job_store->update_job(
			$job['id'],
			array(
				'progress' => $progress,
				'cursor'   => $cursor,
			)
		);
	}

	/**
	 * Import database tables in batches.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_import_database( array $job ) {
		global $wpdb, $wp_version;

		$manifest = $this->load_import_manifest( $job['id'] );
		if ( is_wp_error( $manifest ) ) {
			return $this->fail_job( $job['id'], $manifest );
		}

		if ( ! empty( $manifest['wordpress_version'] ) && version_compare( $wp_version, $manifest['wordpress_version'], '<' ) ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_wordpress_too_old', __( 'The destination WordPress version is older than the source site.', 'wp-site-migrator' ) ) );
		}

		if ( ! empty( $manifest['wordpress_db_version'] ) && (int) get_option( 'db_version' ) < (int) $manifest['wordpress_db_version'] ) {
			return $this->fail_job( $job['id'], new WP_Error( 'wsm_wordpress_database_too_old', __( 'The destination WordPress database version is older than the source site.', 'wp-site-migrator' ) ) );
		}

		$tables = isset( $manifest['database']['tables'] ) && is_array( $manifest['database']['tables'] ) ? $manifest['database']['tables'] : array();
		$cursor = $this->get_cursor( $job );
		$index  = isset( $cursor['table_index'] ) ? (int) $cursor['table_index'] : 0;
		$target_url = isset( $job['target_url'] ) ? $job['target_url'] : home_url();
		$rewriter = new WSM_URL_Rewriter( $this->source_urls_from_manifest( $manifest ), $target_url );
		$swaps    = isset( $cursor['import_swaps'] ) && is_array( $cursor['import_swaps'] ) ? $cursor['import_swaps'] : array();

		while ( $index < count( $tables ) && $this->has_time() ) {
			$table = $tables[ $index ];
			$suffix = isset( $table['suffix'] ) ? (string) $table['suffix'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $suffix ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_table_suffix_invalid', __( 'A table suffix in the package is invalid.', 'wp-site-migrator' ) ) );
			}

			$target_table  = $wpdb->prefix . $suffix;
			$staging_table = $this->temporary_table_name( 'wsmstg', $job['id'] . $target_table );
			$old_table     = $this->temporary_table_name( 'wsmold', $job['id'] . $target_table );

			if ( empty( $cursor['table_started'] ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $staging_table ) );
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $old_table ) );
				$create_sql = $this->rewrite_create_table_name( $table['create_sql'], $staging_table );
				if ( false === $wpdb->query( $create_sql ) ) {
					return $this->fail_job( $job['id'], new WP_Error( 'wsm_create_staging_failed', sprintf( __( 'Could not create staging table for %s: %s', 'wp-site-migrator' ), $target_table, $wpdb->last_error ) ) );
				}
				$cursor['table_started'] = true;
				$cursor['table_offset']  = 0;
				$this->logger->log( $job['id'], sprintf( 'Importing table %s', $target_table ) );
			}

			$stream = $this->open_manifest_entry_stream( $job, $table['part'], isset( $table['archive'] ) ? $table['archive'] : 'database/' . ltrim( $table['file'], '/' ) );
			if ( is_wp_error( $stream ) ) {
				return $this->fail_job( $job['id'], $stream );
			}

			$this->seek_stream( $stream, isset( $cursor['table_offset'] ) ? (int) $cursor['table_offset'] : 0 );
			$rows_this_tick = 0;
			$byte_offset    = isset( $cursor['table_offset'] ) ? (int) $cursor['table_offset'] : 0;

			while ( $rows_this_tick < 250 && $this->has_time() && false !== ( $line = fgets( $stream ) ) ) {
				$byte_offset += strlen( $line );
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$row = json_decode( $line, true );
				if ( ! is_array( $row ) ) {
					fclose( $stream );
					return $this->fail_job( $job['id'], new WP_Error( 'wsm_row_invalid', sprintf( __( 'Invalid row JSON in %s.', 'wp-site-migrator' ), $table['source_table'] ) ) );
				}

				foreach ( $row as $column => $value ) {
					$row[ $column ] = $rewriter->rewrite_value( $value );
				}

				if ( false === $wpdb->insert( $staging_table, $row ) ) {
					fclose( $stream );
					return $this->fail_job( $job['id'], new WP_Error( 'wsm_row_insert_failed', sprintf( __( 'Could not insert row into %1$s: %2$s', 'wp-site-migrator' ), $staging_table, $wpdb->last_error ) ) );
				}

				$rows_this_tick++;
			}

			$done = feof( $stream );
			fclose( $stream );

			if ( $done ) {
				$swaps[] = array(
					'target'  => $target_table,
					'staging' => $staging_table,
					'old'     => $old_table,
				);
				$index++;
				$cursor['table_index']   = $index;
				$cursor['table_offset']  = 0;
				$cursor['table_started'] = false;
				$cursor['import_swaps']  = $swaps;
			} else {
				$cursor['table_offset'] = $byte_offset;
			}
		}

		if ( $index >= count( $tables ) ) {
			$cursor['import_phase'] = 'swap_database';
			return $this->job_store->update_job(
				$job['id'],
				array(
					'phase'    => 'database',
					'progress' => 84,
					'cursor'   => $cursor,
				)
			);
		}

		$progress = 70 + (int) floor( $this->ratio( $index, count( $tables ) ) * 14 );
		return $this->job_store->update_job(
			$job['id'],
			array(
				'progress' => $progress,
				'cursor'   => $cursor,
			)
		);
	}

	/**
	 * Swap database staging tables into place.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_import_database_swap( array $job ) {
		$cursor = $this->get_cursor( $job );
		$swaps  = isset( $cursor['import_swaps'] ) && is_array( $cursor['import_swaps'] ) ? $cursor['import_swaps'] : array();
		$result = $this->swap_staging_tables( $swaps );
		if ( is_wp_error( $result ) ) {
			return $this->fail_job( $job['id'], $result );
		}

		$this->drop_old_tables( $swaps );
		update_option( 'home', untrailingslashit( esc_url_raw( isset( $job['target_url'] ) ? $job['target_url'] : home_url() ) ) );
		update_option( 'siteurl', untrailingslashit( esc_url_raw( isset( $job['target_url'] ) ? $job['target_url'] : home_url() ) ) );
		$this->ensure_plugin_active();

		$cursor['import_phase'] = 'files';
		$cursor['file_index'] = 0;
		$cursor['prepared_files'] = false;
		$this->logger->log( $job['id'], 'Database import finished' );

		return $this->job_store->update_job(
			$job['id'],
			array(
				'phase'    => 'files',
				'progress' => 86,
				'cursor'   => $cursor,
			)
		);
	}

	/**
	 * Extract file entries into shadow directories.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_import_files( array $job ) {
		$manifest = $this->load_import_manifest( $job['id'] );
		if ( is_wp_error( $manifest ) ) {
			return $this->fail_job( $job['id'], $manifest );
		}

		$cursor = $this->get_cursor( $job );
		$files  = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		$target_url = isset( $job['target_url'] ) ? $job['target_url'] : home_url();
		$rewriter = new WSM_URL_Rewriter( $this->source_urls_from_manifest( $manifest ), $target_url );

		if ( empty( $cursor['prepared_files'] ) ) {
			$this->prepare_file_shadow_dirs( $job['id'] );
			$cursor['prepared_files'] = true;
			$cursor['file_index'] = 0;
		}

		$index = isset( $cursor['file_index'] ) ? (int) $cursor['file_index'] : 0;
		while ( $index < count( $files ) && $this->has_time() ) {
			$file = $files[ $index ];
			$target = $this->shadow_file_path( $job['id'], $file );
			if ( is_wp_error( $target ) ) {
				return $this->fail_job( $job['id'], $target );
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_extract_dir_failed', __( 'Could not create a file extraction directory.', 'wp-site-migrator' ) ) );
			}

			$stream = $this->open_manifest_entry_stream( $job, $file['part'], $file['archive'] );
			if ( is_wp_error( $stream ) ) {
				return $this->fail_job( $job['id'], $stream );
			}

			$output = fopen( $target, 'wb' );
			if ( ! $output ) {
				fclose( $stream );
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_extract_file_failed', sprintf( __( 'Could not extract %s.', 'wp-site-migrator' ), $file['archive'] ) ) );
			}

			stream_copy_to_stream( $stream, $output );
			fclose( $stream );
			fclose( $output );

			if ( ! empty( $file['sha256'] ) && hash_file( 'sha256', $target ) !== $file['sha256'] ) {
				return $this->fail_job( $job['id'], new WP_Error( 'wsm_file_checksum_failed', sprintf( __( 'Checksum failed for %s.', 'wp-site-migrator' ), $file['archive'] ) ) );
			}

			if ( $this->is_rewritable_text_file( $target ) ) {
				$rewriter->rewrite_text_file( $target );
			}

			$index++;
			$cursor['file_index'] = $index;
		}

		if ( $index >= count( $files ) ) {
			$cursor['import_phase'] = 'swap_files';
			return $this->job_store->update_job(
				$job['id'],
				array(
					'phase'    => 'files',
					'progress' => 96,
					'cursor'   => $cursor,
				)
			);
		}

		$progress = 86 + (int) floor( $this->ratio( $index, count( $files ) ) * 10 );
		return $this->job_store->update_job(
			$job['id'],
			array(
				'progress' => $progress,
				'cursor'   => $cursor,
			)
		);
	}

	/**
	 * Swap imported file shadows into place.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error
	 */
	private function run_import_file_swap( array $job ) {
		$sections = $this->section_targets();
		foreach ( $sections as $section => $target ) {
			$source = $this->get_shadow_section_dir( $job['id'], $section );
			if ( ! $target || ! is_dir( $source ) ) {
				continue;
			}

			$result = $this->swap_directory( $job['id'], $section, $source, $target );
			if ( is_wp_error( $result ) ) {
				return $this->fail_job( $job['id'], $result );
			}
		}

		$root_dir = $this->get_shadow_section_dir( $job['id'], 'root' );
		if ( is_dir( $root_dir ) ) {
			foreach ( array( '.htaccess', 'web.config', 'robots.txt', 'favicon.ico' ) as $file_name ) {
				$source = $root_dir . '/' . $file_name;
				if ( is_file( $source ) && false === @copy( $source, ABSPATH . $file_name ) ) {
					return $this->fail_job( $job['id'], new WP_Error( 'wsm_root_copy_failed', sprintf( __( 'Could not copy root file %s.', 'wp-site-migrator' ), $file_name ) ) );
				}
			}
		}

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		$this->logger->log( $job['id'], 'Import completed' );

		return $this->job_store->update_job(
			$job['id'],
			array(
				'status'       => 'completed',
				'phase'        => 'done',
				'progress'     => 100,
				'completed_at' => gmdate( 'c' ),
				'cursor'       => array(
					'import_phase' => 'done',
				),
			)
		);
	}

	/**
	 * Get a downloadable export artifact.
	 *
	 * @param string $job_id Job id.
	 * @param string $name Artifact name.
	 * @return string|WP_Error
	 */
	public function get_export_artifact_path( $job_id, $name ) {
		$job = $this->job_store->get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['status'] ) || 'completed' !== $job['status'] ) {
			return new WP_Error( 'wsm_export_not_ready', __( 'Export package is not ready yet.', 'wp-site-migrator' ), array( 'status' => 409 ) );
		}

		if ( empty( $job['package_format'] ) || 'wsm-package-v1' === $job['package_format'] ) {
			return $this->job_store->get_package_path( $job_id );
		}

		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'wsm_export_part_required', __( 'Choose a package file to download.', 'wp-site-migrator' ), array( 'status' => 400 ) );
		}

		$allowed = array();
		if ( ! empty( $job['downloads'] ) && is_array( $job['downloads'] ) ) {
			foreach ( $job['downloads'] as $download ) {
				if ( ! empty( $download['name'] ) ) {
					$allowed[ $download['name'] ] = true;
				}
			}
		}

		if ( empty( $allowed[ $name ] ) ) {
			return new WP_Error( 'wsm_export_part_unknown', __( 'Requested package file is not part of this export.', 'wp-site-migrator' ), array( 'status' => 404 ) );
		}

		$path = $this->get_export_package_dir( $job_id ) . '/' . $name;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'wsm_export_missing', __( 'Export package file was not found.', 'wp-site-migrator' ), array( 'status' => 404 ) );
		}

		return $path;
	}

	/**
	 * Helper methods below.
	 */
	private function get_cursor( array $job ) {
		return isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
	}

	private function has_time() {
		return microtime( true ) < $this->deadline;
	}

	private function acquire_lock( array $job ) {
		if ( ! empty( $job['lock_expires'] ) && (int) $job['lock_expires'] > time() ) {
			return false;
		}

		$this->job_store->update_job(
			$job['id'],
			array(
				'lock_token'   => $this->random_token( 8 ),
				'lock_expires' => time() + self::LOCK_TTL,
			)
		);

		return true;
	}

	private function release_lock( $job_id ) {
		return $this->job_store->update_job(
			$job_id,
			array(
				'lock_token'   => '',
				'lock_expires' => 0,
			)
		);
	}

	private function fail_job( $job_id, WP_Error $error ) {
		$this->logger->log( $job_id, $error->get_error_message(), 'error' );
		return $this->job_store->update_job(
			$job_id,
			array(
				'status' => 'failed',
				'phase'  => 'failed',
				'errors' => array(
					array(
						'code'    => $error->get_error_code(),
						'message' => $error->get_error_message(),
					),
				),
			)
		);
	}

	private function get_source_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return is_array( $tables ) ? $tables : array();
	}

	private function get_single_primary_key( $table ) {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW KEYS FROM ' . $this->quote_identifier( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || empty( $rows[0]['Column_name'] ) ) {
			return '';
		}

		return $rows[0]['Column_name'];
	}

	private function read_export_rows( array $table, array $cursor, $limit ) {
		global $wpdb;

		if ( ! empty( $table['primary_key'] ) ) {
			$pk = $this->quote_identifier( $table['primary_key'] );
			if ( null === $cursor['table_last_pk'] || '' === $cursor['table_last_pk'] ) {
				$sql = sprintf( 'SELECT * FROM %s ORDER BY %s ASC LIMIT %d', $this->quote_identifier( $table['source_table'] ), $pk, $limit );
			} else {
				$sql = $wpdb->prepare(
					sprintf( 'SELECT * FROM %s WHERE %s > %%s ORDER BY %s ASC LIMIT %d', $this->quote_identifier( $table['source_table'] ), $pk, $pk, $limit ),
					$cursor['table_last_pk']
				);
			}
		} else {
			$offset = isset( $cursor['table_offset'] ) ? (int) $cursor['table_offset'] : 0;
			$sql    = sprintf( 'SELECT * FROM %s LIMIT %d OFFSET %d', $this->quote_identifier( $table['source_table'] ), $limit, $offset );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'wsm_table_read_failed', sprintf( __( 'Could not read rows from table %s.', 'wp-site-migrator' ), $table['source_table'] ) );
		}

		return $rows;
	}

	private function get_file_sources() {
		$upload_dir = wp_upload_dir();
		$sources    = array();

		if ( empty( $upload_dir['error'] ) && is_dir( $upload_dir['basedir'] ) ) {
			$sources[] = array( 'section' => 'uploads', 'base' => $upload_dir['basedir'] );
		}

		if ( is_dir( get_theme_root() ) ) {
			$sources[] = array( 'section' => 'themes', 'base' => get_theme_root() );
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
			$sources[] = array( 'section' => 'plugins', 'base' => WP_PLUGIN_DIR );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$sources[] = array( 'section' => 'mu-plugins', 'base' => WPMU_PLUGIN_DIR );
		}

		if ( defined( 'WP_LANG_DIR' ) && is_dir( WP_LANG_DIR ) ) {
			$sources[] = array( 'section' => 'languages', 'base' => WP_LANG_DIR );
		}

		$root_files = array();
		foreach ( array( '.htaccess', 'web.config', 'robots.txt', 'favicon.ico' ) as $file_name ) {
			$path = ABSPATH . $file_name;
			if ( is_file( $path ) && is_readable( $path ) ) {
				$root_files[] = array(
					'section'       => 'root',
					'absolute_path' => $path,
					'relative_path' => $file_name,
				);
			}
		}

		if ( $root_files ) {
			$sources[] = array( 'root_files' => $root_files );
		}

		return $sources;
	}

	private function should_skip_export_path( $section, $path, $basename ) {
		$basename = strtolower( $basename );
		if ( in_array( $basename, array( '.git', '.svn', '.hg' ), true ) ) {
			return true;
		}

		if ( 'uploads' === $section && in_array( $basename, array( 'cache', 'caches', 'log', 'logs', 'upgrade', 'updraft', 'backups', 'backup' ), true ) ) {
			return true;
		}

		if ( 0 === strpos( basename( $path ), 'wsm-' ) ) {
			return true;
		}

		$storage_root = realpath( $this->job_store->get_root_dir() );
		$real_path    = realpath( $path );
		return $storage_root && $real_path && 0 === strpos( wp_normalize_path( $real_path ), wp_normalize_path( $storage_root ) );
	}

	private function build_file_index_entry( $section, $absolute_path, $relative_path ) {
		return array(
			'section'       => sanitize_key( $section ),
			'path'          => $relative_path,
			'archive'       => 'files/' . sanitize_key( $section ) . '/' . $this->normalize_zip_name( $relative_path ),
			'absolute_path' => $absolute_path,
			'size'          => (int) filesize( $absolute_path ),
			'sha256'        => hash_file( 'sha256', $absolute_path ),
		);
	}

	private function append_json_line( $path, array $data ) {
		file_put_contents( $path, wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
	}

	private function read_manifest_entries( $job_id ) {
		$path = $this->get_manifest_entries_path( $job_id );
		if ( ! is_file( $path ) ) {
			return array();
		}

		$entries = array();
		$handle  = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return $entries;
		}

		while ( false !== ( $line = fgets( $handle ) ) ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}
		fclose( $handle );

		return $entries;
	}

	private function remove_manifest_entries_for_part( $path, $part_name ) {
		if ( ! is_file( $path ) ) {
			return;
		}

		$temp = $path . '.tmp';
		$in   = fopen( $path, 'rb' );
		$out  = fopen( $temp, 'wb' );
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			$this->delete_path( $temp );
			return;
		}

		while ( false !== ( $line = fgets( $in ) ) ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) && ! empty( $entry['part'] ) && $entry['part'] === $part_name ) {
				continue;
			}
			fwrite( $out, $line );
		}

		fclose( $in );
		fclose( $out );
		@rename( $temp, $path );
	}

	private function get_export_package_dir( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/package';
	}

	private function get_import_upload_dir( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/uploads';
	}

	private function get_file_index_path( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/file-index.jsonl';
	}

	private function get_entries_index_path( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/entries.jsonl';
	}

	private function get_manifest_entries_path( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/manifest-entries.jsonl';
	}

	private function get_import_manifest_path( $job_id ) {
		return $this->job_store->get_job_dir( $job_id ) . '/import-manifest.json';
	}

	private function load_import_manifest( $job_id ) {
		$path = $this->get_import_manifest_path( $job_id );
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'wsm_manifest_missing', __( 'Import manifest is missing.', 'wp-site-migrator' ) );
		}

		$manifest = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['package_format'] ) || self::PACKAGE_FORMAT !== $manifest['package_format'] ) {
			return new WP_Error( 'wsm_manifest_invalid', __( 'Import manifest is invalid.', 'wp-site-migrator' ) );
		}

		return $manifest;
	}

	private function find_upload_by_role( array $job, $role ) {
		if ( empty( $job['upload_files'] ) || ! is_array( $job['upload_files'] ) ) {
			return null;
		}

		foreach ( $job['upload_files'] as $file ) {
			if ( isset( $file['role'] ) && $role === $file['role'] ) {
				return $file;
			}
		}

		return null;
	}

	private function get_uploaded_part_path( array $job, $name ) {
		$name = sanitize_file_name( $name );
		if ( empty( $job['upload_files'] ) || ! is_array( $job['upload_files'] ) ) {
			return '';
		}

		foreach ( $job['upload_files'] as $file ) {
			if ( isset( $file['name'] ) && $name === $file['name'] ) {
				return $file['path'];
			}
		}

		return '';
	}

	private function validate_part_zip( $path, $part_name, array $manifest ) {
		$expected = $this->expected_entries_for_part( $manifest, $part_name );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'wsm_zip_open_failed', sprintf( __( 'Could not open package part %s.', 'wp-site-migrator' ), $part_name ) );
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = isset( $stat['name'] ) ? $this->normalize_zip_name( $stat['name'] ) : '';
			if ( '' === $name || substr( $name, -1 ) === '/' ) {
				continue;
			}

			if ( ! $this->is_safe_zip_name( $name ) || ( 0 !== strpos( $name, 'database/' ) && 0 !== strpos( $name, 'files/' ) ) ) {
				$zip->close();
				return new WP_Error( 'wsm_zip_name_unsafe', sprintf( __( 'Package part contains an unsafe path: %s', 'wp-site-migrator' ), $name ) );
			}

			if ( empty( $expected[ $name ] ) ) {
				$zip->close();
				return new WP_Error( 'wsm_zip_unexpected_entry', sprintf( __( 'Package part contains an unexpected entry: %s', 'wp-site-migrator' ), $name ) );
			}

			unset( $expected[ $name ] );
		}

		$zip->close();

		if ( ! empty( $expected ) ) {
			return new WP_Error( 'wsm_zip_missing_entry', sprintf( __( 'Package part is missing an expected entry: %s', 'wp-site-migrator' ), key( $expected ) ) );
		}

		return true;
	}

	private function expected_entries_for_part( array $manifest, $part_name ) {
		$expected = array();

		if ( ! empty( $manifest['database']['schema_part'] ) && $part_name === $manifest['database']['schema_part'] ) {
			$expected['database/schema.json'] = true;
		}

		if ( ! empty( $manifest['database']['tables'] ) && is_array( $manifest['database']['tables'] ) ) {
			foreach ( $manifest['database']['tables'] as $table ) {
				if ( ! empty( $table['part'] ) && $part_name === $table['part'] ) {
					$archive = ! empty( $table['archive'] ) ? $table['archive'] : 'database/' . ltrim( $table['file'], '/' );
					$expected[ $archive ] = true;
				}
			}
		}

		if ( ! empty( $manifest['files'] ) && is_array( $manifest['files'] ) ) {
			foreach ( $manifest['files'] as $file ) {
				if ( ! empty( $file['part'] ) && $part_name === $file['part'] && ! empty( $file['archive'] ) ) {
					$expected[ $file['archive'] ] = true;
				}
			}
		}

		return $expected;
	}

	private function open_manifest_entry_stream( array $job, $part_name, $entry_name ) {
		$path = $this->get_uploaded_part_path( $job, $part_name );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'wsm_part_missing', sprintf( __( 'Package part is missing: %s', 'wp-site-migrator' ), $part_name ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'wsm_zip_open_failed', sprintf( __( 'Could not open package part %s.', 'wp-site-migrator' ), $part_name ) );
		}

		$stream = $zip->getStream( $entry_name );
		if ( ! $stream ) {
			$zip->close();
			return new WP_Error( 'wsm_zip_entry_missing', sprintf( __( 'Package entry is missing: %s', 'wp-site-migrator' ), $entry_name ) );
		}

		$this->open_zips[] = $zip;

		return $stream;
	}

	private function seek_stream( $stream, $offset ) {
		if ( $offset <= 0 ) {
			return;
		}

		if ( 0 === @fseek( $stream, $offset ) ) {
			return;
		}

		$remaining = $offset;
		while ( $remaining > 0 && ! feof( $stream ) ) {
			$read = min( 1048576, $remaining );
			$data = fread( $stream, $read );
			if ( false === $data || '' === $data ) {
				break;
			}
			$remaining -= strlen( $data );
		}
	}

	private function close_open_zips() {
		foreach ( $this->open_zips as $zip ) {
			if ( $zip instanceof ZipArchive ) {
				$zip->close();
			}
		}

		$this->open_zips = array();
	}

	private function prepare_file_shadow_dirs( $job_id ) {
		$this->delete_path( $this->job_store->get_job_dir( $job_id ) . '/staged-files' );
		wp_mkdir_p( $this->job_store->get_job_dir( $job_id ) . '/staged-files' );
	}

	private function shadow_file_path( $job_id, array $file ) {
		if ( empty( $file['section'] ) || ! isset( $file['path'] ) ) {
			return new WP_Error( 'wsm_file_manifest_invalid', __( 'A file entry in the package manifest is invalid.', 'wp-site-migrator' ) );
		}

		$section = sanitize_key( $file['section'] );
		$relative = $this->normalize_zip_name( $file['path'] );
		if ( ! $this->is_safe_zip_name( $relative ) ) {
			return new WP_Error( 'wsm_unsafe_path', __( 'Package contains an unsafe path.', 'wp-site-migrator' ) );
		}

		return $this->get_shadow_section_dir( $job_id, $section ) . '/' . $relative;
	}

	private function get_shadow_section_dir( $job_id, $section ) {
		return $this->job_store->get_job_dir( $job_id ) . '/staged-files/' . sanitize_key( $section );
	}

	private function section_targets() {
		$upload_dir = wp_upload_dir();
		return array(
			'uploads'    => empty( $upload_dir['error'] ) ? $upload_dir['basedir'] : '',
			'themes'     => get_theme_root(),
			'plugins'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'languages'  => defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : '',
			'root'       => '',
		);
	}

	private function source_urls_from_manifest( array $manifest ) {
		return array_filter(
			array(
				isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
				isset( $manifest['site_url'] ) ? $manifest['site_url'] : '',
				isset( $manifest['source_url'] ) ? $manifest['source_url'] : '',
			)
		);
	}

	private function manifest_summary( array $manifest ) {
		return array(
			'created_at'        => isset( $manifest['created_at'] ) ? $manifest['created_at'] : '',
			'source_url'        => isset( $manifest['source_url'] ) ? $manifest['source_url'] : '',
			'home_url'          => isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
			'site_url'          => isset( $manifest['site_url'] ) ? $manifest['site_url'] : '',
			'wordpress_version' => isset( $manifest['wordpress_version'] ) ? $manifest['wordpress_version'] : '',
			'wordpress_db_version' => isset( $manifest['wordpress_db_version'] ) ? $manifest['wordpress_db_version'] : '',
			'table_prefix'      => isset( $manifest['table_prefix'] ) ? $manifest['table_prefix'] : '',
			'table_count'       => isset( $manifest['database']['table_count'] ) ? (int) $manifest['database']['table_count'] : 0,
			'file_count'        => isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? count( $manifest['files'] ) : 0,
			'part_count'        => isset( $manifest['parts'] ) && is_array( $manifest['parts'] ) ? count( $manifest['parts'] ) : 0,
		);
	}

	private function swap_directory( $job_id, $section, $source, $target ) {
		$source = untrailingslashit( $source );
		$target = untrailingslashit( $target );
		$parent = dirname( $target );

		if ( ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'wsm_parent_dir_failed', sprintf( __( 'Could not create parent directory for %s.', 'wp-site-migrator' ), $section ) );
		}

		$token = substr( md5( $job_id . $section ), 0, 8 );
		$old   = $parent . '/.wsm-old-' . sanitize_key( $section ) . '-' . $token;

		$this->delete_path( $old );

		if ( file_exists( $target ) && ! @rename( $target, $old ) ) {
			return new WP_Error( 'wsm_file_swap_failed', sprintf( __( 'Could not move existing %s directory aside.', 'wp-site-migrator' ), $section ) );
		}

		if ( ! @rename( $source, $target ) ) {
			if ( file_exists( $old ) && ! file_exists( $target ) ) {
				@rename( $old, $target );
			}
			return new WP_Error( 'wsm_file_swap_failed', sprintf( __( 'Could not move imported %s directory into place.', 'wp-site-migrator' ), $section ) );
		}

		if ( 'plugins' === $section ) {
			$this->restore_migrator_plugin_if_missing( $old, $target );
		}

		$this->delete_path( $old );
		$this->logger->log( $job_id, sprintf( 'Replaced %s files', $section ) );

		return true;
	}

	private function restore_migrator_plugin_if_missing( $old_plugins_dir, $new_plugins_dir ) {
		$plugin_dir_name = dirname( WSM_PLUGIN_BASENAME );
		if ( '.' === $plugin_dir_name ) {
			return;
		}

		$current = trailingslashit( $new_plugins_dir ) . $plugin_dir_name;
		$old     = trailingslashit( $old_plugins_dir ) . $plugin_dir_name;

		if ( ! is_dir( $current ) && is_dir( $old ) ) {
			$this->copy_tree( $old, $current );
		}
	}

	private function copy_tree( $source, $target ) {
		if ( is_file( $source ) ) {
			wp_mkdir_p( dirname( $target ) );
			return @copy( $source, $target );
		}

		if ( ! is_dir( $source ) ) {
			return false;
		}

		wp_mkdir_p( $target );
		$items = scandir( $source );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->copy_tree( $source . '/' . $item, $target . '/' . $item );
		}

		return true;
	}

	private function swap_staging_tables( array $table_swaps ) {
		global $wpdb;

		$renames = array();
		foreach ( $table_swaps as $swap ) {
			if ( $this->table_exists( $swap['target'] ) ) {
				$renames[] = $this->quote_identifier( $swap['target'] ) . ' TO ' . $this->quote_identifier( $swap['old'] );
			}
			$renames[] = $this->quote_identifier( $swap['staging'] ) . ' TO ' . $this->quote_identifier( $swap['target'] );
		}

		if ( empty( $renames ) ) {
			return true;
		}

		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			return new WP_Error( 'wsm_table_swap_failed', sprintf( __( 'Could not swap staging tables into place: %s', 'wp-site-migrator' ), $wpdb->last_error ) );
		}

		return true;
	}

	private function drop_old_tables( array $table_swaps ) {
		global $wpdb;

		foreach ( $table_swaps as $swap ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $swap['old'] ) );
		}
	}

	private function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private function ensure_plugin_active() {
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		if ( ! in_array( WSM_PLUGIN_BASENAME, $active_plugins, true ) ) {
			$active_plugins[] = WSM_PLUGIN_BASENAME;
			update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );
		}
	}

	private function rewrite_create_table_name( $create_sql, $target_table ) {
		return preg_replace(
			'/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?[^`\s]+`?/i',
			'CREATE TABLE ' . $this->quote_identifier( $target_table ),
			$create_sql,
			1
		);
	}

	private function temporary_table_name( $prefix, $seed ) {
		global $wpdb;

		$db_prefix = substr( preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix ), 0, 18 );
		return $db_prefix . $prefix . '_' . substr( md5( $seed ), 0, 24 );
	}

	private function is_rewritable_text_file( $path ) {
		if ( filesize( $path ) > 5 * MB_IN_BYTES ) {
			return false;
		}

		$basename = strtolower( basename( $path ) );
		if ( in_array( $basename, array( '.htaccess', 'web.config', 'robots.txt' ), true ) ) {
			return true;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension ) {
			return false;
		}

		return in_array( $extension, array( 'css', 'js', 'json', 'xml', 'txt', 'html', 'htm', 'svg', 'webmanifest', 'map', 'csv' ), true );
	}

	private function normalize_zip_name( $name ) {
		return ltrim( str_replace( '\\', '/', (string) $name ), '/' );
	}

	private function is_safe_zip_name( $name ) {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, ':' ) ) {
			return false;
		}

		foreach ( explode( '/', $name ) as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	private function site_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return sanitize_file_name( $host ? $host : 'wordpress-site' );
	}

	private function ratio( $value, $total ) {
		return $total > 0 ? min( 1, max( 0, $value / $total ) ) : 0;
	}

	private function disk_free_space( $path ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$space = @disk_free_space( $path );
		return false === $space ? null : (int) $space;
	}

	private function is_active_job( array $job ) {
		return ! empty( $job['status'] ) && in_array( $job['status'], array( 'running', 'validating', 'importing' ), true );
	}

	private function schedule_tick( $job_id ) {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'wsm_site_migrator_run_job', array( $job_id ) ) ) {
			wp_schedule_single_event( time() + 10, 'wsm_site_migrator_run_job', array( $job_id ) );
		}
	}

	private function sum_uploaded_bytes( array $files ) {
		$total = 0;
		foreach ( $files as $file ) {
			$total += isset( $file['uploaded'] ) ? (int) $file['uploaded'] : 0;
		}
		return $total;
	}

	private function all_uploads_complete( array $files ) {
		foreach ( $files as $file ) {
			if ( (int) $file['uploaded'] < (int) $file['size'] ) {
				return false;
			}
		}
		return true;
	}

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

	private function write_guards( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! file_exists( trailingslashit( $dir ) . 'index.html' ) ) {
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' );
		}

		if ( ! file_exists( trailingslashit( $dir ) . '.htaccess' ) ) {
			file_put_contents( trailingslashit( $dir ) . '.htaccess', "Deny from all\n" );
		}

		if ( ! file_exists( trailingslashit( $dir ) . 'web.config' ) ) {
			file_put_contents( trailingslashit( $dir ) . 'web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		}
	}

	private function delete_path( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}

		if ( ! is_dir( $path ) ) {
			return true;
		}

		$items = @scandir( $path );
		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->delete_path( $path . '/' . $item );
		}

		return @rmdir( $path );
	}
}

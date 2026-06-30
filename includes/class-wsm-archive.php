<?php
/**
 * Archive writer/reader and import coordinator.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Archive {
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
	 * Build an export package.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function export( $job_id ) {
		global $wpdb, $wp_version;

		$this->prepare_long_request();
		$this->job_store->update_job(
			$job_id,
			array(
				'status' => 'running',
				'phase'  => 'database',
			)
		);

		$preflight = new WSM_Preflight();
		$checks    = $preflight->run();
		if ( empty( $checks['ok'] ) ) {
			return $this->fail_job( $job_id, new WP_Error( 'wsm_preflight_failed', __( 'Preflight checks failed.', 'wp-site-migrator' ) ) );
		}

		$job_dir      = $this->job_store->get_job_dir( $job_id );
		$database_dir = $job_dir . '/database';
		$package_path = $this->job_store->get_package_path( $job_id );
		$database     = new WSM_Database( $this->logger );
		$db_schema    = $database->export( $job_id, $database_dir );

		if ( is_wp_error( $db_schema ) ) {
			return $this->fail_job( $job_id, $db_schema );
		}

		$this->job_store->update_job(
			$job_id,
			array(
				'phase' => 'files',
			)
		);

		$scanner = new WSM_File_Scanner( $this->job_store );
		$files   = $scanner->scan();

		$manifest = array(
			'package_format'    => 'wsm-package-v1',
			'package_type'      => 'single-site-content-database',
			'created_at'        => gmdate( 'c' ),
			'plugin_version'    => WSM_VERSION,
			'wordpress_version' => $wp_version,
			'wordpress_db_version' => get_option( 'db_version' ),
			'php_version'       => PHP_VERSION,
			'source_url'        => home_url(),
			'home_url'          => home_url(),
			'site_url'          => site_url(),
			'table_prefix'      => $wpdb->prefix,
			'database'          => array(
				'schema'        => 'database/schema.json',
				'schema_sha256' => isset( $db_schema['sha256'] ) ? $db_schema['sha256'] : '',
				'table_count'   => count( $db_schema['tables'] ),
				'tables'        => $db_schema['tables'],
			),
			'files'             => array(),
		);

		$zip = new ZipArchive();
		if ( true !== $zip->open( $package_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return $this->fail_job( $job_id, new WP_Error( 'wsm_zip_create_failed', __( 'Could not create the export zip package.', 'wp-site-migrator' ) ) );
		}

		$this->add_directory_to_zip( $zip, $database_dir, 'database' );

		foreach ( $files as $file ) {
			$archive_path = $this->archive_file_path( $file['section'], $file['relative_path'] );
			if ( ! $archive_path || ! is_file( $file['absolute_path'] ) ) {
				continue;
			}

			$zip->addFile( $file['absolute_path'], $archive_path );
			$manifest['files'][] = array(
				'section' => $file['section'],
				'path'    => $file['relative_path'],
				'archive' => $archive_path,
				'size'    => isset( $file['size'] ) ? (int) $file['size'] : filesize( $file['absolute_path'] ),
				'sha256'  => hash_file( 'sha256', $file['absolute_path'] ),
			);
		}

		$manifest_path = $job_dir . '/manifest.json';
		if ( false === file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			$zip->close();
			return $this->fail_job( $job_id, new WP_Error( 'wsm_manifest_write_failed', __( 'Could not write package manifest.', 'wp-site-migrator' ) ) );
		}

		$zip->addFile( $manifest_path, 'manifest.json' );
		$zip->close();

		$package_size = is_file( $package_path ) ? filesize( $package_path ) : 0;
		$package_hash = is_file( $package_path ) ? hash_file( 'sha256', $package_path ) : '';

		$this->logger->log( $job_id, sprintf( 'Export package created with %d files and %d tables', count( $manifest['files'] ), count( $db_schema['tables'] ) ) );

		return $this->job_store->update_job(
			$job_id,
			array(
				'status'          => 'completed',
				'phase'           => 'done',
				'completed_at'    => gmdate( 'c' ),
				'package_path'    => $package_path,
				'package_size'    => $package_size,
				'package_sha256'  => $package_hash,
				'manifest_summary' => $this->manifest_summary( $manifest ),
			)
		);
	}

	/**
	 * Validate an uploaded package.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public function validate_import_package( $job_id ) {
		$package_path = $this->job_store->get_package_path( $job_id );
		if ( ! is_file( $package_path ) ) {
			return new WP_Error( 'wsm_package_missing', __( 'Uploaded package was not found.', 'wp-site-migrator' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $package_path ) ) {
			return new WP_Error( 'wsm_zip_open_failed', __( 'Could not open the uploaded package.', 'wp-site-migrator' ) );
		}

		$name_result = $this->validate_zip_names( $zip );
		if ( is_wp_error( $name_result ) ) {
			$zip->close();
			return $name_result;
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		if ( false === $manifest_raw ) {
			$zip->close();
			return new WP_Error( 'wsm_manifest_missing', __( 'Package manifest is missing.', 'wp-site-migrator' ) );
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['package_format'] ) || 'wsm-package-v1' !== $manifest['package_format'] ) {
			$zip->close();
			return new WP_Error( 'wsm_manifest_invalid', __( 'Package manifest is invalid or unsupported.', 'wp-site-migrator' ) );
		}

		$schema_raw = $zip->getFromName( 'database/schema.json' );
		if ( false === $schema_raw ) {
			$zip->close();
			return new WP_Error( 'wsm_database_schema_missing', __( 'Database schema file is missing from the package.', 'wp-site-migrator' ) );
		}

		if ( ! empty( $manifest['database']['schema_sha256'] ) && hash( 'sha256', $schema_raw ) !== $manifest['database']['schema_sha256'] ) {
			$zip->close();
			return new WP_Error( 'wsm_database_schema_checksum_failed', __( 'Database schema checksum failed.', 'wp-site-migrator' ) );
		}

		$schema = json_decode( $schema_raw, true );
		if ( ! is_array( $schema ) || empty( $schema['tables'] ) ) {
			$zip->close();
			return new WP_Error( 'wsm_database_schema_invalid', __( 'Database schema file is invalid.', 'wp-site-migrator' ) );
		}

		$expected_entries = array(
			'manifest.json'         => true,
			'database/schema.json'  => true,
		);

		foreach ( $schema['tables'] as $table ) {
			if ( empty( $table['file'] ) || empty( $table['sha256'] ) ) {
				$zip->close();
				return new WP_Error( 'wsm_database_table_manifest_invalid', __( 'A database table entry is missing checksum data.', 'wp-site-migrator' ) );
			}

			$archive_path = 'database/' . ltrim( $table['file'], '/' );
			$expected_entries[ $archive_path ] = true;
			$hash         = $this->zip_entry_hash( $zip, $archive_path );
			if ( is_wp_error( $hash ) || $hash !== $table['sha256'] ) {
				$zip->close();
				return new WP_Error( 'wsm_database_table_checksum_failed', sprintf( __( 'Checksum failed for %s.', 'wp-site-migrator' ), $archive_path ) );
			}
		}

		if ( ! empty( $manifest['files'] ) && is_array( $manifest['files'] ) ) {
			foreach ( $manifest['files'] as $file ) {
				if ( empty( $file['archive'] ) || empty( $file['sha256'] ) ) {
					$zip->close();
					return new WP_Error( 'wsm_file_manifest_invalid', __( 'A file entry is missing checksum data.', 'wp-site-migrator' ) );
				}

				$expected_entries[ $file['archive'] ] = true;
				$hash = $this->zip_entry_hash( $zip, $file['archive'] );
				if ( is_wp_error( $hash ) || $hash !== $file['sha256'] ) {
					$zip->close();
					return new WP_Error( 'wsm_file_checksum_failed', sprintf( __( 'Checksum failed for %s.', 'wp-site-migrator' ), $file['archive'] ) );
				}
			}
		}

		$unexpected = $this->find_unexpected_zip_entry( $zip, $expected_entries );
		if ( is_wp_error( $unexpected ) ) {
			$zip->close();
			return $unexpected;
		}

		$zip->close();

		$this->job_store->update_job(
			$job_id,
			array(
				'status'           => 'validated',
				'phase'            => 'ready',
				'manifest_summary' => $this->manifest_summary( $manifest ),
			)
		);

		return $manifest;
	}

	/**
	 * Import an uploaded package.
	 *
	 * @param string $job_id Job id.
	 * @param string $target_url Destination URL.
	 * @return array|WP_Error
	 */
	public function import( $job_id, $target_url ) {
		$this->prepare_long_request();
		$this->job_store->update_job(
			$job_id,
			array(
				'status' => 'importing',
				'phase'  => 'validating',
			)
		);

		$preflight = new WSM_Preflight();
		$checks    = $preflight->run();
		if ( empty( $checks['ok'] ) ) {
			return $this->fail_job( $job_id, new WP_Error( 'wsm_preflight_failed', __( 'Preflight checks failed.', 'wp-site-migrator' ) ) );
		}

		$manifest = $this->validate_import_package( $job_id );
		if ( is_wp_error( $manifest ) ) {
			return $this->fail_job( $job_id, $manifest );
		}

		$job_dir          = $this->job_store->get_job_dir( $job_id );
		$staged_db_dir    = $job_dir . '/staged-database';
		$staged_files_dir = $job_dir . '/staged-files';

		$this->delete_tree( $staged_db_dir );
		$this->delete_tree( $staged_files_dir );
		wp_mkdir_p( $staged_db_dir );
		wp_mkdir_p( $staged_files_dir );

		$this->job_store->update_job( $job_id, array( 'phase' => 'extracting' ) );
		$extract_result = $this->extract_package( $job_id, $staged_db_dir, $staged_files_dir );
		if ( is_wp_error( $extract_result ) ) {
			return $this->fail_job( $job_id, $extract_result );
		}

		$this->job_store->update_job( $job_id, array( 'phase' => 'database' ) );
		$database = new WSM_Database( $this->logger );
		$db_result = $database->import( $job_id, $staged_db_dir, $manifest, $target_url );
		if ( is_wp_error( $db_result ) ) {
			return $this->fail_job( $job_id, $db_result );
		}

		$this->job_store->update_job( $job_id, array( 'phase' => 'files' ) );
		$file_result = $this->replace_files( $job_id, $staged_files_dir, $manifest, $target_url );
		if ( is_wp_error( $file_result ) ) {
			return $this->fail_job( $job_id, $file_result );
		}

		$this->logger->log( $job_id, 'Import completed' );

		return $this->job_store->update_job(
			$job_id,
			array(
				'status'       => 'completed',
				'phase'        => 'done',
				'completed_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Add a directory to a zip archive.
	 *
	 * @param ZipArchive $zip Zip archive.
	 * @param string     $base Base directory.
	 * @param string     $archive_base Archive base.
	 * @return void
	 */
	private function add_directory_to_zip( ZipArchive $zip, $base, $archive_base ) {
		$base = untrailingslashit( wp_normalize_path( $base ) );
		if ( ! is_dir( $base ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path     = wp_normalize_path( $item->getPathname() );
			$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
			$zip->addFile( $path, $archive_base . '/' . $relative );
		}
	}

	/**
	 * Validate all zip entry names.
	 *
	 * @param ZipArchive $zip Zip archive.
	 * @return true|WP_Error
	 */
	private function validate_zip_names( ZipArchive $zip ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( empty( $stat['name'] ) ) {
				return new WP_Error( 'wsm_zip_name_invalid', __( 'Package contains an invalid zip entry.', 'wp-site-migrator' ) );
			}

			$name = $this->normalize_zip_name( $stat['name'] );
			if ( ! $this->is_safe_zip_name( $name ) ) {
				return new WP_Error( 'wsm_zip_name_unsafe', sprintf( __( 'Package contains an unsafe path: %s', 'wp-site-migrator' ), $stat['name'] ) );
			}

			if ( 'manifest.json' !== $name && 0 !== strpos( $name, 'database/' ) && 0 !== strpos( $name, 'files/' ) ) {
				return new WP_Error( 'wsm_zip_name_unexpected', sprintf( __( 'Package contains an unexpected entry: %s', 'wp-site-migrator' ), $stat['name'] ) );
			}
		}

		return true;
	}

	/**
	 * Extract database and file entries to staging directories.
	 *
	 * @param string $job_id Job id.
	 * @param string $database_dir Database staging dir.
	 * @param string $files_dir Files staging dir.
	 * @return true|WP_Error
	 */
	private function extract_package( $job_id, $database_dir, $files_dir ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $this->job_store->get_package_path( $job_id ) ) ) {
			return new WP_Error( 'wsm_zip_open_failed', __( 'Could not open the uploaded package.', 'wp-site-migrator' ) );
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $this->normalize_zip_name( $stat['name'] );

			if ( substr( $name, -1 ) === '/' || 'manifest.json' === $name ) {
				continue;
			}

			if ( 0 === strpos( $name, 'database/' ) ) {
				$relative = substr( $name, strlen( 'database/' ) );
				$target   = $this->safe_join( $database_dir, $relative );
			} elseif ( 0 === strpos( $name, 'files/' ) ) {
				$relative = substr( $name, strlen( 'files/' ) );
				$target   = $this->safe_join( $files_dir, $relative );
			} else {
				continue;
			}

			if ( is_wp_error( $target ) ) {
				$zip->close();
				return $target;
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				$zip->close();
				return new WP_Error( 'wsm_extract_dir_failed', __( 'Could not create an extraction directory.', 'wp-site-migrator' ) );
			}

			$source = $zip->getStream( $name );
			$output = fopen( $target, 'wb' );
			if ( ! $source || ! $output ) {
				if ( $source ) {
					fclose( $source );
				}
				if ( $output ) {
					fclose( $output );
				}
				$zip->close();
				return new WP_Error( 'wsm_extract_file_failed', sprintf( __( 'Could not extract %s.', 'wp-site-migrator' ), $name ) );
			}

			stream_copy_to_stream( $source, $output );
			fclose( $source );
			fclose( $output );
		}

		$zip->close();
		$this->logger->log( $job_id, 'Package extracted to staging' );

		return true;
	}

	/**
	 * Replace destination files with staged package files.
	 *
	 * @param string $job_id Job id.
	 * @param string $staged_files_dir Staged files dir.
	 * @param array  $manifest Manifest.
	 * @param string $target_url Target URL.
	 * @return true|WP_Error
	 */
	private function replace_files( $job_id, $staged_files_dir, array $manifest, $target_url ) {
		$source_urls = array_filter(
			array(
				isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
				isset( $manifest['site_url'] ) ? $manifest['site_url'] : '',
				isset( $manifest['source_url'] ) ? $manifest['source_url'] : '',
			)
		);
		$rewriter = new WSM_URL_Rewriter( $source_urls, $target_url );

		$this->rewrite_staged_text_files( $staged_files_dir, $rewriter );

		$upload_dir = wp_upload_dir();
		$sections   = array(
			'uploads'    => empty( $upload_dir['error'] ) ? $upload_dir['basedir'] : '',
			'themes'     => get_theme_root(),
			'plugins'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'languages'  => defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : '',
		);

		foreach ( $sections as $section => $target ) {
			$source = $staged_files_dir . '/' . $section;
			if ( ! $target || ! is_dir( $source ) ) {
				continue;
			}

			$result = $this->swap_directory( $job_id, $section, $source, $target );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$root_dir = $staged_files_dir . '/root';
		if ( is_dir( $root_dir ) ) {
			foreach ( array( '.htaccess', 'web.config', 'robots.txt', 'favicon.ico' ) as $file_name ) {
				$source = $root_dir . '/' . $file_name;
				if ( is_file( $source ) ) {
					if ( false === @copy( $source, ABSPATH . $file_name ) ) {
						return new WP_Error( 'wsm_root_copy_failed', sprintf( __( 'Could not copy root file %s.', 'wp-site-migrator' ), $file_name ) );
					}
				}
			}
		}

		$this->logger->log( $job_id, 'File replacement finished' );

		return true;
	}

	/**
	 * Swap a staged directory into its destination.
	 *
	 * @param string $job_id Job id.
	 * @param string $section Section.
	 * @param string $source Source dir.
	 * @param string $target Target dir.
	 * @return true|WP_Error
	 */
	private function swap_directory( $job_id, $section, $source, $target ) {
		$source = untrailingslashit( $source );
		$target = untrailingslashit( $target );
		$parent = dirname( $target );

		if ( ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'wsm_parent_dir_failed', sprintf( __( 'Could not create parent directory for %s.', 'wp-site-migrator' ), $section ) );
		}

		$token = substr( md5( $job_id . $section ), 0, 8 );
		$old   = $parent . '/.wsm-old-' . sanitize_key( $section ) . '-' . $token;
		$new   = $source;

		if ( $this->path_is_inside( $source, $target ) ) {
			$new = $parent . '/.wsm-new-' . sanitize_key( $section ) . '-' . $token;
			$this->delete_tree( $new );

			if ( ! @rename( $source, $new ) ) {
				if ( ! $this->copy_tree( $source, $new ) ) {
					return new WP_Error( 'wsm_file_swap_failed', sprintf( __( 'Could not move staged %s files to a safe swap location.', 'wp-site-migrator' ), $section ) );
				}
				$this->delete_tree( $source );
			}
		}

		$this->delete_tree( $old );

		if ( file_exists( $target ) && ! @rename( $target, $old ) ) {
			return new WP_Error( 'wsm_file_swap_failed', sprintf( __( 'Could not move existing %s directory aside.', 'wp-site-migrator' ), $section ) );
		}

		if ( ! @rename( $new, $target ) ) {
			if ( file_exists( $old ) && ! file_exists( $target ) ) {
				@rename( $old, $target );
			}
			return new WP_Error( 'wsm_file_swap_failed', sprintf( __( 'Could not move imported %s directory into place.', 'wp-site-migrator' ), $section ) );
		}

		if ( 'plugins' === $section ) {
			$this->restore_migrator_plugin_if_missing( $old, $target );
		}

		$this->delete_tree( $old );
		$this->logger->log( $job_id, sprintf( 'Replaced %s files', $section ) );

		return true;
	}

	/**
	 * Keep this plugin on disk even if a package did not include it.
	 *
	 * @param string $old_plugins_dir Old plugins dir.
	 * @param string $new_plugins_dir New plugins dir.
	 * @return void
	 */
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

	/**
	 * Rewrite safe text files in staging.
	 *
	 * @param string           $dir Staged files dir.
	 * @param WSM_URL_Rewriter $rewriter URL rewriter.
	 * @return void
	 */
	private function rewrite_staged_text_files( $dir, WSM_URL_Rewriter $rewriter ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() || ! $this->is_rewritable_text_file( $item->getPathname() ) ) {
				continue;
			}

			$rewriter->rewrite_text_file( $item->getPathname() );
		}
	}

	/**
	 * Check whether a file is safe for text URL rewriting.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
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

		return in_array(
			$extension,
			array( 'css', 'js', 'json', 'xml', 'txt', 'html', 'htm', 'svg', 'webmanifest', 'map', 'csv' ),
			true
		);
	}

	/**
	 * Hash a zip entry stream.
	 *
	 * @param ZipArchive $zip Zip archive.
	 * @param string     $entry Entry name.
	 * @return string|WP_Error
	 */
	private function zip_entry_hash( ZipArchive $zip, $entry ) {
		$stream = $zip->getStream( $entry );
		if ( ! $stream ) {
			return new WP_Error( 'wsm_zip_entry_missing', sprintf( __( 'Package entry is missing: %s', 'wp-site-migrator' ), $entry ) );
		}

		$context = hash_init( 'sha256' );
		while ( ! feof( $stream ) ) {
			hash_update( $context, fread( $stream, 1024 * 1024 ) );
		}
		fclose( $stream );

		return hash_final( $context );
	}

	/**
	 * Reject zip entries that are not represented in the signed manifest.
	 *
	 * @param ZipArchive $zip Zip archive.
	 * @param array      $expected_entries Expected entries keyed by name.
	 * @return true|WP_Error
	 */
	private function find_unexpected_zip_entry( ZipArchive $zip, array $expected_entries ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $this->normalize_zip_name( $stat['name'] );

			if ( substr( $name, -1 ) === '/' ) {
				continue;
			}

			if ( empty( $expected_entries[ $name ] ) ) {
				return new WP_Error( 'wsm_zip_unmanifested_entry', sprintf( __( 'Package contains an unmanifested file: %s', 'wp-site-migrator' ), $name ) );
			}
		}

		return true;
	}

	/**
	 * Build an archive file path.
	 *
	 * @param string $section Section.
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function archive_file_path( $section, $relative ) {
		$section  = sanitize_key( $section );
		$relative = $this->normalize_zip_name( $relative );

		if ( ! $this->is_safe_zip_name( $relative ) ) {
			return '';
		}

		return 'files/' . $section . '/' . $relative;
	}

	/**
	 * Normalize a zip name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function normalize_zip_name( $name ) {
		return ltrim( str_replace( '\\', '/', (string) $name ), '/' );
	}

	/**
	 * Validate a zip path against traversal/absolute paths.
	 *
	 * @param string $name Zip path.
	 * @return bool
	 */
	private function is_safe_zip_name( $name ) {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, ':' ) ) {
			return false;
		}

		$parts = explode( '/', $name );
		foreach ( $parts as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Safely join a base path with a relative path.
	 *
	 * @param string $base Base path.
	 * @param string $relative Relative path.
	 * @return string|WP_Error
	 */
	private function safe_join( $base, $relative ) {
		$relative = $this->normalize_zip_name( $relative );
		if ( ! $this->is_safe_zip_name( $relative ) ) {
			return new WP_Error( 'wsm_unsafe_path', __( 'Package contains an unsafe path.', 'wp-site-migrator' ) );
		}

		return untrailingslashit( $base ) . '/' . $relative;
	}

	/**
	 * Create a compact manifest summary for the UI.
	 *
	 * @param array $manifest Manifest.
	 * @return array
	 */
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
		);
	}

	/**
	 * Mark a job failed.
	 *
	 * @param string   $job_id Job id.
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private function fail_job( $job_id, WP_Error $error ) {
		$this->logger->log( $job_id, $error->get_error_message(), 'error' );
		$this->job_store->update_job(
			$job_id,
			array(
				'status' => 'failed',
				'errors' => array(
					array(
						'code'    => $error->get_error_code(),
						'message' => $error->get_error_message(),
					),
				),
			)
		);

		return $error;
	}

	/**
	 * Raise runtime limits for long admin operations.
	 *
	 * @return void
	 */
	private function prepare_long_request() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
	}

	/**
	 * Recursively delete a path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function delete_tree( $path ) {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}

		$items = @scandir( $path );
		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->delete_tree( $path . '/' . $item );
		}

		return @rmdir( $path );
	}

	/**
	 * Copy a directory tree.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 * @return bool
	 */
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
		if ( ! is_array( $items ) ) {
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

	/**
	 * Check whether a path is inside another path.
	 *
	 * @param string $path Path.
	 * @param string $parent Parent path.
	 * @return bool
	 */
	private function path_is_inside( $path, $parent ) {
		$path_real   = realpath( $path );
		$parent_real = realpath( $parent );

		if ( ! $path_real || ! $parent_real ) {
			return false;
		}

		$path_real   = trailingslashit( wp_normalize_path( $path_real ) );
		$parent_real = trailingslashit( wp_normalize_path( $parent_real ) );

		return 0 === strpos( $path_real, $parent_real );
	}
}

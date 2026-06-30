<?php
/**
 * Database export/import service.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Database {
	/**
	 * Logger.
	 *
	 * @var WSM_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WSM_Logger $logger Logger.
	 */
	public function __construct( WSM_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Export prefixed WordPress tables into JSONL files.
	 *
	 * @param string $job_id Job id.
	 * @param string $database_dir Database export directory.
	 * @return array|WP_Error
	 */
	public function export( $job_id, $database_dir ) {
		global $wpdb;

		if ( ! wp_mkdir_p( $database_dir . '/data' ) ) {
			return new WP_Error( 'wsm_database_dir_failed', __( 'Could not create database export directory.', 'wp-site-migrator' ) );
		}

		$tables = $this->get_source_tables();
		if ( empty( $tables ) ) {
			return new WP_Error( 'wsm_no_tables', __( 'No WordPress tables were found for the current table prefix.', 'wp-site-migrator' ) );
		}

		$schema = array(
			'format'       => 'wsm-jsonl-v1',
			'table_prefix' => $wpdb->prefix,
			'tables'       => array(),
		);

		foreach ( $tables as $table ) {
			$this->logger->log( $job_id, sprintf( 'Exporting table %s', $table ) );

			$create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->quote_identifier( $table ), ARRAY_N );
			if ( ! is_array( $create_row ) || empty( $create_row[1] ) ) {
				return new WP_Error( 'wsm_create_table_failed', sprintf( __( 'Could not read schema for table %s.', 'wp-site-migrator' ), $table ) );
			}

			$suffix   = substr( $table, strlen( $wpdb->prefix ) );
			$file     = substr( md5( $table ), 0, 16 ) . '.jsonl';
			$file_path = $database_dir . '/data/' . $file;
			$count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $table ) );

			$written = $this->write_table_rows( $table, $file_path );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$schema['tables'][] = array(
				'source_table' => $table,
				'suffix'       => $suffix,
				'file'         => 'data/' . $file,
				'rows'         => $count,
				'create_sql'   => $create_row[1],
				'sha256'       => hash_file( 'sha256', $file_path ),
			);
		}

		$schema_path = $database_dir . '/schema.json';
		if ( false === file_put_contents( $schema_path, wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			return new WP_Error( 'wsm_schema_write_failed', __( 'Could not write database schema manifest.', 'wp-site-migrator' ) );
		}

		$schema['sha256'] = hash_file( 'sha256', $schema_path );

		return $schema;
	}

	/**
	 * Import database files into staging tables, then swap into place.
	 *
	 * @param string $job_id Job id.
	 * @param string $database_dir Database directory.
	 * @param array  $manifest Package manifest.
	 * @param string $target_url Destination URL.
	 * @return true|WP_Error
	 */
	public function import( $job_id, $database_dir, array $manifest, $target_url ) {
		global $wpdb, $wp_version;

		$schema_path = $database_dir . '/schema.json';
		if ( ! is_file( $schema_path ) ) {
			return new WP_Error( 'wsm_schema_missing', __( 'Database schema manifest is missing from the package.', 'wp-site-migrator' ) );
		}

		$schema = json_decode( file_get_contents( $schema_path ), true );
		if ( ! is_array( $schema ) || empty( $schema['tables'] ) || 'wsm-jsonl-v1' !== $schema['format'] ) {
			return new WP_Error( 'wsm_schema_invalid', __( 'Database schema manifest is invalid.', 'wp-site-migrator' ) );
		}

		if ( ! empty( $manifest['wordpress_version'] ) && version_compare( $wp_version, $manifest['wordpress_version'], '<' ) ) {
			return new WP_Error( 'wsm_wordpress_too_old', __( 'The destination WordPress version is older than the source site.', 'wp-site-migrator' ) );
		}

		if ( ! empty( $manifest['wordpress_db_version'] ) && (int) get_option( 'db_version' ) < (int) $manifest['wordpress_db_version'] ) {
			return new WP_Error( 'wsm_wordpress_database_too_old', __( 'The destination WordPress database version is older than the source site.', 'wp-site-migrator' ) );
		}

		$source_urls = array_filter(
			array(
				isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
				isset( $manifest['site_url'] ) ? $manifest['site_url'] : '',
				isset( $manifest['source_url'] ) ? $manifest['source_url'] : '',
			)
		);
		$rewriter    = new WSM_URL_Rewriter( $source_urls, $target_url );
		$table_swaps = array();

		foreach ( $schema['tables'] as $table ) {
			$result = $this->import_table_to_staging( $job_id, $database_dir, $table, $rewriter );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_staging_tables( $table_swaps );
				return $result;
			}

			$table_swaps[] = $result;
		}

		$swap_result = $this->swap_staging_tables( $table_swaps );
		if ( is_wp_error( $swap_result ) ) {
			$this->cleanup_staging_tables( $table_swaps );
			return $swap_result;
		}

		$this->drop_old_tables( $table_swaps );

		update_option( 'home', untrailingslashit( esc_url_raw( $target_url ) ) );
		update_option( 'siteurl', untrailingslashit( esc_url_raw( $target_url ) ) );
		$this->ensure_plugin_active();

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		$this->logger->log( $job_id, 'Database import finished' );

		return true;
	}

	/**
	 * Get all source tables with the current prefix.
	 *
	 * @return array
	 */
	private function get_source_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Write table rows to a JSONL file.
	 *
	 * @param string $table Table name.
	 * @param string $file_path File path.
	 * @return true|WP_Error
	 */
	private function write_table_rows( $table, $file_path ) {
		global $wpdb;

		$handle = fopen( $file_path, 'wb' );
		if ( ! $handle ) {
			return new WP_Error( 'wsm_table_file_failed', sprintf( __( 'Could not write export file for table %s.', 'wp-site-migrator' ), $table ) );
		}

		$offset = 0;
		$limit  = 500;

		do {
			$sql  = sprintf( 'SELECT * FROM %s LIMIT %d OFFSET %d', $this->quote_identifier( $table ), $limit, $offset );
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( ! is_array( $rows ) ) {
				fclose( $handle );
				return new WP_Error( 'wsm_table_read_failed', sprintf( __( 'Could not read rows from table %s.', 'wp-site-migrator' ), $table ) );
			}

			foreach ( $rows as $row ) {
				fwrite( $handle, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
			}

			$offset += $limit;
		} while ( count( $rows ) === $limit );

		fclose( $handle );

		return true;
	}

	/**
	 * Import one table into a staging table.
	 *
	 * @param string           $job_id Job id.
	 * @param string           $database_dir Database dir.
	 * @param array            $table Table schema.
	 * @param WSM_URL_Rewriter $rewriter URL rewriter.
	 * @return array|WP_Error
	 */
	private function import_table_to_staging( $job_id, $database_dir, array $table, WSM_URL_Rewriter $rewriter ) {
		global $wpdb;

		if ( empty( $table['source_table'] ) || ! isset( $table['suffix'] ) || empty( $table['file'] ) || empty( $table['create_sql'] ) ) {
			return new WP_Error( 'wsm_table_schema_invalid', __( 'A table entry in the database manifest is invalid.', 'wp-site-migrator' ) );
		}

		$suffix = (string) $table['suffix'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $suffix ) ) {
			return new WP_Error( 'wsm_table_suffix_invalid', __( 'A table suffix in the package is invalid.', 'wp-site-migrator' ) );
		}

		$target_table  = $wpdb->prefix . $suffix;
		$staging_table = $this->temporary_table_name( 'wsmstg', $job_id . $target_table );
		$old_table     = $this->temporary_table_name( 'wsmold', $job_id . $target_table );
		$data_path     = $database_dir . '/' . ltrim( $table['file'], '/' );

		if ( ! is_file( $data_path ) ) {
			return new WP_Error( 'wsm_table_data_missing', sprintf( __( 'Data file for table %s is missing.', 'wp-site-migrator' ), $table['source_table'] ) );
		}

		if ( ! empty( $table['sha256'] ) && hash_file( 'sha256', $data_path ) !== $table['sha256'] ) {
			return new WP_Error( 'wsm_table_checksum_failed', sprintf( __( 'Checksum failed for table %s.', 'wp-site-migrator' ), $table['source_table'] ) );
		}

		$this->logger->log( $job_id, sprintf( 'Importing table %s into staging', $target_table ) );

		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $staging_table ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $old_table ) );

		$create_sql = $this->rewrite_create_table_name( $table['create_sql'], $staging_table );
		if ( false === $wpdb->query( $create_sql ) ) {
			$this->drop_table_names( array( $staging_table, $old_table ) );
			return new WP_Error( 'wsm_create_staging_failed', sprintf( __( 'Could not create staging table for %s: %s', 'wp-site-migrator' ), $target_table, $wpdb->last_error ) );
		}

		$handle = fopen( $data_path, 'rb' );
		if ( ! $handle ) {
			$this->drop_table_names( array( $staging_table, $old_table ) );
			return new WP_Error( 'wsm_table_open_failed', sprintf( __( 'Could not open data file for table %s.', 'wp-site-migrator' ), $table['source_table'] ) );
		}

		$line_number = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$line_number++;
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				fclose( $handle );
				$this->drop_table_names( array( $staging_table, $old_table ) );
				return new WP_Error( 'wsm_row_invalid', sprintf( __( 'Invalid row JSON in %1$s at line %2$d.', 'wp-site-migrator' ), $table['source_table'], $line_number ) );
			}

			foreach ( $row as $column => $value ) {
				$row[ $column ] = $rewriter->rewrite_value( $value );
			}

			if ( false === $wpdb->insert( $staging_table, $row ) ) {
				fclose( $handle );
				$this->drop_table_names( array( $staging_table, $old_table ) );
				return new WP_Error( 'wsm_row_insert_failed', sprintf( __( 'Could not insert row into %1$s: %2$s', 'wp-site-migrator' ), $staging_table, $wpdb->last_error ) );
			}
		}

		fclose( $handle );

		return array(
			'target'  => $target_table,
			'staging' => $staging_table,
			'old'     => $old_table,
		);
	}

	/**
	 * Swap all staging tables into live names.
	 *
	 * @param array $table_swaps Table swaps.
	 * @return true|WP_Error
	 */
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

		$sql = 'RENAME TABLE ' . implode( ', ', $renames );
		if ( false === $wpdb->query( $sql ) ) {
			return new WP_Error( 'wsm_table_swap_failed', sprintf( __( 'Could not swap staging tables into place: %s', 'wp-site-migrator' ), $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Drop old tables after a successful swap.
	 *
	 * @param array $table_swaps Table swaps.
	 * @return void
	 */
	private function drop_old_tables( array $table_swaps ) {
		global $wpdb;

		foreach ( $table_swaps as $swap ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $swap['old'] ) );
		}
	}

	/**
	 * Drop staging tables after a failed import.
	 *
	 * @param array $table_swaps Table swaps.
	 * @return void
	 */
	private function cleanup_staging_tables( array $table_swaps ) {
		global $wpdb;

		foreach ( $table_swaps as $swap ) {
			if ( ! empty( $swap['staging'] ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $swap['staging'] ) );
			}
			if ( ! empty( $swap['old'] ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $swap['old'] ) );
			}
		}
	}

	/**
	 * Drop temporary table names.
	 *
	 * @param array $tables Table names.
	 * @return void
	 */
	private function drop_table_names( array $tables ) {
		global $wpdb;

		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) );
		}
	}

	/**
	 * Ensure this plugin remains active after replacing the options table.
	 *
	 * @return void
	 */
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

	/**
	 * Replace CREATE TABLE target.
	 *
	 * @param string $create_sql Create SQL.
	 * @param string $target_table Target table.
	 * @return string
	 */
	private function rewrite_create_table_name( $create_sql, $target_table ) {
		return preg_replace(
			'/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?[^`\s]+`?/i',
			'CREATE TABLE ' . $this->quote_identifier( $target_table ),
			$create_sql,
			1
		);
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Build a short temporary table name.
	 *
	 * @param string $prefix Prefix.
	 * @param string $seed Seed.
	 * @return string
	 */
	private function temporary_table_name( $prefix, $seed ) {
		global $wpdb;

		$db_prefix = substr( preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix ), 0, 18 );
		return $db_prefix . $prefix . '_' . substr( md5( $seed ), 0, 24 );
	}

	/**
	 * Quote a MySQL identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}

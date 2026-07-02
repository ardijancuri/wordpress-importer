<?php
/**
 * Host compatibility checks.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Preflight {
	/**
	 * Run preflight checks.
	 *
	 * @return array
	 */
	public function run() {
		global $wp_version;

		$upload_dir = wp_upload_dir();
		$checks     = array();

		$checks[] = $this->check( 'single_site', ! is_multisite(), __( 'Single-site WordPress install', 'wp-site-migrator' ), __( 'Multisite is not supported in this version.', 'wp-site-migrator' ), true );
		$checks[] = $this->check( 'ziparchive', class_exists( 'ZipArchive' ), __( 'ZipArchive available', 'wp-site-migrator' ), __( 'The PHP ZipArchive extension is required.', 'wp-site-migrator' ), true );
		$checks[] = $this->check( 'uploads_available', empty( $upload_dir['error'] ), __( 'Uploads directory available', 'wp-site-migrator' ), empty( $upload_dir['error'] ) ? '' : $upload_dir['error'], true );
		$checks[] = $this->check( 'uploads_writable', empty( $upload_dir['error'] ) && wp_is_writable( $upload_dir['basedir'] ), __( 'Uploads directory writable', 'wp-site-migrator' ), __( 'The uploads directory must be writable for packages and imports.', 'wp-site-migrator' ), true );
		$checks[] = $this->check( 'wp_content_writable', defined( 'WP_CONTENT_DIR' ) && wp_is_writable( WP_CONTENT_DIR ), __( 'wp-content directory writable', 'wp-site-migrator' ), __( 'The wp-content directory must be writable so import jobs survive uploads replacement.', 'wp-site-migrator' ), true );
		$checks[] = $this->check( 'php_64_bit', PHP_INT_SIZE >= 8, __( '64-bit PHP runtime', 'wp-site-migrator' ), __( 'Heavy multipart migrations require 64-bit PHP for large file offsets.', 'wp-site-migrator' ), false );

		$free_space = $this->disk_free_space( empty( $upload_dir['error'] ) ? $upload_dir['basedir'] : ABSPATH );
		$checks[]   = array(
			'id'       => 'disk_space',
			'label'    => __( 'Available disk space', 'wp-site-migrator' ),
			'ok'       => null !== $free_space,
			'fatal'    => false,
			'message'  => null === $free_space ? __( 'Unable to determine free disk space.', 'wp-site-migrator' ) : size_format( $free_space ),
			'value'    => $free_space,
		);

		$checks[] = array(
			'id'      => 'php_version',
			'label'   => __( 'PHP version', 'wp-site-migrator' ),
			'ok'      => version_compare( PHP_VERSION, '7.4', '>=' ),
			'fatal'   => version_compare( PHP_VERSION, '7.4', '<' ),
			'message' => PHP_VERSION,
			'value'   => PHP_VERSION,
		);

		$checks[] = array(
			'id'      => 'wordpress_version',
			'label'   => __( 'WordPress version', 'wp-site-migrator' ),
			'ok'      => version_compare( $wp_version, '6.0', '>=' ),
			'fatal'   => false,
			'message' => $wp_version,
			'value'   => $wp_version,
		);

		$checks[] = array(
			'id'      => 'limits',
			'label'   => __( 'Upload limits', 'wp-site-migrator' ),
			'ok'      => true,
			'fatal'   => false,
			'message' => sprintf(
				/* translators: 1: upload max size, 2: post max size, 3: memory limit */
				__( 'upload_max_filesize %1$s, post_max_size %2$s, memory_limit %3$s', 'wp-site-migrator' ),
				ini_get( 'upload_max_filesize' ),
				ini_get( 'post_max_size' ),
				ini_get( 'memory_limit' )
			),
			'value'   => array(
				'upload_max_filesize' => $this->parse_size( ini_get( 'upload_max_filesize' ) ),
				'post_max_size'      => $this->parse_size( ini_get( 'post_max_size' ) ),
				'memory_limit'       => $this->parse_size( ini_get( 'memory_limit' ) ),
			),
		);

		$checks[] = array(
			'id'      => 'execution_limits',
			'label'   => __( 'Execution limits', 'wp-site-migrator' ),
			'ok'      => true,
			'fatal'   => false,
			'message' => sprintf(
				/* translators: 1: max execution time, 2: max input time */
				__( 'max_execution_time %1$s, max_input_time %2$s. Heavy jobs run in short resumable ticks.', 'wp-site-migrator' ),
				ini_get( 'max_execution_time' ),
				ini_get( 'max_input_time' )
			),
			'value'   => array(
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'max_input_time'     => (int) ini_get( 'max_input_time' ),
			),
		);

		$fatal = false;
		foreach ( $checks as $check ) {
			if ( ! empty( $check['fatal'] ) && empty( $check['ok'] ) ) {
				$fatal = true;
				break;
			}
		}

		return array(
			'ok'     => ! $fatal,
			'checks' => $checks,
		);
	}

	/**
	 * Build a check array.
	 *
	 * @param string $id ID.
	 * @param bool   $ok Whether check passes.
	 * @param string $label Label.
	 * @param string $message Message.
	 * @param bool   $fatal Fatal when failed.
	 * @return array
	 */
	private function check( $id, $ok, $label, $message, $fatal ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'ok'      => (bool) $ok,
			'fatal'   => $fatal && ! $ok,
			'message' => $ok ? __( 'OK', 'wp-site-migrator' ) : $message,
		);
	}

	/**
	 * Get free disk space.
	 *
	 * @param string $path Path.
	 * @return int|null
	 */
	private function disk_free_space( $path ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$space = @disk_free_space( $path );
		return false === $space ? null : (int) $space;
	}

	/**
	 * Parse a PHP size string.
	 *
	 * @param string $size Size string.
	 * @return int
	 */
	private function parse_size( $size ) {
		$size = trim( (string) $size );
		if ( '' === $size ) {
			return 0;
		}

		$unit  = strtolower( substr( $size, -1 ) );
		$value = (float) $size;

		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}

		return (int) $value;
	}
}

<?php
/**
 * Migration logger.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Logger {
	/**
	 * Job store.
	 *
	 * @var WSM_Job_Store
	 */
	private $job_store;

	/**
	 * Constructor.
	 *
	 * @param WSM_Job_Store $job_store Job store.
	 */
	public function __construct( WSM_Job_Store $job_store ) {
		$this->job_store = $job_store;
	}

	/**
	 * Write a log message.
	 *
	 * @param string $job_id Job id.
	 * @param string $message Message.
	 * @param string $level Level.
	 * @return void
	 */
	public function log( $job_id, $message, $level = 'info' ) {
		$line = sprintf( "[%s] %s: %s\n", gmdate( 'c' ), strtoupper( sanitize_key( $level ) ), wp_strip_all_tags( (string) $message ) );
		@file_put_contents( $this->job_store->get_log_path( $job_id ), $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Read the tail of the job log.
	 *
	 * @param string $job_id Job id.
	 * @param int    $max_lines Max lines.
	 * @return array
	 */
	public function read( $job_id, $max_lines = 200 ) {
		$path = $this->job_store->get_log_path( $job_id );
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_slice( $lines, -absint( $max_lines ) );
	}
}

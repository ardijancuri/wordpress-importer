<?php
/**
 * File discovery for export packages.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_File_Scanner {
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
	 * Scan exportable files.
	 *
	 * @return array
	 */
	public function scan() {
		$files      = array();
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['error'] ) && is_dir( $upload_dir['basedir'] ) ) {
			$files = array_merge( $files, $this->scan_dir( 'uploads', $upload_dir['basedir'] ) );
		}

		if ( is_dir( get_theme_root() ) ) {
			$files = array_merge( $files, $this->scan_dir( 'themes', get_theme_root() ) );
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
			$files = array_merge( $files, $this->scan_dir( 'plugins', WP_PLUGIN_DIR ) );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$files = array_merge( $files, $this->scan_dir( 'mu-plugins', WPMU_PLUGIN_DIR ) );
		}

		if ( defined( 'WP_LANG_DIR' ) && is_dir( WP_LANG_DIR ) ) {
			$files = array_merge( $files, $this->scan_dir( 'languages', WP_LANG_DIR ) );
		}

		foreach ( $this->root_files() as $file_name ) {
			$path = ABSPATH . $file_name;
			if ( is_file( $path ) && is_readable( $path ) ) {
				$files[] = array(
					'section'      => 'root',
					'absolute_path' => $path,
					'relative_path' => $file_name,
					'size'          => filesize( $path ),
				);
			}
		}

		return $files;
	}

	/**
	 * Scan a directory recursively.
	 *
	 * @param string $section Archive section.
	 * @param string $base Base directory.
	 * @return array
	 */
	private function scan_dir( $section, $base ) {
		$base = untrailingslashit( wp_normalize_path( $base ) );
		if ( ! is_dir( $base ) || ! is_readable( $base ) ) {
			return array();
		}

		$files = array();
		$stack = array( $base );

		while ( $stack ) {
			$dir   = array_pop( $stack );
			$items = @scandir( $dir );

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = $dir . '/' . $item;
				if ( $this->should_skip_path( $path, $item ) ) {
					continue;
				}

				if ( is_dir( $path ) ) {
					$stack[] = $path;
					continue;
				}

				if ( is_file( $path ) && is_readable( $path ) ) {
					$relative = ltrim( substr( wp_normalize_path( $path ), strlen( $base ) ), '/' );
					$files[]  = array(
						'section'      => $section,
						'absolute_path' => $path,
						'relative_path' => $relative,
						'size'          => filesize( $path ),
					);
				}
			}
		}

		return $files;
	}

	/**
	 * Check whether a path should be skipped.
	 *
	 * @param string $path Path.
	 * @param string $basename Basename.
	 * @return bool
	 */
	private function should_skip_path( $path, $basename ) {
		$basename = strtolower( $basename );
		$skip_dir_names = array( '.git', '.svn', '.hg', 'cache', 'caches', 'log', 'logs', 'upgrade', 'updraft', 'backups', 'backup' );

		if ( in_array( $basename, $skip_dir_names, true ) ) {
			return true;
		}

		if ( 0 === strpos( basename( $path ), 'wsm-' ) ) {
			return true;
		}

		$storage_root = realpath( $this->job_store->get_root_dir() );
		$real_path    = realpath( $path );
		if ( $storage_root && $real_path && 0 === strpos( wp_normalize_path( $real_path ), wp_normalize_path( $storage_root ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Selected root files to include.
	 *
	 * @return array
	 */
	private function root_files() {
		return array(
			'.htaccess',
			'web.config',
			'robots.txt',
			'favicon.ico',
		);
	}
}

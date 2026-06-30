<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSM_Plugin {
	const REST_NAMESPACE = 'wp-site-migrator/v1';

	/**
	 * Singleton instance.
	 *
	 * @var WSM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Job store service.
	 *
	 * @var WSM_Job_Store
	 */
	private $job_store;

	/**
	 * Logger service.
	 *
	 * @var WSM_Logger
	 */
	private $logger;

	/**
	 * Admin controller.
	 *
	 * @var WSM_Admin
	 */
	private $admin;

	/**
	 * REST controller.
	 *
	 * @var WSM_REST_Controller
	 */
	private $rest_controller;

	/**
	 * Get the singleton.
	 *
	 * @return WSM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::load_files();
		$job_store = new WSM_Job_Store();
		$job_store->ensure_base_dir();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		self::load_files();

		$this->job_store       = new WSM_Job_Store();
		$this->logger          = new WSM_Logger( $this->job_store );
		$this->admin           = new WSM_Admin();
		$this->rest_controller = new WSM_REST_Controller( $this->job_store, $this->logger );

		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'admin_post_wsm_download_export', array( $this->rest_controller, 'download_export_admin_post' ) );
	}

	/**
	 * Load required class files.
	 *
	 * @return void
	 */
	private static function load_files() {
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-job-store.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-logger.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-preflight.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-file-scanner.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-url-rewriter.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-database.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-archive.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-admin.php';
		require_once WSM_PLUGIN_DIR . 'includes/class-wsm-rest-controller.php';
	}
}

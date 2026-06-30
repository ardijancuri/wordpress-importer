<?php
/**
 * Admin UI controller.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Admin {
	const PAGE_SLUG = 'wp-site-migrator';

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_management_page(
			__( 'Site Migrator', 'wp-site-migrator' ),
			__( 'Site Migrator', 'wp-site-migrator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wsm-admin', WSM_PLUGIN_URL . 'assets/admin.css', array(), WSM_VERSION );
		wp_enqueue_script( 'wsm-admin', WSM_PLUGIN_URL . 'assets/admin.js', array(), WSM_VERSION, true );
		wp_localize_script(
			'wsm-admin',
			'WSMSiteMigrator',
			array(
				'restUrl'    => esc_url_raw( rest_url( WSM_Plugin::REST_NAMESPACE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'downloadUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'downloadNonce' => wp_create_nonce( 'wsm_download_export' ),
				'homeUrl'    => home_url(),
				'adminUrl'   => admin_url(),
				'pluginName' => __( 'WP Site Migrator', 'wp-site-migrator' ),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-site-migrator' ) );
		}
		?>
		<div class="wrap wsm-wrap">
			<h1><?php esc_html_e( 'WP Site Migrator', 'wp-site-migrator' ); ?></h1>

			<div class="wsm-layout">
				<section class="wsm-panel">
					<h2><?php esc_html_e( 'Export', 'wp-site-migrator' ); ?></h2>
					<p><?php esc_html_e( 'Create a migration package containing this site database, uploads, themes, plugins, languages, and selected root files.', 'wp-site-migrator' ); ?></p>
					<button type="button" class="button button-primary" id="wsm-start-export"><?php esc_html_e( 'Create Export Package', 'wp-site-migrator' ); ?></button>
					<a class="button wsm-hidden" id="wsm-download-export" href="#" download><?php esc_html_e( 'Download Package', 'wp-site-migrator' ); ?></a>
				</section>

				<section class="wsm-panel">
					<h2><?php esc_html_e( 'Import', 'wp-site-migrator' ); ?></h2>
					<p class="wsm-danger-text"><?php esc_html_e( 'Import permanently replaces matching destination database tables and site files. No destination backup is kept.', 'wp-site-migrator' ); ?></p>
					<input type="file" id="wsm-import-file" accept=".zip,application/zip" />
					<div class="wsm-import-actions">
						<button type="button" class="button" id="wsm-upload-import"><?php esc_html_e( 'Upload and Validate', 'wp-site-migrator' ); ?></button>
					</div>
					<label for="wsm-target-url"><?php esc_html_e( 'Destination URL', 'wp-site-migrator' ); ?></label>
					<input type="url" id="wsm-target-url" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>" />
					<label for="wsm-confirmation"><?php esc_html_e( 'Confirmation', 'wp-site-migrator' ); ?></label>
					<input type="text" id="wsm-confirmation" class="regular-text" placeholder="<?php esc_attr_e( 'Type REPLACE SITE', 'wp-site-migrator' ); ?>" />
					<button type="button" class="button button-primary button-danger" id="wsm-start-import" disabled><?php esc_html_e( 'Replace This Site', 'wp-site-migrator' ); ?></button>
				</section>
			</div>

			<section class="wsm-panel wsm-status-panel">
				<h2><?php esc_html_e( 'System Status', 'wp-site-migrator' ); ?></h2>
				<div id="wsm-preflight" class="wsm-checks"></div>
			</section>

			<section class="wsm-panel wsm-status-panel">
				<h2><?php esc_html_e( 'Job Status', 'wp-site-migrator' ); ?></h2>
				<div id="wsm-job-summary" class="wsm-job-summary"><?php esc_html_e( 'No migration job is running.', 'wp-site-migrator' ); ?></div>
				<pre id="wsm-log" class="wsm-log"></pre>
			</section>
		</div>
		<?php
	}
}

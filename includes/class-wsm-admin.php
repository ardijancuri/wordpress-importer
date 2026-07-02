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
			<div class="wsm-page-header">
				<div>
					<h1><?php esc_html_e( 'WP Site Migrator', 'wp-site-migrator' ); ?></h1>
					<p><?php esc_html_e( 'Move a single WordPress site with its database, media, themes, and plugins.', 'wp-site-migrator' ); ?></p>
				</div>
				<span class="wsm-version"><?php echo esc_html( WSM_VERSION ); ?></span>
			</div>

			<div class="wsm-bento">
				<section class="postbox wsm-panel wsm-export-panel wsm-bento-export">
					<div class="postbox-header wsm-panel-heading">
						<div>
							<h2 class="hndle"><?php esc_html_e( 'Export', 'wp-site-migrator' ); ?></h2>
							<p><?php esc_html_e( 'Create a portable package from this site.', 'wp-site-migrator' ); ?></p>
						</div>
						<span class="wsm-step">01</span>
					</div>
					<div class="wsm-action-row">
						<button type="button" class="button button-primary" id="wsm-start-export"><?php esc_html_e( 'Create Export Package', 'wp-site-migrator' ); ?></button>
						<div class="wsm-downloads wsm-hidden" id="wsm-download-export"></div>
					</div>
				</section>

				<section class="postbox wsm-panel wsm-import-panel wsm-bento-import">
					<div class="postbox-header wsm-panel-heading">
						<div>
							<h2 class="hndle"><?php esc_html_e( 'Import', 'wp-site-migrator' ); ?></h2>
							<p><?php esc_html_e( 'Upload a package and replace this site.', 'wp-site-migrator' ); ?></p>
						</div>
						<span class="wsm-step">02</span>
					</div>
					<p class="wsm-danger-text"><?php esc_html_e( 'This permanently replaces destination data and files.', 'wp-site-migrator' ); ?></p>
					<div class="wsm-file-row">
						<input type="file" id="wsm-import-file" accept=".zip,.json,application/zip,application/json" multiple />
						<button type="button" class="button" id="wsm-upload-import"><?php esc_html_e( 'Upload and Validate', 'wp-site-migrator' ); ?></button>
					</div>
					<div class="wsm-import-grid">
						<div class="wsm-field">
							<label for="wsm-confirmation"><?php esc_html_e( 'Confirmation', 'wp-site-migrator' ); ?></label>
							<input type="text" id="wsm-confirmation" class="regular-text" placeholder="<?php esc_attr_e( 'Type REPLACE SITE', 'wp-site-migrator' ); ?>" />
						</div>
						<button type="button" class="button button-primary button-danger" id="wsm-start-import" disabled><?php esc_html_e( 'Replace This Site', 'wp-site-migrator' ); ?></button>
					</div>
				</section>

				<section class="postbox wsm-panel wsm-status-panel wsm-bento-system">
					<div class="postbox-header wsm-panel-heading">
						<div>
							<h2 class="hndle"><?php esc_html_e( 'System Status', 'wp-site-migrator' ); ?></h2>
							<p><?php esc_html_e( 'Host checks for export and import jobs.', 'wp-site-migrator' ); ?></p>
						</div>
					</div>
					<div id="wsm-preflight" class="wsm-checks"></div>
				</section>

				<section class="postbox wsm-panel wsm-status-panel wsm-bento-job">
					<div class="postbox-header wsm-panel-heading">
						<div>
							<h2 class="hndle"><?php esc_html_e( 'Job Status', 'wp-site-migrator' ); ?></h2>
							<p><?php esc_html_e( 'Current package, upload, and import activity.', 'wp-site-migrator' ); ?></p>
						</div>
					</div>
					<div class="wsm-progress-shell">
						<div class="wsm-progress-head">
							<span id="wsm-progress-label"><?php esc_html_e( 'Ready', 'wp-site-migrator' ); ?></span>
							<span id="wsm-progress-value">0%</span>
						</div>
						<div class="wsm-progress-track" id="wsm-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div class="wsm-progress-fill" id="wsm-progress-fill"></div>
						</div>
					</div>
					<div id="wsm-job-summary" class="wsm-job-summary"><?php esc_html_e( 'No migration job is running.', 'wp-site-migrator' ); ?></div>
					<pre id="wsm-log" class="wsm-log"></pre>
				</section>
			</div>
		</div>
		<?php
	}
}

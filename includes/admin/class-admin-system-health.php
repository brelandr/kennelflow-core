<?php
/**
 * Admin: System Health dashboard (diagnostics + DB upgrade).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminSystemHealth
 */
class AdminSystemHealth {

	const PAGE_SLUG = 'kf-health';

	const NONCE_DB_UPGRADE = 'ltkf_health_db_upgrade';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_db_upgrade' ) );
	}

	/**
	 * Capability for viewing and running upgrades.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_system_health_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow Hub.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'System Health', 'kennelflow-core' ),
			__( 'System Health', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * URL of this admin screen (no query args).
	 *
	 * @return string
	 */
	public static function screen_url() {
		return admin_url(
			'admin.php?page=' . rawurlencode( self::PAGE_SLUG )
		);
	}

	/**
	 * On admin_init: run dbDelta stack when requested with valid nonce + cap.
	 *
	 * @return void
	 */
	public static function maybe_run_db_upgrade() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || 'run_db_upgrade' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to run database upgrades.', 'kennelflow-core' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_DB_UPGRADE ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'kennelflow-core' ) );
		}

		self::run_database_upgrades();

		wp_safe_redirect(
			add_query_arg(
				'ltkf_health_db',
				'upgraded',
				self::screen_url()
			)
		);
		exit;
	}

	/**
	 * Run dbDelta / install routines for Hub and active KennelFlow add-ons.
	 *
	 * @return void
	 */
	protected static function run_database_upgrades() {
		require_once LTKF_PLUGIN_DIR . 'includes/class-core-db.php';
		CoreDb::install();

		if ( class_exists( 'WaitlistDb' ) ) {
			WaitlistDb::install();
		}

		if ( class_exists( 'KennelFlow_Boarding_Install' ) ) {
			KennelFlow_Boarding_Install::install();
		}

		if ( class_exists( 'KennelFlow_Vet_Install' ) ) {
			KennelFlow_Vet_Install::install();
		}

		if ( class_exists( 'GroomPress_Install' ) ) {
			GroomPress_Install::install();
		}

		if ( class_exists( 'LTKF_Facility_IoT_Install' ) ) {
			LTKF_Facility_IoT_Install::install();
		}

		/**
		 * Fires after System Health runs bundled DB installers (extend for custom plugins).
		 *
		 * @since 0.2.0
		 */
		do_action( 'ltkf_system_health_run_db_upgrade' );
	}

	/**
	 * Add nonce to our own DB upgrade URL; leave external URLs unchanged.
	 *
	 * @param string $url Raw action URL.
	 * @return string
	 */
	protected static function prepare_action_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['query'] ) ) {
			return esc_url_raw( $url );
		}

		parse_str( (string) $parsed['query'], $q );
		if ( isset( $q['page'] ) && self::PAGE_SLUG === $q['page'] && isset( $q['action'] ) && 'run_db_upgrade' === $q['action'] ) {
			return wp_nonce_url( $url, self::NONCE_DB_UPGRADE );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Button label for an action URL.
	 *
	 * @param string $url Action URL.
	 * @return string
	 */
	protected static function action_button_label( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return __( 'Open', 'kennelflow-core' );
		}

		if ( false !== strpos( $url, 'run_db_upgrade' ) || false !== strpos( $url, self::PAGE_SLUG ) ) {
			return __( 'Run database upgrade', 'kennelflow-core' );
		}

		if ( false !== strpos( $url, 'plugin-install.php' ) ) {
			return __( 'Install plugin', 'kennelflow-core' );
		}

		if ( false !== strpos( $url, 'wc-settings' ) ) {
			return __( 'Open WooCommerce settings', 'kennelflow-core' );
		}

		return __( 'Take action', 'kennelflow-core' );
	}

	/**
	 * Dashicon class for status.
	 *
	 * @param string $status ok|warning|error.
	 * @return string
	 */
	protected static function status_dashicon( $status ) {
		if ( 'ok' === $status ) {
			return 'dashicons-yes';
		}
		return 'dashicons-warning';
	}

	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kennelflow-core' ) );
		}

		$rows = SystemHealthEngine::get_diagnostics();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success flag.
		if ( isset( $_GET['ltkf_health_db'] ) && 'upgraded' === sanitize_text_field( wp_unslash( $_GET['ltkf_health_db'] ) ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Database tables were checked and upgraded where needed.', 'kennelflow-core' )
			);
		}

		?>
		<div class="wrap kf-system-health-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Quick checks for WooCommerce, custom tables, CRM scheduling, and POS configuration.', 'kennelflow-core' ); ?>
			</p>

			<table class="widefat striped kf-system-health-table" style="max-width:960px;margin-top:12px;">
				<thead>
					<tr>
						<th scope="col" class="column-kf-health-status" style="width:56px;"><?php esc_html_e( 'Status', 'kennelflow-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Check', 'kennelflow-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'kennelflow-core' ); ?></th>
						<th scope="col" style="width:200px;"><?php esc_html_e( 'Action', 'kennelflow-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$name    = isset( $row['name'] ) ? (string) $row['name'] : '';
						$status  = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'warning';
						$message = isset( $row['message'] ) ? (string) $row['message'] : '';
						$action  = isset( $row['action_url'] ) ? (string) $row['action_url'] : '';
						$icon    = self::status_dashicon( $status );
						?>
						<tr>
							<td>
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:24px;width:24px;height:24px;" aria-hidden="true"></span>
								<span class="screen-reader-text">
									<?php
									if ( 'ok' === $status ) {
										esc_html_e( 'OK', 'kennelflow-core' );
									} elseif ( 'error' === $status ) {
										esc_html_e( 'Error', 'kennelflow-core' );
									} else {
										esc_html_e( 'Warning', 'kennelflow-core' );
									}
									?>
								</span>
							</td>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td><?php echo esc_html( $message ); ?></td>
							<td>
								<?php if ( '' !== $action ) : ?>
									<?php
									$href = self::prepare_action_url( $action );
									$btn  = ( 'error' === $status ) ? 'button-primary' : 'button-secondary';
									?>
									<a class="button <?php echo esc_attr( $btn ); ?>" href="<?php echo esc_url( $href ); ?>">
										<?php echo esc_html( self::action_button_label( $action ) ); ?>
									</a>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

<?php
/**
 * Admin: Demo Data Sandbox — generate / wipe tagged test data (managers only).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminDemoData
 */
class AdminDemoData {

	const PAGE_SLUG = 'kf-demo-data-sandbox';

	const AJAX_START = 'ltkf_start_demo_generator';

	const AJAX_NUKE = 'ltkf_nuke_demo_data';

	const NONCE_ACTION = 'ltkf_demo_data_sandbox';

	/**
	 * Scale option values (POST `scale`).
	 */
	const SCALE_SMALL = 'small';

	const SCALE_ENTERPRISE = 'enterprise';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 19 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_START, array( __CLASS__, 'ajax_start_demo_generator' ) );
		add_action( 'wp_ajax_' . self::AJAX_NUKE, array( __CLASS__, 'ajax_nuke_demo_data' ) );
	}

	/**
	 * Strict capability: administrators only.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return 'manage_options';
	}

	/**
	 * Submenu under Pets (KennelFlow hub).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Demo Data Sandbox', 'kennelflow-core' ),
			__( 'Demo Data Sandbox', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Scripts for this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$expected_hook = ltkf_get_hub_page_hook_suffix( self::PAGE_SLUG );
		$on_screen     = ( $expected_hook === $hook_suffix );
		// When `kf_hub_menu_slug` points at a non-standard parent, `$hook_suffix` may differ from
		// `{slug}_page_{page}`; still load assets when `?page=` requests this screen.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Passive read-only screen resolution; AJAX mutation uses separate nonces below.
		if ( ! $on_screen && isset( $_GET['page'] ) ) {
			$page = sanitize_text_field( wp_unslash( (string) $_GET['page'] ) );
			if ( self::PAGE_SLUG === $page ) {
				$on_screen = true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $on_screen ) {
			return;
		}

		wp_enqueue_style(
			'kf-admin-demo-data',
			LTKF_PLUGIN_URL . 'assets/css/kf-admin-demo-data.css',
			array(),
			LTKF_CORE_VERSION
		);

		wp_enqueue_script(
			'kf-admin-demo-data',
			LTKF_PLUGIN_URL . 'assets/js/kf-admin-demo-data.js',
			array( 'jquery' ),
			LTKF_CORE_VERSION,
			true
		);

		wp_localize_script(
			'kf-admin-demo-data',
			'kfDemoData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'actionStart'     => self::AJAX_START,
				'actionNuke'      => self::AJAX_NUKE,
				'scaleSmall'      => self::SCALE_SMALL,
				'scaleEnterprise' => self::SCALE_ENTERPRISE,
				'i18n'            => array(
					'working'     => __( 'Working…', 'kennelflow-core' ),
					'error'       => __( 'Request failed.', 'kennelflow-core' ),
					'confirmNuke' => __( 'Are you sure? This will delete all data tagged as demo.', 'kennelflow-core' ),
				),
			)
		);
	}

	/**
	 * Render control panel.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'kennelflow-core' ) );
		}

		$scales = self::get_scale_choices();
		?>
		<div class="wrap kf-demo-data-wrap">
			<h1><?php esc_html_e( 'Demo Data Sandbox', 'kennelflow-core' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Generate or remove KennelFlow demo data. For staging and evaluation only.', 'kennelflow-core' ); ?>
			</p>

			<div class="kf-demo-data-section kf-demo-data-section--generate">
				<h2><?php esc_html_e( 'Generate Demo Data', 'kennelflow-core' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose a scale, then start the generator. Data will be tagged as demo for safe removal.', 'kennelflow-core' ); ?>
				</p>
				<p>
					<label for="kf-demo-data-scale" class="screen-reader-text"><?php esc_html_e( 'Scale', 'kennelflow-core' ); ?></label>
					<select id="kf-demo-data-scale" name="ltkf_demo_scale" class="kf-demo-data-scale">
						<?php foreach ( $scales as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<button type="button" class="button button-primary button-hero" id="kf-demo-data-start">
						<?php esc_html_e( 'Start Generator', 'kennelflow-core' ); ?>
					</button>
				</p>
				<p class="kf-demo-data-status" id="kf-demo-data-start-status" role="status" aria-live="polite" hidden></p>
			</div>

			<div class="kf-demo-data-section kf-demo-data-section--nuke">
				<h2><?php esc_html_e( 'Nuke Demo Data', 'kennelflow-core' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Permanently removes all records created by the demo generator (demo-tagged data only).', 'kennelflow-core' ); ?>
				</p>
				<p>
					<button type="button" class="button button-hero kf-demo-data-button-danger" id="kf-demo-data-nuke">
						<?php esc_html_e( 'Wipe All Demo Data', 'kennelflow-core' ); ?>
					</button>
				</p>
				<p class="kf-demo-data-status" id="kf-demo-data-nuke-status" role="status" aria-live="polite" hidden></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Dropdown labels keyed by scale value.
	 *
	 * @return array<string, string>
	 */
	protected static function get_scale_choices() {
		return array(
			self::SCALE_SMALL      => __( 'Small Facility: 100 Pets', 'kennelflow-core' ),
			self::SCALE_ENTERPRISE => __( 'Enterprise: 1,000 Pets', 'kennelflow-core' ),
		);
	}

	/**
	 * AJAX: start demo generator (stub — implementation in a later step).
	 *
	 * @return void
	 */
	public static function ajax_start_demo_generator() {
		$nonce_value = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( '' === $nonce_value || ! wp_verify_nonce( $nonce_value, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ),
				403
			);
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run this action.', 'kennelflow-core' ) ),
				403
			);
		}

		$scale   = isset( $_POST['scale'] ) ? sanitize_text_field( wp_unslash( $_POST['scale'] ) ) : '';
		$choices = self::get_scale_choices();
		if ( ! array_key_exists( $scale, $choices ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid scale.', 'kennelflow-core' ) ),
				400
			);
		}

		/**
		 * Fires when demo data generation is requested (after capability and nonce checks).
		 *
		 * @since 0.2.0
		 *
		 * @param string $scale One of {@see AdminDemoData::SCALE_SMALL} or SCALE_ENTERPRISE.
		 */
		do_action( 'ltkf_demo_data_start_generator', $scale );

		if ( ! class_exists( 'DemoDataSeeder' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Demo data seeder is not available.', 'kennelflow-core' ) ),
				500
			);
		}

		$queued = DemoDataSeeder::queue_for_scale( $scale );
		if ( is_wp_error( $queued ) ) {
			wp_send_json_error(
				array( 'message' => $queued->get_error_message() ),
				400
			);
		}

		$batches = ( self::SCALE_ENTERPRISE === $scale ) ? 100 : 10;

		wp_send_json_success(
			array(
				/* translators: %d: number of background jobs */
				'message' => sprintf( _n( 'Queued %d background batch.', 'Queued %d background batches.', $batches, 'kennelflow-core' ), $batches ),
				'scale'   => $scale,
				'batches' => $batches,
			)
		);
	}

	/**
	 * AJAX: wipe all demo-tagged data (stub — implementation in a later step).
	 *
	 * @return void
	 */
	public static function ajax_nuke_demo_data() {
		$nonce_value = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( '' === $nonce_value || ! wp_verify_nonce( $nonce_value, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ),
				403
			);
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run this action.', 'kennelflow-core' ) ),
				403
			);
		}

		/**
		 * Fires when demo data wipe is requested (after capability and nonce checks).
		 *
		 * @since 0.2.0
		 */
		do_action( 'ltkf_demo_data_wipe_requested' );

		if ( ! class_exists( 'DemoDataSeeder' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Demo data seeder is not available.', 'kennelflow-core' ) ),
				500
			);
		}

		$counts = DemoDataSeeder::nuke_demo_data();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: bookings, 2: medical rows, 3: commission rows, 4: pets, 5: users */
					__( 'Demo data removed: %1$d bookings, %2$d medical records, %3$d commission rows, %4$d pets, %5$d users.', 'kennelflow-core' ),
					isset( $counts['bookings'] ) ? (int) $counts['bookings'] : 0,
					isset( $counts['medical'] ) ? (int) $counts['medical'] : 0,
					isset( $counts['commissions'] ) ? (int) $counts['commissions'] : 0,
					isset( $counts['pets'] ) ? (int) $counts['pets'] : 0,
					isset( $counts['users'] ) ? (int) $counts['users'] : 0
				),
				'counts'  => $counts,
			)
		);
	}
}

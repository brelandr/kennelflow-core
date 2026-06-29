<?php
/**
 * KennelFlow Hub: top-level admin menu (core settings home).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminHub
 */
class AdminHub {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 5 );
	}

	/**
	 * Capability required to see the KennelFlow top-level menu and Hub home.
	 *
	 * @return string
	 */
	public static function required_cap() {
		/**
		 * Minimum capability for the KennelFlow Hub admin menu (default: staff who can edit content).
		 *
		 * @since 0.3.1
		 *
		 * @param string $cap Capability slug.
		 */
		return (string) apply_filters( 'ltkf_hub_menu_capability', 'edit_posts' );
	}

	/**
	 * Register top-level Hub before CPT submenus attach.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'KennelFlow Hub', 'kennelflow-core' ),
			__( 'KennelFlow', 'kennelflow-core' ),
			self::required_cap(),
			ltkf_get_hub_menu_slug(),
			array( __CLASS__, 'render_page' ),
			'dashicons-pets',
			54
		);
	}

	/**
	 * Hub landing screen (overview; submenus hold feature UIs).
	 *
	 * @return void
	 */
	public static function render_page() {
		$hub_cap = self::required_cap();
		if ( ! current_user_can( $hub_cap ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kennelflow-core' ) );
		}

		$pet_pt = function_exists( 'ltkf_get_pet_post_type' ) ? ltkf_get_pet_post_type() : 'kf_pet';
		$loc_pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';

		$sections = self::get_dashboard_sections( $pet_pt, $loc_pt );
		if ( ! current_user_can( 'manage_options' ) ) {
			$sections = self::filter_staff_dashboard_sections( $sections );
		}
		?>
		<div class="wrap kf-hub-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description">
				<?php
				if ( current_user_can( 'manage_options' ) ) {
					esc_html_e( 'Quick links to Hub tools. The same screens are available from the KennelFlow menu in the left sidebar.', 'kennelflow-core' );
				} else {
					esc_html_e( 'Quick links for daily front-desk work — pets, bookings, and calendars.', 'kennelflow-core' );
				}
				?>
			</p>

			<style>
				.kf-hub-wrap .kf-hub-grid { display: grid; grid-template-columns: repeat( auto-fit, minmax( 280px, 1fr ) ); gap: 16px; margin-top: 16px; max-width: 1200px; }
				.kf-hub-wrap .kf-hub-card { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 0; padding: 0; }
				.kf-hub-wrap .kf-hub-card h2 { font-size: 14px; margin: 0; padding: 12px 16px; border-bottom: 1px solid #c3c4c7; background: #f6f7f7; }
				.kf-hub-wrap .kf-hub-card ul { margin: 0; padding: 8px 0; list-style: none; }
				.kf-hub-wrap .kf-hub-card li { margin: 0; }
				.kf-hub-wrap .kf-hub-card a { display: block; padding: 10px 16px; text-decoration: none; color: #1d2327; }
				.kf-hub-wrap .kf-hub-card a:hover, .kf-hub-wrap .kf-hub-card a:focus { background: #f0f0f1; color: #2271b1; box-shadow: none; }
				.kf-hub-wrap .kf-hub-card a .dashicons { width: 20px; height: 20px; font-size: 20px; line-height: 1; margin-right: 8px; vertical-align: text-bottom; color: #646970; }
			</style>

			<div class="kf-hub-grid" role="navigation" aria-label="<?php echo esc_attr__( 'KennelFlow quick links', 'kennelflow-core' ); ?>">
				<?php
				foreach ( $sections as $section ) {
					if ( empty( $section['title'] ) || empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
						continue;
					}
					?>
					<div class="postbox kf-hub-card">
						<h2><?php echo esc_html( $section['title'] ); ?></h2>
						<ul>
							<?php
							foreach ( $section['items'] as $item ) {
								if ( empty( $item['url'] ) || empty( $item['label'] ) ) {
									continue;
								}
								$icon = isset( $item['icon'] ) && is_string( $item['icon'] ) ? $item['icon'] : 'arrow-right-alt2';
								?>
								<li>
									<a href="<?php echo esc_url( $item['url'] ); ?>">
										<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( $item['label'] ); ?>
									</a>
								</li>
							<?php } ?>
						</ul>
					</div>
					<?php
				}
				?>
			</div>

			<?php
			/**
			 * After Hub dashboard content (e.g. add-on status, notices).
			 *
			 * @since 0.2.0
			 */
			do_action( 'ltkf_hub_dashboard_after' );
			?>
		</div>
		<?php
	}

	/**
	 * Dashboard link sections for the Hub home screen.
	 *
	 * @param string $pet_pt Pet post type slug.
	 * @param string $loc_pt Location post type slug.
	 * @return array<int, array{title: string, items: array<int, array{url: string, label: string, icon?: string}>}>
	 */
	private static function get_dashboard_sections( $pet_pt, $loc_pt ) {
		$sections = array(
			array(
				'title' => __( 'Daily work', 'kennelflow-core' ),
				'items' => array(
					array(
						'url'   => add_query_arg( 'post_type', $pet_pt, admin_url( 'edit.php' ) ),
						'label' => __( 'Pets', 'kennelflow-core' ),
						'icon'  => 'admin-users',
					),
					array(
						'url'   => add_query_arg( 'post_type', $loc_pt, admin_url( 'edit.php' ) ),
						'label' => __( 'Locations', 'kennelflow-core' ),
						'icon'  => 'location',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-calendar' ),
						'label' => __( 'Calendar', 'kennelflow-core' ),
						'icon'  => 'calendar-alt',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-staff-permissions' ),
						'label' => __( 'Staff & permissions', 'kennelflow-core' ),
						'icon'  => 'admin-users',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-pending-records' ),
						'label' => __( 'Pending records', 'kennelflow-core' ),
						'icon'  => 'media-document',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-daily-reports' ),
						'label' => __( 'Daily reports', 'kennelflow-core' ),
						'icon'  => 'clipboard',
					),
				),
			),
			array(
				'title' => __( 'Configuration', 'kennelflow-core' ),
				'items' => array(
					array(
						'url'   => admin_url( 'admin.php?page=kf-kennelflow-settings' ),
						'label' => __( 'KennelFlow settings', 'kennelflow-core' ),
						'icon'  => 'admin-settings',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-revenue' ),
						'label' => __( 'Revenue', 'kennelflow-core' ),
						'icon'  => 'tickets',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-webhooks-api' ),
						'label' => __( 'Webhooks & API', 'kennelflow-core' ),
						'icon'  => 'share',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-data-vault' ),
						'label' => __( 'Data vault & archive', 'kennelflow-core' ),
						'icon'  => 'lock',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-client-migration' ),
						'label' => __( 'Client migration', 'kennelflow-core' ),
						'icon'  => 'update',
					),
				),
			),
			array(
				'title' => __( 'Safety & quality', 'kennelflow-core' ),
				'items' => array(
					array(
						'url'   => admin_url( 'admin.php?page=kf-compliance' ),
						'label' => __( 'Compliance (pets)', 'kennelflow-core' ),
						'icon'  => 'yes-alt',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-compliance-rules' ),
						'label' => __( 'Compliance rules', 'kennelflow-core' ),
						'icon'  => 'list-view',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-health' ),
						'label' => __( 'System health', 'kennelflow-core' ),
						'icon'  => 'heart',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-documentation' ),
						'label' => __( 'Documentation', 'kennelflow-core' ),
						'icon'  => 'book',
					),
					array(
						'url'   => admin_url( 'admin.php?page=kf-demo-data-sandbox' ),
						'label' => __( 'Demo & sandbox', 'kennelflow-core' ),
						'icon'  => 'admin-tools',
					),
				),
			),
		);

		$add_on = array(
			'title' => __( 'Add-ons', 'kennelflow-core' ),
			'items' => array(),
		);
		if ( class_exists( 'KennelFlow_Boarding_Plugin' ) || defined( 'KENNELFLOW_BOARDING_VERSION' ) ) {
			$add_on['items'][] = array(
				'url'   => admin_url( 'admin.php?page=kennelflow-boarding-facility-settings' ),
				'label' => __( 'Kennel rules (KennelFlow Boarding)', 'kennelflow-core' ),
				'icon'  => 'admin-generic',
			);
			$add_on['items'][] = array(
				'url'   => admin_url( 'admin.php?page=kennelflow-boarding-calendar' ),
				'label' => __( 'Kennel calendar (KennelFlow Boarding)', 'kennelflow-core' ),
				'icon'  => 'schedule',
			);
		}
		if ( class_exists( 'KennelFlow_Groom_Plugin' ) || defined( 'KENNELFLOW_GROOM_VERSION' ) ) {
			$add_on['items'][] = array(
				'url'   => admin_url( 'admin.php?page=groompress-schedule' ),
				'label' => __( 'Grooming schedule (KennelFlow Groom)', 'kennelflow-core' ),
				'icon'  => 'calendar-alt',
			);
			$add_on['items'][] = array(
				'url'   => admin_url( 'admin.php?page=groompress-settings' ),
				'label' => __( 'Grooming settings (KennelFlow Groom)', 'kennelflow-core' ),
				'icon'  => 'admin-settings',
			);
		}
		if ( ! empty( $add_on['items'] ) ) {
			$sections[] = $add_on;
		}

		/**
		 * Hub dashboard link sections (title + list of url/label/icon).
		 *
		 * @since 0.2.0
		 *
		 * @param array   $sections Sections.
		 * @param string  $pet_pt  Pet post type.
		 * @param string  $loc_pt  Location post type.
		 */
		return apply_filters( 'ltkf_hub_dashboard_sections', $sections, $pet_pt, $loc_pt );
	}

	/**
	 * Hide admin-only Hub links for front-desk staff (no manage_options).
	 *
	 * @param array<int, array{title: string, items: array<int, array{url: string, label: string, icon?: string}>}> $sections Sections.
	 * @return array<int, array{title: string, items: array<int, array{url: string, label: string, icon?: string}>}>
	 */
	private static function filter_staff_dashboard_sections( $sections ) {
		$admin_only_paths = array(
			'kf-staff-permissions',
			'kf-pending-records',
			'kf-daily-reports',
			'kf-kennelflow-settings',
			'kf-revenue',
			'kf-webhooks-api',
			'kf-data-vault',
			'kf-client-migration',
			'kf-compliance',
			'kf-compliance-rules',
			'kf-health',
			'kf-documentation',
			'kf-demo-data-sandbox',
		);

		$out = array();
		foreach ( (array) $sections as $section ) {
			if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $section['items'] as $item ) {
				if ( empty( $item['url'] ) ) {
					continue;
				}
				$skip = false;
				foreach ( $admin_only_paths as $path ) {
					if ( false !== strpos( (string) $item['url'], $path ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}
				$items[] = $item;
			}
			if ( empty( $items ) ) {
				continue;
			}
			$section['items'] = $items;
			$out[]            = $section;
		}

		return $out;
	}
}

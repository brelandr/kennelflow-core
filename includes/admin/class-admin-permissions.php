<?php
/**
 * Admin: Staff Permissions matrix (React).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPermissions
 */
class AdminPermissions {

	const PAGE_SLUG = 'kf-staff-permissions';

	/**
	 * Distinct handle so other code cannot replace this script or localization.
	 */
	const SCRIPT_HANDLE = 'kf-hub-staff-permissions';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_scripts' ) );
	}

	/**
	 * Capability for this screen.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_staff_permissions_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Staff Permissions', 'kennelflow-core' ),
			__( 'Staff Permissions', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue compiled bundle on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook (preferred; matches `admin_enqueue_scripts` $hook).
	 * @return void
	 */
	public static function maybe_enqueue_scripts( $hook_suffix = '' ) {
		$pt           = function_exists( 'ltkf_get_pet_post_type' ) ? ltkf_get_pet_post_type() : 'kf_pet';
		$expected_ids = array();
		if ( function_exists( 'ltkf_get_hub_page_hook_suffix' ) ) {
			$expected_ids[] = ltkf_get_hub_page_hook_suffix( self::PAGE_SLUG );
		}
		$expected_ids[] = $pt . '_page_' . self::PAGE_SLUG;
		$expected_ids   = array_unique( array_map( 'strval', $expected_ids ) );

		$current_hook = (string) $hook_suffix;
		$ok           = in_array( $current_hook, $expected_ids, true );
		if ( ! $ok && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && in_array( (string) $screen->id, $expected_ids, true ) ) {
				$ok = true;
			}
		}

		// Failsafes: `admin.php?page=kf-staff-permissions` and loose hook match.
		if ( ! $ok && is_admin() && ! wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page_get = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( self::PAGE_SLUG === $page_get ) {
				$ok = true;
			} elseif ( '' !== (string) $hook_suffix && false !== strpos( (string) $hook_suffix, 'kf-staff-permissions' ) ) {
				$ok = true;
			}
		}

		/**
		 * Whether the current admin view is the Staff Permissions screen (bundle should load).
		 *
		 * @since 0.2.0
		 *
		 * @param bool     $ok            Whether we matched hook / screen.
		 * @param string   $hook_suffix   Hook passed to `admin_enqueue_scripts`.
		 * @param string   $page_slug     This screen’s page slug.
		 * @param string[] $expected_ids  Candidate screen / hook ids.
		 */
		$ok = (bool) apply_filters( 'ltkf_is_admin_staff_permissions_screen', $ok, (string) $hook_suffix, self::PAGE_SLUG, $expected_ids );
		if ( ! $ok ) {
			return;
		}

		$pm_js = LTKF_PLUGIN_DIR . 'build/permissions-matrix.js';
		if ( ! is_readable( $pm_js ) ) {
			$page_slug = self::PAGE_SLUG;
			add_action(
				'admin_notices',
				static function () use ( $page_slug ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== $page_slug ) {
						return;
					}
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
						esc_html__( 'KennelFlow Staff Permissions is missing the compiled bundle. From the kennelflow-core plugin folder, run: npm install && npm run build (then upload the build/ folder: permissions-matrix.js, permissions-matrix.css, permissions-matrix.asset.php).', 'kennelflow-core' )
					);
				}
			);
			return;
		}

		$asset_file = LTKF_PLUGIN_DIR . 'build/permissions-matrix.asset.php';
		$asset      = array(
			'dependencies' => array(),
			'version'      => LTKF_CORE_VERSION,
		);
		if ( is_readable( $asset_file ) ) {
			$loaded = require $asset_file;
			if ( is_array( $loaded ) ) {
				$asset = array_merge( $asset, $loaded );
			}
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			LTKF_PLUGIN_URL . 'build/permissions-matrix.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$localized = array(
			'rest_url' => esc_url_raw( rest_url() ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		);

		/**
		 * Localized data for the permissions matrix script.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, mixed> $localized Settings for JS.
		 */
		$localized = apply_filters( 'ltkf_admin_permissions_matrix_localized', $localized );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'kfPermissionsMatrixSettings',
			$localized
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'kennelflow-core', LTKF_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			LTKF_PLUGIN_URL . 'build/permissions-matrix.css',
			array(),
			$asset['version']
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kennelflow-core' ) );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Toggle KennelFlow-managed capabilities per staff role. Changes are saved to WordPress immediately (requires the manage_options capability).', 'kennelflow-core' ); ?>
			</p>
			<div id="kf-permissions-matrix-root" class="kf-permissions-matrix-root"></div>
		</div>
		<?php
	}
}

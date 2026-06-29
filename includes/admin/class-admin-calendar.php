<?php
/**
 * Admin Calendar screen: React CalendarGrid wrapper (build/index.js).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminCalendar
 */
class AdminCalendar {

	const PAGE_SLUG = 'kf-calendar';

	/**
	 * Script handle for the compiled admin calendar bundle.
	 * Distinct from `kf-calendar` used elsewhere to avoid other plugins reusing the same handle and replacing the source or localization.
	 */
	const SCRIPT_HANDLE = 'kf-hub-admin-calendar';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_scripts' ) );
	}

	/**
	 * Capability for viewing and interacting with the calendar.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_admin_calendar_capability', 'edit_posts' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Calendar', 'kennelflow-core' ),
			__( 'Calendar', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue `build/index.js` / `build/index.css` on the Calendar screen only.
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

		// Failsafes: some environments pass an unexpected $hook_suffix or a late screen; `admin.php?page=kf-calendar` is reliable.
		if ( ! $ok && is_admin() && ! wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page_get = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( self::PAGE_SLUG === $page_get ) {
				$ok = true;
			} elseif ( '' !== (string) $hook_suffix && false !== strpos( (string) $hook_suffix, 'kf-calendar' ) ) {
				$ok = true;
			}
		}

		/**
		 * Whether the current admin view is the Hub Calendar screen (bundle should load).
		 *
		 * @since 0.2.0
		 *
		 * @param bool     $ok            Whether we matched hook / screen.
		 * @param string   $hook_suffix   Hook passed to `admin_enqueue_scripts`.
		 * @param string   $page_slug     This screen’s page slug.
		 * @param string[] $expected_ids  Candidate screen / hook ids.
		 */
		$ok = (bool) apply_filters( 'ltkf_is_admin_hub_calendar_screen', $ok, (string) $hook_suffix, self::PAGE_SLUG, $expected_ids );
		if ( ! $ok ) {
			return;
		}

		$index_js = LTKF_PLUGIN_DIR . 'build/index.js';
		if ( ! is_readable( $index_js ) ) {
			if ( is_admin() && function_exists( 'add_action' ) ) {
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
							esc_html__( 'KennelFlow Hub calendar is missing the compiled bundle. From the kennelflow-core plugin folder, run: npm install && npm run build (then upload the build/ folder: index.js, index.css, index.asset.php).', 'kennelflow-core' )
						);
					}
				);
			}
			return;
		}

		ltkf_enqueue_hub_calendar_bundle( self::SCRIPT_HANDLE );
	}

	/**
	 * Current UTC week (Mon–Sun) as Y-m-d for the React shell.
	 *
	 * @return array{0:string,1:string} start_date, end_date
	 */
	protected static function current_week_range_utc() {
		$utc = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$day_of_week = (int) $utc->format( 'N' );
		$week_start  = $utc->modify( '-' . ( $day_of_week - 1 ) . ' days' )->setTime( 0, 0, 0 );
		$week_end    = $week_start->modify( '+6 days' );

		return array( $week_start->format( 'Y-m-d' ), $week_end->format( 'Y-m-d' ) );
	}

	/**
	 * UTC week range for the front-end `[ltkf_hub_calendar]` shortcode shell.
	 *
	 * @return array{0:string,1:string} start_date, end_date
	 */
	public static function get_shell_week_range_utc() {
		return self::current_week_range_utc();
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! ltkf_user_can_view_hub_calendar() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kennelflow-core' ) );
		}

		list( $start_date, $end_date ) = self::current_week_range_utc();

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description"><?php
			printf(
				/* translators: %s: site timezone string from Settings → General */
				esc_html__( 'Drag bookings to reschedule or move between resources. Times display in %s.', 'kennelflow-core' ),
				esc_html( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' )
			);
			?></p>
			<?php
			if ( ! ltkf_hub_calendar_bundle_readable() ) {
				printf(
					'<div class="notice notice-error inline"><p>%s</p></div>',
					esc_html__( 'Calendar JavaScript is missing. Run npm install && npm run build in the KennelFlow Core plugin folder, then redeploy the build/ directory.', 'kennelflow-core' )
				);
			} elseif ( ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
				printf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'Calendar scripts did not load on this screen. Try reloading the page; if the grid stays empty, check the browser console for JavaScript errors.', 'kennelflow-core' )
				);
			}

			$booking_diag = ltkf_get_calendar_add_booking_diagnostics( ltkf_get_calendar_localized_settings() );
			if ( is_array( $booking_diag ) && empty( $booking_diag['ready'] ) && ! empty( $booking_diag['issues'] ) ) {
				echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Add booking requires KennelFlow Boarding', 'kennelflow-core' ) . '</strong></p><ul style="margin-left:1.2em;list-style:disc;">';
				foreach ( (array) $booking_diag['issues'] as $issue ) {
					echo '<li>' . esc_html( (string) $issue ) . '</li>';
				}
				echo '</ul></div>';
			}

			echo ltkf_get_hub_calendar_shell_markup(
				array(
					'id'         => 'kf-admin-calendar-root',
					'class'      => 'kf-admin-calendar-root',
					'start_date' => $start_date,
					'end_date'   => $end_date,
				)
			);
			?>
		</div>
		<?php
	}
}

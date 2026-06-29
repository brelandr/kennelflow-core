<?php
/**
 * Staff-only front-end shortcode embedding the Hub booking calendar (`build/index.js`).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class FrontendHubCalendar
 */
class FrontendHubCalendar {

	const SCRIPT_HANDLE = 'kf-calendar-frontend';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_for_shortcode_page' ), 20 );
	}

	/**
	 * Register `[kf_hub_calendar]` on init.
	 *
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( 'ltkf_hub_calendar', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Capability required to load the calendar UI (default: same as admin Hub calendar).
	 *
	 * @return string
	 */
	protected static function required_cap() {
		$cap = apply_filters( 'ltkf_hub_calendar_shortcode_cap', AdminCalendar::required_cap() );
		return is_string( $cap ) && '' !== $cap ? $cap : 'edit_posts';
	}

	/**
	 * Pre-enqueue on singular pages that contain `[ltkf_hub_calendar]` (before shortcode render).
	 *
	 * @return void
	 */
	public static function maybe_enqueue_for_shortcode_page() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! self::page_has_hub_calendar_shortcode( $post ) ) {
			return;
		}

		if ( ! ltkf_user_can_view_hub_calendar() ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Whether page content includes a Hub calendar shortcode (including spoke aliases).
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	protected static function page_has_hub_calendar_shortcode( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$shortcode_tags = apply_filters(
			'ltkf_hub_calendar_shortcode_tags',
			array( 'ltkf_hub_calendar' )
		);
		if ( ! is_array( $shortcode_tags ) ) {
			$shortcode_tags = array( 'ltkf_hub_calendar' );
		}

		foreach ( $shortcode_tags as $tag ) {
			$tag = sanitize_key( (string) $tag );
			if ( '' !== $tag && has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue Hub calendar bundle when shortcode renders.
	 *
	 * @return void
	 */
	protected static function enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		ltkf_enqueue_hub_calendar_bundle( self::SCRIPT_HANDLE );
	}

	/**
	 * Shortcode output: Hub calendar root (same id as wp-admin for `build/index.js`).
	 *
	 * @param string[]|string $atts Attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'booking_kind' => '',
				'corner_label' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'ltkf_hub_calendar'
		);

		/**
		 * Shortcode attributes for `[ltkf_hub_calendar]` (e.g. booking_kind=grooming).
		 *
		 * @since 0.3.2
		 *
		 * @param array<string, string> $atts Attributes.
		 */
		$atts = apply_filters( 'ltkf_hub_calendar_shortcode_atts', $atts );

		if ( ! ltkf_user_can_view_hub_calendar() ) {
			if ( is_user_logged_in() ) {
				return '<p class="kf-hub-calendar-denied">' . esc_html__(
					'You do not have permission to view the booking calendar.',
					'kennelflow-core'
				) . '</p>';
			}

			return '<p class="kf-hub-calendar-login">' . esc_html__(
				'Log in with a staff account to view the booking calendar.',
				'kennelflow-core'
			) . '</p>';
		}

		if ( ! is_readable( LTKF_PLUGIN_DIR . 'build/index.js' ) ) {
			return '<p class="kf-hub-calendar-missing">' . esc_html__(
				'Calendar assets are missing. From the KennelFlow Core plugin folder run: npm install && npm run build.',
				'kennelflow-core'
			) . '</p>';
		}

		self::enqueue_assets();

		list( $start_date, $end_date ) = AdminCalendar::get_shell_week_range_utc();

		$booking_kind = sanitize_key( (string) $atts['booking_kind'] );
		$corner_label = sanitize_text_field( (string) $atts['corner_label'] );

		$class = 'kf-admin-calendar-root kf-hub-calendar-root';

		return ltkf_get_hub_calendar_shell_markup(
			array(
				'id'           => 'kf-admin-calendar-root',
				'class'        => $class,
				'start_date'   => $start_date,
				'end_date'     => $end_date,
				'booking_kind' => $booking_kind,
				'corner_label' => $corner_label,
			)
		);
	}
}

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

		$asset_file = LTKF_PLUGIN_DIR . 'build/index.asset.php';
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
			LTKF_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'kfCalendarSettings',
			ltkf_get_calendar_localized_settings()
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'kennelflow-core', LTKF_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			LTKF_PLUGIN_URL . 'build/index.css',
			array(),
			$asset['version']
		);
	}

	/**
	 * Shortcode output: Hub calendar root (same id as wp-admin for `build/index.js`).
	 *
	 * @param string[] $atts Attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		unset( $atts );

		if ( ! current_user_can( self::required_cap() ) ) {
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

		return sprintf(
			'<div id="kf-admin-calendar-root" class="kf-admin-calendar-root kf-hub-calendar-root" data-start-date="%s" data-end-date="%s" aria-live="polite"></div>',
			esc_attr( $start_date ),
			esc_attr( $end_date )
		);
	}
}

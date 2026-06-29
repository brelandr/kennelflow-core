<?php
/**
 * Public boarding booking wizard shortcode `[ltkf_booking]` when KennelFlow Vet does not register one.
 *
 * Assets ship with KennelFlow Core (`assets/dist/booking-wizard.*`).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class BookingWizardFrontend
 */
class BookingWizardFrontend {

	const SCRIPT_HANDLE = 'kf-booking-wizard';

	/**
	 * Enqueue guard.
	 *
	 * @var bool
	 */
	protected static $enqueued = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 5 );
	}

	/**
	 * Register shortcodes when KennelFlow Vet does not own the wizard.
	 *
	 * @return void
	 */
	public static function register_shortcodes() {
		if ( class_exists( 'KennelFlow_Vet_Frontend' ) ) {
			return;
		}
		if ( shortcode_exists( 'ltkf_booking' ) ) {
			return;
		}

		add_shortcode( 'ltkf_booking', array( __CLASS__, 'render_wizard' ) );
	}

	/**
	 * Multi-step boarding booking wizard (React).
	 *
	 * @param string[] $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_wizard( $atts ) {
		unset( $atts );

		$path_js = LTKF_PLUGIN_DIR . 'assets/dist/booking-wizard.js';
		if ( ! is_readable( $path_js ) ) {
			return '<p class="kennelflow-vet-booking-wizard-missing">' . esc_html__(
				'Booking form assets are missing. From the KennelFlow Core plugin folder run: npm install && npm run build (or install the plugin from a complete release zip).',
				'kennelflow-core'
			) . '</p>';
		}

		self::enqueue_wizard_assets();

		return '<div id="kennelflow-vet-booking-wizard-root" class="kennelflow-vet-booking-wizard-root" aria-live="polite"></div>';
	}

	/**
	 * Scripts and styles for the booking wizard bundle.
	 *
	 * @return void
	 */
	protected static function enqueue_wizard_assets() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$path_js  = LTKF_PLUGIN_DIR . 'assets/dist/booking-wizard.js';
		$path_css = LTKF_PLUGIN_DIR . 'assets/dist/booking-wizard.css';

		if ( is_readable( $path_js ) ) {
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				LTKF_PLUGIN_URL . 'assets/dist/booking-wizard.js',
				array(),
				LTKF_CORE_VERSION,
				true
			);
			wp_localize_script(
				self::SCRIPT_HANDLE,
				'kennelflowVetBookingWizard',
				array(
					'restUrl'                      => esc_url_raw( rest_url( 'kennelflow-vet/v1/' ) ),
					'kennelflowRestUrl'            => esc_url_raw( rest_url( 'kennelflow/v1/' ) ),
					'nonce'                        => wp_create_nonce( 'wp_rest' ),
					'isLoggedIn'                   => is_user_logged_in(),
					'loginUrl'                     => esc_url_raw( wp_login_url( get_permalink() ) ),
					'allowOwnerOnlineBoarding'     => function_exists( 'ltkf_is_owner_online_boarding_enabled' ) && ltkf_is_owner_online_boarding_enabled(),
					'complianceUploadUrl'          => esc_url_raw( rest_url( 'kennelflow/v1/compliance/upload' ) ),
					'complianceUploadAvailable'    => true,
					'i18n'                         => array(
						'needLogin'       => __( 'Log in with your pet owner account to request a boarding stay.', 'kennelflow-core' ),
						'createAccount'   => __( 'Create an account', 'kennelflow-core' ),
						'registerIntro'   => __( 'New pet owner? Create an account below. We will send a link to verify your email; then you can log in to book.', 'kennelflow-core' ),
						'registerSuccess' => __( 'Account created. Check your email and click the verification link. After that, use Log in to continue.', 'kennelflow-core' ),
					),
				)
			);
		}

		if ( is_readable( $path_css ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				LTKF_PLUGIN_URL . 'assets/dist/booking-wizard.css',
				array(),
				LTKF_CORE_VERSION
			);
		}
	}
}

<?php
/**
 * Revenue settings (deposit percentage).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminRevenue
 */
class AdminRevenue {

	const PAGE_SLUG = 'kf-revenue';

	const OPTION_DEPOSIT_PERCENTAGE = 'ltkf_deposit_percentage';

	const OPTION_SURGE_ENABLED = 'ltkf_surge_enabled';

	const OPTION_SURGE_THRESHOLD = 'ltkf_surge_threshold';

	const OPTION_SURGE_INCREASE_PERCENTAGE = 'ltkf_surge_increase_percentage';

	const SETTINGS_GROUP = 'ltkf_revenue_settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_revenue_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Revenue', 'kennelflow-core' ),
			__( 'Revenue', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DEPOSIT_PERCENTAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_deposit_percentage' ),
				'default'           => 20,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_SURGE_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_surge_enabled' ),
				'default'           => false,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_SURGE_THRESHOLD,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_surge_threshold' ),
				'default'           => 80,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_SURGE_INCREASE_PERCENTAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_surge_increase_percentage' ),
				'default'           => 20,
			)
		);

		add_settings_section(
			'ltkf_revenue_deposits',
			__( 'Deposits', 'kennelflow-core' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'ltkf_revenue_surge',
			__( 'Surge pricing', 'kennelflow-core' ),
			array( __CLASS__, 'render_section_surge_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ltkf_deposit_percentage_field',
			__( 'Deposit percentage', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_deposit_percentage' ),
			self::PAGE_SLUG,
			'ltkf_revenue_deposits'
		);

		add_settings_field(
			'ltkf_surge_enabled_field',
			__( 'Enable surge pricing', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_surge_enabled' ),
			self::PAGE_SLUG,
			'ltkf_revenue_surge'
		);

		add_settings_field(
			'ltkf_surge_threshold_field',
			__( 'Occupancy threshold', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_surge_threshold' ),
			self::PAGE_SLUG,
			'ltkf_revenue_surge'
		);

		add_settings_field(
			'ltkf_surge_increase_percentage_field',
			__( 'Price increase', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_surge_increase_percentage' ),
			self::PAGE_SLUG,
			'ltkf_revenue_surge'
		);
	}

	/**
	 * Section blurb.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'At checkout, boarding bookings can require a deposit; the rest is stored as an unpaid balance payable later from the owner portal.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Surge section blurb.
	 *
	 * @return void
	 */
	public static function render_section_surge_description() {
		echo '<p>' . esc_html__( 'When occupancy (from confirmed boarding stays vs. total kennel capacity) exceeds the threshold, boarding cart prices can be increased automatically at checkout.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Sanitize 0–100.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public static function sanitize_deposit_percentage( $value ) {
		$n = absint( $value );
		if ( $n > 100 ) {
			$n = 100;
		}
		return $n;
	}

	/**
	 * Checkbox: store boolean.
	 *
	 * @param mixed $value Raw (missing when unchecked).
	 * @return bool
	 */
	public static function sanitize_surge_enabled( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Occupancy percent 0–100.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public static function sanitize_surge_threshold( $value ) {
		$n = absint( $value );
		if ( $n > 100 ) {
			$n = 100;
		}
		return $n;
	}

	/**
	 * Price bump percent (applied as a multiplier on base price).
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public static function sanitize_surge_increase_percentage( $value ) {
		$n = absint( $value );
		if ( $n > 500 ) {
			$n = 500;
		}
		return $n;
	}

	/**
	 * Number field.
	 *
	 * @return void
	 */
	public static function render_field_deposit_percentage() {
		$val = ltkf_get_deposit_percentage();
		printf(
			'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="0" max="100" step="1" class="small-text" /> %%',
			esc_attr( self::OPTION_DEPOSIT_PERCENTAGE ),
			(int) $val
		);
		echo '<p class="description">' . esc_html__( '0 = pay full amount at checkout. 100 = full amount due at checkout (no separate balance). Values between 1 and 99 collect a deposit and leave the remainder as balance due.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Surge on/off.
	 *
	 * @return void
	 */
	public static function render_field_surge_enabled() {
		$on = filter_var( get_option( self::OPTION_SURGE_ENABLED, false ), FILTER_VALIDATE_BOOLEAN );
		printf(
			'<input type="hidden" name="%1$s" value="0" />',
			esc_attr( self::OPTION_SURGE_ENABLED )
		);
		printf(
			'<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_SURGE_ENABLED ),
			checked( $on, true, false ),
			esc_html__( 'Increase boarding prices when occupancy is above the threshold', 'kennelflow-core' )
		);
	}

	/**
	 * Occupancy threshold field.
	 *
	 * @return void
	 */
	public static function render_field_surge_threshold() {
		printf(
			'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="0" max="100" step="1" class="small-text" /> %%',
			esc_attr( self::OPTION_SURGE_THRESHOLD ),
			absint( get_option( self::OPTION_SURGE_THRESHOLD, 80 ) )
		);
		echo '<p class="description">' . esc_html__( 'Surge applies when current occupancy (from the bookings ledger vs. total kennel capacity) is greater than this percentage.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Percent increase over catalog price when surge applies.
	 *
	 * @return void
	 */
	public static function render_field_surge_increase_percentage() {
		printf(
			'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="0" max="500" step="1" class="small-text" /> %%',
			esc_attr( self::OPTION_SURGE_INCREASE_PERCENTAGE ),
			absint( get_option( self::OPTION_SURGE_INCREASE_PERCENTAGE, 20 ) )
		);
		echo '<p class="description">' . esc_html__( 'Boarding line price becomes base price plus this percentage (e.g. 20%% → 1.2× the regular price).', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kennelflow-core' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Revenue', 'kennelflow-core' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper wp-clearfix">';
		echo '<a href="#" class="nav-tab nav-tab-active">' . esc_html__( 'Revenue', 'kennelflow-core' ) . '</a>';
		echo '</h2>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}

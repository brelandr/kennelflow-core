<?php
/**
 * Admin: KennelFlow Settings (hub-wide options).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminSettings
 */
class AdminSettings {

	const PAGE_SLUG = 'kf-kennelflow-settings';

	const SETTINGS_GROUP = 'ltkf_kennelflow_settings';

	const OPTION_ALLOW_OWNER_CLINICIAN = 'ltkf_allow_owner_clinician_selection';

	const OPTION_ALLOW_OWNER_ONLINE_BOARDING = 'ltkf_allow_owner_online_boarding';

	const OPTION_VIP_DISCOUNT_PERCENTAGE = 'ltkf_vip_discount_percentage';

	/**
	 * Twilio SMS (see {@see TwilioService}).
	 */
	const OPTION_TWILIO_ACCOUNT_SID = 'ltkf_twilio_account_sid';

	const OPTION_TWILIO_AUTH_TOKEN = 'ltkf_twilio_auth_token';

	const OPTION_TWILIO_FROM_NUMBER = 'ltkf_twilio_from_number';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Capability for this screen.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_kennelflow_settings_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'KennelFlow Settings', 'kennelflow-core' ),
			__( 'KennelFlow Settings', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register option and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_ALLOW_OWNER_CLINICIAN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
				'default'           => '0',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_ALLOW_OWNER_ONLINE_BOARDING,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
				'default'           => '1',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_VIP_DISCOUNT_PERCENTAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_vip_discount_percentage' ),
				'default'           => 10,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_TWILIO_ACCOUNT_SID,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_twilio_account_sid' ),
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_TWILIO_AUTH_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_twilio_auth_token' ),
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_TWILIO_FROM_NUMBER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_twilio_from_number' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'ltkf_kennelflow_clinician_section',
			__( 'Clinician selection', 'kennelflow-core' ),
			array( __CLASS__, 'render_clinician_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_ALLOW_OWNER_CLINICIAN,
			__( 'Allow owner clinician selection', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_allow_owner_clinician' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_clinician_section'
		);

		add_settings_section(
			'ltkf_kennelflow_boarding_section',
			__( 'Online boarding', 'kennelflow-core' ),
			array( __CLASS__, 'render_boarding_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_ALLOW_OWNER_ONLINE_BOARDING,
			__( 'Allow online boarding booking', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_allow_owner_online_boarding' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_boarding_section'
		);

		add_settings_section(
			'ltkf_kennelflow_vip_section',
			__( 'VIP membership discount', 'kennelflow-core' ),
			array( __CLASS__, 'render_vip_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_VIP_DISCOUNT_PERCENTAGE,
			__( 'VIP discount (%)', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_vip_discount_percentage' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_vip_section'
		);

		add_settings_section(
			'ltkf_kennelflow_twilio_section',
			__( 'Twilio SMS', 'kennelflow-core' ),
			array( __CLASS__, 'render_twilio_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_TWILIO_ACCOUNT_SID,
			__( 'Account SID', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_twilio_account_sid' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_twilio_section'
		);

		add_settings_field(
			self::OPTION_TWILIO_AUTH_TOKEN,
			__( 'Auth token', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_twilio_auth_token' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_twilio_section'
		);

		add_settings_field(
			self::OPTION_TWILIO_FROM_NUMBER,
			__( 'From number', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_twilio_from_number' ),
			self::PAGE_SLUG,
			'ltkf_kennelflow_twilio_section'
		);
	}

	/**
	 * Sanitize stored checkbox as '1' or '0'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_yes_no( $value ) {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return '1';
		}
		return '0';
	}

	/**
	 * Sanitize VIP discount 0–100.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_vip_discount_percentage( $value ) {
		$n = is_numeric( $value ) ? (int) $value : 10;
		if ( $n < 0 ) {
			$n = 0;
		}
		if ( $n > 100 ) {
			$n = 100;
		}
		return $n;
	}

	/**
	 * Sanitize Twilio Account SID.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_twilio_account_sid( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize Twilio auth token; empty submit keeps the previous value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_twilio_auth_token( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			$prev = get_option( self::OPTION_TWILIO_AUTH_TOKEN, '' );
			return is_string( $prev ) ? $prev : '';
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize Twilio caller ID / From number.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_twilio_from_number( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return sanitize_text_field( $value );
	}

	/**
	 * Section blurb.
	 *
	 * @return void
	 */
	public static function render_clinician_section_description() {
		echo '<p>' . esc_html__( 'When enabled, the public REST endpoint lists clinicians so booking UIs can let pet owners choose a veterinarian. When disabled, that endpoint returns an error.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Online boarding section blurb.
	 *
	 * @return void
	 */
	public static function render_boarding_section_description() {
		echo '<p>' . esc_html__( 'When enabled, logged-in pet owners can submit boarding requests through the public booking wizard when their pet meets boarding compliance (signed waiver and required vaccinations) and a kennel is available for the selected dates. Staff can always create bookings from the admin calendar.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * VIP section blurb.
	 *
	 * @return void
	 */
	public static function render_vip_section_description() {
		echo '<p>' . esc_html__( 'When WooCommerce Subscriptions is active, customers with an active subscription receive this percentage off boarding and grooming stays at checkout.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Twilio section blurb.
	 *
	 * @return void
	 */
	public static function render_twilio_section_description() {
		echo '<p>' . esc_html__( 'Optional. Store your Twilio REST credentials to send SMS from KennelFlow features. Messages are sent through the Twilio API from your site; review Twilio terms and privacy policy.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Checkbox: allow public clinician list.
	 *
	 * @return void
	 */
	public static function render_field_allow_owner_clinician() {
		$opt = get_option( self::OPTION_ALLOW_OWNER_CLINICIAN, '0' );
		$on  = ( '1' === $opt );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_ALLOW_OWNER_CLINICIAN ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_ALLOW_OWNER_CLINICIAN ); ?>"
				value="1"
				<?php checked( $on ); ?>
			/>
			<?php esc_html_e( 'Expose sanitized clinician data to the public API (GET /kennelflow/v1/public-clinicians).', 'kennelflow-core' ); ?>
		</label>
		<?php
	}

	/**
	 * Checkbox: allow compliant pet owners to book boarding online.
	 *
	 * @return void
	 */
	public static function render_field_allow_owner_online_boarding() {
		$opt = get_option( self::OPTION_ALLOW_OWNER_ONLINE_BOARDING, '1' );
		$on  = ( '1' === $opt );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_ALLOW_OWNER_ONLINE_BOARDING ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_ALLOW_OWNER_ONLINE_BOARDING ); ?>"
				value="1"
				<?php checked( $on ); ?>
			/>
			<?php esc_html_e( 'Let pet owners submit boarding requests online when waiver and vaccination requirements are met and a kennel is available.', 'kennelflow-core' ); ?>
		</label>
		<?php
	}

	/**
	 * Number: VIP discount percent (0 = off).
	 *
	 * @return void
	 */
	public static function render_field_vip_discount_percentage() {
		$opt = get_option( self::OPTION_VIP_DISCOUNT_PERCENTAGE, 10 );
		$opt = is_numeric( $opt ) ? (int) $opt : 10;
		if ( $opt < 0 ) {
			$opt = 0;
		}
		if ( $opt > 100 ) {
			$opt = 100;
		}
		?>
		<label>
			<input
				type="number"
				name="<?php echo esc_attr( self::OPTION_VIP_DISCOUNT_PERCENTAGE ); ?>"
				value="<?php echo esc_attr( (string) $opt ); ?>"
				min="0"
				max="100"
				step="1"
				class="small-text"
			/>
		</label>
		<p class="description"><?php esc_html_e( 'Applied as a cart fee for logged-in subscribers with an active subscription. Set to 0 to disable.', 'kennelflow-core' ); ?></p>
		<?php
	}

	/**
	 * Twilio Account SID.
	 *
	 * @return void
	 */
	public static function render_field_twilio_account_sid() {
		$opt = get_option( self::OPTION_TWILIO_ACCOUNT_SID, '' );
		$opt = is_string( $opt ) ? $opt : '';
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_TWILIO_ACCOUNT_SID ); ?>"
			value="<?php echo esc_attr( $opt ); ?>"
			class="regular-text code"
			autocomplete="off"
		/>
		<?php
	}

	/**
	 * Twilio Auth Token (password field; leave blank to keep saved token).
	 *
	 * @return void
	 */
	public static function render_field_twilio_auth_token() {
		$has_token = '' !== (string) get_option( self::OPTION_TWILIO_AUTH_TOKEN, '' );
		?>
		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_TWILIO_AUTH_TOKEN ); ?>"
			value=""
			class="regular-text"
			autocomplete="new-password"
			placeholder="<?php echo esc_attr( $has_token ? __( 'Leave blank to keep existing token', 'kennelflow-core' ) : '' ); ?>"
		/>
		<?php if ( $has_token ) : ?>
			<p class="description"><?php esc_html_e( 'A token is saved. Enter a new value to replace it, or leave blank to keep the current token.', 'kennelflow-core' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Twilio From (E.164 recommended, e.g. +15551234567).
	 *
	 * @return void
	 */
	public static function render_field_twilio_from_number() {
		$opt = get_option( self::OPTION_TWILIO_FROM_NUMBER, '' );
		$opt = is_string( $opt ) ? $opt : '';
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_TWILIO_FROM_NUMBER ); ?>"
			value="<?php echo esc_attr( $opt ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description"><?php esc_html_e( 'Your Twilio phone number or approved sender ID (E.164 recommended).', 'kennelflow-core' ); ?></p>
		<?php
	}

	/**
	 * Settings page markup.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access KennelFlow Settings.', 'kennelflow-core' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KennelFlow Settings', 'kennelflow-core' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

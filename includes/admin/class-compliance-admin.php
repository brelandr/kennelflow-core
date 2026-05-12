<?php
/**
 * Legal compliance settings (state, retention, end-of-retention action).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ComplianceAdmin
 */
class ComplianceAdmin {

	const PAGE_SLUG = 'kf-compliance';

	const OPTION_KEY = 'ltkf_compliance';

	const SETTINGS_GROUP = 'ltkf_compliance_settings';

	const ACTION_ALERT = 'alert_only';

	const ACTION_ARCHIVE = 'auto_archive';

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
		return apply_filters( 'ltkf_compliance_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Compliance & Security', 'kennelflow-core' ),
			__( 'Compliance & Security', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register option, section, and fields (Settings API).
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'ltkf_compliance_main',
			__( 'Legal compliance', 'kennelflow-core' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ltkf_compliance_state',
			__( 'State of Operation', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_state' ),
			self::PAGE_SLUG,
			'ltkf_compliance_main'
		);

		add_settings_field(
			'ltkf_compliance_retention_years',
			__( 'Record Retention Period', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_retention_years' ),
			self::PAGE_SLUG,
			'ltkf_compliance_main'
		);

		add_settings_field(
			'ltkf_compliance_end_action',
			__( 'End-of-Retention Action', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_end_action' ),
			self::PAGE_SLUG,
			'ltkf_compliance_main'
		);
	}

	/**
	 * Default option values.
	 *
	 * @return array<string, int|string>
	 */
	public static function get_defaults() {
		return array(
			'state'                   => '',
			'retention_years'         => 5,
			'end_of_retention_action' => self::ACTION_ALERT,
		);
	}

	/**
	 * Merged saved settings with defaults.
	 *
	 * @return array<string, int|string>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * US states (and DC) for dropdown keys — ISO 3166-2 style codes.
	 *
	 * @return array<string, string> Code => label.
	 */
	public static function get_us_states() {
		$states = array(
			''   => __( '— Select —', 'kennelflow-core' ),
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		);

		/**
		 * Filter the US state list for the compliance state dropdown.
		 *
		 * @since 0.2.6
		 *
		 * @param array<string, string> $states Code => label.
		 */
		return apply_filters( 'ltkf_compliance_us_states', $states );
	}

	/**
	 * Sanitize and validate settings before saving to wp_options.
	 *
	 * @param mixed $input Raw POSTed option.
	 * @return array<string, int|string>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::get_defaults();
		$out      = self::get_settings();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$codes = array_keys( self::get_us_states() );
		$codes = array_filter(
			$codes,
			static function ( $c ) {
				return '' !== $c;
			}
		);

		$state = isset( $input['state'] ) ? sanitize_text_field( wp_unslash( $input['state'] ) ) : '';
		$state = strtoupper( $state );
		if ( '' !== $state && ! in_array( $state, $codes, true ) ) {
			$state = '';
		}
		$out['state'] = $state;

		$years = isset( $input['retention_years'] ) ? absint( $input['retention_years'] ) : (int) $defaults['retention_years'];
		if ( $years < 1 ) {
			$years = 1;
		}
		if ( $years > 99 ) {
			$years = 99;
		}
		$out['retention_years'] = $years;

		$action = isset( $input['end_of_retention_action'] ) ? sanitize_key( $input['end_of_retention_action'] ) : '';
		if ( ! in_array( $action, array( self::ACTION_ALERT, self::ACTION_ARCHIVE ), true ) ) {
			$action = (string) $defaults['end_of_retention_action'];
		}
		$out['end_of_retention_action'] = $action;

		return $out;
	}

	/**
	 * Section blurb.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Configure jurisdiction and record retention to match your facility’s legal obligations.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * State dropdown.
	 *
	 * @return void
	 */
	public static function render_field_state() {
		$settings = self::get_settings();
		$current  = isset( $settings['state'] ) ? (string) $settings['state'] : '';
		$states   = self::get_us_states();

		echo '<select id="ltkf_compliance_state" name="' . esc_attr( self::OPTION_KEY ) . '[state]">';
		foreach ( $states as $code => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Primary US state where your facility operates (for jurisdiction-specific requirements).', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Retention years number input.
	 *
	 * @return void
	 */
	public static function render_field_retention_years() {
		$settings = self::get_settings();
		$years    = isset( $settings['retention_years'] ) ? absint( $settings['retention_years'] ) : (int) self::get_defaults()['retention_years'];

		printf(
			'<input type="number" class="small-text" id="ltkf_compliance_retention_years" name="%1$s[retention_years]" min="1" max="99" step="1" value="%2$d" />',
			esc_attr( self::OPTION_KEY ),
			absint( $years )
		);
		echo ' <span class="description">' . esc_html__( 'Years', 'kennelflow-core' ) . '</span>';
		echo '<p class="description">' . esc_html__( 'How long to retain records before the end-of-retention action applies.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * End-of-retention radio group.
	 *
	 * @return void
	 */
	public static function render_field_end_action() {
		$settings = self::get_settings();
		$current  = isset( $settings['end_of_retention_action'] ) ? (string) $settings['end_of_retention_action'] : self::ACTION_ALERT;
		if ( ! in_array( $current, array( self::ACTION_ALERT, self::ACTION_ARCHIVE ), true ) ) {
			$current = self::ACTION_ALERT;
		}

		$name = self::OPTION_KEY . '[end_of_retention_action]';

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'End-of-Retention Action', 'kennelflow-core' ) . '</span></legend>';

		printf(
			'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label><br />',
			esc_attr( $name ),
			esc_attr( self::ACTION_ALERT ),
			checked( $current, self::ACTION_ALERT, false ),
			esc_html__( 'Alert Admin Only', 'kennelflow-core' )
		);

		printf(
			'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( self::ACTION_ARCHIVE ),
			checked( $current, self::ACTION_ARCHIVE, false ),
			esc_html__( 'Auto-Archive Records', 'kennelflow-core' )
		);

		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'What happens when a record reaches the end of its retention period.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Settings page markup.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kennelflow-core' ) );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save changes', 'kennelflow-core' ) );
				?>
			</form>
		</div>
		<?php
	}
}

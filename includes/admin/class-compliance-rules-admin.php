<?php
/**
 * Admin: Compliance Rules (required vaccines for facility policy).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ComplianceRulesAdmin
 */
class ComplianceRulesAdmin {

	const PAGE_SLUG = 'kf-compliance-rules';

	const SETTINGS_GROUP = 'ltkf_compliance_rules_settings';

	const OPTION_KEY = 'ltkf_required_vaccines';

	/**
	 * Boarding / kennel booking wizard: required vaccines (falls back to general list when empty).
	 */
	const OPTION_KEY_BOARDING = 'ltkf_boarding_required_vaccines';

	/**
	 * Standard vaccine labels (checkboxes).
	 *
	 * @return string[]
	 */
	public static function get_standard_vaccine_labels() {
		return array(
			'Rabies',
			'Bordetella',
			'DHPP',
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Capability for this screen.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_compliance_rules_capability', 'manage_options' );
	}

	/**
	 * Save options via POST to admin.php (bypasses options.php whitelist / isset quirks).
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['ltkf_compliance_rules_save'], $_POST['ltkf_compliance_rules_nonce'] ) ) {
			return;
		}
		$save_flag = sanitize_text_field( wp_unslash( $_POST['ltkf_compliance_rules_save'] ) );
		if ( '1' !== $save_flag ) {
			return;
		}
		$page = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to save these settings.', 'kennelflow-core' ),
				'',
				array( 'response' => 403 )
			);
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ltkf_compliance_rules_nonce'] ) ), 'ltkf_compliance_rules_save' ) ) {
			wp_die(
				esc_html__( 'Invalid security token.', 'kennelflow-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		$req_raw = array();
		if ( isset( $_POST['ltkf_required_vaccines'] ) && is_array( $_POST['ltkf_required_vaccines'] ) ) {
			$req_raw = map_deep( wp_unslash( $_POST['ltkf_required_vaccines'] ), 'sanitize_text_field' );
		}
		$board_raw = array();
		if ( isset( $_POST['ltkf_boarding_required_vaccines'] ) && is_array( $_POST['ltkf_boarding_required_vaccines'] ) ) {
			$board_raw = map_deep( wp_unslash( $_POST['ltkf_boarding_required_vaccines'] ), 'sanitize_text_field' );
		}

		update_option( self::OPTION_KEY, self::sanitize_required_vaccines( $req_raw ) );
		update_option( self::OPTION_KEY_BOARDING, self::sanitize_required_vaccines( $board_raw ) );

		wp_safe_redirect(
			add_query_arg(
				'settings-updated',
				'true',
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Compliance Rules', 'kennelflow-core' ),
			__( 'Compliance Rules', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register option and fields (Settings API).
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_required_vaccines' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY_BOARDING,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_required_vaccines' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'ltkf_compliance_rules_main',
			__( 'Required vaccines', 'kennelflow-core' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ltkf_compliance_rules_standard',
			__( 'Standard vaccines', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_standard' ),
			self::PAGE_SLUG,
			'ltkf_compliance_rules_main'
		);

		add_settings_field(
			'ltkf_compliance_rules_custom',
			__( 'Additional required vaccines', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_custom' ),
			self::PAGE_SLUG,
			'ltkf_compliance_rules_main'
		);

		add_settings_section(
			'ltkf_compliance_rules_boarding',
			__( 'Boarding and kennel stays', 'kennelflow-core' ),
			array( __CLASS__, 'render_section_description_boarding' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ltkf_compliance_rules_boarding_standard',
			__( 'Standard vaccines (boarding)', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_boarding_standard' ),
			self::PAGE_SLUG,
			'ltkf_compliance_rules_boarding'
		);

		add_settings_field(
			'ltkf_compliance_rules_boarding_custom',
			__( 'Additional required vaccines (boarding)', 'kennelflow-core' ),
			array( __CLASS__, 'render_field_boarding_custom' ),
			self::PAGE_SLUG,
			'ltkf_compliance_rules_boarding'
		);
	}

	/**
	 * Section blurb.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Select which vaccines are required for your facility. Compliance checks use the kf_medical_records table: the latest row per vaccine (matching analyte name) is compared by expiration_gmt in UTC.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Boarding section blurb.
	 *
	 * @return void
	 */
	public static function render_section_description_boarding() {
		echo '<p>' . esc_html__( 'Optional: set which vaccines boarding guests must show on the public booking wizard. If you leave this empty, the general required vaccines list above is used. Owner uploads are stored on the pet’s medical record (pending staff review) like the owner portal.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Checkboxes for Rabies, Bordetella, DHPP.
	 *
	 * @return void
	 */
	public static function render_field_standard() {
		self::render_standard_vaccines_for_option( self::OPTION_KEY, 'ltkf_req_vacc_' );
	}

	/**
	 * Boarding section: standard vaccines.
	 *
	 * @return void
	 */
	public static function render_field_boarding_standard() {
		self::render_standard_vaccines_for_option( self::OPTION_KEY_BOARDING, 'ltkf_board_req_vacc_' );
	}

	/**
	 * Render standard vaccine checkboxes for a given option key.
	 *
	 * @param string $option_key Option name.
	 * @param string $id_prefix  HTML id prefix for inputs.
	 * @return void
	 */
	protected static function render_standard_vaccines_for_option( $option_key, $id_prefix ) {
		$saved    = get_option( $option_key, array() );
		$selected = is_array( $saved ) ? $saved : array();
		$labels   = self::get_standard_vaccine_labels();

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Standard vaccines', 'kennelflow-core' ) . '</span></legend>';
		foreach ( $labels as $label ) {
			$id = $id_prefix . sanitize_key( $label );
			printf(
				'<label for="%1$s"><input type="checkbox" name="%2$s[standard][]" id="%1$s" value="%3$s"%4$s /> %3$s</label><br />',
				esc_attr( $id ),
				esc_attr( $option_key ),
				esc_attr( $label ),
				checked( in_array( $label, $selected, true ), true, false )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Pre-defined options; checked vaccines are required for compliance.', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Extra vaccine names, one per line (e.g. Canine Influenza (CIV)).
	 *
	 * @return void
	 */
	public static function render_field_custom() {
		self::render_custom_vaccines_for_option( self::OPTION_KEY, 'ltkf_req_vacc_custom' );
	}

	/**
	 * Boarding section: custom lines.
	 *
	 * @return void
	 */
	public static function render_field_boarding_custom() {
		self::render_custom_vaccines_for_option( self::OPTION_KEY_BOARDING, 'ltkf_board_req_vacc_custom' );
	}

	/**
	 * Render custom vaccine textarea for a given option key.
	 *
	 * @param string $option_key Option name.
	 * @param string $textarea_id HTML id for textarea.
	 * @return void
	 */
	protected static function render_custom_vaccines_for_option( $option_key, $textarea_id ) {
		$saved    = get_option( $option_key, array() );
		$standard = self::get_standard_vaccine_labels();
		$lines    = array();
		if ( is_array( $saved ) ) {
			foreach ( $saved as $v ) {
				$v = (string) $v;
				if ( '' === $v ) {
					continue;
				}
				if ( ! in_array( $v, $standard, true ) ) {
					$lines[] = $v;
				}
			}
		}
		$textarea = implode( "\n", $lines );

		printf(
			'<textarea class="large-text" rows="4" cols="40" name="%1$s[custom_lines]" id="%2$s">%3$s</textarea>',
			esc_attr( $option_key ),
			esc_attr( $textarea_id ),
			esc_textarea( $textarea )
		);
		echo '<p class="description">' . esc_html__( 'Optional: one vaccine name per line. Names must match analyte_name in medical records (case-insensitive).', 'kennelflow-core' ) . '</p>';
	}

	/**
	 * Sanitize stored vaccine list from POST.
	 *
	 * @param mixed $input Raw (from options.php).
	 * @return string[]
	 */
	public static function sanitize_required_vaccines( $input ) {
		$allowed_standard = self::get_standard_vaccine_labels();
		$out              = array();

		if ( null === $input || ! is_array( $input ) ) {
			return array();
		}

		if ( isset( $input['standard'] ) && is_array( $input['standard'] ) ) {
			foreach ( $input['standard'] as $v ) {
				$v = sanitize_text_field( wp_unslash( (string) $v ) );
				if ( in_array( $v, $allowed_standard, true ) ) {
					$out[] = $v;
				}
			}
		}

		if ( isset( $input['custom_lines'] ) ) {
			$raw = wp_unslash( (string) $input['custom_lines'] );
			$raw = explode( "\n", $raw );
			foreach ( $raw as $line ) {
				$line = sanitize_text_field( trim( $line ) );
				if ( '' === $line ) {
					continue;
				}
				if ( strlen( $line ) > 191 ) {
					$line = substr( $line, 0, 191 );
				}
				$out[] = $line;
			}
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );

		return $out;
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after wp_safe_redirect from nonce-verified save.
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) {
			add_settings_error(
				'ltkf_compliance_rules',
				'settings_updated',
				__( 'Settings saved.', 'kennelflow-core' ),
				'success'
			);
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="ltkf_compliance_rules_save" value="1" />
				<?php wp_nonce_field( 'ltkf_compliance_rules_save', 'ltkf_compliance_rules_nonce' ); ?>
				<?php do_settings_sections( self::PAGE_SLUG ); ?>
				<?php submit_button( __( 'Save changes', 'kennelflow-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}

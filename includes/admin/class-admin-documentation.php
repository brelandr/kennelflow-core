<?php
/**
 * Admin: Documentation (active plugins only + shortcodes).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminDocumentation
 */
class AdminDocumentation {

	const PAGE_SLUG = 'kf-documentation';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Capability for viewing documentation.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_documentation_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Documentation', 'kennelflow-core' ),
			__( 'Documentation', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Minimal styles on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_add_inline_style(
			'wp-admin',
			'.kf-doc-section { max-width: 52rem; margin-top: 1.25rem; }
			.kf-doc-section .kf-doc-shortcodes { margin-top: 0.75rem; padding: 0.75rem 1rem; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; }
			.kf-doc-section code { font-size: 13px; }
			.kf-doc-toc { margin: 1rem 0 2rem; padding: 0.75rem 1rem; background: #f0f6fc; border-left: 4px solid #72aee6; max-width: 52rem; }'
		);
	}

	/**
	 * Render documentation HTML.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kennelflow-core' ) );
		}

		$sections = self::collect_sections();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'KennelFlow documentation', 'kennelflow-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Instructions below apply only to plugins that are currently active on this site.', 'kennelflow-core' ) . '</p>';

		if ( count( $sections ) > 1 ) {
			echo '<nav class="kf-doc-toc" aria-label="' . esc_attr__( 'On this page', 'kennelflow-core' ) . '">';
			echo '<strong>' . esc_html__( 'On this page', 'kennelflow-core' ) . '</strong><ul class="ul-disc" style="margin:0.5em 0 0 1.25em;">';
			foreach ( $sections as $sec ) {
				$anchor = isset( $sec['id'] ) ? (string) $sec['id'] : '';
				if ( '' === $anchor ) {
					continue;
				}
				echo '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $sec['title'] ) . '</a></li>';
			}
			echo '</ul></nav>';
		}

		foreach ( $sections as $sec ) {
			$id    = isset( $sec['id'] ) ? (string) $sec['id'] : '';
			$title = isset( $sec['title'] ) ? (string) $sec['title'] : '';
			$body  = isset( $sec['content'] ) ? (string) $sec['content'] : '';

			echo '<section class="card kf-doc-section"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
			echo '<h2 class="title" style="margin-top:0;">' . esc_html( $title ) . '</h2>';
			echo wp_kses_post( $body );
			echo '</section>';
		}

		echo '</div>';
	}

	/**
	 * Build sections for active plugins only, then allow extensions.
	 *
	 * @return array<int, array{id:string, title:string, content:string}>
	 */
	protected static function collect_sections() {
		$sections = array();

		$sections[] = self::section_kennelflow_core();

		if ( self::is_kennelpress_active() ) {
			$sections[] = self::section_kennelpress();
		}

		if ( self::is_kennelflow_vet_active() ) {
			$sections[] = self::section_kennelflow_vet();
		}

		if ( self::is_groompress_active() ) {
			$sections[] = self::section_groompress();
		}

		if ( self::is_kf_pos_active() ) {
			$sections[] = self::section_kf_pos();
		}

		if ( self::is_kf_facility_iot_active() ) {
			$sections[] = self::section_kf_facility_iot();
		}

		/**
		 * Documentation sections for the KennelFlow Documentation admin screen.
		 *
		 * Each section: `id` (anchor slug), `title` (string), `content` (HTML fragment, will be passed through wp_kses_post).
		 * Append or replace entries for custom / premium plugins.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, array{id?:string, title?:string, content?:string}> $sections Sections to render.
		 */
		$filtered = apply_filters( 'ltkf_documentation_sections', $sections );

		return is_array( $filtered ) ? $filtered : $sections;
	}

	/**
	 * Whether KennelFlow Boarding (or legacy Kennel Press) is active.
	 *
	 * @return bool
	 */
	protected static function is_kennelpress_active() {
		return ( defined( 'KENNELFLOW_BOARDING_VERSION' ) && class_exists( 'KennelFlow_Boarding_Plugin' ) )
			|| ( defined( 'KENNELPRESS_VERSION' ) && class_exists( 'KennelPress_Plugin' ) );
	}

	/**
	 * Whether KennelFlow Vet is active.
	 *
	 * @return bool
	 */
	protected static function is_kennelflow_vet_active() {
		return defined( 'KENNELFLOW_VET_VERSION' ) && class_exists( 'KennelFlow_Vet_Plugin' );
	}

	/**
	 * Whether GroomPress is active.
	 *
	 * @return bool
	 */
	protected static function is_groompress_active() {
		return defined( 'GROOMPRESS_VERSION' ) && class_exists( 'GroomPress_Plugin' );
	}

	/**
	 * Whether KF Point of Sale is active.
	 *
	 * @return bool
	 */
	protected static function is_kf_pos_active() {
		return defined( 'LTKF_POS_VERSION' ) && class_exists( 'LTKF_POS_Plugin' );
	}

	/**
	 * Whether KF Facility IoT is active.
	 *
	 * @return bool
	 */
	protected static function is_kf_facility_iot_active() {
		return defined( 'LTKF_FIOT_VERSION' ) && class_exists( 'LTKF_Facility_IoT_Plugin' );
	}

	/**
	 * KennelFlow Core (always; this screen is part of Core).
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_kennelflow_core() {
		$shortcodes = self::shortcode_block(
			array(
				array(
					'tag'         => 'ltkf_dashboard',
					'description' => __( 'Renders the pet owner portal: bookings, medical records, waivers, waitlist actions, and balance payment when WooCommerce is configured. Place on a page that logged-in customers can access. The legacy tag [kennelflow_dashboard] is still detected when resolving the portal URL.', 'kennelflow-core' ),
					'example'     => '[ltkf_dashboard]',
					'attributes'  => __( 'No attributes.', 'kennelflow-core' ),
				),
				array(
					'tag'         => 'ltkf_booking',
					'description' => __( 'Boarding booking wizard when KennelFlow Vet is not active and has not registered its own wizard shortcode. Requires KennelFlow Core assets and (for API data) Kennel Press or a compatible booking provider.', 'kennelflow-core' ),
					'example'     => '[ltkf_booking]',
					'attributes'  => __( 'No attributes.', 'kennelflow-core' ),
				),
				array(
					'tag'         => 'ltkf_hub_calendar',
					'description' => __( 'Embeds the Hub booking calendar (same React app as Pets → Calendar) on a front-end page. Staff only (default capability: edit_posts). Anonymous users see a login message; no booking data is exposed without permission.', 'kennelflow-core' ),
					'example'     => '[ltkf_hub_calendar]',
					'attributes'  => __( 'No attributes.', 'kennelflow-core' ),
				),
			)
		);

		$woo = '';
		if ( class_exists( 'WooCommerce' ) ) {
			$woo  = '<h3>' . esc_html__( 'WooCommerce', 'kennelflow-core' ) . '</h3>';
			$woo .= '<p>' . esc_html__( 'Boarding products, deposits, checkout integration, and portal “Pay” actions require WooCommerce. Configure products and payment gateways under WooCommerce → Settings.', 'kennelflow-core' ) . '</p>';
		}

		$content  = '<p>' . esc_html__( 'KennelFlow Core registers the shared pet hub (pets, locations, owner ↔ pet user links), compliance tools, CRM sweeps, archive vault, migrations, calendar APIs, waitlist storage, the owner portal shortcode, public booking wizard shortcodes when KennelFlow Vet is off, and an optional staff-only Hub calendar shortcode.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Where to start', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Use System Health under Pets to confirm WooCommerce, database tables, and cron.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Create pet and location records, then connect owners to pets from user/profile workflows.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Add a public or members-only page with the owner portal shortcode (see below).', 'kennelflow-core' ) . '</li></ul>';
		$content .= $woo;
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= $shortcodes;

		return array(
			'id'      => 'kennelflow-core',
			'title'   => __( 'KennelFlow Core', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * Documentation block for Kennel Press.
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_kennelpress() {
		$content  = '<p>' . esc_html__( 'Kennel Press adds boarding-specific post types (kennels, bookings), availability checks, the booking index, facility settings, and REST endpoints for calendars and integrations.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Typical setup', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Define locations and kennel inventory, then map products in WooCommerce to boarding stays.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Use the Hub calendar and facility screens under Pets to manage occupancy and scheduling.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Pet owners use the KennelFlow portal shortcode from Core; Kennel Press supplies the booking data behind it.', 'kennelflow-core' ) . '</li></ul>';
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= '<p>' . esc_html__( 'Kennel Press does not register its own public shortcodes. Use [ltkf_dashboard] from KennelFlow Core for the owner portal.', 'kennelflow-core' ) . '</p>';

		return array(
			'id'      => 'kennel-press',
			'title'   => __( 'Kennel Press', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * Documentation block for KennelFlow Vet.
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_kennelflow_vet() {
		$shortcodes = self::shortcode_block(
			array(
				array(
					'tag'         => 'kennelflow_vet_booking',
					'description' => __( 'When KennelFlow Vet is active, the plugin registers this shortcode and loads the boarding/clinic wizard. When inactive, KennelFlow Core registers [ltkf_booking] instead.', 'kennelflow-core' ),
					'example'     => '[kennelflow_vet_booking]',
					'attributes'  => __( 'No attributes.', 'kennelflow-core' ),
				),
			)
		);

		$content  = '<p>' . esc_html__( 'KennelFlow Vet adds clinical rooms, encounters, SOAP notes, labs, and related types on top of the Hub pet model, with availability and REST endpoints aligned to KennelFlow.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Typical setup', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Configure rooms, locations, and clinical workflows in the KennelFlow Vet admin menus.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Expose the booking wizard on a page using the shortcode below.', 'kennelflow-core' ) . '</li></ul>';
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= $shortcodes;

		return array(
			'id'      => 'kennelflow-vet',
			'title'   => __( 'KennelFlow Vet', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * Documentation block for GroomPress.
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_groompress() {
		$content  = '<p>' . esc_html__( 'GroomPress adds grooming scheduling: groomer role, calendar integration with booking kind “grooming”, commissions, and optional KennelFlow Vet access rules.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Typical setup', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Assign the Groomer role to staff who should appear as calendar resources.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Open GroomPress settings and calendar screens under Pets to configure earnings and grooming views.', 'kennelflow-core' ) . '</li></ul>';
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= '<p>' . esc_html__( 'GroomPress does not register a front-end shortcode; scheduling is managed in the admin calendar.', 'kennelflow-core' ) . '</p>';

		return array(
			'id'      => 'groompress',
			'title'   => __( 'GroomPress', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * Documentation block for KF Point of Sale.
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_kf_pos() {
		$content  = '<p>' . esc_html__( 'KF Point of Sale connects Stripe Terminal readers to WooCommerce: connection tokens, reader UI, and webhooks to reconcile in-person charges with orders.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Typical setup', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Requires WooCommerce. Add your Stripe secret key in Point of Sale settings (see System Health if missing).', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Pair readers and process payments from the POS admin screens.', 'kennelflow-core' ) . '</li></ul>';
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= '<p>' . esc_html__( 'KF Point of Sale does not register public shortcodes.', 'kennelflow-core' ) . '</p>';

		return array(
			'id'      => 'kf-point-of-sale',
			'title'   => __( 'KF Point of Sale', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * Documentation block for KF Facility IoT.
	 *
	 * @return array{id:string, title:string, content:string}
	 */
	protected static function section_kf_facility_iot() {
		$content  = '<p>' . esc_html__( 'KF Facility IoT receives sensor webhooks, evaluates temperature and other alerts, and can integrate smart lock signals for facility automation.', 'kennelflow-core' ) . '</p>';
		$content .= '<h3>' . esc_html__( 'Typical setup', 'kennelflow-core' ) . '</h3>';
		$content .= '<ul class="ul-disc"><li>' . esc_html__( 'Open Facility IoT settings to configure endpoints, thresholds, and notification behavior.', 'kennelflow-core' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Point hardware or middleware at the REST routes documented in plugin settings.', 'kennelflow-core' ) . '</li></ul>';
		$content .= '<h3>' . esc_html__( 'Shortcodes', 'kennelflow-core' ) . '</h3>';
		$content .= '<p>' . esc_html__( 'KF Facility IoT does not register public shortcodes.', 'kennelflow-core' ) . '</p>';

		return array(
			'id'      => 'kf-facility-iot',
			'title'   => __( 'KF Facility IoT', 'kennelflow-core' ),
			'content' => $content,
		);
	}

	/**
	 * HTML block listing shortcodes.
	 *
	 * @param array<int, array{tag:string, description:string, example:string, attributes:string}> $items Rows.
	 * @return string
	 */
	protected static function shortcode_block( array $items ) {
		$out = '<div class="kf-doc-shortcodes"><dl style="margin:0;">';
		foreach ( $items as $row ) {
			$tag = isset( $row['tag'] ) ? sanitize_key( (string) $row['tag'] ) : '';
			if ( '' === $tag ) {
				continue;
			}
			$out .= '<dt style="margin-top:0.75rem;"><code>[' . esc_html( $tag ) . ']</code></dt>';
			$out .= '<dd style="margin-left:0;">';
			$out .= '<p style="margin:0.35rem 0;">' . esc_html( isset( $row['description'] ) ? (string) $row['description'] : '' ) . '</p>';
			$out .= '<p style="margin:0.35rem 0;"><strong>' . esc_html__( 'Example:', 'kennelflow-core' ) . '</strong> <code>' . esc_html( isset( $row['example'] ) ? (string) $row['example'] : '' ) . '</code></p>';
			$out .= '<p style="margin:0.35rem 0;"><strong>' . esc_html__( 'Attributes:', 'kennelflow-core' ) . '</strong> ' . esc_html( isset( $row['attributes'] ) ? (string) $row['attributes'] : '' ) . '</p>';
			$out .= '</dd>';
		}
		$out .= '</dl></div>';

		return $out;
	}
}

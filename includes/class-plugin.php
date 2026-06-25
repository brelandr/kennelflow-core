<?php
/**
 * Main KennelFlow Core plugin class.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			AdminHub::init();
			MigrationAdmin::init();
			AdminSettings::init();
			AdminPermissions::init();
			AdminPetMeta::init();
			AdminPetCareDefaults::init();
			AdminPetLedger::init();
			AdminLocationMeta::init();
			AdminClinicianProfiles::init();
			AdminUserMultiRoles::init();
			AdminCalendar::init();
			AdminReportCard::init();
			ComplianceAdmin::init();
			ComplianceRulesAdmin::init();
			AdminPendingRecords::init();
			ArchiveVaultAdmin::init();
			AdminSystemHealth::init();
			AdminDocumentation::init();
			AdminDemoData::init();
			AdminRevenue::init();
			AdminWoocommerceNotice::init();
			AdminWebhooks::init();
		}

		WebhookEngine::init();

		add_action( 'init', array( $this, 'register_content' ), 3 );
		add_action( 'init', array( $this, 'maybe_boot_portal' ), 6 );
		add_action( 'plugins_loaded', array( $this, 'boot_waitlist' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_load_woocommerce_modules' ), 20 );

		DemoDataSeeder::init();
		AutomatedCrm::init();
		AdminCalendarApi::init();
		ReportCardApi::init();
		ComplianceUploadApi::init();
		OwnerPetsRestApi::init();
		PublicCliniciansApi::init();
		BookingWizardRest::init();
		BookingWizardFrontend::init();
		FrontendHubCalendar::init();
		RestPermissions::init();
		UserRegistration::init();

		add_action( 'plugins_loaded', array( ActionSchedulerOptimization::class, 'init' ), 20 );

		/**
		 * Fires after KennelFlow Core registers primary hooks.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_core_init' );
	}

	/**
	 * CPTs, taxonomies, meta bridges.
	 *
	 * @return void
	 */
	public function register_content() {
		PostTypes::register();
		OwnerPets::init();

		/**
		 * Fires after Hub registers pets, locations, and owner mapping hooks.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_core_registered_content' );
	}

	/**
	 * Owner portal shortcode and AJAX.
	 *
	 * @return void
	 */
	public function maybe_boot_portal() {
		Portal::init();
	}

	/**
	 * Waitlist table + engine (KennelPress cancellation hook).
	 *
	 * @return void
	 */
	public function boot_waitlist() {
		WaitlistDb::maybe_upgrade();
		WaitlistEngine::init();
		WaitlistFront::init();
	}

	/**
	 * WooCommerce-dependent modules (cart pricing, etc.).
	 *
	 * @return void
	 */
	public function maybe_load_woocommerce_modules() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Woocommerce::init();
		RevenueOptimizer::init();
		WoocommerceDeposits::init();
		VipMemberships::init();
		BookingComplianceGate::init();
	}
}

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
			if ( class_exists( 'AdminHub' ) ) {
				AdminHub::init();
			}
			MigrationAdmin::init();
			if ( class_exists( 'AdminSettings' ) ) {
				AdminSettings::init();
			}
			if ( class_exists( 'AdminPermissions' ) ) {
				AdminPermissions::init();
			}
			AdminPetMeta::init();
			AdminPetCareDefaults::init();
			AdminPetLedger::init();
			AdminLocationMeta::init();
			AdminClinicianProfiles::init();
			AdminUserMultiRoles::init();
			if ( class_exists( 'AdminCalendar' ) ) {
				AdminCalendar::init();
			}
			if ( class_exists( 'AdminReportCard' ) ) {
				AdminReportCard::init();
			}
			if ( class_exists( 'ComplianceAdmin' ) ) {
				ComplianceAdmin::init();
			}
			ComplianceRulesAdmin::init();
			if ( class_exists( 'AdminPendingRecords' ) ) {
				AdminPendingRecords::init();
			}
			if ( class_exists( 'ArchiveVaultAdmin' ) ) {
				ArchiveVaultAdmin::init();
			}
			if ( class_exists( 'AdminSystemHealth' ) ) {
				AdminSystemHealth::init();
			}
			if ( class_exists( 'AdminDocumentation' ) ) {
				AdminDocumentation::init();
			}
			if ( class_exists( 'AdminDemoData' ) ) {
				AdminDemoData::init();
			}
			if ( class_exists( 'AdminRevenue' ) ) {
				AdminRevenue::init();
			}
			if ( class_exists( 'AdminWoocommerceNotice' ) ) {
				AdminWoocommerceNotice::init();
			}
			if ( class_exists( 'AdminWebhooks' ) ) {
				AdminWebhooks::init();
			}
		}

		if ( class_exists( 'WebhookEngine' ) ) {
			WebhookEngine::init();
		}

		add_action( 'init', array( $this, 'register_content' ), 3 );
		add_action( 'init', array( $this, 'maybe_boot_portal' ), 6 );
		add_action( 'plugins_loaded', array( $this, 'boot_waitlist' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_load_woocommerce_modules' ), 20 );

		if ( class_exists( 'DemoDataSeeder' ) ) {
			DemoDataSeeder::init();
		}

		if ( class_exists( 'AutomatedCrm' ) ) {
			AutomatedCrm::init();
		}

		if ( class_exists( 'AdminCalendarApi' ) ) {
			AdminCalendarApi::init();
		}
		if ( class_exists( 'ReportCardApi' ) ) {
			ReportCardApi::init();
		}
		if ( class_exists( 'ComplianceUploadApi' ) ) {
			ComplianceUploadApi::init();
		}
		if ( class_exists( 'OwnerPetsRestApi' ) ) {
			OwnerPetsRestApi::init();
		}
		if ( class_exists( 'PublicCliniciansApi' ) ) {
			PublicCliniciansApi::init();
		}
		if ( class_exists( 'BookingWizardRest' ) ) {
			BookingWizardRest::init();
		}
		if ( class_exists( 'BookingWizardFrontend' ) ) {
			BookingWizardFrontend::init();
		}
		if ( class_exists( 'FrontendHubCalendar' ) ) {
			FrontendHubCalendar::init();
		}
		if ( class_exists( 'RestPermissions' ) ) {
			RestPermissions::init();
		}
		if ( class_exists( 'UserRegistration' ) ) {
			UserRegistration::init();
		}

		add_action( 'plugins_loaded', array( 'ActionSchedulerOptimization', 'init' ), 20 );

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
		if ( class_exists( 'Portal' ) ) {
			Portal::init();
		}
	}

	/**
	 * Waitlist table + engine (KennelPress cancellation hook).
	 *
	 * @return void
	 */
	public function boot_waitlist() {
		if ( ! class_exists( 'WaitlistDb' ) ) {
			return;
		}

		WaitlistDb::maybe_upgrade();

		if ( class_exists( 'WaitlistEngine' ) ) {
			WaitlistEngine::init();
		}

		if ( class_exists( 'WaitlistFront' ) ) {
			WaitlistFront::init();
		}
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

		if ( class_exists( 'Woocommerce' ) ) {
			Woocommerce::init();
		}

		if ( class_exists( 'RevenueOptimizer' ) ) {
			RevenueOptimizer::init();
		}

		if ( class_exists( 'WoocommerceDeposits' ) ) {
			WoocommerceDeposits::init();
		}

		if ( class_exists( 'VipMemberships' ) ) {
			VipMemberships::init();
		}

		if ( class_exists( 'BookingComplianceGate' ) ) {
			BookingComplianceGate::init();
		}
	}
}

/**
 * WP-CLI helpers for KennelFlow E2E (options, fixtures, order meta).
 *
 * Set E2E_WP_CLI_PREFIX when using wp-env, e.g.:
 *   export E2E_WP_CLI_PREFIX="npx wp-env run tests-cli"
 *
 * Set E2E_WP_EVAL_FILE_REL if the plugin lives somewhere other than
 * wp-content/plugins/kennelflow-core (path relative to WordPress root).
 *
 * @package KennelFlow
 */

const { execSync } = require( 'child_process' );

/**
 * @return {string}
 */
function getWpCliPrefix() {
	return ( process.env.E2E_WP_CLI_PREFIX || '' ).trim();
}

/**
 * @param {string} wpArgs Everything after `wp `.
 * @param {import('child_process').ExecSyncOptions} opts Extra exec options.
 * @return {string} stdout
 */
function runWpCli( wpArgs, opts = {} ) {
	const prefix = getWpCliPrefix();
	const cmd    = prefix ? `${ prefix } wp ${ wpArgs }` : `wp ${ wpArgs }`;
	return execSync(
		cmd,
		{
			encoding: 'utf8',
			stdio: [ 'pipe', 'pipe', 'pipe' ],
			...opts,
		}
	);
}

/**
 * @return {boolean}
 */
function wpCliWorks() {
	if ( '0' === process.env.E2E_WP_CLI ) {
		return false;
	}
	try {
		runWpCli( '--info' );
		return true;
	} catch ( e ) {
		return false;
	}
}

/**
 * Path to a fixture PHP file, relative to WordPress root (inside wp-env container).
 *
 * @param {string} basename Fixture file name (e.g. e2e-seed-confirmed-occupancy.php).
 * @return {string}
 */
function getFixtureEvalPath( basename ) {
	const rel =
		process.env.E2E_WP_EVAL_FILE_REL ||
		`wp - content / plugins / kennelflow - core / tests / e2e / fixtures / ${ basename }`;
	return rel.replace( /\\/g, '/' );
}

/**
 * @param {string} relPath Relative path from WP root.
 * @param {Record<string, string>} extraEnv  Extra env for the child process.
 * @return {string} stdout
 */
function wpEvalFile( relPath, extraEnv = {} ) {
	return runWpCli(
		`eval - file ${ relPath }`,
		{
			env: { ...process.env, ...extraEnv },
		}
	);
}

/**
 * @param {string} key Option name.
 * @return {string}
 */
function getOption( key ) {
	try {
		return runWpCli( `option get ${ key } --quiet` ).trim();
	} catch ( e ) {
		return '';
	}
}

/**
 * @param {string} key   Option name.
 * @param {string|number} value Raw value (numeric or string).
 * @return {void}
 */
function setOption( key, value ) {
	const v = String( value );
	if ( /^-?\d+(\.\d+)?$/.test( v ) ) {
		runWpCli( `option update ${ key } ${ v }` );
	} else {
		const escaped = v.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
		runWpCli( `option update ${ key } "${ escaped }"` );
	}
}

/**
 * @param {number|string} amount Boarding product regular price.
 * @return {string} stdout (echoed price)
 */
function setBoardingProductPrice( amount ) {
	const rel = getFixtureEvalPath( 'e2e-set-boarding-product-price.php' );
	return wpEvalFile( rel, { E2E_BOARDING_PRICE: String( amount ) } ).trim();
}

/**
 * @return {void}
 */
function seedConfirmedOccupancy() {
	const rel = getFixtureEvalPath( 'e2e-seed-confirmed-occupancy.php' );
	wpEvalFile( rel );
}

/**
 * @return {void}
 */
function cleanupOccupancySeed() {
	const rel = getFixtureEvalPath( 'e2e-cleanup-occupancy-seed.php' );
	wpEvalFile( rel );
}

/**
 * @param {number} postId Order or post ID.
 * @param {string} metaKey Meta key.
 * @return {string}
 */
function getPostMeta( postId, metaKey ) {
	try {
		return runWpCli(
			`post meta get ${ String( postId ) } ${ metaKey }`
		).trim();
	} catch ( e ) {
		return '';
	}
}

/**
 * @typedef {{ kf_total_kennel_capacity: string, kf_deposit_percentage: string, boarding_price: string, kf_surge_enabled: string, kf_surge_threshold: string, kf_surge_increase_percentage: string }} RevenueSnapshot
 */

/**
 * @return {RevenueSnapshot}
 */
function saveRevenueSnapshot() {
	let boarding_price = '';
	try {
		const rel      = getFixtureEvalPath( 'e2e-get-boarding-price.php' );
		boarding_price = wpEvalFile( rel ).trim();
	} catch ( e ) {
		boarding_price = '';
	}

	return {
		kf_total_kennel_capacity: getOption( 'kf_total_kennel_capacity' ),
		kf_deposit_percentage: getOption( 'kf_deposit_percentage' ),
		boarding_price: boarding_price,
		kf_surge_enabled: getOption( 'kf_surge_enabled' ),
		kf_surge_threshold: getOption( 'kf_surge_threshold' ),
		kf_surge_increase_percentage: getOption( 'kf_surge_increase_percentage' ),
	};
}

/**
 * @param {RevenueSnapshot} snap Snapshot from saveRevenueSnapshot.
 * @return {void}
 */
function restoreRevenueSnapshot( snap ) {
	if ( snap.kf_total_kennel_capacity !== '' ) {
		setOption( 'kf_total_kennel_capacity', snap.kf_total_kennel_capacity );
	}
	if ( snap.kf_deposit_percentage !== '' ) {
		setOption( 'kf_deposit_percentage', snap.kf_deposit_percentage );
	}
	if ( snap.boarding_price !== '' ) {
		setBoardingProductPrice( snap.boarding_price );
	}
	if ( snap.kf_surge_enabled !== '' ) {
		setOption( 'kf_surge_enabled', snap.kf_surge_enabled );
	}
	if ( snap.kf_surge_threshold !== '' ) {
		setOption( 'kf_surge_threshold', snap.kf_surge_threshold );
	}
	if ( snap.kf_surge_increase_percentage !== '' ) {
		setOption( 'kf_surge_increase_percentage', snap.kf_surge_increase_percentage );
	}
	cleanupOccupancySeed();
}

/**
 * @return {{ ok: boolean, pet_id: number, record_id: number, target: string }}
 */
function seedCrmMedicalRecord() {
	const rel = getFixtureEvalPath( 'e2e-seed-crm-medical-record.php' );
	const out = wpEvalFile( rel ).trim();
	return JSON.parse( out );
}

/**
 * Runs `do_action( 'kf_daily_crm_sweep' )` with `pre_wp_mail` stubbed (same as `wp cron event run kf_daily_crm_sweep` when mail is not configured).
 *
 * @return {string} stdout
 */
function runCrmDailySweepCronHook() {
	const rel = getFixtureEvalPath( 'e2e-run-crm-sweep.php' );
	return wpEvalFile( rel ).trim();
}

/**
 * @param {number} petId kf_pet ID
 * @return {number}
 */
function countCrmAuditEntries( petId ) {
	const rel = getFixtureEvalPath( 'e2e-count-crm-audit.php' );
	const out = wpEvalFile( rel, { E2E_CRM_PET_ID: String( petId ) } ).trim();
	const n   = parseInt( out, 10 );
	return Number.isNaN( n ) ? 0 : n;
}

/**
 * @param {number} recordId kf_medical_records primary key
 * @return {void}
 */
function cleanupCrmMedicalRecord( recordId ) {
	const rel = getFixtureEvalPath( 'e2e-cleanup-crm-medical-record.php' );
	wpEvalFile( rel, { E2E_CRM_RECORD_ID: String( recordId ) } );
}

/**
 * @param {string} hook Cron hook name, e.g. kf_daily_crm_sweep
 * @return {string} stdout
 */
function runWpCliCronEvent( hook ) {
	return runWpCli( `cron event run ${ hook }` );
}

/**
 * @return {number} Pet post ID or 0 on failure.
 */
function getPetIdForOwner() {
	const rel = getFixtureEvalPath( 'e2e-get-pet-id-for-owner.php' );
	try {
		const out = wpEvalFile( rel ).trim();
		const n   = parseInt( out, 10 );
		return Number.isNaN( n ) ? 0 : n;
	} catch ( e ) {
		return 0;
	}
}

/**
 * @param {number} petId  kf_pet ID.
 * @param {string} needle Substring expected in latest encounter subjective.
 * @return {void}
 */
function assertEncounterSoapAndAudit( petId, needle = 'E2E Subj' ) {
	const rel = getFixtureEvalPath( 'e2e-assert-encounter-e2e.php' );
	wpEvalFile(
		rel,
		{
			E2E_PET_ID: String( petId ),
			E2E_SOAP_SUBJECTIVE_NEEDLE: needle,
		}
	);
}

/**
 * @param {number} groomerId WordPress user ID (groomer / admin).
 * @return {string} JSON stdout from fixture.
 */
function seedGroompressPendingCommission( groomerId ) {
	const rel = getFixtureEvalPath( 'e2e-seed-groompress-commission.php' );
	return wpEvalFile(
		rel,
		{
			E2E_GROOMER_ID: String( groomerId ),
		}
	).trim();
}

/**
 * @param {string} emailOrLogin Admin or groomer identifier.
 * @return {number}
 */
function resolveWpUserId( emailOrLogin ) {
	const rel = getFixtureEvalPath( 'e2e-resolve-user-id.php' );
	try {
		const out = wpEvalFile(
			rel,
			{
				E2E_RESOLVE_USER: String( emailOrLogin ),
			}
		).trim();
		const n   = parseInt( out, 10 );
		return Number.isNaN( n ) ? 0 : n;
	} catch ( e ) {
		return 0;
	}
}

/**
 * @param {number} groomerId WordPress user ID.
 * @return {number}
 */
function countGroompressPendingRows( groomerId ) {
	const rel = getFixtureEvalPath( 'e2e-count-groompress-pending.php' );
	try {
		const out = wpEvalFile(
			rel,
			{
				E2E_GROOMER_ID: String( groomerId ),
			}
		).trim();
		const n   = parseInt( out, 10 );
		return Number.isNaN( n ) ? -1 : n;
	} catch ( e ) {
		return -1;
	}
}

/**
 * @return {string} JSON from e2e-compliance-backup-required-vaccines.php
 */
function complianceBackupRequiredVaccines() {
	const rel = getFixtureEvalPath( 'e2e-compliance-backup-required-vaccines.php' );
	return wpEvalFile( rel ).trim();
}

/**
 * @param {string} snapshotJson JSON from complianceBackupRequiredVaccines.
 * @return {void}
 */
function complianceRestoreRequiredVaccines( snapshotJson ) {
	if ( ! snapshotJson || 'string' !== typeof snapshotJson ) {
		return;
	}
	const rel = getFixtureEvalPath( 'e2e-compliance-restore-required-vaccines.php' );
	wpEvalFile( rel, { E2E_COMPLIANCE_SNAPSHOT: snapshotJson } );
}

/**
 * Pet ID for the owner’s pending_payment boarding booking (matches Pay now row).
 *
 * @return {number}
 */
function complianceGetPetIdForPendingBooking() {
	const rel = getFixtureEvalPath( 'e2e-compliance-get-pet-for-pending-booking.php' );
	const out = wpEvalFile( rel ).trim();
	const n   = parseInt( out, 10 );
	return Number.isNaN( n ) ? 0 : n;
}

/**
 * @param {number} petId kf_pet ID.
 * @return {number} Rows deleted.
 */
function complianceDeletePetMedicalRecords( petId ) {
	const rel = getFixtureEvalPath( 'e2e-compliance-delete-pet-medical-records.php' );
	const out = wpEvalFile( rel, { E2E_PET_ID: String( petId ) } ).trim();
	const n   = parseInt( out, 10 );
	return Number.isNaN( n ) ? 0 : n;
}

/**
 * Set kf_required_vaccines (default Rabies via fixture).
 *
 * @return {string} stdout JSON
 */
function complianceSetRequiredVaccinesRabies() {
	const rel = getFixtureEvalPath( 'e2e-compliance-set-required-vaccines.php' );
	return wpEvalFile( rel ).trim();
}

/**
 * Set kf_required_vaccines to [ 'Rabies' ] (Compliance Gatekeeper E2E rule).
 *
 * @return {string} stdout JSON
 */
function complianceSetComplianceRule() {
	const rel = getFixtureEvalPath( 'e2e-set-compliance-rule.php' );
	return wpEvalFile( rel ).trim();
}

/**
 * @param {number} petId kf_pet ID.
 * @return {{ ok: boolean, pet_id: number, record_id: number, expires: string }}
 */
function complianceSeedRabiesValid( petId ) {
	const rel = getFixtureEvalPath( 'e2e-compliance-seed-rabies-valid.php' );
	const out = wpEvalFile(
		rel,
		{
			E2E_PET_ID: String( petId ),
		}
	).trim();
	return JSON.parse( out );
}

/**
 * Insert valid Rabies row (expiration_gmt +365 days, status active).
 *
 * @param {number} petId kf_pet ID.
 * @return {{ ok: boolean, pet_id: number, record_id: number, expires: string }}
 */
function complianceInjectValidRabies( petId ) {
	const rel = getFixtureEvalPath( 'e2e-inject-valid-rabies.php' );
	const out = wpEvalFile(
		rel,
		{
			E2E_PET_ID: String( petId ),
			E2E_OWNER_USER: process.env.E2E_OWNER_USER || '',
		}
	).trim();
	return JSON.parse( out );
}

/**
 * Restore kf_required_vaccines snapshot and delete seeded medical record by id.
 *
 * @param {string} snapshotJson JSON from complianceBackupRequiredVaccines.
 * @param {number} recordId      kf_medical_records id (0 to skip delete).
 * @return {void}
 */
function complianceGatekeeperTeardown( snapshotJson, recordId ) {
	const rel = getFixtureEvalPath( 'e2e-compliance-gatekeeper-teardown.php' );
	wpEvalFile(
		rel,
		{
			E2E_COMPLIANCE_SNAPSHOT: snapshotJson || '',
			E2E_RECORD_ID: String( recordId || 0 ),
		}
	);
}

/**
 * Portal/AJAX allow reaching checkout (WooCommerce still enforces compliance).
 *
 * @return {void}
 */
function complianceEnableE2eBypass() {
	const rel = getFixtureEvalPath( 'e2e-compliance-enable-e2e-bypass.php' );
	wpEvalFile( rel );
}

/**
 * @return {void}
 */
function complianceDisableE2eBypass() {
	const rel = getFixtureEvalPath( 'e2e-compliance-disable-e2e-bypass.php' );
	wpEvalFile( rel );
}

/**
 * Assert KennelFlow Vet draft encounter matches booking meta (Omni-Booking clinic → auto-SOAP).
 *
 * @param {number} bookingId          `kennelpress_booking` post ID.
 * @param {string} expectedSubjective Exact subjective text from the UI.
 * @return {void}
 */
function verifyAutoSoapDraft( bookingId, expectedSubjective ) {
	const rel = getFixtureEvalPath( 'e2e-verify-auto-soap-draft.php' );
	wpEvalFile(
		rel,
		{
			E2E_BOOKING_ID: String( bookingId ),
			E2E_EXPECTED_SUBJECTIVE: String( expectedSubjective ),
		}
	);
}

/**
 * Enable KennelFlow option so `GET /kennelflow/v1/public-clinicians` and KennelFlow Vet wizard show providers.
 *
 * @return {void}
 */
function enableClinicianSelectionForE2e() {
	const rel = getFixtureEvalPath( 'e2e-enable-clinician-selection.php' );
	wpEvalFile( rel );
}

/**
 * Create veterinarian user “Dr. E2E Test” with kf_public_bio / kf_specialties (stdout JSON).
 *
 * @return {string} JSON
 */
function createE2eTestClinician() {
	const rel = getFixtureEvalPath( 'e2e-create-test-clinician.php' );
	return wpEvalFile(
		rel,
		{
			E2E_VET_CLINICIAN_EMAIL: process.env.E2E_VET_CLINICIAN_EMAIL || '',
			E2E_VET_CLINICIAN_LOGIN: process.env.E2E_VET_CLINICIAN_LOGIN || '',
		}
	).trim();
}

/**
 * Remove test clinician, latest KennelFlow Vet booking for E2E owner pet(s), and disable clinician selection.
 *
 * @return {string} JSON stdout
 */
function teardownProviderBookingWizard() {
	const rel = getFixtureEvalPath( 'e2e-teardown-provider-booking-wizard.php' );
	return wpEvalFile(
		rel,
		{
			E2E_VET_CLINICIAN_EMAIL: process.env.E2E_VET_CLINICIAN_EMAIL || '',
			E2E_OWNER_USER: process.env.E2E_OWNER_USER || '',
		}
	).trim();
}

module.exports = {
	runWpCli,
	wpCliWorks,
	getFixtureEvalPath,
	wpEvalFile,
	getOption,
	setOption,
	setBoardingProductPrice,
	seedConfirmedOccupancy,
	cleanupOccupancySeed,
	getPostMeta,
	saveRevenueSnapshot,
	restoreRevenueSnapshot,
	seedCrmMedicalRecord,
	runCrmDailySweepCronHook,
	countCrmAuditEntries,
	cleanupCrmMedicalRecord,
	runWpCliCronEvent,
	getPetIdForOwner,
	assertEncounterSoapAndAudit,
	seedGroompressPendingCommission,
	resolveWpUserId,
	countGroompressPendingRows,
	complianceBackupRequiredVaccines,
	complianceRestoreRequiredVaccines,
	complianceGetPetIdForPendingBooking,
	complianceDeletePetMedicalRecords,
	complianceSetRequiredVaccinesRabies,
	complianceSetComplianceRule,
	complianceSeedRabiesValid,
	complianceInjectValidRabies,
	complianceGatekeeperTeardown,
	complianceEnableE2eBypass,
	complianceDisableE2eBypass,
	verifyAutoSoapDraft,
	enableClinicianSelectionForE2e,
	createE2eTestClinician,
	teardownProviderBookingWizard,
};

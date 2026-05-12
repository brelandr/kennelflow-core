/**
 * E2E: Automated CRM — `kf_daily_crm_sweep` → KennelFlow Vet audit log.
 *
 * Prerequisites:
 * - WP-CLI (`wp` on PATH, or `E2E_WP_CLI_PREFIX` for wp-env).
 * - KennelFlow Core + KennelFlow Vet active; `wp_kf_medical_records` has `expiration_gmt`; `wp_kennelflow_vet_audit_log` exists.
 * - `E2E_OWNER_USER` — email of a user who owns at least one `kf_pet` (same as other portal tests).
 *
 * Trigger: we run `wp eval-file e2e-run-crm-sweep.php`, which stubs `pre_wp_mail` and calls
 * `do_action( 'kf_daily_crm_sweep' )` — the same callback as `wp cron event run kf_daily_crm_sweep`
 * (WP-CLI cannot persist filters across separate processes, so plain `cron event run` often fails
 * mail in CI; use the fixture for reliable tests).
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const {
	wpCliWorks,
	runWpCli,
	seedCrmMedicalRecord,
	runCrmDailySweepCronHook,
	countCrmAuditEntries,
	cleanupCrmMedicalRecord,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';

test.describe( 'Automated CRM (kf_daily_crm_sweep)', () => {
	test( 'WP-Cron registers kf_daily_crm_sweep', () => {
		test.skip(
			! wpCliWorks(),
			'WP-CLI not available (install wp-cli or set E2E_WP_CLI_PREFIX for wp-env).'
		);

		const out = runWpCli( 'cron event list' );
		expect( out ).toMatch( /kf_daily_crm_sweep/ );
	} );

	test( 'seed medical row (30d) + sweep creates KennelFlow Vet audit entry', () => {
		test.skip(
			! wpCliWorks(),
			'WP-CLI not available (install wp-cli or set E2E_WP_CLI_PREFIX for wp-env).'
		);
		test.skip(
			! ownerUser,
			'Set E2E_OWNER_USER to a pet-owner account that owns at least one kf_pet.'
		);

		let seed;
		try {
			seed = seedCrmMedicalRecord();
		} catch ( e ) {
			throw new Error(
				`CRM seed failed (need KennelFlow Vet, kf_medical_records.expiration_gmt, and kf_pet for E2E_OWNER_USER): ${ String( e ) }`
			);
		}

		expect( seed.pet_id ).toBeGreaterThan( 0 );
		expect( seed.record_id ).toBeGreaterThan( 0 );

		const petId = seed.pet_id;
		const recordId = seed.record_id;

		try {
			const before = countCrmAuditEntries( petId );

			const sweepOut = runCrmDailySweepCronHook();
			expect( sweepOut ).toContain( 'OK' );

			const after = countCrmAuditEntries( petId );
			expect( after ).toBeGreaterThan( before );
			expect( after ).toBeGreaterThanOrEqual( 1 );
		} finally {
			cleanupCrmMedicalRecord( recordId );
		}
	} );
} );

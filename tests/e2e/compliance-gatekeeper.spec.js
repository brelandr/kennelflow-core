/**
 * E2E: Compliance gatekeeper — checkout blocks without valid Rabies; succeeds after seeding record.
 *
 * Prerequisites: same as booking-checkout.spec.js (WooCommerce, portal, owner credentials,
 * pending_payment boarding booking). Requires WP-CLI and kf_medical_records table.
 *
 * Sets option kf_compliance_gatekeeper_e2e_allow_noncompliant_checkout so the owner can
 * reach checkout while WooCommerce still enforces compliance.
 *
 * Fixtures: e2e-set-compliance-rule.php (kf_required_vaccines = [ 'Rabies' ]),
 * e2e-inject-valid-rabies.php (+365 days expiration_gmt, active), teardown restores snapshot + deletes row.
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginViaWpLogin,
	fillWooCheckoutBillingFields,
	clickWooCheckoutPlaceOrder,
} = require( './helpers' );
const {
	wpCliWorks,
	complianceBackupRequiredVaccines,
	complianceGetPetIdForPendingBooking,
	complianceDeletePetMedicalRecords,
	complianceSetComplianceRule,
	complianceInjectValidRabies,
	complianceGatekeeperTeardown,
	complianceEnableE2eBypass,
	complianceDisableE2eBypass,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';
const ownerPass = process.env.E2E_OWNER_PASS || '';
const portalPath = process.env.E2E_PORTAL_PATH || '/portal/';

test.describe.serial( 'Compliance gatekeeper (WooCommerce checkout)', () => {
	/** @type {string} */
	let vaccinesSnapshot = '';
	/** @type {number} */
	let petId = 0;
	/** @type {number} */
	let seededRecordId = 0;
	/** @type {string} */
	let bookingId = '';

	test.beforeAll( () => {
		test.skip(
			! wpCliWorks(),
			'WP-CLI not available (install wp or set E2E_WP_CLI_PREFIX for wp-env).'
		);
		test.skip(
			! ownerUser || ! ownerPass,
			'Set E2E_OWNER_USER and E2E_OWNER_PASS.'
		);

		complianceEnableE2eBypass();
		vaccinesSnapshot = complianceBackupRequiredVaccines();
		petId = complianceGetPetIdForPendingBooking();
		test.skip(
			petId < 1,
			'No pending_payment boarding booking for E2E_OWNER_USER — seed kf_bookings or run against a fresh owner fixture.'
		);

		complianceDeletePetMedicalRecords( petId );
		complianceSetComplianceRule();
	} );

	test.afterAll( () => {
		if ( ! wpCliWorks() ) {
			return;
		}
		complianceGatekeeperTeardown( vaccinesSnapshot, seededRecordId );
		complianceDisableE2eBypass();
	} );

	test( 'hard stop then happy path (inject Rabies, reload, confirmed)', async ( { page } ) => {
		await test.step( 'Hard stop: checkout halted or blocked without Rabies', async () => {
			await loginViaWpLogin( page, {
				userLogin: ownerUser,
				userPass: ownerPass,
			} );

			await page.goto( portalPath, { waitUntil: 'domcontentloaded' } );

			const payBtn = page
				.locator( 'button.kf-portal__pay[data-kf-pay-booking]' )
				.first();
			await expect( payBtn ).toBeVisible( { timeout: 30000 } );

			const id = await payBtn.getAttribute( 'data-kf-pay-booking' );
			expect( id ).toBeTruthy();
			bookingId = String( id );

			await Promise.all( [
				page.waitForURL( /checkout/, { timeout: 60000 } ),
				payBtn.click(),
			] );

			await page.waitForLoadState( 'networkidle' );

			await fillWooCheckoutBillingFields( page );

			const placeOrderBtn = page
				.getByRole( 'button', { name: /place order|pay/i } )
				.first();
			const disabledBeforeSubmit = await placeOrderBtn
				.isDisabled()
				.catch( () => false );

			const body = page.locator( 'body' );

			if ( disabledBeforeSubmit ) {
				await expect( body ).toContainText( /Rabies/i, { timeout: 10000 } );
			} else {
				await clickWooCheckoutPlaceOrder( page );
				await expect( body ).toContainText( /Checkout halted/i, {
					timeout: 90000,
				} );
				await expect( body ).toContainText( /Rabies/i, { timeout: 10000 } );
			}

			await expect( page ).not.toHaveURL( /order-received/, { timeout: 3000 } );
		} );

		await test.step( 'Happy path: valid Rabies row, reload checkout, place order, confirmed', async () => {
			const seed = complianceInjectValidRabies( petId );
			expect( seed.record_id ).toBeTruthy();
			seededRecordId = seed.record_id;

			await page.reload( { waitUntil: 'networkidle' } );

			await expect(
				page.getByText( /missing a valid Rabies vaccination|Rabies vaccination has expired/i )
			).toHaveCount( 0, { timeout: 15000 } );

			await fillWooCheckoutBillingFields( page );
			await clickWooCheckoutPlaceOrder( page );

			await page.waitForURL( /order-received|checkout\/order-received/, {
				timeout: 120000,
			} );

			await page.goto( portalPath, { waitUntil: 'networkidle' } );
			await page.locator( '[data-kf-portal]' ).waitFor( { state: 'visible' } );

			const row = page
				.locator( 'tr', { hasText: `Booking #${ bookingId }` } )
				.first();

			await expect( row ).toContainText( 'confirmed', { timeout: 120000 } );
		} );
	} );
} );

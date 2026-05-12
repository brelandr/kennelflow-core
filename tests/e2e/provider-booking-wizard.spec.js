/**
 * E2E: KennelFlow Vet public booking wizard — clinician selection + full boarding request.
 *
 * Prerequisites:
 * - WP-CLI (or `E2E_WP_CLI_PREFIX` for wp-env) to run fixtures in `tests/e2e/fixtures/`.
 * - KennelFlow Core + KennelFlow Vet active; a published page with `[kennelflow_vet_booking]` (set `E2E_KENNELFLOW_VET_BOOKING_PATH`, default `/booking/`).
 * - Pet owner with at least one pet and a location with an available room for the chosen dates (`E2E_OWNER_USER` / `E2E_OWNER_PASS`).
 * - Optional: `E2E_VET_CLINICIAN_EMAIL`, `E2E_VET_CLINICIAN_LOGIN` for the seeded veterinarian (defaults in fixture).
 * - Add-pet in wizard: `POST /wp-json/kennelflow/v1/me/pets` (JSON `title`, `X-WP-Nonce`); document uploads use `POST .../kennelflow/v1/compliance/upload` and require KennelFlow Vet protected uploads. Manual: Pet step shows Add a pet; after selection, vaccination rows and file upload when configured.
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const { loginViaWpLogin } = require( './helpers' );
const {
	wpCliWorks,
	enableClinicianSelectionForE2e,
	createE2eTestClinician,
	teardownProviderBookingWizard,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';
const ownerPass = process.env.E2E_OWNER_PASS || '';
const bookingPath = process.env.E2E_KENNELFLOW_VET_BOOKING_PATH || '/booking/';

test.describe( 'KennelFlow Vet booking wizard — provider selection', () => {
	test.beforeAll( () => {
		test.skip( ! wpCliWorks(), 'WP-CLI not available.' );
		enableClinicianSelectionForE2e();
		createE2eTestClinician();
	} );

	test.afterAll( () => {
		if ( ! wpCliWorks() ) {
			return;
		}
		teardownProviderBookingWizard();
	} );

	test( 'Choose Your Provider shows clinician profile; completes booking', async ( { page } ) => {
		test.skip(
			! ownerUser || ! ownerPass,
			'Set E2E_OWNER_USER and E2E_OWNER_PASS (pet owner with a pet and bookable room).'
		);

		await loginViaWpLogin( page, {
			userLogin: ownerUser,
			userPass: ownerPass,
		} );

		await page.goto( bookingPath, { waitUntil: 'domcontentloaded' } );

		await page.locator( '#kennelflow-vet-booking-wizard-root' ).waitFor( {
			state: 'visible',
			timeout: 30000,
		} );

		const locSel = page.locator( '#kennelflow-vet-bw-loc' );
		const firstLoc = await locSel
			.locator( 'option[value]:not([value=""])' )
			.first()
			.getAttribute( 'value' );
		test.skip(
			! firstLoc,
			'Need at least one KennelFlow Vet location term (facility).'
		);
		await locSel.selectOption( firstLoc );

		await page.getByRole( 'button', { name: 'Next' } ).click();

		const petSel = page.locator( '#kennelflow-vet-bw-pet' );
		const firstPet = await petSel
			.locator( 'option[value]:not([value="0"])' )
			.first()
			.getAttribute( 'value' );
		test.skip( ! firstPet, 'Need at least one pet for the owner account.' );
		await petSel.selectOption( firstPet );

		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Choose Your Provider' } )
		).toBeVisible( { timeout: 30000 } );

		await expect( page.getByText( 'Dr. E2E Test' ) ).toBeVisible( {
			timeout: 30000,
		} );
		await expect(
			page.locator( '.kennelflow-vet-bw-provider-specialties' )
		).toContainText( 'Surgery, Dentistry E2E' );
		await expect( page.locator( '.kennelflow-vet-bw-provider-bio-preview' ) ).toContainText(
			/E2E public bio/
		);

		await page
			.locator( '.kennelflow-vet-bw-provider-card--clinician' )
			.filter( { hasText: 'Dr. E2E Test' } )
			.first()
			.click();

		await page.getByRole( 'button', { name: 'Next' } ).click();

		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const startD = new Date();
		startD.setDate( startD.getDate() + 40 );
		const endD = new Date( startD );
		endD.setDate( endD.getDate() + 3 );

		const startLocal = `${ startD.getFullYear() }-${ pad(
			startD.getMonth() + 1
		) }-${ pad( startD.getDate() ) }T10:00`;
		const endLocal = `${ endD.getFullYear() }-${ pad(
			endD.getMonth() + 1
		) }-${ pad( endD.getDate() ) }T10:00`;

		await page.locator( '#kennelflow-vet-bw-start' ).fill( startLocal );
		await page.locator( '#kennelflow-vet-bw-end' ).fill( endLocal );

		await page.getByRole( 'button', { name: /check availability/i } ).click();

		await expect( page.getByRole( 'heading', { name: 'Choose a room' } ) ).toBeVisible( {
			timeout: 120000,
		} );

		const firstRoom = page.locator( 'input[name="kennelflow-vet-bw-room"]' ).first();
		await expect( firstRoom ).toBeVisible( { timeout: 15000 } );
		await firstRoom.click();

		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect(
			page.getByRole( 'heading', { name: /add-ons/i } )
		).toBeVisible( { timeout: 15000 } );

		await page.getByRole( 'button', { name: /submit request/i } ).click();

		await expect( page.getByRole( 'heading', { name: /thank you/i } ) ).toBeVisible( {
			timeout: 60000,
		} );
		await expect( page.locator( '.kennelflow-vet-bw-msg-ok' ) ).toContainText(
			/Booking request submitted/
		);
	} );
} );

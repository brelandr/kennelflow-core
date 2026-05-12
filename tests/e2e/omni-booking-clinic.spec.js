/**
 * E2E: Omni-Booking — Clinic intake + KennelFlow Vet auto-draft SOAP (kennelpress_booking_saved).
 *
 * Prerequisites:
 * - Administrator (E2E_ADMIN_USER / E2E_ADMIN_PASSWORD).
 * - KennelFlow Core + KennelPress + KennelFlow Vet; calendar bridge must expose Add booking.
 * - At least one kf_location, kf_pet, and clinic intake options (exam room and/or attending clinician) for the location.
 * - WP-CLI reachable from the test host (E2E_WP_CLI_PREFIX for wp-env) to run the verify fixture.
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const { loginViaWpLogin } = require( './helpers' );
const { wpCliWorks, verifyAutoSoapDraft } = require( './helpers-wp-cli' );

const adminUser = process.env.E2E_ADMIN_USER || '';
const adminPass =
	process.env.E2E_ADMIN_PASSWORD || process.env.E2E_ADMIN_PASS || '';

const REASON_TEXT = 'E2E Test: Limping on back left leg';

test.describe( 'Omni-Booking — Clinic + auto-SOAP', () => {
	test( 'modal switches to Clinical Intake; save creates draft encounter with Subjective', async ( {
		page,
	} ) => {
		test.skip(
			! adminUser || ! adminPass,
			'Set E2E_ADMIN_USER and E2E_ADMIN_PASSWORD (or E2E_ADMIN_PASS).'
		);

		await loginViaWpLogin( page, {
			userLogin: adminUser,
			userPass: adminPass,
		} );

		const calendarUrl =
			'/wp-admin/admin.php?page=kf-calendar';

		await page.goto( calendarUrl, { waitUntil: 'domcontentloaded' } );
		await page.locator( '#kf-admin-calendar-root' ).waitFor( {
			state: 'visible',
			timeout: 30000,
		} );

		const addBtn = page.getByRole( 'button', { name: /add booking/i } );
		await expect( addBtn ).toBeVisible( { timeout: 15000 } );
		await addBtn.click();

		await expect(
			page.getByRole( 'dialog', { name: /add booking/i } )
		).toBeVisible( { timeout: 15000 } );

		const locSel = page.locator( '#kf-bm-location' );
		const firstLoc = await locSel
			.locator( 'option[value]:not([value="0"])' )
			.first()
			.getAttribute( 'value' );
		test.skip(
			! firstLoc,
			'Need at least one kf_location for clinic booking.'
		);
		await locSel.selectOption( firstLoc );

		const petSel = page.locator( '#kf-bm-pet' );
		const firstPet = await petSel
			.locator( 'option[value]:not([value="0"])' )
			.first()
			.getAttribute( 'value' );
		test.skip(
			! firstPet,
			'Need at least one kf_pet for booking.'
		);
		await petSel.selectOption( firstPet );

		await page.locator( '#kf-bm-kind' ).selectOption( 'boarding' );

		await page.getByRole( 'tab', { name: /stay care sheet/i } ).click();
		await expect( page.locator( '#kf-bm-diet' ) ).toBeVisible( {
			timeout: 15000,
		} );

		await page.getByRole( 'tab', { name: /care instructions/i } ).click();
		await page.locator( '#kf-bm-kind' ).selectOption( 'clinic' );

		await expect(
			page.getByRole( 'tab', { name: /clinical intake/i } )
		).toBeVisible();
		await expect( page.locator( '#kf-bm-diet' ) ).toHaveCount( 0 );

		await page.waitForFunction(
			() => {
				const exam = document.querySelector( '#kf-bm-clinic-exam' );
				const clin = document.querySelector( '#kf-bm-clinic-clinician' );
				const hasOpt = ( sel ) =>
					sel &&
					Array.from( sel.options ).some(
						( o ) => o.value && o.value !== '0' && o.value !== ''
					);
				return hasOpt( exam ) || hasOpt( clin );
			},
			null,
			{ timeout: 45000 }
		);

		const examSel = page.locator( '#kf-bm-clinic-exam' );
		const clinSel = page.locator( '#kf-bm-clinic-clinician' );

		const firstExam = await examSel
			.locator( 'option[value]:not([value="0"]):not([value=""])' )
			.first()
			.getAttribute( 'value' )
			.catch( () => null );

		if ( firstExam ) {
			await examSel.selectOption( firstExam );
		} else {
			const firstClin = await clinSel
				.locator( 'option[value]:not([value="0"]):not([value=""])' )
				.first()
				.getAttribute( 'value' );
			test.skip(
				! firstClin,
				'No exam room or clinician for this location — seed kennels / staff in KennelPress intake.'
			);
			await clinSel.selectOption( firstClin );
		}

		await page.getByRole( 'tab', { name: /clinical intake/i } ).click();
		await expect( page.locator( '#kf-bm-reason' ) ).toBeVisible( {
			timeout: 15000,
		} );
		await page.locator( '#kf-bm-reason' ).fill( REASON_TEXT );

		const responsePromise = page.waitForResponse(
			( response ) => {
				const url = response.url();
				const req = response.request();
				return (
					url.includes( '/wp-json/kennelflow/v1/bookings' ) &&
					'POST' === req.method()
				);
			},
			{ timeout: 90000 }
		);

		await page.getByRole( 'button', { name: /save booking/i } ).click();

		const res = await responsePromise;
		expect( res.status() ).toBe( 201 );
		const body = await res.json();
		const bookingId = parseInt( body.id, 10 );
		expect( bookingId ).toBeGreaterThan( 0 );

		if ( wpCliWorks() ) {
			verifyAutoSoapDraft( bookingId, REASON_TEXT );
		}
	} );
} );

/**
 * E2E: Admin Calendar — drag a booking bar and assert PATCH succeeds (200).
 *
 * Prerequisites:
 * - Administrator (E2E_ADMIN_USER / E2E_ADMIN_PASSWORD).
 * - At least one booking visible on the calendar for the current week
 *   (wp_kf_bookings + pets); drag must land on a free kennel slot or the API
 *   returns 409 and the test should be skipped or data adjusted.
 *
 * Admin screen: Pets → Calendar (page slug kf-calendar).
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const { loginViaWpLogin } = require( './helpers' );

const adminUser = process.env.E2E_ADMIN_USER || '';
const adminPass =
	process.env.E2E_ADMIN_PASSWORD || process.env.E2E_ADMIN_PASS || '';

test.describe( 'Admin occupancy calendar — PATCH after drag', () => {
	test( 'PATCH /kennelflow/v1/calendar/booking/{id} returns 200', async ( {
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

		const eventCount = await page.locator( '.kf-cal-event' ).count();
		test.skip(
			eventCount < 1,
			'No calendar events in the current week — seed kf_bookings or change the week range.'
		);

		const eventBar = page.locator( '.kf-cal-event' ).first();
		await expect( eventBar ).toBeVisible( { timeout: 30000 } );

		const tracks = page.locator( '.kf-cal-track' );
		const trackCount = await tracks.count();
		test.skip(
			trackCount < 2,
			'Need at least two resource rows to drag to a different kennel.'
		);

		const targetTrack = tracks.nth( 1 );

		const responsePromise = page.waitForResponse(
			( response ) => {
				const url = response.url();
				const req = response.request();
				return (
					url.includes( '/wp-json/kennelflow/v1/calendar/booking/' ) &&
					'PATCH' === req.method()
				);
			},
			{ timeout: 60000 }
		);

		await eventBar.dragTo( targetTrack, { force: true } );

		const res = await responsePromise;
		expect( res.status() ).toBe( 200 );
	} );
} );

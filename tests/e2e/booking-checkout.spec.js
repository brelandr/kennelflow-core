/**
 * E2E: portal "Pay now" → WooCommerce checkout → booking confirmed in ledger.
 *
 * Prerequisites (staging / wp-env):
 * - WooCommerce active, checkout reachable, at least one gateway that yields
 *   order status `processing` or `completed` (KennelFlow confirms bookings on those).
 * - A page containing [kennelflow_dashboard] (set E2E_PORTAL_PATH).
 * - Logged-in pet owner with a boarding row in wp_kf_bookings with status
 *   `pending_payment` linked to their pets (set E2E_OWNER_USER / E2E_OWNER_PASS).
 *
 * Tests in this file are ordered: surge pricing (no payment) runs before the full
 * checkout test so the same pending_payment booking can be used.
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginViaWpLogin,
	completeWooCheckoutBestEffort,
} = require( './helpers' );
const {
	wpCliWorks,
	saveRevenueSnapshot,
	restoreRevenueSnapshot,
	setOption,
	setBoardingProductPrice,
	seedConfirmedOccupancy,
	cleanupOccupancySeed,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';
const ownerPass = process.env.E2E_OWNER_PASS || '';
const portalPath = process.env.E2E_PORTAL_PATH || '/portal/';

test.describe( 'Simulate High Occupancy Surge Pricing', () => {
	/** @type {ReturnType<typeof saveRevenueSnapshot>|null} */
	let snapshot = null;

	test.beforeAll( () => {
		if ( ! wpCliWorks() ) {
			return;
		}
		snapshot = saveRevenueSnapshot();
		cleanupOccupancySeed();
		setOption( 'kf_deposit_percentage', 0 );
		setOption( 'kf_total_kennel_capacity', 1 );
		setOption( 'kf_surge_enabled', 1 );
		setOption( 'kf_surge_threshold', 80 );
		setOption( 'kf_surge_increase_percentage', 20 );
		setBoardingProductPrice( 100 );
		seedConfirmedOccupancy();
	} );

	test.afterAll( () => {
		if ( ! wpCliWorks() || ! snapshot ) {
			return;
		}
		restoreRevenueSnapshot( snapshot );
	} );

	test( 'cart shows 1.2x surge (20% increase) and (High Demand Rate) at 100% occupancy', async ( {
		page,
	} ) => {
		test.skip(
			! wpCliWorks(),
			'WP-CLI not available (install wp-cli or set E2E_WP_CLI_PREFIX for wp-env).'
		);
		test.skip(
			! ownerUser || ! ownerPass,
			'Set E2E_OWNER_USER and E2E_OWNER_PASS to a pet-owner account with a pending_payment boarding booking.'
		);

		await loginViaWpLogin( page, {
			userLogin: ownerUser,
			userPass: ownerPass,
		} );

		await page.goto( portalPath, { waitUntil: 'domcontentloaded' } );

		const payBtn = page
			.locator( 'button.kf-portal__pay[data-kf-pay-booking]' )
			.first();
		await expect( payBtn ).toBeVisible( { timeout: 30000 } );

		await Promise.all( [
			page.waitForURL( /checkout/, { timeout: 60000 } ),
			payBtn.click(),
		] );

		await page.waitForLoadState( 'networkidle' );

		await expect( page.getByText( '(High Demand Rate)' ) ).toBeVisible( {
			timeout: 30000,
		} );

		await expect( page.locator( 'body' ) ).toContainText( /\b120[.,]00\b/ );
	} );
} );

test.describe( 'KennelFlow portal → checkout → confirmed booking', () => {
	test( 'Pay now redirects to checkout; after payment portal shows confirmed', async ( {
		page,
	} ) => {
		test.skip(
			! ownerUser || ! ownerPass,
			'Set E2E_OWNER_USER and E2E_OWNER_PASS to a pet-owner account with a pending_payment booking.'
		);

		await loginViaWpLogin( page, {
			userLogin: ownerUser,
			userPass: ownerPass,
		} );

		await page.goto( portalPath, { waitUntil: 'domcontentloaded' } );

		const payBtn = page
			.locator( 'button.kf-portal__pay[data-kf-pay-booking]' )
			.first();
		await expect( payBtn ).toBeVisible( { timeout: 30000 } );

		const bookingId = await payBtn.getAttribute( 'data-kf-pay-booking' );
		expect( bookingId ).toBeTruthy();

		await Promise.all( [
			page.waitForURL( /checkout/, { timeout: 60000 } ),
			payBtn.click(),
		] );

		await completeWooCheckoutBestEffort( page );

		// Ledger updates when order hits processing/completed — reload portal and assert that row.
		await page.goto( portalPath, { waitUntil: 'networkidle' } );
		await page.locator( '[data-kf-portal]' ).waitFor( { state: 'visible' } );

		const row = page
			.locator( 'tr', { hasText: `Booking #${ bookingId }` } )
			.first();
		await expect( row ).toContainText( 'confirmed', { timeout: 120000 } );
	} );
} );

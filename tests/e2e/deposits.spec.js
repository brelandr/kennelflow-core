/**
 * E2E: deposit checkout (20% due now) → order meta _kf_unpaid_balance → portal Pay remaining balance.
 *
 * Prerequisites:
 * - Same as booking-checkout (WooCommerce, portal, owner with pending_payment boarding).
 * - WP-CLI available (PATH or E2E_WP_CLI_PREFIX for wp-env).
 * - KennelPress (wp_kf_bookings table).
 * - Run after a fresh pending_payment seed, or alone: `npx playwright test tests/e2e/deposits.spec.js`
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginViaWpLogin,
	completeWooCheckoutBestEffort,
	parseOrderIdFromUrl,
} = require( './helpers' );
const {
	wpCliWorks,
	saveRevenueSnapshot,
	restoreRevenueSnapshot,
	setOption,
	setBoardingProductPrice,
	cleanupOccupancySeed,
	getPostMeta,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';
const ownerPass = process.env.E2E_OWNER_PASS || '';
const portalPath = process.env.E2E_PORTAL_PATH || '/portal/';

test.describe( 'Deposits — 20% due at checkout, unpaid meta, portal balance button', () => {
	/** @type {ReturnType<typeof saveRevenueSnapshot>|null} */
	let snapshot = null;

	test.beforeAll( () => {
		if ( ! wpCliWorks() ) {
			return;
		}
		snapshot = saveRevenueSnapshot();
		cleanupOccupancySeed();
		setOption( 'kf_total_kennel_capacity', 20 );
		setOption( 'kf_deposit_percentage', 20 );
		setBoardingProductPrice( 100 );
	} );

	test.afterAll( () => {
		if ( ! wpCliWorks() || ! snapshot ) {
			return;
		}
		restoreRevenueSnapshot( snapshot );
	} );

	test( 'checkout total is 20% of standard rate; completed order has _kf_unpaid_balance; portal shows Pay remaining balance', async ( {
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

		const bookingId = await payBtn.getAttribute( 'data-kf-pay-booking' );
		expect( bookingId ).toBeTruthy();

		await Promise.all( [
			page.waitForURL( /checkout/, { timeout: 60000 } ),
			payBtn.click(),
		] );

		await page.waitForLoadState( 'networkidle' );

		const totalLocator = page
			.locator(
				'.cart_totals .order-total .amount, .cart_totals .order-total td, tr.order-total .amount, .wc-block-components-totals-footer-item .wc-block-formatted-money-amount'
			)
			.first();
		await expect( totalLocator ).toBeVisible( { timeout: 30000 } );
		await expect( totalLocator ).toContainText( /\b20[.,]00\b/ );

		await completeWooCheckoutBestEffort( page );

		const orderId = parseOrderIdFromUrl( page.url() );
		expect( orderId ).toBeTruthy();

		const unpaid = getPostMeta( orderId, '_kf_unpaid_balance' );
		expect( unpaid ).not.toBe( '' );
		expect( parseFloat( unpaid ) ).toBeCloseTo( 80, 1 );

		await page.goto( portalPath, { waitUntil: 'networkidle' } );
		await page.locator( '[data-kf-portal]' ).waitFor( { state: 'visible' } );

		const row = page
			.locator( 'tr', { hasText: `Booking #${ bookingId }` } )
			.first();
		await expect( row ).toContainText( 'confirmed', { timeout: 120000 } );

		const balanceBtn = page.locator( '[data-kf-pay-balance]' ).first();
		await expect( balanceBtn ).toBeVisible( { timeout: 60000 } );
		await expect( balanceBtn ).toContainText( /remaining balance/i );
	} );
} );

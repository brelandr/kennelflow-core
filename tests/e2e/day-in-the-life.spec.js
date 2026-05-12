/**
 * Phase 3 smoke: Surge pricing (Revenue Optimizer), calendar PATCH + IoT notes,
 * KennelFlow Vet AI SOAP (mocked) + audit, GroomPress commission payout.
 *
 * Prerequisites: `npx wp-env start` (or any WP with plugins), Playwright `E2E_BASE_URL`,
 * `E2E_WP_CLI_PREFIX` when using wp-env (e.g. `npx wp-env run tests-cli`).
 *
 * Env:
 * - E2E_OWNER_USER / E2E_OWNER_PASS — portal owner with pending_payment boarding booking
 * - E2E_ADMIN_USER / E2E_ADMIN_PASSWORD — administrator
 * - E2E_PORTAL_PATH — optional (default `/portal/`)
 *
 * Notes:
 * - Step 2 asserts the calendar REST PATCH succeeds. Smart Lock unlock is a server-side
 *   `wp_remote_post` to `iot_smart_lock_api_url` and is not visible in the browser.
 * - Step 2 skips if no `.kf-cal-event` in the current week (seed bookings or adjust dates).
 * - Step 3 skips if `sharedPetId` is 0 or KennelFlow Vet AI assets are missing.
 *
 * @package KennelFlow
 */

const { test, expect } = require( '@playwright/test' );
const { loginViaWpLogin } = require( './helpers' );
const {
	wpCliWorks,
	saveRevenueSnapshot,
	restoreRevenueSnapshot,
	setOption,
	setBoardingProductPrice,
	seedConfirmedOccupancy,
	cleanupOccupancySeed,
	getPetIdForOwner,
	assertEncounterSoapAndAudit,
	seedGroompressPendingCommission,
	resolveWpUserId,
	countGroompressPendingRows,
} = require( './helpers-wp-cli' );

const ownerUser = process.env.E2E_OWNER_USER || '';
const ownerPass = process.env.E2E_OWNER_PASS || '';
const adminUser = process.env.E2E_ADMIN_USER || '';
const adminPass =
	process.env.E2E_ADMIN_PASSWORD || process.env.E2E_ADMIN_PASS || '';
const portalPath = process.env.E2E_PORTAL_PATH || '/portal/';

const MOCK_SOAP = {
	subjective: 'E2E Subj',
	objective: 'E2E Obj',
	assessment: 'E2E Ass',
	plan: 'E2E Plan',
};

test.describe.serial( 'Phase 3: Day in the life (smoke)', () => {
	let revenueSnapshot = null;
	let sharedPetId = 0;

	test.beforeAll( () => {
		if ( ! wpCliWorks() ) {
			return;
		}
		revenueSnapshot = saveRevenueSnapshot();
		cleanupOccupancySeed();
		setOption( 'kf_deposit_percentage', 0 );
		setOption( 'kf_total_kennel_capacity', 1 );
		setOption( 'kf_surge_enabled', 1 );
		setOption( 'kf_surge_threshold', 80 );
		setOption( 'kf_surge_increase_percentage', 20 );
		setBoardingProductPrice( 100 );
		seedConfirmedOccupancy();
		if ( ownerUser ) {
			sharedPetId = getPetIdForOwner();
		}
	} );

	test.afterAll( () => {
		if ( ! wpCliWorks() || ! revenueSnapshot ) {
			return;
		}
		restoreRevenueSnapshot( revenueSnapshot );
	} );

	test( 'Step 1: Boarding checkout shows surge (High Demand Rate)', async ( { page } ) => {
		test.skip(
			! wpCliWorks(),
			'WP-CLI not available (set E2E_WP_CLI_PREFIX for wp-env).'
		);
		test.skip(
			! ownerUser || ! ownerPass,
			'Set E2E_OWNER_USER and E2E_OWNER_PASS.'
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

		await page.context().clearCookies();
	} );

	test( 'Step 2: Admin calendar drag — PATCH succeeds', async ( { page } ) => {
		test.skip( ! adminUser || ! adminPass, 'Set E2E_ADMIN_USER and E2E_ADMIN_PASSWORD.' );

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
			'No calendar events this week — add kf_bookings + booking posts or change week.'
		);

		const tracks = page.locator( '.kf-cal-track' );
		const trackCount = await tracks.count();
		test.skip(
			trackCount < 2,
			'Need at least two resource rows to drag between kennels.'
		);

		const eventBar = page.locator( '.kf-cal-event' ).first();
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
		expect( res.status() ).toBeLessThan( 500 );
	} );

	test( 'Step 3: Pet EMR — mocked AI SOAP + save + DB audit', async ( { page } ) => {
		test.skip( ! adminUser || ! adminPass, 'Admin credentials required.' );
		test.skip(
			! wpCliWorks(),
			'WP-CLI required for DB assertions.'
		);
		test.skip(
			sharedPetId < 1,
			'Could not resolve pet ID (E2E_OWNER_USER must own a kf_pet).'
		);

		await page.route( '**/wp-json/kennelflow-vet/v1/ai/soap-parse**', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( MOCK_SOAP ),
			} );
		} );

		await loginViaWpLogin( page, {
			userLogin: adminUser,
			userPass: adminPass,
		} );

		await page.goto(
			`/wp-admin/post.php?post=${ sharedPetId }&action=edit`,
			{ waitUntil: 'domcontentloaded' }
		);

		const dictRoot = page.locator( '#kennelflow-vet-ai-dictation-root' );
		const hasDict = await dictRoot
			.waitFor( { state: 'visible', timeout: 15000 } )
			.then( () => true )
			.catch( () => false );
		test.skip(
			! hasDict,
			'KennelFlow Vet AI dictation not on screen (build kennelflow_vet assets: npm run build).'
		);

		await page.waitForFunction(
			() =>
				typeof window !== 'undefined' &&
				window.kennelflowVetAiDictation &&
				typeof window.kennelflowVetAiDictation.restUrl === 'string',
			null,
			{ timeout: 30000 }
		);

		await page.evaluate( async () => {
			const cfg = window.kennelflowVetAiDictation;
			const fd = new FormData();
			fd.append(
				'file',
				new Blob( [ 'e2e-audio' ], { type: 'audio/webm' } ),
				'e2e.webm'
			);
			const res = await fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': cfg.nonce,
				},
				body: fd,
			} );
			const body = await res.json();
			[ 'subjective', 'objective', 'assessment', 'plan' ].forEach( ( k ) => {
				const el = document.getElementById( 'kennelflow-vet-encounter-new-' + k );
				if ( el ) {
					el.value = body[ k ] != null ? String( body[ k ] ) : '';
				}
			} );
		} );

		const gmt = new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' );
		await page
			.locator( 'input[name="kennelflow_vet_encounter_new_gmt"]' )
			.fill( gmt );
		await page.locator( 'input[name="kennelflow_vet_encounter_add"]' ).click();

		const publish = page.locator( '#publish' ).first();
		await expect( publish ).toBeVisible( { timeout: 15000 } );
		await publish.click();

		await page.waitForLoadState( 'networkidle' );

		assertEncounterSoapAndAudit( sharedPetId, MOCK_SOAP.subjective );
	} );

	test( 'Step 4: Groomer Earnings — Mark All as Paid', async ( { page } ) => {
		test.skip( ! adminUser || ! adminPass, 'Admin credentials required.' );
		test.skip( ! wpCliWorks(), 'WP-CLI required to seed commission.' );

		const groomerId =
			parseInt( process.env.E2E_GROOMER_ID || '0', 10 ) ||
			resolveWpUserId( adminUser );
		test.skip(
			groomerId < 1,
			'Could not resolve groomer user id (set E2E_GROOMER_ID or valid E2E_ADMIN_USER).'
		);

		let seedJson = '';
		try {
			seedJson = seedGroompressPendingCommission( groomerId );
		} catch ( e ) {
			test.skip( true, `GroomPress seed failed: ${ String( e ) }` );
		}
		expect( seedJson ).toMatch( /"ok"\s*:\s*true/ );

		await loginViaWpLogin( page, {
			userLogin: adminUser,
			userPass: adminPass,
		} );

		const earningsUrl =
			'/wp-admin/admin.php?page=groompress-earnings';

		await page.goto( earningsUrl, { waitUntil: 'domcontentloaded' } );

		const markBtn = page.getByRole( 'button', {
			name: 'Mark All as Paid',
		} );
		await expect( markBtn.first() ).toBeVisible( { timeout: 30000 } );

		page.once( 'dialog', ( dialog ) => {
			dialog.accept();
		} );

		await markBtn.first().click();

		await expect(
			page.getByText( 'Groomer commissions successfully marked as paid.' )
		).toBeVisible( { timeout: 30000 } );

		await page.reload( { waitUntil: 'networkidle' } );

		await expect(
			page.getByText( 'Groomer commissions successfully marked as paid.' )
		).toHaveCount( 0 );

		expect( countGroompressPendingRows( groomerId ) ).toBe( 0 );
	} );
} );

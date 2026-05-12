/**
 * Shared Playwright helpers for KennelFlow Core E2E.
 *
 * @package KennelFlow
 */

const { expect } = require( '@playwright/test' );

/**
 * Log in via wp-login.php (cookie-based session).
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {{ userLogin: string, userPass: string }} creds Credentials.
 * @return {Promise<void>}
 */
async function loginViaWpLogin( page, { userLogin, userPass } ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.locator( '#user_login' ).fill( userLogin );
	await page.locator( '#user_pass' ).fill( userPass );
	await page.locator( '#wp-submit' ).click();
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
}

/**
 * Fill WooCommerce checkout billing + payment (COD if available). Does not submit.
 *
 * @param {import('@playwright/test').Page} page Page (on checkout URL).
 * @return {Promise<void>}
 */
async function fillWooCheckoutBillingFields( page ) {
	// Blocks checkout: billing fields often use input names like billing-first_name.
	const first = page.locator(
		'input[name="billing_first_name"], #billing_first_name'
	).first();
	if ( await first.isVisible( { timeout: 5000 } ).catch( () => false ) ) {
		await first.fill( 'E2E' );
	}
	const last = page.locator(
		'input[name="billing_last_name"], #billing_last_name'
	).first();
	if ( await last.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await last.fill( 'Test' );
	}
	const email = page.locator(
		'input[name="billing_email"], #billing_email'
	).first();
	if ( await email.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		const v = await email.inputValue().catch( () => '' );
		if ( ! v ) {
			await email.fill( 'e2e@example.com' );
		}
	}
	const phone = page.locator(
		'input[name="billing_phone"], #billing_phone'
	).first();
	if ( await phone.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await phone.fill( '5550100' );
	}
	const address = page.locator(
		'input[name="billing_address_1"], #billing_address_1'
	).first();
	if ( await address.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await address.fill( '123 Test St' );
	}
	const city = page.locator( 'input[name="billing_city"], #billing_city' ).first();
	if ( await city.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await city.fill( 'Testville' );
	}
	const postcode = page.locator(
		'input[name="billing_postcode"], #billing_postcode'
	).first();
	if ( await postcode.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await postcode.fill( '90210' );
	}

	// Country / state (selects) — fill only if empty.
	const country = page.locator( '#billing_country' ).first();
	if ( await country.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		const cur = await country.inputValue().catch( () => '' );
		if ( ! cur ) {
			await country.selectOption( { label: / united states / i } ).catch( () => {} );
		}
	}
	const state = page.locator( '#billing_state' ).first();
	if ( await state.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		const tag = await state.evaluate( ( el ) => el.tagName.toLowerCase() );
		if ( 'select' === tag ) {
			const opts = await state.locator( 'option' ).count();
			if ( opts > 1 ) {
				await state.selectOption( { index: 1 } ).catch( () => {} );
			}
		} else {
			await state.fill( 'CA' ).catch( () => {} );
		}
	}

	// Payment method: prefer COD if present.
	const cod = page.locator(
		'input[name="payment_method"][value="cod"], #payment_method_cod'
	).first();
	if ( await cod.isVisible( { timeout: 3000 } ).catch( () => false ) ) {
		await cod.click( { force: true } ).catch( () => {} );
	}
}

/**
 * Click Place order / Pay on checkout (after {@see fillWooCheckoutBillingFields}).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<void>}
 */
async function clickWooCheckoutPlaceOrder( page ) {
	const placeBtn = page.getByRole( 'button', { name: / place order | pay / i } ).first();
	await placeBtn.click();
}

/**
 * Complete WooCommerce checkout (classic or block) with best-effort field fills.
 * Prefer Cash on Delivery when available so the order can reach processing/completed.
 *
 * @param {import('@playwright/test').Page} page Page (on checkout URL).
 * @return {Promise<void>}
 */
async function completeWooCheckoutBestEffort( page ) {
	await fillWooCheckoutBillingFields( page );
	await clickWooCheckoutPlaceOrder( page );

	await page.waitForURL(
		/order-received|order-pay|checkout\/order-received/,
		{
			timeout: 120000,
		}
	);
}

/**
 * Parse WooCommerce order ID from order-received / thank-you URL.
 *
 * @param {string} url Full URL.
 * @return {number|null}
 */
function parseOrderIdFromUrl( url ) {
	const m = url.match( /order-received\/(\d+)/ );
	if ( m ) {
		return parseInt( m[ 1 ], 10 );
	}
	try {
		const u = new URL( url );
		const o = u.searchParams.get( 'order-received' );
		if ( o ) {
			return parseInt( o, 10 );
		}
	} catch ( e ) {
		return null;
	}
	return null;
}

module.exports = {
	loginViaWpLogin,
	fillWooCheckoutBillingFields,
	clickWooCheckoutPlaceOrder,
	completeWooCheckoutBestEffort,
	parseOrderIdFromUrl,
};

/**
 * Optional Hub calendar debug logging (WP_DEBUG or `ltkf_calendar_debug` filter).
 *
 * @package KennelFlow
 */

/**
 * Whether verbose calendar diagnostics / console logging is enabled.
 *
 * @return {boolean}
 */
export function isCalendarDebugEnabled() {
	const settings =
		typeof window !== 'undefined' && window.kfCalendarSettings
			? window.kfCalendarSettings
			: {};
	if ( settings.debug || settings.force_debug_log || settings.show_debug_panel ) {
		return true;
	}
	const diag = settings.add_booking_diagnostics;
	return !!( diag && diag.debug );
}

/**
 * Always-on trace for admin troubleshooting (console.warn).
 *
 * @param {string} label
 * @param {unknown} [data]
 * @return {void}
 */
export function logCalendarTrace( label, data ) {
	const prefix = '[KennelFlow Calendar]';
	if ( undefined === data ) {
		// eslint-disable-next-line no-console
		console.warn( `${ prefix } ${ label }` );
		return;
	}
	// eslint-disable-next-line no-console
	console.warn( `${ prefix } ${ label }`, data );
}

/**
 * @param {string} label
 * @param {unknown} [data]
 * @return {void}
 */
export function logCalendarDebug( label, data ) {
	if ( ! isCalendarDebugEnabled() ) {
		return;
	}
	logCalendarTrace( label, data );
}

/**
 * @return {boolean}
 */
export function shouldShowCalendarDebugPanel() {
	const settings =
		typeof window !== 'undefined' && window.kfCalendarSettings
			? window.kfCalendarSettings
			: {};
	return !! settings.show_debug_panel;
}

/**
 * @param {unknown} err
 * @param {string}  fallback
 * @return {string}
 */
export function formatApiFetchError( err, fallback ) {
	if ( ! err || 'object' !== typeof err ) {
		return fallback;
	}
	const message =
		'string' === typeof err.message && err.message.trim() !== ''
			? err.message.trim()
			: fallback;
	const code =
		'string' === typeof err.code && err.code
			? err.code
			: err.data &&
			  'object' === typeof err.data &&
			  'string' === typeof err.data.code
			? err.data.code
			: '';
	const status =
		err.data &&
		'object' === typeof err.data &&
		err.data.status
			? err.data.status
			: '';
	let out = message;
	if ( code ) {
		out += ` (${ code })`;
	}
	if ( status ) {
		out += ` [HTTP ${ status }]`;
	}
	return out;
}

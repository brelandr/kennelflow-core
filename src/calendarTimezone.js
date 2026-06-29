/**
 * Site timezone helpers for Hub calendar display (storage remains UTC).
 *
 * @package KennelFlow
 */

/**
 * IANA timezone from PHP (`kfCalendarSettings.site_timezone`).
 *
 * @return {string}
 */
export function getSiteTimezone() {
	const settings =
		typeof window !== 'undefined' && window.kfCalendarSettings
			? window.kfCalendarSettings
			: {};
	const tz =
		settings.site_timezone && 'string' === typeof settings.site_timezone
			? settings.site_timezone.trim()
			: '';
	return tz || 'UTC';
}

/**
 * Short label for toolbar / popovers (e.g. "EST" or "America/New_York").
 *
 * @return {string}
 */
export function getSiteTimezoneLabel() {
	const tz = getSiteTimezone();
	if ( 'UTC' === tz ) {
		return 'UTC';
	}
	try {
		const parts = new Intl.DateTimeFormat( undefined, {
			timeZone: tz,
			timeZoneName: 'short',
		} ).formatToParts( new Date() );
		const name = parts.find( ( p ) => 'timeZoneName' === p.type );
		if ( name && name.value ) {
			return name.value;
		}
	} catch ( e ) {
		void e;
	}
	return tz;
}

/**
 * @param {string} mysqlUtc MySQL datetime UTC.
 * @param {Intl.DateTimeFormatOptions} [options]
 * @return {string}
 */
export function formatUtcMysqlInSiteTimezone( mysqlUtc, options = {} ) {
	if ( ! mysqlUtc || 'string' !== typeof mysqlUtc ) {
		return '—';
	}
	const t = mysqlUtc.includes( 'T' ) ? mysqlUtc : mysqlUtc.replace( ' ', 'T' );
	const d = new Date( t.endsWith( 'Z' ) ? t : `${ t }Z` );
	if ( Number.isNaN( d.getTime() ) ) {
		return '—';
	}
	const tz = getSiteTimezone();
	try {
		return d.toLocaleString( undefined, {
			...options,
			timeZone: tz,
		} );
	} catch ( e ) {
		void e;
		return d.toLocaleString( undefined, options );
	}
}

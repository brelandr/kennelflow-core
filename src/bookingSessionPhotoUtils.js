/**
 * Helpers for booking session photo settings on the Hub calendar.
 *
 * @package KennelFlow
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {object} settings kfCalendarSettings
 * @param {object} booking  Calendar booking row
 * @return {object|null}
 */
export function resolveBookingSessionPhotoSettings( settings, booking ) {
	if ( ! booking || ! settings || 'object' !== typeof settings ) {
		return null;
	}

	const kindRaw = String( booking.booking_kind || '' )
		.trim()
		.toLowerCase();
	const kind = kindRaw || 'boarding';

	const byKind = settings.booking_session_photos;
	if ( byKind && 'object' === typeof byKind ) {
		const spoke = byKind[ kind ];
		if ( spoke && 'object' === typeof spoke && spoke.active ) {
			return normalizePhotoSettings( spoke );
		}
	}

	if ( 'grooming' === kind && settings.groompro_session_photos?.active ) {
		return normalizePhotoSettings( {
			...settings.groompro_session_photos,
			media_kinds: defaultGroomingMediaKinds(),
		} );
	}

	return null;
}

/**
 * @param {object} raw Raw spoke settings.
 * @return {object}
 */
export function normalizePhotoSettings( raw ) {
	const mediaKinds = Array.isArray( raw.media_kinds ) ? raw.media_kinds : defaultGroomingMediaKinds();

	return {
		active: !! raw.active,
		can_upload: !! ( raw.can_upload ?? raw.enabled ),
		rest_ns: raw.rest_ns || 'kennelflow-groom-pro/v1',
		heading: raw.heading || __( 'Session photos', 'kennelflow-core' ),
		media_kinds: mediaKinds
			.map( ( row ) => ( {
				key: String( row.key || '' ).trim(),
				label: String( row.label || row.key || '' ).trim(),
				takeLabel: String( row.takeLabel || row.take_label || '' ).trim(),
				chooseLabel: String( row.chooseLabel || row.choose_label || '' ).trim(),
			} ) )
			.filter( ( row ) => '' !== row.key ),
	};
}

/**
 * @return {object[]}
 */
export function defaultGroomingMediaKinds() {
	return [
		{
			key: 'before',
			label: __( 'Before', 'kennelflow-core' ),
			takeLabel: __( 'Take before photo', 'kennelflow-core' ),
			chooseLabel: __( 'Choose before photo', 'kennelflow-core' ),
		},
		{
			key: 'after',
			label: __( 'After', 'kennelflow-core' ),
			takeLabel: __( 'Take after photo', 'kennelflow-core' ),
			chooseLabel: __( 'Choose after photo', 'kennelflow-core' ),
		},
	];
}

/**
 * @param {object} settings Normalized photo settings.
 * @return {string}
 */
export function sessionPhotoRestBase( settings ) {
	const ns =
		settings && settings.rest_ns && 'string' === typeof settings.rest_ns
			? settings.rest_ns
			: 'kennelflow-groom-pro/v1';
	return '/' + String( ns ).replace( /^\/+|\/+$/g, '' );
}

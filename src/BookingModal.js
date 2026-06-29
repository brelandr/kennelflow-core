/**
 * Add booking modal: Omni-Booking (context-aware intake). POST targets KennelFlow
 * `POST /kennelflow/v1/bookings`, which proxies to Kennel Press.
 *
 * @package KennelFlow
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState, useCallback, useRef, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DateTime } from 'luxon';

import { BookingDatetimePicker } from './BookingDatetimePicker';
import {
	formatApiFetchError,
	isCalendarDebugEnabled,
	logCalendarTrace,
} from './calendarDebug';
import { renderInBodyPortal } from './calendarPortal';

/**
 * Avoid "Dr. Dr. Smith" when the directory name already includes a title.
 *
 * @param {string} rawName Clinician display name.
 * @return {string}
 */
function formatClinicianTitleForWarning( rawName ) {
	const s = String( rawName ).trim();
	if ( '' === s ) {
		return '';
	}
	if ( /^(dr\.?|doctor)\b/i.test( s ) ) {
		return s;
	}
	return sprintf(
		/* translators: %s: clinician display name without title */
		__( 'Dr. %s', 'kennelflow-core' ),
		s
	);
}

const ROSTER_WEEK_KEYS = [
	'mon',
	'tue',
	'wed',
	'thu',
	'fri',
	'sat',
	'sun',
];

/**
 * @param {import('luxon').DateTime} dt
 * @return {string}
 */
function weekdayKeyFromLuxon( dt ) {
	if ( ! dt || ! dt.isValid ) {
		return 'mon';
	}
	const w = dt.weekday;
	return ROSTER_WEEK_KEYS[ w - 1 ] || 'mon';
}

/**
 * @param {Record<string, Record<string, { start?: string, end?: string }>>} roster
 * @param {string}                                                                 dayKey
 * @return {number[]}
 */
function getRosterLocationIdsForDay( roster, dayKey ) {
	if ( ! roster || 'object' !== typeof roster ) {
		return [];
	}
	const out = [];
	for ( const locKey of Object.keys( roster ) ) {
		const lid = parseInt( locKey, 10 ) || 0;
		if ( lid < 1 ) {
			continue;
		}
		const block = roster[ locKey ];
		const row = block && block[ dayKey ];
		if (
			row &&
			'string' === typeof row.start &&
			'string' === typeof row.end &&
			row.start.trim() &&
			row.end.trim()
		) {
			out.push( lid );
		}
	}
	out.sort( ( a, b ) => a - b );
	return out;
}

/**
 * @param {Record<string, Record<string, { start?: string, end?: string }>>} roster
 * @param {string}                                                                 ianaZone
 * @param {{ id: number|string, title?: { rendered?: string } }[]}                 locationsList
 * @return {number}
 */
function pickLocationForTodayFromRoster( roster, ianaZone, locationsList ) {
	let z = ianaZone;
	let now = DateTime.now().setZone( z );
	if ( ! now.isValid ) {
		z = 'UTC';
		now = DateTime.now().setZone( z );
	}
	const dayKey = weekdayKeyFromLuxon( now );
	const ids = getRosterLocationIdsForDay( roster, dayKey );
	if ( ids.length < 1 ) {
		return 0;
	}
	const valid = new Set(
		locationsList.map( ( l ) => parseInt( l.id, 10 ) || 0 )
	);
	for ( const id of ids ) {
		if ( valid.has( id ) ) {
			return id;
		}
	}
	return ids[ 0 ];
}

/**
 * @param {{ id: number|string, title?: { rendered?: string } }[]} locationsList
 * @param {number}                                                   locId
 * @return {string}
 */
function getLocationDisplayTitle( locationsList, locId ) {
	const r = locationsList.find( ( l ) => parseInt( l.id, 10 ) === locId );
	if ( r && r.title && r.title.rendered ) {
		return String( r.title.rendered );
	}
	return '#' + String( locId );
}

/**
 * Pet dropdown label: "Pet name · Owner name" (matches calendar event titles).
 *
 * @param {{ id: number|string, title?: { rendered?: string }, owner_name?: string }} pet
 * @return {string}
 */
function formatPetSelectLabel( pet ) {
	const petName =
		pet.title && pet.title.rendered && String( pet.title.rendered ).trim() !== ''
			? String( pet.title.rendered ).trim()
			: `#${ pet.id }`;
	const ownerName =
		pet.owner_name && String( pet.owner_name ).trim() !== ''
			? String( pet.owner_name ).trim()
			: '';
	if ( '' === ownerName ) {
		return petName;
	}
	return sprintf(
		/* translators: 1: pet name, 2: owner display name */
		__( '%1$s · %2$s', 'kennelflow-core' ),
		petName,
		ownerName
	);
}

/**
 * @param {object}   props
 * @param {boolean}  props.open
 * @param {Function} props.onClose
 * @param {Function} [props.onSaved] Optional extra callback after save (calendar uses query invalidation).
 * @param {Function} [props.onError] Optional callback when modal render fails.
 * @param {{ start: string, end: string }} props.weekRange UTC Y-m-d bounds from calendar.
 * @param {string} [props.defaultBookingKind] Default kind when modal opens (e.g. grooming).
 * @param {'booking'|'walk_in'} [props.mode] Walk-in opens with now + checked-in defaults.
 */
export function BookingModal( { open, onClose, onSaved, onError, weekRange, defaultBookingKind = '', mode = 'booking' } ) {
	const queryClient = useQueryClient();
	const settings =
		typeof window !== 'undefined' && window.kfCalendarSettings
			? window.kfCalendarSettings
			: {};

	const siteTimezone =
		settings.site_timezone && 'string' === typeof settings.site_timezone
			? settings.site_timezone
			: 'UTC';

	const kennelpressBookings = settings.kennelpress_bookings_url || '';
	const bookingsCreatePath =
		settings.bookings_create_path || '/kennelflow/v1/bookings';
	const resolvedDefaultKind =
		defaultBookingKind && String( defaultBookingKind ).trim() !== ''
			? String( defaultBookingKind ).trim().toLowerCase()
			: settings.default_booking_kind &&
			  String( settings.default_booking_kind ).trim() !== ''
			? String( settings.default_booking_kind ).trim().toLowerCase()
			: 'boarding';
	const boardingRestBase = useMemo( () => {
		const raw =
			settings.kennelflow_boarding_rest_base &&
			'string' === typeof settings.kennelflow_boarding_rest_base
				? settings.kennelflow_boarding_rest_base
				: 'kennelpress/v1';
		return String( raw ).replace( /^\/+|\/+$/g, '' );
	}, [ settings.kennelflow_boarding_rest_base ] );

	const isWalkInMode = 'walk_in' === mode;
	const walkInKind =
		'clinic' === resolvedDefaultKind ? 'clinic' : resolvedDefaultKind || 'clinic';

	const modalTitle = isWalkInMode
		? __( 'Add walk-in', 'kennelflow-core' )
		: __( 'Add booking', 'kennelflow-core' );

	const modalEnabled = open && !! kennelpressBookings;

	const bootstrapQuery = useQuery( {
		queryKey: [ 'resources', 'bootstrap' ],
		queryFn: async () => {
			let fac = null;
			try {
				fac = await apiFetch( {
					path: `/${ boardingRestBase }/facility-settings`,
				} );
			} catch {
				fac = null;
			}
			const locs = await apiFetch( {
				path: '/wp/v2/kf-locations?per_page=100&status=publish,draft,pending,private',
			} );
			return {
				facility: fac ?? null,
				locations: Array.isArray( locs ) ? locs : [],
			};
		},
		enabled: modalEnabled,
		staleTime: 60_000,
	} );

	const petsQuery = useQuery( {
		queryKey: [ 'resources', 'pets' ],
		queryFn: async () => {
			try {
				const rows = await apiFetch( {
					path: '/wp/v2/kf-pets?per_page=100&status=publish,draft,pending,private',
				} );
				return Array.isArray( rows ) ? rows : [];
			} catch {
				return [];
			}
		},
		enabled: modalEnabled,
		staleTime: 60_000,
	} );

	const [ tab, setTab ] = useState( 'care_instructions' );
	const [ bookingKind, setBookingKind ] = useState( resolvedDefaultKind );
	const [ locationId, setLocationId ] = useState( 0 );
	const [ resourceId, setResourceId ] = useState( 0 );
	/** Exam room = kennelpress_kennel post ID (0 = none). */
	const [ clinicExamRoomId, setClinicExamRoomId ] = useState( 0 );
	/** Attending clinician = WordPress user ID (0 = none). */
	const [ clinicClinicianId, setClinicClinicianId ] = useState( 0 );
	const [ petId, setPetId ] = useState( 0 );
	const [ startUtc, setStartUtc ] = useState( '' );
	const [ endUtc, setEndUtc ] = useState( '' );
	const [ status, setStatus ] = useState( 'pending' );

	const [ stayDiet, setStayDiet ] = useState( '' );
	const [ stayMed, setStayMed ] = useState( '' );
	const [ stayBelong, setStayBelong ] = useState( '' );
	const [ sendCareSheetEmail, setSendCareSheetEmail ] = useState( false );

	const [ groomingStyle, setGroomingStyle ] = useState( '' );
	const [ coatCondition, setCoatCondition ] = useState( '' );
	const [ reasonForVisit, setReasonForVisit ] = useState( '' );

	const [ submitErr, setSubmitErr ] = useState( '' );
	const [ rosterOverrideOk, setRosterOverrideOk ] = useState( false );

	const dietTouchedRef = useRef( false );
	const lastWeekFacilityKeyRef = useRef( '' );
	const walkInTimesKeyRef = useRef( '' );
	const rosterAutoLocationSigRef = useRef( '' );

	const facilityPayload = useMemo( () => {
		if ( undefined === bootstrapQuery.data ) {
			return undefined;
		}
		return bootstrapQuery.data.facility ?? null;
	}, [ bootstrapQuery.data ] );

	const locations = bootstrapQuery.data?.locations ?? [];

	const getTimezoneForLocation = useCallback(
		( locId ) => {
			const id = parseInt( locId, 10 ) || 0;
			if (
				id > 0 &&
				facilityPayload &&
				'object' === typeof facilityPayload &&
				Array.isArray( facilityPayload.locations )
			) {
				const row = facilityPayload.locations.find(
					( x ) => parseInt( x.id, 10 ) === id
				);
				if (
					row &&
					row.settings &&
					row.settings.timezone &&
					'string' === typeof row.settings.timezone
				) {
					return String( row.settings.timezone );
				}
			}
			return siteTimezone;
		},
		[ facilityPayload, siteTimezone ]
	);

	const locationTimezone = useMemo(
		() => getTimezoneForLocation( locationId ),
		[ getTimezoneForLocation, locationId ]
	 );

	const kennelsQuery = useQuery( {
		queryKey: [ 'resources', 'kennels', locationId ],
		queryFn: async () => {
			const res = await apiFetch( {
				path: `/${ boardingRestBase }/kennels?location=${ locationId }&per_page=100`,
			} );
			return res && Array.isArray( res.kennels ) ? res.kennels : [];
		},
		enabled: modalEnabled && locationId > 0 && 'boarding' === bookingKind,
		staleTime: 30_000,
	} );

	const intakeContext =
		'grooming' === bookingKind ? 'grooming' : 'clinic';

	const intakeQuery = useQuery( {
		queryKey: [ 'resources', 'intake', locationId, intakeContext ],
		queryFn: async () => {
			const res = await apiFetch( {
				path: `/${ boardingRestBase }/booking-intake-resources?location=${ locationId }&context=${ intakeContext }`,
			} );
			return {
				users: res && Array.isArray( res.users ) ? res.users : [],
				kennels: res && Array.isArray( res.kennels ) ? res.kennels : [],
			};
		},
		enabled:
			modalEnabled &&
			locationId > 0 &&
			( 'grooming' === bookingKind || 'clinic' === bookingKind ),
		staleTime: 30_000,
	} );

	const careQuery = useQuery( {
		queryKey: [ 'resources', 'pet-care', petId ],
		queryFn: async () => {
			const data = await apiFetch( {
				path: `/${ boardingRestBase }/pets/${ petId }/care-defaults`,
			} );
			return data && 'object' === typeof data ? data : null;
		},
		enabled: modalEnabled && petId > 0,
	} );

	const rosterQuery = useQuery( {
		queryKey: [ 'clinician-location-roster', clinicClinicianId ],
		queryFn: async () => {
			return apiFetch( {
				path:
					'/kennelflow/v1/clinicians/' +
					clinicClinicianId +
					'/location-roster',
			} );
		},
		enabled:
			modalEnabled &&
			'clinic' === bookingKind &&
			clinicClinicianId > 0,
		staleTime: 30_000,
	} );

	const kennels = kennelsQuery.data ?? [];
	const intakeUsers = intakeQuery.data?.users ?? [];
	const intakeKennels = intakeQuery.data?.kennels ?? [];

	const availBaseEnabled =
		modalEnabled &&
		'clinic' === bookingKind &&
		locationId > 0 &&
		!! startUtc &&
		!! endUtc;

	const availBaseQuery = useQuery( {
		queryKey: [ 'kp-avail-base', locationId, startUtc, endUtc ],
		queryFn: async () => {
			const path =
				'/' +
				boardingRestBase +
				'/availability?location=' +
				locationId +
				'&start=' +
				encodeURIComponent( startUtc ) +
				'&end=' +
				encodeURIComponent( endUtc );
			return apiFetch( { path } );
		},
		enabled: availBaseEnabled,
		staleTime: 15_000,
	} );

	const availClinEnabled = availBaseEnabled && clinicClinicianId > 0;

	const availClinQuery = useQuery( {
		queryKey: [
			'kp-avail-clin',
			locationId,
			startUtc,
			endUtc,
			clinicClinicianId,
		],
		queryFn: async () => {
			const path =
				'/' +
				boardingRestBase +
				'/availability?location=' +
				locationId +
				'&start=' +
				encodeURIComponent( startUtc ) +
				'&end=' +
				encodeURIComponent( endUtc ) +
				'&clinician_id=' +
				clinicClinicianId;
			return apiFetch( { path } );
		},
		enabled: availClinEnabled,
		staleTime: 15_000,
	} );

	const clinicianOverlapWarning = useMemo( () => {
		if ( clinicClinicianId < 1 ) {
			return '';
		}
		if (
			! availBaseQuery.isFetched ||
			! availClinQuery.isFetched
		) {
			return '';
		}
		const base = availBaseQuery.data?.kennel_ids ?? [];
		const filt = availClinQuery.data?.kennel_ids ?? [];
		const baseN = Array.isArray( base ) ? base.length : 0;
		const filtN = Array.isArray( filt ) ? filt.length : 0;
		if ( baseN > 0 && filtN === 0 ) {
			const u = intakeUsers.find( ( x ) => x.id === clinicClinicianId );
			const name = u?.name
				? String( u.name )
				: '#' + String( clinicClinicianId );
			return sprintf(
				/* translators: %s: clinician name (title added when missing) */
				__(
					'Warning: %s is already scheduled for another appointment at this time.',
					'kennelflow-core'
				),
				formatClinicianTitleForWarning( name )
			);
		}
		return '';
	}, [
		clinicClinicianId,
		availBaseQuery.isFetched,
		availClinQuery.isFetched,
		availBaseQuery.data,
		availClinQuery.data,
		intakeUsers,
	] );

	const rosterMismatchWarning = useMemo( () => {
		if ( 'clinic' !== bookingKind || clinicClinicianId < 1 || locationId < 1 ) {
			return '';
		}
		if ( ! startUtc ) {
			return '';
		}
		const rosterRaw = rosterQuery.data?.roster;
		if ( ! rosterRaw || 'object' !== typeof rosterRaw ) {
			return '';
		}
		const dt = DateTime.fromSQL( startUtc.replace( 'T', ' ' ), {
			zone: 'utc',
		} ).setZone( locationTimezone );
		if ( ! dt.isValid ) {
			return '';
		}
		const dayKey = weekdayKeyFromLuxon( dt );
		const allowed = getRosterLocationIdsForDay( rosterRaw, dayKey );
		if ( allowed.length < 1 ) {
			return '';
		}
		if ( allowed.includes( locationId ) ) {
			return '';
		}
		const u = intakeUsers.find( ( x ) => x.id === clinicClinicianId );
		const name = u?.name ? String( u.name ) : '#' + String( clinicClinicianId );
		const titles = allowed.map( ( id ) =>
			getLocationDisplayTitle( locations, id )
		);
		const uniq = [ ...new Set( titles ) ];
		const locList = uniq.join(
			/* translators: Separator between location names in a list */
			__( ', ', 'kennelflow-core' )
		);
		return sprintf(
			/* translators: 1: clinician name (may include Dr.), 2: other location name(s) */
			__(
				'Note: %1$s is rostered at %2$s today. Proceed with override?',
				'kennelflow-core'
			),
			formatClinicianTitleForWarning( name ),
			locList
		);
	}, [
		bookingKind,
		clinicClinicianId,
		locationId,
		startUtc,
		locationTimezone,
		rosterQuery.data,
		intakeUsers,
		locations,
	] );

	useEffect( () => {
		setRosterOverrideOk( false );
	}, [ locationId, clinicClinicianId, startUtc ] );

	useEffect( () => {
		if ( ! modalEnabled || 'clinic' !== bookingKind ) {
			return;
		}
		if ( clinicClinicianId < 1 ) {
			return;
		}
		if ( ! rosterQuery.isSuccess || ! rosterQuery.data?.roster ) {
			return;
		}
		const roster = rosterQuery.data.roster;
		const sig =
			String( clinicClinicianId ) +
			':' +
			JSON.stringify( roster );
		if ( rosterAutoLocationSigRef.current === sig ) {
			return;
		}
		const pick = pickLocationForTodayFromRoster(
			roster,
			siteTimezone,
			locations
		);
		if ( pick < 1 ) {
			rosterAutoLocationSigRef.current = sig;
			return;
		}
		rosterAutoLocationSigRef.current = sig;
		setLocationId( pick );
	}, [
		modalEnabled,
		bookingKind,
		clinicClinicianId,
		rosterQuery.isSuccess,
		rosterQuery.data,
		siteTimezone,
		locations,
	] );

	const pets = petsQuery.data ?? [];
	const careData = careQuery.data ?? null;
	const careLoading = careQuery.isFetching && petId > 0;

	const loadErr =
		bootstrapQuery.isError && bootstrapQuery.error
			? formatApiFetchError(
					bootstrapQuery.error,
					__( 'Could not load locations.', 'kennelflow-core' )
			  )
			: '';

	const resetDefaultsForWeek = useCallback( () => {
		if ( isWalkInMode ) {
			return;
		}
		const s = weekRange && weekRange.start ? weekRange.start : '';
		const e = weekRange && weekRange.end ? weekRange.end : '';
		let z = getTimezoneForLocation( locationId );
		const zTest = DateTime.now().setZone( z );
		if ( ! zTest.isValid ) {
			z = siteTimezone;
		}
		if ( ! s || ! e ) {
			setStartUtc( '' );
			setEndUtc( '' );
			return;
		}
		const [ ys, ms, ds ] = s.split( '-' ).map( ( x ) => parseInt( x, 10 ) );
		const [ ye, me, de ] = e.split( '-' ).map( ( x ) => parseInt( x, 10 ) );
		const startLocal = DateTime.fromObject(
			{ year: ys, month: ms, day: ds, hour: 12, minute: 0, second: 0 },
			{ zone: z }
		);
		const endLocal = DateTime.fromObject(
			{ year: ye, month: me, day: de, hour: 12, minute: 0, second: 0 },
			{ zone: z }
		);
		setStartUtc( startLocal.toUTC().toFormat( 'yyyy-MM-dd HH:mm:ss' ) );
		setEndUtc( endLocal.toUTC().toFormat( 'yyyy-MM-dd HH:mm:ss' ) );
	}, [ weekRange, locationId, getTimezoneForLocation, siteTimezone, isWalkInMode ] );

	const resetWalkInTimes = useCallback( () => {
		let z = getTimezoneForLocation( locationId );
		const zTest = DateTime.now().setZone( z );
		if ( ! zTest.isValid ) {
			z = siteTimezone;
		}
		const now = DateTime.now().setZone( z );
		if ( ! now.isValid ) {
			return;
		}
		const minute = now.minute;
		const roundUp = minute % 15 === 0 ? 0 : 15 - ( minute % 15 );
		const startLocal = now.plus( { minutes: roundUp } ).startOf( 'minute' );
		const endLocal = startLocal.plus( { minutes: 30 } );
		setStartUtc( startLocal.toUTC().toFormat( 'yyyy-MM-dd HH:mm:ss' ) );
		setEndUtc( endLocal.toUTC().toFormat( 'yyyy-MM-dd HH:mm:ss' ) );
	}, [ locationId, getTimezoneForLocation, siteTimezone ] );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const onKey = ( e ) => {
			if ( 'Escape' === e.key ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ open, onClose ] );

	useEffect( () => {
		if ( ! open ) {
			lastWeekFacilityKeyRef.current = '';
			return;
		}
		dietTouchedRef.current = false;
		setTab( 'care_instructions' );
		if ( isWalkInMode ) {
			setBookingKind( walkInKind );
			setStatus( 'checked_in' );
		} else {
			setBookingKind( resolvedDefaultKind );
			setStatus( 'pending' );
		}
		setResourceId( 0 );
		setClinicExamRoomId( 0 );
		setClinicClinicianId( 0 );
		setPetId( 0 );
		setStayMed( '' );
		setStayBelong( '' );
		setSendCareSheetEmail( false );
		setGroomingStyle( '' );
		setCoatCondition( '' );
		setReasonForVisit( '' );
		setSubmitErr( '' );
		setRosterOverrideOk( false );
		rosterAutoLocationSigRef.current = '';
		lastWeekFacilityKeyRef.current = '';
		walkInTimesKeyRef.current = '';
	}, [ open, resolvedDefaultKind, isWalkInMode, walkInKind ] );

	useEffect( () => {
		if ( ! open || typeof document === 'undefined' ) {
			return;
		}
		document.body.classList.add( 'kf-cal-modal-open' );
		return () => {
			document.body.classList.remove( 'kf-cal-modal-open' );
		};
	}, [ open ] );

	useEffect( () => {
		if ( ! modalEnabled || ! bootstrapQuery.data?.locations?.length ) {
			return;
		}
		setLocationId( ( prev ) =>
			prev > 0 ? prev : parseInt( bootstrapQuery.data.locations[ 0 ].id, 10 ) || 0
		);
	}, [ modalEnabled, bootstrapQuery.data?.locations ] );

	/**
	 * Default start/end from the visible calendar week: noon on first/last day in
	 * the selected location's Kennel rules timezone (or site TZ until facility loads).
	 * Re-run when the week changes, facility payload arrives, or location becomes valid.
	 */
	useEffect( () => {
		if ( ! modalEnabled ) {
			return;
		}
		if ( locationId < 1 ) {
			return;
		}
		const s = weekRange && weekRange.start ? weekRange.start : '';
		const e = weekRange && weekRange.end ? weekRange.end : '';
		if ( ! s || ! e ) {
			return;
		}
		const facReady = undefined === facilityPayload ? '0' : '1';
		const key = `${ s }|${ e }|${ facReady }`;
		if ( lastWeekFacilityKeyRef.current === key ) {
			return;
		}
		lastWeekFacilityKeyRef.current = key;
		resetDefaultsForWeek();
	}, [
		modalEnabled,
		weekRange,
		facilityPayload,
		resetDefaultsForWeek,
		locationId,
		isWalkInMode,
	] );

	useEffect( () => {
		if ( ! modalEnabled || ! isWalkInMode ) {
			return;
		}
		if ( locationId < 1 ) {
			return;
		}
		const facReady = undefined === facilityPayload ? '0' : '1';
		const key = `${ locationId }|${ facReady }`;
		if ( walkInTimesKeyRef.current === key ) {
			return;
		}
		walkInTimesKeyRef.current = key;
		resetWalkInTimes();
	}, [
		modalEnabled,
		isWalkInMode,
		locationId,
		facilityPayload,
		resetWalkInTimes,
	] );

	useEffect( () => {
		if ( ! modalEnabled || locationId < 1 ) {
			return;
		}
		if ( 'boarding' !== bookingKind ) {
			return;
		}
		const list = kennelsQuery.data ?? [];
		if ( ! kennelsQuery.isFetched ) {
			return;
		}
		if ( list.length > 0 ) {
			setResourceId( ( prev ) =>
				prev > 0 ? prev : parseInt( list[ 0 ].id, 10 ) || 0
			);
		} else {
			setResourceId( 0 );
		}
	}, [ modalEnabled, locationId, bookingKind, kennelsQuery.data, kennelsQuery.isFetched ] );

	useEffect( () => {
		if ( ! modalEnabled || locationId < 1 ) {
			return;
		}
		if ( 'grooming' !== bookingKind && 'clinic' !== bookingKind ) {
			return;
		}
		if ( ! intakeQuery.isFetched ) {
			return;
		}
		const res = intakeQuery.data;
		if ( ! res ) {
			return;
		}
		const u = res.users;
		const k = res.kennels;
		if ( 'grooming' === bookingKind ) {
			setResourceId( ( prev ) => {
				if ( prev > 0 && u.some( ( x ) => x.id === prev ) ) {
					return prev;
				}
				return u.length > 0 ? parseInt( u[ 0 ].id, 10 ) || 0 : 0;
			} );
		}
		if ( 'clinic' === bookingKind ) {
			if ( u.length > 0 ) {
				setClinicClinicianId( parseInt( u[ 0 ].id, 10 ) || 0 );
			} else {
				setClinicClinicianId( 0 );
			}
			if ( k.length > 0 ) {
				setClinicExamRoomId( parseInt( k[ 0 ].id, 10 ) || 0 );
			} else {
				setClinicExamRoomId( 0 );
			}
		}
	}, [
		modalEnabled,
		locationId,
		bookingKind,
		intakeQuery.data,
		intakeQuery.isFetched,
	] );

	useEffect( () => {
		if ( ! open || petId < 1 ) {
			return;
		}
		const data = careQuery.data;
		if (
			! dietTouchedRef.current &&
			data &&
			'string' === typeof data.kf_default_diet
		) {
			setStayDiet( data.kf_default_diet );
		}
	}, [ open, petId, careQuery.data ] );

	const saveMutation = useMutation( {
		mutationFn: ( data ) =>
			apiFetch( {
				path: bookingsCreatePath,
				method: 'POST',
				data,
			} ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
			if ( onSaved ) {
				onSaved();
			}
			onClose();
		},
		onError: ( e ) => {
			const msg =
				e && e.message
					? String( e.message )
					: __( 'Could not create booking.', 'kennelflow-core' );
			setSubmitErr( msg );
		},
	} );

	const contextTabLabel =
		'boarding' === bookingKind
			? __( 'Stay Care Sheet', 'kennelflow-core' )
			: 'grooming' === bookingKind
			? __( 'Grooming Details', 'kennelflow-core' )
			: __( 'Clinical Intake', 'kennelflow-core' );

	const handleSubmit = () => {
		setSubmitErr( '' );
		if ( ! kennelpressBookings ) {
			return;
		}
		if ( petId < 1 || ! startUtc || ! endUtc || locationId < 1 ) {
			setSubmitErr(
				__( 'Location, pet, start, and end are required.', 'kennelflow-core' )
			);
			return;
		}

		if (
			'clinic' === bookingKind &&
			clinicClinicianId > 0 &&
			rosterMismatchWarning &&
			! rosterOverrideOk
		) {
			setSubmitErr(
				__(
					'Please confirm the roster override or change location, clinician, or appointment time.',
					'kennelflow-core'
				)
			);
			return;
		}

		let resId = 0;
		if ( 'boarding' === bookingKind ) {
			resId = resourceId;
			if ( resId < 1 ) {
				setSubmitErr( __( 'Select a kennel.', 'kennelflow-core' ) );
				return;
			}
		} else if ( 'grooming' === bookingKind ) {
			resId = resourceId;
			if ( resId < 1 ) {
				setSubmitErr( __( 'Select a groomer.', 'kennelflow-core' ) );
				return;
			}
		} else if ( 'clinic' === bookingKind ) {
			if ( clinicClinicianId < 1 && clinicExamRoomId < 1 ) {
				setSubmitErr(
					__(
						'Select an exam room and/or an attending clinician.',
						'kennelflow-core'
					)
				);
				return;
			}
			if ( clinicClinicianId > 0 ) {
				resId = clinicClinicianId;
			} else {
				resId = clinicExamRoomId;
			}
			if ( resId < 1 ) {
				setSubmitErr(
					__( 'Select a valid scheduling resource.', 'kennelflow-core' )
				);
				return;
			}
		}

		const data = {
			pet_id: petId,
			start: startUtc,
			end: endUtc,
			status,
			booking_kind: bookingKind,
			location_id: locationId,
			resource_id: resId,
		};

		if ( 'boarding' === bookingKind ) {
			data.stay_diet = stayDiet;
			data.stay_medication_notes = stayMed;
			data.stay_belongings = stayBelong;
			data.send_care_sheet_email = sendCareSheetEmail;
		} else if ( 'grooming' === bookingKind ) {
			data.grooming_style_notes = groomingStyle;
			data.coat_condition = coatCondition;
		} else if ( 'clinic' === bookingKind ) {
			data.reason_for_visit = reasonForVisit;
			if ( clinicClinicianId > 0 && clinicExamRoomId > 0 ) {
				data.clinic_exam_room_id = clinicExamRoomId;
			}
			if ( clinicianOverlapWarning ) {
				data.force_clinic_overlap = true;
			}
		}

		saveMutation.mutate( data );
	};

	if ( ! open ) {
		return null;
	}

	logCalendarTrace( 'BookingModal render', {
		open,
		kennelpress_bookings_url: kennelpressBookings,
		boarding_rest_base: boardingRestBase,
		diagnostics: settings.add_booking_diagnostics || null,
	} );

	const bootstrapLoading =
		!! kennelpressBookings &&
		bootstrapQuery.isPending &&
		undefined === bootstrapQuery.data;

	const portalTarget =
		typeof document !== 'undefined' ? document.body : null;

	if ( ! kennelpressBookings ) {
		const unavailableModal = (
			<div
				className="kf-booking-modal-backdrop"
				role="presentation"
				onClick={ onClose }
			>
				<div
					className="kf-booking-modal"
					role="dialog"
					aria-modal="true"
					aria-labelledby="kf-booking-modal-title"
					onClick={ ( e ) => e.stopPropagation() }
				>
					<div className="kf-booking-modal__header">
						<h2 id="kf-booking-modal-title">
							{ modalTitle }
						</h2>
						<button
							type="button"
							className="button-link kf-booking-modal__close"
							onClick={ onClose }
						>
							{ __( 'Close', 'kennelflow-core' ) }
						</button>
					</div>
					<p className="kf-booking-modal__hint">
						{ __(
							'Booking intake requires KennelFlow Boarding (not Core alone). Install and activate kennelflow-boarding, then reload this page.',
							'kennelflow-core'
						) }
					</p>
					{ settings.add_booking_diagnostics &&
					Array.isArray( settings.add_booking_diagnostics.issues ) &&
					settings.add_booking_diagnostics.issues.length > 0 ? (
						<ul className="kf-booking-modal__hint-list">
							{ settings.add_booking_diagnostics.issues.map( ( issue, index ) => (
								<li key={ index }>{ issue }</li>
							) ) }
						</ul>
					) : null }
					{ isCalendarDebugEnabled() &&
					settings.add_booking_diagnostics ? (
						<details className="kf-cal-diagnostics__debug">
							<summary>
								{ __( 'Technical details (debug)', 'kennelflow-core' ) }
							</summary>
							<pre>
								{ JSON.stringify( settings.add_booking_diagnostics, null, 2 ) }
							</pre>
						</details>
					) : null }
				</div>
			</div>
		);
		return portalTarget
			? renderInBodyPortal( unavailableModal, portalTarget )
			: unavailableModal;
	}

	const allergies = careData && careData.kf_allergies ? String( careData.kf_allergies ).trim() : '';
	const tagLabels =
		careData && Array.isArray( careData.behavioral_tag_labels )
			? careData.behavioral_tag_labels
			: [];

	const submitting = saveMutation.isPending;

	const bookingModal = (
		<div
			className="kf-booking-modal-backdrop"
			role="presentation"
			onClick={ onClose }
		>
			<div
				className="kf-booking-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="kf-booking-modal-title"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="kf-booking-modal__header">
					<h2 id="kf-booking-modal-title">
						{ modalTitle }
					</h2>
					<button
						type="button"
						className="button-link kf-booking-modal__close"
						onClick={ onClose }
					>
						{ __( 'Close', 'kennelflow-core' ) }
					</button>
				</div>

				{ bootstrapLoading ? (
					<p className="kf-booking-modal__loading">
						{ __( 'Loading booking form…', 'kennelflow-core' ) }
					</p>
				) : (
					<>
				<div className="kf-booking-modal__tabs" role="tablist">
					<button
						type="button"
						className={
							'care_instructions' === tab
								? 'is-active'
								: ''
						}
						onClick={ () => setTab( 'care_instructions' ) }
						role="tab"
						aria-selected={ 'care_instructions' === tab }
					>
						{ __( 'Care Instructions', 'kennelflow-core' ) }
					</button>
					<button
						type="button"
						className={ 'context' === tab ? 'is-active' : '' }
						onClick={ () => setTab( 'context' ) }
						role="tab"
						aria-selected={ 'context' === tab }
					>
						{ contextTabLabel }
					</button>
				</div>

				{ loadErr ? (
					<p className="kf-booking-modal__err">{ loadErr }</p>
				) : null }

				{ 'care_instructions' === tab && (
					<div className="kf-booking-modal__panel">
						<p>
							<label htmlFor="kf-bm-kind">{ __( 'Booking Type', 'kennelflow-core' ) }</label>
							<select
								id="kf-bm-kind"
								className="widefat"
								value={ bookingKind }
								onChange={ ( e ) => {
									setBookingKind( e.target.value );
									setResourceId( 0 );
									setClinicExamRoomId( 0 );
									setClinicClinicianId( 0 );
								} }
							>
								<option value="boarding">{ __( 'Boarding', 'kennelflow-core' ) }</option>
								<option value="grooming">{ __( 'Grooming', 'kennelflow-core' ) }</option>
								<option value="clinic">{ __( 'Clinic', 'kennelflow-core' ) }</option>
							</select>
						</p>
						<p className="description">
							{ __(
								'Pick location, scheduling resource, pet, and start/end times in the Kennel rules timezone for that location (saved as UTC).',
								'kennelflow-core'
							) }
						</p>
						<p>
							<label htmlFor="kf-bm-location">{ __( 'Location', 'kennelflow-core' ) }</label>
							<select
								id="kf-bm-location"
								className="widefat"
								value={ locationId || '' }
								onChange={ ( e ) =>
									setLocationId( parseInt( e.target.value, 10 ) || 0 )
								}
							>
								<option value="0">{ __( '— Select —', 'kennelflow-core' ) }</option>
								{ locations.map( ( loc ) => (
									<option key={ loc.id } value={ String( loc.id ) }>
										{ loc.title && loc.title.rendered
											? loc.title.rendered
											: `#${ loc.id }` }
									</option>
								) ) }
							</select>
						</p>
						{ 'boarding' === bookingKind && (
							<p>
								<label htmlFor="kf-bm-resource">{ __( 'Kennel', 'kennelflow-core' ) }</label>
								<select
									id="kf-bm-resource"
									className="widefat"
									value={ resourceId || '' }
									onChange={ ( e ) =>
										setResourceId( parseInt( e.target.value, 10 ) || 0 )
									}
								>
									<option value="0">{ __( '— Select —', 'kennelflow-core' ) }</option>
									{ kennels.map( ( k ) => (
										<option key={ k.id } value={ String( k.id ) }>
											{ k.title || `#${ k.id }` }
										</option>
									) ) }
								</select>
								<span className="description">
									{ __( 'Kennels at this location.', 'kennelflow-core' ) }
								</span>
							</p>
						) }
						{ 'grooming' === bookingKind && (
							<p>
								<label htmlFor="kf-bm-groomer">{ __( 'Resource', 'kennelflow-core' ) }</label>
								<select
									id="kf-bm-groomer"
									className="widefat"
									value={ resourceId || '' }
									onChange={ ( e ) =>
										setResourceId( parseInt( e.target.value, 10 ) || 0 )
									}
								>
									<option value="0">{ __( '— Select —', 'kennelflow-core' ) }</option>
									{ intakeUsers.map( ( u ) => (
										<option key={ u.id } value={ String( u.id ) }>
											{ u.name || `#${ u.id }` }
										</option>
									) ) }
								</select>
								<span className="description">
									{ __( 'Staff with the Groomer role.', 'kennelflow-core' ) }
								</span>
							</p>
						) }
						{ 'clinic' === bookingKind && (
							<>
								<p>
									<label htmlFor="kf-bm-clinic-exam">{ __( 'Exam room', 'kennelflow-core' ) }</label>
									<select
										id="kf-bm-clinic-exam"
										className="widefat"
										value={ clinicExamRoomId || '' }
										onChange={ ( e ) =>
											setClinicExamRoomId(
												parseInt( e.target.value, 10 ) || 0
											)
										}
									>
										<option value="0">{ __( '— None —', 'kennelflow-core' ) }</option>
										{ intakeKennels.map( ( k ) => (
											<option key={ k.id } value={ String( k.id ) }>
												{ k.title || `#${ k.id }` }
											</option>
										) ) }
									</select>
									<span className="description">
										{ __(
											'Physical exam room (kennel) at this location.',
											'kennelflow-core'
										) }
									</span>
								</p>
								<div className="kf-booking-modal__field">
									<label htmlFor="kf-bm-clinic-clinician">{ __( 'Attending clinician', 'kennelflow-core' ) }</label>
									<select
										id="kf-bm-clinic-clinician"
										className="widefat"
										value={ clinicClinicianId || '' }
										onChange={ ( e ) =>
											setClinicClinicianId(
												parseInt( e.target.value, 10 ) || 0
											)
										}
									>
										<option value="0">{ __( '— None —', 'kennelflow-core' ) }</option>
										{ intakeUsers.map( ( u ) => (
											<option key={ u.id } value={ String( u.id ) }>
												{ u.name || `#${ u.id }` }
											</option>
										) ) }
									</select>
									<span className="description">
										{ __(
											'Staff member whose schedule blocks this visit (user ID).',
											'kennelflow-core'
										) }
									</span>
									{ clinicianOverlapWarning ? (
										<p
											className="kf-booking-modal__warn-soft"
											role="status"
										>
											{ clinicianOverlapWarning }
										</p>
									) : null }
									{ rosterMismatchWarning ? (
										<>
											<p
												className="kf-booking-modal__warn-soft"
												role="status"
											>
												{ rosterMismatchWarning }
											</p>
											<p className="kf-booking-modal__roster-override">
												<label>
													<input
														type="checkbox"
														checked={ rosterOverrideOk }
														onChange={ ( e ) =>
															setRosterOverrideOk( e.target.checked )
														}
													/>
													{ ' ' }
													{ __(
														'Proceed with roster override',
														'kennelflow-core'
													) }
												</label>
											</p>
										</>
									) : null }
								</div>
							</>
						) }
						<p>
							<label htmlFor="kf-bm-pet">{ __( 'Pet', 'kennelflow-core' ) }</label>
							<select
								id="kf-bm-pet"
								className="widefat"
								value={ petId || '' }
								onChange={ ( e ) => {
									dietTouchedRef.current = false;
									setPetId( parseInt( e.target.value, 10 ) || 0 );
								} }
							>
								<option value="0">{ __( '— Select —', 'kennelflow-core' ) }</option>
								{ pets.map( ( p ) => (
									<option key={ p.id } value={ String( p.id ) }>
										{ formatPetSelectLabel( p ) }
									</option>
								) ) }
							</select>
						</p>
						<BookingDatetimePicker
							id="kf-bm-start"
							label={ __( 'Start', 'kennelflow-core' ) }
							valueUtc={ startUtc }
							onChangeUtc={ setStartUtc }
							ianaZone={ locationTimezone }
						/>
						<BookingDatetimePicker
							id="kf-bm-end"
							label={ __( 'End', 'kennelflow-core' ) }
							valueUtc={ endUtc }
							onChangeUtc={ setEndUtc }
							ianaZone={ locationTimezone }
						/>
						<p>
							<label htmlFor="kf-bm-status">{ __( 'Status', 'kennelflow-core' ) }</label>
							<select
								id="kf-bm-status"
								value={ status }
								onChange={ ( e ) => setStatus( e.target.value ) }
							>
								<option value="pending">{ __( 'Pending', 'kennelflow-core' ) }</option>
								<option value="confirmed">{ __( 'Confirmed', 'kennelflow-core' ) }</option>
								<option value="checked_in">{ __( 'Checked in', 'kennelflow-core' ) }</option>
							</select>
						</p>
					</div>
				) }

				{ 'context' === tab && 'boarding' === bookingKind && (
					<div className="kf-booking-modal__panel kf-booking-modal__panel--care">
						{ careLoading ? (
							<p className="description">{ __( 'Loading pet profile…', 'kennelflow-core' ) }</p>
						) : null }
						{ petId < 1 ? (
							<p className="description">
								{ __( 'Select a pet on the Care Instructions tab first.', 'kennelflow-core' ) }
							</p>
						) : (
							<>
								{ allergies ? (
									<div
										className="kf-stay-care-alert kf-stay-care-alert--allergy"
										role="status"
									>
										<strong>{ __( 'Allergies (from pet profile)', 'kennelflow-core' ) }</strong>
										<div>{ allergies }</div>
									</div>
								) : null }
								{ tagLabels.length > 0 ? (
									<div
										className="kf-stay-care-alert kf-stay-care-alert--behavior"
										role="status"
									>
										<strong>
											{ __( 'Behavioral tags (from pet profile)', 'kennelflow-core' ) }
										</strong>
										<ul>
											{ tagLabels.map( ( t, i ) => (
												<li key={ i }>{ t }</li>
											) ) }
										</ul>
									</div>
								) : null }
								{ ! allergies && tagLabels.length < 1 && ! careLoading ? (
									<p className="description">
										{ __(
											'No allergy or behavioral alerts on file for this pet.',
											'kennelflow-core'
										) }
									</p>
								) : null }

								<p>
									<label htmlFor="kf-bm-diet">
										{ __( 'Stay diet instructions', 'kennelflow-core' ) }
									</label>
									<textarea
										id="kf-bm-diet"
										rows={ 4 }
										className="widefat"
										value={ stayDiet }
										onChange={ ( e ) => {
											dietTouchedRef.current = true;
											setStayDiet( e.target.value );
										} }
									/>
								</p>
								<p>
									<label htmlFor="kf-bm-med">{ __( 'Stay medication notes', 'kennelflow-core' ) }</label>
									<textarea
										id="kf-bm-med"
										rows={ 3 }
										className="widefat"
										value={ stayMed }
										onChange={ ( e ) => setStayMed( e.target.value ) }
										placeholder={ __(
											'e.g. Owner brought Cerenia, 1 pill AM',
											'kennelflow-core'
										) }
									/>
								</p>
								<p>
									<label htmlFor="kf-bm-belong">{ __( 'Belongings brought', 'kennelflow-core' ) }</label>
									<textarea
										id="kf-bm-belong"
										rows={ 3 }
										className="widefat"
										value={ stayBelong }
										onChange={ ( e ) => setStayBelong( e.target.value ) }
										placeholder={ __(
											'e.g. Green leash, blue blanket, 2 toys',
											'kennelflow-core'
										) }
									/>
								</p>

								<div className="kf-booking-modal__email-care">
									<label
										className="kf-booking-modal__email-care-label"
										htmlFor="kf-bm-email-care"
									>
										<input
											id="kf-bm-email-care"
											type="checkbox"
											checked={ sendCareSheetEmail }
											onChange={ ( e ) =>
												setSendCareSheetEmail( e.target.checked )
											}
										/>
										<span>
											{ __(
												'Email Care Sheet Confirmation to Owner',
												'kennelflow-core'
											) }
										</span>
									</label>
									<p className="description">
										{ __(
											'Off by default so routine edits do not email the owner.',
											'kennelflow-core'
										) }
									</p>
								</div>
							</>
						) }
					</div>
				) }

				{ 'context' === tab && 'grooming' === bookingKind && (
					<div className="kf-booking-modal__panel kf-booking-modal__panel--care">
						<h3 className="kf-booking-modal__section-title">
							{ __( 'Grooming Details', 'kennelflow-core' ) }
						</h3>
						<p>
							<label htmlFor="kf-bm-style">{ __( 'Grooming style notes', 'kennelflow-core' ) }</label>
							<textarea
								id="kf-bm-style"
								rows={ 4 }
								className="widefat"
								value={ groomingStyle }
								onChange={ ( e ) => setGroomingStyle( e.target.value ) }
							/>
						</p>
						<p>
							<label htmlFor="kf-bm-coat">{ __( 'Coat condition', 'kennelflow-core' ) }</label>
							<textarea
								id="kf-bm-coat"
								rows={ 3 }
								className="widefat"
								value={ coatCondition }
								onChange={ ( e ) => setCoatCondition( e.target.value ) }
							/>
						</p>
					</div>
				) }

				{ 'context' === tab && 'clinic' === bookingKind && (
					<div className="kf-booking-modal__panel kf-booking-modal__panel--care">
						<h3 className="kf-booking-modal__section-title">
							{ __( 'Clinical Intake', 'kennelflow-core' ) }
						</h3>
						<p>
							<label htmlFor="kf-bm-reason">{ __( 'Reason for visit', 'kennelflow-core' ) }</label>
							<textarea
								id="kf-bm-reason"
								rows={ 5 }
								className="widefat"
								value={ reasonForVisit }
								onChange={ ( e ) => setReasonForVisit( e.target.value ) }
							/>
						</p>
					</div>
				) }

				{ submitErr ? (
					<p className="kf-booking-modal__err">{ submitErr }</p>
				) : null }

				<div className="kf-booking-modal__actions">
					<button type="button" className="button" onClick={ onClose }>
						{ __( 'Cancel', 'kennelflow-core' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						onClick={ handleSubmit }
						disabled={ submitting || bootstrapLoading }
					>
						{ submitting
							? __( 'Saving…', 'kennelflow-core' )
							: __( 'Save booking', 'kennelflow-core' ) }
					</button>
				</div>
					</>
				) }
			</div>
		</div>
	);

	return portalTarget
		? renderInBodyPortal( bookingModal, portalTarget )
		: bookingModal;
}

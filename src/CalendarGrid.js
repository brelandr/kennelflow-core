/**
 * Admin occupancy shell: 7-day resource grid + drag to update bookings.
 *
 * @package KennelFlow
 */

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { BookingModal } from './BookingModal';
import './CalendarGrid.css';

/**
 * @param {string} s MySQL datetime UTC
 * @return {Date}
 */
function parseMysqlUtc( s ) {
	if ( ! s || 'string' !== typeof s ) {
		return new Date( 0 );
	}
	const t = s.includes( 'T' ) ? s : s.replace( ' ', 'T' );
	const d = new Date( t.endsWith( 'Z' ) ? t : `${ t }Z` );
	return Number.isNaN( d.getTime() ) ? new Date( 0 ) : d;
}

/**
 * @param {Date} d
 * @return {string} Y-m-d UTC
 */
function formatYmdUtc( d ) {
	return d.toISOString().slice( 0, 10 );
}

/**
 * @param {string} ymd
 * @param {number} addDays
 * @return {string}
 */
function addDaysYmd( ymd, addDays ) {
	const d = parseMysqlUtc( `${ ymd }T12:00:00` );
	const n = new Date( d.getTime() + addDays * 86400000 );
	return formatYmdUtc( n );
}

/**
 * @param {object} booking
 * @return {boolean}
 */
function isClinicBooking( booking ) {
	const k = ( booking.booking_kind || '' ).toLowerCase();
	return 'clinic' === k;
}

/**
 * @param {object} booking
 * @return {boolean}
 */
function isGroomingBooking( booking ) {
	const k = ( booking.booking_kind || '' ).toLowerCase();
	return 'grooming' === k;
}

/**
 * Boarding stays (not clinic or grooming).
 *
 * @param {object} booking
 * @return {boolean}
 */
function isBoardingBooking( booking ) {
	return ! isClinicBooking( booking ) && ! isGroomingBooking( booking );
}

/**
 * @param {string} mysqlUtc
 * @return {string}
 */
function formatPopoverDateTime( mysqlUtc ) {
	const d = parseMysqlUtc( mysqlUtc );
	if ( 0 === d.getTime() ) {
		return '—';
	}
	return d.toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
}

/**
 * Deep snapshot of calendar query data for rollback (optimistic drag).
 *
 * @param {unknown} data Query cache value.
 * @return {unknown}
 */
function cloneCalendarQueryData( data ) {
	if ( null === data || 'object' !== typeof data ) {
		return data;
	}
	if ( 'function' === typeof structuredClone ) {
		try {
			return structuredClone( data );
		} catch ( e ) {
			void e;
		}
	}
	try {
		return JSON.parse( JSON.stringify( data ) );
	} catch ( e ) {
		void e;
		return data;
	}
}

/**
 * Human-readable message from @wordpress/api-fetch errors.
 *
 * @param {unknown} err Error object.
 * @return {string}
 */
function getCalendarPatchErrorMessage( err ) {
	if ( err && 'object' === typeof err ) {
		if ( err.message && 'string' === typeof err.message ) {
			return err.message;
		}
		const data = err.data;
		if ( data && 'object' === typeof data && data.message ) {
			return String( data.message );
		}
	}
	return __( 'Could not update booking.', 'kennelflow-core' );
}

/**
 * Fixed admin-style error toast (no dependency on core/notices store).
 *
 * @param {string} message Text.
 * @return {void}
 */
function showCalendarDragErrorToast( message ) {
	if ( 'undefined' === typeof document || ! document.body ) {
		return;
	}
	const el = document.createElement( 'div' );
	el.className = 'kf-cal-toast notice notice-error';
	el.setAttribute( 'role', 'alert' );
	const p = document.createElement( 'p' );
	p.textContent = message;
	el.appendChild( p );
	document.body.appendChild( el );
	window.setTimeout( () => {
		el.remove();
	}, 8000 );
}

/**
 * Layout one event bar within the week strip (0–100% of track width).
 *
 * @param {object} booking
 * @param {Date}   weekStart Inclusive UTC
 * @param {Date}   weekEnd   Exclusive UTC
 * @return {{left:number,width:number,visible:boolean}|null}
 */
function layoutEventInWeek( booking, weekStart, weekEnd ) {
	const start = parseMysqlUtc( booking.start_gmt );
	const end = parseMysqlUtc( booking.end_gmt );
	if ( end <= weekStart || start >= weekEnd ) {
		return null;
	}
	const clipStart = start < weekStart ? weekStart : start;
	const clipEnd = end > weekEnd ? weekEnd : end;
	const span = weekEnd.getTime() - weekStart.getTime();
	if ( span <= 0 ) {
		return null;
	}
	const left = ( ( clipStart.getTime() - weekStart.getTime() ) / span ) * 100;
	const width = ( ( clipEnd.getTime() - clipStart.getTime() ) / span ) * 100;
	return {
		left: Math.max( 0, left ),
		width: Math.max( 0.35, Math.min( 100 - left, width ) ),
		visible: true,
	};
}

/**
 * @param {object} props
 * @param {string} props.initialStartDate Y-m-d
 * @param {string} props.initialEndDate   Y-m-d
 * @param {string} [props.bookingKind]     Optional REST filter (e.g. grooming).
 * @param {string} [props.cornerLabel]     Y-axis header (e.g. Groomer).
 */
export function CalendarGrid( {
	initialStartDate,
	initialEndDate,
	bookingKind = '',
	cornerLabel = '',
} ) {
	const [ range, setRange ] = useState( () => {
		let s = initialStartDate;
		let e = initialEndDate;
		if ( ! s || ! e ) {
			const now = new Date();
			const u = Date.UTC(
				now.getUTCFullYear(),
				now.getUTCMonth(),
				now.getUTCDate()
			);
			const dow = new Date( u ).getUTCDay();
			const mondayOffset = ( dow + 6 ) % 7;
			const monMs = u - mondayOffset * 86400000;
			s = new Date( monMs ).toISOString().slice( 0, 10 );
			e = addDaysYmd( s, 6 );
		}
		return { start: s, end: e };
	} );


	const [ bookingModalOpen, setBookingModalOpen ] = useState( false );
	const [ popoverBooking, setPopoverBooking ] = useState( null );

	const kennelpressBookingsUrl =
		typeof window !== 'undefined' &&
		window.kfCalendarSettings &&
		window.kfCalendarSettings.kennelpress_bookings_url
			? window.kfCalendarSettings.kennelpress_bookings_url
			: '';

	const queryClient = useQueryClient();
	const kindTrim = ( bookingKind || '' ).trim();
	const calendarQueryKey = useMemo(
		() => [ 'calendar', range.start, range.end, kindTrim ],
		[ range.start, range.end, kindTrim ]
	);

	const calendarQuery = useQuery( {
		queryKey: calendarQueryKey,
		queryFn: async () => {
			const kindQs =
				'' !== kindTrim
					? `&booking_kind=${ encodeURIComponent( kindTrim ) }`
					: '';
			try {
				const resp = await apiFetch( {
					path: `/kennelflow/v1/calendar?start_date=${ encodeURIComponent(
						range.start
					) }&end_date=${ encodeURIComponent( range.end ) }${ kindQs }`,
				} );
				if ( ! resp ) {
					return { bookings: [], resources: undefined };
				}
				const bookings = Array.isArray( resp.bookings ) ? resp.bookings : [];
				const resources = Object.prototype.hasOwnProperty.call( resp, 'resources' )
					? ( Array.isArray( resp.resources ) ? resp.resources : undefined )
					: undefined;
				return { bookings, resources };
			} catch {
				return { bookings: [], resources: undefined };
			}
		},
	} );

	const explicitResources = calendarQuery.data?.resources;
	const bookings = calendarQuery.data?.bookings ?? [];

	const weekStart = useMemo(
		() => parseMysqlUtc( `${ range.start }T00:00:00` ),
		[ range.start ]
	);
	const weekEnd = useMemo(
		() => new Date( weekStart.getTime() + 7 * 86400000 ),
		[ weekStart ]
	);

	const dayLabels = useMemo( () => {
		const out = [];
		for ( let i = 0; i < 7; i++ ) {
			const d = new Date( weekStart.getTime() + i * 86400000 );
			out.push(
				d.toLocaleDateString( undefined, {
					weekday: 'short',
					month: 'short',
					day: 'numeric',
					timeZone: 'UTC',
				} )
			);
		}
		return out;
	}, [ weekStart ] );

	const resources = useMemo( () => {
		if ( Array.isArray( explicitResources ) && explicitResources.length > 0 ) {
			return explicitResources.map( ( r ) => {
				const id = parseInt( r.id, 10 );
				return {
					id: Number.isNaN( id ) ? 0 : id,
					title:
						r.title && String( r.title ).trim() !== ''
							? String( r.title )
							: sprintf(
									/* translators: %d: resource id */
									__( 'Resource %d', 'kennelflow-core' ),
									id
							  ),
				};
			} );
		}
		const list = bookings || [];
		const ids = new Set();
		list.forEach( ( b ) => {
			const rid = parseInt( b.resource_id, 10 );
			ids.add( Number.isNaN( rid ) ? 0 : rid );
		} );
		if ( ids.size < 1 ) {
			ids.add( 0 );
		}
		return Array.from( ids )
			.sort( ( a, b ) => a - b )
			.map( ( id ) => ( {
				id,
				title:
					id > 0
						? sprintf(
								/* translators: %d: resource id (kennel / room) */
								__( 'Resource %d', 'kennelflow-core' ),
								id
						  )
						: __( 'Unassigned', 'kennelflow-core' ),
			} ) );
	}, [ bookings, explicitResources ] );

	const eventsByResource = useMemo( () => {
		const map = {};
		resources.forEach( ( r ) => {
			map[ r.id ] = [];
		} );
		( bookings || [] ).forEach( ( b ) => {
			const rid = parseInt( b.resource_id, 10 );
			const key = Number.isNaN( rid ) ? 0 : rid;
			if ( ! map[ key ] ) {
				map[ key ] = [];
			}
			map[ key ].push( b );
		} );
		return map;
	}, [ bookings, resources ] );

	const shiftWeek = ( delta ) => {
		setRange( ( prev ) => ( {
			start: addDaysYmd( prev.start, 7 * delta ),
			end: addDaysYmd( prev.end, 7 * delta ),
		} ) );
	};

	const dragRef = useRef( null );

	const onPointerDownEvent = useCallback(
		( ev, booking ) => {
			ev.preventDefault();
			const el = ev.currentTarget;
			const track = el.closest( '.kf-cal-track' );
			if ( ! track ) {
				return;
			}
			const trackRect = track.getBoundingClientRect();
			dragRef.current = {
				id: booking.id,
				booking: { ...booking },
				startPointerX: ev.clientX,
				startPointerY: ev.clientY,
				trackRect,
				trackEl: track,
			};
			el.classList.add( 'kf-cal-event--dragging' );
			el.setPointerCapture( ev.pointerId );
		},
		[]
	);

	const onPointerMoveEvent = useCallback( ( ev ) => {
		const d = dragRef.current;
		if ( ! d || ! d.trackEl ) {
			return;
		}
		const dx = ev.clientX - d.startPointerX;
		const dy = ev.clientY - d.startPointerY;
		const bar = d.trackEl.querySelector( `.kf-cal-event[data-booking-id="${ d.id }"]` );
		if ( bar ) {
			bar.style.transform = `translate(${ dx }px,${ dy }px)`;
		}
	}, [] );

	const commitDrag = useCallback(
		async ( ev, d ) => {
			if ( ! d ) {
				return;
			}

			const dx = ev.clientX - d.startPointerX;
			const tw = d.trackRect.width;
			const spanMs = weekEnd.getTime() - weekStart.getTime();
			const deltaMs = tw > 0 ? ( dx / tw ) * spanMs : 0;

			const start = parseMysqlUtc( d.booking.start_gmt );
			const end = parseMysqlUtc( d.booking.end_gmt );
			const newStart = new Date( start.getTime() + deltaMs );
			const newEnd = new Date( end.getTime() + deltaMs );

			let newResourceId = parseInt( d.booking.resource_id, 10 );
			if ( Number.isNaN( newResourceId ) ) {
				newResourceId = 0;
			}
			document.querySelectorAll( '[data-kf-resource-row]' ).forEach( ( node ) => {
				const r = node.getBoundingClientRect();
				if ( ev.clientY >= r.top && ev.clientY <= r.bottom ) {
					const raw = node.getAttribute( 'data-kf-resource-row' );
					const parsed = parseInt( raw, 10 );
					newResourceId = Number.isNaN( parsed ) ? 0 : parsed;
				}
			} );

			const patch = {
				start_gmt: newStart.toISOString().replace( 'T', ' ' ).slice( 0, 19 ),
				end_gmt: newEnd.toISOString().replace( 'T', ' ' ).slice( 0, 19 ),
				resource_id: newResourceId,
			};

			const previousState = queryClient.getQueryData( calendarQueryKey );
			const previousSnapshot =
				undefined !== previousState
					? cloneCalendarQueryData( previousState )
					: undefined;

			queryClient.setQueryData( calendarQueryKey, ( old ) => {
				if ( ! old ) {
					return old;
				}
				return {
					...old,
					bookings: ( old.bookings || [] ).map( ( b ) =>
						b.id === d.id ? { ...b, ...patch } : b
					),
				};
			} );

			try {
				await apiFetch( {
					path: `/kennelflow/v1/calendar/booking/${ d.id }`,
					method: 'PATCH',
					data: patch,
				} );
			} catch ( err ) {
				if ( undefined !== previousSnapshot ) {
					queryClient.setQueryData(
						calendarQueryKey,
						previousSnapshot
					);
				}
				showCalendarDragErrorToast(
					getCalendarPatchErrorMessage( err )
				);
			} finally {
				await queryClient.invalidateQueries( {
					queryKey: [ 'calendar' ],
				} );
			}
		},
		[ calendarQueryKey, queryClient, weekEnd, weekStart ]
	);

	const onPointerUpEvent = useCallback(
		( ev ) => {
			const d = dragRef.current;
			if ( ! d ) {
				return;
			}
			dragRef.current = null;
			const bar = d.trackEl?.querySelector(
				`.kf-cal-event[data-booking-id="${ d.id }"]`
			);
			if ( bar ) {
				bar.classList.remove( 'kf-cal-event--dragging' );
				bar.style.transform = '';
			}
			try {
				if ( bar ) {
					bar.releasePointerCapture( ev.pointerId );
				}
			} catch ( e ) {
				void e;
			}
			if ( 'pointercancel' === ev.type ) {
				return;
			}
			const dx = ev.clientX - d.startPointerX;
			const dy = ev.clientY - d.startPointerY;
			const moved = Math.abs( dx ) > 8 || Math.abs( dy ) > 8;
			if ( ! moved ) {
				setPopoverBooking( d.booking );
				return;
			}
			void commitDrag( ev, d );
		},
		[ commitDrag ]
	);

	useEffect( () => {
		if ( ! popoverBooking ) {
			return;
		}
		const onKey = ( e ) => {
			if ( 'Escape' === e.key ) {
				setPopoverBooking( null );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'keydown', onKey );
		};
	}, [ popoverBooking ] );

	const popoverResourceTitle = useMemo( () => {
		if ( ! popoverBooking ) {
			return '';
		}
		const rid = parseInt( popoverBooking.resource_id, 10 );
		const key = Number.isNaN( rid ) ? 0 : rid;
		const row = resources.find( ( r ) => r.id === key );
		return row && row.title ? String( row.title ) : '';
	}, [ popoverBooking, resources ] );

	const openRunCardPrint = useCallback( () => {
		if ( ! popoverBooking ) {
			return;
		}
		const bid = parseInt( popoverBooking.booking_post_id, 10 );
		const s =
			typeof window !== 'undefined' && window.kfCalendarSettings
				? window.kfCalendarSettings
				: {};
		const admin = s.admin_url || '';
		const nonce = s.kennelpress_print_run_card_nonce || '';
		if ( bid < 1 || ! admin || ! nonce ) {
			return;
		}
		const url = `${ admin }admin-post.php?action=kennelpress_print_run_card&booking_id=${ bid }&_wpnonce=${ encodeURIComponent(
			nonce
		) }`;
		window.open( url, '_blank', 'noopener,noreferrer' );
	}, [ popoverBooking ] );

	const runCardPrintReady = useMemo( () => {
		if ( ! popoverBooking || ! isBoardingBooking( popoverBooking ) ) {
			return false;
		}
		const bid = parseInt( popoverBooking.booking_post_id, 10 );
		if ( bid < 1 ) {
			return false;
		}
		const nonce =
			typeof window !== 'undefined' &&
			window.kfCalendarSettings &&
			window.kfCalendarSettings.kennelpress_print_run_card_nonce
				? window.kfCalendarSettings.kennelpress_print_run_card_nonce
				: '';
		return '' !== String( nonce ).trim();
	}, [ popoverBooking ] );

	useEffect( () => {
		window.addEventListener( 'pointermove', onPointerMoveEvent );
		window.addEventListener( 'pointerup', onPointerUpEvent );
		window.addEventListener( 'pointercancel', onPointerUpEvent );
		return () => {
			window.removeEventListener( 'pointermove', onPointerMoveEvent );
			window.removeEventListener( 'pointerup', onPointerUpEvent );
			window.removeEventListener( 'pointercancel', onPointerUpEvent );
		};
	}, [ onPointerMoveEvent, onPointerUpEvent ] );

	if ( calendarQuery.isPending && undefined === calendarQuery.data ) {
		return <div className="kf-cal-loading">{ __( 'Loading…', 'kennelflow-core' ) }</div>;
	}

	return (
		<div className="kf-cal-wrap">
			<div className="kf-cal-toolbar">
				<button type="button" onClick={ () => shiftWeek( -1 ) }>
					{ __( '← Previous week', 'kennelflow-core' ) }
				</button>
				<button type="button" onClick={ () => shiftWeek( 1 ) }>
					{ __( 'Next week →', 'kennelflow-core' ) }
				</button>
				{ kennelpressBookingsUrl ? (
					<button
						type="button"
						className="button button-primary"
						onClick={ () => setBookingModalOpen( true ) }
					>
						{ __( 'Add booking', 'kennelflow-core' ) }
					</button>
				) : null }
				<span>
					{ sprintf(
						/* translators: 1: start Y-m-d, 2: end Y-m-d */
						__( '%1$s – %2$s (UTC)', 'kennelflow-core' ),
						range.start,
						range.end
					) }
				</span>
			</div>
			<div className="kf-cal-legend">
				<span className="kf-legend-boarding">
					<i />
					{ __( 'Boarding (multi-day)', 'kennelflow-core' ) }
				</span>
				<span className="kf-legend-clinic">
					<i />
					{ __( 'Clinic (timed)', 'kennelflow-core' ) }
				</span>
				{ 'grooming' === ( bookingKind || '' ).toLowerCase() && (
					<span className="kf-legend-grooming">
						<i />
						{ __( 'Grooming', 'kennelflow-core' ) }
					</span>
				) }
			</div>
			<div className="kf-cal-grid" role="grid" aria-label={ __( 'Occupancy calendar', 'kennelflow-core' ) }>
				<div className="kf-cal-grid-head">
					<div className="kf-cal-corner">
						{ cornerLabel && String( cornerLabel ).trim() !== ''
							? cornerLabel
							: __( 'Resource', 'kennelflow-core' ) }
					</div>
					{ dayLabels.map( ( label, i ) => (
						<div key={ i } className="kf-cal-day">
							{ label }
						</div>
					) ) }
				</div>
				{ resources.map( ( res ) => (
					<div className="kf-cal-row" key={ res.id }>
						<div
							className="kf-cal-resource"
							data-kf-resource-row={ String( res.id ) }
						>
							{ res.title }
						</div>
						<div
							className="kf-cal-track"
							data-kf-resource-row={ String( res.id ) }
						>
							{ Array.from( { length: 7 } ).map( ( _, i ) => (
								<div key={ i } className="kf-cal-daycell" />
							) ) }
							{ ( eventsByResource[ res.id ] || [] ).map( ( b ) => {
								const layout = layoutEventInWeek( b, weekStart, weekEnd );
								if ( ! layout ) {
									return null;
								}
								const clinic = isClinicBooking( b );
								const grooming = isGroomingBooking( b );
								let eventMod = 'kf-cal-event--boarding';
								if ( clinic ) {
									eventMod = 'kf-cal-event--clinic';
								} else if ( grooming ) {
									eventMod = 'kf-cal-event--grooming';
								}
								const title = `${ b.pet_name } · ${ b.owner_name || '—' }`;
								return (
									<div
										key={ b.id }
										className={ `kf-cal-event ${ eventMod }` }
										data-booking-id={ b.id }
										style={ {
											left: `${ layout.left }%`,
											width: `${ layout.width }%`,
										} }
										title={ title }
										onPointerDown={ ( e ) => onPointerDownEvent( e, b ) }
										role="button"
										tabIndex={ 0 }
									>
										{ title }
									</div>
								);
							} ) }
						</div>
					</div>
				) ) }
			</div>
			{ 0 === ( bookings || [] ).length && (
				<p className="kf-cal-empty">{ __( 'No bookings in this range.', 'kennelflow-core' ) }</p>
			) }

			{ bookingModalOpen ? (
				<BookingModal
					open={ bookingModalOpen }
					onClose={ () => setBookingModalOpen( false ) }
					weekRange={ range }
				/>
			) : null }

			{ popoverBooking ? (
				<div
					className="kf-cal-popover-backdrop"
					role="presentation"
					onClick={ () => setPopoverBooking( null ) }
				>
					<div
						className="kf-cal-popover"
						role="dialog"
						aria-modal="true"
						aria-label={ __( 'Booking details', 'kennelflow-core' ) }
						onClick={ ( e ) => e.stopPropagation() }
					>
						<div className="kf-cal-popover__header">
							<h2 className="kf-cal-popover__title">
								{ popoverBooking.pet_name
									? popoverBooking.pet_name
									: __( 'Booking', 'kennelflow-core' ) }
							</h2>
							<button
								type="button"
								className="kf-cal-popover__close"
								onClick={ () => setPopoverBooking( null ) }
								aria-label={ __( 'Close', 'kennelflow-core' ) }
							>
								×
							</button>
						</div>
						<div className="kf-cal-popover__body">
							<p className="kf-cal-popover__line">
								<strong>{ __( 'Owner', 'kennelflow-core' ) }</strong>{ ' ' }
								{ popoverBooking.owner_name &&
								String( popoverBooking.owner_name ).trim() !== ''
									? popoverBooking.owner_name
									: '—' }
							</p>
							<p className="kf-cal-popover__line">
								<strong>{ __( 'Kennel / resource', 'kennelflow-core' ) }</strong>{ ' ' }
								{ popoverResourceTitle && popoverResourceTitle.trim() !== ''
									? popoverResourceTitle
									: sprintf(
											/* translators: %d: numeric resource id */
											__( 'Resource %d', 'kennelflow-core' ),
											parseInt( popoverBooking.resource_id, 10 ) || 0
									  ) }
							</p>
							<p className="kf-cal-popover__line">
								<strong>{ __( 'Stay', 'kennelflow-core' ) }</strong>
								<br />
								{ formatPopoverDateTime( popoverBooking.start_gmt ) }
								{ ' → ' }
								{ formatPopoverDateTime( popoverBooking.end_gmt ) }
							</p>
							{ isBoardingBooking( popoverBooking ) ? (
								<div className="kf-cal-popover__actions">
									{ runCardPrintReady ? (
										<button
											type="button"
											className="button button-primary"
											onClick={ openRunCardPrint }
										>
											{ __( 'Print run card', 'kennelflow-core' ) }
										</button>
									) : (
										<p className="kf-cal-popover__hint">
											{ parseInt( popoverBooking.booking_post_id, 10 ) < 1
												? __(
														'Run card needs a Kennel Press booking record linked to this stay.',
														'kennelflow-core'
												  )
												: __(
														'Print run card is unavailable (Kennel Press not loaded or permissions).',
														'kennelflow-core'
												  ) }
										</p>
									) }
								</div>
							) : null }
						</div>
					</div>
				</div>
			) : null }
		</div>
	);
}

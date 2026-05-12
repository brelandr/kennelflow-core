/**
 * Location-timezone datetime picker with calendar popover (admin booking modal).
 *
 * @package KennelFlow
 */

import {
	useState,
	useMemo,
	useEffect,
	useLayoutEffect,
	useRef,
	useCallback,
} from '@wordpress/element';
import { createPortal } from 'react-dom';
import { __, sprintf } from '@wordpress/i18n';
import { DateTime } from 'luxon';

const SLOT_MINUTES = 15;

/**
 * @param {string} z IANA zone
 * @return {string}
 */
function safeZone( z ) {
	if ( ! z || 'string' !== typeof z ) {
		return 'UTC';
	}
	const t = DateTime.now().setZone( z.trim() );
	return t.isValid ? z.trim() : 'UTC';
}

/**
 * @param {string} utcSql Y-m-d H:i:s UTC
 * @param {string} zone   IANA
 * @return {DateTime}
 */
function utcSqlToZoned( utcSql, zone ) {
	const z = safeZone( zone );
	const raw = ( utcSql || '' ).trim();
	if ( '' === raw ) {
		return DateTime.now().setZone( z );
	}
	let d = DateTime.fromSQL( raw, { zone: 'utc' } );
	if ( ! d.isValid ) {
		d = DateTime.fromISO( raw.replace( ' ', 'T' ) + 'Z', { zone: 'utc' } );
	}
	if ( ! d.isValid ) {
		return DateTime.now().setZone( z );
	}
	return d.setZone( z );
}

/**
 * @param {DateTime} localInZone Wall time in facility zone
 * @return {string} Y-m-d H:i:s UTC
 */
function zonedToUtcSql( localInZone ) {
	if ( ! localInZone || ! localInZone.isValid ) {
		return '';
	}
	return localInZone.setZone( 'utc' ).toFormat( 'yyyy-MM-dd HH:mm:ss' );
}

/**
 * Build month grid (weeks x 7) for a given month, Monday-first rows.
 *
 * @param {DateTime} monthStart First day of month in zone
 * @return {DateTime[][]}
 */
function buildMonthWeeks( monthStart ) {
	const first = monthStart.startOf( 'month' );
	const daysSinceMon = ( first.weekday + 6 ) % 7;
	let cur = first.minus( { days: daysSinceMon } );
	const weeks = [];
	for ( let w = 0; w < 6; w++ ) {
		const row = [];
		for ( let i = 0; i < 7; i++ ) {
			row.push( cur );
			cur = cur.plus( { days: 1 } );
		}
		weeks.push( row );
	}
	return weeks;
}

/**
 * @param {object} props
 * @param {string} props.id
 * @param {string} props.label
 * @param {string} props.valueUtc  Y-m-d H:i:s UTC
 * @param {Function} props.onChangeUtc
 * @param {string} props.ianaZone  Kennel rules timezone for selected location
 */
export function BookingDatetimePicker( {
	id,
	label,
	valueUtc,
	onChangeUtc,
	ianaZone,
} ) {
	const zone = useMemo( () => safeZone( ianaZone ), [ ianaZone ] );
	const anchorRef = useRef( null );
	const popoverRef = useRef( null );
	const [ open, setOpen ] = useState( false );
	const [ popoverStyle, setPopoverStyle ] = useState( {} );
	const [ viewMonth, setViewMonth ] = useState( () =>
		utcSqlToZoned( valueUtc, zone ).startOf( 'month' )
	);
	const [ draft, setDraft ] = useState( () => utcSqlToZoned( valueUtc, zone ) );

	const displayZoned = useMemo(
		() => utcSqlToZoned( valueUtc, zone ),
		[ valueUtc, zone ]
	);

	useEffect( () => {
		if ( open ) {
			const d = utcSqlToZoned( valueUtc, zone );
			setDraft( d );
			setViewMonth( d.startOf( 'month' ) );
		}
	}, [ open, valueUtc, zone ] );

	const updatePopoverPosition = useCallback( () => {
		const el = anchorRef.current;
		if ( ! el ) {
			return;
		}
		const rect = el.getBoundingClientRect();
		const margin = 6;
		const popW = 300;
		const popH = 380;
		let left = rect.left;
		let top = rect.bottom + margin;
		if ( left + popW > window.innerWidth - margin ) {
			left = Math.max( margin, window.innerWidth - popW - margin );
		}
		if ( top + popH > window.innerHeight - margin ) {
			top = Math.max( margin, rect.top - popH - margin );
		}
		setPopoverStyle( {
			position: 'fixed',
			top: `${ Math.round( top ) }px`,
			left: `${ Math.round( left ) }px`,
			zIndex: 100002,
		} );
	}, [] );

	useLayoutEffect( () => {
		if ( ! open ) {
			return;
		}
		updatePopoverPosition();
		const onScrollResize = () => updatePopoverPosition();
		window.addEventListener( 'scroll', onScrollResize, true );
		window.addEventListener( 'resize', onScrollResize );
		return () => {
			window.removeEventListener( 'scroll', onScrollResize, true );
			window.removeEventListener( 'resize', onScrollResize );
		};
	}, [ open, updatePopoverPosition, viewMonth ] );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const onDoc = ( ev ) => {
			const t = ev.target;
			if ( ! t ) {
				return;
			}
			const inAnchor =
				anchorRef.current && anchorRef.current.contains( t );
			const inPopover =
				popoverRef.current && popoverRef.current.contains( t );
			if ( ! inAnchor && ! inPopover ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', onDoc, true );
		return () => document.removeEventListener( 'mousedown', onDoc, true );
	}, [ open ] );

	const weeks = useMemo( () => buildMonthWeeks( viewMonth ), [ viewMonth ] );

	const utcDisplay = useMemo( () => {
		const u = zonedToUtcSql( displayZoned );
		return u || '—';
	}, [ displayZoned ] );

	const slotOptions = useMemo( () => {
		const out = [];
		for ( let m = 0; m < 24 * 60; m += SLOT_MINUTES ) {
			const h = Math.floor( m / 60 );
			const mm = m % 60;
			const labelSlot = DateTime.fromObject(
				{ hour: h, minute: mm, second: 0 },
				{ zone: 'utc' }
			).toFormat( 'HH:mm' );
			out.push( { h, mm, label: labelSlot } );
		}
		return out;
	}, [] );

	const onPickDay = useCallback( ( day ) => {
		if ( ! day || ! day.isValid ) {
			return;
		}
		setDraft( ( prev ) =>
			prev.set( {
				year: day.year,
				month: day.month,
				day: day.day,
				second: 0,
				millisecond: 0,
			} )
		);
	}, [] );

	const onApply = useCallback( () => {
		onChangeUtc( zonedToUtcSql( draft ) );
		setOpen( false );
	}, [ draft, onChangeUtc ] );

	const monthLabel = viewMonth.toFormat( 'LLLL yyyy' );

	const popoverContent = open ? (
		<div
			ref={ popoverRef }
			className="kf-dt-picker__popover"
			style={ popoverStyle }
			role="dialog"
			aria-label={ __( 'Choose date and time', 'kennelflow-core' ) }
			onClick={ ( e ) => e.stopPropagation() }
			onMouseDown={ ( e ) => e.stopPropagation() }
		>
			<div className="kf-dt-picker__nav">
				<button
					type="button"
					className="button"
					onClick={ () =>
						setViewMonth( ( m ) => m.minus( { months: 1 } ) )
					}
				>
					‹
				</button>
				<span className="kf-dt-picker__month">{ monthLabel }</span>
				<button
					type="button"
					className="button"
					onClick={ () =>
						setViewMonth( ( m ) => m.plus( { months: 1 } ) )
					}
				>
					›
				</button>
			</div>

			<div className="kf-dt-picker__weekdays" aria-hidden="true">
				{ [ 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ].map( ( w ) => (
					<span key={ w } className="kf-dt-picker__wd">
						{ w }
					</span>
				) ) }
			</div>

			<div className="kf-dt-picker__grid">
				{ weeks.map( ( row, ri ) => (
					<div key={ `w${ ri }` } className="kf-dt-picker__row">
						{ row.map( ( day ) => {
							const inMonth = day.month === viewMonth.month;
							const isSel =
								day.hasSame( draft, 'day' ) &&
								day.hasSame( draft, 'month' ) &&
								day.hasSame( draft, 'year' );
							return (
								<button
									key={ day.toISODate() }
									type="button"
									className={
										'kf-dt-picker__day' +
										( ! inMonth ? ' is-muted' : '' ) +
										( isSel ? ' is-selected' : '' )
									}
									onClick={ () => onPickDay( day ) }
								>
									{ day.day }
								</button>
							);
						} ) }
					</div>
				) ) }
			</div>

			<div className="kf-dt-picker__time">
				<label htmlFor={ `${ id }-time` }>
					{ __( 'Time', 'kennelflow-core' ) }
				</label>
				<select
					id={ `${ id }-time` }
					className="widefat"
					value={ `${ String( draft.hour ).padStart( 2, '0' ) }:${ String(
						draft.minute
					).padStart( 2, '0' ) }` }
					onChange={ ( e ) => {
						const [ hs, ms ] = e.target.value.split( ':' );
						const hour = parseInt( hs, 10 ) || 0;
						const minute = parseInt( ms, 10 ) || 0;
						setDraft(
							draft.set( {
								hour,
								minute,
								second: 0,
								millisecond: 0,
							} )
						);
					} }
				>
					{ slotOptions.map( ( s ) => (
						<option
							key={ s.label }
							value={ `${ String( s.h ).padStart( 2, '0' ) }:${ String(
								s.mm
							).padStart( 2, '0' ) }` }
						>
							{ s.label }
						</option>
					) ) }
				</select>
			</div>

			<div className="kf-dt-picker__actions">
				<button
					type="button"
					className="button"
					onClick={ () => setOpen( false ) }
				>
					{ __( 'Cancel', 'kennelflow-core' ) }
				</button>
				<button
					type="button"
					className="button button-primary"
					onClick={ onApply }
				>
					{ __( 'Apply', 'kennelflow-core' ) }
				</button>
			</div>
		</div>
	) : null;

	return (
		<div className="kf-dt-picker">
			<label htmlFor={ `${ id }-toggle` }>{ label }</label>
			<button
				type="button"
				id={ `${ id }-toggle` }
				ref={ anchorRef }
				className="kf-dt-picker__toggle button"
				onClick={ ( e ) => {
					e.stopPropagation();
					setOpen( ( v ) => ! v );
				} }
				aria-expanded={ open }
				aria-haspopup="dialog"
			>
				<span className="kf-dt-picker__toggle-main">
					{ displayZoned.toFormat( 'ccc, LLL d yyyy, HH:mm' ) }
				</span>
				<span className="kf-dt-picker__toggle-sub">
					{ sprintf(
						/* translators: %s: IANA timezone string */
						__( 'Location: %s', 'kennelflow-core' ),
						zone
					) }
				</span>
				<span className="kf-dt-picker__toggle-sub kf-dt-picker__toggle-utc">
					{ sprintf(
						/* translators: %s: UTC datetime */
						__( 'Stored (UTC): %s', 'kennelflow-core' ),
						utcDisplay
					) }
				</span>
			</button>

			{ typeof document !== 'undefined' && popoverContent
				? createPortal( popoverContent, document.body )
				: null }
		</div>
	);
}

/**
 * Always-visible admin debug panel for Hub calendar troubleshooting.
 *
 * @package KennelFlow
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {object}   props
 * @param {boolean}  props.bookingModalOpen
 * @param {string}   props.debugAction
 * @param {object}   props.settings
 * @param {Function} props.onForceOpenModal
 * @param {Error|null} props.lastError
 * @return {import('react').JSX.Element|null}
 */
export function CalendarDebugPanel( {
	bookingModalOpen,
	debugAction,
	settings,
	onForceOpenModal,
	lastError,
} ) {
	if ( ! settings || ! settings.show_debug_panel ) {
		return null;
	}

	const diag = settings.add_booking_diagnostics || {};

	return (
		<div className="kf-cal-debug-panel" id="kf-cal-debug-panel">
			<p className="kf-cal-debug-panel__title">
				<strong>{ __( 'KennelFlow calendar debug', 'kennelflow-core' ) }</strong>
			</p>
			<ul className="kf-cal-debug-panel__list">
				<li>
					{ __( 'Core version:', 'kennelflow-core' ) }{ ' ' }
					<code>{ settings.script_version || diag.core_version || '?' }</code>
				</li>
				<li>
					{ __( 'Boarding active:', 'kennelflow-core' ) }{ ' ' }
					<code>{ diag.boarding_active ? 'yes' : 'no' }</code>
					{ diag.boarding_version ? (
						<>
							{ ' ' }
							<code>{ diag.boarding_version }</code>
						</>
					) : null }
				</li>
				<li>
					{ __( 'Add booking ready:', 'kennelflow-core' ) }{ ' ' }
					<code>{ diag.ready ? 'yes' : 'no' }</code>
				</li>
				<li>
					{ __( 'Modal state:', 'kennelflow-core' ) }{ ' ' }
					<code>{ bookingModalOpen ? 'open' : 'closed' }</code>
				</li>
				<li>
					{ __( 'Last action:', 'kennelflow-core' ) }{ ' ' }
					<code>{ debugAction || '—' }</code>
				</li>
				<li>
					{ __( 'Bookings URL set:', 'kennelflow-core' ) }{ ' ' }
					<code>{ settings.kennelpress_bookings_url ? 'yes' : 'no' }</code>
				</li>
			</ul>
			{ lastError ? (
				<p className="kf-cal-debug-panel__error">
					<strong>{ __( 'Last error:', 'kennelflow-core' ) }</strong>{ ' ' }
					{ lastError.message }
				</p>
			) : null }
			{ ! diag.ready && Array.isArray( diag.issues ) && diag.issues.length > 0 ? (
				<ul className="kf-cal-diagnostics__list">
					{ diag.issues.map( ( issue, index ) => (
						<li key={ index }>{ issue }</li>
					) ) }
				</ul>
			) : null }
			<p className="kf-cal-debug-panel__actions">
				<button type="button" className="button" onClick={ onForceOpenModal }>
					{ __( 'Force open Add booking modal', 'kennelflow-core' ) }
				</button>
			</p>
			<details className="kf-cal-diagnostics__debug">
				<summary>{ __( 'Raw kfCalendarSettings', 'kennelflow-core' ) }</summary>
				<pre>{ JSON.stringify( settings, null, 2 ) }</pre>
			</details>
			<p className="description">
				{ __(
					'Open the browser console (F12) and filter for “KennelFlow Calendar”.',
					'kennelflow-core'
				) }
			</p>
		</div>
	);
}

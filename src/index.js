/**
 * Admin Calendar — mounts CalendarGrid and configures REST (api-fetch) from PHP.
 *
 * @package KennelFlow
 */

import apiFetch from '@wordpress/api-fetch';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from '@wordpress/element';
import { CalendarGrid } from './CalendarGrid';
import { CalendarErrorBoundary } from './CalendarErrorBoundary';
import { logCalendarTrace } from './calendarDebug';

const settings =
	typeof window !== 'undefined' && window.kfCalendarSettings
		? window.kfCalendarSettings
		: {};

if ( settings.rest_url && 'function' === typeof apiFetch.createRootURLMiddleware ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest_url ) );
}
if ( settings.nonce && 'function' === typeof apiFetch.createNonceMiddleware ) {
	apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );
}

const queryClient = new QueryClient(
	{
		defaultOptions: {
			queries: {
				staleTime: 30_000,
			},
		},
	}
);

/** @type {WeakSet<HTMLElement>} */
const mountedRoots = new WeakSet();

/**
 * @param {HTMLElement|null} mountEl Root container.
 * @return {void}
 */
function mountCalendarGrid( mountEl ) {
	if ( ! mountEl || mountedRoots.has( mountEl ) ) {
		return;
	}

	mountedRoots.add( mountEl );

	const start       = mountEl.dataset.startDate || '';
	const end         = mountEl.dataset.endDate || '';
	const bookingKind = mountEl.dataset.bookingKind || '';
	const cornerLabel = mountEl.dataset.cornerLabel || '';

	createRoot( mountEl ).render(
		<QueryClientProvider client={ queryClient }>
			<CalendarErrorBoundary>
				<CalendarGrid
					initialStartDate={ start }
					initialEndDate={ end }
					bookingKind={ bookingKind }
					cornerLabel={ cornerLabel }
				/>
			</CalendarErrorBoundary>
		</QueryClientProvider>
	);
}

/**
 * Mount all Hub calendar roots (admin + GroomPress schedule).
 *
 * @return {void}
 */
function mountAllHubCalendars() {
	mountCalendarGrid( document.getElementById( 'kf-admin-calendar-root' ) );
	mountCalendarGrid( document.getElementById( 'groompress-admin-calendar-root' ) );
}

if ( typeof window !== 'undefined' ) {
	window.kfMountHubCalendars = mountAllHubCalendars;
	window.kfCalendarDebug = {
		getSettings: () =>
			typeof window.kfCalendarSettings !== 'undefined'
				? window.kfCalendarSettings
				: null,
		remount: () => mountAllHubCalendars(),
		openBookingModal: () => {
			logCalendarTrace( 'window.kfCalendarDebug.openBookingModal()', null );
			document.dispatchEvent(
				new CustomEvent( 'kf-cal-debug-open-modal', { bubbles: true } )
			);
		},
	};
	logCalendarTrace( 'Calendar bundle loaded', {
		version: settings.script_version || settings.add_booking_diagnostics?.core_version,
		show_debug_panel: settings.show_debug_panel,
	} );
}

function bootHubCalendars() {
	mountAllHubCalendars();
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', bootHubCalendars );
} else {
	bootHubCalendars();
}

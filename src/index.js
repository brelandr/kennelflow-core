/**
 * Admin Calendar — mounts CalendarGrid and configures REST (api-fetch) from PHP.
 *
 * @package KennelFlow
 */

import apiFetch from '@wordpress/api-fetch';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from '@wordpress/element';
import { CalendarGrid } from './CalendarGrid';

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

/**
 * @param {HTMLElement|null} mountEl Root container.
 * @return {void}
 */
function mountCalendarGrid( mountEl ) {
	if ( ! mountEl ) {
		return;
	}
	const start                      = mountEl.dataset.startDate || '';
	const end                        = mountEl.dataset.endDate || '';
	const bookingKind                = mountEl.dataset.bookingKind || '';
	const cornerLabel                = mountEl.dataset.cornerLabel || '';
	createRoot( mountEl ).render(
		<QueryClientProvider client={ queryClient }>
			<CalendarGrid
				initialStartDate={ start }
				initialEndDate={ end }
				bookingKind={ bookingKind }
				cornerLabel={ cornerLabel }
			/>
		</QueryClientProvider>
	);
}

mountCalendarGrid( document.getElementById( 'kf-admin-calendar-root' ) );
mountCalendarGrid( document.getElementById( 'groompress-admin-calendar-root' ) );

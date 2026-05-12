/**
 * Staff Permissions admin screen — mounts PermissionsMatrix with React Query + api-fetch.
 *
 * @package KennelFlow
 */

import apiFetch from '@wordpress/api-fetch';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from '@wordpress/element';

import { PermissionsMatrixApp } from './PermissionsMatrix';

const settings =
	typeof window !== 'undefined' && window.kfPermissionsMatrixSettings
		? window.kfPermissionsMatrixSettings
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
				staleTime: 60_000,
			},
		},
	}
);

const rootEl = document.getElementById( 'kf-permissions-matrix-root' );
if ( rootEl ) {
	createRoot( rootEl ).render(
		< QueryClientProvider client        = { queryClient } >
			< PermissionsMatrixApp apiFetch = { apiFetch } / >
		< / QueryClientProvider >
	);
}

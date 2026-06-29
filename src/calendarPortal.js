/**
 * Render modal/popover into document.body with react-dom fallback.
 *
 * @package KennelFlow
 */

import { createPortal as elementCreatePortal } from '@wordpress/element';
import { createPortal as reactDomCreatePortal } from 'react-dom';

import { logCalendarTrace } from './calendarDebug';

/**
 * @param {import('react').ReactNode} children
 * @param {HTMLElement|null|undefined} target
 * @return {import('react').ReactNode}
 */
export function renderInBodyPortal( children, target ) {
	if ( ! target ) {
		logCalendarTrace( 'Portal target missing; rendering inline', null );
		return children;
	}

	const portalFn =
		'function' === typeof elementCreatePortal
			? elementCreatePortal
			: 'function' === typeof reactDomCreatePortal
			? reactDomCreatePortal
			: null;

	if ( ! portalFn ) {
		logCalendarTrace( 'createPortal unavailable; rendering inline', null );
		return children;
	}

	try {
		return portalFn( children, target );
	} catch ( err ) {
		logCalendarTrace( 'createPortal failed; rendering inline', err );
		return children;
	}
}

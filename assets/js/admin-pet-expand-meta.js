/**
 * Expand all meta boxes on the kf_pet edit screen (classic + block editor meta area).
 *
 * WordPress remembers closed boxes per user; this runs after load so panels are visible without clicking.
 */
(function () {
	'use strict';

	function expandAll() {
		var closed = document.querySelectorAll( '.postbox.closed' );
		if ( ! closed.length ) {
			return;
		}
		closed.forEach(
			function ( box ) {
				box.classList.remove( 'closed' );
				var inside = box.querySelector( '.inside' );
				if ( inside ) {
						inside.removeAttribute( 'hidden' );
						inside.style.display = '';
				}
				var headers = box.querySelectorAll( '.postbox-header button' );
				headers.forEach(
					function ( btn ) {
						btn.setAttribute( 'aria-expanded', 'true' );
					}
				);
			}
		);
	}

	function schedule() {
		expandAll();
		[ 50, 150, 400, 1000, 2500 ].forEach(
			function ( ms ) {
				setTimeout( expandAll, ms );
			}
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}
})();

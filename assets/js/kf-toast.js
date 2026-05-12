/**
 * Non-blocking toasts (front-end) and admin `.notice` messages (wp-admin).
 *
 * @package KennelFlow
 */
( function () {
	'use strict';

	function isWpAdmin() {
		return (
			document.body &&
			( document.body.classList.contains( 'wp-admin' ) ||
				document.documentElement.classList.contains( 'wp-admin' ) )
		);
	}

	function escapeHtml( s ) {
		var d         = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}

	function noticeClassForType( type ) {
		if ( type === 'error' ) {
			return 'notice-error';
		}
		if ( type === 'success' ) {
			return 'notice-success';
		}
		if ( type === 'warning' ) {
			return 'notice-warning';
		}
		return 'notice-info';
	}

	/**
	 * Insert a WordPress admin dismissible notice (classic admin screens).
	 *
	 * @param {string} message Plain text.
	 * @param {string} type    success|error|warning|info
	 */
	function showAdminNotice( message, type ) {
		var wrap      =
			document.querySelector( '#wpbody-content' ) ||
			document.querySelector( '#wpcontent' ) ||
			document.body;
		var div       = document.createElement( 'div' );
		var nc        = noticeClassForType( type || 'info' );
		div.className = 'notice ' + nc + ' is-dismissible';
		div.setAttribute( 'role', 'alert' );

		var dismissSr =
			typeof kfToastConfig !== 'undefined' && kfToastConfig.dismissSr
				? kfToastConfig.dismissSr
				: 'Dismiss this notice.';

		div.innerHTML =
			'<p>' +
			escapeHtml( message ) +
			'</p>' +
			'<button type="button" class="notice-dismiss">' +
			'<span class="screen-reader-text">' +
			escapeHtml( dismissSr ) +
			'</span>' +
			'</button>';

		if ( wrap.firstChild ) {
			wrap.insertBefore( div, wrap.firstChild );
		} else {
			wrap.appendChild( div );
		}

		var btn = div.querySelector( '.notice-dismiss' );
		if ( btn ) {
			btn.addEventListener(
				'click',
				function () {
					if ( div.parentNode ) {
						div.parentNode.removeChild( div );
					}
				}
			);
		}
	}

	/**
	 * Fixed-position toast stack for the front-end.
	 *
	 * @param {string} message Plain text.
	 * @param {string} type    success|error|warning|info
	 */
	function showFrontendToast( message, type ) {
		var root = document.getElementById( 'kf-toast-root' );
		if ( ! root ) {
			root           = document.createElement( 'div' );
			root.id        = 'kf-toast-root';
			root.className = 'kf-toast-root';
			root.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( root );
		}
		var el       = document.createElement( 'div' );
		el.className = 'kf-toast kf-toast--' + ( type || 'info' );
		el.setAttribute( 'role', 'status' );
		el.textContent = message;
		root.appendChild( el );
		window.setTimeout(
			function () {
				el.classList.add( 'kf-toast--out' );
				window.setTimeout(
					function () {
						if ( el.parentNode ) {
								el.parentNode.removeChild( el );
						}
					},
					280
				);
			},
			6000
		);
	}

	/**
	 * @param {string} message Plain text.
	 * @param {object} opts    { type: 'error'|'success'|'warning'|'info' }
	 */
	function show( message, opts ) {
		opts = opts || {};
		if ( ! message ) {
			return;
		}
		var type = opts.type || 'info';
		if ( isWpAdmin() ) {
			showAdminNotice( String( message ), type );
		} else {
			showFrontendToast( String( message ), type );
		}
	}

	/**
	 * Non-blocking confirmation (replaces window.confirm).
	 *
	 * @param {string} message Plain text.
	 * @param {object} opts    confirmText, cancelText
	 * @return {Promise<boolean>}
	 */
	function confirm( message, opts ) {
		opts = opts || {};
		return new Promise(
			function ( resolve ) {
				var cancelLabel  =
				opts.cancelText ||
				( typeof kfToastConfig !== 'undefined' && kfToastConfig.cancel
				? kfToastConfig.cancel
				: 'Cancel' );
				var confirmLabel =
				opts.confirmText ||
				( typeof kfToastConfig !== 'undefined' && kfToastConfig.confirm
					? kfToastConfig.confirm
					: 'OK' );

				var overlay       = document.createElement( 'div' );
				overlay.className = 'kf-toast-confirm';
				overlay.setAttribute( 'role', 'dialog' );
				overlay.setAttribute( 'aria-modal', 'true' );
				overlay.setAttribute( 'aria-labelledby', 'kf-toast-confirm-title' );

				var boxClass = isWpAdmin() ? 'kf-toast-confirm__box kf-toast-confirm__box--admin' : 'kf-toast-confirm__box';

				overlay.innerHTML =
				'<div class="' +
				boxClass +
				'">' +
				'<p id="kf-toast-confirm-title" class="kf-toast-confirm__msg">' +
				escapeHtml( String( message ) ) +
				'</p>' +
				'<div class="kf-toast-confirm__actions">' +
				'<button type="button" class="button kf-toast-confirm__cancel">' +
				escapeHtml( cancelLabel ) +
				'</button>' +
				'<button type="button" class="button button-primary kf-toast-confirm__ok">' +
				escapeHtml( confirmLabel ) +
				'</button>' +
				'</div></div>';

				document.body.appendChild( overlay );

				function cleanup( result ) {
					if ( overlay.parentNode ) {
						overlay.parentNode.removeChild( overlay );
					}
					resolve( result );
				}

				var cancelBtn = overlay.querySelector( '.kf-toast-confirm__cancel' );
				var okBtn     = overlay.querySelector( '.kf-toast-confirm__ok' );
				if ( cancelBtn ) {
						cancelBtn.addEventListener(
							'click',
							function () {
								cleanup( false );
							}
						);
				}
				if ( okBtn ) {
					okBtn.addEventListener(
						'click',
						function () {
							cleanup( true );
						}
					);
				}
				overlay.addEventListener(
					'click',
					function ( e ) {
						if ( e.target === overlay ) {
							cleanup( false );
						}
					}
				);

				window.setTimeout(
					function () {
						if ( okBtn ) {
								okBtn.focus();
						}
					},
					10
				);
			}
		);
	}

	window.KFToast = {
		show: show,
		confirm: confirm,
	};
} )();

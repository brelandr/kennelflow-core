/**
 * Webhooks & API: test ping and “add another URL” row.
 *
 * @package KennelFlow
 */
( function () {
	'use strict';

	/**
	 * Test ping buttons (delegated).
	 */
	function findUrlInput( btn ) {
		var row = btn.closest( 'tr' );
		if ( ! row ) {
			return null;
		}
		return row.querySelector( 'input[type="url"][name^="kf_webhook_rows"]' );
	}

	function setResult( btn, text, ok ) {
		var wrap = btn.closest( '.kf-webhook-test-wrap' );
		if ( ! wrap ) {
			return;
		}
		var out = wrap.querySelector( '.kf-webhook-test-result' );
		if ( ! out ) {
			return;
		}
		out.textContent = text;
		out.classList.remove( 'kf-webhook-test-result--ok', 'kf-webhook-test-result--err' );
		if ( true === ok ) {
			out.classList.add( 'kf-webhook-test-result--ok' );
		} else if ( false === ok ) {
			out.classList.add( 'kf-webhook-test-result--err' );
		}
	}

	document.addEventListener(
		'click',
		function ( e ) {
			var btn = e.target.closest( '.kf-webhook-test-ping' );
			if ( ! btn || btn.disabled ) {
				return;
			}
			e.preventDefault();
			if ( 'undefined' === typeof kfWebhookTest ) {
				return;
			}
			var input = findUrlInput( btn );
			var url   = input && input.value ? String( input.value ).trim() : '';
			if ( ! url ) {
				setResult( btn, kfWebhookTest.strings.emptyUrl, false );
				return;
			}
			btn.disabled = true;
			setResult( btn, kfWebhookTest.strings.sending, null );
			var body = new window.URLSearchParams();
			body.set( 'action', kfWebhookTest.action );
			body.set( 'nonce', kfWebhookTest.nonce );
			body.set( 'webhook_url', url );
			window
			.fetch(
				kfWebhookTest.ajaxUrl,
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				}
			)
			.then(
				function ( r ) {
					return r.json();
				}
			)
			.then(
				function ( data ) {
					var msg = '';
					if ( data && data.success && data.data && data.data.message ) {
							msg = data.data.message;
							setResult( btn, msg, true );
					} else if ( data && data.data && data.data.message ) {
						msg = data.data.message;
						setResult( btn, msg, false );
					} else {
						setResult( btn, String( data && data.data ? JSON.stringify( data.data ) : '' ), false );
					}
				}
			)
			.catch(
				function () {
					setResult( btn, kfWebhookTest.strings.requestFailed, false );
				}
			)
			.finally(
				function () {
					btn.disabled = false;
				}
			);
		}
	);
}() );

( function () {
	'use strict';

	if ( 'undefined' === typeof kfWebhooksPage ) {
		return;
	}

	var btn = document.getElementById( 'kf-webhook-add-row' );
	if ( ! btn ) {
		return;
	}

	var tbody = btn.closest( 'table' );
	if ( ! tbody ) {
		return;
	}
	tbody      = tbody.querySelector( 'tbody' );
	var anchor = btn.closest( 'tr' );
	if ( ! tbody || ! anchor ) {
		return;
	}

	var nextIndex = parseInt( String( kfWebhooksPage.nextRowIndex || '0' ), 10 ) || 0;
	var labels    = kfWebhooksPage.eventLabels && 'object' === typeof kfWebhooksPage.eventLabels
		? kfWebhooksPage.eventLabels
		: {};
	var st        = kfWebhooksPage.strings || {};

	function buildEventCell( i ) {
		var cell  = document.createElement( 'td' );
		var slugs = Object.keys( labels );
		for ( var k = 0; k < slugs.length; k++ ) {
			var slug            = slugs[ k ];
			var label           = document.createElement( 'label' );
			label.style.display = 'block';
			label.style.margin  = '0.25rem 0';
			var input           = document.createElement( 'input' );
			input.type          = 'checkbox';
			input.name          = 'kf_webhook_rows[' + i + '][events][]';
			input.value         = slug;
			var text            = ( labels && null != labels[ slug ] ? String( labels[ slug ] ) : slug ) + '';
			label.appendChild( input );
			label.appendChild( document.createTextNode( ' ' + text ) );
			cell.appendChild( label );
		}
		return cell;
	}

	btn.addEventListener(
		'click',
		function ( e ) {
			e.preventDefault();
			var i     = nextIndex;
			nextIndex = nextIndex + 1;

			var tr             = document.createElement( 'tr' );
			var td1            = document.createElement( 'td' );
			var inputUrl       = document.createElement( 'input' );
			inputUrl.type      = 'url';
			inputUrl.className = 'large-text';
			inputUrl.name      = 'kf_webhook_rows[' + i + '][url]';
			if ( st.placeholderUrl ) {
				inputUrl.placeholder = st.placeholderUrl;
			} else {
				inputUrl.placeholder = 'https://';
			}

			var wrap       = document.createElement( 'div' );
			wrap.className = 'kf-webhook-test-wrap';
			var ping       = document.createElement( 'button' );
			ping.type      = 'button';
			ping.className = 'button kf-webhook-test-ping';
			ping.setAttribute( 'type', 'button' );
			ping.textContent = st.testPing || 'Send Test Ping';
			var out          = document.createElement( 'span' );
			out.className    = 'kf-webhook-test-result';
			out.setAttribute( 'aria-live', 'polite' );
			wrap.appendChild( ping );
			wrap.appendChild( out );
			td1.appendChild( inputUrl );
			td1.appendChild( wrap );
			var td2 = buildEventCell( i );
			tr.appendChild( td1 );
			tr.appendChild( td2 );
			tbody.insertBefore( tr, anchor );
		}
	);
}() );

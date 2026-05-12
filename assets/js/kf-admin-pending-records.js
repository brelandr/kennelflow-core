( function () {
	'use strict';

	function toastShow( message, type ) {
		if ( window.KFToast && message ) {
			KFToast.show( message, { type: type || 'error' } );
		}
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			var wrap = document.querySelector( '.kf-pending-records' );
			if ( ! wrap || typeof kfPendingRecords === 'undefined' ) {
				return;
			}

			var st = kfPendingRecords.strings || {};

			wrap.addEventListener(
				'click',
				function ( e ) {
					var toggle = e.target.closest( '.kf-pending-records__toggle' );
					if ( toggle ) {
						var rid    = toggle.getAttribute( 'data-kf-pr-record' );
						var detail = document.getElementById( 'kf-pending-detail-' + rid );
						if ( detail ) {
							detail.hidden = ! detail.hidden;
						}
						return;
					}

					var approve = e.target.closest( '.kf-pending-records__approve' );
					if ( approve ) {
						e.preventDefault();
						var recId     = approve.getAttribute( 'data-kf-pr-record' );
						var detailRow = document.getElementById( 'kf-pending-detail-' + recId );
						var exp       = detailRow ? detailRow.querySelector( '.kf-pending-records__exp' ) : null;
						var msgEl     = detailRow ? detailRow.querySelector( '[data-kf-pr-msg="' + recId + '"]' ) : null;
						if ( ! exp || ! exp.value ) {
							if ( msgEl ) {
								msgEl.textContent = st.needExpiration || '';
							} else {
								toastShow( st.needExpiration || '', 'warning' );
							}
							return;
						}
						var prev            = approve.textContent;
						approve.disabled    = true;
						approve.textContent = st.working || '';

						var fd = new FormData();
						fd.append( 'action', kfPendingRecords.actionApprove );
						fd.append( '_wpnonce', kfPendingRecords.nonce );
						fd.append( 'record_id', recId );
						fd.append( 'expiration_date', exp.value );

						fetch(
							kfPendingRecords.ajaxUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								body: fd,
							}
						)
							.then(
								function ( r ) {
									return r.json();
								}
							)
							.then(
								function ( data ) {
									if ( data.success ) {
											window.location.reload();
											return;
									}
									var m =
									data.data && data.data.message
									? data.data.message
									: st.error || '';
									if ( msgEl ) {
										msgEl.textContent = m;
									} else {
										toastShow( m, 'error' );
									}
									approve.disabled    = false;
									approve.textContent = prev;
								}
							)
							.catch(
								function () {
									if ( msgEl ) {
											msgEl.textContent = st.network || '';
									} else {
										toastShow( st.network || '', 'error' );
									}
									approve.disabled    = false;
									approve.textContent = prev;
								}
							);
						return;
					}

					var reject = e.target.closest( '.kf-pending-records__reject' );
					if ( reject ) {
						e.preventDefault();
						if ( ! window.KFToast || typeof KFToast.confirm !== 'function' ) {
							return;
						}
						KFToast.confirm(
							st.confirmReject || '',
							{
								confirmText: st.confirmRejectOk || '',
								cancelText: st.confirmRejectCancel || '',
							}
						).then(
							function ( ok ) {
								if ( ! ok ) {
										return;
								}
								var recIdR         = reject.getAttribute( 'data-kf-pr-record' );
								var detailR        = document.getElementById( 'kf-pending-detail-' + recIdR );
								var msgR           = detailR ? detailR.querySelector( '[data-kf-pr-msg="' + recIdR + '"]' ) : null;
								var prevR          = reject.textContent;
								reject.disabled    = true;
								reject.textContent = st.working || '';

								var fd2 = new FormData();
								fd2.append( 'action', kfPendingRecords.actionReject );
								fd2.append( '_wpnonce', kfPendingRecords.nonce );
								fd2.append( 'record_id', recIdR );

								fetch(
									kfPendingRecords.ajaxUrl,
									{
										method: 'POST',
										credentials: 'same-origin',
										body: fd2,
									}
								)
								.then(
									function ( r ) {
										return r.json();
									}
								)
								.then(
									function ( data ) {
										if ( data.success ) {
											window.location.reload();
											return;
										}
										var m2 =
										data.data && data.data.message
										? data.data.message
										: st.error || '';
										if ( msgR ) {
												msgR.textContent = m2;
										} else {
											toastShow( m2, 'error' );
										}
										reject.disabled    = false;
										reject.textContent = prevR;
									}
								)
								.catch(
									function () {
										if ( msgR ) {
											msgR.textContent = st.network || '';
										} else {
												toastShow( st.network || '', 'error' );
										}
										reject.disabled    = false;
										reject.textContent = prevR;
									}
								);
							}
						);
					}
				}
			);
		}
	);
} )();

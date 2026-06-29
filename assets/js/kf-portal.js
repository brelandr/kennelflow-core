( function () {
	'use strict';

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			var root = document.querySelector( '[data-kf-portal]' );
			if ( ! root ) {
				return;
			}

			var tabs   = root.querySelectorAll( '[data-kf-tab]' );
			var panels = root.querySelectorAll( '[data-kf-panel]' );

			function activate( id ) {
				tabs.forEach(
					function ( btn ) {
						var isSel = btn.getAttribute( 'data-kf-tab' ) === id;
						btn.classList.toggle( 'is-active', isSel );
						btn.setAttribute( 'aria-selected', isSel ? 'true' : 'false' );
						btn.setAttribute( 'tabindex', isSel ? '0' : '-1' );
					}
				);
				panels.forEach(
					function ( panel ) {
						var match = panel.getAttribute( 'data-kf-panel' ) === id;
						panel.classList.toggle( 'is-active', match );
						panel.setAttribute( 'aria-hidden', match ? 'false' : 'true' );
					}
				);
			}

			if ( typeof kfPortalVars !== 'undefined' && kfPortalVars.checkoutUrl ) {
				root.addEventListener(
					'click',
					function ( e ) {
						var btnBal = e.target.closest( '[data-kf-pay-balance]' );
						var btn    = e.target.closest( '[data-kf-pay-booking]' );
						if ( ! btnBal && ! btn ) {
							return;
						}
						var targetBtn = btnBal || btn;
						e.preventDefault();
						var bookingId = targetBtn.getAttribute( btnBal ? 'data-kf-pay-balance' : 'data-kf-pay-booking' );
						if ( ! bookingId ) {
							return;
						}
						if ( targetBtn.disabled ) {
							return;
						}
						if ( btnBal && ( ! kfPortalVars.balanceAction || ! kfPortalVars.balanceNonce ) ) {
							return;
						}
						targetBtn.disabled    = true;
						var prev              = targetBtn.textContent;
						targetBtn.textContent = kfPortalVars.strings.paying;

						var params = new URLSearchParams();
						if ( btnBal ) {
							params.set( 'action', kfPortalVars.balanceAction );
							params.set( '_wpnonce', kfPortalVars.balanceNonce );
						} else {
							params.set( 'action', kfPortalVars.action );
							params.set( '_wpnonce', kfPortalVars.nonce );
						}
						params.set( 'booking_id', bookingId );

						fetch(
							kfPortalVars.ajaxUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
								},
								body: params.toString(),
							}
						)
						.then(
							function ( r ) {
								return r.json();
							}
						)
						.then(
							function ( data ) {
								if ( data.success && data.data && data.data.checkout_url ) {
										window.location.href = data.data.checkout_url;
										return;
								}
								var msg =
								data.data && data.data.message
								? data.data.message
								: kfPortalVars.strings.error;
								if ( window.KFToast ) {
									KFToast.show( msg, { type: 'error' } );
								}
								targetBtn.disabled    = false;
								targetBtn.textContent = prev;
							}
						)
						.catch(
							function () {
								if ( window.KFToast ) {
										KFToast.show( kfPortalVars.strings.network, { type: 'error' } );
								}
								targetBtn.disabled    = false;
								targetBtn.textContent = prev;
							}
						);
					}
				);
			}

			function initWaiver() {
				if ( typeof kfPortalVars === 'undefined' || ! kfPortalVars.waiver ) {
					return;
				}
				var wv  = kfPortalVars.waiver;
				var Sig = typeof SignaturePad !== 'undefined' ? SignaturePad : window.SignaturePad;
				if ( ! Sig ) {
					return;
				}
				var wrap = root.querySelector( '[data-kf-waiver]' );
				if ( ! wrap ) {
					return;
				}
				var canvas    = wrap.querySelector( '.kf-waiver__canvas' );
				var petSel    = wrap.querySelector( '[data-kf-waiver-pet]' );
				var btnClear  = wrap.querySelector( '[data-kf-waiver-clear]' );
				var btnSubmit = wrap.querySelector( '[data-kf-waiver-submit]' );
				var msgEl     = wrap.querySelector( '[data-kf-waiver-msg]' );
				var signedEl  = wrap.querySelector( '[data-kf-waiver-signed]' );
				if ( ! canvas || ! petSel || ! btnClear || ! btnSubmit ) {
					return;
				}

				var pad = new Sig(
					canvas,
					{
						penColor: '#111',
						backgroundColor: 'rgba(255,255,255,0)',
					}
				);

				function resizeCanvas() {
					if ( typeof pad.resizeCanvas === 'function' ) {
						pad.resizeCanvas();
					}
				}

				function petStatus( petId ) {
					var list = wv.pets || [];
					for ( var i = 0; i < list.length; i++ ) {
						if ( String( list[ i ].id ) === String( petId ) ) {
							return list[ i ];
						}
					}
					return null;
				}

				function updateSignedBanner() {
					var id = petSel.value;
					var st = petStatus( id );
					if ( ! signedEl ) {
						return;
					}
					if ( st && st.signed ) {
						signedEl.hidden      = false;
						signedEl.textContent =
						wv.strings.signed +
						' — ' +
						wv.strings.signedOn +
						' ' +
						( st.signedDate || '' );
					} else {
						signedEl.hidden      = true;
						signedEl.textContent = '';
					}
				}

				window.addEventListener( 'resize', resizeCanvas );
				resizeCanvas();

				petSel.addEventListener(
					'change',
					function () {
						pad.clear();
						updateSignedBanner();
					}
				);
				updateSignedBanner();

				btnClear.addEventListener(
					'click',
					function () {
						pad.clear();
					}
				);

				btnSubmit.addEventListener(
					'click',
					function () {
						if ( pad.isEmpty() ) {
							if ( window.KFToast ) {
								KFToast.show( wv.strings.emptySig, { type: 'warning' } );
							}
							return;
						}
						var petId = petSel.value;
						if ( ! petId ) {
							return;
						}
						var dataUrl        = canvas.toDataURL( 'image/png' );
						btnSubmit.disabled = true;
						btnClear.disabled  = true;
						if ( msgEl ) {
							msgEl.hidden      = false;
							msgEl.textContent = wv.strings.saving;
							msgEl.className   = 'kf-waiver__msg';
						}

						var params = new URLSearchParams();
						params.set( 'action', wv.ajaxAction );
						params.set( '_wpnonce', wv.nonce );
						params.set( 'pet_id', petId );
						params.set( 'image', dataUrl );

						fetch(
							kfPortalVars.ajaxUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
								},
								body: params.toString(),
							}
						)
						.then(
							function ( r ) {
								return r.text().then(
									function ( text ) {
										if ( ! text ) {
											return { success: false, data: { message: wv.strings.invalidResp } };
										}
										try {
											return JSON.parse( text );
										} catch ( parseErr ) {
											if ( '0' === text || '-1' === text ) {
												return {
													success: false,
													data: {
														message: wv.strings.invalidResp,
													},
												};
											}
											if ( ! r.ok ) {
												return {
													success: false,
													data: {
														message: wv.strings.invalidResp,
													},
												};
											}
											throw parseErr;
										}
									}
								);
							}
						)
						.then(
							function ( data ) {
								if ( data.success && data.data ) {
									if ( msgEl ) {
										msgEl.textContent = data.data.message || wv.strings.saved;
										msgEl.className   = 'kf-waiver__msg kf-waiver__msg--ok';
									}
									var list = wv.pets || [];
									for ( var j = 0; j < list.length; j++ ) {
										if ( String( list[ j ].id ) === String( petId ) ) {
											list[ j ].signed = true;
											if ( data.data.signed_at_display ) {
												list[ j ].signedDate = data.data.signed_at_display;
											} else if ( data.data.signed_at ) {
												list[ j ].signedDate = data.data.signed_at;
											}
											break;
										}
									}
									pad.clear();
									updateSignedBanner();
								} else {
									var err =
										data.data && data.data.message
										? data.data.message
										: wv.strings.invalidResp;
									if ( msgEl ) {
										msgEl.textContent = err;
										msgEl.className   = 'kf-waiver__msg kf-waiver__msg--err';
									}
									if ( window.KFToast ) {
										KFToast.show( err, { type: 'error' } );
									}
								}
							}
						)
						.catch(
							function () {
								if ( msgEl ) {
										msgEl.textContent = wv.strings.network;
										msgEl.className   = 'kf-waiver__msg kf-waiver__msg--err';
								}
								if ( window.KFToast ) {
									KFToast.show( wv.strings.network, { type: 'error' } );
								}
							}
						)
						.finally(
							function () {
								btnSubmit.disabled = false;
								btnClear.disabled  = false;
							}
						);
					}
				);

				root.addEventListener(
					'click',
					function ( e ) {
						var t = e.target.closest( '[data-kf-tab]' );
						if ( ! t || t.getAttribute( 'data-kf-tab' ) !== 'waivers' ) {
							return;
						}
						setTimeout( resizeCanvas, 50 );
					}
				);
			}

			initWaiver();

			function initWaitlist() {
				if (
				typeof kfPortalVars === 'undefined' ||
				! kfPortalVars.waitlist ||
				! kfPortalVars.waitlist.restBase
				) {
					return;
				}
				var wl   = kfPortalVars.waitlist;
				var wrap = root.querySelector( '[data-kf-waitlist]' );
				if ( ! wrap ) {
					return;
				}
				var petsWrap = wrap.querySelector( '[data-kf-wl-pets]' );
				var loc      = wrap.querySelector( '[data-kf-wl-location]' );
				var st       = wrap.querySelector( '[data-kf-wl-start]' );
				var en       = wrap.querySelector( '[data-kf-wl-end]' );
				var btnCheck = wrap.querySelector( '[data-kf-wl-check]' );
				var btnBook  = wrap.querySelector( '[data-kf-wl-book]' );
				var btnJoin  = wrap.querySelector( '[data-kf-wl-join]' );
				var msg      = wrap.querySelector( '[data-kf-wl-msg]' );
				if ( ! petsWrap || ! loc || ! st || ! en || ! btnCheck || ! btnJoin || ! msg ) {
					return;
				}

				function getSelectedPetIds() {
					var ids = [];
					petsWrap.querySelectorAll( 'input[data-kf-wl-pet-id]:checked' ).forEach(
						function ( input ) {
							var id = parseInt( input.value, 10 ) || 0;
							if ( id > 0 && ids.indexOf( id ) === -1 ) {
								ids.push( id );
							}
						}
					);
					return ids;
				}

				function petsMayBookOnline() {
					if ( ! wl.onlineBoardingEnabled || ! wl.bookingPageUrl ) {
						return false;
					}
					var ids = getSelectedPetIds();
					if ( ids.length < 1 || ! wl.eligiblePetIds || ! wl.eligiblePetIds.length ) {
						return false;
					}
					return ids.every(
						function ( id ) {
							return wl.eligiblePetIds.indexOf( id ) !== -1;
						}
					);
				}

				function buildBookingUrl() {
					var base = wl.bookingPageUrl || '';
					if ( ! base ) {
						return '';
					}
					var ids = getSelectedPetIds();
					var params = new URLSearchParams();
					params.set( 'kf_kind', 'boarding' );
					params.set( 'kf_check_availability', '1' );
					if ( ids.length === 1 ) {
						params.set( 'kf_pet_id', String( ids[0] ) );
					} else if ( ids.length > 1 ) {
						params.set( 'kf_pet_ids', ids.join( ',' ) );
					}
					if ( loc.value ) {
						params.set( 'kf_location', loc.value );
					}
					if ( st.value ) {
						params.set( 'kf_start', st.value );
					}
					if ( en.value ) {
						params.set( 'kf_end', en.value );
					}
					var join = base.indexOf( '?' ) >= 0 ? '&' : '?';
					return base + join + params.toString();
				}

				function hideBookCta() {
					if ( btnBook ) {
						btnBook.hidden = true;
						btnBook.setAttribute( 'href', '#' );
					}
				}

				function showAvailableOutcome() {
					hideBookCta();
					btnJoin.hidden   = true;
					btnJoin.disabled = true;
					if ( wl.onlineBoardingEnabled && wl.bookingPageUrl ) {
						if ( petsMayBookOnline() ) {
							msg.textContent = wl.strings.availableOnline || wl.strings.available;
							if ( btnBook ) {
								btnBook.textContent = wl.strings.bookOnline || btnBook.textContent;
								btnBook.href        = buildBookingUrl();
								btnBook.hidden      = false;
							}
							return;
						}
						msg.textContent = wl.strings.availableNeedCompliance || wl.strings.available;
						return;
					}
					msg.textContent = wl.strings.available;
				}

				function wlToast( text, type ) {
					if ( ! text || ! window.KFToast ) {
						return;
					}
					KFToast.show( text, { type: type || 'error' } );
				}

				function toUtcMysql( val ) {
					if ( ! val ) {
						return '';
					}
					var d = new Date( val );
					if ( isNaN( d.getTime() ) ) {
						return '';
					}
					return d.toISOString().slice( 0, 19 ).replace( 'T', ' ' );
				}

				btnCheck.addEventListener(
					'click',
					function () {
						msg.textContent  = '';
						hideBookCta();
						btnJoin.hidden   = true;
						btnJoin.disabled = true;
						if ( getSelectedPetIds().length < 1 || ! loc.value || ! st.value || ! en.value ) {
							msg.textContent = wl.strings.needDates;
							return;
						}
						var startUtc = toUtcMysql( st.value );
						var endUtc   = toUtcMysql( en.value );
						if ( ! startUtc || ! endUtc ) {
							msg.textContent = wl.strings.needDates;
							return;
						}
						var url              =
						wl.restBase +
						'availability?location=' +
						encodeURIComponent( loc.value ) +
						'&start=' +
						encodeURIComponent( startUtc ) +
						'&end=' +
						encodeURIComponent( endUtc );
						var prevCheck        = btnCheck.textContent;
						btnCheck.disabled    = true;
						btnCheck.textContent = wl.strings.checking;
						fetch( url, { credentials: 'same-origin' } )
						.then(
							function ( r ) {
								return r.json();
							}
						)
						.then(
							function ( data ) {
								btnCheck.disabled    = false;
								btnCheck.textContent = prevCheck;
								if ( data.code && data.message ) {
										msg.textContent = data.message;
										wlToast( data.message, 'error' );
										return;
								}
								var kennels = data.kennel_ids || [];
								if ( kennels.length === 0 ) {
									msg.textContent  = wl.strings.full;
									btnJoin.hidden   = false;
									btnJoin.disabled = false;
								} else {
									showAvailableOutcome();
								}
							}
						)
						.catch(
							function () {
								btnCheck.disabled    = false;
								btnCheck.textContent = prevCheck;
								msg.textContent      = wl.strings.network;
								wlToast( wl.strings.network, 'error' );
							}
						);
					}
				);

				btnJoin.addEventListener(
					'click',
					function () {
						msg.textContent = '';
						var startUtc    = toUtcMysql( st.value );
						var endUtc      = toUtcMysql( en.value );
						var selectedPetIds = getSelectedPetIds();
						if ( selectedPetIds.length < 1 || ! loc.value || ! startUtc || ! endUtc ) {
							msg.textContent = wl.strings.needDates;
							return;
						}
						var prevJoin        = btnJoin.textContent;
						btnJoin.disabled    = true;
						btnJoin.textContent = wl.strings.joining;
						var params          = new URLSearchParams();
						params.set( 'action', wl.action );
						params.set( '_wpnonce', wl.nonce );
						params.set( 'pet_id', String( selectedPetIds[0] ) );
						params.set( 'location_id', loc.value );
						params.set( 'start_gmt', startUtc );
						params.set( 'end_gmt', endUtc );
						fetch(
							kfPortalVars.ajaxUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
								},
								body: params.toString(),
							}
						)
						.then(
							function ( r ) {
								return r.json();
							}
						)
						.then(
							function ( data ) {
								btnJoin.disabled    = false;
								btnJoin.textContent = prevJoin;
								if ( data.success && data.data && data.data.message ) {
										msg.textContent = data.data.message;
										btnJoin.hidden  = true;
										wlToast( data.data.message, 'success' );
										return;
								}
								var em          =
								data.data && data.data.message
								? data.data.message
								: wl.strings.error;
								msg.textContent = em;
								wlToast( em, 'error' );
							}
						)
						.catch(
							function () {
								btnJoin.disabled    = false;
								btnJoin.textContent = prevJoin;
								msg.textContent     = wl.strings.network;
								wlToast( wl.strings.network, 'error' );
							}
						);
					}
				);
			}

			initWaitlist();

			function initComplianceUpload() {
				if (
				typeof kfPortalVars === 'undefined' ||
				! kfPortalVars.complianceUpload ||
				! kfPortalVars.complianceUpload.restUrl
				) {
					return;
				}
				var cu       = kfPortalVars.complianceUpload;
				var st       = cu.strings || {};
				var maxBytes = 5242880;

				root.addEventListener(
					'click',
					function ( e ) {
						var trigger = e.target.closest( '[data-kf-compliance-upload-trigger]' );
						if ( ! trigger ) {
							return;
						}
						e.preventDefault();
						var row = trigger.closest( '.kf-portal__compliance-vaccine-row' );
						if ( ! row ) {
							return;
						}
						var input = row.querySelector( '.kf-portal__compliance-file' );
						if ( input ) {
							input.value = '';
							input.click();
						}
					}
				);

				root.addEventListener(
					'change',
					function ( e ) {
						var input = e.target;
						if ( ! input.classList.contains( 'kf-portal__compliance-file' ) ) {
							return;
						}
						if ( ! input.files || ! input.files[ 0 ] ) {
							return;
						}
						var file  = input.files[ 0 ];
						var row   = input.closest( '.kf-portal__compliance-vaccine-row' );
						var petLi = input.closest( '[data-kf-pet-id]' );
						if ( ! row || ! petLi ) {
							return;
						}
						var petId       = petLi.getAttribute( 'data-kf-pet-id' );
						var vaccineName = row.getAttribute( 'data-kf-vaccine-label' ) || '';
						if ( ! petId || ! vaccineName ) {
							return;
						}

						var mimeOk =
						file.type === 'application/pdf' ||
						file.type === 'image/png' ||
						file.type === 'image/jpeg';
						if ( ! mimeOk ) {
							if ( window.KFToast ) {
								KFToast.show( st.fileType || '', { type: 'warning' } );
							}
							input.value = '';
							return;
						}

						if ( file.size > maxBytes ) {
							if ( window.KFToast ) {
								KFToast.show( st.fileTooLarge || '', { type: 'warning' } );
							}
							input.value = '';
							return;
						}

						var trigger = row.querySelector( '[data-kf-compliance-upload-trigger]' );
						var prevBtn = trigger ? trigger.textContent : '';
						if ( trigger ) {
							trigger.disabled    = true;
							trigger.textContent = st.uploading || '';
						}

						var fd = new FormData();
						fd.append( 'pet_id', petId );
						fd.append( 'vaccine_name', vaccineName );
						fd.append( 'file', file );

						fetch(
							cu.restUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'X-WP-Nonce': cu.restNonce || '',
								},
								body: fd,
							}
						)
						.then(
							function ( r ) {
								return r.json().then(
									function ( body ) {
										return { ok: r.ok, body: body };
									}
								);
							}
						)
						.then(
							function ( res ) {
								if ( res.ok && res.body && res.body.record_id ) {
										var actions = row.querySelector( '.kf-portal__compliance-vaccine-actions' );
									if ( actions ) {
										actions.innerHTML =
										'<span class="kf-portal__badge kf-portal__badge--pending">' +
										( st.pendingReview || '' ) +
										'</span>';
									}
									var waitlistPets = root.querySelector( '[data-kf-wl-pets]' );
									if ( waitlistPets ) {
										var chk = waitlistPets.querySelector(
											'input[data-kf-wl-pet-id="' + petId + '"]'
										);
										if ( chk ) {
											chk.setAttribute( 'disabled', 'disabled' );
											chk.checked = false;
										}
									}
									return;
								}
								var errMsg = st.error || '';
								if ( res.body && res.body.message ) {
									errMsg = res.body.message;
								}
								if ( window.KFToast ) {
									KFToast.show( errMsg, { type: 'error' } );
								}
							}
						)
						.catch(
							function () {
								if ( window.KFToast ) {
										KFToast.show( st.network || '', { type: 'error' } );
								}
							}
						)
						.finally(
							function () {
								if ( input ) {
										input.value = '';
								}
								if ( trigger && trigger.parentNode ) {
									trigger.disabled    = false;
									trigger.textContent = prevBtn;
								}
							}
						);
					}
				);
			}

			initComplianceUpload();

			function initAddPet() {
				if (
				typeof kfPortalVars === 'undefined' ||
				! kfPortalVars.addPet ||
				! kfPortalVars.addPet.enabled ||
				! kfPortalVars.addPet.restUrl
				) {
					return;
				}
				var ap   = kfPortalVars.addPet;
				var st   = ap.strings || {};
				var form = root.querySelector( '[data-kf-add-pet-form]' );
				if ( ! form ) {
					return;
				}
				var input = form.querySelector( '[data-kf-add-pet-name]' );
				var btn   = form.querySelector( '[data-kf-add-pet-submit]' );
				var msgEl = form.querySelector( '[data-kf-add-pet-msg]' );

				function showMsg( text, isError ) {
					if ( ! msgEl ) {
						return;
					}
					msgEl.textContent = text || '';
					msgEl.hidden      = ! text;
					msgEl.classList.toggle( 'kf-portal__add-pet-msg--error', !! isError );
				}

				form.addEventListener(
					'submit',
					function ( e ) {
						e.preventDefault();
						var title = input ? String( input.value || '' ).trim() : '';
						if ( ! title ) {
							showMsg( st.needName || '', true );
							if ( input ) {
								input.focus();
							}
							return;
						}
						showMsg( '', false );
						if ( btn ) {
							btn.disabled    = true;
							btn.textContent = st.saving || '';
						}

						fetch(
							ap.restUrl,
							{
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/json',
									'X-WP-Nonce': ap.restNonce || '',
								},
								body: JSON.stringify( { title: title } ),
							}
						)
						.then(
							function ( r ) {
								return r.json().then(
									function ( body ) {
										return { ok: r.ok, body: body };
									}
								);
							}
						)
						.then(
							function ( res ) {
								if ( res.ok && res.body && res.body.id ) {
										showMsg( st.saved || '', false );
										window.location.reload();
										return;
								}
								var errMsg = st.error || '';
								if ( res.body && res.body.message ) {
									errMsg = res.body.message;
								}
								showMsg( errMsg, true );
								if ( window.KFToast ) {
									KFToast.show( errMsg, { type: 'error' } );
								}
							}
						)
						.catch(
							function () {
								var netMsg = st.network || '';
								showMsg( netMsg, true );
								if ( window.KFToast ) {
									KFToast.show( netMsg, { type: 'error' } );
								}
							}
						)
						.finally(
							function () {
								if ( btn ) {
									btn.disabled    = false;
									btn.textContent = st.submit || '';
								}
							}
						);
					}
				);
			}

			initAddPet();

			root.addEventListener(
				'click',
				function ( e ) {
					var btnRx = e.target.closest( '[data-kennelflow-vet-refill]' );
					if ( ! btnRx || typeof kfPortalVars === 'undefined' || ! kfPortalVars.rx ) {
						return;
					}
					e.preventDefault();
					var rxId = btnRx.getAttribute( 'data-kennelflow-vet-refill' );
					if ( ! rxId ) {
						return;
					}
					if ( btnRx.disabled ) {
						return;
					}
					var rx            = kfPortalVars.rx;
					var prev          = btnRx.textContent;
					btnRx.disabled    = true;
					btnRx.textContent = rx.strings.requesting;

					var params = new URLSearchParams();
					params.set( 'action', rx.action );
					params.set( '_wpnonce', rx.nonce );
					params.set( 'prescription_id', rxId );

					fetch(
						kfPortalVars.ajaxUrl,
						{
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
							},
							body: params.toString(),
						}
					)
					.then(
						function ( r ) {
							return r.json();
						}
					)
					.then(
						function ( data ) {
							btnRx.disabled    = false;
							btnRx.textContent = prev;
							if ( data.success && data.data && data.data.message ) {
								if ( window.KFToast ) {
									KFToast.show( data.data.message, { type: 'success' } );
								}
								return;
							}
							var msg =
							data.data && data.data.message
							? data.data.message
							: rx.strings.error;
							if ( window.KFToast ) {
								KFToast.show( msg, { type: 'error' } );
							}
						}
					)
					.catch(
						function () {
							btnRx.disabled    = false;
							btnRx.textContent = prev;
							if ( window.KFToast ) {
									KFToast.show( rx.strings.network, { type: 'error' } );
							}
						}
					);
				}
			);

			tabs.forEach(
				function ( btn ) {
					btn.addEventListener(
						'click',
						function () {
							activate( btn.getAttribute( 'data-kf-tab' ) );
						}
					);
					btn.addEventListener(
						'keydown',
						function ( e ) {
							var keys = [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ];
							if ( keys.indexOf( e.key ) === -1 ) {
									return;
							}
							e.preventDefault();
							var i = Array.prototype.indexOf.call( tabs, btn );
							if ( e.key === 'ArrowRight' || e.key === 'End' ) {
								i = e.key === 'End' ? tabs.length - 1 : ( i + 1 ) % tabs.length;
							} else if ( e.key === 'ArrowLeft' || e.key === 'Home' ) {
								i = e.key === 'Home' ? 0 : ( i - 1 + tabs.length ) % tabs.length;
							}
							tabs[ i ].focus();
							activate( tabs[ i ].getAttribute( 'data-kf-tab' ) );
						}
					);
				}
			);
		}
	);
} )();

( function ( $ ) {
	'use strict';

	function showStatus( $el, message, type ) {
		$el.removeClass( 'is-error is-success' );
		if ( type ) {
			$el.addClass( type === 'error' ? 'is-error' : 'is-success' );
		}
		$el.text( message ).prop( 'hidden', false );
	}

	$(
		function () {
			var $start       = $( '#kf-demo-data-start' );
			var $nuke        = $( '#kf-demo-data-nuke' );
			var $scale       = $( '#kf-demo-data-scale' );
			var $startStatus = $( '#kf-demo-data-start-status' );
			var $nukeStatus  = $( '#kf-demo-data-nuke-status' );

			// Do not tie nuke to the Start button: if #kf-demo-data-start is missing, Wipe still binds
			// when kfDemoData is localized (see enqueue_assets fallback for non-standard hub hooks).
			if ( typeof kfDemoData === 'undefined' ) {
					return;
			}

			if ( $start.length ) {
				$start.on(
					'click',
					function () {
						$start.prop( 'disabled', true );
						showStatus( $startStatus, kfDemoData.i18n.working, '' );

						$.post(
							kfDemoData.ajaxUrl,
							{
								action: kfDemoData.actionStart,
								nonce: kfDemoData.nonce,
								scale: $scale.val(),
							}
						)
						.done(
							function ( res ) {
								if ( res.success && res.data && res.data.message ) {
									showStatus( $startStatus, res.data.message, 'success' );
								} else {
									showStatus(
										$startStatus,
										( res.data && res.data.message ) ? res.data.message : kfDemoData.i18n.error,
										'error'
									);
								}
							}
						)
						.fail(
							function () {
								showStatus( $startStatus, kfDemoData.i18n.error, 'error' );
							}
						)
						.always(
							function () {
								$start.prop( 'disabled', false );
							}
						);
					}
				);
			}

			if ( $nuke.length ) {
				$nuke.on(
					'click',
					function () {
						if ( ! window.confirm( kfDemoData.i18n.confirmNuke ) ) {
							return;
						}

						$nuke.prop( 'disabled', true );
						showStatus( $nukeStatus, kfDemoData.i18n.working, '' );

						$.post(
							kfDemoData.ajaxUrl,
							{
								action: kfDemoData.actionNuke,
								nonce: kfDemoData.nonce,
							}
						)
							.done(
								function ( res ) {
									if ( res.success && res.data && res.data.message ) {
											showStatus( $nukeStatus, res.data.message, 'success' );
									} else {
										showStatus(
											$nukeStatus,
											( res.data && res.data.message ) ? res.data.message : kfDemoData.i18n.error,
											'error'
										);
									}
								}
							)
							.fail(
								function () {
									showStatus( $nukeStatus, kfDemoData.i18n.error, 'error' );
								}
							)
							.always(
								function () {
									$nuke.prop( 'disabled', false );
								}
							);
					}
				);
			}
		}
	);
}( jQuery ) );

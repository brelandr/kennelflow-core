( function ( $ ) {
	'use strict';

	var root = $( '#kf-migration-progress' );
	if ( ! root.length ) {
		return;
	}

	var jobId = root.data( 'job-id' );
	if ( ! jobId ) {
		return;
	}

	var bar      = $( '#kf-migration-bar' );
	var statusEl = root.find( '.kf-migration-status' );
	var logEl    = root.find( '.kf-migration-log' );

	function appendLog( items ) {
		if ( ! items || ! items.length ) {
			return;
		}
		items.forEach(
			function ( msg ) {
				$( '<li></li>' ).text( msg ).appendTo( logEl );
			}
		);
	}

	function runBatch() {
		statusEl.text( kfMigration.i18n.working );

		$.post(
			kfMigration.ajaxUrl,
			{
				action: kfMigration.action,
				nonce: kfMigration.nonce,
				job_id: jobId
			}
		)
			.done(
				function ( res ) {
					if ( ! res || ! res.success ) {
							var err =
						res && res.data && res.data.message
						? res.data.message
						: kfMigration.i18n.error;
							statusEl.text( err );
							return;
					}

					var d = res.data || {};
					if ( typeof d.progress_percent === 'number' ) {
						bar.val( d.progress_percent );
					}

					statusEl.text(
					// translators: screen reader / status line.
						'Owners: ' +
						( d.created_owners || 0 ) +
						' · Pets: ' +
						( d.created_pets || 0 ) +
						' · Batch: ' +
						( d.batch_rows || 0 ) +
						' rows'
					);

					appendLog( d.errors );

					if ( d.done ) {
						bar.val( 100 );
						statusEl.text( kfMigration.i18n.done );
						return;
					}

					runBatch();
				}
			)
			.fail(
				function () {
					statusEl.text( kfMigration.i18n.error );
				}
			);
	}

	runBatch();
}( jQuery ) );

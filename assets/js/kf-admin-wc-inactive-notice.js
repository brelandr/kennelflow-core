/**
 * Dismiss handler for the WooCommerce inactive admin notice.
 *
 * @package KennelFlow
 */
( function ( $ ) {
	'use strict';

	$(
		function () {
			$( document ).on(
				'click',
				'.kf-wc-inactive-notice .notice-dismiss',
				function () {
					if ( 'undefined' === typeof ajaxurl || 'undefined' === typeof kfWcInactiveNotice ) {
							return;
					}
					$.post(
						ajaxurl,
						{
							action: kfWcInactiveNotice.action,
							_wpnonce: kfWcInactiveNotice.nonce
						}
					);
				}
			);
		}
	);
}( window.jQuery ) );

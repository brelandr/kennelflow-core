( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wc || ! wc.blocksCheckout ) {
		return;
	}

	var el                  = wp.element.createElement;
	var useState            = wp.element.useState;
	var registerPlugin      = wp.plugins.registerPlugin;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;

	function getSettings() {
		if ( wc.wcSettings && typeof wc.wcSettings.getSetting === 'function' ) {
			return wc.wcSettings.getSetting( 'kennelflow-kf-emergency_data' ) || {};
		}
		return {};
	}

	function EmergencyContactField() {
		var settings = getSettings();
		var initial  = useState( '' );
		var value    = initial[ 0 ];
		var setValue = initial[ 1 ];

		if ( ! settings.showEmergencyField ) {
			return null;
		}

		return el(
			'div',
			{ className: 'kf-checkout-emergency wc-block-components-text-input' },
			el(
				'label',
				{ htmlFor: 'kf-emergency-contact' },
				settings.fieldLabel || ''
			),
			el(
				'input',
				{
					id: 'kf-emergency-contact',
					type: 'text',
					className: 'wc-block-components-text-input__input',
					placeholder: settings.fieldPlaceholder || '',
					value: value,
					onChange: function ( e ) {
						var v = e.target.value;
						setValue( v );
						if ( typeof extensionCartUpdate === 'function' ) {
							extensionCartUpdate(
								{
									namespace: 'kf-emergency-contact',
									data: {
										kf_emergency_contact: v,
									},
								}
							);
						}
					},
				}
			)
		);
	}

	registerPlugin(
		'kennelflow-kf-emergency',
		{
			render: EmergencyContactField,
			scope: 'woocommerce-checkout',
		}
	);
} )( window.wp, window.wc );

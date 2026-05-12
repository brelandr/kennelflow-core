/**
 * KennelFlow — Emergency contact field for the WooCommerce Checkout block (billing step).
 *
 * Persists via checkout extension data (setExtensionData) and cart/extensions
 * (extensionCartUpdate) so PHP `woocommerce_store_api_register_update_callback`
 * continues to receive `kf_emergency_contact` for session → order meta.
 */
import { extensionCartUpdate, registerCheckoutBlock } from '@woocommerce/blocks-checkout';
import { ValidatedTextInput } from '@woocommerce/blocks-components';
import { checkoutStore } from '@woocommerce/block-data';
import { getSetting } from '@woocommerce/settings';
import { useDispatch, useSelect } from '@wordpress/data';

const EXTENSION_NAMESPACE = 'kf-emergency-contact';
const EXTENSION_KEY = 'kf_emergency_contact';

function EmergencyContactField() {
	const settings = getSetting( 'kennelflow-kf-emergency_data', {} );
	const showEmergencyField = Boolean( settings.showEmergencyField );
	const fieldLabel = settings.fieldLabel || '';
	const fieldPlaceholder = settings.fieldPlaceholder || '';

	const value = useSelect( ( select ) => {
		const extensionData = select( checkoutStore ).getExtensionData();
		return extensionData?.[ EXTENSION_NAMESPACE ]?.[ EXTENSION_KEY ] ?? '';
	}, [] );

	const { setExtensionData } = useDispatch( checkoutStore );

	if ( ! showEmergencyField ) {
		return null;
	}

	const onChange = ( newValue ) => {
		setExtensionData( EXTENSION_NAMESPACE, {
			[ EXTENSION_KEY ]: newValue,
		} );
		if ( 'function' === typeof extensionCartUpdate ) {
			extensionCartUpdate( {
				namespace: EXTENSION_NAMESPACE,
				data: {
					[ EXTENSION_KEY ]: newValue,
				},
			} );
		}
	};

	return (
		<ValidatedTextInput
			instanceId="kf-emergency-contact"
			id="kf-emergency-contact"
			type="tel"
			label={ fieldLabel }
			value={ value }
			onChange={ onChange }
			placeholder={ fieldPlaceholder }
		/>
	);
}

registerCheckoutBlock( {
	metadata: {
		name: 'kennelflow-core/emergency-contact',
		parent: [ 'woocommerce/checkout-billing-address-block' ],
	},
	component: EmergencyContactField,
} );

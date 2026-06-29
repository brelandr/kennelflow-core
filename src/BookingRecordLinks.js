/**
 * Admin links to booking, pet, owner, and patient history from calendar popovers.
 *
 * @package KennelFlow
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {object|null|undefined} links record_links from REST.
 * @return {Array<{ url: string, label: string, primary?: boolean }>}
 */
function collectRecordLinkButtons( links ) {
	if ( ! links || 'object' !== typeof links ) {
		return [];
	}

	const buttons = [];
	const booking = links.booking && 'object' === typeof links.booking ? links.booking : {};
	const pet = links.pet && 'object' === typeof links.pet ? links.pet : {};
	const owner = links.owner && 'object' === typeof links.owner ? links.owner : {};

	if ( booking.edit && String( booking.edit ).trim() !== '' ) {
		buttons.push( {
			url: String( booking.edit ),
			label: __( 'Edit appointment', 'kennelflow-core' ),
			primary: true,
		} );
	} else if ( booking.view && String( booking.view ).trim() !== '' ) {
		buttons.push( {
			url: String( booking.view ),
			label: __( 'View appointment', 'kennelflow-core' ),
			primary: true,
		} );
	}

	if ( pet.edit && String( pet.edit ).trim() !== '' ) {
		buttons.push( {
			url: String( pet.edit ),
			label: __( 'Edit pet', 'kennelflow-core' ),
		} );
	} else if ( pet.view && String( pet.view ).trim() !== '' ) {
		buttons.push( {
			url: String( pet.view ),
			label: __( 'View pet', 'kennelflow-core' ),
		} );
	}

	if ( links.patient_history && String( links.patient_history ).trim() !== '' ) {
		buttons.push( {
			url: String( links.patient_history ),
			label: __( 'Patient history', 'kennelflow-core' ),
		} );
	}

	if ( owner.edit && String( owner.edit ).trim() !== '' ) {
		buttons.push( {
			url: String( owner.edit ),
			label: __( 'Edit owner', 'kennelflow-core' ),
		} );
	} else if ( owner.view && String( owner.view ).trim() !== '' ) {
		buttons.push( {
			url: String( owner.view ),
			label: __( 'View owner', 'kennelflow-core' ),
		} );
	}

	return buttons;
}

/**
 * @param {object} props
 * @param {object|null|undefined} props.links record_links payload.
 * @param {string} [props.className] Wrapper class.
 * @return {import('react').ReactElement|null}
 */
export function BookingRecordLinks( { links, className } ) {
	const buttons = collectRecordLinkButtons( links );
	if ( buttons.length < 1 ) {
		return null;
	}

	const wrapClass =
		className && String( className ).trim() !== ''
			? String( className )
			: 'kf-cal-popover__actions kf-cal-popover__record-links';

	return (
		<div className={ wrapClass }>
			{ buttons.map( ( btn ) => (
				<a
					key={ `${ btn.label }:${ btn.url }` }
					href={ btn.url }
					className={ btn.primary ? 'button button-primary' : 'button button-secondary' }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ btn.label }
				</a>
			) ) }
		</div>
	);
}

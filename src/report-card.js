/**
 * Pet Report Card — Daily Reports admin app.
 *
 * @package KennelFlow
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './report-card.css';

const PRESET_TAGS = [
	__( 'Ate Well', 'kennelflow-core' ),
	__( 'Played', 'kennelflow-core' ),
	__( 'Rested', 'kennelflow-core' ),
	__( 'Social', 'kennelflow-core' ),
	__( 'Happy', 'kennelflow-core' ),
];

const settings =
	typeof window !== 'undefined' && window.kfReportCardSettings
		? window.kfReportCardSettings
		: {};

if ( settings.rest_url && 'function' === typeof apiFetch.createRootURLMiddleware ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest_url ) );
}
if ( settings.nonce && 'function' === typeof apiFetch.createNonceMiddleware ) {
	apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );
}

function fileToDataUrl( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result );
		reader.onerror = reject;
		reader.readAsDataURL( file );
	} );
}

function ReportCardApp() {
	const [ pets, setPets ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ petId, setPetId ] = useState( '' );
	const [ tags, setTags ] = useState( {} );
	const [ notes, setNotes ] = useState( '' );
	const [ photoData, setPhotoData ] = useState( '' );
	const [ photoPreview, setPhotoPreview ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ banner, setBanner ] = useState( null );

	useEffect( () => {
		setLoading( true );
		setLoadError( '' );
		apiFetch( { path: '/kennelflow/v1/report-card/boarded-pets' } )
			.then( ( res ) => {
				const list = res && res.pets ? res.pets : [];
				setPets( list );
				if ( list.length ) {
					setPetId( String( list[ 0 ].id ) );
				}
			} )
			.catch( ( err ) => {
				let msg = __(
					'Could not load boarded pets. Run System Health → database upgrade so the hub bookings table exists, and keep KennelFlow Boarding or KennelFlow Vet active so calendar stays in sync.',
					'kennelflow-core'
				);
				if ( err && err.message ) {
					msg = err.message;
				}
				setLoadError( msg );
			} )
			.finally( () => setLoading( false ) );
	}, [] );

	const toggleTag = ( label ) => {
		setTags( ( prev ) => ( { ...prev, [ label ]: ! prev[ label ] } ) );
	};

	const onPickPhoto = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		if ( ! file.type || ! file.type.startsWith( 'image/' ) ) {
			setBanner( { type: 'error', text: __( 'Please choose an image file.', 'kennelflow-core' ) } );
			return;
		}
		fileToDataUrl( file )
			.then( ( dataUrl ) => {
				setPhotoData( dataUrl );
				setPhotoPreview( dataUrl );
				setBanner( null );
			} )
			.catch( () => {
				setBanner( { type: 'error', text: __( 'Could not read the image.', 'kennelflow-core' ) } );
			} );
	};

	const onSubmit = ( ev ) => {
		ev.preventDefault();
		setBanner( null );

		if ( ! petId ) {
			setBanner( { type: 'error', text: __( 'Select a pet.', 'kennelflow-core' ) } );
			return;
		}
		if ( ! photoData ) {
			setBanner( { type: 'error', text: __( 'Add a photo.', 'kennelflow-core' ) } );
			return;
		}

		const selectedTags = PRESET_TAGS.filter( ( t ) => tags[ t ] );

		setSending( true );
		apiFetch( {
			path: '/kennelflow/v1/report-card',
			method: 'POST',
			data: {
				pet_id: parseInt( petId, 10 ),
				photo: photoData,
				tags: selectedTags,
				notes,
			},
		} )
			.then( () => {
				setBanner( {
					type: 'ok',
					text: __( 'Report sent to the owner.', 'kennelflow-core' ),
				} );
				setNotes( '' );
				setTags( {} );
				setPhotoData( '' );
				setPhotoPreview( '' );
			} )
			.catch( ( err ) => {
				let msg = __( 'Something went wrong.', 'kennelflow-core' );
				if ( err && err.message ) {
					msg = err.message;
				} else if ( err && err.data && err.data.message ) {
					msg = err.data.message;
				}
				setBanner( { type: 'error', text: msg } );
			} )
			.finally( () => setSending( false ) );
	};

	if ( loading ) {
		return (
			<div className="kf-rc-app">
				<p className="kf-rc-muted">{ __( 'Loading pets…', 'kennelflow-core' ) }</p>
			</div>
		);
	}

	if ( loadError ) {
		return (
			<div className="kf-rc-app">
				<div className="kf-rc-banner kf-rc-banner--error" role="alert">
					{ loadError }
				</div>
			</div>
		);
	}

	if ( ! pets.length ) {
		return (
			<div className="kf-rc-app">
				<div className="kf-rc-banner" role="status">
					{ __(
						'No pets are checked in right now (no active booking for the current time).',
						'kennelflow-core'
					) }
				</div>
			</div>
		);
	}

	return (
		<form className="kf-rc-app" onSubmit={ onSubmit }>
			{ banner ? (
				<div
					className={
						'kf-rc-banner ' +
						( banner.type === 'error' ? 'kf-rc-banner--error' : 'kf-rc-banner--ok' )
					}
					role="status"
				>
					{ banner.text }
				</div>
			) : null }

			<div className="kf-rc-field">
				<label className="kf-rc-label" htmlFor="kf-rc-pet">
					{ __( 'Pet (checked in now)', 'kennelflow-core' ) }
				</label>
				<select
					id="kf-rc-pet"
					className="kf-rc-select"
					value={ petId }
					onChange={ ( e ) => setPetId( e.target.value ) }
					required
				>
					{ pets.map( ( p ) => (
						<option key={ p.id } value={ String( p.id ) }>
							{ p.name }
						</option>
					) ) }
				</select>
			</div>

			<div className="kf-rc-field">
				<span className="kf-rc-label">{ __( 'Quick status', 'kennelflow-core' ) }</span>
				<div className="kf-rc-tags" role="group" aria-label={ __( 'Status tags', 'kennelflow-core' ) }>
					{ PRESET_TAGS.map( ( t ) => (
						<button
							key={ t }
							type="button"
							className="kf-rc-tag"
							aria-pressed={ !! tags[ t ] }
							onClick={ () => toggleTag( t ) }
						>
							{ t }
						</button>
					) ) }
				</div>
			</div>

			<div className="kf-rc-field">
				<label className="kf-rc-label" htmlFor="kf-rc-notes">
					{ __( 'Notes', 'kennelflow-core' ) }
				</label>
				<textarea
					id="kf-rc-notes"
					className="kf-rc-notes"
					value={ notes }
					onChange={ ( e ) => setNotes( e.target.value ) }
					placeholder={ __( 'Anything else the owner should know…', 'kennelflow-core' ) }
					rows={ 4 }
				/>
			</div>

			<div className="kf-rc-field">
				<span className="kf-rc-label" id="kf-rc-photo-label">
					{ __( 'Photo', 'kennelflow-core' ) }
				</span>
				<label className="kf-rc-photo" htmlFor="kf-rc-photo-input">
					<input
						id="kf-rc-photo-input"
						type="file"
						accept="image/*"
						capture="environment"
						className="kf-rc-photo-input"
						onChange={ onPickPhoto }
					/>
					<span className="kf-rc-photo-prompt" aria-hidden="true">
						{ __( 'Tap to take or choose a photo', 'kennelflow-core' ) }
					</span>
				</label>
				{ photoPreview ? (
					<img
						className="kf-rc-photo-preview"
						src={ photoPreview }
						alt=""
					/>
				) : null }
			</div>

			<button type="submit" className="kf-rc-submit" disabled={ sending }>
				{ sending ? __( 'Sending…', 'kennelflow-core' ) : __( 'Send daily update email', 'kennelflow-core' ) }
			</button>
		</form>
	);
}

const rootEl = document.getElementById( 'kf-report-card-root' );
if ( rootEl ) {
	createRoot( rootEl ).render( <ReportCardApp /> );
}

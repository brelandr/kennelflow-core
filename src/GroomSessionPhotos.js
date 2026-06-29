/**
 * Booking session photos in calendar popover (grooming, boarding, clinic).
 *
 * @package KennelFlow
 */

import { useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	defaultGroomingMediaKinds,
	normalizePhotoSettings,
	sessionPhotoRestBase,
} from './bookingSessionPhotoUtils';
import { formatApiFetchError } from './calendarDebug';
import { DeviceCameraCapture } from './DeviceCameraCapture';

/**
 * Upload a file to the WordPress Media Library.
 *
 * @param {File} file Image file.
 * @return {Promise<object>}
 */
async function uploadMediaFile( file ) {
	const formData = new FormData();
	formData.append( 'file', file, file.name || 'photo.jpg' );
	return apiFetch( {
		path: '/wp/v2/media',
		method: 'POST',
		body: formData,
	} );
}

/**
 * @param {object} props
 * @param {object} props.booking       Calendar booking row (needs booking_post_id).
 * @param {object} [props.photoSettings] Normalized session photo settings.
 */
export function BookingSessionPhotos( { booking, photoSettings: rawSettings } ) {
	const queryClient = useQueryClient();
	const libraryRefs = useRef( {} );
	const [ cameraKind, setCameraKind ] = useState( '' );
	const [ busyKind, setBusyKind ] = useState( '' );
	const [ statusMsg, setStatusMsg ] = useState( '' );
	const [ statusErr, setStatusErr ] = useState( false );

	const photoSettings = useMemo(
		() => normalizePhotoSettings( rawSettings || {} ),
		[ rawSettings ]
	);
	const mediaKinds = photoSettings.media_kinds.length
		? photoSettings.media_kinds
		: defaultGroomingMediaKinds();

	const bookingPostId = parseInt( booking?.booking_post_id, 10 ) || 0;
	const restBase = useMemo(
		() => sessionPhotoRestBase( photoSettings ),
		[ photoSettings ]
	);
	const isActive = !! photoSettings.active;
	const canView = isActive && bookingPostId > 0;
	const canUpload = canView && !! photoSettings.can_upload;

	const mediaQueryKey = useMemo(
		() => [ 'booking-session-media', restBase, bookingPostId ],
		[ restBase, bookingPostId ]
	);

	const mediaQuery = useQuery( {
		queryKey: mediaQueryKey,
		queryFn: async () => {
			const resp = await apiFetch( {
				path: `${ restBase }/bookings/${ bookingPostId }/media`,
			} );
			return resp && Array.isArray( resp.media ) ? resp.media : [];
		},
		enabled: canView,
		staleTime: 15_000,
	} );

	const photosByKind = useMemo( () => {
		const grouped = {};
		mediaKinds.forEach( ( kindRow ) => {
			grouped[ kindRow.key ] = [];
		} );
		( mediaQuery.data || [] ).forEach( ( row ) => {
			const key = String( row.media_kind || '' ).toLowerCase();
			if ( ! grouped[ key ] ) {
				grouped[ key ] = [];
			}
			grouped[ key ].push( row );
		} );
		return grouped;
	}, [ mediaQuery.data, mediaKinds ] );

	const invalidate = useCallback( async () => {
		await queryClient.invalidateQueries( { queryKey: mediaQueryKey } );
	}, [ queryClient, mediaQueryKey ] );

	const linkPhoto = useCallback(
		async ( attachmentId, kind ) => {
			await apiFetch( {
				path: `${ restBase }/bookings/${ bookingPostId }/media`,
				method: 'POST',
				data: {
					media_kind: kind,
					attachment_id: attachmentId,
				},
			} );
		},
		[ restBase, bookingPostId ]
	);

	const handleFilePicked = useCallback(
		async ( kind, fileList ) => {
			if ( ! canUpload || busyKind ) {
				return;
			}
			const file = fileList && fileList[ 0 ] ? fileList[ 0 ] : null;
			if ( ! file ) {
				return;
			}
			if ( ! file.type || ! file.type.startsWith( 'image/' ) ) {
				setStatusMsg(
					__( 'Please choose an image file.', 'kennelflow-core' )
				);
				setStatusErr( true );
				return;
			}

			setBusyKind( kind );
			setStatusMsg( __( 'Uploading…', 'kennelflow-core' ) );
			setStatusErr( false );

			try {
				const uploaded = await uploadMediaFile( file );
				const attachmentId = parseInt( uploaded?.id, 10 ) || 0;
				if ( attachmentId < 1 ) {
					throw new Error(
						__( 'Upload failed.', 'kennelflow-core' )
					);
				}
				await linkPhoto( attachmentId, kind );
				setStatusMsg( __( 'Photo saved.', 'kennelflow-core' ) );
				setStatusErr( false );
				await invalidate();
			} catch ( err ) {
				setStatusMsg(
					formatApiFetchError(
						err,
						__( 'Could not upload photo.', 'kennelflow-core' )
					)
				);
				setStatusErr( true );
			} finally {
				setBusyKind( '' );
				const input = libraryRefs.current[ kind ];
				if ( input ) {
					input.value = '';
				}
			}
		},
		[ canUpload, busyKind, linkPhoto, invalidate ]
	);

	const handleRemove = useCallback(
		async ( mediaId ) => {
			if ( ! canUpload || busyKind || mediaId < 1 ) {
				return;
			}
			if (
				! window.confirm(
					__( 'Remove this session photo?', 'kennelflow-core' )
				)
			) {
				return;
			}
			setBusyKind( 'remove' );
			setStatusMsg( __( 'Removing…', 'kennelflow-core' ) );
			setStatusErr( false );
			try {
				await apiFetch( {
					path: `${ restBase }/bookings/${ bookingPostId }/media/${ mediaId }`,
					method: 'DELETE',
				} );
				setStatusMsg( '' );
				await invalidate();
			} catch ( err ) {
				setStatusMsg(
					formatApiFetchError(
						err,
						__( 'Could not remove photo.', 'kennelflow-core' )
					)
				);
				setStatusErr( true );
			} finally {
				setBusyKind( '' );
			}
		},
		[ canUpload, busyKind, restBase, bookingPostId, invalidate ]
	);

	const handleCameraCapture = useCallback(
		( kind, file ) => {
			setCameraKind( '' );
			handleFilePicked( kind, [ file ] );
		},
		[ handleFilePicked ]
	);

	const activeCameraKind = mediaKinds.find(
		( row ) => row.key === cameraKind
	);

	if ( ! isActive ) {
		return null;
	}

	if ( bookingPostId < 1 ) {
		return (
			<div className="kf-cal-groom-photos">
				<h3 className="kf-cal-groom-photos__heading">
					{ photoSettings.heading }
				</h3>
				<p className="kf-cal-popover__hint">
					{ __(
						'This calendar entry is not linked to a booking record yet, so session photos cannot be added here.',
						'kennelflow-core'
					) }
				</p>
			</div>
		);
	}

	const renderThumbGrid = ( rows, label ) => {
		if ( ! rows.length ) {
			return (
				<p className="kf-cal-popover__hint">
					{ sprintf(
						/* translators: %s: photo slot label (e.g. Check-in). */
						__( 'No %s photos yet.', 'kennelflow-core' ),
						label.toLowerCase()
					) }
				</p>
			);
		}
		return (
			<div className="kf-cal-groom-photos__grid">
				{ rows.map( ( row ) => {
					const src =
						row.thumbnail_url ||
						row.url ||
						'';
					const mediaId = parseInt( row.id, 10 ) || 0;
					return (
						<div key={ row.id } className="kf-cal-groom-photos__item">
							{ src ? (
								<img src={ src } alt="" width={ 72 } height={ 72 } />
							) : null }
							{ canUpload ? (
								<button
									type="button"
									className="button-link-delete kf-cal-groom-photos__remove"
									disabled={ !! busyKind }
									onClick={ () => handleRemove( mediaId ) }
								>
									{ __( 'Remove', 'kennelflow-core' ) }
								</button>
							) : null }
						</div>
					);
				} ) }
			</div>
		);
	};

	return (
		<div className="kf-cal-groom-photos">
			<h3 className="kf-cal-groom-photos__heading">
				{ photoSettings.heading }
			</h3>
			{ mediaQuery.isLoading ? (
				<p className="kf-cal-popover__hint">
					{ __( 'Loading photos…', 'kennelflow-core' ) }
				</p>
			) : null }
			{ mediaQuery.isError ? (
				<p className="kf-cal-popover__hint kf-cal-popover__hint--error">
					{ __( 'Could not load photos.', 'kennelflow-core' ) }
				</p>
			) : null }
			{ mediaKinds.map( ( kindRow ) => {
				const rows = photosByKind[ kindRow.key ] || [];
				const isBusy = busyKind === kindRow.key;
				const takeLabel =
					kindRow.takeLabel ||
					sprintf(
						/* translators: %s: photo slot label. */
						__( 'Take %s photo', 'kennelflow-core' ),
						kindRow.label
					);
				const chooseLabel =
					kindRow.chooseLabel ||
					sprintf(
						/* translators: %s: photo slot label. */
						__( 'Choose %s photo', 'kennelflow-core' ),
						kindRow.label
					);

				return (
					<div
						key={ kindRow.key }
						className="kf-cal-groom-photos__section"
					>
						<strong>{ kindRow.label }</strong>
						{ renderThumbGrid( rows, kindRow.label ) }
						{ canUpload ? (
							<div className="kf-cal-groom-photos__row-actions">
								<input
									ref={ ( node ) => {
										libraryRefs.current[ kindRow.key ] = node;
									} }
									type="file"
									accept="image/*"
									className="kf-cal-groom-photos__file"
									onChange={ ( e ) =>
										handleFilePicked(
											kindRow.key,
											e.target.files
										)
									}
								/>
								<button
									type="button"
									className="button"
									disabled={ !! busyKind }
									onClick={ () =>
										setCameraKind( kindRow.key )
									}
								>
									{ isBusy
										? __( 'Uploading…', 'kennelflow-core' )
										: takeLabel }
								</button>
								<button
									type="button"
									className="button"
									disabled={ !! busyKind }
									onClick={ () =>
										libraryRefs.current[
											kindRow.key
										]?.click()
									}
								>
									{ chooseLabel }
								</button>
							</div>
						) : null }
					</div>
				);
			} ) }
			{ ! canUpload ? (
				<p className="kf-cal-popover__hint">
					{ __(
						'You can view session photos but cannot upload. Ask an administrator to grant the Upload Files capability to your role.',
						'kennelflow-core'
					) }
				</p>
			) : null }
			{ statusMsg ? (
				<p
					className={
						statusErr
							? 'kf-cal-popover__hint kf-cal-popover__hint--error'
							: 'kf-cal-popover__hint'
					}
				>
					{ statusMsg }
				</p>
			) : null }
			<DeviceCameraCapture
				isOpen={ !! activeCameraKind }
				title={ activeCameraKind?.takeLabel || photoSettings.heading }
				onClose={ () => setCameraKind( '' ) }
				onCapture={ ( file ) =>
					handleCameraCapture( cameraKind, file )
				}
			/>
		</div>
	);
}

/** @deprecated 0.3.23 Use BookingSessionPhotos. */
export const GroomSessionPhotos = BookingSessionPhotos;

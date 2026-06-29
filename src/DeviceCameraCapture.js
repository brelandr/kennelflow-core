/**
 * In-browser camera capture (getUserMedia) for session photos.
 *
 * @package KennelFlow
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { createPortal } from 'react-dom';
import { __ } from '@wordpress/i18n';

/**
 * Whether the browser can open a live camera stream.
 *
 * @return {boolean}
 */
export function deviceCameraSupported() {
	return (
		'undefined' !== typeof navigator &&
		!! navigator.mediaDevices &&
		'function' === typeof navigator.mediaDevices.getUserMedia
	);
}

/**
 * @param {MediaStream} stream Active camera stream.
 * @return {void}
 */
function stopStream( stream ) {
	if ( ! stream ) {
		return;
	}
	stream.getTracks().forEach( ( track ) => {
		track.stop();
	} );
}

/**
 * @param {object} props
 * @param {boolean} props.isOpen Whether the modal is visible.
 * @param {string} [props.title] Modal heading.
 * @param {() => void} props.onClose Close without capturing.
 * @param {(file: File) => void} props.onCapture JPEG file from the current frame.
 */
export function DeviceCameraCapture( { isOpen, title, onClose, onCapture } ) {
	const videoRef = useRef( null );
	const streamRef = useRef( null );
	const [ error, setError ] = useState( '' );
	const [ ready, setReady ] = useState( false );

	const closeCamera = useCallback( () => {
		stopStream( streamRef.current );
		streamRef.current = null;
		setReady( false );
		setError( '' );
		onClose();
	}, [ onClose ] );

	useEffect( () => {
		if ( ! isOpen ) {
			stopStream( streamRef.current );
			streamRef.current = null;
			setReady( false );
			setError( '' );
			return undefined;
		}

		let cancelled = false;

		async function startCamera() {
			if ( ! deviceCameraSupported() ) {
				setError(
					__(
						'Camera is not available in this browser.',
						'kennelflow-core'
					)
				);
				return;
			}

			setError( '' );
			setReady( false );

			try {
				const stream = await navigator.mediaDevices.getUserMedia( {
					video: {
						facingMode: { ideal: 'environment' },
						width: { ideal: 1920 },
						height: { ideal: 1080 },
					},
					audio: false,
				} );

				if ( cancelled ) {
					stopStream( stream );
					return;
				}

				streamRef.current = stream;

				const video = videoRef.current;
				if ( video ) {
					video.srcObject = stream;
					await video.play();
					setReady( true );
				}
			} catch ( err ) {
				if ( cancelled ) {
					return;
				}
				setError(
					__(
						'Could not access the camera. Check browser permissions and try again.',
						'kennelflow-core'
					)
				);
			}
		}

		startCamera();

		return () => {
			cancelled = true;
			stopStream( streamRef.current );
			streamRef.current = null;
		};
	}, [ isOpen ] );

	const handleCapture = useCallback( () => {
		const video = videoRef.current;
		if ( ! video || ! ready || video.videoWidth < 1 || video.videoHeight < 1 ) {
			return;
		}

		const canvas = document.createElement( 'canvas' );
		canvas.width = video.videoWidth;
		canvas.height = video.videoHeight;
		const ctx = canvas.getContext( '2d' );
		if ( ! ctx ) {
			return;
		}
		ctx.drawImage( video, 0, 0 );

		canvas.toBlob(
			( blob ) => {
				if ( ! blob ) {
					setError(
						__( 'Could not capture photo.', 'kennelflow-core' )
					);
					return;
				}
				const file = new File(
					[ blob ],
					`groom-session-${ Date.now() }.jpg`,
					{ type: 'image/jpeg' }
				);
				stopStream( streamRef.current );
				streamRef.current = null;
				setReady( false );
				onCapture( file );
			},
			'image/jpeg',
			0.92
		);
	}, [ ready, onCapture ] );

	if ( ! isOpen ) {
		return null;
	}

	const modal = (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="kf-device-camera"
			role="dialog"
			aria-modal="true"
			aria-labelledby="kf-device-camera-title"
			onKeyDown={ ( e ) => {
				if ( 'Escape' === e.key ) {
					closeCamera();
				}
			} }
		>
			<div className="kf-device-camera__backdrop" onClick={ closeCamera } />
			<div className="kf-device-camera__panel">
				<h4 id="kf-device-camera-title" className="kf-device-camera__title">
					{ title || __( 'Take photo', 'kennelflow-core' ) }
				</h4>
				{ error ? (
					<p className="kf-device-camera__error">{ error }</p>
				) : (
					<video
						ref={ videoRef }
						className="kf-device-camera__video"
						playsInline
						muted
						autoPlay
					/>
				) }
				<div className="kf-device-camera__actions">
					<button
						type="button"
						className="button"
						onClick={ closeCamera }
					>
						{ __( 'Cancel', 'kennelflow-core' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						disabled={ ! ready || !! error }
						onClick={ handleCapture }
					>
						{ __( 'Capture photo', 'kennelflow-core' ) }
					</button>
				</div>
			</div>
		</div>
	);

	return 'undefined' !== typeof document
		? createPortal( modal, document.body )
		: modal;
}

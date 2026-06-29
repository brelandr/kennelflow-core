/**
 * Catches React render errors in the Hub calendar UI.
 *
 * @package KennelFlow
 */

import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { logCalendarTrace } from './calendarDebug';

/**
 * @typedef {object} Props
 * @property {import('react').ReactNode} children
 * @property {(error: Error) => void} [onError]
 */

/**
 * @extends {Component<Props, { error: Error|null, componentStack: string }>}
 */
export class CalendarErrorBoundary extends Component {
	/**
	 * @param {Props} props
	 */
	constructor( props ) {
		super( props );
		this.state = {
			error: null,
			componentStack: '',
		};
	}

	/**
	 * @param {Error} error
	 * @return {{ error: Error }}
	 */
	static getDerivedStateFromError( error ) {
		return { error };
	}

	/**
	 * @param {Error}                           error
	 * @param {{ componentStack?: string }} info
	 * @return {void}
	 */
	componentDidCatch( error, info ) {
		const stack = info && info.componentStack ? String( info.componentStack ) : '';
		this.setState( { componentStack: stack } );
		logCalendarTrace( 'React render error', {
			message: error.message,
			stack: error.stack,
			componentStack: stack,
		} );
		if ( 'function' === typeof this.props.onError ) {
			this.props.onError( error );
		}
	}

	render() {
		const { error, componentStack } = this.state;
		if ( error ) {
			return (
				<div className="kf-cal-error notice notice-error">
					<p>
						<strong>{ __( 'Calendar stopped rendering', 'kennelflow-core' ) }</strong>
					</p>
					<p>{ error.message }</p>
					{ componentStack ? (
						<details className="kf-cal-diagnostics__debug">
							<summary>{ __( 'Component stack', 'kennelflow-core' ) }</summary>
							<pre>{ componentStack }</pre>
						</details>
					) : null }
					{ error.stack ? (
						<details className="kf-cal-diagnostics__debug">
							<summary>{ __( 'JavaScript stack', 'kennelflow-core' ) }</summary>
							<pre>{ error.stack }</pre>
						</details>
					) : null }
				</div>
			);
		}

		return this.props.children;
	}
}

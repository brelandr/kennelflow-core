/**
 * Staff permissions matrix: KennelFlow-managed capabilities × roles.
 *
 * @package KennelFlow
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';

import './PermissionsMatrix.css';

/**
 * @param {object} props
 * @param {object} props.data API payload from GET /kennelflow/v1/permissions
 * @param {import('@tanstack/react-query').UseMutationResult} props.mutation PATCH mutation
 */
export function PermissionsMatrix( { data, mutation } ) {
	if ( ! data || ! Array.isArray( data.roles ) || ! Array.isArray( data.capabilities ) ) {
		return (
			<p className="kf-permissions-matrix__empty">
				{ __( 'No permission data available.', 'kennelflow-core' ) }
			</p>
		);
	}

	const grants = data.grants && 'object' === typeof data.grants ? data.grants : {};

	/**
	 * @param {string} roleSlug
	 * @param {string} capSlug
	 * @return {boolean}
	 */
	const isCellPending = ( roleSlug, capSlug ) => {
		return (
			mutation.isPending &&
			mutation.variables &&
			mutation.variables.role === roleSlug &&
			mutation.variables.capability === capSlug
		);
	};

	return (
		<div className="kf-permissions-matrix__scroll">
			{ mutation.isError && (
				<div className="notice notice-error kf-permissions-matrix__notice">
					<p>
						{ mutation.error && mutation.error.message
							? mutation.error.message
							: __( 'Could not update permission.', 'kennelflow-core' ) }
					</p>
				</div>
			) }
			<table className="widefat striped kf-permissions-matrix__table">
				<thead>
					<tr>
						<th scope="col" className="kf-permissions-matrix__cap-col">
							{ __( 'Capability', 'kennelflow-core' ) }
						</th>
						{ data.roles.map( ( role ) => (
							<th key={ role.slug } scope="col" className="kf-permissions-matrix__role-col">
								{ role.name }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ data.capabilities.map( ( cap ) => (
						<tr key={ cap.slug }>
							<th scope="row" className="kf-permissions-matrix__cap-label">
								{ cap.label }
							</th>
							{ data.roles.map( ( role ) => {
								const roleGrants = grants[ role.slug ] || {};
								const checked = !! roleGrants[ cap.slug ];
								const id = `kf-pm-${ role.slug }-${ cap.slug }`;
								const pending = isCellPending( role.slug, cap.slug );
								return (
									<td key={ role.slug } className="kf-permissions-matrix__cell">
										<input
											id={ id }
											type="checkbox"
											className="kf-permissions-matrix__checkbox"
											checked={ checked }
											disabled={ pending }
											onChange={ ( e ) => {
												mutation.mutate( {
													role: role.slug,
													capability: cap.slug,
													grant: e.target.checked,
												} );
											} }
											aria-label={ `${ cap.label } — ${ role.name }` }
										/>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * @param {object} props
 * @param {import('@wordpress/api-fetch').default} props.apiFetch Bound api-fetch instance
 */
export function PermissionsMatrixApp( { apiFetch: fetchImpl } ) {
	const queryClient = useQueryClient();

	const query = useQuery( {
		queryKey: [ 'kf', 'permissions' ],
		queryFn: async () => {
			return fetchImpl( { path: '/kennelflow/v1/permissions' } );
		},
	} );

	const mutation = useMutation( {
		mutationFn: async ( { role, capability, grant } ) => {
			return fetchImpl( {
				path: '/kennelflow/v1/permissions',
				method: 'PATCH',
				data: { role, capability, grant },
			} );
		},
		onMutate: async ( vars ) => {
			await queryClient.cancelQueries( { queryKey: [ 'kf', 'permissions' ] } );
			const previous = queryClient.getQueryData( [ 'kf', 'permissions' ] );
			queryClient.setQueryData( [ 'kf', 'permissions' ], ( old ) => {
				if ( ! old || 'object' !== typeof old ) {
					return old;
				}
				return {
					...old,
					grants: {
						...old.grants,
						[ vars.role ]: {
							...( old.grants[ vars.role ] || {} ),
							[ vars.capability ]: vars.grant,
						},
					},
				};
			} );
			return { previous };
		},
		onError: ( err, vars, context ) => {
			if ( context && context.previous ) {
				queryClient.setQueryData( [ 'kf', 'permissions' ], context.previous );
			}
		},
		onSuccess: ( response ) => {
			if ( response && response.grants ) {
				const cleaned = { ...response };
				delete cleaned.updated_role;
				queryClient.setQueryData( [ 'kf', 'permissions' ], cleaned );
			}
		},
	} );

	if ( query.isLoading ) {
		return (
			<p className="kf-permissions-matrix__loading">
				{ __( 'Loading permissions…', 'kennelflow-core' ) }
			</p>
		);
	}

	if ( query.isError ) {
		const msg =
			query.error && query.error.message
				? query.error.message
				: __( 'Could not load permissions.', 'kennelflow-core' );
		return (
			<div className="notice notice-error">
				<p>{ msg }</p>
			</div>
		);
	}

	return <PermissionsMatrix data={ query.data } mutation={ mutation } />;
}

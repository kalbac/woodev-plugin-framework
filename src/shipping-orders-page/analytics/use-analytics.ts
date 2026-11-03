/**
 * Loads the analytics answer for a request (#711).
 *
 * The previous answer stays on screen, dimmed, while the next one loads (no flash of an empty page on every date pick);
 * a request that is superseded is aborted. `reload()` asks the server for a fresh read (`refresh=1`, which skips its
 * cache) — only for that one call, never for the next period pick.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchAnalytics } from './analytics-rest';
import type { AnalyticsRequest, AnalyticsResponse } from './types';

export interface AnalyticsState {
	data: AnalyticsResponse | null;
	loading: boolean;
	/** The server's own Russian message, or a generic one; `null` when the last load worked. */
	error: string | null;
	reload: () => void;
}

function messageOf( error: unknown ): string {
	const message = ( error as { message?: unknown } | null )?.message;

	return 'string' === typeof message && '' !== message ? message : __( 'Не удалось загрузить аналитику.', 'woodev-plugin-framework' );
}

export function useAnalytics( request: AnalyticsRequest | null ): AnalyticsState {
	const [ data, setData ] = useState< AnalyticsResponse | null >( null );
	const [ loading, setLoading ] = useState( null !== request );
	const [ error, setError ] = useState< string | null >( null );
	const [ reloads, setReloads ] = useState( 0 );
	const forceRefresh = useRef( false );
	const key = JSON.stringify( request );

	useEffect( () => {
		if ( null === request ) {
			setLoading( false );
			return;
		}

		const controller = new AbortController();
		const refresh = forceRefresh.current;
		forceRefresh.current = false;

		setLoading( true );
		setError( null );

		fetchAnalytics( request, refresh, controller.signal )
			.then( ( answer ) => {
				setData( answer );
				setLoading( false );
			} )
			.catch( ( failure: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				setError( messageOf( failure ) );
				setLoading( false );
			} );

		return () => controller.abort();
		// `key` stands for `request`: a new object with the same content must not refetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key, reloads ] );

	const reload = useCallback( () => {
		forceRefresh.current = true;
		setReloads( ( count ) => count + 1 );
	}, [] );

	return { data, loading, error, reload };
}

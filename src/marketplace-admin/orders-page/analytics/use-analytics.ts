/**
 * Loads the analytics answer for a request (#1891), the way the delivery tab does: the previous answer stays on screen, dimmed,
 * while the next one loads; a superseded request is aborted; `reload()` asks the server for a fresh read once.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { analyticsPath } from './request';
import type { AnalyticsRequest, AnalyticsResponse } from './types';

export interface AnalyticsState {
	data: AnalyticsResponse | null;
	loading: boolean;
	/** The server's message, or the fallback; null when the last load worked. */
	error: string | null;
	/** The server's error code, '' when there is none. */
	errorCode: string;
	reload: () => void;
}

interface Failure {
	message?: unknown;
	code?: unknown;
}

export function useAnalytics( request: AnalyticsRequest | null, fallback: string ): AnalyticsState {
	const [ data, setData ] = useState< AnalyticsResponse | null >( null );
	const [ loading, setLoading ] = useState( request !== null );
	const [ error, setError ] = useState< string | null >( null );
	const [ errorCode, setErrorCode ] = useState( '' );
	const [ reloads, setReloads ] = useState( 0 );
	const forceRefresh = useRef( false );
	const key = JSON.stringify( request );

	useEffect( () => {
		if ( request === null ) {
			setLoading( false );
			return;
		}

		const controller = new AbortController();
		const refresh = forceRefresh.current;
		forceRefresh.current = false;

		setLoading( true );
		setError( null );
		setErrorCode( '' );

		apiFetch< AnalyticsResponse >( { path: analyticsPath( request, refresh ), signal: controller.signal } )
			.then( ( answer ) => {
				setData( answer );
				setLoading( false );
			} )
			.catch( ( failure: Failure | null ) => {
				if ( controller.signal.aborted ) {
					return;
				}

				const message = failure?.message;

				setError( typeof message === 'string' && message !== '' ? message : fallback );
				setErrorCode( typeof failure?.code === 'string' ? failure.code : '' );
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

	return { data, loading, error, errorCode, reload };
}

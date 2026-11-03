/**
 * REST client of the «Аналитика» tab (#711): `GET woodev/v1/shipping/orders/analytics`.
 *
 * Same wiring as every call of `../rest.ts`: the route root and the nonce come from `window.woodevShippingOrders`
 * (inlined by `Orders_Registry::enqueue_assets()`), the nonce travels in the `X-WP-Nonce` header.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { withQuery } from '../../components/rest-url';
import '../rest';
import type { AnalyticsRequest, AnalyticsResponse } from './types';

/** The query string of a request; `refresh=1` is added only when the merchant asked for a fresh read. */
export function buildAnalyticsQuery( request: AnalyticsRequest, refresh: boolean ): string {
	const params = new URLSearchParams( { after: request.after, before: request.before, interval: request.interval } );

	if ( request.compareAfter && request.compareBefore ) {
		params.set( 'compare_after', request.compareAfter );
		params.set( 'compare_before', request.compareBefore );
	}

	if ( 'all' !== request.carrier ) {
		params.set( 'carrier', request.carrier );
	}

	if ( refresh ) {
		params.set( 'refresh', '1' );
	}

	return params.toString();
}

export function fetchAnalytics(
	request: AnalyticsRequest,
	refresh: boolean,
	signal?: AbortSignal
): Promise< AnalyticsResponse > {
	const { restRoot = '', nonce = '' } = window.woodevShippingOrders || {};

	return apiFetch< AnalyticsResponse >( {
		url: withQuery( `${ restRoot.replace( /\/+$/, '' ) }/analytics`, buildAnalyticsQuery( request, refresh ) ),
		headers: { 'X-WP-Nonce': nonce },
		...( signal ? { signal } : {} ),
	} );
}

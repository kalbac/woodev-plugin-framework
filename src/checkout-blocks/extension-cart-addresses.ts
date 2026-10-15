/**
 * Framework cart extensions own rates and pickup snapshots, never the native address (#1293).
 *
 * WooCommerce's extensionCartUpdate imports the reply's addresses unless its debounced localStorage
 * dirty flag is set. That flag trails a setter by 300 ms, and updateCustomerData clears it even for
 * a partial address request. A late fee/pickup reply can therefore erase a newer city or email;
 * core's next focusout then pushes that erased address back to the server.
 *
 * Remove only our extensions' address snapshots BEFORE core imports their replies. Native setters
 * remain the address owner; a pickup's explicit destination is adopted from its confirmation by
 * adoptDestination, which moves only street/postcode and respects the billing relationship.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { requestPath } from './customer-data-request';

type ApiObject = Record< string, unknown >;

function isObject( value: unknown ): value is ApiObject {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

function withoutAddresses( response: unknown ): unknown {
	if ( ! isObject( response ) ) {
		return response;
	}

	const { shipping_address, billing_address, ...contents } = response;

	return contents;
}

/** Core's receiveError also imports a cart attached to an API refusal. */
function withoutErrorAddresses( error: unknown ): unknown {
	if ( ! isObject( error ) || ! isObject( error.data ) || ! isObject( error.data.cart ) ) {
		return error;
	}

	return { ...error, data: { ...error.data, cart: withoutAddresses( error.data.cart ) } };
}

/** Install before any framework extension request is sent; foreign namespaces are untouched. */
export function keepExtensionAddressesInForm( namespaces: string[] ): void {
	const owned = new Set( namespaces.filter( ( namespace ) => namespace !== '' ) );
	if ( owned.size === 0 ) {
		return;
	}

	const matches = ( request: unknown ): boolean => {
		if ( ! isObject( request ) || requestPath( request.path ?? request.url ) !== '/wc/store/v1/cart/extensions' ) {
			return false;
		}
		const data = request.data ?? request.body;
		return isObject( data ) && typeof data.namespace === 'string' && owned.has( data.namespace );
	};

	apiFetch.use( ( options, next ) => {
		const direct = matches( options );
		const requests = requestPath( options.path ?? options.url ) === '/wc/store/v1/batch' && isObject( options.data )
			? options.data.requests
			: null;
		const indexes = Array.isArray( requests )
			? requests.flatMap( ( request, index ) => matches( request ) ? [ index ] : [] )
			: [];

		if ( ! direct && indexes.length === 0 ) {
			return next( options );
		}

		return next( options ).then( ( response ) => {
			if ( direct ) {
				return withoutAddresses( response );
			}
			if ( ! isObject( response ) || ! Array.isArray( response.responses ) ) {
				return response;
			}

			return {
				...response,
				responses: response.responses.map( ( item: unknown, index: number ) =>
					indexes.includes( index ) && isObject( item ) && typeof item.status === 'number'
						? { ...item, body: item.status >= 200 && item.status < 300
							? withoutAddresses( item.body ) : withoutErrorAddresses( item.body ) }
						: item
				),
			};
		}, ( error: unknown ) => Promise.reject( withoutErrorAddresses( error ) ) );
	} );
}

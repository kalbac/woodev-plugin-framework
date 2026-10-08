/**
 * Mounts the cities list into the markup `City_Limit_Form::render()` prints (#1176).
 *
 * WooCommerce draws a method's settings on the server and the zone screen inserts that HTML into a Backbone modal after
 * the page has loaded, then announces it with `wc_backbone_modal_loaded`. So: mount what is on the page now, mount again
 * on that event, and let go of roots whose element the modal took away.
 *
 * @package woodev-plugin-framework
 */

import { createRoot } from '@wordpress/element';
import CityLimitList, { type CityLimitConfig } from './city-limit-list';

/** The element `City_Limit_Form::render()` prints. */
export const MOUNT_SELECTOR = '.woodev-city-limit';

type Root = ReturnType<typeof createRoot>;

const roots = new Map<Element, Root>();

/**
 * Reads the `data-config` JSON of a mount element.
 *
 * @param {Element} element The mount element.
 * @return {CityLimitConfig|null} The config, or `null` when it is missing or broken.
 */
export function readConfig( element: Element ): CityLimitConfig | null {
	try {
		const parsed = JSON.parse( element.getAttribute( 'data-config' ) || '' ) as CityLimitConfig;

		return parsed && 'string' === typeof parsed.inputId && Array.isArray( parsed.items ) ? parsed : null;
	} catch ( error ) {
		return null;
	}
}

/**
 * Lets go of the roots whose element is no longer in the document (the modal was closed).
 *
 * @return {void}
 */
export function releaseDetached(): void {
	roots.forEach( ( root, element ) => {
		if ( ! element.isConnected ) {
			root.unmount();
			roots.delete( element );
		}
	} );
}

/**
 * Mounts the list into every mount element under `scope` that has none yet.
 *
 * @param {ParentNode} scope Where to look. Default: the document.
 * @return {void}
 */
export function mountAll( scope: ParentNode = document ): void {
	releaseDetached();

	scope.querySelectorAll( MOUNT_SELECTOR ).forEach( ( element ) => {
		if ( roots.has( element ) ) {
			return;
		}

		const config = readConfig( element );
		const input = config ? document.getElementById( config.inputId ) : null;

		if ( ! config || ! ( input instanceof HTMLInputElement ) ) {
			return;
		}

		const root = createRoot( element );

		root.render(
			<CityLimitList
				config={ config }
				onChange={ ( value ) => {
					input.value = value;
					// Bubbles: WooCommerce's modal and our show-if script listen on the document.
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} }
			/>
		);

		roots.set( element, root );
	} );
}

/**
 * Starts watching: mounts what is there, and mounts again whenever the zone modal opens.
 *
 * @return {void}
 */
export function start(): void {
	const run = () => mountAll();

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}

	// The event is a jQuery one (triggered on `document.body`), which a native listener never hears.
	const jq = ( window as unknown as { jQuery?: ( target: unknown ) => { on: ( events: string, handler: () => void ) => void } } ).jQuery;

	if ( jq ) {
		jq( document.body ).on( 'wc_backbone_modal_loaded wc_backbone_modal_removed', run );
	}
}

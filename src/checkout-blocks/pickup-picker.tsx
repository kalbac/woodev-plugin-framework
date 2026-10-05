/**
 * The Checkout Blocks pickup-point button (SP-11 C-2b, #1089).
 *
 * A forced inner block of the shipping-methods block. For a rate one of the framework's pickup
 * fields owns — and for no other — it shows a «choose a pickup point» button and, once a point is
 * confirmed, that point's address. The button opens the storefront map session both checkouts share
 * (`pickup-session.js`, operator decision D-4 A), in the framework's accessible dialog.
 *
 * THE BUTTON HOLDS NO SELECTION OF ITS OWN. What it shows is derived, on every render, from the
 * cart store: the server names the field that owns the chosen rate and carries the confirmed point
 * (`Store_Api_Pickup::cart_data()`). So
 *
 * - a reload restores the point — the cart WooCommerce preloads already carries the snapshot;
 * - a switch to another rate or carrier clears it — the snapshot is scoped to the rate it was
 *   confirmed under, and a point of another rate is never shown, not even for the moment
 *   WooCommerce's rate selection is still in flight (`resolveView()`);
 * - a refusal leaves nothing behind — the server keeps no point it refused.
 *
 * The same view feeds the two things the order needs: the checkout request's echo of the
 * confirmation, and a validation error that blocks Place Order while our rate has no point.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { createHost } from './pickup-host';
import {
	buildEcho,
	readConfig,
	readExtension,
	readSelectedRateId,
	readSessionApi,
	readVisibleError,
	releasePoint,
	requirePoint,
	resolveView,
	validationId,
} from './pickup-stores';
import type { PickupConfig, PickupData, PickupExtension, PickupFieldDescriptor, PickupSession } from './pickup-types';
import { refreshRates, subscribeCheckoutFailure } from './wc-stores';

/** The inner-block helper WooCommerce hands every Checkout inner block (`checkoutExtensionData`). */
export interface CheckoutExtensionData {
	setExtensionData?: ( namespace: string, key: string, value: unknown ) => void;
}

export interface PickupPickerProps {
	data: PickupData;
	checkoutExtensionData?: CheckoutExtensionData;
}

const NO_FIELDS: PickupFieldDescriptor[] = [];

/** The accent custom properties the trigger wears — the ones `pickup.css` styles it by. */
const ACCENT_PROPERTIES: Array< [ 'accentColor' | 'accentFillColor' | 'accentContrastColor', string ] > = [
	[ 'accentColor', '--woodev-pickup-accent' ],
	[ 'accentFillColor', '--woodev-pickup-accent-fill' ],
	[ 'accentContrastColor', '--woodev-pickup-accent-contrast' ],
];

/** A CSS colour the server resolved — re-validated, since it reaches `style` from a page global. */
const SAFE_COLOR = /^#[0-9a-f]{3,8}$/i;

/**
 * The classic trigger's class list (`pickup-mount.js`: `triggerClassName()`) — `button` and
 * `woodev-pickup-trigger` are what `pickup.css` paints the accent on, the theme's own button class
 * gives the shape — plus the block's own element class.
 */
function triggerClassName( config: PickupConfig ): string {
	const classes = [ 'button', 'woodev-pickup-trigger', 'woodev-pickup-block__trigger' ];
	const themeClass = typeof config.themeButtonClass === 'string' ? config.themeButtonClass : '';

	for ( const token of themeClass.split( /\s+/ ) ) {
		const clean = token.replace( /[^A-Za-z0-9_-]/g, '' );

		if ( clean !== '' && ! classes.includes( clean ) ) {
			classes.push( clean );
		}
	}

	return classes.join( ' ' );
}

function applyAccent( button: HTMLElement | null, config: PickupConfig ): void {
	if ( ! button ) {
		return;
	}

	for ( const [ key, property ] of ACCENT_PROPERTIES ) {
		const colour = config[ key ];

		if ( typeof colour === 'string' && SAFE_COLOR.test( colour ) ) {
			button.style.setProperty( property, colour );
		}
	}
}

export function PickupPicker( { data, checkoutExtensionData }: PickupPickerProps ) {
	const namespace = data.namespace ?? '';
	const fields = data.fields ?? NO_FIELDS;

	const extension: PickupExtension | null = useSelect(
		( registrySelect ) => readExtension( namespace, registrySelect ),
		[ namespace ]
	);
	const selectedRateId: string = useSelect( ( registrySelect ) => readSelectedRateId( registrySelect ), [] );

	const view = useMemo(
		() => resolveView( fields, extension, selectedRateId ),
		[ fields, extension, selectedRateId ]
	);
	const { field, confirmed } = view;
	const config = field ? readConfig( field ) : null;
	const errorId = field ? validationId( field ) : '';

	const visibleError: string = useSelect(
		( registrySelect ) => ( errorId === '' ? '' : readVisibleError( errorId, registrySelect ) ),
		[ errorId ]
	);

	const triggerRef = useRef< HTMLButtonElement | null >( null );
	const sessionRef = useRef< PickupSession | null >( null );
	const [ unavailable, setUnavailable ] = useState( false );

	// A failed payment clears the session choice after the order has kept it. Re-read the cart
	// through WooCommerce's existing customer/rates refresh so its extension snapshot can restore it.
	useEffect( () => subscribeCheckoutFailure( () => { void refreshRates(); } ), [] );

	const closeSession = useCallback( () => {
		const session = sessionRef.current;

		sessionRef.current = null;
		session?.destroy();
	}, [] );

	/*
	 * The checkout request's echo: the confirmation's identity for the owning field, `null` for
	 * every other. Rewritten whenever the confirmation changes — a rate switch included — so an
	 * order is never placed echoing a point the shopper no longer sees.
	 */
	const echo = useMemo( () => buildEcho( fields, view ), [ fields, view ] );
	const echoKey = JSON.stringify( echo );
	const setExtensionData = checkoutExtensionData?.setExtensionData;

	useEffect( () => {
		if ( namespace !== '' && typeof setExtensionData === 'function' ) {
			setExtensionData( namespace, 'pickup', JSON.parse( echoKey ) );
		}
	}, [ namespace, setExtensionData, echoKey ] );

	// The block going away (WooCommerce unmounts the shipping methods for its own local pickup)
	// must not leave a confirmation echoed for a rate the order no longer uses.
	useEffect(
		() => () => {
			if ( namespace !== '' && typeof setExtensionData === 'function' ) {
				setExtensionData( namespace, 'pickup', buildEcho( fields, resolveView( fields, null, '' ) ) );
			}
		},
		[ namespace, setExtensionData, fields ]
	);

	/*
	 * Our rate with no confirmed point blocks Place Order; the server's pre-payment validation is
	 * the authority, this is the shopper's earlier and nearer notice. Only this field's own error
	 * is ever set or cleared.
	 */
	const required = data.i18n?.required ?? '';
	const needsPoint = errorId !== '' && config !== null && confirmed === null;

	useEffect( () => {
		if ( errorId === '' ) {
			return undefined;
		}

		if ( needsPoint ) {
			requirePoint( errorId, required );
		} else {
			releasePoint( errorId );
		}

		return () => releasePoint( errorId );
	}, [ errorId, needsPoint, required ] );

	/*
	 * A session belongs to the rate it was opened for. The rate changing under an open dialog — or
	 * the block going away — tears it down, which also discards an answer still in flight.
	 *
	 * `left` is LATCHED for everything opened under that rate: a confirmation answering after the
	 * shopper moved on must not write the point's address into the form, even if its own late cart
	 * puts the old rate back into the store for a moment (#1090).
	 */
	const sessionScope = field ? `${ field.pluginId }|${ field.fieldId }|${ selectedRateId }` : '';
	const scopeRef = useRef( { left: false } );

	useEffect( () => {
		const scope = { left: false };

		scopeRef.current = scope;

		return () => {
			scope.left = true;
			closeSession();
		};
	}, [ sessionScope, closeSession ] );

	useEffect( () => {
		applyAccent( triggerRef.current, config ?? { fieldId: '' } );
	} );

	if ( ! field || ! config ) {
		return null;
	}

	const i18n = config.i18n ?? {};

	const open = (): void => {
		const api = readSessionApi();

		closeSession();

		if ( ! api ) {
			setUnavailable( true );

			return;
		}

		const scope = scopeRef.current;

		setUnavailable( false );
		sessionRef.current = api.open(
			config,
			createHost( {
				data,
				field,
				config,
				trigger: triggerRef.current,
				onClose: closeSession,
				isCurrent: () => ! scope.left,
			} )
		);
	};

	return (
		<div className="woodev-pickup-block" data-field-id={ field.fieldId }>
			<button
				ref={ triggerRef }
				type="button"
				className={ triggerClassName( config ) }
				aria-haspopup="dialog"
				aria-describedby={ visibleError !== '' ? `${ errorId }-error` : undefined }
				onClick={ open }
			>
				{ confirmed ? i18n.triggerChange : i18n.trigger }
			</button>
			{ confirmed && confirmed.summary !== '' && (
				<p className="woodev-pickup-block__chosen woodev-pickup-chosen-address">
					{ i18n.chosenPointAddress } <strong>{ confirmed.summary }</strong>
				</p>
			) }
			{ visibleError !== '' && (
				<p id={ `${ errorId }-error` } className="woodev-pickup-block__error" role="alert">
					{ visibleError }
				</p>
			) }
			{ unavailable && (
				<p className="woodev-pickup-block__error" role="alert">
					{ i18n.error }
				</p>
			) }
		</div>
	);
}

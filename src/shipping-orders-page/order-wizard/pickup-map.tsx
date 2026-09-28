/**
 * The pickup list + map, as a React host over {@link createPickupSession} (#710 D3 / O10,
 * increment I5a of card #970).
 *
 * The picker is a set of framework scripts that draw into a DOM node, so this component only
 * owns that node's lifetime: a session is created when the inputs that change WHAT it shows
 * change (the destination record, the order weight, the payment method, the endpoint) and
 * destroyed when they change again or the step goes away. The chosen point is held by the
 * wizard, not here — a remount reopens the card on it, it does not lose it.
 *
 * ⚠ Never mount this without checking {@link isPickupRuntimeAvailable} first: with a script
 * missing there is nothing to draw, and the step offers a typed point code instead.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { createPickupSession } from './pickup-session';
import type { PickupPoint, PickupSession, PickupWizardConfig } from './pickup-session';

export interface PickupMapProps {
	config: PickupWizardConfig;
	/** Weight (grams), payment method, destination record — read by the data source on every request. */
	context: Record<string, unknown>;
	/** A geocodable place name the map centres on. */
	locality: string;
	/** The settlement record's key — how a `bulk` carrier addresses its points. */
	localityKey: string;
	/** The REST nonce of the orders page. */
	nonce: string;
	/** The chosen point's id, '' for none. */
	selectedId: string;
	onSelect: ( point: PickupPoint ) => void;
}

export default function PickupMap( { config, context, locality, localityKey, nonce, selectedId, onSelect }: PickupMapProps ) {
	const hostRef = useRef<HTMLDivElement>( null );
	const sessionRef = useRef<PickupSession | null>( null );
	const [ failure, setFailure ] = useState( '' );

	// The session reads these on demand: a NEW value must be heard without rebuilding the map.
	const latest = useRef( { context, selectedId, onSelect } );
	latest.current = { context, selectedId, onSelect };

	// What the picker SHOWS depends on these; anything else (typing in the price field) must not rebuild it.
	const contextKey = JSON.stringify( context );

	useEffect( () => {
		const host = hostRef.current;

		if ( ! host ) {
			return undefined;
		}

		setFailure( '' );

		const session = createPickupSession( {
			host,
			config,
			nonce,
			context: () => latest.current.context,
			locality,
			localityKey,
			selectedId: latest.current.selectedId,
			onSelect: ( point ) => latest.current.onSelect( point ),
			onError: setFailure,
		} );

		sessionRef.current = session;

		return () => {
			session.destroy();
			sessionRef.current = null;
		};
	}, [ config.restRoot, config.provider, contextKey, locality, localityKey, nonce ] );

	// A point chosen anywhere else (a typed code, a new tariff clearing it) is mirrored onto the card.
	useEffect( () => {
		sessionRef.current?.setSelectedId( selectedId );
	}, [ selectedId ] );

	return (
		<>
			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }
			<div ref={ hostRef } className="woodev-order-wizard__pickup-map" />
		</>
	);
}

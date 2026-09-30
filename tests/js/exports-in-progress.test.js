/**
 * The «exports in progress» notice and the «cancel failed» badge (#1007).
 *
 * Nothing here words a plural: the server sends the finished sentence, and these tests pin that
 * the page shows it verbatim.
 *
 * @see src/shipping-orders-page/exports-in-progress.tsx
 */

import '@testing-library/jest-dom';
import { act, render, screen, within } from '@testing-library/react';
import { ExportsInProgressNotice } from '../../src/shipping-orders-page/exports-in-progress';
import { StatusCell } from '../../src/shipping-orders-page/app';

const KEY = 'woodev-exports-in-progress';
const ONE = 'Сейчас выгружается 1 заказ перевозчику';
const TWO = 'Сейчас выгружаются 2 заказа перевозчику';
const FIVE = 'Сейчас выгружаются 5 заказов перевозчику';

function setBootstrap( exportsInProgress ) {
	window.woodevShippingOrders = {
		restRoot: '/wp-json/woodev/v1/shipping/orders',
		nonce: 'n',
		providers: [],
		exportsInProgress,
	};
}

/** A jQuery stand-in that records handlers by their full event name, namespace included. */
function installJquery() {
	const handlers = {};
	const off = jest.fn( ( event ) => {
		delete handlers[ event ];
	} );

	window.jQuery = () => ( {
		on: ( event, handler ) => {
			handlers[ event ] = handler;
		},
		off,
	} );

	return { handlers, off };
}

const SEND = 'heartbeat-send.woodevExportsInProgress';
const TICK = 'heartbeat-tick.woodevExportsInProgress';

afterEach( () => {
	delete window.woodevShippingOrders;
	delete window.jQuery;
	delete window.wp;
} );

describe( 'ExportsInProgressNotice', () => {
	test( 'renders nothing without a bootstrap object, and registers no heartbeat handler', () => {
		const { handlers } = installJquery();
		const { container } = render( <ExportsInProgressNotice /> );

		expect( container ).toBeEmptyDOMElement();
		expect( handlers ).toEqual( {} );
	} );

	test( 'renders nothing for a malformed bootstrap object', () => {
		setBootstrap( { count: 'two', text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'renders nothing when the count is 0', () => {
		installJquery();
		setBootstrap( { count: 0, text: '', heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	test.each( [ [ 1, ONE ], [ 2, TWO ], [ 5, FIVE ] ] )( 'shows the server sentence verbatim for %i (no JS-side wording)', ( count, text ) => {
		setBootstrap( { count, text, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		expect( within( container ).getByText( text ) ).toBeInTheDocument();
		expect( container.querySelector( '.woodev-orders-exports-notice' ) ).not.toBeNull();
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( container.querySelector( 'a' ) ).toBeNull();
	} );

	test( 'a heartbeat-send handler sets the server key to true', () => {
		const { handlers } = installJquery();
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		const payload = {};
		handlers[ SEND ]( {}, payload );

		expect( payload ).toEqual( { [ KEY ]: true } );
	} );

	test( 'a tick with count 0 hides the notice; a later tick with a count shows it again, without a remount', () => {
		const { handlers } = installJquery();
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );
		expect( within( container ).getByText( TWO ) ).toBeInTheDocument();

		act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 0, text: '' } } ) );
		expect( within( container ).queryByText( TWO ) ).toBeNull();

		act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 3, text: 'Сейчас выгружаются 3 заказа перевозчику' } } ) );
		expect( within( container ).getByText( 'Сейчас выгружаются 3 заказа перевозчику' ) ).toBeInTheDocument();
	} );

	test( 'a tick updates the sentence in place', () => {
		const { handlers } = installJquery();
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 5, text: FIVE } } ) );

		expect( within( container ).getByText( FIVE ) ).toBeInTheDocument();
		expect( within( container ).queryByText( TWO ) ).toBeNull();
	} );

	test( 'a tick with a positive count asks the heartbeat for the fast interval', () => {
		const { handlers } = installJquery();
		const interval = jest.fn();
		window.wp = { heartbeat: { interval } };
		setBootstrap( { count: 0, text: '', heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 0, text: '' } } ) );
		expect( interval ).not.toHaveBeenCalled();

		act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 1, text: ONE } } ) );
		expect( interval ).toHaveBeenCalledWith( 'fast' );
	} );

	test( 'a positive tick does not throw when wp.heartbeat is absent', () => {
		const { handlers } = installJquery();
		setBootstrap( { count: 1, text: ONE, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		expect( () => act( () => handlers[ TICK ]( {}, { [ KEY ]: { count: 2, text: TWO } } ) ) ).not.toThrow();
		expect( within( container ).getByText( TWO ) ).toBeInTheDocument();
	} );

	test.each( [
		[ 'no answer', {} ],
		[ 'a non-object answer', { [ KEY ]: 'nope' } ],
		[ 'a string count', { [ KEY ]: { count: '3', text: 'x' } } ],
		[ 'a negative count', { [ KEY ]: { count: -1, text: 'x' } } ],
		[ 'a NaN count', { [ KEY ]: { count: NaN, text: 'x' } } ],
		[ 'a non-string text', { [ KEY ]: { count: 3, text: 7 } } ],
		[ 'a missing text', { [ KEY ]: { count: 3 } } ],
	] )( 'ignores a malformed tick: %s', ( label, data ) => {
		const { handlers } = installJquery();
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		act( () => handlers[ TICK ]( {}, data ) );

		expect( within( container ).getByText( TWO ) ).toBeInTheDocument();
	} );

	test( 'removes exactly its namespaced handlers on unmount', () => {
		const { handlers, off } = installJquery();
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { unmount } = render( <ExportsInProgressNotice /> );
		expect( Object.keys( handlers ).sort() ).toEqual( [ SEND, TICK ] );

		unmount();

		expect( off ).toHaveBeenCalledWith( SEND );
		expect( off ).toHaveBeenCalledWith( TICK );
		expect( handlers ).toEqual( {} );
	} );

	test( 'renders the initial sentence and does not throw when jQuery is absent', () => {
		setBootstrap( { count: 2, text: TWO, heartbeatKey: KEY } );
		const { container } = render( <ExportsInProgressNotice /> );

		expect( within( container ).getByText( TWO ) ).toBeInTheDocument();
	} );
} );

describe( 'StatusCell «cancel failed» badge', () => {
	const STATUS = { canonical: 'cancelled', canonical_label: 'Отменён', raw_label: null };
	const BADGE = 'Не отменена у перевозчика';

	test( 'shows the warn badge under the status with its tooltip when cancelFailed is true', () => {
		render( <StatusCell deliveryStatus={ STATUS } cancelFailed /> );

		const badge = screen.getByText( BADGE );
		expect( badge ).toHaveClass( 'woodev-orders-badge', 'woodev-orders-badge--warn' );
		expect( badge ).toHaveAttribute( 'title', 'Заказ отменён в магазине, но перевозчик не отменил заявку — свяжитесь с перевозчиком' );
		expect( screen.getByText( 'Отменён' ) ).toBeInTheDocument();
	} );

	test.each( [ [ 'false', false ], [ 'absent', undefined ] ] )( 'shows no badge when cancelFailed is %s', ( label, cancelFailed ) => {
		render( <StatusCell deliveryStatus={ STATUS } cancelFailed={ cancelFailed } /> );

		expect( screen.queryByText( BADGE ) ).toBeNull();
		expect( screen.getByText( 'Отменён' ) ).toBeInTheDocument();
	} );
} );

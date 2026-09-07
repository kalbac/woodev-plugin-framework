/**
 * Component tests for the shipping orders page App (SP-10 increment 2b rewrite).
 *
 * `./rest` is mocked wholesale — `getProviders`/`fetchOrders` are the only
 * seam between App and the server, so every scenario below drives them
 * directly rather than reaching for a real REST layer.
 *
 * `window.wc.components.TableCard` is a real `@woocommerce/components` global
 * at runtime (Route B, see `../../src/shipping-orders-page/wc-globals.d.ts`),
 * which is not installed in this repo — so it is faked here with the same
 * observable contract (headers/rows/actions/isLoading/emptyMessage) the real
 * component has (verified against `packages/js/components/src/table/index.tsx`
 * in the `woocommerce/woocommerce` monorepo). This tests OUR wiring into that
 * contract, not TableCard's own internals.
 *
 * @see src/shipping-orders-page/app.tsx
 */

import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import App from '../../src/shipping-orders-page/app';
import { fetchOrders, getProviders } from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	getProviders: jest.fn(),
	fetchOrders: jest.fn(),
} ) );

function FakeTableCard( { title, headers, rows, actions, isLoading, emptyMessage, summary } ) {
	return (
		<div>
			<h2>{ title }</h2>
			<div>{ actions }</div>
			{ isLoading ? (
				<p>Загрузка…</p>
			) : 0 === rows.length ? (
				<p>{ emptyMessage }</p>
			) : (
				<table>
					<thead>
						<tr>
							{ headers.map( ( header ) => (
								<th key={ header.key }>{ header.label }</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, rowIndex ) => (
							<tr key={ rowIndex }>
								{ row.map( ( cell, cellIndex ) => (
									<td key={ cellIndex }>{ cell.display }</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ summary && (
				<div>{ summary.map( ( entry ) => `${ entry.label }: ${ entry.value }` ).join( ', ' ) }</div>
			) }
		</div>
	);
}

/**
 * Stands in for `@woocommerce/components`' `FilterPicker`, mirroring the part of
 * its real contract this page depends on: it is URL-DRIVEN. It receives `path`
 * and `query` and owns the query parameter named by `config.param`; it does not
 * call back with a value, it navigates. Asserting against this fake therefore
 * asserts the config we hand WooCommerce, which is the actual seam.
 */
function FakeFilterPicker( { config, path, query } ) {
	return (
		<div
			data-testid="carrier-filter"
			data-param={ config.param }
			data-path={ path }
			data-static-params={ config.staticParams.join( ',' ) }
			data-active={ query[ config.param ] || '' }
		>
			<span>{ config.label }</span>
			<ul>
				{ config.filters.map( ( filter ) => (
					<li key={ filter.value }>{ filter.label }</li>
				) ) }
			</ul>
		</div>
	);
}

/** The URL query the fake `wc.navigation` reports; reset per test. */
let fakeQuery = {};

/** Listeners registered through the fake `addHistoryListener`. */
let historyListeners = [];

/**
 * Simulates what `FilterPicker` really does on a pick — and what the browser's
 * back button does: change the query, then fire the history listeners.
 *
 * @param {Object} query the new URL query.
 */
function navigate( query ) {
	// Wrapped in `act()` because the listeners set React state, exactly as the
	// real history events do in the browser.
	act( () => {
		fakeQuery = query;
		historyListeners.forEach( ( listener ) => listener() );
	} );
}

beforeAll( () => {
	window.wc = {
		components: { TableCard: FakeTableCard, FilterPicker: FakeFilterPicker },
		navigation: {
			getQuery: () => fakeQuery,
			getPath: () => '/woodev-shipping-orders',
			addHistoryListener: ( listener ) => {
				historyListeners.push( listener );

				return () => {
					historyListeners = historyListeners.filter( ( entry ) => entry !== listener );
				};
			},
		},
	};
} );

/**
 * Builds one full REST row, overridable per test.
 *
 * @param {Object} overrides field overrides.
 * @return {Object} a row matching Order_Row_Builder's contract.
 */
function makeRow( overrides = {} ) {
	return {
		id: 42,
		order_number: '42',
		edit_url: 'https://example.test/wp-admin/post.php?post=42&action=edit',
		date_created: new Date().toISOString(),
		status: { slug: 'processing', label: 'Processing' },
		carrier: { id: 'cdek', label: 'СДЭК' },
		customer: {
			name: 'Иван Петров',
			email: 'ivan@example.test',
			phone: '+79991234567',
			user_id: 0,
			user_edit_url: null,
		},
		payment: {
			method_title: 'Картой',
			formatted_total: '2 400 ₽',
			needs_payment: false,
		},
		shipping: {
			method_title: 'СДЭК до ПВЗ',
			formatted_total: '300 ₽',
			destination_kind: 'pickup',
			destination_text: 'ул. Ленина, 1',
		},
		type: 'pickup',
		tracking: { number: '10012345', url: 'https://cdek.ru/track/10012345' },
		delivery_status: {
			canonical: 'in_transit',
			canonical_label: 'В пути',
			raw: 'CDEK_ACCEPTED',
			raw_label: 'Принят курьером',
		},
		...overrides,
	};
}

function resultOf( rows, extra = {} ) {
	return { rows, total: rows.length, total_pages: 1, ...extra };
}

const oneProvider = () => [ { id: 'all', label: 'Все перевозчики', count: 3 } ];
const twoProviders = () => [
	{ id: 'all', label: 'Все перевозчики', count: 5 },
	{ id: 'cdek', label: 'СДЭК', count: 3 },
	{ id: 'yandex', label: 'Яндекс Доставка', count: 2 },
];

beforeEach( () => {
	jest.clearAllMocks();
	fakeQuery = {};
	historyListeners = [];
} );

describe( 'carrier filter', () => {
	test( 'no carrier control when there is one provider (the aggregate only)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.queryByTestId( 'carrier-filter' ) ).not.toBeInTheDocument();
	} );

	test( 'the carrier filter renders its options once there is more than one provider', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'carrier-filter' ) ).toBeInTheDocument() );

		expect( screen.getByText( 'Показать' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Все перевозчики (5)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'СДЭК (3)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Яндекс Доставка (2)' ) ).toBeInTheDocument();
	} );

	/**
	 * The control sits ABOVE `TableCard`, not in its `actions` slot (operator on
	 * the rig, 08.09.2026, against «Аналитика → Заказы»). `actions` is where the
	 * previous implementation put it, so this pins the placement rather than
	 * merely the presence.
	 */
	test( 'the carrier filter is outside the table card, not among its actions', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'carrier-filter' ) ).toBeInTheDocument() );

		const filter = screen.getByTestId( 'carrier-filter' );
		const card = container.querySelector( '.woodev-orders__filters' );

		expect( card ).toContainElement( filter );
		expect( filter.closest( 'table' ) ).toBeNull();
	} );

	/** `staticParams` is empty on purpose: `paged` must not survive a carrier change. */
	test( 'the filter owns the carrier query param and carries nothing across a change', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'carrier-filter' ) ).toBeInTheDocument() );

		const filter = screen.getByTestId( 'carrier-filter' );

		expect( filter ).toHaveAttribute( 'data-param', 'carrier' );
		expect( filter ).toHaveAttribute( 'data-static-params', '' );
		expect( filter ).toHaveAttribute( 'data-path', '/woodev-shipping-orders' );
	} );
} );

describe( 'the carrier lives in the URL, not in component state', () => {
	test( 'a carrier already in the query scopes the very first fetch', async () => {
		fakeQuery = { carrier: 'cdek' };
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { carrier: 'cdek' } ) )
		);
	} );

	/**
	 * `FilterPicker` navigates instead of calling back, so a pick — and the
	 * browser's back button — reach this page only through the history listener.
	 */
	test( 'a history change re-scopes the fetch', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { carrier: 'all' } ) )
		);

		navigate( { carrier: 'cdek' } );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { carrier: 'cdek' } ) )
		);

		navigate( {} );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { carrier: 'all' } ) )
		);
	} );
} );

describe( 'the aggregate is the default view', () => {
	test( 'the very first fetch is scoped to carrier=all, with two providers registered', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { carrier: 'all' } )
			)
		);
	} );

	test( 'still requests carrier=all with a single provider (no filter to choose from)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { carrier: 'all' } )
			)
		);
	} );
} );

describe( 'status cell', () => {
	test( 'an unknown canonical status renders visibly as "Неизвестно", never a guessed state', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [
				makeRow( {
					delivery_status: {
						canonical: 'unknown',
						canonical_label: 'Неизвестно',
						raw: 'SOME_WEIRD_STATUS',
						raw_label: 'SOME_WEIRD_STATUS',
					},
				} ),
			] )
		);

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Неизвестно' ) ).toBeInTheDocument() );
	} );
} );

describe( 'tracking cell', () => {
	test( 'a row with a tracking number renders a link to it', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => {
			const link = screen.getByRole( 'link', { name: '10012345' } );
			expect( link ).toHaveAttribute( 'href', 'https://cdek.ru/track/10012345' );
		} );
	} );

	test( 'a row with no tracking number renders no link at all', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [ makeRow( { tracking: { number: null, url: null } } ) ] )
		);

		render( <App /> );

		// Wait for the row to actually land before asserting its absence.
		await waitFor( () =>
			expect( screen.getByText( /ул\. Ленина, 1/ ) ).toBeInTheDocument()
		);

		// The only link on the page is the order link itself — no tracking link.
		const links = screen.getAllByRole( 'link' );
		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ] ).toHaveTextContent( 'Заказ 42' );
	} );
} );

describe( 'empty and error states', () => {
	test( 'zero rows renders the empty-state notice', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [] ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getAllByText( 'Заказов доставки пока нет.' ).length ).toBeGreaterThan( 0 )
		);
	} );

	test( 'a rejected fetch renders an error notice, not an endless spinner', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockRejectedValue( new Error( 'Сервер недоступен.' ) );

		render( <App /> );

		// @wordpress/components' Notice also announces itself via an a11y-speak
		// live region, so the same text can legitimately appear twice — assert
		// presence via getAllByText rather than the ambiguous getByText.
		await waitFor( () =>
			expect( screen.getAllByText( 'Сервер недоступен.' ).length ).toBeGreaterThan( 0 )
		);
	} );

	test( 'a rejection with no message falls back to a generic Russian error, not a blank notice', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockRejectedValue( {} );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getAllByText( 'Не удалось загрузить заказы.' ).length ).toBeGreaterThan( 0 )
		);
	} );
} );

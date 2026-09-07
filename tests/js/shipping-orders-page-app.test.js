/**
 * Component tests for the shipping orders page App (SP-10 increment 2b).
 *
 * `./rest` is mocked wholesale — `getProviders`/`fetchOrders` are the only
 * seam between App and the server, so every scenario below drives them
 * directly rather than reaching for a real REST layer.
 *
 * @see src/shipping-orders-page/app.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import App from '../../src/shipping-orders-page/app';
import { fetchOrders, getProviders } from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	getProviders: jest.fn(),
	fetchOrders: jest.fn(),
} ) );

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
} );

describe( 'tabs', () => {
	test( 'no tab strip when there is one provider (the aggregate only)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.queryByText( 'Все перевозчики (3)' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'tab' ) ).not.toBeInTheDocument();
	} );

	test( 'a tab strip renders once there is more than one provider', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'tab', { name: 'Все перевозчики (5)' } ) ).toBeInTheDocument()
		);
		expect( screen.getByRole( 'tab', { name: 'СДЭК (3)' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'tab', { name: 'Яндекс Доставка (2)' } ) ).toBeInTheDocument();
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

	test( 'still requests carrier=all with a single provider (no tabs to choose from)', async () => {
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

		// See the a11y-speak note below: assert via getAllByText for the same reason.
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

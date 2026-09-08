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

function FakeTableCard( { title, headers, rows, actions, isLoading, emptyMessage, summary, onPageChange } ) {
	return (
		<div>
			<h2>{ title }</h2>
			<div>{ actions }</div>
			{ /* Exists only so a test can drive `page` without a real `TableCard`. */ }
			<button onClick={ () => onPageChange( 2 ) }>Следующая страница</button>
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
 *
 * The page now renders TWO of these side by side (#835 — carrier scope and
 * display mode are independent pickers), so the fake's test id is keyed by
 * `config.param` rather than a fixed string, letting a test address either one.
 */
function FakeFilterPicker( { config, path, query } ) {
	return (
		<div
			data-testid={ `filter-picker-${ config.param }` }
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

/**
 * Stands in for `@woocommerce/components`' `DateRangeFilterPicker`. It does
 * NOT navigate on its own (unlike `FilterPicker`) — its `onRangeSelect` is the
 * whole contract, so the fake exposes a button that calls it with a fixed
 * update, letting a test assert exactly what App does with it.
 */
function FakeDateRangeFilterPicker( { dateQuery, isoDateFormat, onRangeSelect } ) {
	return (
		<div data-testid="date-range-filter" data-period={ dateQuery.period } data-iso-format={ isoDateFormat }>
			<button
				onClick={ () =>
					onRangeSelect( { period: 'custom', compare: dateQuery.compare, before: '2026-02-01', after: '2026-01-01' } )
				}
			>
				Изменить период
			</button>
		</div>
	);
}

/**
 * Stands in for `@woocommerce/components`' `AdvancedFilters`. Real WooCommerce
 * writes its own query keys and navigates on apply; this fake only surfaces
 * the CONFIG this page hands it, which is the actual seam under test.
 */
function FakeAdvancedFilters( { config, path, query } ) {
	return (
		<div data-testid="advanced-filters" data-path={ path } data-active={ JSON.stringify( query ) }>
			<ul>
				{ Object.keys( config.filters ).map( ( key ) => (
					<li key={ key }>{ config.filters[ key ].labels.add }</li>
				) ) }
			</ul>
		</div>
	);
}

/** A `moment`-like stub — only `.format()` is ever called on one of these. */
function fakeMoment( isoString ) {
	return { format: () => isoString };
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

/** Every `navigation.updateQueryString()` call — reset per test. */
let updateQueryStringCalls = [];

beforeAll( () => {
	window.wc = {
		components: {
			TableCard: FakeTableCard,
			FilterPicker: FakeFilterPicker,
			// WooCommerce's loading skeletons, which the ROI panel reuses as its frame.
			SummaryListPlaceholder: ( { numberOfItems } ) => (
				<div data-testid="summary-placeholder" data-items={ numberOfItems } />
			),
			ChartPlaceholder: ( { height } ) => (
				<div data-testid="chart-placeholder" data-height={ height } />
			),
			DateRangeFilterPicker: FakeDateRangeFilterPicker,
			AdvancedFilters: FakeAdvancedFilters,
		},
		navigation: {
			getQuery: () => fakeQuery,
			getPath: () => '/woodev-shipping-orders',
			addHistoryListener: ( listener ) => {
				historyListeners.push( listener );

				return () => {
					historyListeners = historyListeners.filter( ( entry ) => entry !== listener );
				};
			},
			// `DateRangeFilterPicker` does not navigate on its own — App is
			// expected to push its `onRangeSelect` update through this, then this
			// fake mirrors what the real function does: change the query and fire
			// history listeners, exactly like `navigate()` below.
			updateQueryString: ( query, path, currentQuery ) => {
				updateQueryStringCalls.push( { query, path, currentQuery } );
				navigate( { ...currentQuery, ...query } );
			},
		},
		date: {
			// The default range is a real query STRING, so parse it as one. Splitting
			// on '=' silently produced 'year&compare' the moment a second parameter
			// was added, and the assertion that caught it only did so because it
			// compared against a literal rather than against the same split.
			getDateParamsFromQuery: ( query, defaultDateRange ) => {
				const defaults = Object.fromEntries(
					new URLSearchParams( defaultDateRange ).entries()
				);

				return {
					period: query.period || defaults.period,
					compare: query.compare || defaults.compare,
					before: query.before ? fakeMoment( query.before ) : null,
					after: query.after ? fakeMoment( query.after ) : null,
				};
			},
			// Deterministic for tests — a real `@woocommerce/date` resolves
			// `period=year` into "since Jan 1st"; this fake just hardcodes that one
			// resolution and otherwise trusts an explicit `after`/`before` already
			// in the query (the "custom" period), rather than doing real date math.
			// It DOES reproduce one piece of real behaviour on purpose: an absent or
			// unknown `compare` throws, exactly as the shipped bundle does.
			getCurrentDates: ( query, defaultDateRange ) => {
				const defaults = Object.fromEntries(
					new URLSearchParams( defaultDateRange ).entries()
				);
				const compare = query.compare || defaults.compare;

				if ( ! [ 'previous_period', 'previous_year' ].includes( compare ) ) {
					throw new Error( `Cannot find compare: ${ compare || '' }` );
				}

				return {
					primary: {
						label: 'Test range',
						range: '',
						before: fakeMoment( query.before || '2026-09-08' ),
						after: fakeMoment( query.after || '2026-01-01' ),
					},
					secondary: { label: 'Test previous range', range: '', before: null, after: null },
				};
			},
			isoDateFormat: 'YYYY-MM-DD',
		},
		// Mirrors the real module shape, measured on the rig: `wc.currency` is an
		// OBJECT that is not callable, and the factory hangs off it. The previous
		// fake made the module itself a function, so the page's `wc.currency()`
		// passed every test and threw `B is not a function` in the browser.
		currency: { CurrencyFactory: () => ( { getCurrencyConfig: () => ( {} ) } ) },
		wcSettings: {
			getSetting: ( name, fallback ) =>
				'orderStatuses' === name
					? { pending: 'В ожидании', completed: 'Выполнен' }
					: fallback,
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
	updateQueryStringCalls = [];
} );

describe( 'carrier filter', () => {
	test( 'no carrier control when there is one provider (the aggregate only)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.queryByTestId( 'filter-picker-carrier' ) ).not.toBeInTheDocument();
	} );

	test( 'the carrier filter renders its options once there is more than one provider', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument() );

		expect( screen.getByText( 'Перевозчик' ) ).toBeInTheDocument();
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

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument() );

		const filter = screen.getByTestId( 'filter-picker-carrier' );
		const card = container.querySelector( '.woodev-orders__filters' );

		expect( card ).toContainElement( filter );
		expect( filter.closest( 'table' ) ).toBeNull();
	} );

	/**
	 * `staticParams` carries every OTHER filter-row query key (increment 7),
	 * including the display-mode picker's own `filter` param (#835) — the date
	 * range, the advanced filters and the display mode all describe "what work
	 * queue view am I in", independent of carrier, so a carrier switch must not
	 * silently drop them. `paged` is still not one of these keys: it is
	 * component state, not a URL param, so it still cannot survive a carrier
	 * change.
	 */
	test( 'the filter owns the carrier query param and carries the rest of the filter row across a change', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument() );

		const filter = screen.getByTestId( 'filter-picker-carrier' );

		expect( filter ).toHaveAttribute( 'data-param', 'carrier' );
		expect( filter ).toHaveAttribute(
			'data-static-params',
			'filter,period,compare,before,after,delivery_status_is,status_is,has_tracking_is'
		);
		expect( filter ).toHaveAttribute( 'data-path', '/woodev-shipping-orders' );
	} );
} );

describe( 'the display-mode filter (#835 — split from carrier scope)', () => {
	/** Unlike the carrier picker, this one is offered even with a single provider. */
	test( 'renders regardless of how many providers there are, with its own label and options', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-filter' ) ).toBeInTheDocument() );

		expect( screen.getByText( 'Фильтры' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Все заказы' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Расширенные фильтры' ) ).toBeInTheDocument();
	} );

	/** …and «Расширенные фильтры» is its LAST option, as in WooCommerce's own Analytics. */
	test( 'offers «Расширенные фильтры» as its last option', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		const picker = await screen.findByTestId( 'filter-picker-filter' );
		const options = picker.textContent;

		expect( options ).toContain( 'Расширенные фильтры' );
		expect( options.trim().endsWith( 'Расширенные фильтры' ) ).toBe( true );
	} );

	/** `staticParams` must carry `carrier` (#835) or a mode switch would drop the carrier scope. */
	test( 'owns the filter query param and carries the carrier param and the rest of the row across a change', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-filter' ) ).toBeInTheDocument() );

		const filter = screen.getByTestId( 'filter-picker-filter' );

		expect( filter ).toHaveAttribute( 'data-param', 'filter' );
		expect( filter ).toHaveAttribute(
			'data-static-params',
			'carrier,period,compare,before,after,delivery_status_is,status_is,has_tracking_is'
		);
	} );

	/** Both pickers render at once, each owning its own param and carrying its own label — the actual seam #835 depends on. */
	test( 'both the carrier and display-mode pickers render together, each with its own param and label', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument() );
		await waitFor( () => expect( screen.getByTestId( 'filter-picker-filter' ) ).toBeInTheDocument() );

		expect( screen.getByTestId( 'filter-picker-carrier' ) ).toHaveAttribute( 'data-param', 'carrier' );
		expect( screen.getByTestId( 'filter-picker-filter' ) ).toHaveAttribute( 'data-param', 'filter' );
		expect( screen.getByText( 'Перевозчик' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Фильтры' ) ).toBeInTheDocument();
	} );

	/**
	 * #835 point 3: an unrecognized carrier must reach the server unchanged —
	 * no client-side coercion to the aggregate, and no confusion with the
	 * (now-separate) `filter=advanced` display mode.
	 */
	test( 'an unrecognized carrier in the URL is sent to the server verbatim, not coerced to the aggregate', async () => {
		fakeQuery = { carrier: 'unknown-carrier' };
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { carrier: 'unknown-carrier' } ) )
		);
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

	/**
	 * #837 defect 5a's regression guard: a rejected query parameter used to
	 * return the error `Notice` INSTEAD OF the whole page — bare text on an
	 * otherwise empty screen, with no filter controls left to fix the thing
	 * that broke. The notice must render ABOVE the filter row and table, both
	 * of which stay mounted, rather than replacing them.
	 */
	test( 'a rejected fetch keeps the filter row and the table mounted alongside the error notice', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockRejectedValue( new Error( 'Неверный параметр фильтра.' ) );

		const { container } = render( <App /> );

		await waitFor( () =>
			expect( screen.getAllByText( 'Неверный параметр фильтра.' ).length ).toBeGreaterThan( 0 )
		);

		expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'filter-picker-filter' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Заказы доставки' ) ).toBeInTheDocument();
		expect( container.querySelector( '.woodev-orders__filters' ) ).toBeInTheDocument();
	} );
} );

describe( 'the delivery-analytics panel (#711)', () => {
	/**
	 * Operator, 08.09.2026: ship the frame now behind a «Скоро» overlay, BELOW
	 * the table — Analytics puts its chart above, and this one deliberately does
	 * not. Which metric it plots is still open; that it is announced is not.
	 */
	test( 'the panel renders below the table with a «Скоро» overlay', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		const panel = container.querySelector( '.woodev-orders-roi' );
		const table = container.querySelector( 'table' );

		expect( panel ).toBeInTheDocument();
		expect( screen.getByText( 'Скоро' ) ).toBeInTheDocument();
		expect(
			table.compareDocumentPosition( panel ) & Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	/** The frame is WooCommerce's own skeletons, not a drawn fake chart. */
	test( 'the frame is built from the WooCommerce placeholders, and is decorative', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.getByTestId( 'summary-placeholder' ) ).toHaveAttribute( 'data-items', '4' );
		// ChartPlaceholder's own default height is 0, so a height must be passed.
		expect( screen.getByTestId( 'chart-placeholder' ) ).toHaveAttribute( 'data-height', '260' );
		expect( container.querySelector( '.woodev-orders-roi__frame' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
	} );

	/** An older WooCommerce without the placeholders must not crash the page. */
	test( 'the panel is skipped when the placeholders are absent', async () => {
		const components = window.wc.components;
		window.wc.components = { TableCard: FakeTableCard, FilterPicker: FakeFilterPicker };

		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( container.querySelector( '.woodev-orders-roi' ) ).not.toBeInTheDocument();
		expect( container.querySelector( 'table' ) ).toBeInTheDocument();

		window.wc.components = components;
	} );
} );

describe( 'the date range filter (SP-10 #826, increment 7)', () => {
	test( 'with no period in the URL, it defaults to period=year and resolves it into after/before for the REST call', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-09-08' } )
			)
		);

		expect( screen.getByTestId( 'date-range-filter' ) ).toHaveAttribute( 'data-period', 'year' );
		expect( screen.getByTestId( 'date-range-filter' ) ).toHaveAttribute( 'data-iso-format', 'YYYY-MM-DD' );
	} );

	test( 'a custom range already in the URL scopes the very first fetch', async () => {
		fakeQuery = { period: 'custom', after: '2026-02-01', before: '2026-03-01' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { after: '2026-02-01', before: '2026-03-01' } )
			)
		);
	} );

	/**
	 * `DateRangeFilterPicker` does not navigate on its own (unlike
	 * `FilterPicker`) — App is expected to push its `onRangeSelect` update
	 * through `wc.navigation.updateQueryString()` itself.
	 */
	test( 'picking a new range pushes it through updateQueryString and re-scopes the fetch', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-09-08' } )
			)
		);

		act( () => {
			screen.getByText( 'Изменить период' ).click();
		} );

		expect( updateQueryStringCalls ).toHaveLength( 1 );
		expect( updateQueryStringCalls[ 0 ].path ).toBe( '/woodev-shipping-orders' );
		expect( updateQueryStringCalls[ 0 ].query ).toEqual(
			expect.objectContaining( { period: 'custom', before: '2026-02-01', after: '2026-01-01' } )
		);

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-02-01' } )
			)
		);
	} );

	test( 'changing the date range resets the page to 1', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { page: 1 } ) )
		);

		act( () => {
			screen.getByText( 'Следующая страница' ).click();
		} );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { page: 2 } ) )
		);

		act( () => {
			screen.getByText( 'Изменить период' ).click();
		} );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { page: 1 } ) )
		);
	} );
} );

describe( 'AdvancedFilters (SP-10 #827, increment 7)', () => {
	/**
	 * The operator caught this on his own rig pass, 08.09.2026: the advanced
	 * block must NOT be a permanently visible region. WooCommerce's own
	 * «Аналитика → Заказы» reveals it from the last option of the same «Show»
	 * picker — measured there before this was built. Here it is revealed by
	 * the display-mode picker's own `filter` param (#835), split from carrier.
	 */
	test( 'stays hidden until the display-mode picker\'s advanced option is chosen', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-filter' ) ).toBeInTheDocument() );
		expect( screen.queryByTestId( 'advanced-filters' ) ).not.toBeInTheDocument();

		navigate( { filter: 'advanced' } );

		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );
	} );

	test( 'offers delivery status, WC order status and tracking presence — never delivery type', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );
		// The block is revealed by the display-mode picker's own last option
		// (#835 — its own `filter` param, split from `carrier`), so the query
		// has to say so before it renders at all.
		navigate( { filter: 'advanced' } );

		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );

		const list = screen.getByTestId( 'advanced-filters' );
		expect( list ).toHaveTextContent( 'Статус доставки' );
		expect( list ).toHaveTextContent( 'Трек-номер' );
		expect( list ).toHaveTextContent( 'Статус заказа' );
		expect( list ).not.toHaveTextContent( 'Тип доставки' );
	} );

	/** D10: order-status options have no framework-owned source — `wcSettings` is a WooCommerce Core admin setting this page only reads defensively. */
	test( 'omits the order-status filter, but keeps the other two, when wcSettings is unavailable', async () => {
		const wcSettings = window.wc.wcSettings;
		delete window.wc.wcSettings;

		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );
		navigate( { filter: 'advanced' } );

		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );

		const list = screen.getByTestId( 'advanced-filters' );
		expect( list ).toHaveTextContent( 'Статус доставки' );
		expect( list ).toHaveTextContent( 'Трек-номер' );
		expect( list ).not.toHaveTextContent( 'Статус заказа' );

		window.wc.wcSettings = wcSettings;
	} );

	/** `AdvancedFilters` is required by its own contract to carry a `currency` instance; no `wc-currency` means the control degrades like the others. */
	test( 'the whole control is skipped, without crashing, when wc-currency is unavailable', async () => {
		const currency = window.wc.currency;
		delete window.wc.currency;

		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.queryByTestId( 'advanced-filters' ) ).not.toBeInTheDocument();
		expect( container.querySelector( 'table' ) ).toBeInTheDocument();

		window.wc.currency = currency;
	} );

	test( 'a delivery-status filter already in the URL scopes the very first fetch', async () => {
		fakeQuery = { delivery_status_is: 'in_transit' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { deliveryStatus: 'in_transit' } )
			)
		);
	} );

	/** `status_is` carries one WC order-status slug; `fetchOrders()` still takes the REST route's own array shape. */
	test( 'an order-status filter already in the URL is sent as a one-element array', async () => {
		fakeQuery = { status_is: 'processing' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { status: [ 'processing' ] } ) )
		);
	} );

	describe( 'has_tracking_is', () => {
		test( '"yes" translates to hasTracking: true', async () => {
			fakeQuery = { has_tracking_is: 'yes' };
			getProviders.mockReturnValue( oneProvider() );
			fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

			render( <App /> );

			await waitFor( () =>
				expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { hasTracking: true } ) )
			);
		} );

		test( '"no" translates to hasTracking: false — not falsy-and-therefore-absent', async () => {
			fakeQuery = { has_tracking_is: 'no' };
			getProviders.mockReturnValue( oneProvider() );
			fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

			render( <App /> );

			await waitFor( () =>
				expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { hasTracking: false } ) )
			);
		} );

		test( 'absent from the URL stays undefined — the REST route reads presence, not truthiness', async () => {
			getProviders.mockReturnValue( oneProvider() );
			fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

			render( <App /> );

			await waitFor( () =>
				expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { hasTracking: undefined } ) )
			);
		} );
	} );
} );

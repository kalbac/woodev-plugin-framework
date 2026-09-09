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
import { act, render, screen, waitFor, within } from '@testing-library/react';
import App from '../../src/shipping-orders-page/app';
import { fetchOrders, fetchSyncStatus, getProviders } from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	getProviders: jest.fn(),
	fetchOrders: jest.fn(),
	fetchSyncStatus: jest.fn(),
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
function FakeFilterPicker( { config, path, query, advancedFilters } ) {
	// ⚠ Reproduces the ONE branch of the real `update()` that crashes, rather than
	// merely recording a prop. WooCommerce hard-codes the param name `filter`
	// (`packages/js/components/src/filter-picker/index.js:174`) and dereferences
	// `advancedFilters.filters` when the value moves AWAY from `advanced` — and
	// `advancedFilters` has no defaultProp. Omit it and leaving advanced mode throws,
	// the URL never changes, and the merchant is stuck there. Asserting a prop is
	// present would pass for a prop that is present and wrong; running the branch does not.
	const leaveAdvancedMode = () => {
		if ( 'filter' === config.param ) {
			// Throws exactly as upstream does when `advancedFilters` is missing.
			return Object.keys( advancedFilters.filters || {} ).length;
		}
		return 0;
	};

	return (
		<div
			data-testid={ `filter-picker-${ config.param }` }
			data-param={ config.param }
			data-path={ path }
			data-static-params={ config.staticParams.join( ',' ) }
			data-active={ query[ config.param ] || '' }
			data-leaving-advanced-mode={ String( leaveAdvancedMode() ) }
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
 * Simulates what `FilterPicker` really does on a pick, and what the browser's back button
 * does.
 *
 * ⚠ THE ORDER HERE IS THE WHOLE POINT, and this fake had it BACKWARDS until s128.
 * `addHistoryListener` monkey-patches `window.history.pushState`
 * (`packages/js/navigation/src/index.js:134`) and dispatches its `pushstate` event
 * BEFORE delegating to the real `pushState`:
 *
 *     history.pushState = function ( state ) {
 *         window.dispatchEvent( pushStateEvent );      // listeners run HERE
 *         return pushState.apply( history, arguments ); // URL changes AFTER
 *     };
 *
 * So a listener that calls `getQuery()` synchronously reads the PREVIOUS URL, and a page
 * built that way is permanently one navigation behind. The old fake updated `fakeQuery`
 * first, which made that bug impossible to express — 1831 green tests, and the live page
 * did not filter at all.
 *
 * WooCommerce's own `useQuery()` hook (same file, :216) is the shape that survives it: the
 * listener only raises a flag, and the query is read in a LATER effect.
 *
 * @param {Object} query the new URL query.
 */
function navigate( query ) {
	// Wrapped in `act()` because the listeners set React state, exactly as the
	// real history events do in the browser.
	act( () => {
		// Listeners first, still seeing the OLD query — as in the browser.
		historyListeners.forEach( ( listener ) => listener() );
		fakeQuery = query;
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

				// ⚠ `undefined` REMOVES a key — that is how `@wordpress/url`'s
				// `addQueryArgs()` behaves, and it is the mechanism the advanced-filter
				// reset depends on. A plain spread would keep the key with an undefined
				// value, so a test asserting "the filters were cleared" would pass for
				// the wrong reason. Same class of fiction as the event ordering above.
				const merged = { ...currentQuery, ...query };
				Object.keys( merged ).forEach( ( key ) => {
					if ( undefined === merged[ key ] ) {
						delete merged[ key ];
					}
				} );

				navigate( merged );
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
	// Every existing test in this file renders `App` without caring about the
	// data-status panel — default it to "no carriers registered", which is the
	// one case the panel is required to render as nothing at all (#828).
	fetchSyncStatus.mockResolvedValue( { last_updated: null, carriers: [] } );
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
	 * including the display-mode toggle's own `filter` param (#835) — the date
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
			'filter,period,compare,before,after,delivery_status_is,delivery_status_is_not,status_is,status_is_not,has_tracking_is,has_pickup_point_is'
		);
		expect( filter ).toHaveAttribute( 'data-path', '/woodev-shipping-orders' );
	} );
} );

describe( 'the display mode is a TOGGLE, not a picker (#835, operator 09.09.2026)', () => {
	/**
	 * The operator's reasoning, on the rig: while «Показать» held ONE axis — a specific
	 * carrier or a pointwise filter across all of them — a list was the honest control.
	 * Splitting the carrier onto its own picker left this one with exactly two states,
	 * and a two-state list is a wasted click plus a false promise of a third option.
	 */
	test( 'renders as a toggle regardless of provider count, and no mode picker survives', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeInTheDocument()
		);

		expect( screen.queryByTestId( 'filter-picker-filter' ) ).toBeNull();
		expect( screen.queryByText( 'Фильтры' ) ).toBeNull();
		expect( screen.queryByText( 'Все заказы' ) ).toBeNull();
	} );

	test( 'reflects the URL rather than its own state', async () => {
		fakeQuery = { filter: 'advanced' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeChecked()
		);
	} );

	test( 'switching it on writes filter=advanced and touches nothing else', async () => {
		fakeQuery = { carrier: 'cdek' };
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		const toggle = await screen.findByRole( 'checkbox', { name: 'Расширенные фильтры' } );

		act( () => {
			toggle.click();
		} );

		expect( updateQueryStringCalls.at( -1 ).query ).toEqual( { filter: 'advanced' } );
		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );
	} );

	/**
	 * ⚠ The half that used to come free and no longer does. `FilterPicker.update()`
	 * special-cases `param === 'filter'` and clears the active filters on the way out
	 * (`filter-picker/index.js:174`). With the picker gone that branch never runs, so
	 * leaving advanced mode would strand `*_is` in the URL — invisible, still filtering
	 * a table whose filter block is hidden.
	 */
	test( 'switching it off clears the advanced filters, not just the mode', async () => {
		fakeQuery = {
			carrier: 'cdek',
			filter: 'advanced',
			delivery_status_is: 'in_transit',
			status_is: 'wc-processing',
			has_tracking_is: 'yes',
		};
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		const toggle = await screen.findByRole( 'checkbox', { name: 'Расширенные фильтры' } );

		act( () => {
			toggle.click();
		} );

		const sent = updateQueryStringCalls.at( -1 ).query;

		expect( sent.filter ).toBeUndefined();
		expect( sent.delivery_status_is ).toBeUndefined();
		expect( sent.status_is ).toBeUndefined();
		expect( sent.has_tracking_is ).toBeUndefined();

		// And it must reach the FETCH, not merely the URL — the carrier scope survives.
		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( { carrier: 'cdek', deliveryStatus: '', status: [] } )
			)
		);
	} );

	/**
	 * ⚠ Placement, not presence. The operator moved this toggle out from BETWEEN the two
	 * pickers onto its own row beneath them (09.09.2026): every picker carries a label above
	 * its control and they align on their bottom edge, so a label-less toggle among them
	 * reads as something that fell out of alignment. Nothing else in this file can see where
	 * the control sits, so folding it back into the row would go unnoticed.
	 */
	test( 'the toggle sits on its own row, NOT inside the pickers row', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		const { container } = render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeInTheDocument()
		);

		const row = container.querySelector( '.woodev-orders__basic-filters' );
		const toggle = container.querySelector( '.woodev-orders__mode-toggle' );

		expect( row ).not.toBeNull();
		expect( toggle ).not.toBeNull();
		expect( row.contains( toggle ) ).toBe( false );

		// …and it comes AFTER the row, not before it.
		expect( row.compareDocumentPosition( toggle ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
	} );

	test( 'the carrier picker still renders beside it, owning its own param', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument() );

		expect( screen.getByTestId( 'filter-picker-carrier' ) ).toHaveAttribute( 'data-param', 'carrier' );
		expect( screen.getByText( 'Перевозчик' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeInTheDocument();
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

	/**
	 * #835's own warning: `filter` (display mode) must NOT enter the
	 * `UrlFilters`/`filtersEqual` snapshot `readUrlFilters()` builds, or
	 * switching modes would reset pagination the same way a real filter
	 * change does — a change of VIEW is not a change of selection. Confirmed
	 * here rather than assumed, exactly as the brief for this asked.
	 */
	test( 'switching display mode does not reset the page — it is a view change, not a filter change', async () => {
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

		navigate( { filter: 'advanced' } );

		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );

		expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { page: 2 } ) );
	} );

	/**
	 * ⚠ The defect this whole file's `navigate()` helper was blind to, and the one the rig
	 * found on 09.09.2026: pressing «Filter» wrote `delivery_status_is` into the address bar
	 * and the table went on showing every order.
	 *
	 * `addHistoryListener` fires its event BEFORE the real `pushState`, so a listener that
	 * reads `getQuery()` synchronously sees the PREVIOUS URL and the page is permanently one
	 * navigation behind. `navigate()` now reproduces that ordering, which is what lets this
	 * test fail against the one-step listener.
	 *
	 * It asserts the FETCH, not the URL — the URL was never the broken part.
	 */
	test( 'a filter arriving by history push reaches the fetch, not the previous query', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { deliveryStatus: '' } )
			)
		);

		navigate( { filter: 'advanced', delivery_status_is: 'in_transit' } );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( { deliveryStatus: 'in_transit' } )
			)
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
		expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Заказы доставки' ) ).toBeInTheDocument();
		expect( container.querySelector( '.woodev-orders__filters' ) ).toBeInTheDocument();
	} );

	/**
	 * Follow-up to the fix above: `rows` was reset to `null` at the top of the
	 * fetch effect and the `.catch()` branch never touched it again, so
	 * `TableCard` kept rendering `isLoading={ true }` forever — a permanent
	 * loading skeleton sitting under the error notice, "pretending to load".
	 * Asserted on the RENDERED loading text, not the internal `rows` state.
	 */
	test( 'a rejected fetch settles the table out of its loading state, not a permanent spinner', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockRejectedValue( new Error( 'Сервер недоступен.' ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getAllByText( 'Сервер недоступен.' ).length ).toBeGreaterThan( 0 )
		);

		expect( screen.queryByText( 'Загрузка…' ) ).not.toBeInTheDocument();
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
	 * the display-mode toggle's own `filter` param (#835), split from carrier.
	 */
	test( 'stays hidden until the display-mode picker\'s advanced option is chosen', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'checkbox', { name: 'Расширенные фильтры' } ) ).toBeInTheDocument()
		);
		expect( screen.queryByTestId( 'advanced-filters' ) ).not.toBeInTheDocument();

		navigate( { filter: 'advanced' } );

		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );
	} );

	test( 'offers delivery status, WC order status and tracking presence — never delivery type', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );
		// The block is revealed by the display-mode TOGGLE (operator, 09.09.2026)
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

describe( 'the data-status panel (#828 increment 8)', () => {
	test( 'no carriers registered at all — the panel renders nothing', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockResolvedValue( { last_updated: null, carriers: [] } );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchSyncStatus ).toHaveBeenCalled() );

		expect( container.querySelector( '.woodev-orders-sync' ) ).not.toBeInTheDocument();
	} );

	test( 'a fetch rejection degrades quietly — no panel, no error notice, the rest of the page is untouched', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockRejectedValue( new Error( 'Сервер недоступен.' ) );

		const { container } = render( <App /> );

		await waitFor( () => expect( fetchSyncStatus ).toHaveBeenCalled() );
		// Let the rejected promise settle before asserting its absence.
		await waitFor( () => expect( screen.getByText( 'Заказы доставки' ) ).toBeInTheDocument() );

		expect( container.querySelector( '.woodev-orders-sync' ) ).not.toBeInTheDocument();
		// Scoped to the render container, not `screen` (= document.body) — a
		// `@wordpress/a11y` speak region from an EARLIER test in this file lives
		// outside the container and can carry this exact string as leftover
		// pollution, which is not what this assertion means to catch.
		expect( within( container ).queryByText( 'Сервер недоступен.' ) ).not.toBeInTheDocument();
	} );

	test( 'every carrier has synced — the aggregate reads "Обновлено …", not the honest fallback', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockResolvedValue( {
			last_updated: Math.floor( ( Date.now() - 10 * 60 * 1000 ) / 1000 ),
			carriers: [
				{
					id: 'test_shipping',
					label: 'Тестовая доставка',
					last_updated: Math.floor( ( Date.now() - 10 * 60 * 1000 ) / 1000 ),
					next_update: Math.floor( ( Date.now() + 50 * 60 * 1000 ) / 1000 ),
				},
			],
		} );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Статус данных' ) ).toBeInTheDocument() );

		expect( screen.getByText( /^Обновлено /, { selector: '.woodev-orders-sync__aggregate' } ) ).toBeInTheDocument();
		expect(
			screen.queryByText( /синхронизировались хотя бы раз/ )
		).not.toBeInTheDocument();

		// The per-carrier row: label, "Обновлено …" and "Обновится …" all rendered.
		expect( screen.getByText( 'Тестовая доставка' ) ).toBeInTheDocument();
		const carrierRow = screen.getByText( 'Тестовая доставка' ).closest( 'li' );
		expect( carrierRow ).toHaveTextContent( /^Тестовая доставкаОбновлено .+Обновится /s );
	} );

	/**
	 * The aggregate `null` case (#828's whole reason for existing): the server
	 * sends `last_updated: null` the moment ANY registered carrier has never
	 * synced. The panel must say so honestly, not print a blank "Обновлено",
	 * and the breakdown beneath it must show WHICH carrier is why.
	 */
	test( 'one carrier never synced — aggregate null renders the honest sentence, and the breakdown shows why', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockResolvedValue( {
			last_updated: null,
			carriers: [
				{
					id: 'cdek',
					label: 'СДЭК',
					last_updated: Math.floor( ( Date.now() - 10 * 60 * 1000 ) / 1000 ),
					next_update: Math.floor( ( Date.now() + 50 * 60 * 1000 ) / 1000 ),
				},
				{ id: 'yandex', label: 'Яндекс Доставка', last_updated: null, next_update: null },
			],
		} );

		render( <App /> );

		await waitFor( () =>
			expect(
				screen.getByText( /Не все перевозчики синхронизировались хотя бы раз/ )
			).toBeInTheDocument()
		);

		// Never rendered as a blank "Обновлено" with nothing after it.
		expect(
			screen.queryByText( /^Обновлено\s*$/, { selector: '.woodev-orders-sync__aggregate' } )
		).not.toBeInTheDocument();

		// The breakdown shows exactly which carrier is why: СДЭК has synced,
		// Яндекс never has.
		const yandexRow = screen.getByText( 'Яндекс Доставка' ).closest( 'li' );
		expect( yandexRow ).toHaveTextContent( 'Ни разу не синхронизировалось' );

		const cdekRow = screen.getByText( 'СДЭК' ).closest( 'li' );
		expect( cdekRow ).toHaveTextContent( /^СДЭКОбновлено /s );
	} );

	/**
	 * A webhook-only carrier has no cron, so `next_update` is `null` — normal,
	 * not an error, and it must render as a stated fact, never a blank cell.
	 */
	test( 'a webhook-only carrier (next_update: null) renders "По расписанию не обновляется", not a blank', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockResolvedValue( {
			last_updated: null,
			carriers: [
				{
					id: 'realistic',
					label: 'Реалистичная доставка',
					last_updated: null,
					next_update: null,
				},
			],
		} );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Реалистичная доставка' ) ).toBeInTheDocument()
		);

		const row = screen.getByText( 'Реалистичная доставка' ).closest( 'li' );
		expect( row ).toHaveTextContent( 'По расписанию не обновляется' );
		expect( row ).not.toHaveTextContent( 'Обновится' );
	} );

	/** The brief's own placement requirement: third block, beside the other two. */
	test( 'renders as the third block in the basic-filters row, beside the carrier and date pickers', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );
		fetchSyncStatus.mockResolvedValue( {
			last_updated: Math.floor( Date.now() / 1000 ),
			carriers: [
				{ id: 'cdek', label: 'СДЭК', last_updated: Math.floor( Date.now() / 1000 ), next_update: null },
			],
		} );

		const { container } = render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Статус данных' ) ).toBeInTheDocument() );

		const row = container.querySelector( '.woodev-orders__basic-filters' );
		const panel = container.querySelector( '.woodev-orders-sync' );
		const carrierFilter = screen.getByTestId( 'filter-picker-carrier' );

		expect( row ).toContainElement( panel );
		expect( row ).toContainElement( carrierFilter );
		expect(
			carrierFilter.compareDocumentPosition( panel ) & Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );
} );

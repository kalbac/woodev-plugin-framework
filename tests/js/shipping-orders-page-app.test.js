/**
 * Component tests for the shipping orders page App (SP-10 increment 2b rewrite).
 *
 * `./rest` is mocked wholesale — `getProviders`/`fetchOrders` are the main
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
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import App from '../../src/shipping-orders-page/app';
import {
	fetchOrders,
	fetchSyncStatus,
	getProviders,
	performOrderAction,
} from '../../src/shipping-orders-page/rest';

jest.mock( '../../src/shipping-orders-page/rest', () => ( {
	getProviders: jest.fn(),
	fetchOrders: jest.fn(),
	fetchSyncStatus: jest.fn(),
	// #824 — one row action's REST call. Encodes a claim about the server contract
	// (§2 of the brief): resolve with `{ row, message }`, reject with an object
	// carrying `message`, exactly as `apiFetch` itself resolves/rejects.
	performOrderAction: jest.fn(),
	// #837 defect 4: the reachable-status list. Defaults to [] — the same
	// «bootstrap did not say» answer the real accessor gives, which makes the
	// filter offer every canonical state, so these tests keep asserting what
	// they asserted before the list existed.
	getReachableDeliveryStatuses: jest.fn( () => [] ),
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
 * Stands in for `@woocommerce/components`' `DateRange` — the CALENDAR the page's own
 * «Период» control (#855) opens behind «Произвольный период». The control itself is
 * ours and renders for real in these tests; only this piece is WooCommerce's.
 *
 * Its `onUpdate` patch shapes are read off the shipped bundle, not invented: the real
 * component emits `{ after, before, afterText, beforeText, afterError, beforeError }`
 * on a day click and one side of that at a time on a typed input.
 */
function FakeDateRange( { onUpdate } ) {
	const moment = ( iso, short ) => ( {
		format: ( format ) => ( 'YYYY-MM-DD' === format ? iso : short ),
	} );

	return (
		<div data-testid="date-range">
			<button
				onClick={ () =>
					onUpdate( {
						after: moment( '2026-01-01', '01.01.2026' ),
						before: moment( '2026-02-01', '01.02.2026' ),
						afterText: '01.01.2026',
						beforeText: '01.02.2026',
					} )
				}
			>
				Выбрать обе даты
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

/**
 * Stands in for `@woocommerce/components`' `Link` (#841 — the «Все / Новые» scope
 * links). The real one renders a genuine `<a href>` AND intercepts the click to push
 * onto wc-admin's own history, so this fake does BOTH: the href reaches the DOM, and
 * clicking it navigates.
 *
 * ⚠ The navigation is derived FROM THE HREF, not from a query the fake rebuilds. A
 * fake that recomputed the target could disagree with `getNewPath()` and then a
 * passing test would only prove the fake and the page agree — the same class of
 * fiction as a test double that gets someone else's runtime wrong. Parsing the href
 * means the assertion "the link points at scope=new" and the behaviour "clicking it
 * lands in «Новые»" are backed by the same string.
 */
function FakeLink( { href, type, className, children, ...rest } ) {
	return (
		<a
			href={ href }
			data-link-type={ type }
			className={ className }
			{ ...rest }
			onClick={ ( event ) => {
				event.preventDefault();

				const search = href.includes( '?' ) ? href.slice( href.indexOf( '?' ) + 1 ) : '';

				navigate( Object.fromEntries( new URLSearchParams( search ).entries() ) );
			} }
		>
			{ children }
		</a>
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
			DateRange: FakeDateRange,
			AdvancedFilters: FakeAdvancedFilters,
			Link: FakeLink,
		},
		navigation: {
			getQuery: () => fakeQuery,
			getPath: () => '/woodev-shipping-orders',
			/**
			 * `getNewPath(query, path, currentQuery)` — "Return a URL with set query
			 * parameters […] merging query params into existing params"
			 * (`packages/js/navigation/README.md`).
			 *
			 * ⚠ It drops keys whose value is `undefined`, because that is what
			 * `@wordpress/url`'s `addQueryArgs()` does and it is the mechanism «Все»
			 * uses to CLEAR the scope rather than write `scope=all`. A fake that kept
			 * the key with an undefined value would let a broken «Все» link pass.
			 */
			getNewPath: ( query, path, currentQuery ) => {
				const merged = { ...currentQuery, ...query };
				Object.keys( merged ).forEach( ( key ) => {
					if ( undefined === merged[ key ] ) {
						delete merged[ key ];
					}
				} );

				return `${ path }?${ new URLSearchParams( merged ).toString() }`;
			},
			addHistoryListener: ( listener ) => {
				historyListeners.push( listener );

				return () => {
					historyListeners = historyListeners.filter( ( entry ) => entry !== listener );
				};
			},
			// The «Период» control does not navigate on its own — App is expected to
			// push its query patch through this, then this fake mirrors what the real
			// function does: change the query and fire history listeners, exactly
			// like `navigate()` below.
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

/**
 * ⚠ No `count` on a bootstrap provider since #855. The inlined list says WHICH carriers
 * exist — that is a fact about the site the page needs before its first fetch — and the
 * numbers come back with the rows, counted under the same filters. A fixture that still
 * carried a count would let a picker reading the old field pass.
 */
const oneProvider = () => [ { id: 'all', label: 'Все перевозчики' } ];
const twoProviders = () => [
	{ id: 'all', label: 'Все перевозчики' },
	{ id: 'cdek', label: 'СДЭК' },
	{ id: 'yandex', label: 'Яндекс Доставка' },
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
		fetchOrders.mockResolvedValue(
			resultOf( [ makeRow() ], { carrier_counts: { all: 5, cdek: 3, yandex: 2 } } )
		);

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Все перевозчики (5)' ) ).toBeInTheDocument() );

		expect( screen.getByText( 'Перевозчик' ) ).toBeInTheDocument();
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
	 * including the display-mode toggle's own `filter` param (#835) and the
	 * «Все / Новые» scope's `scope` (#841) — the date range, the advanced
	 * filters, the display mode and the scope all describe "what work queue view
	 * am I in", independent of carrier, so a carrier switch must not silently
	 * drop them. `paged` is still not one of these keys: it is component state,
	 * not a URL param, so it still cannot survive a carrier change.
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
			'filter,scope,period,compare,before,after,delivery_status_is,delivery_status_is_not,status_is,status_is_not,has_tracking_is,has_pickup_point_is'
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

	/**
	 * ⚠ #850, the operator on the rig 11.09.2026: switching «Расширенные фильтры» ON sent a
	 * request byte-identical to the one already answered — same carrier, same page, same
	 * dates — and `setRows( null )` dropped the table into its loading skeleton while it
	 * flew. Nothing had been selected yet; `filter=advanced` is not even a field the fetch
	 * reads.
	 *
	 * ⚠ THIS ASSERTS THE CALL COUNT, and that is the point. The test right above already
	 * navigated with exactly `{ filter: 'advanced' }` and stayed green through the whole
	 * defect, because `toHaveBeenLastCalledWith` cannot see a REPEAT of the same call — the
	 * last call matched either way. A redundant fetch is only visible by counting.
	 *
	 * The control is in the same file rather than in this test: «a history change re-scopes
	 * the fetch» proves a navigation that DOES change a filter still refetches, so a fix
	 * that simply stopped reacting to history would fail there instead.
	 */
	test( 'a history change that alters no filter does not refetch — the count stays put', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		const callsBefore = fetchOrders.mock.calls.length;

		// The toggle's own navigation: it adds `filter=advanced` and touches nothing else.
		navigate( { filter: 'advanced' } );

		// The advanced block appearing is the proof the navigation was actually processed —
		// without it a passing count would only mean the page ignored the event entirely.
		await waitFor( () => expect( screen.getByTestId( 'advanced-filters' ) ).toBeInTheDocument() );

		expect( fetchOrders.mock.calls.length ).toBe( callsBefore );

		// And back off again — the same navigation in reverse, equally free of any selection.
		navigate( {} );

		await waitFor( () =>
			expect( screen.queryByTestId( 'advanced-filters' ) ).not.toBeInTheDocument()
		);

		expect( fetchOrders.mock.calls.length ).toBe( callsBefore );
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

	test( 'renders one WooCommerce-toned badge carrying the canonical label, with no coloured dot and no second raw-label line (#829)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [
				makeRow( {
					delivery_status: {
						canonical: 'delivered',
						canonical_label: 'Доставлено',
						raw: 'DELIVERED',
						raw_label: 'Доставлен',
					},
				} ),
			] )
		);

		render( <App /> );

		const badge = await screen.findByText( 'Доставлено' );
		expect( badge ).toHaveClass( 'woodev-orders-status', 'woodev-orders-status--ok' );
		expect( badge.querySelector( '.woodev-orders-status__dot' ) ).toBeNull();
		// The raw carrier word used to duplicate onto a visible second line —
		// it must not render anywhere in the cell any more.
		expect( screen.queryByText( 'Доставлен' ) ).not.toBeInTheDocument();
	} );

	test( 'the carrier word surfaces as a tooltip on the badge when raw_label carries something the canonical status lost', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [
				makeRow( {
					delivery_status: {
						canonical: 'unknown',
						canonical_label: 'Неизвестно',
						raw: 'CUSTOMS_HOLD',
						raw_label: 'Задержан на таможне',
					},
				} ),
			] )
		);

		render( <App /> );

		const badge = await screen.findByText( 'Неизвестно' );
		// `Tooltip` makes its anchor keyboard-focusable (tabIndex 0) — that is
		// real rendered DOM state, not a prop we are trusting blindly.
		expect( badge ).toHaveAttribute( 'tabindex', '0' );

		// Force keyboard modality (Ariakit's own focus-visible heuristic —
		// see node_modules/@ariakit/react-components/src/focusable/focusable.tsx)
		// so the focus below is treated the same way a real Tab press is.
		fireEvent.keyDown( document, { key: 'Tab' } );
		act( () => {
			badge.focus();
		} );

		await waitFor( () =>
			expect( screen.getByText( 'Задержан на таможне' ) ).toBeInTheDocument()
		);
	} );

	test( 'no tooltip at all when raw_label is empty — never an empty one', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [
				makeRow( {
					delivery_status: {
						canonical: 'delivered',
						canonical_label: 'Доставлено',
						raw: null,
						raw_label: null,
					},
				} ),
			] )
		);

		render( <App /> );

		const badge = await screen.findByText( 'Доставлено' );
		// No Tooltip wrapper was rendered at all — the badge stays a plain,
		// non-focusable span, not a focusable anchor with nothing to show.
		expect( badge ).not.toHaveAttribute( 'tabindex' );
	} );
} );

describe( 'payment cell', () => {
	test( 'the formatted total carries its own no-wrap class so a thousands space cannot break the amount (#865)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue(
			resultOf( [
				makeRow( {
					payment: {
						method_title: 'Картой',
						formatted_total: '3 980,00 ₽',
						needs_payment: false,
					},
				} ),
			] )
		);

		render( <App /> );

		// Asserted on the RENDERED amount, not on the row payload: the defect this
		// pins is that «3 980,00 ₽» broke across two lines, and only the rendered
		// node carries the class that prevents it.
		const amount = await screen.findByText( '3 980,00 ₽' );
		expect( amount ).toHaveClass( 'woodev-orders-cell__meta', 'woodev-orders-amount' );
	} );

	test( 'the shipping line keeps the shared meta class WITHOUT the no-wrap one — an address must still wrap (#865)', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		const shippingLine = await screen.findByText( /СДЭК до ПВЗ/ );
		expect( shippingLine ).toHaveClass( 'woodev-orders-cell__meta' );
		expect( shippingLine ).not.toHaveClass( 'woodev-orders-amount' );
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

/**
 * The «Период» control (SP-10 #826, increment 7; ours since #855).
 *
 * The control itself renders FOR REAL here — it is this repo's component, not a
 * WooCommerce global — so these tests drive it the way the merchant does, through its own
 * dropdown, and assert what reaches `fetchOrders()` and `updateQueryString()`. Its own
 * entries and labels are pinned in `shipping-orders-page-period-picker.test.js`; what is
 * pinned HERE is the wiring: URL → request, and pick → URL.
 */
describe( 'the period filter (SP-10 #826, increment 7; #855)', () => {
	/**
	 * Opens the period dropdown and clicks one entry.
	 *
	 * `aria-expanded` is the selector because it is the one thing on the page that only
	 * this control has — the table's own buttons carry no expanded state, and the toggle
	 * is a checkbox. `await act( async … )` flushes the popover's asynchronous
	 * positioning, which otherwise sets state outside `act()` and fails the NEXT assertion.
	 *
	 * @param {string} label the entry to pick.
	 */
	async function pickPeriod( label ) {
		await act( async () => {
			screen.getByRole( 'button', { expanded: false } ).click();
		} );

		await act( async () => {
			screen.getByText( label ).click();
		} );
	}

	/**
	 * The heart of #855. The default used to be `period=year`, so the page silently hid
	 * every order older than January — on a WORK QUEUE, where the order stuck since June
	 * is exactly the one being looked for. Empty strings are what `fetchOrders()` omits
	 * from the request entirely (`rest.ts`), so this asserts "no date bound at all", not
	 * "a wide one".
	 */
	test( 'with no period in the URL the request carries no date bound at all', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { after: '', before: '' } ) )
		);

		expect( screen.getByRole( 'button', { expanded: false } ) ).toHaveTextContent( 'Всё время' );
	} );

	test( 'a preset in the URL resolves into after/before for the REST call', async () => {
		fakeQuery = { period: 'year', compare: 'previous_year' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-09-08' } )
			)
		);
	} );

	test( 'a custom range already in the URL scopes the very first fetch', async () => {
		fakeQuery = { period: 'custom', compare: 'previous_year', after: '2026-02-01', before: '2026-03-01' };
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
	 * The control does not navigate on its own (unlike `FilterPicker`) — App is expected
	 * to push its query patch through `wc.navigation.updateQueryString()` itself.
	 */
	test( 'picking a preset pushes it through updateQueryString and re-scopes the fetch', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { after: '', before: '' } ) )
		);

		await pickPeriod( 'С начала года' );

		expect( updateQueryStringCalls ).toHaveLength( 1 );
		expect( updateQueryStringCalls[ 0 ].path ).toBe( '/woodev-shipping-orders' );
		expect( updateQueryStringCalls[ 0 ].query ).toEqual(
			expect.objectContaining( { period: 'year', compare: 'previous_year' } )
		);

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-09-08' } )
			)
		);

		/*
		 * ⚠ The RENDERED label, not the query object. The control reads its period out of
		 * the URL on every render, and the URL only settles a tick after the pick — so a
		 * page that filtered correctly while its own button still said «Всё время» would
		 * pass every assertion above. That is the shape of defect a green suite has
		 * certified here before.
		 */
		expect( screen.getByRole( 'button', { expanded: false } ) ).toHaveTextContent( 'С начала года' );
	} );

	/**
	 * ⚠ Back to «Всё время» must DROP the keys, not write `period=all` — that value throws
	 * inside `@woocommerce/date` and takes the whole wc-admin app down. The fake
	 * `updateQueryString` deletes `undefined` keys exactly as `addQueryArgs()` does, so
	 * the resulting query here is the real one.
	 */
	test( 'picking «Всё время» clears the date keys and the request loses its bounds', async () => {
		fakeQuery = { period: 'year', compare: 'previous_year' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-09-08' } )
			)
		);

		await pickPeriod( 'Всё время' );

		expect( fakeQuery.period ).toBeUndefined();
		expect( fakeQuery.compare ).toBeUndefined();

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { after: '', before: '' } ) )
		);

		expect( screen.getByRole( 'button', { expanded: false } ) ).toHaveTextContent( 'Всё время' );
	} );

	/** «Произвольный период» reaches WooCommerce's calendar and its two days reach the request. */
	test( 'a custom range picked in the calendar reaches the URL and the request', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		await pickPeriod( 'Произвольный период' );

		await act( async () => {
			screen.getByText( 'Выбрать обе даты' ).click();
		} );
		await act( async () => {
			screen.getByText( 'Применить' ).click();
		} );

		expect( updateQueryStringCalls[ 0 ].query ).toEqual(
			expect.objectContaining( {
				period: 'custom',
				compare: 'previous_year',
				after: '2026-01-01',
				before: '2026-02-01',
			} )
		);

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( { after: '2026-01-01', before: '2026-02-01' } )
			)
		);
	} );

	test( 'changing the period resets the page to 1', async () => {
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

		await pickPeriod( 'Прошлый месяц' );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { page: 1 } ) )
		);
	} );

	/**
	 * Degrades rather than crashes when `wc-date` is missing (an older WooCommerce).
	 * Without it there is nothing to resolve a period INTO, so a control that still
	 * rendered would write a URL that filters nothing.
	 */
	test( 'no wc.date at all — the control is skipped and the rest of the page stands', async () => {
		const date = window.wc.date;
		delete window.wc.date;
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		try {
			render( <App /> );

			await waitFor( () =>
				expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { after: '', before: '' } ) )
			);

			expect( screen.queryByText( 'Всё время' ) ).not.toBeInTheDocument();
			expect( screen.getByText( 'Заказы доставки' ) ).toBeInTheDocument();
		} finally {
			window.wc.date = date;
		}
	} );
} );

/**
 * The carrier counts (#855). They used to be inlined into the page bootstrap once per
 * page load, so they described the whole table forever and disagreed with it under every
 * filter the page has. They now arrive with the rows, counted under the same request.
 */
describe( 'the carrier counts (#855)', () => {
	test( 'the counts come from the response, so they follow the period', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders
			.mockResolvedValueOnce( resultOf( [ makeRow() ], { carrier_counts: { all: 71, cdek: 68, yandex: 3 } } ) )
			.mockResolvedValueOnce( resultOf( [ makeRow() ], { carrier_counts: { all: 4, cdek: 3, yandex: 1 } } ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'СДЭК (68)' ) ).toBeInTheDocument() );

		await act( async () => {
			screen.getByRole( 'button', { expanded: false } ).click();
		} );
		await act( async () => {
			screen.getByText( 'С начала недели' ).click();
		} );

		await waitFor( () => expect( screen.getByText( 'СДЭК (3)' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Все перевозчики (4)' ) ).toBeInTheDocument();
	} );

	/**
	 * ⚠ «Ещё не знаем» and «ноль» are different states, and rendering the first as the
	 * second is how a loading page reads as an empty shop. Before any response has landed
	 * the options carry NO number — not «(0)».
	 */
	test( 'before the first response the options carry no number at all, never «(0)»', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockReturnValue( new Promise( () => {} ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'СДЭК' ) ).toBeInTheDocument() );

		expect( screen.queryByText( 'СДЭК (0)' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Все перевозчики' ) ).toBeInTheDocument();
	} );

	/** An older server sends no `carrier_counts` — «not stated» leaves the options unnumbered. */
	test( 'a response without carrier_counts leaves the options unnumbered', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( fetchOrders ).toHaveBeenCalled() );

		expect( screen.getByText( 'СДЭК' ) ).toBeInTheDocument();
		expect( screen.queryByText( /СДЭК \(/ ) ).not.toBeInTheDocument();
	} );

	/**
	 * A failed fetch's counts described a table that is no longer on screen — the same
	 * rule the scope links follow. Dropping them leaves the picker usable and silent
	 * rather than confidently wrong.
	 */
	test( 'a failed fetch drops the counts instead of showing stale ones', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders
			.mockResolvedValueOnce( resultOf( [ makeRow() ], { carrier_counts: { all: 71, cdek: 68, yandex: 3 } } ) )
			.mockRejectedValue( { message: 'Счётчики недоступны.' } );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'СДЭК (68)' ) ).toBeInTheDocument() );

		navigate( { carrier: 'yandex' } );

		// `getAllByText` and a message of this test's OWN, for the reason spelled out on
		// the scope-links twin below: `Notice` also announces itself into a document-level
		// a11y-speak live region that OUTLIVES the test, so a message shared with another
		// test is found there before this render has settled — and the assertion then runs
		// against the previous state. That is not hypothetical; it is what failed here first.
		await waitFor( () =>
			expect( screen.getAllByText( 'Счётчики недоступны.' ).length ).toBeGreaterThan( 0 )
		);

		expect( screen.queryByText( 'СДЭК (68)' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'СДЭК' ) ).toBeInTheDocument();
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

		const { container } = render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Статус данных' ) ).toBeInTheDocument() );

		// Two labelled columns — WooCommerce's own shape, asserted on RENDERED text.
		expect( screen.getByText( 'Обновлено' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Обновится' ) ).toBeInTheDocument();

		// The «Обновлено» column carries a real timestamp: never «Ни разу», never blank.
		const updated = screen.getByText( 'Обновлено' ).nextElementSibling;
		expect( updated.textContent.trim().length ).toBeGreaterThan( 0 );
		expect( updated ).not.toHaveTextContent( 'Ни разу' );

		// The per-carrier detail moved into the bar's tooltip when the block took
		// WooCommerce's shape — not lost, it just no longer decides the row width.
		const bar = container.querySelector( '.woodev-orders-sync__bar' );
		expect( bar.getAttribute( 'title' ) ).toContain( 'Тестовая доставка' );
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

		const { container } = render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Статус данных' ) ).toBeInTheDocument() );

		// The aggregate says «Ни разу» — a statement about the WHOLE table, never a
		// blank cell and never an overstated «Обновлено».
		const updated = screen.getByText( 'Обновлено' ).nextElementSibling;
		expect( updated ).toHaveTextContent( 'Ни разу' );

		// ⚠ The tooltip is what makes that actionable — it names WHICH carrier is the
		// reason. Without it the value is true but unusable.
		const bar = container.querySelector( '.woodev-orders-sync__bar' );
		expect( bar.getAttribute( 'title' ) ).toContain( 'Яндекс Доставка: ни разу не синхронизировалось' );
		expect( bar.getAttribute( 'title' ) ).toContain( 'СДЭК: ' );
		expect( bar.getAttribute( 'title' ) ).not.toContain( 'СДЭК: ни разу' );
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

		const { container } = render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Статус данных' ) ).toBeInTheDocument() );

		// No carrier has a cron, so the column states that instead of rendering blank.
		const next = screen.getByText( 'Обновится' ).nextElementSibling;
		expect( next ).toHaveTextContent( 'Не по расписанию' );

		const bar = container.querySelector( '.woodev-orders-sync__bar' );
		expect( bar.getAttribute( 'title' ) ).toContain( 'по расписанию не обновляется' );
	} );

	/**
	 * ⚠ Placement, and this is the assertion that changed when the operator sent a
	 * screenshot of WooCommerce's own block: the panel is a SIBLING of the pickers
	 * container inside the header row, NOT one of the controls in it. Inside the
	 * pickers row its width competed with two 430px-capped pickers and flex dropped
	 * it onto a line of its own — exactly what he saw on the rig.
	 */
	test( 'sits beside the pickers container in the header row, not inside it', async () => {
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

		const header = container.querySelector( '.woodev-orders__header' );
		const pickers = container.querySelector( '.woodev-orders__basic-filters' );
		const panel = container.querySelector( '.woodev-orders-sync' );
		const carrierFilter = screen.getByTestId( 'filter-picker-carrier' );

		expect( header ).toContainElement( pickers );
		expect( header ).toContainElement( panel );
		expect( pickers ).toContainElement( carrierFilter );
		// The assertion that would have caught the defect: the panel is NOT a picker.
		expect( pickers ).not.toContainElement( panel );
		expect(
			pickers.compareDocumentPosition( panel ) & Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );
} );

/**
 * The «Все (134) | Новые (7)» scope links (#841, operator 11.09.2026 with an ASCII
 * mock in front of him).
 *
 * ⚠ EVERY assertion here reads the RENDERED TEXT of a link, numbers included, and
 * never a prop or a component's internal value. Three times in this project a green
 * suite certified a widget whose visible text was wrong, and here the visible number
 * IS the requirement: the operator chose counted links over a toggle so the merchant
 * can check «Новые (7)» against the badge in the admin menu by eye. `getByRole(
 * 'link', … )` asserts two things at once — the accessible text, and that the control
 * really is a link rather than a button dressed as one.
 */
describe( 'the «Все / Новые» scope links (#841)', () => {
	const withCounts = ( rows, counts ) => resultOf( rows, { scope_counts: counts } );

	test( 'both scopes render with their counts as visible text', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 7 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toBeInTheDocument()
		);

		expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toBeInTheDocument();
	} );

	/**
	 * The number is the point, so a count of zero must still be ON SCREEN. «Новые (0)»
	 * is the answer «новых заказов нет»; a link that silently loses its number reads as
	 * a broken control instead.
	 */
	test( 'a zero count renders rather than disappearing', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 0 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Новые (0)' } ) ).toBeInTheDocument()
		);
	} );

	/**
	 * ⚠ Real `href`s (requirement 2), because the scope lives in the URL like every
	 * other filter here — that is what makes the view linkable and the back button
	 * work. «Все» REMOVES the key rather than writing `scope=all`, so the default view
	 * has exactly one spelling.
	 */
	test( 'the links are real hrefs: «Новые» carries scope=new, «Все» carries no scope at all', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 7 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toBeInTheDocument()
		);

		const newLink = screen.getByRole( 'link', { name: 'Новые (7)' } );
		const allLink = screen.getByRole( 'link', { name: 'Все (134)' } );

		expect( newLink.getAttribute( 'href' ) ).toContain( 'scope=new' );
		expect( allLink.getAttribute( 'href' ) ).not.toContain( 'scope' );
		// `type` is what makes the prefix a wc-admin one instead of a hand-built guess.
		expect( newLink ).toHaveAttribute( 'data-link-type', 'wc-admin' );
	} );

	/** WordPress marks the active scope `current`; `aria-current` carries the same fact to a screen reader. */
	test( 'the active scope is visibly the active one', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 7 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toBeInTheDocument()
		);

		expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toHaveClass( 'current' );
		expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toHaveAttribute( 'aria-current', 'page' );
		expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).not.toHaveClass( 'current' );
		expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).not.toHaveAttribute( 'aria-current' );
	} );

	test( 'a scope already in the URL is the current one, and scopes the very first fetch', async () => {
		fakeQuery = { scope: 'new' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 7 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenCalledWith( expect.objectContaining( { isExported: false } ) )
		);

		expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toHaveClass( 'current' );
		expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).not.toHaveClass( 'current' );
	} );

	/**
	 * ⚠ THIS ASSERTS THE CALL COUNT, and that is deliberate. `toHaveBeenLastCalledWith`
	 * cannot see a REPEAT of an identical call — that is exactly how #850 stayed green
	 * through a live redundant-fetch defect for two sessions. A scope switch is one
	 * navigation and must be one request.
	 *
	 * The new rows on screen are what proves the whole cycle actually completed; without
	 * that wait a passing count could just mean the click did nothing at all.
	 */
	test( 'switching scope issues exactly ONE fetch, not two', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders
			.mockResolvedValueOnce( withCounts( [ makeRow() ], { all: 134, new: 7 } ) )
			.mockResolvedValue(
				withCounts( [ makeRow( { id: 77, order_number: '77' } ) ], { all: 134, new: 7 } )
			);

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toBeInTheDocument()
		);

		const callsBefore = fetchOrders.mock.calls.length;

		fireEvent.click( screen.getByRole( 'link', { name: 'Новые (7)' } ) );

		await waitFor( () => expect( screen.getByText( 'Заказ 77' ) ).toBeInTheDocument() );

		expect( fetchOrders.mock.calls.length ).toBe( callsBefore + 1 );
		expect( fetchOrders ).toHaveBeenLastCalledWith( expect.objectContaining( { isExported: false } ) );
		expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toHaveClass( 'current' );
	} );

	/**
	 * ⚠ Back to «Все» must send NOTHING, not `is_exported=true`. The REST arg is a
	 * tri-state whose presence decides whether the query filters at all, so `true` would
	 * quietly turn «Все» into «уже экспортированные» — a wrong table under a link that
	 * says «Все».
	 */
	test( 'switching back to «Все» sends no isExported at all — and still only one fetch', async () => {
		fakeQuery = { scope: 'new' };
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders
			.mockResolvedValueOnce( withCounts( [ makeRow() ], { all: 134, new: 7 } ) )
			.mockResolvedValue(
				withCounts( [ makeRow( { id: 88, order_number: '88' } ) ], { all: 134, new: 7 } )
			);

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toBeInTheDocument()
		);

		const callsBefore = fetchOrders.mock.calls.length;

		fireEvent.click( screen.getByRole( 'link', { name: 'Все (134)' } ) );

		await waitFor( () => expect( screen.getByText( 'Заказ 88' ) ).toBeInTheDocument() );

		expect( fetchOrders.mock.calls.length ).toBe( callsBefore + 1 );
		expect( fetchOrders.mock.calls[ callsBefore ][ 0 ].isExported ).toBeUndefined();
		expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toHaveClass( 'current' );
	} );

	/**
	 * The counts describe what the table would show, so a scope switch must carry every
	 * other filter with it — the operator's own case: with a carrier picked, «Новые»
	 * means new among THAT carrier's orders.
	 */
	test( 'a scope switch keeps every other filter on the request', async () => {
		fakeQuery = { carrier: 'cdek', delivery_status_is: 'in_transit' };
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 12, new: 3 } ) );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Новые (3)' } ) ).toBeInTheDocument()
		);

		fireEvent.click( screen.getByRole( 'link', { name: 'Новые (3)' } ) );

		await waitFor( () =>
			expect( fetchOrders ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					carrier: 'cdek',
					deliveryStatus: 'in_transit',
					isExported: false,
				} )
			)
		);
	} );

	/**
	 * The links go ABOVE THE TABLE on their own row — not into the row of filter
	 * controls. The operator has corrected that row's geometry twice in two days, and a
	 * counted-link pair is not a filter control anyway; it is WordPress's own scope row.
	 */
	test( 'the links sit above the table and outside the filter row', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders.mockResolvedValue( withCounts( [ makeRow() ], { all: 134, new: 7 } ) );

		const { container } = render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Все (134)' } ) ).toBeInTheDocument()
		);

		const scopes = container.querySelector( '.woodev-orders__scopes' );
		const filters = container.querySelector( '.woodev-orders__filters' );

		expect( scopes ).not.toBeNull();
		expect( filters ).not.toContainElement( scopes );
		expect( scopes.closest( 'table' ) ).toBeNull();
		// Above the table: the scope row precedes the table in document order.
		expect(
			scopes.compareDocumentPosition( screen.getByRole( 'table' ) ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	/**
	 * A response with no `scope_counts` is an older server, and «not stated» is not
	 * «zero»: rendering invented numbers would destroy the one property this control
	 * has — that its numbers can be trusted against the menu badge. The table renders
	 * as before.
	 */
	test( 'a response without scope_counts renders no links at all', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByRole( 'table' ) ).toBeInTheDocument() );

		expect( screen.queryByRole( 'link', { name: /^Все \(/ } ) ).toBeNull();
		expect( screen.queryByRole( 'link', { name: /^Новые \(/ } ) ).toBeNull();
	} );

	/**
	 * A failed fetch leaves the previous counts describing a table that is no longer on
	 * screen. A stale «Новые (7)» above an empty table is precisely the number the
	 * merchant would carry to the menu badge and then mistrust, so the links go rather
	 * than lie — the carrier picker and the toggle stay mounted, so the view is still
	 * escapable.
	 */
	test( 'a failed fetch drops the links instead of showing stale counts', async () => {
		getProviders.mockReturnValue( twoProviders() );
		fetchOrders
			.mockResolvedValueOnce( withCounts( [ makeRow() ], { all: 134, new: 7 } ) )
			.mockRejectedValue( { message: 'Неизвестный перевозчик.' } );

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByRole( 'link', { name: 'Новые (7)' } ) ).toBeInTheDocument()
		);

		navigate( { carrier: 'nope' } );

		// `getAllByText`, not `getByText`: `@wordpress/components`' `Notice` also
		// announces itself through an a11y-speak live region, so the same text
		// legitimately appears twice — and because that region is populated
		// asynchronously, `getByText` here is not merely wrong, it is FLAKY (it passed
		// alone and failed in the suite).
		await waitFor( () =>
			expect( screen.getAllByText( 'Неизвестный перевозчик.' ).length ).toBeGreaterThan( 0 )
		);

		expect( screen.queryByRole( 'link', { name: 'Новые (7)' } ) ).toBeNull();
		expect( screen.queryByRole( 'link', { name: 'Все (134)' } ) ).toBeNull();
		// The row of filter controls survives, so the merchant can undo what broke it.
		expect( screen.getByTestId( 'filter-picker-carrier' ) ).toBeInTheDocument();
	} );
} );

/**
 * The «Действие» column (#824) — a per-row button row driven by `row.actions`, a POST
 * through `performOrderAction` (mocked here the same way `fetchOrders` is), in-flight
 * state scoped per row, a success swap of that one row, and a failure that leaves the row
 * untouched. Every assertion below is on RENDERED TEXT, not on props or `.value` — three
 * separate sessions on this page shipped a visible defect past a green suite and five
 * reviewers by asserting the value instead of what the cell draws (the brief's own
 * warning, and the same rule `payment cell`/`status cell` above already follow).
 */
describe( 'the «Действие» column (#824)', () => {
	/** One row with a single non-destructive «Выгрузить» action, overridable. */
	function actionRow( overrides = {} ) {
		return makeRow( {
			actions: [ { action: 'export', label: 'Выгрузить', title: '', destructive: false } ],
			...overrides,
		} );
	}

	beforeEach( () => {
		getProviders.mockReturnValue( oneProvider() );
	} );

	test( 'renders no button at all when actions is absent entirely', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ makeRow() ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Заказ 42' ) ).toBeInTheDocument() );

		expect( screen.queryByRole( 'button', { name: 'Выгрузить' } ) ).toBeNull();
	} );

	test( 'renders no button at all when actions is an empty array', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ actionRow( { actions: [] } ) ] ) );

		render( <App /> );

		await waitFor( () => expect( screen.getByText( 'Заказ 42' ) ).toBeInTheDocument() );

		expect( screen.queryByRole( 'button', { name: 'Выгрузить' } ) ).toBeNull();
	} );

	test( 'renders one action', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ actionRow() ] ) );

		render( <App /> );

		expect( await screen.findByRole( 'button', { name: 'Выгрузить' } ) ).toBeInTheDocument();
	} );

	test( 'renders three actions, in server order', async () => {
		fetchOrders.mockResolvedValue(
			resultOf( [
				actionRow( {
					actions: [
						{ action: 'export', label: 'Выгрузить', title: '', destructive: false },
						{ action: 'update', label: 'Обновить', title: '', destructive: false },
						{ action: 'cancel', label: 'Отменить', title: '', destructive: true },
					],
				} ),
			] )
		);

		render( <App /> );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Выгрузить' } ) ).toBeInTheDocument() );

		// ⚠ Assert on the ACCESSIBLE NAME, not `textContent`. The buttons are icon-only
		// since the operator rejected the text version against his own plugins, so every
		// one of them has an empty `textContent` and a `textContent`-based assertion
		// compares [] to the labels and fails — or, worse, passes vacuously if it filters
		// first. The name comes from `aria-label`, which is what a merchant's screen
		// reader announces and what the tooltip repeats.
		const labels = [ 'Выгрузить', 'Обновить', 'Отменить' ];
		const names = screen
			.getAllByRole( 'button' )
			.map( ( button ) => button.getAttribute( 'aria-label' ) )
			.filter( ( name ) => labels.includes( name ) );

		expect( names ).toEqual( labels );
	} );

	test( 'a non-empty title wraps the button in a tooltip', async () => {
		fetchOrders.mockResolvedValue(
			resultOf( [
				actionRow( {
					actions: [
						{
							action: 'export',
							label: 'Выгрузить',
							title: 'Отправить в СДЭК',
							destructive: false,
						},
					],
				} ),
			] )
		);

		render( <App /> );

		const button = await screen.findByRole( 'button', { name: 'Выгрузить' } );

		fireEvent.keyDown( document, { key: 'Tab' } );
		act( () => {
			button.focus();
		} );

		await waitFor( () => expect( screen.getByText( 'Отправить в СДЭК' ) ).toBeInTheDocument() );
	} );

	test( 'the in-flight state disables only its own row', async () => {
		fetchOrders.mockResolvedValue(
			resultOf( [
				actionRow( { id: 1, order_number: '1' } ),
				actionRow( { id: 2, order_number: '2' } ),
			] )
		);

		let resolveAction;
		performOrderAction.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveAction = resolve;
			} )
		);

		render( <App /> );

		const buttons = await screen.findAllByRole( 'button', { name: 'Выгрузить' } );
		expect( buttons ).toHaveLength( 2 );

		fireEvent.click( buttons[ 0 ] );

		await waitFor( () => expect( buttons[ 0 ] ).toBeDisabled() );
		expect( buttons[ 1 ] ).not.toBeDisabled();

		// Settle the pending call so it does not leak into the next test.
		await act( async () => {
			resolveAction( { row: actionRow( { id: 1, order_number: '1' } ), message: 'Готово.' } );
		} );
	} );

	test( 'success swaps the row in place, without a refetch', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ actionRow() ] ) );
		performOrderAction.mockResolvedValue( {
			row: actionRow( { order_number: '99', actions: [] } ),
			message: 'Заказ выгружен.',
		} );

		render( <App /> );

		const button = await screen.findByRole( 'button', { name: 'Выгрузить' } );

		fireEvent.click( button );

		await waitFor( () => expect( screen.getByText( 'Заказ 99' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Заказ 42' ) ).not.toBeInTheDocument();
		await waitFor( () =>
			expect( screen.getAllByText( 'Заказ выгружен.' ).length ).toBeGreaterThan( 0 )
		);
		// Exactly one fetch — the initial load — proves the swap did not refetch the page.
		expect( fetchOrders ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'failure renders the server message and leaves the old row untouched', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ actionRow() ] ) );
		performOrderAction.mockRejectedValue( { message: 'СДЭК недоступен.' } );

		render( <App /> );

		const button = await screen.findByRole( 'button', { name: 'Выгрузить' } );

		fireEvent.click( button );

		await waitFor( () =>
			expect( screen.getAllByText( 'СДЭК недоступен.' ).length ).toBeGreaterThan( 0 )
		);
		expect( screen.getByText( 'Заказ 42' ) ).toBeInTheDocument();
	} );

	test( 'a rejection with no message falls back to a generic Russian error', async () => {
		fetchOrders.mockResolvedValue( resultOf( [ actionRow() ] ) );
		performOrderAction.mockRejectedValue( {} );

		render( <App /> );

		const button = await screen.findByRole( 'button', { name: 'Выгрузить' } );

		fireEvent.click( button );

		await waitFor( () =>
			expect(
				screen.getAllByText( 'Не удалось выполнить действие.' ).length
			).toBeGreaterThan( 0 )
		);
	} );

	/**
	 * #824 round 2 (MEDIUM 4): a refetch that lands WHILE the POST is still in flight
	 * must not be overwritten by the POST's own (by-then-stale) row. Sequence from the
	 * brief: click «Выгрузить» on order 42, switch scope while the request is pending
	 * (a real refetch, via the same `ScopeLinks` navigation `switching scope issues
	 * exactly ONE fetch` above already exercises), the refetch lands first, THEN the
	 * action resolves. The notice must still show — the action really did happen — but
	 * the stale exported row must not be swapped into the now-current view.
	 */
	test( 'a refetch landing before the action resolves is not overwritten by the stale row', async () => {
		getProviders.mockReturnValue( oneProvider() );
		fetchOrders.mockResolvedValue( resultOf( [ actionRow() ], { scope_counts: { all: 1, new: 1 } } ) );

		let resolveAction;
		performOrderAction.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveAction = resolve;
			} )
		);

		render( <App /> );

		const button = await screen.findByRole( 'button', { name: 'Выгрузить' } );

		fireEvent.click( button );

		const callsBefore = fetchOrders.mock.calls.length;

		// A refetch lands FIRST — the merchant switched scope while the action was
		// still in flight.
		fireEvent.click( await screen.findByRole( 'link', { name: 'Новые (1)' } ) );

		await waitFor( () => expect( fetchOrders.mock.calls.length ).toBe( callsBefore + 1 ) );

		// The action resolves AFTER the refetch, with a row that is now stale relative
		// to the view on screen.
		await act( async () => {
			resolveAction( {
				row: actionRow( { order_number: '99', actions: [] } ),
				message: 'Заказ выгружен.',
			} );
		} );

		// The action really did happen — the notice shows either way.
		await waitFor( () =>
			expect( screen.getAllByText( 'Заказ выгружен.' ).length ).toBeGreaterThan( 0 )
		);
		// But the stale row was never swapped into the refetched table.
		expect( screen.queryByText( 'Заказ 99' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Заказ 42' ) ).toBeInTheDocument();
	} );

	describe( 'the destructive confirm', () => {
		function cancelRow( overrides = {} ) {
			return actionRow( {
				actions: [ { action: 'cancel', label: 'Отменить', title: '', destructive: true } ],
				...overrides,
			} );
		}

		test( 'the first click opens the confirm modal and never calls the API', async () => {
			fetchOrders.mockResolvedValue( resultOf( [ cancelRow() ] ) );

			render( <App /> );

			const cancelButton = await screen.findByRole( 'button', { name: 'Отменить' } );

			fireEvent.click( cancelButton );

			// The operator's own wording, carrier name and all — the row fixture's carrier
			// is «СДЭК», and the question must name it rather than saying "the carrier".
			expect(
				await screen.findByText( 'Вы уверены, что хотите отменить этот заказ в «СДЭК»?' )
			).toBeInTheDocument();
			expect( performOrderAction ).not.toHaveBeenCalled();
		} );

		test( 'confirming with «Да» calls the API and then swaps the row', async () => {
			fetchOrders.mockResolvedValue( resultOf( [ cancelRow() ] ) );
			performOrderAction.mockResolvedValue( {
				row: cancelRow( { actions: [] } ),
				message: 'Заказ отменён.',
			} );

			render( <App /> );

			fireEvent.click( await screen.findByRole( 'button', { name: 'Отменить' } ) );

			const confirmButton = await screen.findByRole( 'button', { name: 'Да' } );

			fireEvent.click( confirmButton );

			expect( performOrderAction ).toHaveBeenCalledWith( 42, 'cancel' );
			await waitFor( () =>
				expect( screen.getAllByText( 'Заказ отменён.' ).length ).toBeGreaterThan( 0 )
			);
		} );

		test( '«Нет» drops the confirm without ever calling the API', async () => {
			fetchOrders.mockResolvedValue( resultOf( [ cancelRow() ] ) );

			render( <App /> );

			fireEvent.click( await screen.findByRole( 'button', { name: 'Отменить' } ) );

			const noButton = await screen.findByRole( 'button', { name: 'Нет' } );

			fireEvent.click( noButton );

			expect(
				screen.queryByText( 'Вы уверены, что хотите отменить этот заказ в «СДЭК»?' )
			).not.toBeInTheDocument();
			expect( await screen.findByRole( 'button', { name: 'Отменить' } ) ).toBeInTheDocument();
			expect( performOrderAction ).not.toHaveBeenCalled();
		} );
	} );
} );

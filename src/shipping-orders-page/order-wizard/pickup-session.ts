/**
 * The pickup-point picker, mounted INSIDE the order wizard's delivery step (#710 D3 / O10,
 * increment I5a of card #970) — no second modal.
 *
 * Nothing here draws a map or a list. It assembles the framework's own storefront pieces —
 * `WoodevPickupDataSource` (REST bridge), `WoodevPickupPanels` (list, card, search, filter) and
 * the active map provider (`WoodevPickupMapProviders[ config.provider ]`, Yandex or an embedded
 * carrier widget) — into a host element, the way `pickup-mount.js`'s `openSession()` does for
 * the checkout. `pickup-mount.js` itself cannot be reused: its session is a ~2 700-line closure
 * welded to `WoodevModal`, the checkout's address fields and the visitor's session. This file
 * is the same wiring reduced to what an admin picker needs:
 *
 * - the data source takes an explicit `context` (order weight, payment method, destination
 *   record) because an admin REST request has no cart, no session and no visitor location
 *   chain — see `Pickup_Controller::register_admin_routes()`;
 * - a chosen point is reported to the caller straight away through `onSelect` — nothing is
 *   POSTed to `/select` (that route writes the calling admin's own session), and the payload
 *   validator re-checks the point when the order is saved;
 * - the drawn set is the LAST fetch, not an accumulating pool: the checkout keeps every point
 *   it has seen across viewport pans (issue #234), which an admin picking one point once does
 *   not need.
 *
 * Both chrome models are supported: a provider that draws its own whole UI (`mapConfig.ownsChrome`,
 * the embedded carrier widget) gets the host itself and reports `select`; every other provider
 * gets the panels' map element and the panels own list / card / search.
 *
 * **Manager mode (operator decision, 28.09.2026).** The panels are built with `mode: 'manager'`
 * (`pickup-panels.js`): the sidebar is permanent — the list by default, a point's details on a
 * row or marker click, «← К списку» back — and the card's button is «Выбрать», which reports the
 * point through `onSelect` exactly as before; the chosen point is then marked in the list and on
 * its own card («Выбрано»). The storefront's checkout-only chrome (the collapsible drawer and its
 * mobile bar, the «Продолжить оформление заказа» state) is what the mode drops; clustering,
 * viewport loading, search, the type filter and the lazy verdict check stay — a manager finds a
 * point the same way a buyer does. The three labels the mode reads are worded HERE, for a
 * manager ({@link managerI18n}); the storefront's own config never carries them and never sees
 * the flag, so the checkout is untouched.
 *
 * ⚠ The globals are read on every call, never captured at import: the scripts are enqueued
 * classic scripts, so what exists depends on the page, not on the bundle.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';

/** A point as the points route returns it — display fields arrive already `esc_html()`-escaped. */
export interface PickupPoint {
	id: string;
	name?: string;
	address?: string;
	short_address?: string;
	type?: { code: string; label: string };
	selectable?: { allowed: boolean; reason?: string };
	[ key: string ]: unknown;
}

/** `Pickup_Handler::get_admin_wizard_js_config()` — only the keys this file reads are typed. */
export interface PickupWizardConfig {
	provider: string;
	strategy: 'bulk' | 'viewport' | string;
	restRoot: string;
	i18n?: Record<string, string>;
	mapConfig?: Record<string, unknown> & { ownsChrome?: boolean; lang?: string };
	defaultLocation?: unknown;
	pointIcons?: unknown;
	accentColor?: string;
	[ key: string ]: unknown;
}

export interface PickupSessionOptions {
	/** Positioned, sized element the picker fills (`.woodev-pickup-stage` is `position: absolute; inset: 0`). */
	host: HTMLElement;
	config: PickupWizardConfig;
	/** The orders page's REST nonce; a function is read on every request. */
	nonce: string | ( () => string );
	/** Extra request params — weight, payment method, `location` — read on EVERY request. */
	context: () => Record<string, unknown>;
	/** A geocodable place name the map centres on; `''` = unknown. */
	locality: string;
	/** The key the server addresses points by under `bulk` (a settlement record's key); falls back to `locality`. */
	localityKey: string;
	/** The point already chosen, so its card opens marked. */
	selectedId: string;
	onSelect: ( point: PickupPoint ) => void;
	/** A failure the picker cannot show itself (an embedded provider owns the whole surface). */
	onError?: ( message: string ) => void;
}

export interface PickupSession {
	/** Marks a point as the chosen one (the panels' own «selected» state). */
	setSelectedId: ( id: string ) => void;
	/** Tears the provider and panels down and empties the host. Safe to call twice. */
	destroy: () => void;
}

/* eslint-disable @typescript-eslint/no-explicit-any -- the storefront scripts are untyped ES5 globals. */
type Loose = any;

interface PickupGlobals {
	WoodevPickupDataSource?: ( options: Record<string, unknown> ) => Loose;
	WoodevPickupPanels?: new ( container: HTMLElement, config: Record<string, unknown> ) => Loose;
	WoodevPickupGeo?: { groupByPosition: ( points: PickupPoint[] ) => Loose[] };
	WoodevPickupMapProviders?: Record<string, new () => Loose>;
}

const pickupGlobals = (): PickupGlobals => window as unknown as PickupGlobals;
/* eslint-enable @typescript-eslint/no-explicit-any */

/** The server's machine-readable error codes → the i18n key that words them (`pickup-mount.js`'s table). */
const ERROR_KEYS: Record<string, string> = {
	woodev_pickup_upstream_error: 'upstreamError',
	woodev_pickup_rate_limited: 'rateLimited',
	woodev_pickup_point_not_found: 'notFound',
	rest_cookie_invalid_nonce: 'stalePage',
	woodev_pickup_invalid_nonce: 'stalePage',
	rest_forbidden: 'stalePage',
};

/**
 * The panels' i18n keys manager mode reads, worded for a manager: the card's button, its chosen
 * state, and the way back from a card to the list. Laid OVER the carrier's own i18n table (which
 * carries the storefront's `select` wording, «Выбрать этот пункт» for a buyer) — the storefront's
 * table is never changed, this is the wizard's own copy of it.
 *
 * @return {Record<string, string>} key → label.
 */
const managerI18n = (): Record<string, string> => ( {
	select: __( 'Выбрать', 'woodev-plugin-framework' ),
	selected: __( 'Выбрано', 'woodev-plugin-framework' ),
	backToList: __( 'К списку', 'woodev-plugin-framework' ),
	yourAddress: __( 'Найти адрес', 'woodev-plugin-framework' ),
} );

/**
 * Whether everything the picker is assembled from is on the page: the data source, the panels
 * (unless the provider owns the whole chrome), the geometry helper and the provider class.
 * When it is not — a script that failed to load, a provider whose file is not built — the step
 * falls back to a typed point code instead of an empty box.
 *
 * @param {PickupWizardConfig|undefined} config the carrier's picker config, if it has one.
 * @return {boolean} true when {@link createPickupSession} can run.
 */
export function isPickupRuntimeAvailable( config: PickupWizardConfig | undefined ): boolean {
	if ( ! config || ! config.restRoot ) {
		return false;
	}

	const g = pickupGlobals();
	const provider = g.WoodevPickupMapProviders?.[ config.provider ];
	const ownsChrome = !! config.mapConfig?.ownsChrome;

	return (
		'function' === typeof g.WoodevPickupDataSource &&
		'function' === typeof provider &&
		( ownsChrome || ( 'function' === typeof g.WoodevPickupPanels && 'function' === typeof g.WoodevPickupGeo?.groupByPosition ) )
	);
}

/** The distinct `{ code, label }` point types in `points`, first-seen order (what the type filter offers). */
function extractTypes( points: PickupPoint[] ): Array<{ code: string; label: string }> {
	const seen = new Set<string>();
	const types: Array<{ code: string; label: string }> = [];

	for ( const point of points ) {
		const type = point && point.type;

		if ( type && 'string' === typeof type.code && ! seen.has( type.code ) ) {
			seen.add( type.code );
			types.push( { code: type.code, label: type.label } );
		}
	}

	return types;
}

/**
 * Builds and starts a picker in `options.host`.
 *
 * @param {PickupSessionOptions} options see {@link PickupSessionOptions}.
 * @return {PickupSession} the handle the host component keeps.
 */
export function createPickupSession( options: PickupSessionOptions ): PickupSession {
	const { host, config, context, onSelect, onError } = options;
	const g = pickupGlobals();
	const ProviderCtor = g.WoodevPickupMapProviders?.[ config.provider ] as ( new () => Loose ) | undefined;
	const DataSource = g.WoodevPickupDataSource as ( ( opts: Record<string, unknown> ) => Loose ) | undefined;
	const PanelsCtor = g.WoodevPickupPanels;
	const geo = g.WoodevPickupGeo;
	const ownsChrome = !! config.mapConfig?.ownsChrome;
	const i18n = config.i18n || {};
	const say = ( key: string ): string => ( 'string' === typeof i18n[ key ] ? i18n[ key ] : '' );

	let destroyed = false;
	let selectedId = options.selectedId;
	let provider: Loose = null;
	let panels: Loose = null;
	let searchLayoutEl: HTMLElement | null = null;
	let groupsByKey: Record<string, Loose> = {};
	let lastBbox: unknown = null;
	let lastAddresses: Array<{ query?: string; displayName?: string }> = [];
	let typeFilter: string[] = [];
	let loading = 0;
	let firstSettled = false;
	let anchorCaptured = false;
	let initialAnchor: unknown = null;
	const detailed = new Set<string>();

	if ( ! ProviderCtor || ! DataSource ) {
		onError?.( say( 'error' ) );

		return { setSelectedId: () => undefined, destroy: () => undefined };
	}

	const dataSource = DataSource( {
		restRoot: config.restRoot,
		nonce: typeof options.nonce === 'function' ? options.nonce : () => String( options.nonce ),
		context,
	} );

	const bumpLoading = () => {
		loading += 1;
		panels?.setLoading( true );
	};

	const dropLoading = () => {
		loading = Math.max( 0, loading - 1 );

		if ( 0 === loading ) {
			panels?.setLoading( false );
		}
	};

	/** Whatever settles first clears the stage's initial «busy» cover (`clearInitialBusy()` in the mount). */
	const clearInitialBusy = () => {
		if ( ! firstSettled ) {
			firstSettled = true;
			panels?.setBusy( false );
		}
	};

	/** A failure or an empty answer: the panels' own message card, or the caller for an embedded widget. */
	const showMessage = ( key: string ) => {
		if ( panels ) {
			panels.showMessage( key );
		} else {
			onError?.( say( key ) || say( 'error' ) );
		}
	};

	const fetchAndSetPoints = ( query: Record<string, unknown> ): Promise<void> => {
		bumpLoading();

		return ( dataSource.fetchPoints( query ) as Promise<PickupPoint[]> ).then(
			( points ) => {
				dropLoading();
				clearInitialBusy();

				if ( destroyed ) {
					return;
				}

				const groups = geo ? geo.groupByPosition( points ) : [];

				groupsByKey = {};
				groups.forEach( ( group ) => {
					groupsByKey[ group.key ] = group;
				} );
				detailed.clear();

				provider.setPoints( groups, null );
				panels?.setTypes( extractTypes( points ) );

				if ( selectedId ) {
					panels?.setSelectedId( selectedId );
				}

				if ( points.length > 0 ) {
					panels?.hideMessage();
				} else {
					showMessage( 'bulk' === config.strategy ? 'emptyLocality' : 'emptyInView' );
				}
			},
			( reason: { code?: string } ) => {
				dropLoading();
				clearInitialBusy();

				if ( ! destroyed ) {
					const key = ( reason && reason.code && ERROR_KEYS[ reason.code ] ) || 'error';

					showMessage( say( key ) ? key : 'error' );
				}
			}
		);
	};

	const bulkQuery = () => ( { locality: options.localityKey || options.locality, types: typeFilter } );

	/**
	 * `viewport` listings are sparse — the constraint inputs (weight, COD) may be absent, and
	 * the verdict permissive by omission (#219). The details route re-runs the checker over the
	 * full record, so opening a card asks it once per point per listing and merges the answer.
	 */
	const refreshPointDetails = ( pointId: string ) => {
		if ( 'viewport' !== config.strategy || ! pointId || destroyed || detailed.has( pointId ) ) {
			return;
		}

		detailed.add( pointId );
		panels?.setVerdictPending( true );

		( dataSource.fetchDetails( pointId ) as Promise<PickupPoint> ).then(
			( point ) => {
				panels?.setVerdictPending( false );

				if ( ! destroyed ) {
					panels?.updatePoint( pointId, point );
				}
			},
			() => {
				// Degrades to the sparse verdict the listing gave; the payload validator still re-checks on save.
				panels?.setVerdictPending( false );
				detailed.delete( pointId );
			}
		);
	};

	const handleSelection = ( point: PickupPoint ) => {
		if ( destroyed || ! point || ! point.id ) {
			return;
		}

		selectedId = String( point.id );
		panels?.setSelectedId( selectedId );
		onSelect( point );
	};

	const buildProviderConfig = (): Record<string, unknown> => ( {
		...( config.mapConfig || {} ),
		strategy: config.strategy,
		i18n: config.i18n,
		locality: options.locality,
		defaultLocation: config.defaultLocation,
		pointIcons: config.pointIcons,
		accentColor: config.accentColor,
		searchLayoutEl,
	} );

	/** Constructs, wires and `init()`s a FRESH provider — a retry never re-inits a live one. */
	const start = () => {
		provider?.destroy?.();
		provider = new ProviderCtor();
		lastBbox = null;
		anchorCaptured = false;
		initialAnchor = null;
		firstSettled = false;

		provider.on( 'select', handleSelection );

		// A provider-level failure breaks the whole map: the panels' message card says so where there
		// are panels, and the caller does where the provider owns the surface.
		provider.on( 'error', ( reason: { message?: string } ) => {
			if ( panels ) {
				panels.showMessage( 'error' );
			} else {
				onError?.( ( reason && reason.message ) || say( 'error' ) );
			}
		} );

		provider.on( 'zoomChange', ( limits: unknown ) => panels?.setZoomLimits( limits ) );

		if ( ! ownsChrome ) {
			provider.on( 'pointClick', ( key: string ) => {
				const group = groupsByKey[ key ];

				if ( group ) {
					panels.openCard( group, null, 'marker' );
				}
			} );

			provider.on( 'visibleChange', ( keys: string[] ) => {
				const visible = ( keys || [] ).map( ( key ) => groupsByKey[ key ] ).filter( Boolean );

				// The FIRST `visibleChange` is the first moment the camera has settled: the list's
				// distance anchor is captured there, once, and never follows a pan (issue #163).
				if ( ! anchorCaptured ) {
					anchorCaptured = true;

					const center = 'function' === typeof provider.getCenter ? provider.getCenter() : null;

					if ( center ) {
						initialAnchor = center;
						panels.setAnchor( center );
					}
				}

				panels.setVisible( visible );
			} );

			provider.on( 'boundsChange', ( bbox: unknown ) => {
				lastBbox = bbox;
				fetchAndSetPoints( { bounds: bbox, types: typeFilter } ).catch( () => undefined );
			} );

			provider.on( 'nothingNearby', ( info: unknown ) => panels.showNothingNearby( info ) );
			provider.on( 'bboxTooWide', () => {
				clearInitialBusy();
				panels.showMessage( 'zoomIn' );
			} );
			provider.on( 'searchResults', ( results: { addresses?: typeof lastAddresses } ) => {
				lastAddresses = ( results && results.addresses ) || [];
				panels.renderSearchResults( results );
			} );
			provider.on( 'searchCleared', () => panels.hideSearchResults() );
			provider.on( 'addressMatchedPoint', ( info: { key?: string } ) => {
				const group = info && info.key ? groupsByKey[ info.key ] : null;

				if ( group ) {
					panels.openCard( group, null, 'search' );
				}
			} );
			provider.on( 'addressFocused', ( info: { latLng: unknown; label: string } ) => {
				panels.setAnchor( info.latLng, info.label );
				panels.openList();
			} );
		}

		const mapHost = ownsChrome ? host : panels.getMapElement();

		Promise.resolve( provider.init( mapHost, buildProviderConfig(), dataSource ) ).then( () => {
			if ( destroyed ) {
				return;
			}

			// Manager mode opens the sidebar in `render()`, before any `listToggle` listener
			// exists, so the camera's margin for it is reserved HERE, before the first move — by
			// asking the panels for the strip they occupy, the same number `listToggle` would
			// carry (the checkout's mount reserves nothing here because its panels start closed).
			if ( panels && 'function' === typeof provider.setMargin ) {
				const width = 'function' === typeof panels.getSidebarWidth ? Number( panels.getSidebarWidth() ) || 0 : 0;

				provider.setMargin( width > 0, width );
			}

			panels?.setBusy( true );

			if ( ! ownsChrome && 'bulk' === config.strategy ) {
				fetchAndSetPoints( bulkQuery() ).catch( () => undefined );
			}
		} );
	};

	if ( ! ownsChrome ) {
		panels = new PanelsCtor!( host, {
			...config,
			mode: 'manager',
			i18n: { ...( config.i18n || {} ), ...managerI18n() },
			lang: 'string' === typeof config.mapConfig?.lang ? config.mapConfig.lang : '',
		} );
		panels.render();
		searchLayoutEl = panels.buildSearchLayout();

		panels.on( 'cardOpened', ( payload: { pointId: string | number; group?: { key: string }; origin?: string } ) => {
			refreshPointDetails( String( payload.pointId ) );

			// `restore` and `tab` move no camera (see the mount for why); a marker click only pans.
			if ( 'restore' === payload.origin || 'tab' === payload.origin ) {
				return;
			}

			if ( payload.group && 'function' === typeof provider?.focusGroup ) {
				provider.focusGroup( payload.group.key, { zoom: 'marker' !== payload.origin } );
			}
		} );

		panels.on( 'select', handleSelection );

		panels.on( 'typeFilterChange', ( codes: string[] ) => {
			typeFilter = codes;

			if ( 'bulk' === config.strategy ) {
				provider.setTypeFilter( codes );

				return;
			}

			// `viewport`: the SERVER applies the filter, so the same bbox is asked again with the new types.
			if ( lastBbox ) {
				fetchAndSetPoints( { bounds: lastBbox, types: codes } ).catch( () => undefined );
			}
		} );

		panels.on( 'listToggle', ( state: { open: boolean; width: number } ) => provider?.setMargin?.( state.open, state.width ) );
		panels.on( 'zoom', ( payload: { step: number } ) => provider?.zoomBy?.( payload.step ) );

		panels.on( 'searchAddressPicked', ( index: number ) => {
			const address = lastAddresses[ index ];
			const query = address && ( address.query || address.displayName );

			if ( query && 'function' === typeof provider?.resolveAddress ) {
				provider.resolveAddress( query );
			}
		} );

		panels.on( 'searchPointPicked', ( pointId: string ) => {
			const group = Object.values( groupsByKey ).find( ( candidate ) =>
				( candidate.points || [] ).some( ( point: PickupPoint ) => String( point.id ) === String( pointId ) )
			);

			if ( group ) {
				panels.openCard( group, pointId, 'search' );
			}
		} );

		panels.on( 'showNearestRequested', ( info: { key?: string } ) => {
			const group = info && info.key ? groupsByKey[ info.key ] : null;

			if ( group ) {
				panels.openCard( group, null, 'nearest' );
			}
		} );

		panels.on( 'anchorCleared', () => provider?.clearAddress?.() );

		// Dropping a search falls the list's distance anchor BACK to where the picker opened, never to
		// nothing — the list keeps its ordering (issue #163).
		panels.on( 'searchReset', () => {
			provider?.clearAddress?.();
			panels.setAnchor( initialAnchor );
		} );

		// Typing previews the loaded pool plus the geocoder's suggestions; Enter resolves the best hit.
		panels.on( 'searchType', ( payload: { query: string } ) => {
			if ( 'function' === typeof provider?.suggestAddresses ) {
				provider
					.suggestAddresses( payload.query )
					.then( ( results: { addresses?: typeof lastAddresses } ) => {
						if ( destroyed ) {
							return;
						}

						lastAddresses = ( results && results.addresses ) || [];
						panels.previewSearchResults( results );
					} )
					.catch( () => undefined );

				return;
			}

			if ( 'function' === typeof provider?.matchLoadedPoints ) {
				panels.previewSearchResults( { points: provider.matchLoadedPoints( payload.query ), addresses: [] } );
			}
		} );

		let submitInFlight = false;

		panels.on( 'searchSubmit', ( payload: { query: string } ) => {
			if ( submitInFlight || 'function' !== typeof provider?.suggestAddresses || 'function' !== typeof provider?.resolveAddress ) {
				return;
			}

			submitInFlight = true;

			provider
				.suggestAddresses( payload.query )
				.then( ( results: { addresses?: typeof lastAddresses; points?: PickupPoint[] } ) => {
					if ( destroyed ) {
						return undefined;
					}

					const addresses = ( results && results.addresses ) || [];

					lastAddresses = addresses;

					if ( 0 === addresses.length ) {
						panels.previewSearchResults( { points: ( results && results.points ) || [], addresses: [] } );

						return undefined;
					}

					panels.hideSearchResults();

					return provider.resolveAddress( addresses[ 0 ].query || addresses[ 0 ].displayName );
				} )
				.catch( () => undefined )
				.then( () => {
					submitInFlight = false;
				} );
		} );

		panels.on( 'retryRequested', () => start() );

		if ( selectedId ) {
			panels.setSelectedId( selectedId );
		}
	}

	start();

	return {
		setSelectedId: ( id: string ) => {
			selectedId = id;
			panels?.setSelectedId( id );
		},
		destroy: () => {
			if ( destroyed ) {
				return;
			}

			destroyed = true;
			provider?.destroy?.();
			panels?.destroy?.();
			host.replaceChildren();
		},
	};
}

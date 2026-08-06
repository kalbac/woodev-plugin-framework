/**
 * Woodev Pickup DataSource — REST bridge between a map provider and the
 * `woodev/v1` pickup-points routes ({@see class-pickup-controller.php}).
 *
 * Plain factory, ES5-safe, no jQuery, no build step — this file is enqueued
 * directly (see `woodev-pickup-datasource` in class-pickup-handler.php),
 * never bundled. `fetch`/`Promise` are runtime APIs, not syntax, so their use
 * here is fine even though the file itself stays ES5-safe (no arrow
 * functions, no `const`/`let`, no template literals).
 *
 * WHY THE FRAMEWORK HANDS THE PROVIDER A dataSource INSTEAD OF A LIST: a
 * bulk carrier (Yandex, CDEK) wants a whole locality in one call and filters
 * it locally; a viewport carrier (OZON Logistics) wants one call per visible
 * bounding box as the customer pans, plus a details call per point on
 * balloon-open. Only the provider knows the viewport moved or a balloon
 * opened, so only the provider can decide WHEN to call — this object is
 * what it calls.
 *
 * `fetchPoints()` is debounced (see DEBOUNCE_MS below); `fetchDetails()`
 * fires immediately every time — a balloon open is a discrete, deliberate
 * user action, not a continuous stream, and should never wait on a timer.
 *
 * Each returned point already carries a server-computed `selectable`
 * verdict ({@see Constraint_Checker}). This module passes points through
 * UNTOUCHED apart from de-duplication by id — it never filters or
 * reinterprets `selectable`.
 *
 * ERROR SHAPE: every rejection is a plain object `{ status, code, message }`:
 *   - `status` — the HTTP status (`502`, `429`, `404`, ...), or `null` when
 *     the request never reached the server (network failure) or the
 *     response body could not be parsed as JSON.
 *   - `code` — the server's machine-readable error code
 *     (`woodev_pickup_upstream_error`, `woodev_pickup_rate_limited`,
 *     `woodev_pickup_point_not_found`), or one of this module's own
 *     transport-level codes (`woodev_pickup_network_error`,
 *     `woodev_pickup_invalid_response`) when the failure never reached the
 *     controller. Consumers branch on `code`, never on `message` prose.
 *   - `message` — the server's (or a generic) human-readable string; NOT
 *     used for branching, only for a last-resort log line.
 * A genuinely empty result (`200` with `{ points: [] }`) resolves to `[]` —
 * it never rejects. An empty array and a rejection are the two distinct
 * states the modal's empty/error UI is built against; this module must
 * never blur them.
 *
 * SUPERSEDE SEMANTICS: only `fetchPoints()` bursts are debounced, but two
 * ISSUED requests can still race — a burst ends, one request fires, the
 * customer pans again after the debounce window closes, a second request
 * fires, and the network delivers the two out of order. Every issued
 * request gets an increasing sequence number; when a response arrives for a
 * request that is no longer the latest ISSUED one, its own waiters are
 * NEVER resolved with that stale payload. Instead they are chained onto the
 * latest request's own promise (already settled or still in flight) and
 * resolve/reject with THAT outcome — kinder to callers than leaving an
 * older burst's promise pending forever, and it guarantees a caller never
 * sees a viewport result older than the one it already has.
 *
 * UMD-ish dual export (matches woodev-modal.js):
 *   - Browser global: window.WoodevPickupDataSource = WoodevPickupDataSource
 *   - CommonJS:       module.exports = WoodevPickupDataSource  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/**
	 * Default debounce interval, in milliseconds, for `fetchPoints()`.
	 *
	 * MUST be chosen together with `Pickup_Controller::POINTS_RATE_LIMIT_MAX`
	 * (240 req/min) in class-pickup-controller.php — that budget assumes a
	 * ~300ms client debounce, giving a worst-case continuous-pan rate of
	 * ~200/min with headroom to spare. Shipping a materially shorter default
	 * here without raising that budget in lockstep will make a customer who
	 * pans continuously hit a hard 429.
	 *
	 * @type {number}
	 */
	var DEBOUNCE_MS = 300;

	/**
	 * Maximum accepted length for a free-text `q` value before it is sent —
	 * purely a courtesy trim; the server independently caps and validates
	 * every param (`Pickup_Controller::MAX_PARAM_LENGTH`).
	 *
	 * @type {number}
	 */
	var MAX_Q_LENGTH = 128;

	/**
	 * De-duplicates a point list by `id`, keeping the FIRST occurrence.
	 *
	 * A point without a usable id (missing/null) cannot be deduplicated and
	 * is passed through unchanged — dropping it would silently hide a
	 * malformed-but-otherwise-valid point from the map.
	 *
	 * @param {Array} points
	 * @returns {Array}
	 */
	function dedupeById( points ) {
		var seen = {};
		var out = [];
		var i, point, id;

		for ( i = 0; i < points.length; i++ ) {
			point = points[ i ];
			id = point && point.id !== undefined && point.id !== null ? String( point.id ) : null;

			if ( null === id ) {
				out.push( point );
				continue;
			}

			if ( Object.prototype.hasOwnProperty.call( seen, id ) ) {
				continue;
			}

			seen[ id ] = true;
			out.push( point );
		}

		return out;
	}

	/**
	 * Builds the `key=value&…` query string for a points request.
	 *
	 * Emits, in order, `locality`, `bbox` (the bounds array joined as
	 * `lat1,lng1,lat2,lng2` — the EXACT format
	 * `Point_Query::from_request()` parses; any other separator or field
	 * order is silently rejected server-side), `q`, and `types` (the codes
	 * comma-joined — the EXACT format `Point_Query::parse_types()` parses,
	 * same separator convention as `bbox`; Task 20's mount is the first
	 * caller to ever pass this). Omits a param entirely rather than sending
	 * it empty/malformed, so the server's own "no addressing mode"/"no
	 * types" empty-result branch decides what an omitted param means, not
	 * this module.
	 *
	 * @param {Object} query `{ locality, bounds, q, types }` — all optional.
	 * @returns {string}
	 */
	function serializePointsQuery( query ) {
		var q = query || {};
		var parts = [];

		if ( 'string' === typeof q.locality && q.locality.length > 0 ) {
			parts.push( 'locality=' + encodeURIComponent( q.locality ) );
		}

		if ( Array.isArray( q.bounds ) && 4 === q.bounds.length ) {
			parts.push( 'bbox=' + encodeURIComponent( q.bounds.join( ',' ) ) );
		}

		if ( 'string' === typeof q.q && q.q.length > 0 ) {
			parts.push( 'q=' + encodeURIComponent( q.q.substring( 0, MAX_Q_LENGTH ) ) );
		}

		if ( Array.isArray( q.types ) && q.types.length > 0 ) {
			parts.push( 'types=' + encodeURIComponent( q.types.join( ',' ) ) );
		}

		return parts.join( '&' );
	}

	/**
	 * Builds a transport/parse-level error — a failure that never reached
	 * (or never got a usable answer from) the REST controller, distinct
	 * from a server error response (see {@see errorFromResponse}).
	 *
	 * @param {string} code
	 * @param {string} message
	 * @returns {{status: null, code: string, message: string}}
	 */
	function transportError( code, message ) {
		return { status: null, code: code, message: message };
	}

	/**
	 * Builds the error object for a non-OK REST response.
	 *
	 * A WP REST `WP_Error` response body is `{ code, message, data: { status } }`
	 * ({@see \WP_Error} JSON shape) — `body.data.status` is preferred over
	 * the transport-level `response.status` because it is the value the
	 * controller actually set (e.g. `502`/`429`/`404`); they are expected to
	 * always agree, but the body is the more authoritative of the two.
	 *
	 * @param {Response} response
	 * @param {*}         body
	 * @returns {{status: number, code: string, message: string}}
	 */
	function errorFromResponse( response, body ) {
		var hasStatus = body && body.data && 'number' === typeof body.data.status;

		return {
			status: hasStatus ? body.data.status : response.status,
			code: body && 'string' === typeof body.code ? body.code : 'woodev_pickup_unexpected_response',
			message: body && 'string' === typeof body.message ? body.message : '',
		};
	}

	/**
	 * Performs one request against the pickup REST surface and resolves with
	 * the parsed JSON body, or rejects with the error shape documented at the
	 * top of this file. Shared by every verb (`request()`'s GETs and
	 * `selectPoint()`'s POST) so all of them fail the same way.
	 *
	 * @param {string} url
	 * @param {Object} init `fetch()`'s own second argument (method, headers, body, …).
	 * @returns {Promise<*>}
	 */
	function requestJson( url, init ) {
		return fetch( url, init ).then(
			function( response ) {
				return response.json().then(
					function( body ) {
						if ( ! response.ok ) {
							return Promise.reject( errorFromResponse( response, body ) );
						}

						return body;
					},
					function() {
						// Body did not parse as JSON — cannot recover the server's code/message,
						// but the HTTP status alone still tells success from failure.
						if ( ! response.ok ) {
							return Promise.reject( errorFromResponse( response, null ) );
						}

						return Promise.reject(
							transportError( 'woodev_pickup_invalid_response', 'Malformed response body.' )
						);
					}
				);
			},
			function( networkFailure ) {
				return Promise.reject(
					transportError(
						'woodev_pickup_network_error',
						networkFailure && networkFailure.message ? networkFailure.message : 'Network request failed.'
					)
				);
			}
		);
	}

	/**
	 * Performs one GET request against the pickup REST surface. Thin wrapper
	 * around {@see requestJson} that supplies the GET-specific `init`.
	 *
	 * @param {string}   url
	 * @param {Function} readNonce reads the CURRENT nonce — see {@see WoodevPickupDataSource}'s
	 *                             own docblock for why this is a provider, not a value.
	 * @returns {Promise<*>}
	 */
	function request( url, readNonce ) {
		return requestJson( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': readNonce() },
		} );
	}

	/**
	 * Issues one (non-debounced) points request and resolves with the
	 * de-duplicated point list. `{ points: [] }` resolves to `[]` — a
	 * genuinely empty result is never an error (see the file-level docblock).
	 *
	 * @param {string}   restRoot
	 * @param {Function} readNonce
	 * @param {Object}   query
	 * @returns {Promise<Array>}
	 */
	function fetchPointsOnce( restRoot, readNonce, query ) {
		var qs = serializePointsQuery( query );
		var url = restRoot + ( qs.length > 0 ? '?' + qs : '' );

		return request( url, readNonce ).then( function( body ) {
			var points = body && Array.isArray( body.points ) ? body.points : [];

			return dedupeById( points );
		} );
	}

	/**
	 * Issues a single point detail request.
	 *
	 * @param {string}   restRoot
	 * @param {Function} readNonce
	 * @param {string}   pointId
	 * @returns {Promise<Object>}
	 */
	function fetchDetailsOnce( restRoot, readNonce, pointId ) {
		var url = restRoot.replace( /\/+$/, '' ) + '/' + encodeURIComponent( pointId );

		return request( url, readNonce );
	}

	/**
	 * Settles every waiter in `waiters` with the same fulfilled value.
	 *
	 * @param {Array} waiters
	 * @param {*}     value
	 * @returns {void}
	 */
	function resolveAll( waiters, value ) {
		var i;
		for ( i = 0; i < waiters.length; i++ ) {
			waiters[ i ].resolve( value );
		}
	}

	/**
	 * Settles every waiter in `waiters` with the same rejection reason.
	 *
	 * @param {Array} waiters
	 * @param {*}     reason
	 * @returns {void}
	 */
	function rejectAll( waiters, reason ) {
		var i;
		for ( i = 0; i < waiters.length; i++ ) {
			waiters[ i ].reject( reason );
		}
	}

	/**
	 * @typedef {Object} WoodevPickupDataSourceOptions
	 * @property {string}          restRoot         Points collection REST URL (details is
	 *                                               `{restRoot}/{id}`, see
	 *                                               {@see Pickup_Handler::rest_root()}).
	 * @property {string|Function} nonce            `X-WP-Nonce` value (`wp_create_nonce( 'wp_rest' )`),
	 *                                               or a zero-arg function returning the CURRENT one —
	 *                                               see {@see WoodevPickupDataSource}'s own docblock for
	 *                                               why a function, not a captured string, is what makes
	 *                                               a fragment-refreshed nonce (issue #157) actually reach
	 *                                               the request.
	 * @property {number}          [debounceMs=300] `fetchPoints()` debounce interval — see
	 *                                               {@see DEBOUNCE_MS} for why 300 is the default and
	 *                                               must not be lowered without also raising the
	 *                                               controller's rate-limit budget.
	 */

	/**
	 * Builds a pickup dataSource bound to one REST root.
	 *
	 * @param {WoodevPickupDataSourceOptions} options
	 * @returns {{fetchPoints: function(Object): Promise<Array>, fetchDetails: function(string): Promise<Object>, selectPoint: function({pointId: string, fieldId: string}): Promise<Object>}}
	 */
	function WoodevPickupDataSource( options ) {
		var opts = options || {};
		var restRoot = String( opts.restRoot || '' );

		/*
		 * A PROVIDER, not a value. `wp_localize_script()` prints the JS config once per page
		 * load, outside the fragment `update_checkout` refreshes, so a nonce captured here could
		 * never become fresh again — issue #157. Callers pass a function reading whichever node
		 * currently holds a valid nonce; a plain string is still accepted so nothing else in the
		 * codebase has to change at once.
		 */
		var readNonce = 'function' === typeof opts.nonce
			? opts.nonce
			: function() {
				return String( opts.nonce || '' );
			};

		var debounceMs = 'number' === typeof opts.debounceMs ? opts.debounceMs : DEBOUNCE_MS;

		/** @type {number|null} pending debounce timer id. */
		var timerId = null;

		/** @type {Array} waiters — `{resolve, reject}` — for the CURRENT debounce burst. */
		var pendingWaiters = [];

		/** @type {Object|null} the most recently supplied query, used when the burst flushes. */
		var latestArgs = null;

		/** @type {number} monotonically increasing id of the last ISSUED (post-debounce) request. */
		var latestSeq = 0;

		/**
		 * @type {Promise<Array>|null} promise of the most recently ISSUED request — the
		 * "current" viewport result a superseded, still in-flight older request adopts
		 * instead of resolving its own waiters with stale data.
		 */
		var latestPromise = null;

		/**
		 * Fires the actual REST call for the current burst and settles its waiters —
		 * either directly (this was still the latest request when it settled) or by
		 * adopting whatever the meanwhile-newer request settles with (see the file-level
		 * SUPERSEDE SEMANTICS docblock).
		 *
		 * @returns {void}
		 */
		function flush() {
			var waiters = pendingWaiters;
			var args = latestArgs;
			var mySeq;

			timerId = null;
			pendingWaiters = [];
			latestArgs = null;

			latestSeq += 1;
			mySeq = latestSeq;

			latestPromise = fetchPointsOnce( restRoot, readNonce, args ).then(
				function( points ) {
					if ( mySeq === latestSeq ) {
						resolveAll( waiters, points );
						return points;
					}

					// Superseded: adopt the newer request's own outcome rather than
					// resolving these waiters with a viewport they have already left.
					return latestPromise.then(
						function( newerPoints ) {
							resolveAll( waiters, newerPoints );
							return newerPoints;
						},
						function( newerReason ) {
							rejectAll( waiters, newerReason );
							return Promise.reject( newerReason );
						}
					);
				},
				function( reason ) {
					if ( mySeq === latestSeq ) {
						rejectAll( waiters, reason );
						return Promise.reject( reason );
					}

					return latestPromise.then(
						function( newerPoints ) {
							resolveAll( waiters, newerPoints );
							return newerPoints;
						},
						function( newerReason ) {
							rejectAll( waiters, newerReason );
							return Promise.reject( newerReason );
						}
					);
				}
			);

			// `latestPromise` is read (via the closures above) by whichever LATER burst
			// supersedes this one, but when THIS burst turns out to be the final one, or
			// when a rejection is adopted by a superseded predecessor, nothing else ever
			// calls `.then()`/`.catch()` on the exact object below — without this, a
			// rejected request would be reported as an unhandled promise rejection even
			// though `rejectAll()`/the adoption chain above already delivered the failure
			// to every real caller through the promise `fetchPoints()` returned them.
			latestPromise.catch( function() {} );
		}

		/**
		 * Requests points for a locality or a viewport bounding box, debounced by
		 * `debounceMs`. Every call within one debounce window collapses into a SINGLE
		 * REST request that uses the LAST call's query — all of that window's promises
		 * resolve (or reject) together with that one request's outcome. See the
		 * file-level docblock for what happens when this request is later superseded by
		 * a subsequent, out-of-order-delivered one.
		 *
		 * @param {Object} query `{ locality, bounds, q, types }` — all optional.
		 * @returns {Promise<Array>}
		 */
		function fetchPoints( query ) {
			return new Promise( function( resolve, reject ) {
				latestArgs = query;
				pendingWaiters.push( { resolve: resolve, reject: reject } );

				if ( null !== timerId ) {
					clearTimeout( timerId );
				}

				timerId = setTimeout( flush, debounceMs );
			} );
		}

		/**
		 * Requests a single point's details. NEVER debounced — a balloon open is a
		 * discrete, deliberate user action, not a continuous stream of input.
		 *
		 * @param {string} pointId
		 * @returns {Promise<Object>}
		 */
		function fetchDetails( pointId ) {
			return fetchDetailsOnce( restRoot, readNonce, pointId );
		}

		/**
		 * Confirms one point with the server.
		 *
		 * Never debounced and never superseded, unlike `fetchPoints()`: a confirmation is a
		 * single deliberate act, and the card is locked while it is in flight (spec D-9), so
		 * there is no burst to collapse and no newer result to adopt.
		 *
		 * @param {{pointId: string, fieldId: string}} args
		 * @returns {Promise<Object>}
		 */
		function selectPoint( args ) {
			var url = restRoot.replace( /\/points\/*$/, '' ) + '/select';

			return requestJson( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': readNonce(),
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					point_id: String( args.pointId ),
					field_id: String( args.fieldId ),
				} ),
			} );
		}

		return {
			fetchPoints: fetchPoints,
			fetchDetails: fetchDetails,
			selectPoint: selectPoint,
		};
	}

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupDataSource = WoodevPickupDataSource;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevPickupDataSource;
	}

}() );

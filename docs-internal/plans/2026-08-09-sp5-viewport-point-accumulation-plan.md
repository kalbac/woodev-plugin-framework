# Viewport Point Accumulation (#234) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Under the `viewport` strategy the map draws the UNION of every listing seen this session, so zooming out never loses points the customer just saw (#234).

**Architecture:** A session-scoped point pool lives in `pickup-mount.js` — the file that already owns fetching. `Map_Provider`'s contract, `setPoints()` and `map-provider-embedded.js` are NOT touched: `setPoints()`'s own docblock already assigns cross-fetch de-duplication to the caller. The `bulk` strategy is untouched throughout.

**Tech Stack:** Vanilla ES5 JS (no transpilation — no `??`, no arrow functions, no `const`/`let` in the shipped files), jest via `wp-scripts test-unit-js`, PHP 7.4+ for the config seam.

**Spec:** `docs-internal/specs/2026-08-09-sp5-viewport-point-accumulation-design.md` — read §3 and §7
before starting. §7 records an adversarial review of this design and what changed because of it;
Task 1b exists entirely because of it.

---

## MANDATORY for every task

**Run the FULL verification set before every commit. All four, every time:**

```bash
composer test:unit
composer phpcs
composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
```

- **NEVER `npx jest`** — this project has no jest config of its own; `npx jest` loses jsdom and prints phantom failures (gotcha `npx-jest-bypasses-wp-scripts-jsdom`).
- The `--roots` flag is REQUIRED — without it jest also counts agent worktrees under `.claude/worktrees/` and reports a wrong total (gotcha `jest-scans-agent-worktrees-inside-the-repo`).
- **You are expected to contradict this plan where the code disagrees with it.** Say so and stop rather than forcing a step through. Three of four errors in an earlier plan were caught this way.

## File structure

| File | Change |
|---|---|
| `woodev/shipping-method/assets/js/frontend/pickup-mount.js` | All of the pool: state, merge, reset, group build, types, empty-message gate, trim. |
| `tests/js/pickup-mount.test.js` | All new tests. |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | One config key + one filter (Task 6). |
| `tests/unit/Shipping/Pickup/PickupHandlerTest.php` | Config-key tests (Task 6). |

`map-provider-yandex.js`, `map-provider-embedded.js`, `pickup-panels.js`, `pickup-geo.js`, `pickup-datasource.js` — **do not modify.**

---

### Task 1: The pool, its merge, and groups built from it

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (session state block near `var groupsByKey = {};` ~line 1070; `fetchAndSetPoints()` ~line 1652; `start()` ~line 2624)
- Test: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing tests**

Add near the other viewport tests (after the `emptyInView` test, ~line 1707):

```js
// -------------------------------------------------------------------------
// #234 — viewport point accumulation: the drawn set is the UNION of every
// listing this session, never just the last one.
// -------------------------------------------------------------------------

test( 'viewport: two listings with disjoint points draw the UNION, not the last listing', async () => {
	const listings = [
		[ point( { id: 'A', lat: 55.1, lng: 37.1 } ) ],
		[ point( { id: 'B', lat: 55.2, lng: 37.2 } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] ).sort();

	expect( ids ).toEqual( [ 'A', 'B' ] );
} );

test( 'viewport: a point in BOTH listings appears once, carrying the SECOND listing\'s values', async () => {
	const listings = [
		[ point( { id: 'A', name: 'Старое имя' } ) ],
		[ point( { id: 'A', name: 'Новое имя' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const all = drawn.reduce( ( acc, group ) => acc.concat( group.points ), [] );

	expect( all ).toHaveLength( 1 );
	expect( all[ 0 ].name ).toBe( 'Новое имя' );
} );

test( 'bulk is unaffected — its listing still REPLACES the drawn set', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	// bulk's second fetch can only come from refresh()
	document.body.dispatchEvent( new Event( 'updated_checkout' ) );
	jest.runOnlyPendingTimers();
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'B' ] );
} );

test( 'retry (start()) drops the pool — a fresh session starts from nothing', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	let provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	panels.emit( 'retryRequested' );
	await flushAsync();

	provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'B' ] );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "#234"` and `npm run test:js -- --roots "<rootDir>/tests/js" -t "accumulation"`

Expected: the union/dedup/retry tests FAIL (the last listing replaces the set); the `bulk` test PASSES already (it pins existing behaviour so a later task cannot regress it).

- [ ] **Step 3: Add the pool state**

In the session state block, immediately after `var groupsByKey = {};` (~line 1070), add:

```js
		/**
		 * #234 — the UNION of every listing this session, by `point.id`, under
		 * `strategy: 'viewport'` ONLY. `bulk` fetches once and its listing is authoritative,
		 * so it never reads or writes this.
		 *
		 * Why the pool lives HERE and not in the provider: `setPoints()`'s own docblock
		 * already assigns cross-fetch de-duplication to the caller ("the caller is the one
		 * that decides what the current full set is"), so accumulating is this file's job
		 * and the provider contract is unchanged. That also keeps every other provider —
		 * `map-provider-embedded.js` today, anything added later — free of it.
		 *
		 * Insertion order is meaningful: it is what {@see trimPointPool} evicts by when a
		 * domain has bounded the pool. Plain object key order preserves insertion order for
		 * string keys, which every `point.id` is coerced to on the way in.
		 *
		 * MUST be cleared together with the detail memo — see {@see resetPointPool}.
		 *
		 * @type {Object.<string, Object>}
		 */
		var pointPool = {};
```

- [ ] **Step 4: Add the pool helpers**

Add beside the other session-level helpers, just before `function fetchAndSetPoints( query ) {` (~line 1652):

```js
		/**
		 * The pooled points, in insertion order.
		 *
		 * @since 2.0.2
		 * @returns {Array} never null.
		 */
		function poolValues() {
			return Object.keys( pointPool ).map( function( id ) {
				return pointPool[ id ];
			} );
		}

		/**
		 * Merges one listing into the pool and returns the full set to draw (#234).
		 *
		 * THE LISTING WINS on conflict: it is the fresher carrier record. Whatever a detail
		 * fetch already learned is re-applied AFTER grouping, from `detailsById`, so a sparse
		 * re-merged record cannot erase it — that ordering is load-bearing, see
		 * {@see fetchAndSetPoints}.
		 *
		 * A point with no `id` is passed through un-pooled rather than dropped: `id` is the
		 * pool's whole identity and a record without one cannot be de-duplicated, but it is
		 * still a point the carrier returned and the customer should see it.
		 *
		 * @since 2.0.2
		 * @param {Array} points this listing's points.
		 * @returns {Array} every point to draw.
		 */
		function mergeIntoPool( points ) {
			var passthrough = [];

			( points || [] ).forEach( function( point ) {
				if ( ! point || null === point.id || undefined === point.id ) {
					if ( point ) {
						passthrough.push( point );
					}

					return;
				}

				pointPool[ String( point.id ) ] = point;
			} );

			trimPointPool();

			return poolValues().concat( passthrough );
		}

		/**
		 * Empties the pool.
		 *
		 * INVARIANT: every caller that clears the pool must also clear the detail memo, and
		 * vice versa — {@see forgetPointDetails}. `geo.groupByPosition()` does not deep-copy,
		 * so the detail fields re-applied in {@see fetchAndSetPoints} land on the pooled point
		 * objects themselves; dropping `detailsById` while keeping the pool would strand those
		 * fields with nothing left to re-derive them from, and dropping the pool while keeping
		 * `detailsById` would re-apply a verdict computed against a cart that has since moved.
		 *
		 * @since 2.0.2
		 * @returns {void}
		 */
		function resetPointPool() {
			pointPool = {};
		}
```

Add a temporary no-op `trimPointPool` directly above `mergeIntoPool` (Task 7 fills it in):

```js
		/**
		 * Bounds the pool when the domain asked for a bound — filled in by Task 7.
		 *
		 * @since 2.0.2
		 * @returns {void}
		 */
		function trimPointPool() {
		}
```

- [ ] **Step 5: Build the groups from the pool**

In `fetchAndSetPoints()`'s resolve branch, replace the single line

```js
					var groups = geo.groupByPosition( points );
```

with

```js
					// #234: under `viewport` the drawn set is the UNION of every listing this
					// session, so the customer never loses points by zooming out and back. Under
					// `bulk` there is one listing and it is authoritative — the pool is bypassed
					// entirely rather than being a union of one, so `bulk` keeps its exact
					// previous behaviour including "a later listing REPLACES the set".
					var drawable = 'viewport' === config.strategy ? mergeIntoPool( points ) : points;
					var groups = geo.groupByPosition( drawable );
```

- [ ] **Step 6: Reset the pool on every `start()`**

In `start()`, beside the existing `lastBbox = null;` (~line 2630), add:

```js
				// #234: a retry rebuilds the provider from scratch, so the drawn set restarts
				// from nothing too. Paired with forgetPointDetails() per resetPointPool()'s
				// stated invariant.
				resetPointPool();
				forgetPointDetails();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:js -- --roots "<rootDir>/tests/js"`
Expected: PASS, and the total must be the previous total + 4. A CHANGED total in any other direction means a bad invocation, not a regression.

- [ ] **Step 8: Run the full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "feat(pickup): draw the union of every viewport listing, not just the last (#234)"
```

---

### Task 1b: The generation barrier — a reset must survive an in-flight listing

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`
- Test: `tests/js/pickup-mount.test.js`

**Why (adversarial review, 09.08.2026):** a listing already travelling when a reset fires settles
afterwards and merges its stale points into the freshly emptied pool. The continuation's only guard
today is `destroyed`, which is false here. The race exists today and is HARMLESS today — each
listing replaces the set, so the next one overwrites the damage — and accumulation is exactly what
makes it permanent. `fetchPoints()`'s debounce/supersede does NOT close it: supersede means an
earlier CALLER gets the LATER result; it says nothing about a request that had already gone out.

Modelled on `pendingSelectionToken`, which exists because the equivalent ABA hole was found in the
selection lock (s57). Keep the same shape.

- [ ] **Step 1: Write the failing tests**

```js
test( '#234: a listing already in flight when the pool is reset is DROPPED on arrival', async () => {
	let releaseFirst;
	const first = new Promise( ( resolve ) => { releaseFirst = resolve; } );
	const responses = [ first, Promise.resolve( [ point( { id: 'B' } ) ] ) ];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => responses[ call++ ] || Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	// A listing goes out and does NOT settle yet.
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// The cart changes: the pool resets and a second listing goes out and settles.
	document.body.dispatchEvent( new Event( 'updated_checkout' ) );
	jest.runOnlyPendingTimers();
	await flushAsync();

	// Only NOW does the stale first listing come back.
	releaseFirst( [ point( { id: 'STALE' } ) ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).not.toContain( 'STALE' );
} );

test( '#234: a listing in flight across NO reset still lands normally — the guard is not a '
	+ 'blanket drop', async () => {
	let releaseFirst;
	const first = new Promise( ( resolve ) => { releaseFirst = resolve; } );
	let call = 0;
	const responses = [ first ];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => responses[ call++ ] || Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	releaseFirst( [ point( { id: 'A' } ) ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'A' ] );
} );
```

- [ ] **Step 2: Run to verify**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "in flight"`
Expected: the first test FAILS (`STALE` is drawn); the second PASSES.

- [ ] **Step 3: Add the generation counter**

Beside `var pointPool = {};`:

```js
		/**
		 * Bumped by every {@see resetPointPool}. A listing captures it when it goes out and is
		 * DISCARDED on arrival if it moved — see {@see fetchAndSetPoints}.
		 *
		 * Why a counter and not a boolean "was reset": two resets can bracket one request, and a
		 * boolean cleared by the second would let the request through. Same reason
		 * `pendingSelectionToken` is a token rather than a point id (s57's ABA hole).
		 *
		 * @type {number}
		 */
		var poolGeneration = 0;
```

In `resetPointPool()`, add as the last line:

```js
			poolGeneration += 1;
```

- [ ] **Step 4: Capture and check it**

In `fetchAndSetPoints()`, immediately after `bumpLoading();`:

```js
			// #234: the generation this listing belongs to. Anything that empties the pool while
			// this request is in flight makes its answer describe a state nobody is looking at
			// any more — see the check in the resolve branch.
			var myGeneration = poolGeneration;
```

In the resolve branch, directly after the existing `if ( destroyed ) { return points; }`:

```js
					// #234: a reset happened while this was travelling. Merging now would put the
					// pre-reset carrier answer back into a pool that was deliberately emptied —
					// permanently, since nothing removes a pooled point. `dropLoading()` and
					// `clearInitialBusy()` above have already run, so the customer's spinner state
					// is correct; there is simply nothing here worth drawing. Viewport-only: `bulk`
					// does not accumulate, so its late listing is still the best answer available.
					if ( 'viewport' === config.strategy && myGeneration !== poolGeneration ) {
						return points;
					}
```

- [ ] **Step 5: Run to verify both pass**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "in flight"`
Expected: PASS

- [ ] **Step 6: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): discard a listing that was in flight across a pool reset (#234)"
```

---

### Task 2: Reset on checkout update, pinned to the details memo

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (`refresh()` ~line 2834)
- Test: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing test**

```js
test( '#234 invariant: refresh() clears the pool AND the details memo in ONE call', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	window.WoodevPickupDataSource.fetchDetails = () =>
		Promise.resolve( point( { id: 'A', work_time: 'из деталей' } ) );

	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// Learn a detail for A, so the memo is demonstrably non-empty. The payload shape is
	// `{ group, pointId, origin }` — verified against this file's existing cardOpened tests,
	// NOT guessed.
	panels.emit( 'cardOpened', {
		group: provider.setPointsCalls[ provider.setPointsCalls.length - 1 ][ 0 ],
		pointId: 'A',
		origin: 'list',
	} );
	await flushAsync();

	// The cart changes.
	document.body.dispatchEvent( new Event( 'updated_checkout' ) );
	jest.runOnlyPendingTimers();
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const all = drawn.reduce( ( acc, group ) => acc.concat( group.points ), [] );

	// Pool cleared: only the refresh listing's own point is drawn.
	expect( all.map( ( p ) => p.id ) ).toEqual( [ 'B' ] );
	// Memo cleared: nothing carries the stale detail field.
	expect( all.some( ( p ) => 'из деталей' === p.work_time ) ).toBe( false );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "invariant"`
Expected: FAIL — `A` is still drawn.

- [ ] **Step 3: Implement**

In `refresh()`, change

```js
			// THE cart-change event (#232) — see {@see forgetPointDetails} for why this is the
			// only place details are forgotten, and no longer every listing.
			forgetPointDetails();
```

to

```js
			// THE cart-change event (#232) — see {@see forgetPointDetails} for why this is the
			// only place details are forgotten, and no longer every listing.
			//
			// #234: the pool goes with it, ALWAYS, and the two calls must never be separated —
			// see {@see resetPointPool}'s stated invariant. A pooled point's `selectable` was
			// computed against the cart that just changed.
			forgetPointDetails();
			resetPointPool();
```

- [ ] **Step 4: Run to verify it passes**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "invariant"`
Expected: PASS

- [ ] **Step 5: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): drop the accumulated pool whenever the cart changes (#234)"
```

---

### Task 3: Reset on a viewport type-filter change

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (`typeFilterChange` handler ~line 2360)
- Test: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing tests**

```js
test( '#234: a viewport type-filter change drops the pool — the server filters, so a union '
	+ 'across different filters would be incoherent', async () => {
	const listings = [
		[ point( { id: 'A', type: { code: 'pvz', label: 'ПВЗ' } } ) ],
		[ point( { id: 'B', type: { code: 'postamat', label: 'Постамат' } } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	panels.emit( 'typeFilterChange', [ 'postamat' ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'B' ] );
} );

test( '#234: a BULK type-filter change does not touch the pool or refetch — it still filters '
	+ 'client-side through the provider', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [ point( { id: 'A' } ) ] ) );
	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	const drawsBefore = provider.setPointsCalls.length;

	panels.emit( 'typeFilterChange', [ 'postamat' ] );
	await flushAsync();

	expect( provider.setTypeFilterCalls ).toEqual( [ [ 'postamat' ] ] );
	expect( provider.setPointsCalls.length ).toBe( drawsBefore );
} );
```

- [ ] **Step 2: Run to verify**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "type-filter"`
Expected: the viewport test FAILS (`A` is still drawn); the bulk test PASSES already.

- [ ] **Step 3: Implement**

In the `typeFilterChange` handler, replace

```js
				// viewport: a client-side filter would show stale points outside the current
				// bbox — refetch with the SAME bbox and the new types instead (see the file
				// docblock's judgement-call note on getting this backwards).
				if ( lastBbox ) {
```

with

```js
				// viewport: a client-side filter would show stale points outside the current
				// bbox — refetch with the SAME bbox and the new types instead (see the file
				// docblock's judgement-call note on getting this backwards).
				//
				// #234: the pool goes first. The SERVER is what applies the type filter on this
				// strategy, so points pooled under the previous filter are not a subset of the
				// new answer — a union across two different filters describes no query anyone
				// made. Paired with forgetPointDetails() per resetPointPool()'s invariant.
				resetPointPool();
				forgetPointDetails();

				if ( lastBbox ) {
```

- [ ] **Step 4: Run to verify it passes**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "type-filter"`
Expected: PASS

- [ ] **Step 5: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): drop the pool when the viewport type filter changes (#234)"
```

---

### Task 4: Type chips come from the pool

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (`fetchAndSetPoints()`)
- Test: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing test**

```js
test( '#234: the type chips are computed from the POOL, so they do not flicker as the '
	+ 'customer pans', async () => {
	const listings = [
		[ point( { id: 'A', type: { code: 'pvz', label: 'ПВЗ' } } ) ],
		[ point( { id: 'B', type: { code: 'postamat', label: 'Постамат' } } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	expect( panels.lastTypes ).toEqual( [
		{ code: 'pvz', label: 'ПВЗ' },
		{ code: 'postamat', label: 'Постамат' },
	] );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "type chips"`
Expected: FAIL — only `Постамат` is present (types came from the last listing).

- [ ] **Step 3: Implement**

Replace

```js
					if ( panels ) {
						panels.setTypes( extractTypes( points ) );
					}
```

with

```js
					if ( panels ) {
						// #234: from the DRAWN set, not this listing — a chip that vanishes
						// because the customer panned past the last point of its type, while
						// points of that type are still drawn, is the same defect this issue
						// is about, one surface over.
						panels.setTypes( extractTypes( drawable ) );
					}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "type chips"`
Expected: PASS

- [ ] **Step 5: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): compute the type chips from the drawn set, not the last listing (#234)"
```

---

### Task 5: "Nothing in this area" must not print over a map that shows points

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (session state; the `visibleChange` handler ~line 2678; `fetchAndSetPoints()`'s empty branch)
- Test: `tests/js/pickup-mount.test.js`

**Why:** rig-measured 09.08.2026 — a live listing for the Ryazan frame returned 0 points after 16.9 s while neighbouring frames returned 1 500+. Driving the message off `points.length` alone would print «в этой области нет точек» over a map still drawing pooled markers. The decision must use the ONE definition of "in frame" that already exists — the provider's `visibleChange` — never a second inequality chain of our own (see `_groupsInsideBounds`'s docblock on why that was unified in #167).

- [ ] **Step 1: Write the failing tests**

```js
test( '#234: an empty listing does NOT show emptyInView while pooled points are still in frame', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// The provider reports A is on screen — this is the mount's only source for "in frame".
	const drawnKey = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ][ 0 ].key;
	provider.emit( 'visibleChange', [ drawnKey ] );

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	expect( panels.showMessageCalls ).toBeUndefined();
} );

test( '#234: an empty listing DOES show emptyInView when nothing is in frame', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'visibleChange', [] );
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	expect( panels.showMessageCalls ).toEqual( [ 'emptyInView' ] );
} );
```

- [ ] **Step 2: Run to verify**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "emptyInView"`
Expected: the first test FAILS (`emptyInView` is shown); the second PASSES.

- [ ] **Step 3: Track what the provider says is in frame**

In the session state block, after `var pointPool = {};`, add:

```js
		/**
		 * How many groups the provider last reported as being inside the frame (#234).
		 *
		 * The mount deliberately does NOT compute this itself: `_groupsInsideBounds()` is the
		 * ONE definition of "in frame" in this codebase, unified in #167 precisely so two
		 * inequality chains over the same rectangle cannot disagree. This is a cached read of
		 * that answer, not a second opinion.
		 *
		 * @type {number}
		 */
		var visibleGroupCount = 0;
```

In the existing `visibleChange` handler, record it — the handler becomes:

```js
					provider.on( 'visibleChange', function( keys ) {
						var groups = ( keys || [] )
							.map( function( key ) { return groupsByKey[ key ]; } )
							.filter( function( group ) { return !! group; } );

						// #234: what the empty-frame message is decided from — see
						// {@see visibleGroupCount}. Counted from the RAW keys, not the mapped
						// groups: a key the mount cannot resolve still means the provider is
						// drawing something there, and suppressing the message is the safe
						// direction (a missing message beats a false one over a full map).
						visibleGroupCount = ( keys || [] ).length;

						panels.setVisible( groups );
					} );
```

Reset it in `start()`, beside `resetPointPool()`:

```js
				visibleGroupCount = 0;
```

- [ ] **Step 4: Gate the message**

In `fetchAndSetPoints()`, replace the `else` branch

```js
					} else {
```

…and its `showFetchMessage(...)` call with:

```js
					} else if ( 'bulk' === config.strategy || 0 === visibleGroupCount ) {
						// `emptyLocality` (a locality genuinely has none) vs `emptyInView` (the
						// current viewport does) — the SAME shared function backs both the bulk
						// strategy's one-shot fetch and the viewport strategy's per-bbox
						// `boundsChange` fetch, so the key is chosen from `config.strategy`, not
						// hardcoded to either. Distinct from `noResults`, which stays reserved for
						// the search view finding nothing (spec V-5).
						//
						// #234: under `viewport` an empty listing no longer means an empty screen.
						// A listing can come back empty for a frame the pool still has points in —
						// rig-measured on live Russian Post, where one frame answered 0 after 16.9s
						// while its neighbours answered 1500+. Printing "nothing here" over drawn
						// markers is worse than printing nothing, so the message waits until the
						// provider itself reports an empty frame. `bulk` keeps its old behaviour
						// exactly: it has no frame-driven refetch to correct a wrong message later.
						showFetchMessage( 'bulk' === config.strategy ? 'emptyLocality' : 'emptyInView' );
					}
```

- [ ] **Step 5: Run to verify both pass**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "emptyInView"`
Expected: PASS

- [ ] **Step 6: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): never print 'nothing in this area' over a map that is drawing points (#234)"
```

---

### Task 6: The `maxAccumulatedPoints` config seam (PHP)

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php` (`get_js_config()` ~line 1236)
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

**Why a seam with no consumer:** the default is unlimited and rig-measured safe for Russian Post (§4 of the spec). How dense a carrier is, is domain knowledge — the framework owns the mechanism and the knob, the plugin owns the number. "No consumer yet" is not an argument against a hook in this codebase.

- [ ] **Step 1: Write the failing tests**

Follow the file's existing config-assertion tests for setup. Add:

```php
	/** @test */
	public function js_config_defaults_max_accumulated_points_to_zero_meaning_unlimited(): void {
		$handler = $this->make_handler();

		$config = $handler->get_js_config();

		$this->assertSame( 0, $config['maxAccumulatedPoints'] );
	}

	/** @test */
	public function js_config_max_accumulated_points_is_filterable_and_never_negative(): void {
		$handler = $this->make_handler();

		add_filter(
			'woodev_pickup_max_accumulated_points',
			static function () {
				return -5;
			}
		);

		$config = $handler->get_js_config();

		$this->assertSame( 0, $config['maxAccumulatedPoints'] );
	}
```

```php
	/** @test */
	public function js_config_passes_a_positive_max_accumulated_points_through(): void {
		$handler = $this->make_handler();

		add_filter(
			'woodev_pickup_max_accumulated_points',
			static function () {
				return 3000;
			}
		);

		$config = $handler->get_js_config();

		$this->assertSame( 3000, $config['maxAccumulatedPoints'] );
	}
```

**If `make_handler()` is not this test class's actual helper name, use whatever the file already
uses to build a handler — do not add a new helper.**

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:unit -- --filter PickupHandlerTest`
Expected: FAIL — undefined index `maxAccumulatedPoints`.

- [ ] **Step 3: Implement**

In `get_js_config()`, beside the `strategy` key, add:

```php
				/**
				 * Caps how many points the browser accumulates across viewport listings (#234).
				 *
				 * `0` — the default — means UNLIMITED, which is measured-safe: with the pool at
				 * 20 000 points a full redraw costs 334 ms against a listing that takes 6-13 s to
				 * arrive, and the one superlinear operation in the map provider is reached only by
				 * the `bulk` strategy. A realistic session over a whole region pools ~1 300 points.
				 *
				 * The knob exists because point DENSITY is domain knowledge, not framework
				 * knowledge: a carrier far denser than Russian Post can bound the pool here
				 * without any redesign. Ignored entirely by `strategy: 'bulk'`, which never
				 * accumulates.
				 *
				 * @since 2.0.2
				 *
				 * @param int    $max       0 for unlimited; a positive point count to bound the pool.
				 * @param string $plugin_id the plugin the map belongs to.
				 */
				$max_accumulated = (int) apply_filters(
					'woodev_pickup_max_accumulated_points',
					0,
					$this->plugin_id
				);

				// A negative bound is meaningless and must not reach the browser as one — it
				// would read as "keep nothing", silently reinstating the very defect #234 fixed.
				$max_accumulated = max( 0, $max_accumulated );
```

…then add `'maxAccumulatedPoints' => $max_accumulated,` to the returned array, directly under `'strategy'`.

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:unit -- --filter PickupHandlerTest`
Expected: PASS

- [ ] **Step 5: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/pickup/class-pickup-handler.php tests/unit/Shipping/Pickup/PickupHandlerTest.php
git commit -m "feat(pickup): filterable cap on accumulated viewport points, unlimited by default (#234)"
```

---

### Task 7: The trim itself (JS)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (`trimPointPool()` stub from Task 1)
- Test: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing tests**

```js
test( '#234 cap: with maxAccumulatedPoints set, the OLDEST-seen points are evicted first', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
		[ point( { id: 'C' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport', maxAccumulatedPoints: 2 } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] ).sort();

	expect( ids ).toEqual( [ 'B', 'C' ] );
} );

test( '#234 cap: the customer\'s CURRENT SELECTION is never evicted, however old', async () => {
	// Seed the field with A so it is the current selection from the first listing on.
	document.getElementById( FIELD_ID ).value = 'A';

	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
		[ point( { id: 'C' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport', maxAccumulatedPoints: 2 } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toContain( 'A' );
} );

test( '#234 cap: an unset (0) cap keeps everything', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
		[ point( { id: 'C' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] ).sort();

	expect( ids ).toEqual( [ 'A', 'B', 'C' ] );
} );
```

- [ ] **Step 2: Run to verify**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "cap"`
Expected: the first two FAIL; the third PASSES (the stub is a no-op today).

- [ ] **Step 3: Implement the trim**

Replace the Task 1 stub with:

```js
		/**
		 * Bounds the pool to `config.maxAccumulatedPoints` when the domain asked for a bound
		 * (#234). A missing/zero/negative value means UNLIMITED and this returns immediately —
		 * that is the shipped default, and it is measured-safe rather than assumed: see the
		 * design doc's measurement table.
		 *
		 * Evicts OLDEST-INSERTED first (plain object key order for string keys), skipping any
		 * point the customer would notice losing:
		 *
		 *  - the current selection — the field's own value; losing it would strand the
		 *    checkout on a point the map can no longer draw;
		 *  - the open card's point — {@see cardPointId}; a card whose point vanished mid-read
		 *    is the #232 defect wearing a different hat.
		 *
		 * "In frame" is deliberately NOT a third exemption: it would need a rectangle test of
		 * our own, and the frame is exactly where a re-listing puts points back a moment later
		 * anyway.
		 *
		 * @since 2.0.2
		 * @returns {void}
		 */
		function trimPointPool() {
			var max = parseInt( config.maxAccumulatedPoints, 10 );

			if ( ! max || max < 1 ) {
				return;
			}

			var ids = Object.keys( pointPool );
			var over = ids.length - max;

			if ( over < 1 ) {
				return;
			}

			var selected = fieldValue( config.fieldId );
			var protectedIds = {};

			if ( selected ) {
				protectedIds[ String( selected ) ] = true;
			}

			if ( cardPointId ) {
				protectedIds[ String( cardPointId ) ] = true;
			}

			ids.forEach( function( id ) {
				if ( over < 1 || Object.prototype.hasOwnProperty.call( protectedIds, id ) ) {
					return;
				}

				delete pointPool[ id ];
				over -= 1;
			} );
		}
```

- [ ] **Step 4: Run to verify all three pass**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "cap"`
Expected: PASS

- [ ] **Step 5: Full set and commit**

```bash
composer test:unit && composer phpcs && composer phpstan
npm run test:js -- --roots "<rootDir>/tests/js"
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "feat(pickup): honour maxAccumulatedPoints, protecting the selection and open card (#234)"
```

---

### Task 8: Restore finds a pooled point the last listing does not contain

**Files:**
- Test only: `tests/js/pickup-mount.test.js`

This is a behaviour the pool GIVES us for free (`restoreSelection()`/`pendingRestoreGroup()` read `groupsByKey`, which is now built from the pool). It needs a test so a later refactor cannot silently take it away.

- [ ] **Step 1: Write the test**

```js
test( '#234: a previously chosen point is still restorable from the pool after the customer '
	+ 'panned to a frame whose listing does not contain it', async () => {
	document.getElementById( FIELD_ID ).value = 'A';

	const listings = [
		[ point( { id: 'A', lat: 55.1, lng: 37.1 } ) ],
		[ point( { id: 'B', lat: 60.0, lng: 30.0 } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 59, 29, 61, 31 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toContain( 'A' );

	// …and the SECOND listing must not have re-focused the camera onto that pooled group.
	// The adversarial review flagged this as a possible camera jump: with groups now built
	// from the pool, `pendingRestoreGroup()` can find a chosen point that the current frame's
	// listing does not contain. It is unreachable because `selectionRestoreAttempted` is
	// claimed on the FIRST listing (which is where the pool still equals that listing), but
	// "unreachable" is a property that must be pinned, not assumed.
	const lastOptions = provider.setPointsOptions[ provider.setPointsOptions.length - 1 ];
	expect( lastOptions ).toBeFalsy();
} );
```

- [ ] **Step 2: Run it**

Run: `npm run test:js -- --roots "<rootDir>/tests/js" -t "restorable"`
Expected: PASS (Task 1 already delivers this). If it FAILS, STOP and report — it means the pool is not reaching `groupsByKey`.

- [ ] **Step 3: Commit**

```bash
git add tests/js/pickup-mount.test.js
git commit -m "test(pickup): pin that a chosen point stays restorable from the pool (#234)"
```

---

### Task 9: (removed — see below)

**Deliberately NOT in this plan.** The original Task 9 was "document the new filter in
`docs/shipping-method.md`". Checked before writing it: the public docs document **none** of the
existing pickup filters (`woodev_pickup_accent_color`, `woodev_pickup_accent_contrast_color`,
`woodev_pickup_accent_fill_color`, `woodev_pickup_map_point_glyphs`,
`woodev_pickup_map_search_enabled`) — the whole pickup extension surface is undocumented publicly.

Adding one filter into that vacuum would be inconsistent, and documenting the surface properly is
a separate piece of work with its own scope. Tracked as its own card instead. Do not write partial
public docs as part of #234.

---

## After the last task

1. **Rig verification (mine, not a worker's).** On this branch, live Russian Post, `:8973`: draw a frame, zoom in, zoom out — the earlier points must be back. Then pan away and back. Then change the type filter and confirm the pool resets. Then trigger a checkout update and confirm it resets.
2. **Codex critic** on the full diff, inline bundle, ≤~12 KB per bundle.
3. PR, every CI job verified pass + CLEAN **separately**, then `gh pr merge --squash --delete-branch`. Never `--auto`.

# SP-5 Pickup Map — Visual Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the pickup map's presentation layer so it matches the Yandex.Delivery and Russian
Post references the operator named, without touching any architectural or data contract.

**Architecture:** The modal shell gains its own size and `wc-backbone-modal` parity. A new
`.woodev-pickup-stage` element inside the modal body becomes the positioning context for every panel
and control, replacing `position: fixed` against the transformed dialog. Search, type filter and zoom
become our own DOM controls; ymaps keeps only the map, the `ObjectManager` and the geocoder. Markers
gain a hit area and framework-default inline-SVG icons.

**Tech Stack:** Vanilla ES5-safe JS (no build step for these files), plain CSS, PHP 7.4+, Yandex Maps
JS API 2.1, jest (`wp-scripts test-unit-js`), PHPUnit (Brain Monkey), phpcs (WPCS).

**Authority:** `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` (V-1…V-16),
which extends `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` (D-1…D-15). Where they
disagree, the newer one wins for presentation. **Put both specs in the critic's bundle.**

**Branch:** `feat/pickup-map` (PR #149, do not merge until the operator accepts the visuals).

---

## File structure

| File | Responsibility after this plan |
|---|---|
| `woodev/assets/js/frontend/woodev-modal.js` | Dialog chrome only. Now also applies a consumer-supplied size at construction and renders a spinner in its loading overlay. |
| `woodev/assets/css/frontend/woodev-modal.css` | Dialog chrome styling at `wc-backbone-modal` parity. |
| `woodev/shipping-method/assets/js/frontend/pickup-panels.js` | Owns the stage and every piece of DOM inside it: controls row (search + filter), zoom, sidebar list, point card, overlay. |
| `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js` | The map only: canvas, `ObjectManager`, features, camera moves, geocoding calls. No control chrome, no balloon. |
| `woodev/shipping-method/assets/css/frontend/pickup.css` | Everything inside the stage, plus the style-isolation contract. |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | Emits modal size, the `search` flag and the filtered `i18n` map. |
| `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php` | A fixture rich enough to exercise the filter, the group tab bar, clusters, a long list and an unavailable point. |

`pickup-panels.js` is ~1,420 lines today and will grow. It is **not** split in this plan: the s48
measurement stands (619 lines of code against 589 of docblock), and splitting it while every one of
its surfaces is being rewritten would make each review diff unreadable. Re-measure after T19; if the
code half passes ~900 lines, file a card.

---

## Phase 0 — make the work observable

### Task 1: Extend the rig fixture

Five points of one type cannot show the type filter, the co-located tab bar, a cluster badge, a
scrolling list or a disabled select button. Every later task in this plan is verified against this
fixture, so it comes first (spec V-16, issue #158).

**Files:**
- Modify: `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php:254` (`Woodev_Test_Bulk_Point_Source::all_points()`)
- Test: `tests/unit/Shipping/Pickup/TestFixturePointsTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Shipping/Pickup/TestFixturePointsTest.php`:

```php
<?php

namespace Woodev\Framework\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Tests\Unit\TestCase;

/**
 * The rig fixture is a test surface in its own right: five of the map's surfaces are
 * unreachable unless it supplies the shapes below (spec V-16).
 */
class TestFixturePointsTest extends TestCase {

	/** @return array<int, array<string, mixed>> */
	private function points(): array {
		$path = dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/fixture-points.php';

		return require $path;
	}

	public function test_supplies_at_least_two_distinct_types(): void {
		$codes = array_unique( array_column( array_column( $this->points(), 'type' ), 'code' ) );

		$this->assertGreaterThanOrEqual( 2, count( $codes ) );
		$this->assertContains( 'PVZ', $codes );
		$this->assertContains( 'POSTAMAT', $codes );
	}

	public function test_supplies_enough_points_to_cluster_and_scroll(): void {
		$this->assertGreaterThanOrEqual( 40, count( $this->points() ) );
	}

	public function test_contains_a_pair_on_identical_coordinates(): void {
		$seen  = [];
		$pairs = 0;

		foreach ( $this->points() as $point ) {
			$key = $point['lat'] . ',' . $point['lng'];

			if ( isset( $seen[ $key ] ) ) {
				$pairs++;
			}

			$seen[ $key ] = true;
		}

		$this->assertGreaterThanOrEqual( 1, $pairs );
	}

	public function test_contains_one_point_that_refuses_cod(): void {
		$refusing = array_filter( $this->points(), static fn( array $p ): bool => false === $p['accepts_cod'] );

		$this->assertNotEmpty( $refusing );
	}

	public function test_covers_present_and_absent_optional_sections(): void {
		$points = $this->points();

		$with_services    = array_filter( $points, static fn( array $p ): bool => ! empty( $p['services'] ) );
		$without_services = array_filter( $points, static fn( array $p ): bool => empty( $p['services'] ) );
		$without_phone    = array_filter( $points, static fn( array $p ): bool => '' === $p['phone'] );
		$with_weight      = array_filter( $points, static fn( array $p ): bool => null !== $p['max_weight'] );

		$this->assertNotEmpty( $with_services );
		$this->assertNotEmpty( $without_services );
		$this->assertNotEmpty( $without_phone );
		$this->assertNotEmpty( $with_weight );
	}

	public function test_contains_a_long_address_for_ellipsis_testing(): void {
		$long = array_filter( $this->points(), static fn( array $p ): bool => mb_strlen( $p['address'] ) >= 80 );

		$this->assertNotEmpty( $long );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/TestFixturePointsTest.php`
Expected: FAIL — `fixture-points.php` does not exist.

- [ ] **Step 3: Extract the point list into its own file and grow it**

Create `tests/_fixtures/woodev-test-shipping-method/fixture-points.php` returning an array of point
payloads. Keep the five existing points **byte-identical, ids included** — `FIX-BULK-1`,
`Woodev_Test_Bulk_Point_Source::COD_REFUSING_POINT_ID`, `FIX-BULK-3`,
`::WEIGHT_LIMITED_POINT_ID`, `FIX-BULK-5` — because rig state and older session notes reference them.
Then add:

- **35 more `PVZ` points** spread across Moscow inside roughly `lat 55.60–55.90`, `lng 37.35–37.85`,
  so the map clusters at city zoom and the sidebar scrolls. Vary `payment_methods`, and leave
  `phone` as `''` on at least three of them.
- **6 `POSTAMAT` points** with `'type' => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ]`.
- **One co-located pair**: a `PVZ` and a `POSTAMAT` on *identical* `lat`/`lng`
  (`55.7415`, `37.6156`), ids `FIX-BULK-COLOCATED-PVZ` and `FIX-BULK-COLOCATED-POSTAMAT`. This is the
  CDEK case — a pickup point and a postamat in one building.
- **One point with a deliberately long address** (≥ 80 characters), id `FIX-BULK-LONG-ADDRESS`.
- **`services`** populated on at least five points, e.g.
  `[ 'Примерка', 'Проверка вложений', 'Частичный выкуп' ]`, and absent on the rest.
- **`max_weight`** set on `FIX-BULK-WEIGHT` (already exists) and left `null` elsewhere.

Every payload keeps the exact key set the existing five use, plus `services`.

- [ ] **Step 4: Point the source at the new file**

In `woodev-test-shipping-method.php`, replace the body of `all_points()`:

```php
private function all_points(): array {
	return require __DIR__ . '/fixture-points.php';
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/TestFixturePointsTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Run the full unit suite**

Run: `composer test:unit`
Expected: PASS. The fixture is loaded by other tests; a changed shape must not break them.

- [ ] **Step 7: Add the POSTAMAT icons**

The fixture already declares `'POSTAMAT' => [...]` icon paths at
`woodev-test-shipping-method.php:576`. Confirm both files exist under
`tests/_fixtures/woodev-test-shipping-method/assets/images/`; if either is missing, add a real SVG
(a locker glyph, 45×45 viewBox, single colour) — a missing file renders a broken image, not a
fallback.

Run: `ls tests/_fixtures/woodev-test-shipping-method/assets/images/`
Expected: four files — default and active for each of `PVZ` and `POSTAMAT`.

- [ ] **Step 8: Commit**

```bash
git add tests/_fixtures/woodev-test-shipping-method tests/unit/Shipping/Pickup/TestFixturePointsTest.php
git commit -m "test(fixture): grow the pickup fixture to cover filter, groups, clusters and refusals"
```

---

## Phase 1 — the modal shell

### Task 2: The modal owns its size

**Files:**
- Modify: `woodev/assets/js/frontend/woodev-modal.js:60` (`buildDom`)
- Test: `tests/js/woodev-modal.test.js`

- [ ] **Step 1: Write the failing test**

Append to `tests/js/woodev-modal.test.js`:

```js
describe( 'dialog sizing (spec V-1)', () => {
	it( 'applies the supplied size before any content is mounted', () => {
		const modal = new WoodevModal( {
			title: 'Выберите пункт выдачи',
			width: 920,
			bodyHeight: 'min(80vh, 800px)',
		} );

		modal.open();

		const dialog = document.querySelector( '.woodev-modal__content' );
		const body = document.querySelector( '.woodev-modal__body' );

		expect( dialog.style.minWidth ).toBe( '920px' );
		expect( body.style.height ).toBe( 'min(80vh, 800px)' );
		expect( body.children ).toHaveLength( 0 );
	} );

	it( 'accepts a CSS length string for the width', () => {
		const modal = new WoodevModal( { title: 'x', width: '60rem' } );

		modal.open();

		expect( document.querySelector( '.woodev-modal__content' ).style.minWidth ).toBe( '60rem' );
	} );

	it( 'sets no inline size when none is supplied', () => {
		const modal = new WoodevModal( { title: 'x' } );

		modal.open();

		expect( document.querySelector( '.woodev-modal__content' ).style.minWidth ).toBe( '' );
		expect( document.querySelector( '.woodev-modal__body' ).style.height ).toBe( '' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=woodev-modal -t "dialog sizing"`
Expected: FAIL — `minWidth` is `''`.

- [ ] **Step 3: Implement**

In the `WoodevModal` constructor, read and store the two options next to the existing ones:

```js
this._width = options.width || null;
this._bodyHeight = options.bodyHeight || null;
```

In `buildDom( self )`, immediately after `body.className = 'woodev-modal__body';`:

```js
	// The dialog sizes itself at construction, not when a consumer mounts content (V-1).
	// An empty modal is the same box as a full one — before this, the only source of height
	// in the whole tree was the map element, so the dialog opened as a header-tall strip and
	// every absolutely/fixed-positioned child landed inside the header.
	if ( self._width ) {
		dialog.style.minWidth = 'number' === typeof self._width ? self._width + 'px' : self._width;
	}

	if ( self._bodyHeight ) {
		body.style.height = 'number' === typeof self._bodyHeight
			? self._bodyHeight + 'px'
			: self._bodyHeight;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx wp-scripts test-unit-js --testPathPattern=woodev-modal`
Expected: PASS, whole file.

- [ ] **Step 5: Commit**

```bash
git add woodev/assets/js/frontend/woodev-modal.js tests/js/woodev-modal.test.js
git commit -m "feat(modal): size the dialog at construction so it opens full-size without content"
```

---

### Task 3: Modal chrome — SVG close icon, clipped body, real spinner

**Files:**
- Modify: `woodev/assets/js/frontend/woodev-modal.js:85` (close button), the loading overlay method
- Test: `tests/js/woodev-modal.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'modal chrome (spec V-2, V-4)', () => {
	it( 'renders an SVG close icon, not a text glyph', () => {
		new WoodevModal( { title: 'x' } ).open();

		const close = document.querySelector( '.woodev-modal__close' );

		expect( close.querySelector( 'svg' ) ).not.toBeNull();
		expect( close.textContent.trim() ).toBe( '' );
		expect( close.getAttribute( 'aria-label' ) ).toBeTruthy();
	} );

	it( 'renders a spinner element in the loading overlay', () => {
		const modal = new WoodevModal( { title: 'x' } );

		modal.open();
		modal.showLoading( 'Загрузка…' );

		const overlay = document.querySelector( '.woodev-modal__loading' );

		expect( overlay.querySelector( '.woodev-modal__spinner' ) ).not.toBeNull();
		expect( overlay.textContent ).toContain( 'Загрузка…' );
	} );

	it( 'keeps the close button usable while loading', () => {
		const modal = new WoodevModal( { title: 'x' } );
		const onClose = jest.fn();

		modal.on( 'close', onClose );
		modal.open();
		modal.showLoading( 'Загрузка…' );
		document.querySelector( '.woodev-modal__close' ).click();

		expect( onClose ).toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=woodev-modal -t "modal chrome"`
Expected: FAIL — no `svg` inside the close button.

- [ ] **Step 3: Implement**

Replace `closeButton.textContent = '×';` with the reference's icon (same path data as
`plugins-reference/woocommerce-yandex-delivery/templates/html-modal-map.php:15`):

```js
	closeButton.innerHTML =
		'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">' +
		'<path d="M18.2951 7.11498C18.6845 6.72562 18.6845 6.09435 18.2951 5.70498C17.9057 5.31562 ' +
		'17.2745 5.31562 16.8851 5.70498L12.0001 10.59L7.11511 5.70498C6.72575 5.31562 6.09447 ' +
		'5.31562 5.70511 5.70498C5.31575 6.09435 5.31575 6.72562 5.70511 7.11498L10.5901 12L5.70511 ' +
		'16.885C5.31575 17.2743 5.31575 17.9056 5.70511 18.295C6.09447 18.6843 6.72575 18.6843 ' +
		'7.11511 18.295L12.0001 13.41L16.8851 18.295C17.2745 18.6843 17.9057 18.6843 18.2951 ' +
		'18.295C18.6845 17.9056 18.6845 17.2743 18.2951 16.885L13.4101 12L18.2951 7.11498Z" ' +
		'fill="currentColor"/></svg>';
```

In the loading overlay builder, prepend a spinner node before the message text:

```js
		var spinner = document.createElement( 'span' );
		spinner.className = 'woodev-modal__spinner';
		spinner.setAttribute( 'aria-hidden', 'true' );
		overlay.appendChild( spinner );
```

The overlay stays additive and stays **below** the header in stacking terms — it is a child of the
body, so the close button is never covered.

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx wp-scripts test-unit-js --testPathPattern=woodev-modal`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/assets/js/frontend/woodev-modal.js tests/js/woodev-modal.test.js
git commit -m "feat(modal): SVG close icon and a real spinner in the loading overlay"
```

---

### Task 4: Modal stylesheet at `wc-backbone-modal` parity

No jest coverage — jsdom does not render. This task is verified by reading the diff against the
reference and, at T20, in the browser.

**Files:**
- Modify: `woodev/assets/css/frontend/woodev-modal.css`

- [ ] **Step 1: Bring the frame to parity**

Reference: `plugins-reference/woocommerce-yandex-delivery/assets/css/frontend/backbone-modal.css`.
Keep our own `rgba()` backdrop (gotcha `modal-backdrop-opacity-dims-the-whole-dialog`) — do **not**
copy the reference's `opacity: 0.7`.

Change in `.woodev-modal__content`: add `overflow: hidden` is already present — keep. Leave
`min-width: 920px` as the CSS fallback for consumers that pass no size.

Change in `.woodev-modal__body`: `overflow: hidden` **replaces** `overflow: auto`. Nothing a consumer
mounts may escape the frame (operator's schema); a consumer that needs scrolling scrolls its own
inner element.

- [ ] **Step 2: Style the close button's icon**

```css
.woodev-modal__close svg {
	display: block;
	width: 24px;
	height: 24px;
}
```

- [ ] **Step 3: Style the spinner**

```css
.woodev-modal__loading {
	flex-direction: column;
	gap: 12px;
	background: rgba( 255, 255, 255, 0.9 );
}

.woodev-modal__spinner {
	display: block;
	width: 28px;
	height: 28px;
	border: 2px solid rgba( 0, 0, 0, 0.12 );
	border-top-color: #06aedd;
	border-radius: 50%;
	animation: woodev-modal-spin 0.8s linear infinite;
}

@keyframes woodev-modal-spin {
	to { transform: rotate( 360deg ); }
}

@media ( prefers-reduced-motion: reduce ) {
	.woodev-modal__spinner { animation-duration: 3s; }
}
```

- [ ] **Step 4: Verify no stylesheet syntax error**

Run: `npx stylelint woodev/assets/css/frontend/woodev-modal.css --no-fix 2>&1 | tail -5`
Expected: no parse errors. (If stylelint is not configured in this repo, skip — the browser pass at
T20 is the real gate.)

- [ ] **Step 5: Commit**

```bash
git add woodev/assets/css/frontend/woodev-modal.css
git commit -m "style(modal): wc-backbone-modal parity, clipped body, spinner"
```

---

## Phase 2 — the stage

### Task 5: Introduce `.woodev-pickup-stage` and take every panel off `position: fixed`

This is the structural fix behind П-1, П-6 and П-7 at once.

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js:1055` (`Panels.prototype.render`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css:164, 337, 500`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'stage geometry (spec V-3)', () => {
	it( 'wraps every panel in a single stage element', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-map' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-card' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-list__toggle' ) ).not.toBeNull();
	} );

	it( 'puts nothing directly in the container except the stage', () => {
		const container = document.createElement( 'div' );

		new Panels( container, config ).render();

		expect( container.children ).toHaveLength( 1 );
		expect( container.firstElementChild.className ).toContain( 'woodev-pickup-stage' );
	} );

	it( 'exposes the map element through getMapElement()', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		expect( panels.getMapElement().className ).toContain( 'woodev-pickup-map' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "stage geometry"`
Expected: FAIL — no `.woodev-pickup-stage`.

- [ ] **Step 3: Implement the stage in `render()`**

Build one stage element and append every panel to it instead of to the container:

```js
	var stage = document.createElement( 'div' );
	stage.className = 'woodev-pickup-stage';

	// Order matters — it is the paint order: map, controls, panels, toggle, overlay.
	stage.appendChild( self._mapEl );
	stage.appendChild( self._controlsEl );
	stage.appendChild( self._zoomEl );
	stage.appendChild( self._listEl );
	stage.appendChild( self._cardEl );
	stage.appendChild( self._toggleEl );
	stage.appendChild( self._overlayEl );

	empty( self._container );
	self._container.appendChild( stage );

	self._stage = stage;
```

Add the accessor the provider will use in T10:

```js
	/**
	 * The element the map provider mounts its canvas into.
	 *
	 * @since 2.0.2
	 * @returns {HTMLElement}
	 */
	Panels.prototype.getMapElement = function() {
		return this._mapEl;
	};
```

- [ ] **Step 4: Re-position the panels in CSS**

In `pickup.css`, replace `position: fixed` with `position: absolute` on
`.woodev-pickup-list`, `.woodev-pickup-card` and `.woodev-pickup-list__toggle`, and add the stage:

```css
/*
 * The stage is the positioning context for everything this feature draws. Panels used to be
 * `position: fixed`, which resolves against `.woodev-modal__content` because that element carries
 * a `transform` — so the sidebar covered the modal header and, before the map gave the dialog a
 * height, the toggle button landed on top of the close button (spec V-3).
 */
.woodev-pickup-stage {
	position: absolute;
	inset: 0;
	overflow: hidden;
}

.woodev-pickup-map {
	position: absolute;
	inset: 0;
	width: auto;
	height: auto;
	min-height: 0;
}

.woodev-pickup-list,
.woodev-pickup-card {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	width: 100%;
	max-width: min( 320px, calc( 100% - 48px ) );
}

.woodev-pickup-card { z-index: 3; }
.woodev-pickup-list { z-index: 2; }

.woodev-pickup-list__toggle {
	position: absolute;
	right: 16px;
	bottom: 32px;
	z-index: 4;
}
```

Note the `.woodev-pickup-map` change: its height now comes from the stage, not from
`min( 80vh, 800px )` — that value moves to the modal's `bodyHeight` in T18's handler change.

- [ ] **Step 5: Run the panels suite**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels`
Expected: PASS. Existing tests that query `container.querySelector( '.woodev-pickup-list' )` keep
passing because `querySelector` is not depth-limited.

- [ ] **Step 6: Run the full jest suite**

Run: `npm run test:js`
Expected: PASS. `pickup-mount.test.js` mounts panels and may assert on container children.

- [ ] **Step 7: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/pickup-panels.test.js
git commit -m "fix(pickup): give the panels their own stage instead of positioning against the dialog"
```

---

### Task 6: One toggle, one open state, both panels

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js:1344` (`toggleList`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'sidebar toggle (spec V-3, П-7)', () => {
	it( 'hides the card as well as the list', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group ] );
		panels.openCard( group, group.points[ 0 ].id );
		expect( container.querySelector( '.woodev-pickup-stage' ).className ).toContain( 'is-open' );

		panels.toggleList();

		const stage = container.querySelector( '.woodev-pickup-stage' );
		expect( stage.className ).not.toContain( 'is-open' );
	} );

	it( 'reopens to the list when the card was closed by the toggle', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group ] );
		panels.openCard( group, group.points[ 0 ].id );
		panels.toggleList();
		panels.toggleList();

		const stage = container.querySelector( '.woodev-pickup-stage' );
		expect( stage.className ).toContain( 'is-open' );
		expect( stage.className ).not.toContain( 'is-card' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "sidebar toggle"`
Expected: FAIL — the open state lives on the list, not the stage.

- [ ] **Step 3: Implement**

Move the open state onto the stage: `is-open` means "a right-hand panel is showing", `is-card` means
"that panel is the card". `openCard()` sets both; `closeCard()` drops `is-card` only; `toggleList()`
drops both when open, and restores `is-open` (never `is-card`) when closed.

```js
	Panels.prototype.toggleList = function() {
		var open = this._stage.classList.contains( 'is-open' );

		this._stage.classList.toggle( 'is-open', ! open );

		if ( open ) {
			// Collapsing hides BOTH panels — before this, the card survived the collapse and
			// stayed on screen with no way to dismiss it (operator's П-7).
			this._stage.classList.remove( 'is-card' );
		}

		this._emit( 'listToggle', { open: ! open } );
	};
```

CSS drives visibility from the stage:

```css
.woodev-pickup-list,
.woodev-pickup-card { display: none; }

.woodev-pickup-stage.is-open .woodev-pickup-list { display: flex; }
.woodev-pickup-stage.is-open.is-card .woodev-pickup-card { display: flex; }
```

- [ ] **Step 4: Style the button square and accent**

```css
.woodev-pickup-list__toggle {
	width: 44px;
	height: 44px;
	padding: 0;
	border: 0;
	border-radius: 8px;
	background: var( --woodev-pickup-accent, #06aedd );
	color: var( --woodev-pickup-accent-contrast, #fff );
	cursor: pointer;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}

.woodev-pickup-stage.is-open .woodev-pickup-list__toggle {
	right: calc( min( 320px, 100% - 48px ) + 16px );
}
```

Remove the `border-radius: 50%` rule that made it round.

- [ ] **Step 5: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/pickup-panels.test.js
git commit -m "fix(pickup): one open state on the stage so the toggle hides the card too"
```

---

### Task 7: The sidebar list becomes Russian Post's

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js:306` (`renderListHeader`), `:372` (`buildSinglePointRow`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css:185`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'sidebar list (spec V-11)', () => {
	it( 'renders no list header', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group ] );

		expect( container.querySelector( '.woodev-pickup-list__header' ) ).toBeNull();
	} );

	it( 'renders address first in bold and the name as the subtitle', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group ] );

		const row = container.querySelector( '.woodev-pickup-list__point' );
		const address = row.querySelector( '.woodev-pickup-list__address' );
		const name = row.querySelector( '.woodev-pickup-list__name' );

		expect( row.firstElementChild ).toBe( address );
		expect( address.textContent ).toBe( group.points[ 0 ].short_address || group.points[ 0 ].address );
		expect( name.textContent ).toBe( group.points[ 0 ].name );
		expect( address.getAttribute( 'title' ) ).toBe( group.points[ 0 ].address );
	} );

	it( 'renders the plugin type icon when one is configured, and none otherwise', () => {
		const withIcon = new Panels( document.createElement( 'div' ), {
			...config,
			pointIcons: { PVZ: { default: '/pvz.svg', active: '/pvz-active.svg' } },
		} );
		const withoutIcon = new Panels( document.createElement( 'div' ), { ...config, pointIcons: {} } );

		withIcon.render();
		withIcon.setVisible( [ group ] );
		withoutIcon.render();
		withoutIcon.setVisible( [ group ] );

		expect( withIcon.getMapElement().parentNode.querySelector( '.woodev-pickup-list__icon' ) ).not.toBeNull();
		expect( withoutIcon.getMapElement().parentNode.querySelector( '.woodev-pickup-list__icon' ) ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "sidebar list"`
Expected: FAIL — the header exists and the name renders first.

- [ ] **Step 3: Implement**

Delete `renderListHeader()` and its call site, and the `.woodev-pickup-list__reset` button it owned
(the search field's own clear button replaces it — T11). Keep the `drawerTitle` i18n key in PHP: it
is still used as the panel's `aria-label`, so screen readers keep the context sighted users get from
the layout.

Rewrite `buildSinglePointRow()` so the order is icon → address → name:

```js
	function buildSinglePointRow( point, anchor, group, locale, config ) {
		var wrap = document.createDocumentFragment();
		var iconUrl = pointIconUrl( config, point );

		if ( iconUrl ) {
			var icon = document.createElement( 'img' );
			icon.className = 'woodev-pickup-list__icon';
			icon.src = iconUrl;
			icon.alt = '';
			wrap.appendChild( icon );
		}

		var addressEl = document.createElement( 'span' );
		addressEl.className = 'woodev-pickup-list__address';
		addressEl.textContent = fieldValue( point.short_address ) || fieldValue( point.address );
		addressEl.setAttribute( 'title', fieldValue( point.address ) );
		wrap.appendChild( addressEl );

		var nameEl = document.createElement( 'span' );
		nameEl.className = 'woodev-pickup-list__name';
		nameEl.textContent = fieldValue( point.name );
		nameEl.setAttribute( 'title', fieldValue( point.name ) );
		wrap.appendChild( nameEl );

		if ( anchor ) {
			var meters = geo.distanceMeters( anchor, [ group.lat, group.lng ] );
			var distanceEl = document.createElement( 'span' );
			distanceEl.className = 'woodev-pickup-list__distance';
			distanceEl.textContent = geo.formatDistance( meters, locale );
			wrap.appendChild( distanceEl );
		}

		return wrap;
	}
```

Add the helper next to the other module-level functions:

```js
	/**
	 * The plugin's icon URL for a point's type, or `''` when the plugin supplies none.
	 * The sidebar shows the PLUGIN's icon only — the framework's own default marker (V-9) is
	 * map furniture and would read as decoration in a list.
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {string}
	 */
	function pointIconUrl( config, point ) {
		var icons = ( config && config.pointIcons ) || {};
		var code = ( point && point.type && point.type.code ) || '';

		return ( icons[ code ] && icons[ code ].default ) || '';
	}
```

- [ ] **Step 4: Style the row**

```css
.woodev-pickup-list__point {
	display: grid;
	grid-template-columns: auto 1fr;
	grid-template-areas: 'icon address' 'icon name' 'icon distance';
	column-gap: 8px;
	row-gap: 4px;
	padding: 16px;
	border-bottom: 1px solid rgba( 0, 0, 0, 0.08 );
	text-align: left;
}

.woodev-pickup-list__icon    { grid-area: icon; width: 20px; height: 20px; align-self: start; }
.woodev-pickup-list__address { grid-area: address; font-size: 15px; font-weight: 600; color: #333; }
.woodev-pickup-list__name    { grid-area: name; font-size: 12px; color: rgba( 0, 0, 0, 0.38 ); }
.woodev-pickup-list__distance{ grid-area: distance; font-size: 12px; color: rgba( 0, 0, 0, 0.54 ); }

.woodev-pickup-list__address,
.woodev-pickup-list__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* The last row must not sit under the map's copyright line — the reference reserves this and
   Russian Post does not, which is the flaw the operator named. */
.woodev-pickup-list__body { padding-bottom: 28px; }
```

- [ ] **Step 5: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels`
Expected: PASS. Update any existing assertion that depended on the removed header.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/pickup-panels.test.js
git commit -m "feat(pickup): sidebar rows as address-first, no list header"
```

---

## Phase 3 — the map

### Task 8: Markers get a hit area

Without `iconShape` a custom HTML icon layout has no clickable region, clicks fall through to the
map's POI layer, and Yandex's own organisation card opens — the balloon on the operator's
`04-rig-clicked-on-point.png`.

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js:1011` (`_buildFeature`)
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'marker hit area (spec V-9)', () => {
	it( 'declares an iconShape matching the default icon box', () => {
		const provider = new WoodevYandexMapProvider();
		const feature = provider._buildFeature( group );

		expect( feature.options.iconShape ).toEqual( {
			type: 'Rectangle',
			coordinates: [ [ -22, -23 ], [ 23, 22 ] ],
		} );
	} );

	it( 'declares the larger shape for a selected group', () => {
		const provider = new WoodevYandexMapProvider();

		provider.setSelectedId( group.points[ 0 ].id );

		const feature = provider._buildFeature( group );

		expect( feature.options.iconShape ).toEqual( {
			type: 'Rectangle',
			coordinates: [ [ -25, -40 ], [ 25, 30 ] ],
		} );
	} );
} );
```

The numbers are `ICON_BOX.offset` and `offset + size` for 45×45 at `[-22,-23]`, and
`ICON_BOX_ACTIVE` for 50×70 at `[-25,-40]` (spec D-5).

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=map-provider-yandex -t "marker hit area"`
Expected: FAIL — `iconShape` is `undefined`.

- [ ] **Step 3: Implement**

```js
	/**
	 * The clickable region for one feature.
	 *
	 * `iconImageSize`/`iconImageOffset` only apply to `iconLayout: 'default#image'`. A custom
	 * HTML layout takes its hit area from `iconShape` alone, and without one the overlay has no
	 * region at all: clicks pass through the marker onto the map's POI layer and Yandex's own
	 * organisation card opens instead of our point card.
	 *
	 * @param {Array<number>} offset `[ x, y ]`, negative — the icon's top-left relative to the anchor.
	 * @param {Array<number>} size   `[ width, height ]`.
	 * @returns {{type: string, coordinates: Array<Array<number>>}}
	 */
	function iconShapeFor( offset, size ) {
		return {
			type: 'Rectangle',
			coordinates: [
				[ offset[ 0 ], offset[ 1 ] ],
				[ offset[ 0 ] + size[ 0 ], offset[ 1 ] + size[ 1 ] ],
			],
		};
	}
```

and in `_buildFeature()`:

```js
		var box = this._isSelectedGroup( group ) ? ICON_BOX_ACTIVE : ICON_BOX;

		return {
			type: 'Feature',
			id: group.key,
			geometry: { type: 'Point', coordinates: [ group.lat, group.lng ] },
			properties: this._buildProperties( group ),
			options: {
				iconLayout: this._iconLayoutClass,
				iconImageSize: box.size,
				iconImageOffset: box.offset,
				iconShape: iconShapeFor( box.offset, box.size ),
			},
		};
```

If `ICON_BOX_ACTIVE` or `_isSelectedGroup()` do not exist yet, add them: `ICON_BOX_ACTIVE = { size:
[ 50, 70 ], offset: [ -25, -40 ] }`, and `_isSelectedGroup( group )` returns true when
`this._selectedId` matches any point id in the group.

- [ ] **Step 4: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=map-provider-yandex`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js tests/js/map-provider-yandex.test.js
git commit -m "fix(pickup): declare iconShape so clicks hit our markers, not the Yandex POI layer"
```

---

### Task 9: Framework-default marker icons

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js:1026` (`_renderMarker`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'default marker icon (spec V-9)', () => {
	it( 'renders an inline SVG pin when the plugin supplies no icon', () => {
		const provider = providerWith( { pointIcons: {} } );
		const el = document.createElement( 'div' );

		provider._renderMarker( el, { properties: { type: { code: 'PVZ' }, state: 'default', count: 1 } } );

		expect( el.querySelector( 'img' ) ).toBeNull();
		expect( el.querySelector( 'svg.woodev-pickup-marker__pin' ) ).not.toBeNull();
		expect( el.className ).not.toContain( 'woodev-pickup-marker--unknown' );
	} );

	it( 'renders the check pin for the active state', () => {
		const provider = providerWith( { pointIcons: {} } );
		const el = document.createElement( 'div' );

		provider._renderMarker( el, { properties: { type: { code: 'PVZ' }, state: 'active', count: 1 } } );

		expect( el.querySelector( 'svg' ).getAttribute( 'data-pin' ) ).toBe( 'active' );
	} );

	it( 'still prefers the plugin image when one is configured', () => {
		const provider = providerWith( { pointIcons: { PVZ: { default: '/pvz.svg', active: '/a.svg' } } } );
		const el = document.createElement( 'div' );

		provider._renderMarker( el, { properties: { type: { code: 'PVZ' }, state: 'default', count: 1 } } );

		expect( el.querySelector( 'img' ).getAttribute( 'src' ) ).toBe( '/pvz.svg' );
		expect( el.querySelector( 'svg.woodev-pickup-marker__pin' ) ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=map-provider-yandex -t "default marker icon"`
Expected: FAIL — the marker renders the `--unknown` modifier with no image.

- [ ] **Step 3: Implement**

Add the two pin shapes as module constants. They are filled `map-pin` / `map-pin-check` silhouettes
(Lucide's geometry, ISC-licensed) rather than the stroke originals, because a 1.5px stroke is
invisible against map tiles at 45px:

```js
	/** Filled location pin. `currentColor` so CSS can tint it with the merchant's accent. */
	var PIN_DEFAULT =
		'<svg class="woodev-pickup-marker__pin" data-pin="default" viewBox="0 0 24 24" ' +
		'aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7z"/>' +
		'<circle cx="12" cy="9" r="2.6" fill="#fff"/></svg>';

	/** The same pin with a tick in the head — the selected state. */
	var PIN_ACTIVE =
		'<svg class="woodev-pickup-marker__pin" data-pin="active" viewBox="0 0 24 24" ' +
		'aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7z"/>' +
		'<path d="M9.2 9.1l1.9 1.9 3.7-3.7" stroke="#fff" stroke-width="1.8" fill="none" ' +
		'stroke-linecap="round" stroke-linejoin="round"/></svg>';
```

In `_renderMarker()`, when the resolved plugin URL is empty, insert the matching pin instead of
applying the `--unknown` modifier:

```js
		if ( url ) {
			var img = document.createElement( 'img' );
			img.src = url;
			img.alt = '';
			container.appendChild( img );
		} else {
			// The framework's own default (V-9). Inline, not a file, so it inherits
			// --woodev-pickup-accent and a merchant's colour reaches a plugin that ships no icons.
			container.insertAdjacentHTML( 'beforeend', 'active' === state ? PIN_ACTIVE : PIN_DEFAULT );
		}
```

Delete the `--unknown` modifier and its CSS: there is no longer a state with nothing to draw.

- [ ] **Step 4: Style the pin**

```css
.woodev-pickup-marker__pin {
	display: block;
	width: 100%;
	height: 100%;
	color: var( --woodev-pickup-accent, #06aedd );
	filter: drop-shadow( 0 1px 1px rgba( 0, 0, 0, 0.3 ) );
}
```

- [ ] **Step 5: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=map-provider-yandex`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/map-provider-yandex.test.js
git commit -m "feat(pickup): framework default marker pins as inline accent-tinted SVG"
```

---

### Task 10: Marker click and list click do the same thing

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js` (`focusPoint`)
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (wiring)
- Test: `tests/js/map-provider-yandex.test.js`, `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'focusPoint (spec V-10)', () => {
	it( 'collapses the bounds onto the point and awaits the move', async () => {
		const provider = providerWithMap();

		await provider.focusPoint( { lat: 55.76, lng: 37.64 } );

		expect( provider.map.setBounds ).toHaveBeenCalledWith(
			[ [ 55.76, 37.64 ], [ 55.76, 37.64 ] ],
			{ checkZoomRange: true, zoomMargin: 0, useMapMargin: true, duration: 400 }
		);
	} );

	it( 'never opens a balloon', async () => {
		const provider = providerWithMap();

		await provider.focusPoint( { lat: 55.76, lng: 37.64 } );

		expect( provider.map.balloon.open ).not.toHaveBeenCalled();
	} );

	it( 'serialises overlapping calls so the last click wins', async () => {
		const provider = providerWithMap();

		const first = provider.focusPoint( { lat: 55.1, lng: 37.1 } );
		const second = provider.focusPoint( { lat: 55.2, lng: 37.2 } );

		await Promise.all( [ first, second ] );

		const calls = provider.map.setBounds.mock.calls;
		expect( calls[ calls.length - 1 ][ 0 ] ).toEqual( [ [ 55.2, 37.2 ], [ 55.2, 37.2 ] ] );
	} );
} );
```

And in `pickup-mount.test.js`:

```js
it( 'focuses the map from a sidebar row click, not only from a marker click', async () => {
	const { provider, panels } = mountedFeature();

	panels._emit( 'pointSelect', { group, pointId: group.points[ 0 ].id } );
	await flushPromises();

	expect( provider.focusPoint ).toHaveBeenCalledWith( { lat: group.lat, lng: group.lng } );
} );
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npx wp-scripts test-unit-js --testPathPattern="map-provider-yandex|pickup-mount" -t "focusPoint"`
Expected: FAIL — no `focusPoint`.

- [ ] **Step 3: Implement `focusPoint`**

```js
	/**
	 * Moves the camera onto one point and resolves when the move has actually finished.
	 *
	 * ymaps camera moves are ASYNC — dropping the promise makes the next `getBounds()` read the
	 * PRE-move viewport (gotcha `ymaps-camera-moves-are-async`). Calls are serialised through
	 * `_cameraChain` because two moves need not resolve in click order.
	 *
	 * @param {{lat: number, lng: number}} position
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusPoint = function( position ) {
		var self = this;
		var coords = [ position.lat, position.lng ];

		this._cameraChain = ( this._cameraChain || Promise.resolve() ).then( function() {
			return self.map.setBounds( [ coords, coords ], {
				checkZoomRange: true,
				zoomMargin: 0,
				useMapMargin: true,
				duration: 400,
			} );
		} ).catch( function() {
			// A refused move must not poison the chain for every later click.
		} );

		return this._cameraChain;
	};
```

- [ ] **Step 4: Wire both entry points in `pickup-mount.js`**

Both the provider's `pointClick` and the panels' `pointSelect` run the same two lines, in this order:

```js
	function focusAndOpen( group, pointId ) {
		provider.focusPoint( { lat: group.lat, lng: group.lng } );
		panels.openCard( group, pointId );
	}

	provider.on( 'pointClick', function( payload ) {
		focusAndOpen( payload.group, payload.pointId );
	} );

	panels.on( 'pointSelect', function( payload ) {
		focusAndOpen( payload.group, payload.pointId );
	} );
```

The camera move is deliberately **not** awaited before opening the card: the card is our own DOM and
has nothing to do with the viewport, so making the customer wait 400 ms for it would be a regression.

- [ ] **Step 5: Run the tests**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js
git commit -m "feat(pickup): marker and sidebar clicks both move the camera and open the card"
```

---

## Phase 4 — the controls

### Task 11: Our own search field

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js` (controls row)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css:377`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'search field (spec V-6)', () => {
	it( 'renders a search form with a placeholder from i18n', () => {
		const container = document.createElement( 'div' );

		new Panels( container, config ).render();

		const form = container.querySelector( 'form.woodev-pickup-search__form' );
		const input = form.querySelector( 'input.woodev-pickup-search__input' );

		expect( form.getAttribute( 'role' ) ).toBe( 'search' );
		expect( input.getAttribute( 'placeholder' ) ).toBe( config.i18n.yourAddress );
	} );

	it( 'shows the clear button only when the input is non-empty', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		const clear = container.querySelector( '.woodev-pickup-search__clear' );
		const input = container.querySelector( '.woodev-pickup-search__input' );

		expect( clear.hidden ).toBe( true );

		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );

		expect( clear.hidden ).toBe( false );
	} );

	it( 'emits searchQuery once per debounce window, from 3 characters', () => {
		jest.useFakeTimers();

		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onQuery = jest.fn();

		panels.render();
		panels.on( 'searchQuery', onQuery );

		const input = container.querySelector( '.woodev-pickup-search__input' );

		input.value = 'Тв';
		input.dispatchEvent( new Event( 'input' ) );
		jest.advanceTimersByTime( 400 );
		expect( onQuery ).not.toHaveBeenCalled();

		input.value = 'Твер';
		input.dispatchEvent( new Event( 'input' ) );
		input.value = 'Тверс';
		input.dispatchEvent( new Event( 'input' ) );
		jest.advanceTimersByTime( 400 );

		expect( onQuery ).toHaveBeenCalledTimes( 1 );
		expect( onQuery ).toHaveBeenCalledWith( { query: 'Тверс' } );

		jest.useRealTimers();
	} );

	it( 'clearing resets the results and the anchor', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onReset = jest.fn();

		panels.render();
		panels.on( 'searchReset', onReset );

		const input = container.querySelector( '.woodev-pickup-search__input' );
		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );
		container.querySelector( '.woodev-pickup-search__clear' ).click();

		expect( input.value ).toBe( '' );
		expect( onReset ).toHaveBeenCalled();
	} );

	it( 'renders nothing when the plugin disabled search', () => {
		const container = document.createElement( 'div' );

		new Panels( container, { ...config, search: false } ).render();

		expect( container.querySelector( '.woodev-pickup-search' ) ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "search field"`
Expected: FAIL — no form is rendered.

- [ ] **Step 3: Implement the control**

Build it in a new `buildSearchControl( self )` called from `render()` when
`false !== self._config.search`:

```js
	var SEARCH_DEBOUNCE_MS = 300;
	var SEARCH_MIN_CHARS = 3;

	function buildSearchControl( self ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'woodev-pickup-search';

		var form = document.createElement( 'form' );
		form.className = 'woodev-pickup-search__form';
		form.setAttribute( 'role', 'search' );

		var input = document.createElement( 'input' );
		input.type = 'search';
		input.className = 'woodev-pickup-search__input';
		input.setAttribute( 'placeholder', text( self._config, 'yourAddress' ) );
		input.setAttribute( 'aria-label', text( self._config, 'yourAddress' ) );

		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'woodev-pickup-search__clear';
		clear.hidden = true;
		clear.setAttribute( 'aria-label', text( self._config, 'resetSearch' ) );

		var results = document.createElement( 'div' );
		results.className = 'woodev-pickup-search__results';
		results.hidden = true;

		form.appendChild( input );
		form.appendChild( clear );
		wrap.appendChild( form );
		wrap.appendChild( results );

		// The browser's own submit would reload the checkout page.
		form.addEventListener( 'submit', function( event ) {
			event.preventDefault();
		} );

		input.addEventListener( 'input', function() {
			var value = input.value.trim();

			clear.hidden = 0 === value.length;

			window.clearTimeout( self._searchTimer );

			if ( value.length < SEARCH_MIN_CHARS ) {
				return;
			}

			self._searchTimer = window.setTimeout( function() {
				self._emit( 'searchQuery', { query: value } );
			}, SEARCH_DEBOUNCE_MS );
		} );

		clear.addEventListener( 'click', function() {
			window.clearTimeout( self._searchTimer );
			input.value = '';
			clear.hidden = true;
			results.hidden = true;
			empty( results );
			self._emit( 'searchReset', {} );
		} );

		self._searchInput = input;
		self._searchResults = results;

		return wrap;
	}
```

`renderSearchResults()` keeps its existing two-section markup but now fills `self._searchResults`
and un-hides it; an empty result renders `text( config, 'noResults' )` instead of an empty box.

- [ ] **Step 4: Style it (Russian Post's look)**

```css
.woodev-pickup-controls {
	position: absolute;
	top: 16px;
	left: 16px;
	right: 16px;
	z-index: 5;
	display: flex;
	gap: 8px;
	align-items: flex-start;
	pointer-events: none;          /* the map stays draggable between the controls */
}

.woodev-pickup-controls > * { pointer-events: auto; }

.woodev-pickup-search { width: min( 420px, 100% ); }

.woodev-pickup-search__form {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 0 8px 0 16px;
	height: 48px;
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}

.woodev-pickup-search__input {
	flex: 1 1 auto;
	min-width: 0;
	height: 100%;
	border: 0;
	background: none;
	font-size: 14px;
	outline: none;
}

.woodev-pickup-search__results {
	margin-top: 8px;
	max-height: 320px;
	overflow-y: auto;
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}
```

- [ ] **Step 5: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/pickup-panels.test.js
git commit -m "feat(pickup): own search field with debounce, clear button and two-section results"
```

---

### Task 12: Drop `SearchControl`, call `suggest`/`geocode` directly

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js:737` (`_buildSearchControl`, deleted), `:783` (`_searchGeocodeProvider`)
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'address search (spec V-6, V-7)', () => {
	it( 'adds no ymaps SearchControl', async () => {
		const provider = await initProvider();

		expect( ymaps.control.SearchControl ).not.toHaveBeenCalled();
	} );

	it( 'bounds suggestions to the loaded point set, strictly', async () => {
		const provider = await initProvider();

		provider.setPoints( pointsInMoscow );
		await provider.search( 'Тверская' );

		expect( ymaps.suggest ).toHaveBeenCalledWith( 'Тверская', expect.objectContaining( {
			boundedBy: provider.objectManager.getBounds(),
			strictBounds: true,
		} ) );
	} );

	it( 'omits the bounds when nothing is loaded yet', async () => {
		const provider = await initProvider();

		await provider.search( 'Тверская' );

		expect( ymaps.suggest.mock.calls[ 0 ][ 1 ].boundedBy ).toBeUndefined();
		expect( ymaps.suggest.mock.calls[ 0 ][ 1 ].strictBounds ).toBeUndefined();
	} );

	it( 'emits both sections, points first, even when both are empty', async () => {
		const provider = await initProvider();
		const onResults = jest.fn();

		provider.on( 'searchResults', onResults );
		ymaps.suggest.mockResolvedValue( [] );

		await provider.search( 'ничего' );

		expect( onResults ).toHaveBeenCalledWith( { points: [], addresses: [] } );
	} );

	it( 'geocodes only on selection, never per keystroke', async () => {
		const provider = await initProvider();

		await provider.search( 'Тверская' );
		expect( ymaps.geocode ).not.toHaveBeenCalled();

		await provider.resolveAddress( { value: 'Москва, Тверская 5' } );
		expect( ymaps.geocode ).toHaveBeenCalledTimes( 1 );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=map-provider-yandex -t "address search"`
Expected: FAIL — `SearchControl` is still constructed.

- [ ] **Step 3: Implement**

Delete `_buildSearchControl()` and its call in `init()`. Rename `_searchGeocodeProvider` to a public
`search( query )` that the mount calls on the panels' `searchQuery` event:

```js
	/**
	 * Runs one search: the loaded point pool (free, instant) plus ymaps address suggestions.
	 *
	 * `ymaps.control.SearchControl` used to wrap this and is gone (V-6). It contributed only its
	 * engine, and its options were being passed at the wrong nesting level — controls take
	 * `{ data, options, state }`, so `provider`, `layout` and `noPlacemark` were silently
	 * ignored, leaving ymaps' default chrome and, worse, its default worldwide geocoder.
	 *
	 * @param {string} query
	 * @returns {Promise<{points: Array, addresses: Array}>}
	 */
	WoodevYandexMapProvider.prototype.search = function( query ) {
		var self = this;
		var options = { results: SUGGEST_RESULT_COUNT };
		var bounds = this._loadedBounds();

		if ( bounds ) {
			// Hard-bounded to the loaded points (V-7): under bulk that is exactly the buyer's
			// locality, so a same-named street in another region never appears; under viewport the
			// loaded set follows the viewport, so one rule serves both strategies.
			options.boundedBy = bounds;
			options.strictBounds = true;
		}

		return this.ymaps.suggest( query, options ).then( function( addresses ) {
			var results = {
				points: geo.matchPoints( self._allPoints(), query ),
				addresses: addresses || [],
			};

			self.emit( 'searchResults', results );

			return results;
		} );
	};

	/**
	 * The bounds of the currently loaded points, or null before the first load.
	 *
	 * @returns {Array|null}
	 */
	WoodevYandexMapProvider.prototype._loadedBounds = function() {
		if ( ! this.objectManager || ! this._allPoints().length ) {
			return null;
		}

		return this.objectManager.getBounds();
	};
```

`resolveAddress()` (already written for D-6) keeps calling `ymaps.geocode()` once on selection.

- [ ] **Step 4: Wire it in `pickup-mount.js`**

```js
	panels.on( 'searchQuery', function( payload ) {
		provider.search( payload.query );
	} );

	provider.on( 'searchResults', function( results ) {
		panels.renderSearchResults( results );
	} );
```

- [ ] **Step 5: Run the tests**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js
git commit -m "fix(pickup): own the address search instead of wrapping ymaps SearchControl"
```

---

### Task 13: The type filter, Russian Post's menu

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js:1305` (`setTypes`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css:435`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'type filter (spec V-8)', () => {
	const twoTypes = [
		{ code: 'PVZ', label: 'Пункт выдачи' },
		{ code: 'POSTAMAT', label: 'Постамат' },
	];

	it( 'renders nothing for a single type', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setTypes( [ { code: 'PVZ', label: 'Пункт выдачи' } ] );

		expect( container.querySelector( '.woodev-pickup-filter' ) ).toBeNull();
	} );

	it( 'renders a toggle button and a checkbox per type for two types', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setTypes( twoTypes );

		expect( container.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ).toHaveLength( 2 );
		expect( container.querySelector( '.woodev-pickup-filter__toggle' ) ).not.toBeNull();
	} );

	it( 'hides the badge while everything is selected and shows the count otherwise', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setTypes( twoTypes );

		const badge = container.querySelector( '.woodev-pickup-filter__badge' );
		expect( badge.hidden ).toBe( true );

		container.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

		expect( badge.hidden ).toBe( false );
		expect( badge.textContent ).toBe( '1' );
	} );

	it( 'refuses to uncheck the last remaining type', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onFilter = jest.fn();

		panels.render();
		panels.setTypes( twoTypes );
		panels.on( 'typeFilter', onFilter );

		const boxes = container.querySelectorAll( '.woodev-pickup-filter__checkbox' );
		boxes[ 0 ].click();
		boxes[ 1 ].click();

		expect( boxes[ 1 ].checked ).toBe( true );
		expect( onFilter ).toHaveBeenCalledTimes( 1 );
		expect( onFilter ).toHaveBeenLastCalledWith( { types: [ 'POSTAMAT' ] } );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "type filter"`
Expected: FAIL.

- [ ] **Step 3: Implement**

`setTypes( types )` renders into the controls row: an icon button carrying a hidden badge, and a
panel of checkboxes titled by `text( config, 'filterTypes' )`. Gate the whole control on
`types.length > 1`. In the change handler:

```js
		checkbox.addEventListener( 'change', function() {
			var selected = self._selectedTypes();

			if ( 0 === selected.length ) {
				// An empty map is not a reachable state — the reference does the same
				// (re-select and return).
				checkbox.checked = true;

				return;
			}

			badge.hidden = selected.length === self._types.length;
			badge.textContent = String( selected.length );

			self._emit( 'typeFilter', { types: selected } );
		} );
```

`pickup-mount.js` routes the event by strategy (spec D-10):

```js
	panels.on( 'typeFilter', function( payload ) {
		if ( 'viewport' === config.strategy ) {
			dataSource.setTypes( payload.types );   // goes into the query
		} else {
			provider.setTypeFilter( payload.types ); // objectManager.setFilter, client-side
		}
	} );
```

- [ ] **Step 4: Style the control**

```css
.woodev-pickup-filter { position: relative; }

.woodev-pickup-filter__toggle {
	position: relative;
	width: 48px;
	height: 48px;
	border: 0;
	border-radius: 8px;
	background: #fff;
	color: #333;
	cursor: pointer;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}

.woodev-pickup-filter__badge {
	position: absolute;
	top: 4px;
	right: 4px;
	min-width: 16px;
	height: 16px;
	padding: 0 4px;
	border-radius: 8px;
	background: var( --woodev-pickup-accent, #06aedd );
	color: var( --woodev-pickup-accent-contrast, #fff );
	font-size: 11px;
	line-height: 16px;
	text-align: center;
}

.woodev-pickup-filter__menu {
	position: absolute;
	top: calc( 100% + 8px );
	right: 0;
	min-width: 240px;
	padding: 12px 16px;
	background: #fff;
	border-radius: 8px;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}
```

- [ ] **Step 5: Run the tests**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/js/frontend/pickup-mount.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js
git commit -m "feat(pickup): checkbox type filter with a count badge, gated on two or more types"
```

---

### Task 14: Our own zoom control

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js:647`
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Test: `tests/js/pickup-panels.test.js`, `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'zoom control (spec V-13)', () => {
	it( 'renders two buttons and emits a signed step', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onZoom = jest.fn();

		panels.render();
		panels.on( 'zoom', onZoom );

		const buttons = container.querySelectorAll( '.woodev-pickup-zoom__button' );
		expect( buttons ).toHaveLength( 2 );

		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onZoom ).toHaveBeenNthCalledWith( 1, { step: 1 } );
		expect( onZoom ).toHaveBeenNthCalledWith( 2, { step: -1 } );
	} );
} );
```

and in the provider suite:

```js
it( 'adds no ymaps ZoomControl (spec V-13)', async () => {
	await initProvider();

	expect( ymaps.control.ZoomControl ).not.toHaveBeenCalled();
} );

it( 'clamps zoomBy to the configured range', async () => {
	const provider = await initProvider();

	provider.map.getZoom.mockReturnValue( 18 );
	provider.zoomBy( 1 );

	expect( provider.map.setZoom ).toHaveBeenCalledWith( 18, { duration: 200 } );
} );
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npm run test:js -- -t "zoom"`
Expected: FAIL.

- [ ] **Step 3: Implement**

Panels build the control; the provider gains `zoomBy( step )` clamped to `MIN_ZOOM`/`MAX_ZOOM`; the
mount wires `panels.on( 'zoom', p => provider.zoomBy( p.step ) )`. Delete the
`this.map.controls.add( new ymaps.control.ZoomControl(), ... )` line.

```css
.woodev-pickup-zoom {
	position: absolute;
	left: 12px;
	bottom: 70px;
	z-index: 5;
	display: flex;
	flex-direction: column;
	border-radius: 4px;
	overflow: hidden;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.04 ), 0 4px 8px rgba( 0, 0, 0, 0.06 );
}

.woodev-pickup-zoom__button {
	width: 36px;
	height: 36px;
	border: 0;
	background: #fff;
	color: #333;
	font-size: 18px;
	line-height: 1;
	cursor: pointer;
}

.woodev-pickup-zoom__button + .woodev-pickup-zoom__button {
	border-top: 1px solid rgba( 0, 0, 0, 0.08 );
}
```

- [ ] **Step 4: Run the tests**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js woodev/shipping-method/assets/css/frontend/pickup.css tests/js
git commit -m "feat(pickup): own zoom control, two square buttons at bottom left"
```

---

## Phase 5 — the card and the states

### Task 15: The point card gets sections and air

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js:673` (`buildCardHeader`), `:708` (`buildCardBody`)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css:500`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'point card (spec V-12)', () => {
	it( 'renders one section per populated field, and none for empty ones', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.openCard( groupWithEverything, groupWithEverything.points[ 0 ].id );

		const titles = [ ...container.querySelectorAll( '.woodev-pickup-card__section-title' ) ]
			.map( ( el ) => el.textContent );

		expect( titles ).toEqual( [
			config.i18n.address,
			config.i18n.paymentMethods,
			config.i18n.services,
			config.i18n.phone,
			config.i18n.workTime,
			config.i18n.maxWeight,
		] );
	} );

	it( 'omits a section whose field is empty', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.openCard( groupWithoutPhone, groupWithoutPhone.points[ 0 ].id );

		const titles = [ ...container.querySelectorAll( '.woodev-pickup-card__section-title' ) ]
			.map( ( el ) => el.textContent );

		expect( titles ).not.toContain( config.i18n.phone );
	} );

	it( 'renders the chip only when the plugin supplies an icon', () => {
		const container = document.createElement( 'div' );

		const panels = new Panels( container, { ...config, pointIcons: {} } );
		panels.render();
		panels.openCard( groupWithEverything, groupWithEverything.points[ 0 ].id );

		expect( container.querySelector( '.woodev-pickup-card__chip' ) ).toBeNull();
	} );

	it( 'disables the footer button and states the reason when the point is not selectable', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.openCard( groupWithBlockedPoint, groupWithBlockedPoint.points[ 0 ].id );

		const button = container.querySelector( '.woodev-pickup-card__select' );
		const notice = container.querySelector( '.woodev-pickup-card__notice' );

		expect( button.disabled ).toBe( true );
		expect( notice.textContent ).toBe( groupWithBlockedPoint.points[ 0 ].reason );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels -t "point card"`
Expected: FAIL — the body renders labelled rows, not sections.

- [ ] **Step 3: Implement**

Replace `labelledRow()` usage in the card body with a `cardSection( title, contentNode )` helper that
emits

```html
<div class="woodev-pickup-card__section">
	<div class="woodev-pickup-card__section-title">Адрес</div>
	<div class="woodev-pickup-card__section-content">…</div>
</div>
```

in the fixed order Адрес → Способы оплаты → Услуги → Телефон → Часы работы → Ограничение веса, each
skipped when its value is empty. «Как добраться» stays a `<details>` inside the address section.
The header gains the chip (plugin icon only) and keeps the existing group tab bar.

- [ ] **Step 4: Style the card**

```css
.woodev-pickup-card { display: none; flex-direction: column; background: #fff; }

.woodev-pickup-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 8px 8px 16px;
}

.woodev-pickup-card__chip {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border: 1px solid rgba( 0, 0, 0, 0.12 );
	border-radius: 8px;
	background: var( --woodev-pickup-accent, #06aedd );
}

.woodev-pickup-card__title    { font-size: 16px; line-height: 24px; letter-spacing: 0.5px; }
.woodev-pickup-card__postal   { font-size: 12px; line-height: 16px; color: rgba( 0, 0, 0, 0.54 ); }

.woodev-pickup-card__body     { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

.woodev-pickup-card__section  { padding: 8px 16px 12px; border-top: 1px solid rgba( 0, 0, 0, 0.08 ); }
.woodev-pickup-card__section-title   { padding: 4px 0; font-size: 14px; font-weight: 500; line-height: 20px; }
.woodev-pickup-card__section-content { padding: 2px 0 4px; font-size: 14px; line-height: 20px; }

.woodev-pickup-card__footer {
	position: sticky;
	bottom: 0;
	margin-top: auto;
	padding: 16px 16px 36px;
	background: #fff;
	border-top: 1px solid rgba( 0, 0, 0, 0.08 );
}

.woodev-pickup-card__select {
	width: 100%;
	height: 40px;
	border: 0;
	border-radius: 4px;
	background: var( --woodev-pickup-accent, #06aedd );
	color: var( --woodev-pickup-accent-contrast, #fff );
	font-size: 14px;
	cursor: pointer;
}

.woodev-pickup-card__select:disabled {
	background: rgba( 0, 0, 0, 0.06 );
	color: rgba( 0, 0, 0, 0.24 );
	cursor: default;
}

.woodev-pickup-card__notice {
	margin-bottom: 8px;
	padding: 8px 16px 12px;
	border: 1px solid #fb4513;
	border-radius: 5px;
	background: rgba( 251, 69, 19, 0.1 );
	font-size: 14px;
	line-height: 20px;
}
```

- [ ] **Step 5: Run the tests**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-panels`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js woodev/shipping-method/assets/css/frontend/pickup.css tests/js/pickup-panels.test.js
git commit -m "feat(pickup): sectioned point card with a sticky footer"
```

---

### Task 16: Three loading stages

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Test: `tests/js/pickup-mount.test.js`, `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'loading stages (spec V-4)', () => {
	it( 'shows the modal spinner before the map exists', () => {
		const { modal } = openFeature();

		expect( document.querySelector( '.woodev-modal__loading' ) ).not.toBeNull();
		expect( document.querySelector( '.woodev-pickup-stage' ) ).toBeNull();
	} );

	it( 'moves the spinner onto the stage and blocks the map while points load', async () => {
		const { panels } = openFeature();

		await mapReady();

		expect( document.querySelector( '.woodev-modal__loading' ) ).toBeNull();
		expect( panels.isBusy() ).toBe( true );
		expect( document.querySelector( '.woodev-pickup-stage' ).className ).toContain( 'is-busy' );
	} );

	it( 'clears the busy state once points arrive', async () => {
		const { panels } = openFeature();

		await mapReady();
		await pointsLoaded( [ point ] );

		expect( panels.isBusy() ).toBe( false );
		expect( document.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( true );
	} );

	it( 'keeps the modal closable in every stage', async () => {
		const { modal } = openFeature();
		const onClose = jest.fn();

		modal.on( 'close', onClose );
		document.querySelector( '.woodev-modal__close' ).click();

		expect( onClose ).toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js --testPathPattern=pickup-mount -t "loading stages"`
Expected: FAIL — no `isBusy`.

- [ ] **Step 3: Implement**

Panels gain `setBusy( bool )` (toggles `is-busy` on the stage and un-hides the overlay with a
spinner) and `isBusy()`. The mount sequences:

```js
	modal.showLoading( text( config.i18n, 'loading' ) );          // stage 1
	provider.init( panels.getMapElement(), config ).then( function() {
		modal.hideLoading();
		panels.setBusy( true );                                    // stage 2
		return dataSource.load();
	} ).then( function() {
		panels.setBusy( false );                                   // stage 3
	} );
```

```css
.woodev-pickup-stage.is-busy .woodev-pickup-map { pointer-events: none; }

.woodev-pickup-overlay {
	position: absolute;
	inset: 0;
	z-index: 6;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	background: rgba( 255, 255, 255, 0.75 );
}
```

The overlay covers the map but not the controls' interaction model — while `is-busy`, the controls
row is hidden, because searching a pool that has not loaded is meaningless.

- [ ] **Step 4: Run the tests**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js woodev/shipping-method/assets/css/frontend/pickup.css tests/js
git commit -m "feat(pickup): three loading stages with a closable modal throughout"
```

---

### Task 17: Empty and error states in the plugin's own words

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php:619` (`i18n`), `:612` (`get_js_config`)
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`, `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing PHP test**

```php
public function test_i18n_passes_through_a_filter_so_a_plugin_can_reword_it(): void {
	\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, $value, $plugin_id = null ) {
			if ( 'woodev_pickup_map_i18n' !== $hook ) {
				return $value;
			}

			$value['emptyLocality'] = 'В данном населённом пункте нет отделений Почты России';

			return $value;
		}
	);

	$config = $this->handler()->get_js_config();

	$this->assertSame(
		'В данном населённом пункте нет отделений Почты России',
		$config['i18n']['emptyLocality']
	);
}

public function test_i18n_ships_a_default_empty_locality_string(): void {
	$config = $this->handler()->get_js_config();

	$this->assertArrayHasKey( 'emptyLocality', $config['i18n'] );
	$this->assertNotSame( '', $config['i18n']['emptyLocality'] );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --filter PickupHandlerTest`
Expected: FAIL — `emptyLocality` is missing.

- [ ] **Step 3: Implement**

Add the key next to `emptyInView`:

```php
					'emptyLocality'    => __(
						'В выбранном населённом пункте нет пунктов выдачи',
						'woodev-plugin-framework'
					),
```

and route the whole map through the filter before returning it:

```php
			$strings = [ /* … the existing map … */ ];

			/**
			 * Filters every customer-facing string the pickup map renders.
			 *
			 * An empty result is domain language, not framework language: Russian Post has no
			 * pickup points, it has post offices. Rather than a second `messages` array beside
			 * this one, the existing string map IS the override surface (spec V-5).
			 *
			 * @since 2.0.2
			 *
			 * @param array<string, string> $strings   The framework's defaults.
			 * @param string                $plugin_id The plugin the map belongs to.
			 */
			$strings = apply_filters( 'woodev_pickup_map_i18n', $strings, $this->plugin_id );
```

- [ ] **Step 4: Render it on the JS side**

`Panels.prototype.showMessage( key )` un-hides the overlay with the resolved string and a retry
button when the key is `error`. The mount calls `panels.showMessage( 'emptyLocality' )` on an empty
bulk load, `'emptyInView'` on an empty viewport load, `'error'` on a failed request and `'zoomIn'` on
`bboxTooWide`. The message never replaces the interface (s48 decision) — controls stay usable.

- [ ] **Step 5: Run the tests**

Run: `composer test:unit && npm run test:js`
Expected: PASS.

- [ ] **Step 6: Run phpcs**

Run: `composer phpcs`
Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git add woodev/shipping-method tests
git commit -m "feat(pickup): plugin-overridable map strings and a distinct empty-locality state"
```

---

## Phase 6 — styling and mobile

### Task 18: The style-isolation contract, and the handler emits the modal size

**Files:**
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css` (top of file)
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php:612`
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Write the failing PHP test**

```php
public function test_config_carries_the_modal_size_and_the_search_flag(): void {
	$config = $this->handler()->get_js_config();

	$this->assertSame( 920, $config['modal']['width'] );
	$this->assertSame( 'min(80vh, 800px)', $config['modal']['bodyHeight'] );
	$this->assertTrue( $config['search'] );
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit --filter PickupHandlerTest`
Expected: FAIL — no `modal` key.

- [ ] **Step 3: Implement the config keys**

```php
				// The dialog sizes itself before any content exists (spec V-1); these two values
				// used to live only in CSS, on the MAP element, which is why the modal opened as a
				// header-tall strip until the map mounted.
				'modal'  => [
					'width'      => 920,
					'bodyHeight' => 'min(80vh, 800px)',
				],
				'search' => $this->search_enabled,
```

`search_enabled` is a **new** `bool` property on `Pickup_Handler`, defaulting to `true`, set from the
plugin's pickup config. It is deliberately not a `Map_Provider` method: whether a carrier wants an
address search is the plugin's decision, not the map library's, and `Map_Provider` currently declares
only `get_id`/`get_label`/`get_script_handle`/`get_settings_fields`/`get_js_config`/`owns_chrome` —
adding a seventh method would oblige both providers and every fixture to answer a question neither of
them owns.

Apply the same filter escape hatch as the strings:

```php
			$search_enabled = (bool) apply_filters(
				'woodev_pickup_map_search_enabled',
				$this->search_enabled,
				$this->plugin_id
			);
```

`pickup-mount.js` passes `modal.width`/`modal.bodyHeight` straight through to
`new WoodevModal( { ..., width, bodyHeight } )`, and `config.search` reaches the panels, which is
what T11's last test asserts.

- [ ] **Step 4: Write the style contract**

At the top of `pickup.css`, under a single root selector, declare every element type the feature
emits. A theme that sets `button { display: none }` or `h1 { text-transform: uppercase }` must not
reach us:

```css
/*
 * STYLE ISOLATION (spec V-14).
 *
 * A storefront theme's global element rules land inside our modal too. Every element type we emit
 * therefore declares its own box and typography. The ONE thing we never declare is `font-family`:
 * the map must read as part of the shop, so it inherits — including on `button`, `input` and
 * `select`, which do not inherit it by default.
 */
.woodev-modal .woodev-pickup-stage :is( div, span, p, ul, li, form, details, summary, img, svg ),
.woodev-modal .woodev-pickup-stage :is( h1, h2, h3, h4, h5, h6 ),
.woodev-modal .woodev-pickup-stage :is( button, input ) {
	font-family: inherit;
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font-size: 14px;
	font-weight: 400;
	font-style: normal;
	line-height: 1.4;
	letter-spacing: normal;
	text-align: left;
	text-transform: none;
	box-shadow: none;
	min-width: 0;
	min-height: 0;
	max-width: none;
	float: none;
	visibility: visible;
}

.woodev-modal .woodev-pickup-stage :is( div, p, form, details, ul ) { display: block; }
.woodev-modal .woodev-pickup-stage :is( span, img, svg )            { display: inline-block; }
.woodev-modal .woodev-pickup-stage li                               { display: list-item; list-style: none; }
.woodev-modal .woodev-pickup-stage button                           { display: inline-flex; align-items: center; justify-content: center; cursor: pointer; appearance: none; }
.woodev-modal .woodev-pickup-stage input                            { display: block; width: auto; height: auto; appearance: none; }
.woodev-modal .woodev-pickup-stage summary                          { display: list-item; cursor: pointer; }
.woodev-modal .woodev-pickup-stage img                              { max-width: none; height: auto; }
```

The same block, with `.woodev-modal__header`/`__body` in place of the stage selector, goes into
`woodev-modal.css` for the shell's own elements.

Every rule written in Tasks 4–17 comes **after** this block, so it wins by order at equal
specificity.

- [ ] **Step 5: Run the tests**

Run: `composer test:unit && npm run test:js && composer phpcs`
Expected: PASS, 0 phpcs errors.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method tests
git commit -m "feat(pickup): style-isolation contract and modal sizing from the handler"
```

---

### Task 19: The ≤782px layout

**Files:**
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css` (bottom)
- Modify: `woodev/assets/css/frontend/woodev-modal.css` (existing media block)

- [ ] **Step 1: Write the mobile block**

One breakpoint for the whole feature — 782px, the WordPress one, already used by both files.

```css
@media screen and ( max-width: 782px ) {

	/* Panels take the whole stage: at this width a 320px column leaves the map unusable. */
	.woodev-pickup-list,
	.woodev-pickup-card {
		max-width: none;
	}

	.woodev-pickup-stage.is-open .woodev-pickup-list__toggle {
		right: 16px;
		bottom: 16px;
	}

	/* Every target reaches the 44px minimum. */
	.woodev-pickup-list__toggle,
	.woodev-pickup-filter__toggle,
	.woodev-pickup-zoom__button,
	.woodev-pickup-card__close {
		width: 44px;
		height: 44px;
	}

	.woodev-pickup-controls {
		flex-wrap: nowrap;
	}

	.woodev-pickup-search {
		width: 100%;
	}

	.woodev-pickup-search__results {
		max-height: 50vh;
	}

	/* Above the copyright line, not on it. */
	.woodev-pickup-zoom {
		bottom: 88px;
	}

	.woodev-pickup-card__footer {
		padding-bottom: 24px;
	}
}
```

- [ ] **Step 2: Confirm the modal's own mobile block still matches**

`woodev-modal.css`'s `@media screen and ( max-width: 782px )` sets `width/height: 100%`,
`min-width: 100%`, `max-height: none`, `border-radius: 0`. Add one line so a supplied `bodyHeight`
does not fight it:

```css
	.woodev-modal__body {
		height: auto !important; /* the inline size from V-1 is a desktop value */
	}
```

- [ ] **Step 3: Run the suites**

Run: `npm run test:js`
Expected: PASS (CSS-only, but the suite must stay green).

- [ ] **Step 4: Commit**

```bash
git add woodev/assets/css/frontend/woodev-modal.css woodev/shipping-method/assets/css/frontend/pickup.css
git commit -m "style(pickup): designed layout at 782px and below"
```

---

## Phase 7 — verification

### Task 20: Rig verification, desktop and 390px

Green tests are not evidence here. Two sessions produced seven defects against a fully green suite.

**Preconditions:** containers up on 8973; real Yandex key present in
`wp-content/mu-plugins/zz-rig-yandex-key.php`; страна RU → регион 77 → метод `woodev_test_shipping`
→ город «Москва», set after `jQuery.active` reaches 0 (§8 and `update_checkout` overwrite
`billing_city`). Browser driver: **chrome-devtools MCP**, never Playwright (gotcha
`playwright-mcp-does-not-fire-wc-checkout-ajax`).

- [ ] **Step 1: Run every automated gate first**

```bash
composer test:unit
npm run test:js
composer phpcs
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage
```

Expected: all green. Record the counts.

- [ ] **Step 2: Walk the desktop checklist, one screenshot each**

1. Modal opens at full size with a spinner, before the map exists. Close it mid-load — it closes.
2. Map drawn, points loading: overlay on the stage, map does not drag.
3. Points in: markers carry the fixture's own icons; no `Script error.` in the console.
4. Click a marker: the camera moves to it, our card opens, **no Yandex POI card appears**.
5. Click a sidebar row: identical result.
6. Sidebar: no header, address bold above the muted name, the last row is not under the copyright.
7. Card: chip, six sections with dividers, sticky footer, ample spacing.
8. `FIX-BULK-COLOCATED-*`: the tab bar appears and switches between the two points.
9. A cluster shows its count badge; clicking it zooms in.
10. The COD-refusing point with COD chosen: the footer button is disabled and the reason is shown.
11. Toggle: square, accent-coloured, hides list **and** card; reopening shows the list.
12. Search: our field; type «Тверская» — the points section fills; type an address from another city
    — it does **not** appear; pick an address — a pin drops, the camera fits it plus 3 points, the
    sidebar sorts by distance.
13. Filter: visible (the fixture has two types), badge appears when one is unchecked, the last type
    cannot be unchecked.
14. Zoom control: bottom left, two square buttons, both work, clamped at 8 and 18.
15. An empty locality (set the city to one with no fixture points): the plugin's own message.

- [ ] **Step 3: Repeat at 390px**

Resize to 390×844 and walk items 1, 5, 6, 7, 11, 12, 13 again.

- [ ] **Step 4: Hostile-theme check**

In the console, inject and confirm nothing breaks:

```js
const s = document.createElement( 'style' );
s.textContent = 'button{display:none!important}h1,h2{font-size:34px;text-transform:uppercase}' +
	'input{border:4px solid red;height:80px}';
document.head.appendChild( s );
```

Expected: the map's own buttons stay visible and correctly sized (our rules carry the same
`!important` only where a theme forces it — if a control disappears, add the targeted override and
re-run).

- [ ] **Step 5: Report**

Post the screenshots and the counts to the operator, marked **"needs operator verification"** — not
"done". Он делает свою финальную проверку сам; PR #149 мерджится только после его слова.

- [ ] **Step 6: Commit any fixes found, then clean up**

Once the operator accepts, delete the review artefacts he asked to be removed:

```bash
git rm -r docs-internal/review/pickup-map-visual
git commit -m "chore: drop the visual-review artefacts, folded into the spec"
```

---

## Self-review notes

- **Spec coverage.** V-1 → T2/T18; V-2 → T3/T4; V-3 → T5; V-4 → T3/T16; V-5 → T17; V-6 → T11/T12;
  V-7 → T12; V-8 → T13; V-9 → T8/T9; V-10 → T10; V-11 → T7; V-12 → T15; V-13 → T14; V-14 → T18;
  V-15 → T19; V-16 → T1. Verification (spec §7) → T20.
- **Naming consistency.** `getMapElement()` (T5) is what T16 mounts into; `setBusy`/`isBusy` (T16)
  are used only there; `focusPoint` (T10) is called by both click paths; `search()` (T12) replaces
  `_searchGeocodeProvider`; `showMessage( key )` (T17) is the only message entry point;
  `iconShapeFor()` (T8) is used by `_buildFeature` only.
- **Open risk.** T13's `dataSource.setTypes()` assumes the viewport query already accepts `types`
  (D-10 says it should). If it does not exist, add it inside T13 rather than deferring — the filter
  is dead on the viewport strategy without it.

## Related

- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — the design
- `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` — D-1…D-15
- #158 — fixture gap, closed by T1
- #159 — the query-contract sub-project that follows this plan

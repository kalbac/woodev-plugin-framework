# SP-5 pickup-map rework — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the pickup map's presentation layer so it matches — and improves on — the three
working reference implementations, without touching the server side that s46 verified live.

**Architecture:** The ymaps provider narrows to "draw a map, place markers, move the camera". The
list panel, point card, search view and type filter become framework code shared by any provider.
The modal shell is extracted from the shipping module into a general-purpose `WoodevModal` with a
public event surface. Points are grouped by rounded position before they ever reach the provider, so
co-located points get one marker and a tab bar instead of a broken balloon.

**Tech stack:** Yandex Maps JS API 2.1 (`ObjectManager`, `templateLayoutFactory`,
`control.SearchControl`, `geoQuery`, `map.margin`), vanilla ES5-style JS with UMD-ish dual export
(no build step — these files ship as-is), PHP 7.4+, Brain Monkey + Mockery, jest via `wp-scripts`.

**Spec:** `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md`. Read it first. Decision
IDs (D-1 … D-14) referenced below live there.

---

## Ground rules for every task

- **Branch:** `feat/pickup-map` (already exists, PR #149 open). Do not open a new branch.
- **Commit after every task.** Conventional Commits, English.
- **Run the FULL unit suite, not a targeted file.** PHPUnit silently runs only the first path when
  given several (gotcha `phpunit-multiple-file-args`), and adding a method to a Mockery-mocked class
  breaks unrelated tests (gotcha `mockery-mock-new-method-full-suite`).
- **Line length 120, measured by hand with tabs expanded to 4.** `phpcs` does not enforce it and does
  not scan `tests/` at all (gotcha `phpcs-does-not-enforce-line-length`).
- **Do not use Serena's `replace_content` on existing source** — it rewrites the whole file as CRLF
  on Windows (gotcha `serena-replace-content-eol-flip`). Use the built-in `Edit` tool.
- **Escaping rule, do not get it backwards:** point display fields arrive already `esc_html()`-escaped
  from `Pickup_Point::to_browser_array()` and must go into `innerHTML` as-is; i18n labels and
  `selectable.reason` are NOT pre-escaped and must be escaped in JS before concatenation.
- **New PHP code is namespaced** (`Woodev\Framework\*`), short arrays only, type declarations and
  docblocks on everything public/protected.

### Commands

```bash
composer test:unit                 # full PHP unit suite — the gate
composer phpcs                     # style
npm run test:js                    # full jest suite
npx wp-scripts test-unit-js tests/js/woodev-modal.test.js   # one jest file
php bin/generate-class-map.php     # after ANY PHP file add/move/delete
```

Integration suite (from PowerShell, or Bash with the MSYS guard):

```bash
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration \
  php /var/www/html/woodev-framework/vendor/bin/phpunit \
  --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage
```

---

## File structure

**Created**

| File | Responsibility |
|---|---|
| `woodev/assets/js/frontend/woodev-modal.js` | Generic modal: shell, backdrop, focus trap, Esc, focus return, events. Knows nothing about pickup. |
| `woodev/assets/css/frontend/woodev-modal.css` | Modal chrome + WC-pattern responsive breakpoint. |
| `woodev/shipping-method/assets/js/frontend/pickup-geo.js` | Pure functions: position grouping, haversine, distance formatting, nearest-N, bounds fitting. No DOM, no ymaps. |
| `woodev/shipping-method/assets/js/frontend/pickup-panels.js` | List panel, point card, tab bar, search view, type filter. Owns all panel DOM. |
| `tests/js/woodev-modal.test.js`, `tests/js/pickup-geo.test.js`, `tests/js/pickup-panels.test.js` | jest coverage for the above. |

**Modified**

| File | Change |
|---|---|
| `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js` | Rewritten: map, markers, camera only. ~1477 → ~400 lines. |
| `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js` | Declares `owns_chrome`. |
| `woodev/shipping-method/assets/js/frontend/pickup-mount.js` | New config shape; emits pickup-layer events. |
| `woodev/shipping-method/assets/css/frontend/pickup.css` | Rewritten for the new panels; modal chrome removed. |
| `woodev/shipping-method/map/interface-map-provider.php` | `owns_chrome()`. |
| `woodev/shipping-method/map/class-yandex-map-provider.php` | `lang`, `layers`, `copyrights`; locale fallback. |
| `woodev/shipping-method/map/class-embedded-map-provider.php` | `owns_chrome()` returns true. |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | Icons, default location, new i18n keys, `woodev-modal` dependency. |
| `woodev/shipping-method/pickup/class-pickup-point.php` | `services`. |
| `woodev/shipping-method/pickup/class-point-query.php` | `types`. |
| `woodev/shipping-method/rest-api/class-pickup-controller.php` | `types`. |
| `woodev/class-map.php` | Regenerated. |

**Deleted**

- `woodev/shipping-method/assets/js/frontend/pickup-modal.js` (moved — see T1)
- `tests/js/pickup-modal.test.js` (renamed — see T1)

> **Deletion has a tail.** The wiring names the *path*, not the class, so a class-name grep misses it.
> After deleting or moving any file, grep for its filename across `woodev/`, `tests/`, every
> `includes()` method and every fixture bootstrap (gotcha
> `file-deletion-tail-includes-classmap-fixtures`).

---

## Phase A — the generic modal

### Task 1: Move the modal out of the shipping module, unchanged

Pure move + rename. No behaviour change, so the existing tests must pass with only their imports and
the global name touched. Doing this first means every later task references the final path.

**Files:**
- Create: `woodev/assets/js/frontend/woodev-modal.js`
- Delete: `woodev/shipping-method/assets/js/frontend/pickup-modal.js`
- Create: `tests/js/woodev-modal.test.js` (from `tests/js/pickup-modal.test.js`)
- Delete: `tests/js/pickup-modal.test.js`
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php` (registration, ~line 630 and ~644)

- [ ] **Step 1: Copy the file and rename the exported symbol**

`git mv woodev/shipping-method/assets/js/frontend/pickup-modal.js woodev/assets/js/frontend/woodev-modal.js`,
then inside it rename `WoodevPickupModal` → `WoodevModal` everywhere, including both export sites:

```js
if ( typeof window !== 'undefined' ) {
    window.WoodevModal = WoodevModal;
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = WoodevModal;
}
```

Update the file docblock: it is no longer "the pickup modal", it is the framework's generic dialog.
Strip any sentence that describes it as pickup-specific.

- [ ] **Step 2: Move the test file and fix its require path**

`git mv tests/js/pickup-modal.test.js tests/js/woodev-modal.test.js`, then update the `require()` to
`../../woodev/assets/js/frontend/woodev-modal` and rename the symbol in the test body.

- [ ] **Step 3: Run the full jest suite — it must be green with no behaviour change**

Run: `npm run test:js`
Expected: every existing modal test passes. A failure here means the move was not pure.

- [ ] **Step 4: Repoint the PHP registration**

In `Pickup_Handler::enqueue_assets()`, replace the line registering the old path:

```php
// before
$this->enqueue_script_if_built( 'woodev-pickup-modal', 'js/frontend/pickup-modal.js', [] );
```

The modal is no longer a shipping asset, so `enqueue_script_if_built()` (which resolves paths
relative to the shipping module) cannot register it. Register `woodev-modal` from the framework's own
frontend asset path instead, and list it as a dependency where `woodev-pickup-modal` was listed:

```php
[ 'jquery', 'woodev-modal', 'woodev-pickup-datasource', $provider_handle ]
```

- [ ] **Step 5: Hunt the deletion tail**

Run: `grep -rn "pickup-modal\|WoodevPickupModal" woodev/ tests/ src/ 2>/dev/null`
Expected: no hits. Any hit is a broken reference — fix it before committing.

- [ ] **Step 6: Full suites + commit**

```bash
composer test:unit && npm run test:js && composer phpcs
git add -A
git commit -m "refactor(shipping): move the pickup modal to woodev/assets as the generic WoodevModal"
```

---

### Task 2: Give the modal its public event surface (D-14)

**Files:**
- Modify: `woodev/assets/js/frontend/woodev-modal.js`
- Test: `tests/js/woodev-modal.test.js`

- [ ] **Step 1: Write the failing tests**

```js
describe( 'WoodevModal events', () => {
	const listen = ( type ) => {
		const seen = [];
		document.body.addEventListener( type, ( e ) => seen.push( e ) );
		return seen;
	};

	it( 'fires woodev_modal_opened with modalId and context', () => {
		const seen = listen( 'woodev_modal_opened' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T', context: { a: 1 } } );
		modal.open();

		expect( seen ).toHaveLength( 1 );
		expect( seen[ 0 ].detail ).toEqual( { modalId: 'test-modal', context: { a: 1 } } );
		expect( seen[ 0 ].bubbles ).toBe( true );
	} );

	it( 'fires before_close then closed, carrying the reason', () => {
		const before = listen( 'woodev_modal_before_close' );
		const closed = listen( 'woodev_modal_closed' );
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );

		modal.open();
		modal.close( 'escape' );

		expect( before[ 0 ].detail ).toEqual( { modalId: 'test-modal', reason: 'escape' } );
		expect( closed[ 0 ].detail ).toEqual( { modalId: 'test-modal', reason: 'escape' } );
	} );

	it( 'aborts the close when before_close is prevented', () => {
		const modal = new WoodevModal( { modalId: 'test-modal', title: 'T' } );
		const closed = listen( 'woodev_modal_closed' );

		document.body.addEventListener( 'woodev_modal_before_close', ( e ) => e.preventDefault() );
		modal.open();
		modal.close( 'button' );

		expect( closed ).toHaveLength( 0 );
		expect( document.querySelector( '.woodev-modal' ) ).not.toBeNull();
	} );

	it( 'is visible to jQuery .on() as well as addEventListener', () => {
		const calls = [];
		window.jQuery( document.body ).on( 'woodev_modal_opened', () => calls.push( 1 ) );

		new WoodevModal( { modalId: 'test-modal', title: 'T' } ).open();

		expect( calls ).toHaveLength( 1 );
	} );
} );
```

The jQuery test needs a stub. If `tests/js/` has no jQuery helper yet, add the smallest real one —
do **not** assert against a hand-rolled fake that cannot prove the native/jQuery bridge:

```js
// at the top of the test file
global.window.jQuery = require( 'jquery' );
```

If `jquery` is not a devDependency, add it: `npm i -D jquery`.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/woodev-modal.test.js`
Expected: FAIL — `modal.close is not a function` / no events observed.

- [ ] **Step 3: Implement**

Add one private helper and call it at the three lifecycle points:

```js
/**
 * Dispatches a framework modal event on `document.body`.
 *
 * A native CustomEvent with `bubbles: true` is seen by BOTH `addEventListener` and jQuery's
 * `.on()`. The reverse does not hold: `jQuery.trigger()` on a custom type creates no native
 * event, so a jQuery-dispatched event would be invisible to `addEventListener`. See
 * `pickup-mount.js`'s docblock on `updated_checkout` for the same asymmetry.
 *
 * @param {string}  type       event name.
 * @param {Object}  detail     event payload.
 * @param {boolean} cancelable whether `preventDefault()` is honoured by the caller.
 * @returns {boolean} false when a listener cancelled a cancelable event.
 */
function emit( type, detail, cancelable ) {
	var event = new CustomEvent( type, {
		detail: detail,
		bubbles: true,
		cancelable: !! cancelable,
	} );

	return document.body.dispatchEvent( event );
}
```

`open()` ends with `emit( 'woodev_modal_opened', { modalId: this.modalId, context: this.context } )`
— fired **after** the DOM is in place and focus is trapped, so a listener can query the rendered
tree.

`close( reason )` starts with the cancelable gate and returns early when refused:

```js
WoodevModal.prototype.close = function( reason ) {
	var payload = { modalId: this.modalId, reason: reason || 'button' };

	if ( ! emit( 'woodev_modal_before_close', payload, true ) ) {
		return false;
	}

	this._teardown();
	emit( 'woodev_modal_closed', payload );

	return true;
};
```

Every existing close path passes its own reason: Esc → `'escape'`, backdrop click → `'backdrop'`,
header button → `'button'`. `'select'` is passed by the pickup layer in T18.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS, and every pre-existing modal test still green.

- [ ] **Step 5: Commit**

```bash
git add woodev/assets/js/frontend/woodev-modal.js tests/js/woodev-modal.test.js package.json
git commit -m "feat(modal): public event surface with a cancelable before_close"
```

---

### Task 3: Modal chrome CSS with the WooCommerce responsive pattern (D-13)

**Files:**
- Create: `woodev/assets/css/frontend/woodev-modal.css`
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css` (remove modal chrome)
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php` (enqueue the new stylesheet)

- [ ] **Step 1: Write the stylesheet**

Reference for the responsive rules:
`plugins-reference/woocommerce-yandex-delivery/assets/css/frontend/backbone-modal.css`.

```css
.woodev-modal * { box-sizing: border-box; }

.woodev-modal__content {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate( -50%, -50% );
    z-index: 100000;
    background: #fff;
    max-width: 100%;
    min-width: 920px;
}

.woodev-modal-backdrop {
    position: fixed;
    inset: 0;
    min-height: 360px;
    background: #000;
    opacity: 0.7;
    z-index: 99900;
}

.woodev-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 5px 10px;
    background: #fcfcfc;
    border-bottom: 1px solid #ddd;
}

.woodev-modal__header h1 { margin: 0; font-size: 18px; font-weight: 700; line-height: 1.5em; }

.woodev-modal__close {
    height: 38px;
    width: 38px;
    padding: 0;
    border: 0;
    border-left: 1px solid #ddd;
    background-color: transparent;
    color: #777;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: color 0.1s ease-in-out, background 0.1s ease-in-out;
}

.woodev-modal__close:hover,
.woodev-modal__close:focus { background: #ddd; border-color: #ccc; color: #000; }
.woodev-modal__close:focus { outline: none; }

.woodev-modal__body { display: block; position: relative; }

@media screen and ( max-width: 782px ) {
    .woodev-modal__content { width: 100%; height: 100%; min-width: 100%; }
}
```

782px is WooCommerce's own breakpoint. Use the same number in `pickup.css` for the panels so the
feature has one breakpoint, not two.

- [ ] **Step 2: Strip modal chrome out of `pickup.css`**

Delete every rule in `pickup.css` that styles the dialog frame, backdrop, header or close button.
`pickup.css` keeps only what lives *inside* the body: map canvas, panels, markers.

Run: `grep -n "backdrop\|modal__\|modal-close\|modal-content" woodev/shipping-method/assets/css/frontend/pickup.css`
Expected: no hits.

- [ ] **Step 3: Enqueue it**

In `Pickup_Handler::enqueue_assets()`, enqueue `woodev-modal` (style) alongside the script, from the
framework asset path, versioned by its own `filemtime` — not by the JS bundle's hash (gotcha
`wp-scripts-css-enqueue-version-by-mtime`).

- [ ] **Step 4: Verify nothing else referenced the removed rules**

Run: `grep -rn "wc-backbone-modal\|woodev-pickup-modal" woodev/ tests/`
Expected: no hits.

- [ ] **Step 5: Commit**

```bash
git add woodev/assets/css/frontend/woodev-modal.css \
        woodev/shipping-method/assets/css/frontend/pickup.css \
        woodev/shipping-method/pickup/class-pickup-handler.php
git commit -m "feat(modal): extract modal chrome into its own stylesheet with the WC responsive pattern"
```

---

## Phase B — PHP contracts

### Task 4: `Pickup_Point::services` (D-9)

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-point.php`
- Test: `tests/unit/Shipping/Pickup/PickupPointTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function test_services_default_to_an_empty_array(): void {
    $point = $this->make_point( [] );

    $this->assertSame( [], $point->to_array()['services'] );
}

public function test_services_are_escaped_for_the_browser(): void {
    $point = $this->make_point( [ 'services' => [ 'Примерка', 'A & B' ] ] );

    $this->assertSame( [ 'Примерка', 'A &amp; B' ], $point->to_browser_array()['services'] );
}

public function test_non_string_services_are_dropped(): void {
    $point = $this->make_point( [ 'services' => [ 'Примерка', [ 'x' ], null, 5 ] ] );

    $this->assertSame( [ 'Примерка' ], $point->to_array()['services'] );
}
```

The third case is not paranoia: a carrier adapter that maps an object list without flattening it
would otherwise put an array into `esc_html()` and fatal on PHP 8.

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupPointTest.php`
Expected: FAIL — undefined index `services`.

- [ ] **Step 3: Implement**

Add the property, constructor handling (filter to non-empty strings, `array_values()` to reindex —
`array_filter` preserves keys and `wp_json_encode` would then emit a JSON **object**, gotcha
`php-stdlib-traps-that-survive-tests`), the `to_array()` entry, and `'services'` handling in
`to_browser_array()` via `array_map( 'esc_html', … )` next to `payment_methods`.

- [ ] **Step 4: Run the FULL unit suite**

Run: `composer test:unit`
Expected: PASS. Not the single file — a new field on a widely-constructed value object breaks
fixtures elsewhere.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/pickup/class-pickup-point.php tests/unit/Shipping/Pickup/PickupPointTest.php
git commit -m "feat(shipping): carry per-point services on Pickup_Point"
```

---

### Task 5: `types` on the point query and the REST route (D-10)

**Files:**
- Modify: `woodev/shipping-method/pickup/class-point-query.php`
- Modify: `woodev/shipping-method/rest-api/class-pickup-controller.php`
- Test: `tests/unit/Shipping/Pickup/PointQueryTest.php`, `tests/unit/Shipping/RestApi/PickupControllerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function test_types_default_to_an_empty_array_meaning_all_types(): void {
    $query = Point_Query::from_request( [] );

    $this->assertSame( [], $query->get_types() );
}

public function test_types_are_parsed_from_a_comma_separated_list(): void {
    $query = Point_Query::from_request( [ 'types' => 'pvz,postamat' ] );

    $this->assertSame( [ 'pvz', 'postamat' ], $query->get_types() );
}

public function test_blank_and_duplicate_type_codes_are_dropped(): void {
    $query = Point_Query::from_request( [ 'types' => 'pvz,,pvz, postamat ' ] );

    $this->assertSame( [ 'pvz', 'postamat' ], $query->get_types() );
}
```

Empty means "all types" — an explicit "none selected" is not representable and must not be, because
the filter UI forbids deselecting the last checkbox (T17).

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PointQueryTest.php`
Expected: FAIL — `get_types()` undefined.

- [ ] **Step 3: Implement**

`Point_Query` gains `private array $types` with a `get_types(): array` accessor, parsed in
`from_request()` by splitting on commas, trimming, dropping blanks, de-duplicating and reindexing
with `array_values()`.

`Pickup_Controller` registers `types` as a `string` arg with a `sanitize_callback` of
`sanitize_text_field` and passes it through to `Point_Query::from_request()`.

- [ ] **Step 4: Run the full unit suite**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 5: Document the obligation on `Point_Source`**

Add to the `Point_Source` interface docblock for the points method: implementations under the
`viewport` strategy MUST honour `Point_Query::get_types()` and return only matching points; an empty
array means all types. Under `bulk` the framework filters client-side and the source may ignore it.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/pickup/ woodev/shipping-method/rest-api/ tests/unit/Shipping/
git commit -m "feat(shipping): accept a type filter on the pickup point query and REST route"
```

---

### Task 6: `Map_Provider::owns_chrome()` (D-3)

**Files:**
- Modify: `woodev/shipping-method/map/interface-map-provider.php`
- Modify: `woodev/shipping-method/map/class-yandex-map-provider.php`
- Modify: `woodev/shipping-method/map/class-embedded-map-provider.php`
- Test: `tests/unit/Shipping/Map/YandexMapProviderTest.php`, `.../EmbeddedMapProviderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// YandexMapProviderTest
public function test_the_yandex_provider_does_not_own_the_chrome(): void {
    $this->assertFalse( $this->make_provider()->owns_chrome() );
}

// EmbeddedMapProviderTest
public function test_the_embedded_provider_owns_the_whole_container(): void {
    $this->assertTrue( $this->make_provider()->owns_chrome() );
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `composer test:unit`
Expected: FAIL — `owns_chrome()` undefined.

- [ ] **Step 3: Implement**

Add `public function owns_chrome(): bool;` to the interface with a docblock stating the contract:
`true` means the framework renders no panels and hands the container to the provider whole; `false`
means the provider draws only the map canvas and the framework owns list, card, search and filter.

Yandex returns `false`, embedded returns `true`.

- [ ] **Step 4: Run the full unit suite**

Run: `composer test:unit`
Expected: PASS. Adding an interface method breaks any test double that mocks the interface — fix
those doubles now, not later.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/map/ tests/unit/Shipping/Map/
git commit -m "feat(shipping): declare on the Map_Provider seam who owns the container chrome"
```

---

### Task 7: locale, tile layers and copyrights in `mapConfig` (D-8, D-12)

**Files:**
- Modify: `woodev/shipping-method/map/class-yandex-map-provider.php`
- Test: `tests/unit/Shipping/Map/YandexMapProviderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
/** @dataProvider provide_locales */
public function test_locale_resolution( string $site_locale, string $expected ): void {
    Functions\when( 'get_locale' )->justReturn( $site_locale );

    $this->assertSame( $expected, $this->make_provider()->get_js_config( [] )['lang'] );
}

public function provide_locales(): array {
    return [
        'exact ru_RU'        => [ 'ru_RU', 'ru_RU' ],
        'exact en_US'        => [ 'en_US', 'en_US' ],
        'exact en_RU'        => [ 'en_RU', 'en_RU' ],
        'exact ru_UA'        => [ 'ru_UA', 'ru_UA' ],
        'exact uk_UA'        => [ 'uk_UA', 'uk_UA' ],
        'exact tr_TR'        => [ 'tr_TR', 'tr_TR' ],
        'unsupported de_DE'  => [ 'de_DE', 'en_US' ],
        'unsupported en_GB'  => [ 'en_GB', 'en_US' ],
        'empty'              => [ '', 'en_US' ],
    ];
}

public function test_layers_and_copyrights_default_to_empty(): void {
    $config = $this->make_provider()->get_js_config( [] );

    $this->assertSame( [], $config['layers'] );
    $this->assertSame( [], $config['copyrights'] );
}

public function test_the_lang_in_the_script_url_matches_the_lang_field(): void {
    Functions\when( 'get_locale' )->justReturn( 'de_DE' );
    $config = $this->make_provider()->get_js_config( [] );

    $this->assertStringContainsString( 'lang=en_US', $config['scriptUrl'] );
    $this->assertSame( 'en_US', $config['lang'] );
}
```

`en_GB → en_US` is the deliberate change from the current `en_* → en_US` special case being kept but
the previously-considered `en_* → en_RU` mapping being rejected (D-12). The last test exists because
these two values are computed in different methods and drifting apart is silent.

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Map/YandexMapProviderTest.php`
Expected: FAIL — no `lang` key.

- [ ] **Step 3: Implement**

Change `DEFAULT_LANG` from `'ru_RU'` to `'en_US'` and simplify `resolve_lang()` to: exact match in
`ACCEPTED_LANGS` wins, otherwise `DEFAULT_LANG`. Remove the `en_` prefix branch — with `en_US` as
the default it is now dead code that only obscures the rule.

`get_js_config()` returns `lang`, `layers` and `copyrights` alongside the existing keys. `layers` and
`copyrights` come from constructor arguments defaulting to `[]`, so a plugin opts in explicitly.

Document on `ACCEPTED_LANGS` that the region drives units — `RU`/`UA`/`TR` give kilometres, `US`
gives miles — and that this is a known, accepted consequence of the `en_US` fallback (D-12).

- [ ] **Step 4: Run the full unit suite**

Run: `composer test:unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/map/class-yandex-map-provider.php tests/unit/Shipping/Map/YandexMapProviderTest.php
git commit -m "feat(shipping): emit the resolved locale and optional tile layers in mapConfig"
```

---

### Task 8: required default viewport and optional point icons (D-5, D-7)

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function test_the_default_location_reaches_the_browser_config(): void {
    $handler = $this->make_handler( [ 'default_location' => [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ] ] );

    $this->assertSame(
        [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ],
        $handler->get_js_config()['defaultLocation']
    );
}

public function test_icons_are_passed_through_with_active_falling_back_to_default(): void {
    $handler = $this->make_handler( [
        'point_icons' => [ 'postamat' => [ 'default' => 'https://example.test/pm.svg' ] ],
    ] );

    $this->assertSame(
        [ 'postamat' => [ 'default' => 'https://example.test/pm.svg', 'active' => 'https://example.test/pm.svg' ] ],
        $handler->get_js_config()['pointIcons']
    );
}

public function test_icon_urls_are_escaped_for_a_json_payload(): void {
    $handler = $this->make_handler( [
        'point_icons' => [ 'pvz' => [ 'default' => 'https://example.test/a.svg?x=1&y=2' ] ],
    ] );

    // esc_url_raw, NOT esc_url: this is JSON, and esc_url would turn & into &#038;
    $this->assertStringContainsString( '&y=2', $handler->get_js_config()['pointIcons']['pvz']['default'] );
}
```

That last one is gotcha `esc-url-raw-for-js-consumed-urls` written as a test.

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php`
Expected: FAIL — no `defaultLocation` key.

- [ ] **Step 3: Implement**

`Pickup_Handler`'s constructor gains `array $default_location` as a **required** parameter placed
before the optional ones, and `array $point_icons = []`. Validate the default location on
construction — `center` must be two floats, `zoom` an int — and throw `InvalidArgumentException`
otherwise. A required argument that silently accepts nonsense is not an obligation.

Normalise icons once, at config-build time: fill `active` from `default` when absent, run every URL
through `esc_url_raw()`, drop any type whose `default` is missing.

Add the new i18n keys the panels need. Every key must be exact — the panels read them by name and a
missing key renders **blank**, never a hardcoded Russian fallback:

```php
'services'        => __( 'Услуги', 'woodev-plugin-framework' ),
'yourAddress'     => __( 'Ваш адрес', 'woodev-plugin-framework' ),
'nearestTo'       => __( 'Ближайшие к «%s»', 'woodev-plugin-framework' ),
'resetSearch'     => __( 'Сбросить', 'woodev-plugin-framework' ),
'nothingNearby'   => __( 'Рядом с этим адресом пунктов выдачи нет.', 'woodev-plugin-framework' ),
'showNearest'     => __( 'Показать ближайший', 'woodev-plugin-framework' ),
'continueCheckout'=> __( 'Продолжить оформление заказа', 'woodev-plugin-framework' ),
'zoomIn'          => __( 'Приблизьте карту, чтобы увидеть пункты выдачи', 'woodev-plugin-framework' ),
'sectionPoints'   => __( 'Пункты выдачи', 'woodev-plugin-framework' ),
'sectionAddresses'=> __( 'Адреса', 'woodev-plugin-framework' ),
'filterTypes'     => __( 'Тип пунктов', 'woodev-plugin-framework' ),
'emptyInView'     => __( 'В этой области пунктов выдачи нет', 'woodev-plugin-framework' ),
```

- [ ] **Step 4: Run the full unit suite**

Run: `composer test:unit`
Expected: PASS. Every existing test that constructs `Pickup_Handler` must be updated for the new
required argument — that is the point of making it required.

- [ ] **Step 5: Update the test fixture**

`tests/_fixtures/woodev-test-shipping-method/` constructs the handler. Give it a default location and
two icon sets so the rig exercises both types and the group badge. Remember: a fixture class that
`implements` a framework interface must be declared **inside** the plugin's init callback (gotcha
`fixture-classes-must-live-inside-plugin-init`).

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/pickup/class-pickup-handler.php tests/
git commit -m "feat(shipping): require a default viewport and accept plugin point icons"
```

---

## Phase C — pure geometry, no map involved

### Task 9: `pickup-geo.js` — position grouping (D-4)

Everything in this phase is a pure function over plain data. It is the cheapest place to be correct,
and none of it needs ymaps, jsdom or a network.

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/pickup-geo.js`
- Test: `tests/js/pickup-geo.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const { groupByPosition } = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

const p = ( id, lat, lng, type ) => ( { id, lat, lng, type: { code: type, label: type } } );

describe( 'groupByPosition', () => {
	it( 'keeps distinct positions apart', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.7601, 37.6210 ) ] );

		expect( groups ).toHaveLength( 2 );
		expect( groups.every( ( g ) => g.points.length === 1 ) ).toBe( true );
	} );

	it( 'folds identical coordinates into one group', () => {
		const groups = groupByPosition( [
			p( 'a', 55.7558, 37.6173, 'pvz' ),
			p( 'b', 55.7558, 37.6173, 'postamat' ),
		] );

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].points.map( ( x ) => x.id ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'folds coordinates that differ below the 4-decimal key', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.75580001, 37.61730002 ) ] );

		expect( groups ).toHaveLength( 1 );
	} );

	it( 'keeps coordinates apart at the 4-decimal boundary', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), p( 'b', 55.7559, 37.6173 ) ] );

		expect( groups ).toHaveLength( 2 );
	} );

	it( 'takes its position and icon from the first point of the group', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173, 'pvz' ), p( 'b', 55.7558, 37.6173, 'postamat' ) ] );

		expect( groups[ 0 ].lat ).toBe( 55.7558 );
		expect( groups[ 0 ].typeCode ).toBe( 'pvz' );
		expect( groups[ 0 ].size ).toBe( 2 );
	} );

	it( 'preserves input order of groups so the sidebar is stable', () => {
		const groups = groupByPosition( [ p( 'b', 55.99, 37.99 ), p( 'a', 55.11, 37.11 ) ] );

		expect( groups.map( ( g ) => g.points[ 0 ].id ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'skips points without usable coordinates instead of grouping them at 0,0', () => {
		const groups = groupByPosition( [ p( 'a', 55.7558, 37.6173 ), { id: 'x' }, p( 'c', null, null ) ] );

		expect( groups ).toHaveLength( 1 );
	} );
} );
```

The boundary test and the null-coordinate test are the two that matter. A `null` latitude coerced
through `Number()` becomes `0`, and every broken point would silently cluster off the coast of
Africa — the mutation-sweep lesson is that value bugs survive branch coverage
(`mutation-sweep-branch-only-false-confidence`).

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-geo.test.js`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Implement**

```js
/** @type {number} decimals in the grouping key — 4 ≈ 11 m, see spec D-4. */
var POSITION_PRECISION = 4;

/**
 * Groups points that share a map position, so co-located points get ONE marker and a tab bar
 * instead of a permanently clustered placemark nobody can open. See spec D-4.
 *
 * @param {Array} points normalized points from the dataSource.
 * @returns {Array} groups, in first-seen order: { key, lat, lng, typeCode, size, points }.
 */
function groupByPosition( points ) {
	var byKey = {};
	var order = [];

	( points || [] ).forEach( function( point ) {
		if ( ! point || ! isFiniteNumber( point.lat ) || ! isFiniteNumber( point.lng ) ) {
			return;
		}

		var key = point.lat.toFixed( POSITION_PRECISION ) + ',' + point.lng.toFixed( POSITION_PRECISION );

		if ( ! Object.prototype.hasOwnProperty.call( byKey, key ) ) {
			byKey[ key ] = {
				key: key,
				lat: point.lat,
				lng: point.lng,
				typeCode: ( point.type && point.type.code ) || '',
				size: 0,
				points: [],
			};
			order.push( key );
		}

		byKey[ key ].points.push( point );
		byKey[ key ].size = byKey[ key ].points.length;
	} );

	return order.map( function( key ) { return byKey[ key ]; } );
}

function isFiniteNumber( value ) {
	return 'number' === typeof value && isFinite( value );
}
```

Export via the same UMD-ish dual pattern the sibling files use
(`window.WoodevPickupGeo` + `module.exports`).

- [ ] **Step 4: Run to verify they pass**

Run: `npx wp-scripts test-unit-js tests/js/pickup-geo.test.js`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-geo.js tests/js/pickup-geo.test.js
git commit -m "feat(shipping): group pickup points by rounded position"
```

---

### Task 10: distance, formatting, nearest-N and bounds fitting (D-6, D-12)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-geo.js`
- Test: `tests/js/pickup-geo.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const { distanceMeters, formatDistance, nearest, boundsFor } =
	require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

describe( 'distanceMeters', () => {
	it( 'is zero for the same point', () => {
		expect( distanceMeters( [ 55.75, 37.61 ], [ 55.75, 37.61 ] ) ).toBe( 0 );
	} );

	it( 'matches a known distance within 1%', () => {
		// Red Square → Moscow City, ≈ 7.0 km
		const d = distanceMeters( [ 55.7539, 37.6208 ], [ 55.7473, 37.5389 ] );

		expect( d ).toBeGreaterThan( 5000 );
		expect( d ).toBeLessThan( 5300 );
	} );

	it( 'is symmetric', () => {
		const a = [ 55.75, 37.61 ], b = [ 59.93, 30.33 ];

		expect( distanceMeters( a, b ) ).toBeCloseTo( distanceMeters( b, a ), 3 );
	} );
} );

describe( 'formatDistance', () => {
	it( 'uses metres below a kilometre for a metric region', () => {
		expect( formatDistance( 430, 'ru_RU' ) ).toBe( '430 м' );
	} );

	it( 'uses kilometres with one decimal above a kilometre for a metric region', () => {
		expect( formatDistance( 1240, 'ru_RU' ) ).toBe( '1.2 км' );
	} );

	it( 'uses miles for the US region', () => {
		expect( formatDistance( 1609.34, 'en_US' ) ).toBe( '1.0 mi' );
	} );

	it( 'treats en_RU as metric — the region decides, not the language', () => {
		expect( formatDistance( 1240, 'en_RU' ) ).toBe( '1.2 km' );
	} );
} );

describe( 'nearest', () => {
	const groups = [
		{ key: 'far',  lat: 55.80, lng: 37.61 },
		{ key: 'near', lat: 55.7501, lng: 37.61 },
		{ key: 'mid',  lat: 55.76, lng: 37.61 },
	];

	it( 'returns the N closest, closest first', () => {
		expect( nearest( groups, [ 55.75, 37.61 ], 2 ).map( ( g ) => g.key ) ).toEqual( [ 'near', 'mid' ] );
	} );

	it( 'returns everything when there are fewer than N', () => {
		expect( nearest( groups, [ 55.75, 37.61 ], 99 ) ).toHaveLength( 3 );
	} );

	it( 'returns an empty array when there is nothing to rank', () => {
		expect( nearest( [], [ 55.75, 37.61 ], 3 ) ).toEqual( [] );
	} );
} );

describe( 'boundsFor', () => {
	it( 'spans the anchor and every supplied group', () => {
		const b = boundsFor( [ 55.75, 37.61 ], [ { lat: 55.80, lng: 37.70 }, { lat: 55.70, lng: 37.50 } ] );

		expect( b ).toEqual( [ [ 55.70, 37.50 ], [ 55.80, 37.70 ] ] );
	} );

	it( 'returns a degenerate box when only the anchor is known', () => {
		expect( boundsFor( [ 55.75, 37.61 ], [] ) ).toEqual( [ [ 55.75, 37.61 ], [ 55.75, 37.61 ] ] );
	} );
} );
```

`formatDistance( 1240, 'en_RU' )` is the test that pins D-12's actual rule: units follow the
**region**, not the language, so `en_RU` is metric with English units.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-geo.test.js`
Expected: FAIL — `distanceMeters is not a function`.

- [ ] **Step 3: Implement**

Haversine with `R = 6371008.8` m. `formatDistance` reads the region after `_`, treats `US` as
imperial and everything else as metric, picks the unit word from the language part (`ru` → `м`/`км`,
otherwise `m`/`km`/`mi`), and switches from whole units to one decimal at the 1 km / 1 mi mark.
`nearest` maps each group to its distance, sorts ascending and slices. `boundsFor` reduces to
`[[minLat, minLng], [maxLat, maxLng]]`.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-geo.js tests/js/pickup-geo.test.js
git commit -m "feat(shipping): distance, locale-aware formatting, nearest-N and bounds helpers"
```

---

### Task 11: matching points by free text (D-6)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-geo.js`
- Test: `tests/js/pickup-geo.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const { matchPoints } = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

const pool = [
	{ id: '1', name: 'ПВЗ «Магнит»', address: 'Москва, Ленина 5', short_address: 'Ленина, 5',
	  instruction: 'вход со двора', postal_code: '101000' },
	{ id: '2', name: 'Постамат №4', address: 'Москва, Тверская 12', short_address: 'Тверская, 12',
	  instruction: '', postal_code: '125009' },
];

it( 'matches on the point name, case-insensitively', () => {
	expect( matchPoints( pool, 'магнит' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
} );

it( 'matches on the address', () => {
	expect( matchPoints( pool, 'тверская' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
} );

it( 'matches on the postal code exactly', () => {
	expect( matchPoints( pool, '125009' ).map( ( p ) => p.id ) ).toEqual( [ '2' ] );
} );

it( 'matches on the how-to-get-there instruction', () => {
	expect( matchPoints( pool, 'со двора' ).map( ( p ) => p.id ) ).toEqual( [ '1' ] );
} );

it( 'returns nothing for a blank or too-short query', () => {
	expect( matchPoints( pool, '' ) ).toEqual( [] );
	expect( matchPoints( pool, 'ул' ) ).toEqual( [] );
} );

it( 'ignores the HTML entities that server-side escaping introduces', () => {
	const escaped = [ { id: '3', name: 'ПВЗ &quot;Ромашка&quot;', address: '', short_address: '',
	                    instruction: '', postal_code: '' } ];

	expect( matchPoints( escaped, 'ромашка' ).map( ( p ) => p.id ) ).toEqual( [ '3' ] );
} );
```

The last test is the non-obvious one: these fields arrive `esc_html()`-escaped, so a point literally
named `ПВЗ "Ромашка"` is stored as `ПВЗ &quot;Ромашка&quot;`. Searching the raw string for a quote
would never match, and searching for `ромашка` must not be broken by the entity either.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-geo.test.js`
Expected: FAIL — `matchPoints is not a function`.

- [ ] **Step 3: Implement**

Minimum query length 3. Decode entities once per field by assigning to a detached element's
`innerHTML` and reading `textContent`, lowercase both sides, then substring-match `name`, `address`,
`short_address` and `instruction`, plus an exact match on `postal_code`.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-geo.js tests/js/pickup-geo.test.js
git commit -m "feat(shipping): match pickup points by free text over the loaded pool"
```

---

## Phase D — the panels

### Task 12: the list panel

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const Panels = require( '../../woodev/shipping-method/assets/js/frontend/pickup-panels' );

const config = {
	lang: 'ru_RU',
	i18n: { drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет' },
};

const group = ( id, lat, lng, name ) => ( {
	key: id, lat, lng, size: 1,
	points: [ { id, name, short_address: name + ' addr', locality: 'Москва' } ],
} );

it( 'starts closed', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();

	expect( panels.root.querySelector( '.woodev-pickup-list' ).classList.contains( 'is-open' ) ).toBe( false );
} );

it( 'sorts by distance from the anchor', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setAnchor( [ 55.75, 37.61 ] );
	panels.setVisible( [ group( 'far', 55.90, 37.61 ), group( 'near', 55.7501, 37.61 ) ] );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'near', 'far' ] );
} );

it( 'caps the rendered list at 300 items', () => {
	const many = Array.from( { length: 400 }, ( _, i ) => group( 'g' + i, 55.75 + i / 10000, 37.61 ) );
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( many );

	expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 300 );
} );

it( 'shows the empty state when nothing is in view', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [] );

	expect( panels.root.querySelector( '.woodev-pickup-list__empty' ).textContent )
		.toBe( 'В этой области пунктов выдачи нет' );
} );

it( 'reports its open state and width so the caller can set the map margin', () => {
	const seen = [];
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.on( 'listToggle', ( e ) => seen.push( e ) );
	panels.render();
	panels.toggleList();

	expect( seen[ 0 ].open ).toBe( true );
	expect( typeof seen[ 0 ].width ).toBe( 'number' );
} );

it( 'renders a blank label rather than a Russian default when an i18n key is missing', () => {
	const panels = new Panels( document.createElement( 'div' ), { lang: 'ru_RU', i18n: {} } );
	panels.render();

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent ).toBe( '' );
} );
```

The last test enforces rule I1 from `pickup-mount.js`: a missing key renders blank and loud, never a
hardcoded Russian string that happens to read the same and hides a PHP↔JS key mismatch.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-panels.test.js`
Expected: FAIL — cannot find module.

- [ ] **Step 3: Implement**

`Panels( container, config )` builds `.woodev-pickup-list` with a header and a list body, closed by
default, plus the toggle button. `setAnchor( latLng | null )`, `setVisible( groups )`,
`toggleList()`, `on( event, cb )`. Sorting uses `pickup-geo`'s `distanceMeters` against the anchor,
falling back to the map centre the caller supplies. Distances are rendered only when an anchor is
set. Cap the rendered slice at 300; `log`-style silence about the drop is not acceptable — put the
cap in the header count so the customer sees `300+`.

Point display fields go into `innerHTML` **as-is** (already escaped); the header label and the empty
state come from `i18n` and must be escaped in JS.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(shipping): viewport-synced pickup list panel, closed by default"
```

---

### Task 13: the point card, with services and CTA states

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const cardConfig = { lang: 'ru_RU', i18n: {
	select: 'Забрать здесь', continueCheckout: 'Продолжить оформление заказа',
	services: 'Услуги', paymentMethods: 'Способы оплаты', howToGet: 'Как добраться',
	phone: 'Телефон', workTime: 'Часы работы', maxWeight: 'Максимальный вес', blocked: 'Недоступен',
} };

const point = ( over ) => Object.assign( {
	id: 'p1', name: 'ПВЗ «Магнит»', address: 'Москва, Ленина 5', short_address: 'Ленина, 5',
	postal_code: '101000', phone: '', instruction: '', work_time: '', max_weight: null,
	payment_methods: [], services: [], type: { code: 'pvz', label: 'ПВЗ' },
	selectable: { allowed: true, reason: null },
}, over );

it( 'renders services as chips', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { services: [ 'Примерка', 'Частичный выкуп' ] } ) ] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__service' ) ].map( ( n ) => n.textContent ) )
		.toEqual( [ 'Примерка', 'Частичный выкуп' ] );
} );

it( 'omits the services section entirely when there are none', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__services' ) ).toBeNull();
} );

it( 'disables the CTA and shows the reason when the point is not selectable', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: 'Оплата при получении недоступна' } } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( true );
	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent )
		.toBe( 'Оплата при получении недоступна' );
} );

it( 'switches the CTA when this point is already the selected one', () => {
	const panels = mount( cardConfig );
	panels.setSelectedId( 'p1' );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent )
		.toBe( 'Продолжить оформление заказа' );
} );

it( 'emits select with the point when the CTA is pressed', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen[ 0 ].id ).toBe( 'p1' );
} );

it( 'never emits select from a disabled CTA', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: 'нет' } } ) ] } );
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen ).toHaveLength( 0 );
} );

it( 'renders escaped point text without double-escaping it', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { name: 'ПВЗ &quot;Ромашка&quot;' } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'ПВЗ "Ромашка"' );
} );
```

That final test is the whole escaping contract in one assertion: the value must reach the DOM through
`innerHTML` so the parser decodes the entity. Setting `textContent` would render the literal
`&quot;` and this test would catch it.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-panels.test.js`
Expected: FAIL — `panels.openCard is not a function`.

- [ ] **Step 3: Implement**

`openCard( group )`, `closeCard()`, `setSelectedId( id )`. The card sits in the same corner as the
list with a higher `z-index`. Sections render only when their field is non-empty. The footer is
sticky and holds the warning plus the CTA.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(shipping): point card panel with service chips and CTA states"
```

---

### Task 14: the tab bar for co-located points (D-4)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing tests**

```js
const two = {
	key: 'k', size: 2,
	points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»', type: { code: 'pvz', label: 'ПВЗ' } } ),
		point( { id: 'b', name: 'Постамат №4', type: { code: 'postamat', label: 'Постамат' } } ),
	],
};

it( 'renders no tab bar for a single-point group', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__tabs' ) ).toBeNull();
} );

it( 'renders one tab per point, labelled by type, first active', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );

	const tabs = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ];

	expect( tabs.map( ( t ) => t.textContent ) ).toEqual( [ 'ПВЗ', 'Постамат' ] );
	expect( tabs[ 0 ].classList.contains( 'is-active' ) ).toBe( true );
} );

it( 'swaps the body when a tab is clicked', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );
	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'Постамат №4' );
} );

it( 'falls back to the point name when two points in a group share a type', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»' } ),
		point( { id: 'b', name: 'ПВЗ «Пятёрочка»' } ),
	] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'ПВЗ «Магнит»', 'ПВЗ «Пятёрочка»' ] );
} );

it( 'opens on the requested point when the list drove the click', () => {
	const panels = mount( cardConfig );
	panels.openCard( two, 'b' );

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'Постамат №4' );
} );

it( 'emits select for the ACTIVE tab, not the first point', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( two );
	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen[ 0 ].id ).toBe( 'b' );
} );
```

The last two are the ones that would actually ship broken: clicking a list item for the second point
of a pair must open *that* point, and the CTA must select what the customer is looking at.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-panels.test.js`
Expected: FAIL — no tab elements.

- [ ] **Step 3: Implement**

`openCard( group, pointId )` — `pointId` optional, defaults to the first point. Labels come from
`type.label`, switching to `name` when the group's type labels are not unique.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(shipping): tab bar for points sharing one map position"
```

---

### Task 15: the search view (D-6)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing tests**

```js
it( 'renders the point section and the address section separately', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'Москва, Ленина 5' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points' ) ).not.toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-search__section--addresses' ) ).not.toBeNull();
} );

it( 'omits a section that has no results rather than showing an empty heading', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'Москва' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points' ) ).toBeNull();
} );

it( 'emits pointResult with the point id', () => {
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'searchPointPicked', ( id ) => seen.push( id ) );
	panels.renderSearchResults( { points: [ point() ], addresses: [] } );
	panels.root.querySelector( '.woodev-pickup-search__item' ).click();

	expect( seen ).toEqual( [ 'p1' ] );
} );

it( 'emits addressResult with the index so the caller can resolve it', () => {
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'searchAddressPicked', ( i ) => seen.push( i ) );
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'A' }, { displayName: 'B' } ] } );
	panels.root.querySelectorAll( '.woodev-pickup-search__item' )[ 1 ].click();

	expect( seen ).toEqual( [ 1 ] );
} );

it( 'shows the anchor header and a reset control once an address is active', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Ближайшие к «Москва, Тверская 1»' );
	expect( panels.root.querySelector( '.woodev-pickup-list__reset' ) ).not.toBeNull();
} );

it( 'restores the plain header when the anchor is reset', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области' );
} );

it( 'shows the nothing-nearby state with the nearest distance', () => {
	const panels = mount( searchConfig );
	panels.showNothingNearby( { distanceMeters: 87000, name: 'ПВЗ «Магнит»' } );

	const empty = panels.root.querySelector( '.woodev-pickup-list__nothing-nearby' );

	expect( empty.textContent ).toContain( 'Рядом с этим адресом пунктов выдачи нет.' );
	expect( empty.textContent ).toContain( '87.0 км' );
	expect( empty.querySelector( 'button' ) ).not.toBeNull();
} );
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-panels.test.js`
Expected: FAIL — `renderSearchResults is not a function`.

- [ ] **Step 3: Implement**

`renderSearchResults( { points, addresses } )`, `setAnchor( latLng, label )`,
`showNothingNearby( { distanceMeters, name } )`. The panels own the results markup only; the
`SearchControl` wiring that feeds them lives in the provider (T18), because that is where ymaps is.

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(shipping): two-section search results and search-anchored list header"
```

---

### Task 16: the type filter menu (D-10)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Test: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing tests**

```js
it( 'does not render the filter until a second type appears', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter' ) ).toBeNull();
} );

it( 'renders one checkbox per type, all checked, once there are two', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

	expect( boxes ).toHaveLength( 2 );
	expect( boxes.every( ( b ) => b.checked ) ).toBe( true );
} );

it( 'never disappears again once shown', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter' ) ).not.toBeNull();
} );

it( 'refuses to uncheck the last checked type', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];
	boxes[ 0 ].click();
	boxes[ 1 ].click();

	expect( boxes[ 1 ].checked ).toBe( true );
} );

it( 'shows the badge only when the selection is partial', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter__badge' ) ).toBeNull();

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-filter__badge' ).textContent ).toBe( '1' );
} );

it( 'emits the selected codes on change', () => {
	const seen = [];
	const panels = mount( filterConfig );
	panels.on( 'typeFilterChange', ( codes ) => seen.push( codes ) );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );
	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( seen ).toEqual( [ [ 'postamat' ] ] );
} );
```

"Refuses to uncheck the last one" is the Yandex reference's own rule (`filterControl.events.add`
re-selects the item). Without it the customer can produce an empty map and think there are no points.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-panels.test.js`
Expected: FAIL — no filter element.

- [ ] **Step 3: Implement**

`setTypes( types )` accumulates types first-seen, renders the control once the second distinct type
arrives and never removes it. `typeFilterChange` carries the selected codes; the caller decides
whether that means `objectManager.setFilter()` or a refetch (T19).

- [ ] **Step 4: Run to verify they pass**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(shipping): type filter menu with a partial-selection badge"
```

---

## Phase E — the provider

### Task 17: rewrite the provider onto `ObjectManager` and groups (D-3, D-5, D-11)

This is the largest task. Take the existing `map-provider-yandex.js` as the source of truth for the
things that already work — script loading with the module-scope promise cache, the `_destroyed`
guard at every async continuation, and the `hasApiKey` early exit — and delete everything that draws
a panel.

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js`
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing tests**

Keep the existing ymaps stub in the test file and extend it with `ObjectManager`. Assert the new
contract, not the old one:

```js
it( 'adds every group to the object manager as one feature each', async () => {
	const provider = await init();
	provider.setPoints( [
		{ key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [ { id: '1' } ] },
		{ key: 'b', lat: 55.76, lng: 37.62, typeCode: 'pvz', size: 2, points: [ { id: '2' }, { id: '3' } ] },
	] );

	expect( ymapsStub.lastObjectManager.added ).toHaveLength( 2 );
} );

it( 'uses the plugin icon for the group type and falls back when the type is unknown', async () => {
	const provider = await init( { pointIcons: { pvz: { default: '/pvz.svg', active: '/pvz-a.svg' } } } );
	provider.setPoints( [ { key: 'a', lat: 55.75, lng: 37.61, typeCode: 'unknown', size: 1, points: [ {} ] } ] );

	expect( ymapsStub.lastObjectManager.added[ 0 ].options.iconImageHref ).toBeUndefined();
} );

it( 'marks a group of more than one point so the badge renders', async () => {
	const provider = await init();
	provider.setPoints( [ { key: 'b', lat: 55.76, lng: 37.62, typeCode: 'pvz', size: 3, points: [] } ] );

	expect( ymapsStub.lastObjectManager.added[ 0 ].properties.groupSize ).toBe( 3 );
} );

it( 'emits pointClick with the group key', async () => {
	const seen = [];
	const provider = await init();
	provider.on( 'pointClick', ( key ) => seen.push( key ) );
	provider.setPoints( [ { key: 'a', lat: 55.75, lng: 37.61, typeCode: 'pvz', size: 1, points: [] } ] );
	ymapsStub.lastObjectManager.fireObjectClick( 'a' );

	expect( seen ).toEqual( [ 'a' ] );
} );

it( 'filters by type through setFilter, not by rebuilding the manager', async () => {
	const provider = await init();
	provider.setTypeFilter( [ 'pvz' ] );

	expect( typeof ymapsStub.lastObjectManager.filter ).toBe( 'function' );
	expect( ymapsStub.lastObjectManager.removeAllCalls ).toBe( 0 );
} );

it( 'adds the plugin tile layers and copyrights when supplied', async () => {
	await init( { layers: [ { url: 'https://tiles.test/%c.png', projection: 'sphericalMercator' } ],
	              copyrights: [ '© Test' ] } );

	expect( ymapsStub.lastMap.layers ).toHaveLength( 1 );
	expect( ymapsStub.lastMap.copyrights ).toEqual( [ '© Test' ] );
} );

it( 'draws nothing and emits map_script when there is no API key', async () => {
	const seen = [];
	const provider = new Provider();
	provider.on( 'error', ( e ) => seen.push( e ) );
	await provider.init( document.createElement( 'div' ), { hasApiKey: false }, {} );

	expect( seen[ 0 ].code ).toBe( 'map_script' );
	expect( ymapsStub.lastMap ).toBeUndefined();
} );
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/map-provider-yandex.test.js`
Expected: FAIL — `setPoints is not a function`.

- [ ] **Step 3: Implement**

New surface: `init( canvasEl, config )`, `setPoints( groups )`, `focusGroup( key )`,
`setTypeFilter( codes )`, `on( event, cb )`, `destroy()`. Events out: `pointClick( key )`,
`boundsChange( bbox )`, `visibleChange( keys )`.

`ObjectManager` config mirrors the references:

```js
this.objectManager = new ymaps.ObjectManager( {
    clusterize: true,
    clusterIconColor: CLUSTER_ICON_COLOR,
    geoObjectOpenBalloonOnClick: false,   // the card is our DOM; ymaps must not open anything
    clusterHasBalloon: false,
} );
```

Markers use an HTML icon layout so the framework can overlay the group badge and express state as a
class (D-5). The framework still passes `iconImageSize` / `iconImageOffset` matching the CSS box,
because ymaps needs them for hit-testing:

```js
var ICON_BOX        = { size: [ 45, 45 ], offset: [ -22, -23 ] };
var ICON_BOX_ACTIVE = { size: [ 50, 70 ], offset: [ -25, -40 ] };
```

Delete from this file: the balloon layout classes, the cluster balloon layout, the drawer control,
the filter control, `_renderBalloon`, `_renderClusterBalloon`, `_renderDrawerItems`, `_updateDrawer`,
`buildBalloonHtml`, `escapeHtml`, `formatWeightKg`, `_bindResizeListener`. All of that now lives in
`pickup-panels.js` or has no equivalent.

Keep unchanged: `loadYmapsScript` with its module-scope promise cache, the `_destroyed` guard, and
the `hasApiKey` early exit.

- [ ] **Step 4: Run the full jest suite**

Run: `npm run test:js`
Expected: PASS. Tests asserting the deleted panel behaviour must be **removed**, not weakened — they
now belong to `pickup-panels.test.js`.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js tests/js/map-provider-yandex.test.js
git commit -m "refactor(shipping): narrow the yandex provider to map, markers and camera"
```

---

### Task 18: camera — initial viewport, the co-located guard, the bbox cap (D-4, D-7)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js`
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing tests**

```js
it( 'fits to the loaded points under bulk without geocoding', async () => {
	const provider = await init( { strategy: 'bulk', locality: 'Москва' } );
	provider.setPoints( [ group( 'a' ), group( 'b' ) ] );

	expect( ymapsStub.geocodeCalls ).toBe( 0 );
	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 1 );
} );

it( 'geocodes the locality under viewport', async () => {
	await init( { strategy: 'viewport', locality: 'Москва' } );

	expect( ymapsStub.geocodeCalls ).toBe( 1 );
} );

it( 'falls back to the plugin default when the geocode is empty', async () => {
	ymapsStub.geocodeResult = null;
	await init( { strategy: 'viewport', locality: 'Нетакогогорода',
	              defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 } } );

	expect( ymapsStub.lastMap.setCenterCalls[ 0 ] ).toEqual( [ [ 55.76, 37.64 ], 12 ] );
} );

it( 'uses the plugin default without geocoding when there is no locality', async () => {
	await init( { strategy: 'viewport', locality: '',
	              defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 } } );

	expect( ymapsStub.geocodeCalls ).toBe( 0 );
	expect( ymapsStub.lastMap.setCenterCalls ).toHaveLength( 1 );
} );

it( 'builds the map with minZoom 8 and maxZoom 18', async () => {
	await init();

	expect( ymapsStub.lastMap.options.minZoom ).toBe( 8 );
	expect( ymapsStub.lastMap.options.maxZoom ).toBe( 18 );
} );

it( 'emits zoomIn instead of fetching when the bbox exceeds the server cap', async () => {
	const seen = [];
	const provider = await init( { strategy: 'viewport' } );
	provider.on( 'bboxTooWide', () => seen.push( 1 ) );
	ymapsStub.lastMap.bounds = [ [ 40, 20 ], [ 60, 60 ] ];   // 40° wide, cap is 10°
	ymapsStub.lastMap.fireBoundsChange();

	expect( seen ).toHaveLength( 1 );
} );

it( 'does not try to zoom a group whose points all share one coordinate', async () => {
	const provider = await init();
	ymapsStub.lastObjectManager.state = { isClustered: true, cluster: {
		features: [ { geometry: { coordinates: [ 55.75, 37.61 ] } },
		            { geometry: { coordinates: [ 55.75, 37.61 ] } } ] } };

	provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 0 );
} );

it( 'zooms a genuine cluster and awaits the move before reporting', async () => {
	const provider = await init();
	ymapsStub.lastObjectManager.state = { isClustered: true, cluster: {
		features: [ { geometry: { coordinates: [ 55.75, 37.61 ] } },
		            { geometry: { coordinates: [ 55.76, 37.62 ] } } ] } };

	await provider.focusGroup( 'a' );

	expect( ymapsStub.lastMap.setBoundsCalls ).toHaveLength( 1 );
	expect( ymapsStub.lastMap.setBoundsCalls[ 0 ].options.checkZoomRange ).toBe( true );
} );

it( 'ignores a stale focus continuation when a second focus started first', async () => {
	const provider = await init();
	const slow = provider.focusGroup( 'a' );   // resolves last
	const fast = provider.focusGroup( 'b' );

	await Promise.all( [ slow, fast ] );

	expect( provider.getFocusedKey() ).toBe( 'b' );
} );
```

The last three are the s46 lessons plus the Russian Post guard, written as tests so they cannot
regress silently.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/map-provider-yandex.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement**

Initial viewport per D-7. `focusGroup( key )` implements the guard from spec §7.5 verbatim, including
re-reading `getObjectState()` after the move and the `_focusSeq` counter that discards a stale
continuation. `setBounds()` is always awaited — it returns a promise and animates
(`ymaps-camera-moves-are-async`).

The bbox cap check runs before every viewport fetch: if either side of the bounds exceeds 10°, emit
`bboxTooWide` and skip the fetch. The mount renders `i18n.zoomIn` for it.

- [ ] **Step 4: Run the full jest suite**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js tests/js/map-provider-yandex.test.js
git commit -m "feat(shipping): initial viewport per strategy and a guard for co-located points"
```

---

### Task 19: wire the `SearchControl` view to the panels (D-6)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js`
- Test: `tests/js/map-provider-yandex.test.js`

- [ ] **Step 1: Write the failing tests**

```js
it( 'returns both point matches and suggestions from the custom geocode provider', async () => {
	const provider = await init();
	provider.setPoints( [ groupWith( { id: '1', name: 'ПВЗ «Магнит»', address: 'Ленина 5' } ) ] );
	ymapsStub.suggestResult = [ { displayName: 'Москва, Ленина 5', value: 'Москва, Ленина 5' } ];

	const res = await ymapsStub.lastSearchControl.options.provider.geocode( 'ленина', {} );

	expect( res.points ).toHaveLength( 1 );
	expect( res.addresses ).toHaveLength( 1 );
} );

it( 'calls suggest, not geocode, while the customer is typing', async () => {
	const provider = await init();
	await ymapsStub.lastSearchControl.options.provider.geocode( 'ленина', {} );

	expect( ymapsStub.suggestCalls ).toBe( 1 );
	expect( ymapsStub.geocodeCalls ).toBe( 0 );
} );

it( 'geocodes exactly once when an address result is chosen', async () => {
	const provider = await init();
	await provider.resolveAddress( 'Москва, Ленина 5' );

	expect( ymapsStub.geocodeCalls ).toBe( 1 );
} );

it( 'fits the address plus the three nearest groups', async () => {
	const provider = await init();
	provider.setPoints( [ group( 'a', 55.751 ), group( 'b', 55.752 ), group( 'c', 55.753 ),
	                      group( 'd', 55.999 ) ] );
	await provider.focusAddress( [ 55.75, 37.61 ], 'Москва, Ленина 5' );

	const fitted = ymapsStub.lastMap.setBoundsCalls.pop().bounds;

	expect( fitted[ 1 ][ 0 ] ).toBeLessThan( 55.999 );   // the far group is not in frame
} );

it( 'honours the nearest-count filter value handed in by config', async () => {
	const provider = await init( { searchNearestCount: 1 } );
	provider.setPoints( [ group( 'a', 55.751 ), group( 'b', 55.9 ) ] );
	await provider.focusAddress( [ 55.75, 37.61 ], 'X' );

	expect( ymapsStub.lastMap.setBoundsCalls.pop().bounds[ 1 ][ 0 ] ).toBeLessThan( 55.9 );
} );

it( 'reports nothing-nearby instead of fitting when the nearest exceeds the threshold', async () => {
	const seen = [];
	const provider = await init();
	provider.on( 'nothingNearby', ( info ) => seen.push( info ) );
	provider.setPoints( [ group( 'far', 56.6 ) ] );   // ≈ 95 km away
	await provider.focusAddress( [ 55.75, 37.61 ], 'X' );

	expect( seen[ 0 ].distanceMeters ).toBeGreaterThan( 50000 );
} );
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/map-provider-yandex.test.js`
Expected: FAIL — `resolveAddress is not a function`.

- [ ] **Step 3: Implement**

`SearchControl` is constructed with a `templateLayoutFactory` layout that renders into the panels'
markup (T15) and a fully custom `provider.geocode` returning `{ points, addresses }`. Point matching
uses `pickup-geo.matchPoints`; addresses come from `ymaps.suggest( request, { boundedBy, results: 5 } )`.

`focusAddress( latLng, label )` drops the address pin, computes `nearest()` with
`config.searchNearestCount` (default 3, PHP-side filter `woodev_pickup_search_nearest_count`), and
either fits `boundsFor( latLng, nearestGroups )` or emits `nothingNearby` when the closest exceeds
`NEARBY_THRESHOLD_M = 50000`.

- [ ] **Step 4: Run the full jest suite**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/map-provider-yandex.js tests/js/map-provider-yandex.test.js
git commit -m "feat(shipping): address search that frames the nearest pickup points"
```

---

## Phase F — wiring, styles, verification

### Task 20: mount, pickup-layer events, embedded passthrough (D-3, D-14)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`
- Modify: `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js`
- Test: `tests/js/pickup-mount.test.js`, `tests/js/map-provider-embedded.test.js`

- [ ] **Step 1: Write the failing tests**

```js
it( 'renders panels for a provider that does not own the chrome', async () => {
	await openSession( configWith( { ownsChrome: false } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
} );

it( 'renders no panels for a provider that owns the chrome', async () => {
	await openSession( configWith( { ownsChrome: true } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).toBeNull();
} );

it( 'fires woodev_pickup_map_ready once the provider init resolves', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_map_ready', ( e ) => seen.push( e.detail ) );
	await openSession( configWith() );

	expect( seen[ 0 ].fieldId ).toBe( 'carrier_pickup_point' );
} );

it( 'fires woodev_pickup_points_loaded with the count and strategy', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { strategy: 'bulk' } ) );

	expect( seen[ 0 ] ).toEqual( { fieldId: 'carrier_pickup_point', count: 2, strategy: 'bulk' } );
} );

it( 'fires woodev_pickup_point_selected and closes the modal with reason select', async () => {
	const selected = [], closed = [];
	document.body.addEventListener( 'woodev_pickup_point_selected', ( e ) => selected.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	const session = await openSession( configWith() );
	session.panels.emit( 'select', { id: 'p1' } );

	expect( selected[ 0 ].point.id ).toBe( 'p1' );
	expect( closed[ 0 ].reason ).toBe( 'select' );
} );

it( 'fires woodev_pickup_error when the provider reports a fatal error', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_error', ( e ) => seen.push( e.detail ) );

	const session = await openSession( configWith() );
	session.provider.emit( 'error', { code: 'map_script', message: '' } );

	expect( seen[ 0 ].code ).toBe( 'map_script' );
} );

it( 'exposes refresh() on the open session', async () => {
	const session = await openSession( configWith() );

	expect( typeof session.refresh ).toBe( 'function' );
} );
```

`refresh()` is the hook #148 needs; it re-runs the current strategy's fetch so a changed payment
method produces a fresh server verdict.

- [ ] **Step 2: Run to verify they fail**

Run: `npx wp-scripts test-unit-js tests/js/pickup-mount.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement**

The mount opens a `WoodevModal` with `modalId: 'woodev-pickup-map'`, then — unless
`config.mapConfig.ownsChrome` — constructs `Panels` and wires them to the provider both ways:

| Provider → panels | Panels → provider |
|---|---|
| `pointClick(key)` → `openCard(group)` | `select(point)` → emit + close modal with `'select'` |
| `visibleChange(keys)` → `setVisible(groups)` | `typeFilterChange(codes)` → bulk: `setTypeFilter`; viewport: refetch with `types` |
| `boundsChange` → refresh list | `listToggle({open,width})` → `map.margin` |
| `nothingNearby(info)` → `showNothingNearby` | `searchAddressPicked(i)` → `resolveAddress` → `focusAddress` |
| `bboxTooWide` → render `i18n.zoomIn` | `searchPointPicked(id)` → `focusGroup` + `openCard(group, id)` |

`Embedded_Map_Provider` gains `ownsChrome: true` in its config so this branch is exercised by a real
consumer, not only by a test.

- [ ] **Step 4: Run the full jest suite**

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/ tests/js/
git commit -m "feat(shipping): wire panels to the provider and emit the pickup event surface"
```

---

### Task 21: rewrite `pickup.css` for the new panels

**Files:**
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`

- [ ] **Step 1: Write the panel styles**

Match the reference's geometry exactly (`wc-yandex-delivery-pickup-point-modal-map.css`):

```css
.woodev-pickup-map { position: relative; height: 100vh; }

.woodev-pickup-list {
    position: fixed; top: 0; bottom: 0; right: 0;
    width: 100%; max-width: min( 320px, calc( 100% - 48px ) );
    display: none; flex-direction: column; overflow: auto;
    background: #fff; z-index: 2;
}
.woodev-pickup-list.is-open { display: flex; }

.woodev-pickup-card {
    position: fixed; top: 0; bottom: 0; right: 0;
    width: 100%; max-width: min( 320px, calc( 100% - 48px ) );
    display: flex; flex-direction: column; overflow: auto;
    background: #fff; z-index: 3;
}

.woodev-pickup-card__footer { position: sticky; bottom: 0; margin-top: auto; background: #fff; }

.woodev-pickup-pin                      { width: 45px; height: 45px; position: relative; }
.woodev-pickup-pin[data-state="active"] { width: 50px; height: 70px; }
.woodev-pickup-pin img                  { width: 100%; height: 100%; object-fit: contain; }
.woodev-pickup-pin__badge               { position: absolute; top: 0; right: 0; }
```

Breakpoint 782px, the same number as the modal (T3).

- [ ] **Step 2: Check for leftovers from the old presentation**

Run: `grep -n "drawer\|balloon" woodev/shipping-method/assets/css/frontend/pickup.css`
Expected: no hits. Those class names no longer exist anywhere.

- [ ] **Step 3: Commit**

```bash
git add woodev/shipping-method/assets/css/frontend/pickup.css
git commit -m "style(shipping): rebuild the pickup map stylesheet for the new panels"
```

---

### Task 22: regenerate, run everything, verify on the rig

**Files:**
- Modify: `woodev/class-map.php`

- [ ] **Step 1: Regenerate the class map**

Run: `php bin/generate-class-map.php`
Expected: the completeness test passes. A missing or stale entry is a WSOD on a real vendored boot,
which Composer masks in tests (gotcha `framework-classmap-autoload-vendored-boot`).

- [ ] **Step 2: Run every suite**

```bash
composer test:unit
npm run test:js
composer phpcs
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration \
  php /var/www/html/woodev-framework/vendor/bin/phpunit \
  --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage
```

Expected: all green. Confirm each job's result **separately** — do not chain a check after a grep and
read the grep's exit code as the verdict.

- [ ] **Step 3: Measure line lengths by hand**

Run: `awk 'length > 120 {print FILENAME":"FNR" ("length")"}' $(git diff --name-only main...HEAD | grep -E '\.(php|js|css)$')`
Expected: no output. Tabs count as 4 — expand before measuring in files that use them.

- [ ] **Step 4: Rig verification (chrome-devtools MCP, real key, ports 8973/8974)**

Work through this list and record the result of each:

- [ ] bulk: map opens on the buyer's city with no geocode call
- [ ] viewport (`wp config set WOODEV_TEST_PICKUP_STRATEGY viewport --type=constant`): opens on the geocoded locality
- [ ] viewport with an unknown locality: falls back to the plugin default without a geocode
- [ ] sidebar closed on open; opens to full height; the map shifts by the margin
- [ ] list sorted by distance, active item highlighted, empty state when panned away
- [ ] card: all sections, service chips, sticky footer, disabled CTA with the reason
- [ ] card CTA on an already-selected point reads «Продолжить оформление заказа»
- [ ] co-located points: one marker with a badge, tab bar, switching tabs, CTA selects the active tab
- [ ] **#150**: a city with exactly one available point — no grey tiles, on the initial fit AND on a click
- [ ] search: own address → pin + three nearest + sorted sidebar with distances
- [ ] search: an address with nothing nearby → the explicit empty state, not a blank map
- [ ] search: a point by name → its card opens
- [ ] type filter under both strategies; the last checkbox cannot be unchecked
- [ ] modal at ≤ 782px renders full-screen
- [ ] `woodev_modal_*` and `woodev_pickup_*` events observed in the console via `addEventListener`
- [ ] order placed end-to-end with the point in the order meta

- [ ] **Step 5: Commit and push**

```bash
git add -A
git commit -m "chore(shipping): regenerate the class map after the pickup map rework"
git push
```

- [ ] **Step 6: Codex review before requesting a merge**

Per the project's proactive-Codex rule, this is a substantial rework touching core paths. Run a Codex
review on the full diff, present the findings verbatim, and ask the operator which to fix. Do not
self-certify your own fixes — re-run the critic on them.

---

## Self-review notes

Checked against the spec:

- D-1 → no task; it is a decision to *not* do something, recorded in ADR-010.
- D-2 → T13, T17 (the balloon layout classes are deleted in T17 step 3).
- D-3 → T6, T17, T20.
- D-4 → T9, T14, T18.
- D-5 → T8, T17, T21.
- D-6 → T11, T15, T19.
- D-7 → T8, T18.
- D-8 → T7, T17.
- D-9 → T4, T13.
- D-10 → T5, T16, T20.
- D-11 → T17.
- D-12 → T7, T10.
- D-13 → T1, T3.
- D-14 → T2, T20.
- §7.6 / issue #150 → T22 step 4, both paths.
- §9 backlog items → issues #151, #152, #153, not tasks here.

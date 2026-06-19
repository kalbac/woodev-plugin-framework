# «Мои покупки» (#7) + «Куплено» badge — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a «Мои покупки» tab to the «Плагины» React page and a «Куплено» badge to catalog cards, both driven by the connector's existing signed `GET /purchases`.

**Architecture:** A single capability-gated REST route `GET /woodev/v1/account/purchases` proxies `Woodev_Account_Connection::request('GET','/purchases')`, normalizes the response with a new pure class, and returns BOTH the lean purchase list (for the tab) and the deduped int id list (for the badge) in one payload — fetched async by the React App (mirrors the catalog fetch; never blocks page render). The connector payload has NO permalink, so the tab cross-references the already-loaded catalog by `download_id` for per-row store links. Badge precedence: «Установлен» wins over «Куплено».

**Tech Stack:** PHP 7.4+ (Brain Monkey + Mockery unit tests), `@wordpress/element`/`api-fetch` React (classic JSX runtime), SCSS via `@wordpress/scripts`.

**Decisions locked (operator, s25):**
1. One async REST endpoint returns `{ purchases:[...], purchased:[ids] }` — NOT bootstrap-injected (no render block).
2. Badge: «Установлен» wins; «Куплено» (cyan) shown only for purchased-but-not-installed.
3. Tab row link: cross-reference catalog product by `download_id` → its `permalink`; no match → no link.

**Connector `/purchases` contract (verified live, woodev_theme `class-purchases.php`):**
`{ "purchases": [ { "download_id":int, "slug":str, "title":str, "icon":str(url), "date":str("Y-m-d H:i:s") }, ... ] }` — already deduped by `download_id` on the connector side. `Woodev_Account_Connection::request()` returns `array` on success (decoded JSON) or `WP_Error` on transport/HTTP failure; a successful connector response always carries the `purchases` key.

**Constraints:** `@since 2.0.2` for all new symbols (v2.0.1 unreleased). `composer check` baseline = **690 unit tests** — keep green after every PHP task. JS has no unit harness — React tasks verified by `npm run build` + rig e2e. Do NOT touch public `docs/`. Do NOT touch the woodev-theme connector (contract is fixed).

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `woodev/account/class-account-purchases.php` | Create | Pure normalizer: connector response → lean purchase list + id collector |
| `tests/unit/AccountPurchasesTest.php` | Create | Unit tests for the pure class |
| `woodev/class-plugin.php` | Modify (`includes()`, ~line 586) | `require_once` the new class (prod-fatal if unwired — gotcha `includes-wiring`) |
| `woodev/rest-api/controllers/class-rest-api-account.php` | Modify | Add `GET /account/purchases` route + `handle_purchases()` + cache-clear on disconnect |
| `tests/unit/RestApiAccountTest.php` | Modify | Add handler tests (not-connected / success+cache / malformed+stale / transport-error+stale) |
| `src/plugins-page/purchases.js` | Create | `PurchasesTab` component (list, states, cross-ref link) |
| `src/plugins-page/catalog.js` | Modify | `ExtensionCard` `isPurchased` prop + cyan badge; `ExtensionGrid` passes `purchased` |
| `src/plugins-page/app.js` | Modify | Fetch purchases when connected; tab switch; pass `purchased` to grid |
| `src/plugins-page/style.scss` | Modify | Cyan «Куплено» badge, tab bar, purchases list styles |

---

## Task 1: Pure purchases normalizer + id collector

**Files:**
- Create: `woodev/account/class-account-purchases.php`
- Test: `tests/unit/AccountPurchasesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/AccountPurchasesTest.php`:

```php
<?php
/**
 * Unit tests for Woodev_Account_Purchases — the pure normalizer that maps the
 * connector's /purchases response to the lean UI list, plus the badge-id collector.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-purchases.php';

/**
 * @covers \Woodev_Account_Purchases
 */
final class AccountPurchasesTest extends TestCase {

	public function test_normalize_maps_lean_shape(): void {
		$response = array(
			'purchases' => array(
				array(
					'download_id' => 127940,
					'slug'        => 'wb',
					'title'       => 'Интеграция WB',
					'icon'        => 'https://woodev.ru/i.jpg',
					'date'        => '2024-03-15 10:23:45',
				),
			),
		);

		$out = \Woodev_Account_Purchases::normalize( $response );

		$this->assertSame(
			array(
				array(
					'id'    => 127940,
					'title' => 'Интеграция WB',
					'icon'  => 'https://woodev.ru/i.jpg',
					'date'  => '2024-03-15 10:23:45',
				),
			),
			$out
		);
	}

	public function test_normalize_skips_nonpositive_ids_and_dedupes(): void {
		$response = array(
			'purchases' => array(
				array( 'download_id' => 0, 'title' => 'zero' ),
				array( 'download_id' => -3, 'title' => 'neg' ),
				array( 'download_id' => 21, 'title' => 'A' ),
				array( 'download_id' => 21, 'title' => 'dup' ),
				'not-an-array',
			),
		);

		$out = \Woodev_Account_Purchases::normalize( $response );

		$this->assertCount( 1, $out );
		$this->assertSame( 21, $out[0]['id'] );
		$this->assertSame( 'A', $out[0]['title'] );
	}

	public function test_normalize_defaults_missing_keys(): void {
		$out = \Woodev_Account_Purchases::normalize( array( 'purchases' => array( array( 'download_id' => 5 ) ) ) );

		$this->assertSame( array( array( 'id' => 5, 'title' => '', 'icon' => '', 'date' => '' ) ), $out );
	}

	public function test_normalize_returns_empty_for_missing_or_bad_response(): void {
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( array() ) );
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( array( 'purchases' => 'x' ) ) );
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( 'nope' ) );
	}

	public function test_download_ids_extracts_deduped_positive_ints(): void {
		$purchases = array(
			array( 'id' => 127940 ),
			array( 'id' => 21 ),
			array( 'id' => 127940 ),
			array( 'title' => 'no id' ),
		);

		$this->assertSame( array( 127940, 21 ), \Woodev_Account_Purchases::download_ids( $purchases ) );
	}

	public function test_download_ids_empty_input(): void {
		$this->assertSame( array(), \Woodev_Account_Purchases::download_ids( array() ) );
	}
}
```

> Note: `esc_url_raw` is stubbed by `TestCase::setUp()` via `stubEscapeFunctions()` (returns its arg), so the icon assertion expects the unchanged URL.

- [ ] **Step 2: Run test to verify it fails**

Run (PowerShell): `./vendor/bin/phpunit tests/unit/AccountPurchasesTest.php`
Expected: FAIL — "Class Woodev_Account_Purchases not found".

- [ ] **Step 3: Write minimal implementation**

Create `woodev/account/class-account-purchases.php`:

```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Purchases' ) ) :

	/**
	 * Pure normalizer for the connector's /purchases response.
	 *
	 * Maps the raw connector payload ({ purchases: [ { download_id, slug, title,
	 * icon, date }, ... ] }) to the lean UI list the «Мои покупки» tab renders,
	 * and collects the deduped positive-integer download ids the catalog uses to
	 * badge «Куплено». PURE: depends only on its input and WP escaping helpers, so
	 * it is unit-tested without a live connection; the REST controller feeds it the
	 * decoded response. Defensive against a hostile/malformed issuer reply: any
	 * non-array item or non-positive id is skipped, missing keys default to ''.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Purchases {

		/**
		 * Normalizes a connector /purchases response into the lean UI list.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $response Decoded connector response (expects ['purchases' => [...]]).
		 *
		 * @return array<int,array<string,mixed>> List of { id, title, icon, date }.
		 */
		public static function normalize( $response ): array {

			$items = ( is_array( $response ) && isset( $response['purchases'] ) && is_array( $response['purchases'] ) )
				? $response['purchases']
				: array();

			$seen = array();
			$out  = array();

			foreach ( $items as $item ) {

				if ( ! is_array( $item ) ) {
					continue;
				}

				$id = isset( $item['download_id'] ) ? (int) $item['download_id'] : 0;

				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ] = true;

				$out[] = array(
					'id'    => $id,
					'title' => isset( $item['title'] ) ? (string) $item['title'] : '',
					'icon'  => isset( $item['icon'] ) ? esc_url_raw( (string) $item['icon'] ) : '',
					'date'  => isset( $item['date'] ) ? (string) $item['date'] : '',
				);
			}

			return $out;
		}

		/**
		 * Deduped positive-integer download ids from a normalized purchase list —
		 * the «Куплено» badge set.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $purchases Normalized purchases (each with an 'id').
		 *
		 * @return array<int,int> Deduped, order-preserving list of download ids.
		 */
		public static function download_ids( array $purchases ): array {

			$ids = array();

			foreach ( $purchases as $purchase ) {

				$id = ( is_array( $purchase ) && isset( $purchase['id'] ) ) ? (int) $purchase['id'] : 0;

				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			return array_values( $ids );
		}
	}

endif;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/AccountPurchasesTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/account/class-account-purchases.php tests/unit/AccountPurchasesTest.php
git commit -m "feat(account): pure /purchases normalizer + badge-id collector"
```

---

## Task 2: Wire the new class into includes()

**Files:**
- Modify: `woodev/class-plugin.php` (the `account/class-installed-plugins.php` require_once line, ~586)

> The runtime has no autoloader; the Composer classmap loads the class in tests but production fatals if the file is not `require_once`'d in `includes()` (gotcha `includes-wiring` / `box-packer-interface-unwired-in-includes`). No unit test covers wiring — verified by `composer check` staying green and by the rig boot.

- [ ] **Step 1: Add the require_once**

In `woodev/class-plugin.php`, find:

```php
			require_once $framework_path . '/account/class-installed-plugins.php';
```

Add immediately after it:

```php
			require_once $framework_path . '/account/class-account-purchases.php';
```

- [ ] **Step 2: Verify nothing broke**

Run: `composer test:unit`
Expected: PASS — 696 tests (690 baseline + 6 from Task 1), 0 failures.

- [ ] **Step 3: Commit**

```bash
git add woodev/class-plugin.php
git commit -m "feat(account): wire Woodev_Account_Purchases into includes()"
```

---

## Task 3: REST endpoint `GET /woodev/v1/account/purchases`

**Files:**
- Modify: `woodev/rest-api/controllers/class-rest-api-account.php`
- Test: `tests/unit/RestApiAccountTest.php`

The handler reuses the existing `check_permissions()` (manage_options) and the existing `new Woodev_Account_Connection()` pattern (no injection — the connection is stateless and reads `get_option`, so tests stub `get_option` + the HTTP layer exactly like `ExtensionsRestControllerTest`). A successful connector reply always has the `purchases` key, so a returned array WITHOUT it (or a `WP_Error`) is treated as a transport/format failure → `stale: true`, uncached, retryable.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/unit/RestApiAccountTest.php` (inside the class). Also add `use Brain\Monkey\Functions;` is already imported.

```php
	/**
	 * Stubs a connected state + the signed-transport HTTP layer so the internal
	 * Woodev_Account_Connection::request() runs against a canned connector body.
	 *
	 * @param string $body The connector response body the transport returns.
	 * @param int    $code The HTTP status the transport reports.
	 * @return void
	 */
	private function stub_connected_transport( string $body, int $code = 200 ): void {
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}

		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
		Functions\when( 'untrailingslashit' )->alias( static function ( $u ) { return rtrim( (string) $u, '/' ); } );
		Functions\when( 'wp_parse_url' )->alias( static function ( $u ) { return parse_url( (string) $u ); } );
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		// Connected auth state (request() reads get_option(OPTION_KEY)['auth']).
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'tok', 'access_token_secret' => 'sec' ) )
		);
	}

	public function test_purchases_not_connected_returns_empty_without_network(): void {
		Functions\when( 'get_option' )->justReturn( false ); // not connected.
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\expect( 'wp_safe_remote_request' )->never();
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
	}

	public function test_purchases_success_normalizes_and_caches(): void {
		$this->stub_connected_transport(
			'{"purchases":[{"download_id":127940,"slug":"wb","title":"WB","icon":"https://woodev.ru/i.jpg","date":"2024-03-15 10:23:45"}]}'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( 127940, $response['purchases'][0]['id'] );
		$this->assertSame( 'WB', $response['purchases'][0]['title'] );
		$this->assertSame( array( 127940 ), $response['purchased'] );
	}

	public function test_purchases_malformed_payload_marks_stale_uncached(): void {
		// 200 but no "purchases" key → format failure, not "owns nothing".
		$this->stub_connected_transport( '{"unexpected":true}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
		$this->assertTrue( $response['stale'] );
	}

	public function test_purchases_transport_error_marks_stale_uncached(): void {
		$this->stub_connected_transport( '', 500 ); // request() returns WP_Error on non-200.
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) { return $thing instanceof \WP_Error; }
		);
		Functions\expect( 'set_transient' )->never();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertTrue( $response['stale'] );
		$this->assertSame( array(), $response['purchases'] );
	}

	public function test_purchases_empty_but_valid_is_cached(): void {
		$this->stub_connected_transport( '{"purchases":[]}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();

		$response = ( new \Woodev_REST_API_Account() )->handle_purchases();

		$this->assertSame( array(), $response['purchases'] );
		$this->assertSame( array(), $response['purchased'] );
		$this->assertArrayNotHasKey( 'stale', $response );
	}
```

> The success/empty tests need the `Woodev_Account_Purchases` class loaded. The controller file (next step) does not require it directly (it is wired in `includes()` at runtime); for the test, add a `require_once` for it at the top of `RestApiAccountTest.php` alongside the existing requires:
> ```php
> require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-purchases.php';
> ```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/RestApiAccountTest.php`
Expected: FAIL — `handle_purchases()` undefined.

- [ ] **Step 3: Implement the route + handler**

In `woodev/rest-api/controllers/class-rest-api-account.php`:

(a) Add a cache-key constant after the `$booted` property:

```php
		/**
		 * Per-site purchases cache key (short TTL — user-scoped data).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const PURCHASES_CACHE_KEY = 'woodev_account_purchases';
```

(b) In `register_routes()`, after the disconnect `register_rest_route(...)` call, add:

```php
			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/account/purchases',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_purchases' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);
```

(c) In `handle_disconnect()`, clear the purchases cache so a stale list cannot outlive the connection. Change the body to:

```php
		public function handle_disconnect() {

			( new Woodev_Account_Connection() )->disconnect();

			delete_transient( self::PURCHASES_CACHE_KEY );

			return rest_ensure_response( array( 'connected' => false ) );
		}
```

> The existing `test_disconnect_clears_and_returns_disconnected` stubs only `delete_option`; add `Functions\when( 'delete_transient' )->justReturn( true );` to that test's stubs so the new call does not error. (Brain Monkey errors on an unstubbed WP function.)

(d) Add the handler method:

```php
		/**
		 * GET handler: the connected customer's purchases + the badge-id set.
		 *
		 * Returns { purchases, purchased } — the lean list for the «Мои покупки»
		 * tab and the deduped int id list for the «Куплено» catalog badge — in one
		 * payload (one network round-trip, fetched async by the React app). Served
		 * from a short-lived transient when present. A disconnected site returns the
		 * empty shape without any network call; a transport/HTTP failure or a
		 * malformed reply (no `purchases` key) sets `stale: true` and is NOT cached,
		 * so the next load retries.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response|array<string,mixed>
		 */
		public function handle_purchases() {

			$connection = new Woodev_Account_Connection();

			if ( ! $connection->is_connected() ) {
				return rest_ensure_response( array( 'purchases' => array(), 'purchased' => array() ) );
			}

			$cached = get_transient( self::PURCHASES_CACHE_KEY );

			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}

			$response = $connection->request( 'GET', '/purchases' );

			if ( is_wp_error( $response ) || ! isset( $response['purchases'] ) ) {
				return rest_ensure_response(
					array( 'purchases' => array(), 'purchased' => array(), 'stale' => true )
				);
			}

			$purchases = Woodev_Account_Purchases::normalize( $response );
			$payload   = array(
				'purchases' => $purchases,
				'purchased' => Woodev_Account_Purchases::download_ids( $purchases ),
			);

			set_transient( self::PURCHASES_CACHE_KEY, $payload, 5 * MINUTE_IN_SECONDS );

			return rest_ensure_response( $payload );
		}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/RestApiAccountTest.php`
Expected: PASS (3 existing + 5 new = 8 tests).

- [ ] **Step 5: Full suite green**

Run: `composer test:unit`
Expected: PASS — 701 tests (690 + 6 + 5), 0 failures.

- [ ] **Step 6: Codex review (security-sensitive: auth proxy + token handling)**

Per session protocol, run a Codex review on the proxy auth (cap + the connection going around `Woodev_API_Base`, no token leak into the `woodev_{plugin_id}_api_request_performed` log) and hostile-input handling of the issuer reply. Present findings to the operator (recommended fix first); do NOT auto-fix.

- [ ] **Step 7: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-account.php tests/unit/RestApiAccountTest.php
git commit -m "feat(account): GET /account/purchases proxy (tab list + badge ids)"
```

---

## Task 4: React — «Куплено» badge in catalog cards

**Files:**
- Modify: `src/plugins-page/catalog.js`

> No JS unit harness — verified by `npm run build` (Task 7) + rig. Badge precedence: installed wins; «Куплено» (cyan) only when purchased AND not installed.

- [ ] **Step 1: Add `isPurchased` to `ExtensionCard`**

Change the `ExtensionCard` signature and the badge block. Signature:

```js
export function ExtensionCard( { product, isInstalled = false, isPurchased = false } ) {
```

Replace the existing badge block (the `{ isInstalled ? ( <span ...>Установлен</span> ) : null }`) with:

```js
				{ isInstalled ? (
					<span className="woodev-extension-card__badge">
						{ __( 'Установлен', 'woodev-plugin-framework' ) }
					</span>
				) : isPurchased ? (
					<span className="woodev-extension-card__badge woodev-extension-card__badge--purchased">
						{ __( 'Куплено', 'woodev-plugin-framework' ) }
					</span>
				) : null }
```

- [ ] **Step 2: Thread `purchased` through `ExtensionGrid`**

Change the `ExtensionGrid` signature:

```js
export function ExtensionGrid( { products, installed = [], purchased = [] } ) {
```

And the card render inside its `.map`:

```js
				<ExtensionCard
					key={ p.id }
					product={ p }
					isInstalled={ installed.includes( p.id ) }
					isPurchased={ purchased.includes( p.id ) }
				/>
```

- [ ] **Step 3: Commit (after build in Task 7)** — staged with the other React changes.

---

## Task 5: React — `PurchasesTab` component

**Files:**
- Create: `src/plugins-page/purchases.js`

- [ ] **Step 1: Create the component**

```js
/**
 * «Мои покупки» tab. Lists the connected account's owned downloads (icon, title,
 * purchase date), cross-referencing the already-loaded catalog by download id for
 * a per-row store link (the connector payload carries no permalink). Renders
 * loading / error / empty / list states. Shown only in the connected state.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement required by classic JSX runtime.
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Formats a connector date string ("Y-m-d H:i:s") to "DD.MM.YYYY", or '' when
 * unparseable. Pure string slicing — no Date parsing / timezone surprises.
 *
 * @param {string} raw The raw connector date.
 * @return {string} The display date.
 */
function formatDate( raw ) {
	const m = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( raw || '' ) );
	return m ? `${ m[ 3 ] }.${ m[ 2 ] }.${ m[ 1 ] }` : '';
}

/**
 * The «Мои покупки» tab.
 *
 * @param {Object}      props          Props.
 * @param {Object|null} props.data     { purchases, purchased, stale } or null while loading.
 * @param {Array}       props.products Loaded catalog products (for the id→permalink cross-ref).
 * @return {JSX.Element} The tab.
 */
export default function PurchasesTab( { data, products = [] } ) {
	if ( ! data ) {
		return (
			<p className="woodev-extensions__loading">
				{ __( 'Загрузка покупок…', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	if ( data.stale ) {
		return (
			<p className="woodev-extensions__error">
				{ __( 'Не удалось загрузить покупки, попробуйте позже.', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	const purchases = data.purchases || [];

	if ( ! purchases.length ) {
		return (
			<p className="woodev-extensions__empty">
				{ __( 'У вас пока нет покупок на woodev.ru.', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	const linkById = {};
	products.forEach( ( p ) => {
		if ( p && p.id && p.permalink ) {
			linkById[ p.id ] = p.permalink;
		}
	} );

	return (
		<ul className="woodev-purchases">
			{ purchases.map( ( item ) => {
				const link = linkById[ item.id ] || null;
				const date = formatDate( item.date );

				return (
					<li key={ item.id } className="woodev-purchases__row">
						<span className="woodev-purchases__icon">
							{ item.icon ? (
								<img src={ item.icon } alt="" loading="lazy" />
							) : (
								<span
									className="woodev-purchases__icon-placeholder"
									aria-hidden="true"
								>
									{ ( item.title || '?' ).trim().charAt( 0 ).toUpperCase() }
								</span>
							) }
						</span>
						<span className="woodev-purchases__title">
							{ link ? (
								<a href={ link } target="_blank" rel="noreferrer">
									{ item.title }
								</a>
							) : (
								item.title
							) }
						</span>
						{ date ? (
							<span className="woodev-purchases__date">{ date }</span>
						) : null }
					</li>
				);
			} ) }
		</ul>
	);
}
```

- [ ] **Step 2: Commit (after build in Task 7)** — staged with the other React changes.

---

## Task 6: React — App fetches purchases + tab switch

**Files:**
- Modify: `src/plugins-page/app.js`

- [ ] **Step 1: Import the tab**

After `import AccountMenu from './account';` add:

```js
import PurchasesTab from './purchases';
```

- [ ] **Step 2: Add state + fetch + tab switch**

Replace the `App` function body. The connected flag drives both the purchases fetch and whether the tab bar shows.

```js
export default function App( { config } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState( 'all' );
	const [ tab, setTab ] = useState( 'catalog' );
	const [ purchases, setPurchases ] = useState( null );

	const connected = !! ( config.accountEnabled && config.account && config.account.connected );

	useEffect( () => {
		apiFetch( { path: '/woodev/v1/extensions' } )
			.then( ( res ) => setData( res ) )
			.catch( () => setError( true ) );
	}, [] );

	useEffect( () => {
		if ( ! connected ) {
			return;
		}
		apiFetch( { path: '/woodev/v1/account/purchases' } )
			.then( ( res ) => setPurchases( res ) )
			.catch( () => setPurchases( { stale: true, purchases: [], purchased: [] } ) );
	}, [ connected ] );

	const products = data ? filterProducts( data.products, { category, search } ) : [];
	const failed = error || ( data && data.stale );
	const ready = data && ! data.stale;
	const purchased = ( purchases && purchases.purchased ) || [];

	return (
		<div className="woodev-extensions">
			<p className="woodev-extensions__intro">
				{ __( 'Расширьте возможности магазина плагинами Woodev.', 'woodev-plugin-framework' ) }
			</p>

			<AccountMenu enabled={ !! config.accountEnabled } account={ config.account } />

			{ connected ? (
				<div className="woodev-extensions__tabs" role="tablist">
					<button
						type="button"
						role="tab"
						aria-selected={ tab === 'catalog' }
						className={
							'woodev-extensions__tab' +
							( tab === 'catalog' ? ' is-active' : '' )
						}
						onClick={ () => setTab( 'catalog' ) }
					>
						{ __( 'Каталог', 'woodev-plugin-framework' ) }
					</button>
					<button
						type="button"
						role="tab"
						aria-selected={ tab === 'purchases' }
						className={
							'woodev-extensions__tab' +
							( tab === 'purchases' ? ' is-active' : '' )
						}
						onClick={ () => setTab( 'purchases' ) }
					>
						{ __( 'Мои покупки', 'woodev-plugin-framework' ) }
					</button>
				</div>
			) : null }

			{ connected && tab === 'purchases' ? (
				<PurchasesTab data={ purchases } products={ data ? data.products : [] } />
			) : (
				<Fragment>
					<SearchBox value={ search } onChange={ setSearch } />

					{ ! data && ! error ? (
						<p className="woodev-extensions__loading">
							{ __( 'Загрузка…', 'woodev-plugin-framework' ) }
						</p>
					) : null }

					{ failed ? (
						<p className="woodev-extensions__error">
							{ __( 'Не удалось загрузить каталог, попробуйте позже.', 'woodev-plugin-framework' ) }
						</p>
					) : null }

					{ ready ? (
						<Fragment>
							<CategoryFilter
								categories={ data.categories || [] }
								selected={ category }
								onSelect={ setCategory }
							/>
							<ExtensionGrid
								products={ products }
								installed={ config.installed || [] }
								purchased={ purchased }
							/>
						</Fragment>
					) : null }
				</Fragment>
			) }
		</div>
	);
}
```

> The `SearchBox` moved below the tab bar (was above `AccountMenu`); it now belongs to the catalog tab only. `AccountMenu` stays at the top in both tabs. The badge uses `purchased` from the same fetch, so it appears on catalog cards as soon as the purchases call resolves — no page-render block.

- [ ] **Step 3: Commit (after build in Task 7)** — staged with the other React changes.

---

## Task 7: Styles + build + verify + commit

**Files:**
- Modify: `src/plugins-page/style.scss`

- [ ] **Step 1: Add styles**

Append to `src/plugins-page/style.scss` (match existing BEM + the green installed badge already present in this file — find `.woodev-extension-card__badge` and add the cyan modifier near it; append the tab + purchases blocks at the end):

```scss
// «Куплено» badge — distinct accent (cyan) from the green «Установлен».
.woodev-extension-card__badge--purchased {
	background: #0a7c8a;
	color: #fff;
}

// Catalog / «Мои покупки» tab bar.
.woodev-extensions__tabs {
	display: flex;
	gap: 4px;
	margin: 16px 0;
	border-bottom: 1px solid #dcdcde;
}

.woodev-extensions__tab {
	appearance: none;
	background: none;
	border: 0;
	border-bottom: 2px solid transparent;
	padding: 8px 14px;
	font-size: 14px;
	cursor: pointer;
	color: #50575e;

	&.is-active {
		color: #1d2327;
		font-weight: 600;
		border-bottom-color: #2271b1;
	}
}

// «Мои покупки» list.
.woodev-purchases {
	margin: 0;
	padding: 0;
	list-style: none;
}

.woodev-purchases__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 4px;
	border-bottom: 1px solid #f0f0f1;
}

.woodev-purchases__icon {
	flex: 0 0 40px;

	img {
		width: 40px;
		height: 40px;
		border-radius: 6px;
		object-fit: cover;
		display: block;
	}
}

.woodev-purchases__icon-placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border-radius: 6px;
	background: #f0f0f1;
	font-weight: 600;
	color: #50575e;
}

.woodev-purchases__title {
	flex: 1 1 auto;
	font-weight: 500;
}

.woodev-purchases__date {
	flex: 0 0 auto;
	color: #787c82;
	font-size: 13px;
}
```

- [ ] **Step 2: Build the bundle**

Run (PowerShell): `npm run build`
Expected: webpack success; `woodev/assets/build/plugins-page/index.js` + `style-index.css` rebuilt. (LF line endings are pinned by `.gitattributes` — gotcha `build-artifacts-eol-lf-windows-parity`.)

- [ ] **Step 3: Full check green**

Run: `composer check`
Expected: phpcs + phpstan L3 + 701 unit tests all pass.

- [ ] **Step 4: Commit**

```bash
git add src/plugins-page/ woodev/assets/build/
git commit -m "feat(account): «Мои покупки» tab + «Куплено» catalog badge (React)"
```

---

## Task 8: Rig e2e verification (acceptance criterion)

> Drive container state via `docker exec <cli> wp eval-file` from **PowerShell** (Git-Bash mangles `/var/www`; cyrillic/quotes break inline `wp eval`). Reset the catalog cache after rig changes: `wp transient delete woodev_extensions_catalog_v2`. New: also `wp transient delete woodev_account_purchases` to force a fresh purchases fetch.

- [ ] **Step 1: Preconditions** — issuer (woodev_theme wp-env `:8090`, connector active, EDD); stand (framework wp-env `:8888`, `admin`/`password`), rig filters in `.wp-env-stand/woodev-stand.php` active, account connected (from s24). Ensure the issuer EDD user has ≥1 completed purchase (seed one if needed).

- [ ] **Step 2: Browser** — open «Woodev → Плагины» at `:8888`. Connected state shows the [Каталог][Мои покупки] tab bar.

- [ ] **Step 3** — switch to «Мои покупки»: the owned downloads list renders (icon, title, date); a row whose id matches a catalog product links to its store page.

- [ ] **Step 4** — on «Каталог», a product matching a purchase (but not installed) shows the cyan «Куплено» badge; an installed+purchased product shows «Установлен» (installed wins).

- [ ] **Step 5** — disconnect the account → reload: tab bar gone, no «Куплено» badges, purchases transient cleared.

---

## Task 9: PR + merge + docs

- [ ] **Step 1** — push branch, open PR.
- [ ] **Step 2** — wait for green CI (incl. Assets-build-parity); merge `gh pr merge <N> --squash --delete-branch` (NOT `--auto`); resync `main`.
- [ ] **Step 3** — session-save protocol: `docs-internal/CURRENT-STATE.md` + `SESSION-LOG.md`; record any new gotcha discovered; next-session prompt for s26.

---

## Self-Review

**Spec coverage:**
- #7 «Мои покупки» tab → Tasks 5, 6 (App fetch + tab), 7 (styles). ✅
- «Куплено» badge, installed-wins precedence → Task 4 + decision 2. ✅
- Signed proxy to connector `/purchases`, single payload (list + ids) → Task 3 + decision 1. ✅
- Pure normalizer + id collector, hostile-input safe, unit-tested → Task 1. ✅
- Cross-ref link by id (no permalink in contract) → Task 5 + decision 3. ✅
- includes() wiring (prod-fatal guard) → Task 2. ✅
- Codex review on auth proxy → Task 3 step 6. ✅
- Rig e2e → Task 8. ✅
- Merge hygiene + docs → Task 9. ✅

**Placeholder scan:** none — every code step shows full code.

**Type/name consistency:** `Woodev_Account_Purchases::normalize`/`download_ids`, lean item `{ id, title, icon, date }`, payload `{ purchases, purchased, stale? }`, REST `/account/purchases`, const `PURCHASES_CACHE_KEY = 'woodev_account_purchases'`, React props `isPurchased`/`purchased`/`data`/`products` — consistent across Tasks 1, 3, 4, 5, 6. ✅

**Test-count arithmetic:** baseline 690 → +6 (Task 1) = 696 (Task 2) → +5 (Task 3) = 701 (Tasks 3, 7). ✅

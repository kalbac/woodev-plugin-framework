# «Плагины» page redesign (OB-7, Phase A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]` checkboxes.

**Goal:** Replace the dated English server-rendered «Woodev → Плагины» add-on page with a modern WP-React catalog (license-page design parity, RU), fed by a `woodev/v1` REST proxy over `woodev.ru/edd-api/v2`, plus a feature-flagged account-connection UI scaffold.

**Architecture:** A new core REST controller `Woodev_REST_API_Extensions` registers `GET /woodev/v1/extensions` (cap `manage_options`) through the existing `Woodev_REST_V1_Registrar`; it fetches+caches categories/products from the store and returns a lean normalized payload. A React app `src/plugins-page/` (built with `@wordpress/scripts`, classic JSX runtime) mounts into the existing `woodev-extensions` admin page, fetches once via `apiFetch`, and filters client-side. The account panel is scaffolded but inert behind `accountEnabled` (default false).

**Tech Stack:** PHP 7.4+ (WPCS, PHPStan L3, Brain Monkey), `@wordpress/scripts` 32.x React, `@wordpress/{element,components,api-fetch,i18n}`.

**Spec:** `docs-internal/specs/2026-06-18-plugins-page-ob7-redesign-design.md`.

**Reference patterns (read before coding):**
- REST controller: `woodev/licensing/api/class-rest-api-license.php` (boot/register_routes/check_permissions/respond).
- Registrar: `woodev/rest-api/class-rest-v1-registrar.php`.
- Enqueue + inline bootstrap + mount: `woodev/admin/class-admin-pages.php:106-170` (`load_licenses_page_scripts`, `license_page`).
- React entry/JSX rules: `src/license-page/index.js`, `babel.config.js`, gotcha `wp-scripts-jsx-runtime-wp66`.
- Build parity gotchas: `build-artifacts-eol-lf-windows-parity`, `license-page-css-bundle-only`, i18n `russian-source-i18n-plural-n`.

---

## File Structure

**Create:**
- `woodev/rest-api/controllers/class-rest-api-extensions.php` — REST controller + remote fetch/cache + pure normalizer.
- `src/plugins-page/index.js` — React entry (apiFetch wiring + mount).
- `src/plugins-page/app.js` — root component (fetch, state, layout).
- `src/plugins-page/catalog.js` — `<CategoryFilter>`, `<SearchBox>`, `<ExtensionGrid>`, `<ExtensionCard>`.
- `src/plugins-page/account-panel.js` — feature-flagged account scaffold.
- `src/plugins-page/filter.js` — pure helpers `filterProducts`, `formatPrice`.
- `src/plugins-page/style.scss` — page styles (shared design tokens with license page).
- `tests/unit/ExtensionsRestControllerTest.php` — normalizer + permission + cache/fallback tests.

**Modify:**
- `woodev/class-plugin.php:571` — `require_once` the new controller (unconditional, with the other `woodev/v1` controllers).
- `woodev/class-plugin.php:286-315` (`add_hooks`) — `Woodev_REST_API_Extensions::boot();`.
- `woodev/admin/class-admin-pages.php:221-252` — `extensions_menu` enqueue → React bundle; `extensions_page` → React mount + RU `<h1>`; menu label → «Плагины»; drop `extensions_page_init`/`output` path.
- `package.json` — `build`/`start` scripts add the `plugins-page` entry.

**Delete:**
- `woodev/admin/pages/views/html-admin-page-plugins.php` (old view).
- `woodev/admin/pages/class-admin-plugins.php` (old controller — internal API, clean-break) — after confirming `class-admin-pages.php` is the only referrer.

---

## Task 1: REST controller — pure product normalizer (TDD)

**Files:**
- Create: `woodev/rest-api/controllers/class-rest-api-extensions.php`
- Test: `tests/unit/ExtensionsRestControllerTest.php`

- [ ] **Step 1: Write the failing test** (`tests/unit/ExtensionsRestControllerTest.php`)

```php
<?php

declare( strict_types=1 );

namespace Woodev\Tests\Unit;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-extensions.php';

/**
 * @covers \Woodev_REST_API_Extensions
 */
final class ExtensionsRestControllerTest extends TestCase {

	public function test_normalize_product_maps_paid_product(): void {
		$raw = (object) array(
			'info'    => (object) array(
				'id'        => 127940,
				'slug'      => 'wb',
				'title'     => 'Интеграция WB',
				'excerpt'   => '<b>desc</b>',
				'permalink' => 'https://woodev.ru/downloads/wb',
				'link'      => 'https://woodev.ru/?p=127940',
				'thumbnails'=> (object) array( 'small' => 'S.jpg', 'medium' => 'M.jpg' ),
				'thumbnail' => 'T.jpg',
				'category'  => array( (object) array( 'slug' => 'woocommerce' ), (object) array( 'slug' => 'marketing' ) ),
			),
			'pricing' => (object) array( 'amount' => '12500' ),
		);

		$out = \Woodev_REST_API_Extensions::normalize_product( $raw );

		$this->assertSame( 127940, $out['id'] );
		$this->assertSame( 'wb', $out['slug'] );
		$this->assertSame( 'Интеграция WB', $out['title'] );
		$this->assertSame( 12500, $out['price'] );
		$this->assertFalse( $out['free'] );
		$this->assertSame( 'M.jpg', $out['thumbnail'] );
		$this->assertSame( 'https://woodev.ru/downloads/wb', $out['permalink'] );
		$this->assertSame( array( 'woocommerce', 'marketing' ), $out['categories'] );
	}

	public function test_normalize_product_free_and_thumbnail_fallback(): void {
		$raw = (object) array(
			'info'    => (object) array(
				'id'        => 5,
				'slug'      => 'free',
				'title'     => 'Free',
				'link'      => 'https://woodev.ru/?p=5',
				'thumbnail' => 'only.jpg',
			),
			'pricing' => (object) array( 'amount' => '0' ),
		);

		$out = \Woodev_REST_API_Extensions::normalize_product( $raw );

		$this->assertSame( 0, $out['price'] );
		$this->assertTrue( $out['free'] );
		$this->assertSame( 'only.jpg', $out['thumbnail'] );
		$this->assertSame( 'https://woodev.ru/?p=5', $out['permalink'] );
		$this->assertSame( array(), $out['categories'] );
	}

	public function test_normalize_product_returns_null_without_id(): void {
		$raw = (object) array( 'info' => (object) array( 'title' => 'x' ) );
		$this->assertNull( \Woodev_REST_API_Extensions::normalize_product( $raw ) );
	}
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/unit/ExtensionsRestControllerTest.php`
Expected: FAIL — class `Woodev_REST_API_Extensions` not found.

- [ ] **Step 3: Create the controller with the pure normalizer**

Create `woodev/rest-api/controllers/class-rest-api-extensions.php`. The normalizer is pure+static; `esc_url_raw`/`wp_kses_post`/`absint` are stubbed by the test base (TestCase). Mirror `Woodev_REST_API_License` for boot/permissions/respond.

```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Extensions' ) ) :

	/**
	 * REST controller for the «Плагины» React page (`woodev/v1` namespace).
	 *
	 * Exposes one read route, `GET /woodev/v1/extensions`, which proxies the
	 * woodev.ru EDD storefront API (categories + products), normalizes each
	 * product to a lean UI shape, and caches the assembled payload in a
	 * transient. Network/secrets stay server-side; the React app makes a single
	 * apiFetch. Registered on core rest_api_init through Woodev_REST_V1_Registrar.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_REST_API_Extensions {

		/** Storefront category endpoint. */
		const CATEGORIES_URL = 'https://woodev.ru/edd-api/v2/categories';

		/** Storefront product endpoint. */
		const PRODUCTS_URL = 'https://woodev.ru/edd-api/v2/products/';

		/** Assembled-payload transient key. */
		const CACHE_KEY = 'woodev_extensions_catalog_v2';

		/** @var bool */
		private static $booted = false;

		/**
		 * Registers a single controller instance through the woodev/v1 registrar.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function boot(): void {

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			Woodev_REST_V1_Registrar::register_controller( new self() );
		}

		/**
		 * Registers the catalog route.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/extensions',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);
		}

		/**
		 * Capability gate — matches the «Плагины» admin page (manage_options).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return true|WP_Error
		 */
		public function check_permissions() {

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new WP_Error(
				'woodev_extensions_forbidden',
				esc_html__( 'Недостаточно прав для просмотра каталога плагинов.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * GET handler: returns { categories, products, stale }.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response
		 */
		public function get_items() {

			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}

			$categories = $this->fetch_categories();
			$products   = $this->fetch_products();

			$payload = array(
				'categories' => $categories,
				'products'   => $products,
				'stale'      => ( array() === $products ),
			);

			// Only cache a successful (non-empty) fetch; an outage stays retryable.
			if ( ! $payload['stale'] ) {
				set_transient( self::CACHE_KEY, $payload, WEEK_IN_SECONDS );
			}

			return rest_ensure_response( $payload );
		}

		/**
		 * Fetches + normalizes storefront categories ({slug,label}[]).
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,string>>
		 */
		private function fetch_categories(): array {

			$body = $this->remote_json( self::CATEGORIES_URL );

			if ( ! $body || ! isset( $body->categories ) || ! is_array( $body->categories ) ) {
				return array();
			}

			$out = array();

			foreach ( $body->categories as $cat ) {
				if ( isset( $cat->slug, $cat->label ) ) {
					$out[] = array(
						'slug'  => (string) $cat->slug,
						'label' => (string) $cat->label,
					);
				}
			}

			return $out;
		}

		/**
		 * Fetches + normalizes storefront products (lean UI shape).
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,mixed>>
		 */
		private function fetch_products(): array {

			$url  = add_query_arg( array( 'number' => -1 ), self::PRODUCTS_URL );
			$body = $this->remote_json( $url );

			if ( ! $body || ! isset( $body->products ) || ! is_array( $body->products ) ) {
				return array();
			}

			$out = array();

			foreach ( $body->products as $raw ) {
				$product = self::normalize_product( $raw );
				if ( null !== $product ) {
					$out[] = $product;
				}
			}

			return $out;
		}

		/**
		 * GETs a URL and json-decodes the body, or null on any failure.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url The request URL.
		 *
		 * @return object|null
		 */
		private function remote_json( string $url ) {

			$response = wp_safe_remote_get( $url );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			return is_object( $body ) ? $body : null;
		}

		/**
		 * Maps a raw EDD product object to the lean UI shape, or null when it has no id.
		 *
		 * PURE: depends only on its input + escaping helpers. The UTM decoration of the
		 * permalink keeps the legacy extensionsscreen campaign tags.
		 *
		 * @since 2.0.2
		 *
		 * @param object $raw Raw product object from edd-api/v2.
		 *
		 * @return array<string,mixed>|null
		 */
		public static function normalize_product( $raw ) {

			$info = isset( $raw->info ) && is_object( $raw->info ) ? $raw->info : null;

			if ( null === $info || empty( $info->id ) ) {
				return null;
			}

			$price = isset( $raw->pricing->amount ) ? (int) $raw->pricing->amount : 0;

			$thumbnail = $info->thumbnails->medium
				?? $info->thumbnails->small
				?? $info->thumbnail
				?? '';

			$permalink = ! empty( $info->permalink ) ? $info->permalink : ( $info->link ?? '' );

			$categories = array();
			if ( isset( $info->category ) && is_array( $info->category ) ) {
				foreach ( $info->category as $cat ) {
					if ( isset( $cat->slug ) ) {
						$categories[] = (string) $cat->slug;
					}
				}
			}

			return array(
				'id'         => (int) $info->id,
				'slug'       => (string) ( $info->slug ?? '' ),
				'title'      => (string) ( $info->title ?? '' ),
				'excerpt'    => wp_kses_post( (string) ( $info->excerpt ?? '' ) ),
				'thumbnail'  => esc_url_raw( (string) $thumbnail ),
				'permalink'  => esc_url_raw( self::utm_url( (string) $permalink, (string) ( $info->slug ?? '' ) ) ),
				'price'      => $price,
				'free'       => $price <= 0,
				'categories' => $categories,
			);
		}

		/**
		 * Decorates a storefront URL with the extensions-screen UTM campaign tags.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url     The storefront URL.
		 * @param string $content The utm_content value (product slug).
		 *
		 * @return string
		 */
		private static function utm_url( string $url, string $content ): string {

			if ( '' === $url ) {
				return '';
			}

			return add_query_arg(
				array(
					'utm_source'   => 'extensionsscreen',
					'utm_medium'   => 'product',
					'utm_campaign' => 'woodevplugin',
					'utm_content'  => $content,
				),
				$url
			);
		}
	}

endif;
```

- [ ] **Step 4: Run test, verify it passes**

Run: `./vendor/bin/phpunit tests/unit/ExtensionsRestControllerTest.php`
Expected: PASS (3 tests). If `wp_kses_post`/`esc_url_raw`/`add_query_arg` are undefined, add Brain Monkey stubs in the test `setUp()` (mirror `LicensePageRenderTest`'s WP-function stubs) — `Functions\when('wp_kses_post')->returnArg();`, `Functions\when('esc_url_raw')->returnArg();`, `Functions\when('add_query_arg')->alias(fn($a,$u)=>$u);`.

- [ ] **Step 5: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-extensions.php tests/unit/ExtensionsRestControllerTest.php
git commit -m "feat(plugins-page): add woodev/v1 extensions REST controller + normalizer"
```

---

## Task 2: Wire the controller (includes + boot)

**Files:**
- Modify: `woodev/class-plugin.php:571` (include) and `:286` (`add_hooks` boot)

- [ ] **Step 1: Add the include** after the license controllers (line ~571):

```php
require_once $framework_path . '/licensing/api/class-rest-api-license-command.php';
require_once $framework_path . '/rest-api/controllers/class-rest-api-extensions.php';
```

- [ ] **Step 2: Boot it in `add_hooks()`** (after `add_action( 'init', … 'load_updater' )`, line ~295):

```php
// Register the woodev/v1 extensions catalog REST controller (idempotent;
// must boot in all contexts because REST requests are not is_admin()).
Woodev_REST_API_Extensions::boot();
```

- [ ] **Step 3: Run unit suite, verify still green**

Run: `composer test:unit`
Expected: PASS (existing + 3 new).

- [ ] **Step 4: Commit**

```bash
git add woodev/class-plugin.php
git commit -m "feat(plugins-page): wire extensions REST controller into plugin boot"
```

---

## Task 3: React entry + build wiring

**Files:**
- Create: `src/plugins-page/index.js`, `src/plugins-page/style.scss`
- Modify: `package.json`

- [ ] **Step 1: Create `src/plugins-page/style.scss`** (minimal seed; expanded in Task 6):

```scss
.woodev-extensions-wrap {
	max-width: 1180px;
}
```

- [ ] **Step 2: Create `src/plugins-page/index.js`** (mirror `src/license-page/index.js`):

```js
/**
 * «Плагины» page — entry point. Wires apiFetch (root URL + nonce) and mounts
 * <App> into #woodev-extensions-app. Classic JSX runtime — every JSX file
 * imports { createElement, Fragment } from '@wordpress/element' (babel.config.js).
 *
 * @package woodev-plugin-framework
 */

import './style.scss';

// eslint-disable-next-line no-unused-vars -- required by classic JSX runtime.
import { createElement, Fragment, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './app';

const rootElement = document.getElementById( 'woodev-extensions-app' );

if ( rootElement && window.woodevExtensions ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( window.woodevExtensions.restRoot ) );
	apiFetch.use( apiFetch.createNonceMiddleware( window.woodevExtensions.restNonce ) );

	createRoot( rootElement ).render(
		<App config={ window.woodevExtensions } />
	);
}
```

- [ ] **Step 3: Update `package.json` scripts** to build both entries:

```json
"build": "wp-scripts build ./src/license-page/index.js --output-path=woodev/assets/build/license-page && wp-scripts build ./src/plugins-page/index.js --output-path=woodev/assets/build/plugins-page",
"start": "wp-scripts start ./src/license-page/index.js --output-path=woodev/assets/build/license-page",
"start:plugins": "wp-scripts start ./src/plugins-page/index.js --output-path=woodev/assets/build/plugins-page"
```

- [ ] **Step 4: Commit** (app.js follows in Task 4 — build will run there):

```bash
git add src/plugins-page/index.js src/plugins-page/style.scss package.json
git commit -m "build(plugins-page): add plugins-page React entry + build script"
```

---

## Task 4: React catalog components

**Files:**
- Create: `src/plugins-page/filter.js`, `src/plugins-page/catalog.js`, `src/plugins-page/app.js`

- [ ] **Step 1: Create `src/plugins-page/filter.js`** (pure helpers):

```js
/**
 * Pure helpers for the «Плагины» catalog — no React, no side effects.
 *
 * @package woodev-plugin-framework
 */

/**
 * Filters products by selected category slug and a free-text search.
 *
 * @param {Array}  products Normalized products.
 * @param {Object} opts     { category: string ('all'|slug), search: string }.
 * @return {Array} Filtered products.
 */
export function filterProducts( products, { category = 'all', search = '' } = {} ) {
	const needle = search.trim().toLowerCase();

	return ( products || [] ).filter( ( p ) => {
		const inCategory =
			category === 'all' || ( p.categories || [] ).includes( category );
		const matches =
			! needle ||
			( p.title || '' ).toLowerCase().includes( needle ) ||
			( p.excerpt || '' ).toLowerCase().includes( needle );
		return inCategory && matches;
	} );
}

/**
 * Formats an integer RUB price with ru-RU thousands separators.
 *
 * @param {number} price Integer amount.
 * @return {string} e.g. "12 500".
 */
export function formatPrice( price ) {
	return Number( price || 0 ).toLocaleString( 'ru-RU' );
}
```

- [ ] **Step 2: Create `src/plugins-page/catalog.js`** (filter chips, search, grid, card):

```js
/**
 * «Плагины» catalog UI — category chips, search box, responsive card grid.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- classic JSX runtime.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatPrice } from './filter';

export function SearchBox( { value, onChange } ) {
	return (
		<div className="woodev-extensions__search">
			<input
				type="search"
				value={ value }
				placeholder={ __( 'Поиск плагинов', 'woodev-plugin-framework' ) }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</div>
	);
}

export function CategoryFilter( { categories, selected, onSelect } ) {
	const chips = [ { slug: 'all', label: __( 'Все', 'woodev-plugin-framework' ) }, ...categories ];

	return (
		<div className="woodev-extensions__chips">
			{ chips.map( ( c ) => (
				<button
					key={ c.slug }
					type="button"
					className={
						'woodev-extensions__chip' +
						( c.slug === selected ? ' is-active' : '' )
					}
					onClick={ () => onSelect( c.slug ) }
				>
					{ c.label }
				</button>
			) ) }
		</div>
	);
}

export function ExtensionCard( { product } ) {
	const buttonText = product.free
		? __( 'Бесплатно', 'woodev-plugin-framework' )
		: // translators: %s is a formatted RUB amount.
		  __( 'Купить за %s ₽', 'woodev-plugin-framework' ).replace(
				'%s',
				formatPrice( product.price )
		  );

	return (
		<div className="woodev-extension-card">
			<a className="woodev-extension-card__media" href={ product.permalink } target="_blank" rel="noreferrer">
				{ product.thumbnail ? (
					<img src={ product.thumbnail } alt={ product.title } />
				) : null }
			</a>
			<div className="woodev-extension-card__body">
				<h3 className="woodev-extension-card__title">
					<a href={ product.permalink } target="_blank" rel="noreferrer">
						{ product.title }
					</a>
				</h3>
				<div
					className="woodev-extension-card__excerpt"
					dangerouslySetInnerHTML={ { __html: product.excerpt } }
				/>
			</div>
			<div className="woodev-extension-card__footer">
				<a className="button button-primary" href={ product.permalink } target="_blank" rel="noreferrer">
					{ buttonText }
				</a>
			</div>
		</div>
	);
}

export function ExtensionGrid( { products } ) {
	if ( ! products.length ) {
		return (
			<p className="woodev-extensions__empty">
				{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	return (
		<div className="woodev-extensions__grid">
			{ products.map( ( p ) => (
				<ExtensionCard key={ p.id } product={ p } />
			) ) }
		</div>
	);
}
```

- [ ] **Step 3: Create `src/plugins-page/app.js`** (fetch, state, layout):

```js
/**
 * «Плагины» page root: fetches the catalog once, holds search/category state,
 * renders the account scaffold + catalog.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- classic JSX runtime.
import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { filterProducts } from './filter';
import { SearchBox, CategoryFilter, ExtensionGrid } from './catalog';
import AccountPanel from './account-panel';

export default function App( { config } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState( 'all' );

	useEffect( () => {
		apiFetch( { path: '/woodev/v1/extensions' } )
			.then( ( res ) => setData( res ) )
			.catch( () => setError( true ) );
	}, [] );

	const products = data ? filterProducts( data.products, { category, search } ) : [];
	const failed = error || ( data && data.stale );

	return (
		<div className="woodev-extensions">
			<p className="woodev-extensions__intro">
				{ __( 'Расширьте возможности магазина плагинами Woodev.', 'woodev-plugin-framework' ) }
			</p>

			<SearchBox value={ search } onChange={ setSearch } />

			<AccountPanel enabled={ !! config.accountEnabled } />

			{ ! data && ! error ? (
				<p className="woodev-extensions__loading">{ __( 'Загрузка…', 'woodev-plugin-framework' ) }</p>
			) : null }

			{ failed ? (
				<p className="woodev-extensions__error">
					{ __( 'Не удалось загрузить каталог, попробуйте позже.', 'woodev-plugin-framework' ) }
				</p>
			) : null }

			{ data && ! data.stale ? (
				<Fragment>
					<CategoryFilter
						categories={ data.categories || [] }
						selected={ category }
						onSelect={ setCategory }
					/>
					<ExtensionGrid products={ products } />
				</Fragment>
			) : null }
		</div>
	);
}
```

- [ ] **Step 4: Commit**

```bash
git add src/plugins-page/filter.js src/plugins-page/catalog.js src/plugins-page/app.js
git commit -m "feat(plugins-page): React catalog (chips, search, grid, card) + pure filters"
```

---

## Task 5: Account panel scaffold (feature-flagged)

**Files:**
- Create: `src/plugins-page/account-panel.js`

- [ ] **Step 1: Create `src/plugins-page/account-panel.js`**:

```js
/**
 * Account-connection scaffold (Phase A). When the feature flag is off (default)
 * it renders a disabled «Подключить аккаунт» CTA describing the upcoming feature.
 * The live OAuth handshake (Phase B) is added once woodev-account-connector ships.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- classic JSX runtime.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function AccountPanel( { enabled } ) {
	// Phase A: scaffold only. When disabled we still show the CTA (inert) so the
	// page communicates the upcoming capability; live connect arrives in Phase B.
	return (
		<div className="woodev-extensions__account">
			<span className="woodev-extensions__account-text">
				{ __( 'Подключите аккаунт woodev.ru, чтобы видеть купленные плагины.', 'woodev-plugin-framework' ) }
			</span>
			<button type="button" className="button" disabled={ ! enabled }>
				{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
			</button>
		</div>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/plugins-page/account-panel.js
git commit -m "feat(plugins-page): account-connection UI scaffold (feature-flagged)"
```

---

## Task 6: PHP page render + enqueue + styles + decommission old code

**Files:**
- Modify: `woodev/admin/class-admin-pages.php`
- Modify: `src/plugins-page/style.scss`
- Delete: `woodev/admin/pages/views/html-admin-page-plugins.php`, `woodev/admin/pages/class-admin-plugins.php`

- [ ] **Step 1: Confirm the old controller has no other referrers**

Run: `grep -rn "Woodev_Admin_Plugins" woodev/ tests/`
Expected: only `woodev/admin/class-admin-pages.php`. If anything else references it, stop and reassess.

- [ ] **Step 2: Rewrite `extensions_menu`, enqueue, and page render** in `class-admin-pages.php`.

Replace the menu label, the `load_plugins_page_scripts` body (enqueue the React bundle, mirror `load_licenses_page_scripts`), and `extensions_page` (React mount). Delete `extensions_page_init`.

```php
public function extensions_menu() {

	$extensions_suffix = add_submenu_page(
		'woodev',
		__( 'Плагины Woodev', 'woodev-plugin-framework' ),
		__( 'Плагины', 'woodev-plugin-framework' ),
		'manage_options',
		'woodev-extensions',
		array( $this, 'extensions_page' )
	);

	add_action( 'admin_print_scripts-' . $extensions_suffix, array( $this, 'load_plugins_page_scripts' ) );
}

/**
 * Enqueues the React «Плагины» app bundle + inline bootstrap.
 *
 * @since 2.0.2
 *
 * @return void
 */
public function load_plugins_page_scripts(): void {

	$asset_file = $this->woodev_plugin->get_framework_path() . '/assets/build/plugins-page/index.asset.php';

	if ( file_exists( $asset_file ) ) {
		$asset = include $asset_file;
	} else {
		$asset = array(
			'dependencies' => array(),
			'version'      => $this->woodev_plugin->get_version(),
		);
	}

	$build_url = $this->woodev_plugin->get_framework_assets_url() . '/build/plugins-page';

	wp_enqueue_style( 'wp-components' );

	wp_enqueue_style(
		'woodev-plugins-app',
		$build_url . '/style-index.css',
		array( 'wp-components' ),
		$asset['version']
	);

	wp_enqueue_script(
		'woodev-plugins-app',
		$build_url . '/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_add_inline_script(
		'woodev-plugins-app',
		'window.woodevExtensions = ' . wp_json_encode(
			array(
				'restRoot'       => esc_url_raw( rest_url() ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				/**
				 * Gates the woodev.ru account-connection UI (Phase B). Default false
				 * until the woodev-account-connector plugin ships the OAuth endpoints.
				 *
				 * @since 2.0.2
				 *
				 * @param bool $enabled Whether to enable the account-connection UI.
				 */
				'accountEnabled' => (bool) apply_filters( 'woodev_extensions_account_enabled', false ),
			)
		) . ';',
		'before'
	);
}

public function extensions_page(): void {
	echo '<div class="wrap woodev-extensions-wrap">';
	echo '<h1 class="wp-heading-inline">' . esc_html__( 'Плагины Woodev', 'woodev-plugin-framework' ) . '</h1>';
	echo '<hr class="wp-header-end">';
	echo '<div id="woodev-extensions-app"></div>';
	echo '</div>';
}
```

Then delete the now-unused `extensions_page_init()` method.

- [ ] **Step 3: Delete the old view + controller**

```bash
git rm woodev/admin/pages/views/html-admin-page-plugins.php woodev/admin/pages/class-admin-plugins.php
```

- [ ] **Step 4: Flesh out `src/plugins-page/style.scss`** — page intro, search, account row, chips, responsive 3/2/1 grid, card. Reuse the license page's accent/spacing tokens (open `src/license-page/style.scss` and match variable values / radius / shadow for visual parity). Concrete starter:

```scss
.woodev-extensions-wrap { max-width: 1180px; }

.woodev-extensions {
	&__intro { font-size: 14px; color: #50575e; margin: .5em 0 1.5em; }

	&__search input[type="search"] {
		width: 100%; max-width: 420px; padding: 8px 12px;
		border: 1px solid #c3c4c7; border-radius: 6px;
	}

	&__account {
		display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
		margin: 16px 0; padding: 14px 16px;
		background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 8px;
	}
	&__account-text { flex: 1 1 240px; color: #1d2327; }

	&__chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 20px; }
	&__chip {
		padding: 6px 14px; border: 1px solid #c3c4c7; border-radius: 999px;
		background: #fff; cursor: pointer; font-size: 13px;
		&.is-active { background: #2271b1; border-color: #2271b1; color: #fff; }
	}

	&__grid {
		display: grid; gap: 20px;
		grid-template-columns: repeat(3, 1fr);
		@media (max-width: 1100px) { grid-template-columns: repeat(2, 1fr); }
		@media (max-width: 782px) { grid-template-columns: 1fr; }
	}

	&__empty, &__error, &__loading { color: #50575e; padding: 24px 0; }
	&__error { color: #b32d2e; }
}

.woodev-extension-card {
	display: flex; flex-direction: column;
	background: #fff; border: 1px solid #e0e0e0; border-radius: 10px;
	overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.04);

	&__media img { width: 100%; height: auto; display: block; }
	&__body { padding: 16px; flex: 1 1 auto; }
	&__title { margin: 0 0 8px; font-size: 15px; a { text-decoration: none; } }
	&__excerpt { font-size: 13px; color: #50575e; line-height: 1.5; }
	&__footer { padding: 0 16px 16px; }
}
```

- [ ] **Step 5: Build, then run full checks**

```bash
npm run build
composer check
```
Expected: webpack compiles both bundles; `composer check` green (phpcs + phpstan + unit).

- [ ] **Step 6: Commit** (src + build artifacts together — build parity)

```bash
git add woodev/admin/class-admin-pages.php src/plugins-page/style.scss woodev/assets/build/plugins-page
git rm woodev/admin/pages/views/html-admin-page-plugins.php woodev/admin/pages/class-admin-plugins.php
git commit -m "feat(plugins-page): React mount, enqueue, styles; remove legacy view/controller"
```

---

## Task 7: Rig browser verification + Codex critic + PR

- [ ] **Step 1: Browser-verify on the stand** (:8888, `woodev/` is live-mounted; `npm run build` output is live):
  - Navigate `wp-admin/admin.php?page=woodev-extensions`.
  - Confirm: catalog renders from live woodev.ru (cards with RU titles, prices «Купить за N ₽» / «Бесплатно»), category chips filter, search filters, card links open store permalink (UTM) in a new tab, account row shows the inert CTA, 0 console errors.
  - Empty-state: search gibberish → «Ничего не найдено».
- [ ] **Step 2: Codex inline critic** on the diff (REST proxy + normalizer + React). Present findings to operator; do not auto-fix.
- [ ] **Step 3: PR** `gh pr create`; merge `--squash --delete-branch` only after confirmed-green CI (never `--auto`); resync `git fetch && git reset --hard origin/main`.

---

## Self-Review (spec coverage)

- §1 REST proxy + normalize → Task 1/2. §2 data flow (client filter) → Task 4 `filter.js`/`app.js`. §3 layout → Task 6 styles + Task 4 components. §4 RU + no `_n()` → all strings via `__`, count-neutral. §5 errors/empty → `app.js` (`stale`/error/empty). §6 components → Tasks 4/5. §6a PHP → Tasks 1/2/6. §7 Phase B → spec only (no tasks; UI flag wired in Task 6). §8 testing → Task 1 unit + Task 7 rig. §9 out-of-scope respected (no VERSION bump; `@since 2.0.2`).
- Type consistency: `window.woodevExtensions.{restRoot,restNonce,accountEnabled}` set in Task 6, consumed in Task 3/4/5. Payload `{categories,products,stale}` produced in Task 1, consumed in Task 4. Mount id `woodev-extensions-app` consistent (Task 3 entry, Task 6 render).

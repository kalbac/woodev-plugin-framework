# Account-Connection Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the «Плагины» catalog's account-connection UI to life — a framework-side OAuth-style handshake client (`Woodev_Account_Connection`) that connects a store admin's woodev.ru account, shows the connected state (avatar + name + disconnect), and flags installed catalog products.

**Architecture:** A stateless `Woodev_Account_Connection` owns the handshake + signed transport + stored state (option `woodev_account_data`, transient `woodev_account_handshake`). A pure `Woodev_Account_Signer` reproduces the connector's HMAC byte-for-byte. Page-load handlers (query-flag triggered on the extensions admin page) drive the OAuth redirects; a REST route drives disconnect. The React app gains an `AccountMenu` component and installed-badge logic, fed by extended `window.woodevExtensions` bootstrap data.

**Tech Stack:** PHP 7.4+ (legacy `Woodev_*` prefix, no namespace — mirrors licensing classes), Brain Monkey + Mockery unit tests, `@wordpress/element` classic-JSX-runtime React, SCSS, `@wordpress/scripts` build.

**Source of truth:** spec `docs-internal/specs/2026-06-19-account-connection-client-design.md`. Live connector contract (verified s24, no drift): `woodev_theme/plugins/woodev-account-connector/includes/{class-signer,class-rest-controller,class-authorize-screen,class-pending-store}.php`.

**Versioning:** v2.0.1 unreleased → every new symbol is `@since 2.0.2`. Public `docs/` is NOT touched (s13 operator decision).

---

## Connector contract (frozen reference — sign against this exactly)

**Signer** (`Signer::sign`):
```php
$payload = wp_json_encode( [
    'host'        => (string) $host,
    'request_uri' => (string) $request_uri,
    'method'      => strtoupper( (string) $method ),
    'body'        => (string) $body,
    'timestamp'   => (string) $timestamp,
] );
return hash_hmac( 'sha256', (string) $payload, $key );   // key order is the contract
```

**Server reconstructs canonical fields as:** `host = $_SERVER['HTTP_HOST']`, `request_uri = $_SERVER['REQUEST_URI']`, `method = WP_REST_Request::get_method()`, `body = WP_REST_Request::get_body()`, `timestamp = X-Woodev-Timestamp header`.

**Routes** (namespace `woodev-account/v1`):
| Route | Method | Auth | Body | Response |
|---|---|---|---|---|
| `/oauth/request_token` | POST | open (same-origin) | `{home_url, redirect_uri}` | `{secret}` |
| `/oauth/authorize` | GET/POST | woodev.ru login | query `home_url&redirect_uri&secret` | browser screen → `redirect_uri?request_token=…` or `?woodev_account_denied=1` |
| `/oauth/access_token` | POST | signed w/ secret (NOT freshness-checked, but ts still signed) | `{request_token, home_url}` | `{access_token, access_token_secret, site_id}` |
| `/oauth/me` | GET | signed w/ token (Bearer + freshness ±300s) | — | `{name, email}` → **+`avatar`** (this plan) |
| `/oauth/invalidate_token` | POST | signed w/ token | — | `{success:true}` |

**Headers on signed requests:** `Authorization: Bearer <access_token>` (resource requests only, NOT access_token exchange), `X-Woodev-Signature: <hex>`, `X-Woodev-Timestamp: <unix>`.

**Authorize tamper-check:** the `home_url`/`redirect_uri` passed to `/oauth/authorize` must byte-equal the values registered at `/oauth/request_token` (server stores them `esc_url_raw`'d). ⇒ the client MUST reuse the identical strings.

---

## File Structure

**New (framework):**
- `woodev/account/class-account-signer.php` — pure HMAC helper (`Woodev_Account_Signer`).
- `woodev/account/class-account-connection.php` — handshake + transport + state (`Woodev_Account_Connection`).
- `woodev/account/class-installed-plugins.php` — pure installed-download-id collector (`Woodev_Installed_Plugins`).
- `woodev/rest-api/controllers/class-rest-api-account.php` — REST disconnect (`Woodev_REST_API_Account`).
- `src/plugins-page/account.js` — `AccountMenu` React component (replaces `account-panel.js`).
- Tests: `tests/unit/AccountSignerTest.php`, `tests/unit/AccountConnectionTest.php`, `tests/unit/InstalledPluginsTest.php`, `tests/unit/RestApiAccountTest.php`.

**Changed (framework):**
- `woodev/class-plugin.php` — `includes()` wires the 3 new account files + account REST controller; `add_hooks()` boots the account REST controller.
- `woodev/bootstrap.php` — add `get_active_plugin_instances(): array`.
- `woodev/admin/class-admin-pages.php` — `extensions_menu()` registers page-load handlers; `load_plugins_page_scripts()` adds `account` + `installed` bootstrap data.
- `woodev/rest-api/controllers/class-rest-api-extensions.php` — filterable store base URL (`woodev_extensions_store_url`).
- `src/plugins-page/app.js` — swap `AccountPanel` → `AccountMenu` (pass `config.account`).
- `src/plugins-page/catalog.js` — installed badge + button swap; `ExtensionGrid`/`App` thread `installed`.
- `src/plugins-page/style.scss` — account-menu + installed-badge styles.
- `tests/unit/ExtensionsRestControllerTest.php` — stub `apply_filters`/`untrailingslashit` for the new store_base path.

**Deleted (framework):**
- `src/plugins-page/account-panel.js` (replaced by `account.js`).

**Changed (woodev_theme — operator deploys):**
- `plugins/woodev-account-connector/includes/class-rest-controller.php` — `me()` adds `avatar`.
- connector avatar test.

---

## Task 1: Filterable store base URL for the catalog proxy

**Files:**
- Modify: `woodev/rest-api/controllers/class-rest-api-extensions.php`
- Test: `tests/unit/ExtensionsRestControllerTest.php`

- [ ] **Step 1: Update the existing controller test setUp to stub the new helpers**

In `tests/unit/ExtensionsRestControllerTest.php`, add to `setUp()` after the existing `add_query_arg` stub:

```php
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
```

- [ ] **Step 2: Run the suite to confirm it still passes (stubs are harmless no-ops yet)**

Run (PowerShell): `./vendor/bin/phpunit tests/unit/ExtensionsRestControllerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 3: Add the filterable `store_base()` and route the fetchers through it**

In `class-rest-api-extensions.php`, keep `CATEGORIES_URL`/`PRODUCTS_URL` consts (legacy defaults) but add a private resolver and use it. Replace the body of `fetch_categories()`'s first line and `fetch_products()`'s `$url` line:

Add this method (near `remote_json`):
```php
		/**
		 * The storefront base URL, overridable for the local rig.
		 *
		 * Mirrors the licensing `woodev_license_base_url` override point so the whole
		 * store side can be repointed at the issuer (:8090) during e2e.
		 *
		 * @since 2.0.2
		 *
		 * @return string Trailing-slash-trimmed base, e.g. https://woodev.ru.
		 */
		private function store_base(): string {

			/**
			 * Filters the woodev.ru storefront base URL the catalog proxy fetches from.
			 *
			 * @since 2.0.2
			 *
			 * @param string $base The storefront base URL.
			 */
			return untrailingslashit( apply_filters( 'woodev_extensions_store_url', 'https://woodev.ru' ) );
		}
```

In `fetch_categories()` change:
```php
			$body = $this->remote_json( self::CATEGORIES_URL );
```
to:
```php
			$body = $this->remote_json( $this->store_base() . '/edd-api/v2/categories' );
```

In `fetch_products()` change:
```php
			$url  = add_query_arg( array( 'number' => -1 ), self::PRODUCTS_URL );
```
to:
```php
			$url  = add_query_arg( array( 'number' => -1 ), $this->store_base() . '/edd-api/v2/products/' );
```

- [ ] **Step 4: Run the suite to confirm fetch paths still resolve (URL contains 'categories'/'products')**

Run: `./vendor/bin/phpunit tests/unit/ExtensionsRestControllerTest.php`
Expected: PASS — `stub_http()` keys on the substring `categories` in the URL, still present.

- [ ] **Step 5: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-extensions.php tests/unit/ExtensionsRestControllerTest.php
git commit -m "feat(extensions): filterable storefront base URL (woodev_extensions_store_url)"
```

---

## Task 2: `Woodev_Account_Signer` (pure HMAC, byte-exact)

**Files:**
- Create: `woodev/account/class-account-signer.php`
- Test: `tests/unit/AccountSignerTest.php`

- [ ] **Step 1: Write the failing test (byte-exact round-trip guard vs the connector contract)**

Create `tests/unit/AccountSignerTest.php`:
```php
<?php
/**
 * Unit tests for Woodev_Account_Signer.
 *
 * Byte-exactness guard: the signature must equal an independently-computed HMAC
 * over the documented canonical payload (host, request_uri, method-upper, body,
 * timestamp) — the round-trip cross-check against the connector's Signer.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';

/**
 * @covers \Woodev_Account_Signer
 */
final class AccountSignerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// wp_json_encode == json_encode for these ASCII payloads.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
	}

	public function test_sign_matches_documented_contract_byte_for_byte(): void {
		$parts = array(
			'host'        => 'localhost:8090',
			'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
			'method'      => 'get',           // lower-case on purpose: must be upper-cased.
			'body'        => '',
			'timestamp'   => '1750000000',
		);
		$key = 'secret-key-abc';

		// Independent expectation computed exactly as the connector documents.
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'localhost:8090',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
					'method'      => 'GET',
					'body'        => '',
					'timestamp'   => '1750000000',
				)
			),
			$key
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( $parts, $key ) );
	}

	public function test_sign_includes_body_and_is_key_order_stable(): void {
		// Parts supplied OUT OF ORDER must still produce the fixed-order payload.
		$parts = array(
			'timestamp'   => '42',
			'body'        => '{"request_token":"abc","home_url":"http:\/\/x"}',
			'method'      => 'POST',
			'request_uri' => '/wp-json/woodev-account/v1/oauth/access_token',
			'host'        => 'woodev.ru',
		);
		$key = 'k';

		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'woodev.ru',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/access_token',
					'method'      => 'POST',
					'body'        => '{"request_token":"abc","home_url":"http:\/\/x"}',
					'timestamp'   => '42',
				)
			),
			$key
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( $parts, $key ) );
	}

	public function test_missing_parts_default_to_empty_string(): void {
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array( 'host' => '', 'request_uri' => '', 'method' => '', 'body' => '', 'timestamp' => '' )
			),
			'k'
		);

		$this->assertSame( $expected, \Woodev_Account_Signer::sign( array(), 'k' ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/AccountSignerTest.php`
Expected: FAIL — class `Woodev_Account_Signer` not found.

- [ ] **Step 3: Implement `Woodev_Account_Signer`**

Create `woodev/account/class-account-signer.php`:
```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Signer' ) ) :

	/**
	 * Canonical HMAC-SHA256 request signer — the framework-side mirror of the
	 * woodev-account-connector's Signer.
	 *
	 * PURE: depends only on its inputs and wp_json_encode. The canonical payload
	 * key order — host, request_uri, method (upper-cased), body, timestamp — is the
	 * contract the woodev.ru connector verifies against, reproduced byte-for-byte
	 * regardless of input order. The timestamp is signed (not a side header) so a
	 * captured request cannot be replayed with a refreshed timestamp.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Signer {

		/**
		 * Computes the canonical signature for a request with the given key.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request Parts: host, request_uri, method, body, timestamp.
		 * @param string              $key     The signing secret.
		 *
		 * @return string Hex HMAC-SHA256 signature.
		 */
		public static function sign( array $request, string $key ): string {

			$payload = wp_json_encode(
				array(
					'host'        => (string) ( $request['host'] ?? '' ),
					'request_uri' => (string) ( $request['request_uri'] ?? '' ),
					'method'      => strtoupper( (string) ( $request['method'] ?? '' ) ),
					'body'        => (string) ( $request['body'] ?? '' ),
					'timestamp'   => (string) ( $request['timestamp'] ?? '' ),
				)
			);

			return hash_hmac( 'sha256', (string) $payload, $key );
		}
	}

endif;
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/AccountSignerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/account/class-account-signer.php tests/unit/AccountSignerTest.php
git commit -m "feat(account): Woodev_Account_Signer — byte-exact HMAC mirror of the connector"
```

---

## Task 3: `Woodev_Account_Connection` — state + canonical + connect URL

**Files:**
- Create: `woodev/account/class-account-connection.php`
- Test: `tests/unit/AccountConnectionTest.php`

This task builds the state/storage/canonical surface (no network). Network transport + handlers come in Tasks 4–5.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/AccountConnectionTest.php`:
```php
<?php
/**
 * Unit tests for Woodev_Account_Connection — state, canonical-field derivation,
 * connect-URL, and get_account() shape. Network/redirect paths are rig-verified.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';

/**
 * @covers \Woodev_Account_Connection
 */
final class AccountConnectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
	}

	/** Invokes a private method via reflection. */
	private function call_private( $object, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invokeArgs( $object, $args );
	}

	public function test_canonical_for_pretty_permalink_url(): void {
		$conn      = new \Woodev_Account_Connection();
		$canonical = $this->call_private(
			$conn,
			'canonical_for',
			array( 'http://localhost:8090/wp-json/woodev-account/v1/oauth/me', 'GET', '', '1750000000' )
		);

		$this->assertSame( 'localhost:8090', $canonical['host'] );
		$this->assertSame( '/wp-json/woodev-account/v1/oauth/me', $canonical['request_uri'] );
		$this->assertSame( 'GET', $canonical['method'] );
		$this->assertSame( '', $canonical['body'] );
		$this->assertSame( '1750000000', $canonical['timestamp'] );
	}

	public function test_canonical_for_plain_permalink_url_keeps_query(): void {
		$conn      = new \Woodev_Account_Connection();
		$canonical = $this->call_private(
			$conn,
			'canonical_for',
			array( 'https://woodev.ru/index.php?rest_route=/woodev-account/v1/oauth/me', 'get', '', '1' )
		);

		$this->assertSame( 'woodev.ru', $canonical['host'] );
		$this->assertSame( '/index.php?rest_route=/woodev-account/v1/oauth/me', $canonical['request_uri'] );
		$this->assertSame( 'GET', $canonical['method'] );
	}

	public function test_is_connected_reflects_stored_token(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertFalse( ( new \Woodev_Account_Connection() )->is_connected() );

		Functions\when( 'get_option' )->justReturn(
			array(
				'auth'           => array( 'access_token' => 'tok', 'access_token_secret' => 's', 'url' => 'https://woodev.ru' ),
				'auth_user_data' => array( 'name' => 'Jane', 'email' => 'j@x.dev', 'avatar' => 'https://x/a.png' ),
			)
		);
		$this->assertTrue( ( new \Woodev_Account_Connection() )->is_connected() );
	}

	public function test_get_account_disconnected_shape(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$account = ( new \Woodev_Account_Connection() )->get_account();

		$this->assertFalse( $account['connected'] );
		$this->assertSame( '', $account['name'] );
		$this->assertSame( '', $account['avatar'] );
	}

	public function test_get_account_connected_shape(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'auth'           => array( 'access_token' => 'tok', 'access_token_secret' => 's', 'url' => 'https://woodev.ru' ),
				'auth_user_data' => array( 'name' => 'Jane', 'email' => 'j@x.dev', 'avatar' => 'https://x/a.png' ),
			)
		);

		$account = ( new \Woodev_Account_Connection() )->get_account();

		$this->assertTrue( $account['connected'] );
		$this->assertSame( 'Jane', $account['name'] );
		$this->assertSame( 'j@x.dev', $account['email'] );
		$this->assertSame( 'https://x/a.png', $account['avatar'] );
		$this->assertSame( 'https://woodev.ru', $account['url'] );
	}

	public function test_get_connect_url_is_nonced_and_flagged(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url, $action ) {
				return $url . '&_wpnonce=' . md5( (string) $action );
			}
		);

		$url = ( new \Woodev_Account_Connection() )->get_connect_url();

		$this->assertStringContainsString( 'page=woodev-extensions', $url );
		$this->assertStringContainsString( 'woodev-account-connect=1', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/AccountConnectionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the state/canonical surface of `Woodev_Account_Connection`**

Create `woodev/account/class-account-connection.php` with the class skeleton + the methods exercised so far (transport `request()`, `disconnect()`, and the two page handlers are added in Tasks 4–5 — include their final bodies now per the code below so the file is written once; the tests in later tasks cover them):
```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Connection' ) ) :

	/**
	 * woodev.ru account-connection client.
	 *
	 * Owns the OAuth-style handshake with the woodev-account-connector plugin on the
	 * store, the signed transport for resource requests, and the locally stored
	 * connection state. Legacy-prefixed (mirrors the licensing classes). Stateless:
	 * all state lives in the `woodev_account_data` option and the short-lived
	 * `woodev_account_handshake` transient, so the object is cheap to `new`.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Connection {

		/** Connector REST namespace. @var string */
		const REST_NAMESPACE = 'woodev-account/v1';

		/** Stored-state option key (installed-site data — design-new, see spec). @var string */
		const OPTION_KEY = 'woodev_account_data';

		/** In-flight handshake transient key. @var string */
		const HANDSHAKE_KEY = 'woodev_account_handshake';

		/** Admin page slug hosting the connect/return handlers. @var string */
		const PAGE_SLUG = 'woodev-extensions';

		/**
		 * The store base URL, overridable for the local rig.
		 *
		 * @since 2.0.2
		 *
		 * @return string Trailing-slash-trimmed base, e.g. https://woodev.ru.
		 */
		private function api_base(): string {

			/**
			 * Filters the woodev.ru base URL the account client talks to.
			 *
			 * Mirrors `woodev_license_base_url`: repoint at the issuer (:8090) for e2e.
			 *
			 * @since 2.0.2
			 *
			 * @param string $base The account API base URL.
			 */
			return untrailingslashit( apply_filters( 'woodev_account_api_url', 'https://woodev.ru' ) );
		}

		/**
		 * Builds the full REST endpoint URL for a connector path.
		 *
		 * Pretty-permalink form (`/wp-json/{ns}{path}`). The store (woodev.ru) and the
		 * rig issuer both serve pretty permalinks; `canonical_for()` still derives the
		 * signed request_uri from whatever URL it is given, so a plain-permalink store
		 * only needs this method changed, not the signing.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path Leading-slash connector path, e.g. '/oauth/me'.
		 *
		 * @return string
		 */
		private function endpoint( string $path ): string {
			return $this->api_base() . '/wp-json/' . self::REST_NAMESPACE . $path;
		}

		/**
		 * Derives the canonical signed-request fields for an OUTGOING request exactly
		 * as the connector's server will reconstruct them from its superglobals.
		 *
		 * host = URL host (+ :port when explicit), request_uri = URL path (+ ?query),
		 * method upper-cased, body the exact bytes sent, timestamp the header value.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url       The full endpoint URL.
		 * @param string $method    HTTP method.
		 * @param string $body      The exact request body bytes ('' for none).
		 * @param string $timestamp The X-Woodev-Timestamp value.
		 *
		 * @return array<string,string>
		 */
		private function canonical_for( string $url, string $method, string $body, string $timestamp ): array {

			$parts = wp_parse_url( $url );

			$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			if ( isset( $parts['port'] ) ) {
				$host .= ':' . (int) $parts['port'];
			}

			$request_uri = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
				$request_uri .= '?' . (string) $parts['query'];
			}

			return array(
				'host'        => $host,
				'request_uri' => $request_uri,
				'method'      => strtoupper( $method ),
				'body'        => $body,
				'timestamp'   => $timestamp,
			);
		}

		/**
		 * Whether a connection token is stored.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_connected(): bool {
			$auth = $this->get_auth();
			return '' !== (string) ( $auth['access_token'] ?? '' );
		}

		/**
		 * The account state for the UI / bootstrap.
		 *
		 * @since 2.0.2
		 *
		 * @return array{connected:bool,name:string,email:string,avatar:string,url:string}
		 */
		public function get_account(): array {

			$auth = $this->get_auth();
			$user = $this->get_user_data();

			return array(
				'connected' => '' !== (string) ( $auth['access_token'] ?? '' ),
				'name'      => (string) ( $user['name'] ?? '' ),
				'email'     => (string) ( $user['email'] ?? '' ),
				'avatar'    => (string) ( $user['avatar'] ?? '' ),
				'url'       => (string) ( $auth['url'] ?? $this->api_base() ),
			);
		}

		/**
		 * The nonce'd connect-init admin URL the React app links to.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_connect_url(): string {

			$url = add_query_arg(
				array(
					'page'                   => self::PAGE_SLUG,
					'woodev-account-connect' => '1',
				),
				admin_url( 'admin.php' )
			);

			return wp_nonce_url( $url, 'woodev_account_connect' );
		}

		// ---- Stored state ----------------------------------------------------

		/**
		 * Reads the stored auth sub-array.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,mixed>
		 */
		private function get_auth(): array {
			$data = get_option( self::OPTION_KEY );
			return ( is_array( $data ) && isset( $data['auth'] ) && is_array( $data['auth'] ) ) ? $data['auth'] : array();
		}

		/**
		 * Reads the stored user-data sub-array.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,mixed>
		 */
		private function get_user_data(): array {
			$data = get_option( self::OPTION_KEY );
			return ( is_array( $data ) && isset( $data['auth_user_data'] ) && is_array( $data['auth_user_data'] ) ) ? $data['auth_user_data'] : array();
		}

		/**
		 * Persists the auth + user-data state.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $auth      Token bundle.
		 * @param array<string,mixed> $user_data { name, email, avatar }.
		 *
		 * @return void
		 */
		private function store_auth( array $auth, array $user_data ): void {
			update_option(
				self::OPTION_KEY,
				array(
					'auth'           => $auth,
					'auth_user_data' => $user_data,
				),
				false
			);
		}

		/**
		 * Clears all stored connection state.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function clear(): void {
			delete_option( self::OPTION_KEY );
		}

		// ---- Transport + handlers added in Tasks 4–5 -------------------------
	}

endif;
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/AccountConnectionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/account/class-account-connection.php tests/unit/AccountConnectionTest.php
git commit -m "feat(account): Woodev_Account_Connection state + canonical-field derivation"
```

---

## Task 4: `Woodev_Account_Connection` signed transport + disconnect

**Files:**
- Modify: `woodev/account/class-account-connection.php` (add `request()`, `disconnect()`)
- Test: `tests/unit/AccountConnectionTest.php` (add cases)

- [ ] **Step 1: Write the failing tests (transport signs + headers; disconnect clears even on remote error)**

Append to `AccountConnectionTest.php` (before the closing brace):
```php
	public function test_request_signs_resource_request_with_bearer_and_headers(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $d ) {
				return json_encode( $d );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'auth' => array(
					'access_token'        => 'TOK',
					'access_token_secret' => 'SECRET',
					'url'                 => 'https://woodev.ru',
				),
			)
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"name":"Jane","email":"j@x.dev","avatar":"a"}' );

		$captured = array();
		Functions\when( 'wp_safe_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array( 'url' => $url, 'args' => $args );
				return array();
			}
		);

		$out = ( new \Woodev_Account_Connection() )->request( 'GET', '/oauth/me' );

		$this->assertSame( 'Jane', $out['name'] );
		$this->assertSame( 'https://woodev.ru/wp-json/woodev-account/v1/oauth/me', $captured['url'] );
		$this->assertSame( 'Bearer TOK', $captured['args']['headers']['Authorization'] );
		$this->assertArrayHasKey( 'X-Woodev-Signature', $captured['args']['headers'] );
		$this->assertArrayHasKey( 'X-Woodev-Timestamp', $captured['args']['headers'] );

		// Signature must equal an independent HMAC over the canonical GET payload.
		$ts       = $captured['args']['headers']['X-Woodev-Timestamp'];
		$expected = hash_hmac(
			'sha256',
			json_encode(
				array(
					'host'        => 'woodev.ru',
					'request_uri' => '/wp-json/woodev-account/v1/oauth/me',
					'method'      => 'GET',
					'body'        => '',
					'timestamp'   => (string) $ts,
				)
			),
			'SECRET'
		);
		$this->assertSame( $expected, $captured['args']['headers']['X-Woodev-Signature'] );
	}

	public function test_request_returns_wp_error_on_http_failure(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'TOK', 'access_token_secret' => 'S', 'url' => 'https://woodev.ru' ) )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'Unauthorized' );

		$mock_error = \Mockery::mock( 'WP_Error' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );

		$out = ( new \Woodev_Account_Connection() )->request( 'GET', '/oauth/me' );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_disconnect_clears_option_even_when_remote_errors(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $d ) { return json_encode( $d ); } );
		Functions\when( 'get_option' )->justReturn(
			array( 'auth' => array( 'access_token' => 'TOK', 'access_token_secret' => 'S', 'url' => 'https://woodev.ru' ) )
		);
		// Remote invalidate fails hard.
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_safe_remote_request' )->justReturn( array() );

		// The local option MUST be deleted regardless.
		Functions\expect( 'delete_option' )->once()->with( \Woodev_Account_Connection::OPTION_KEY )->andReturn( true );

		$this->assertTrue( ( new \Woodev_Account_Connection() )->disconnect() );
	}
```

Note: `WP_Error` must be importable. Add a minimal `WP_Error` stub if the suite lacks one — check `tests/unit/`; if absent, define it in `tests/bootstrap.php` or guard with `class_exists`. (Most likely already present from licensing tests; if `./vendor/bin/phpunit` errors on `WP_Error`, add `if ( ! class_exists('WP_Error') ) { class WP_Error {} }` to the test bootstrap.)

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/AccountConnectionTest.php`
Expected: FAIL — `request()` / `disconnect()` undefined.

- [ ] **Step 3: Implement `request()` + `disconnect()` (+ private `unauthorized_error`/`is_resource_path` helpers)**

In `class-account-connection.php`, replace the `// ---- Transport + handlers added in Tasks 4–5 ---` comment with:
```php
		/**
		 * Performs a signed request against a connector resource path and returns the
		 * decoded JSON array, or a WP_Error.
		 *
		 * Resource paths (everything except the unauthenticated handshake routes) get
		 * the `Authorization: Bearer` header; all signed requests get the canonical
		 * X-Woodev-Signature + X-Woodev-Timestamp. The signed body is the exact bytes
		 * sent (empty for a no-body request), so the connector's get_body() matches.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $method HTTP method.
		 * @param string              $path   Connector path, e.g. '/oauth/me'.
		 * @param array<string,mixed> $body   JSON body (omitted when empty).
		 *
		 * @return array<string,mixed>|WP_Error
		 */
		public function request( string $method, string $path, array $body = array() ) {

			$auth = $this->get_auth();
			$key  = (string) ( $auth['access_token_secret'] ?? '' );

			if ( '' === $key || '' === (string) ( $auth['access_token'] ?? '' ) ) {
				return new WP_Error( 'woodev_account_not_connected', __( 'Аккаунт не подключён.', 'woodev-plugin-framework' ) );
			}

			$url       = $this->endpoint( $path );
			$method    = strtoupper( $method );
			$json_body = array() === $body ? '' : (string) wp_json_encode( $body );
			$timestamp = (string) time();

			$signature = Woodev_Account_Signer::sign(
				$this->canonical_for( $url, $method, $json_body, $timestamp ),
				$key
			);

			$headers = array(
				'Authorization'      => 'Bearer ' . (string) $auth['access_token'],
				'X-Woodev-Signature' => $signature,
				'X-Woodev-Timestamp' => $timestamp,
			);

			$args = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => $headers,
			);

			if ( '' !== $json_body ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = $json_body;
			}

			$response = wp_safe_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error(
					'woodev_account_http_error',
					__( 'Сервер woodev.ru вернул ошибку.', 'woodev-plugin-framework' )
				);
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Disconnects: best-effort signed invalidate, then always clears local state.
		 *
		 * A revoked or unreachable connector must never strand the admin in a
		 * permanently-"connected" UI, so the local option is deleted even when the
		 * remote call errors.
		 *
		 * @since 2.0.2
		 *
		 * @return bool Always true (local clear cannot fail meaningfully).
		 */
		public function disconnect(): bool {

			if ( $this->is_connected() ) {
				// Best-effort; return value intentionally ignored.
				$this->request( 'POST', '/oauth/invalidate_token' );
			}

			$this->clear();

			return true;
		}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/AccountConnectionTest.php`
Expected: PASS (9 tests). If `WP_Error` is undefined, add the guard stub to `tests/bootstrap.php` and re-run.

- [ ] **Step 5: Commit**

```bash
git add woodev/account/class-account-connection.php tests/unit/AccountConnectionTest.php
git commit -m "feat(account): signed transport request() + best-effort disconnect()"
```

---

## Task 5: Connect-init / connect-return page handlers

**Files:**
- Modify: `woodev/account/class-account-connection.php` (add `handle_connect_init()`, `handle_connect_return()`, private `exchange_token()`, `fail_redirect()`)

These do full-page redirects (`wp_redirect`/`exit`) and are verified end-to-end at the rig; they are not unit-tested (redirect+exit is not Brain-Monkey-friendly). Keep them small and obviously-correct.

- [ ] **Step 1: Implement the handlers**

Append inside the class (after `disconnect()`):
```php
		/**
		 * Connect-init page handler: opens a handshake and redirects to the issuer's
		 * authorize screen. Hooked on the extensions page load when
		 * `?woodev-account-connect=1` is present.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void Redirects + exits, or returns on failure (caller renders the page).
		 */
		public function handle_connect_init(): void {

			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'woodev_account_connect' ) ) {
				return;
			}

			$home_url     = home_url();
			$return_nonce = wp_create_nonce( 'woodev_account_return' );
			$redirect_uri = add_query_arg(
				array(
					'page'                  => self::PAGE_SLUG,
					'woodev-account-return' => '1',
					'_wpnonce'              => $return_nonce,
				),
				admin_url( 'admin.php' )
			);

			$response = wp_safe_remote_post(
				$this->endpoint( '/oauth/request_token' ),
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'home_url'     => $home_url,
							'redirect_uri' => $redirect_uri,
						)
					),
				)
			);

			$secret = '';
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$secret  = is_array( $decoded ) ? (string) ( $decoded['secret'] ?? '' ) : '';
			}

			if ( '' === $secret ) {
				$this->fail_redirect( __( 'Не удалось начать подключение. Попробуйте позже.', 'woodev-plugin-framework' ) );
				return;
			}

			// Store the secret + the EXACT redirect_uri string registered with the
			// connector (the authorize screen tamper-checks it byte-for-byte).
			set_transient(
				self::HANDSHAKE_KEY,
				array(
					'secret'       => $secret,
					'redirect_uri' => $redirect_uri,
					'home_url'     => $home_url,
				),
				15 * MINUTE_IN_SECONDS
			);

			// Cross-origin to the issuer — wp_redirect (NOT wp_safe_redirect). The host
			// is the configured/filtered api_base origin only.
			wp_redirect(
				add_query_arg(
					array(
						'home_url'     => rawurlencode( $home_url ),
						'redirect_uri' => rawurlencode( $redirect_uri ),
						'secret'       => $secret,
					),
					$this->endpoint( '/oauth/authorize' )
				)
			);
			exit;
		}

		/**
		 * Connect-return page handler: verifies the nonce, exchanges the request_token,
		 * fetches the profile, stores state, and redirects to the clean page. Hooked on
		 * the extensions page load when `?woodev-account-return=1` is present.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void Redirects + exits, or returns on failure.
		 */
		public function handle_connect_return(): void {

			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'woodev_account_return' ) ) {
				return;
			}

			$handshake = get_transient( self::HANDSHAKE_KEY );
			delete_transient( self::HANDSHAKE_KEY ); // single-use, always.

			if ( isset( $_GET['woodev_account_denied'] ) ) {
				$this->fail_redirect( __( 'Подключение отклонено.', 'woodev-plugin-framework' ) );
				return;
			}

			$request_token = isset( $_GET['request_token'] ) ? sanitize_text_field( wp_unslash( $_GET['request_token'] ) ) : '';

			if ( ! is_array( $handshake ) || '' === (string) ( $handshake['secret'] ?? '' ) || '' === $request_token ) {
				$this->fail_redirect( __( 'Сессия подключения истекла. Попробуйте снова.', 'woodev-plugin-framework' ) );
				return;
			}

			if ( ! $this->exchange_token( (string) $handshake['secret'], $request_token ) ) {
				$this->fail_redirect( __( 'Не удалось завершить подключение.', 'woodev-plugin-framework' ) );
				return;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                     => self::PAGE_SLUG,
						'woodev-account-connected' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Exchanges an approved request_token for tokens (signed with the handshake
		 * secret), then fetches and stores the profile.
		 *
		 * @since 2.0.2
		 *
		 * @param string $secret        The handshake secret.
		 * @param string $request_token The approval code.
		 *
		 * @return bool True on a stored connection.
		 */
		private function exchange_token( string $secret, string $request_token ): bool {

			$url       = $this->endpoint( '/oauth/access_token' );
			$body      = array(
				'request_token' => $request_token,
				'home_url'      => home_url(),
			);
			$json_body = (string) wp_json_encode( $body );
			$timestamp = (string) time();
			$signature = Woodev_Account_Signer::sign(
				$this->canonical_for( $url, 'POST', $json_body, $timestamp ),
				$secret
			);

			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'       => 'application/json',
						'X-Woodev-Signature' => $signature,
						'X-Woodev-Timestamp' => $timestamp,
					),
					'body'    => $json_body,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $tokens ) || '' === (string) ( $tokens['access_token'] ?? '' ) ) {
				return false;
			}

			// Store tokens FIRST so the immediate signed /oauth/me uses them.
			$this->store_auth(
				array(
					'access_token'        => (string) $tokens['access_token'],
					'access_token_secret' => (string) ( $tokens['access_token_secret'] ?? '' ),
					'site_id'             => (string) ( $tokens['site_id'] ?? '' ),
					'url'                 => $this->api_base(),
					'user_id'             => get_current_user_id(),
					'updated'             => time(),
				),
				array()
			);

			$me = $this->request( 'GET', '/oauth/me' );

			if ( ! is_wp_error( $me ) ) {
				$auth = $this->get_auth();
				$this->store_auth(
					$auth,
					array(
						'name'   => (string) ( $me['name'] ?? '' ),
						'email'  => (string) ( $me['email'] ?? '' ),
						'avatar' => (string) ( $me['avatar'] ?? '' ),
					)
				);
			}

			return true;
		}

		/**
		 * Stores a flash error and redirects to the clean extensions page.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message Already-translated error text.
		 *
		 * @return void Redirects + exits.
		 */
		private function fail_redirect( string $message ): void {

			set_transient( 'woodev_account_notice', $message, 60 );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => self::PAGE_SLUG,
						'woodev-account-failed' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
```

- [ ] **Step 2: Run the connection suite (no new tests, must still pass)**

Run: `./vendor/bin/phpunit tests/unit/AccountConnectionTest.php`
Expected: PASS (9 tests) — handlers add no regressions.

- [ ] **Step 3: Lint the new file**

Run: `composer phpcs -- woodev/account/class-account-connection.php`
Expected: no errors (fix any with `composer phpcbf -- woodev/account/class-account-connection.php`).

- [ ] **Step 4: Commit**

```bash
git add woodev/account/class-account-connection.php
git commit -m "feat(account): connect-init + connect-return OAuth page handlers"
```

---

## Task 6: Installed-download-id collector

**Files:**
- Create: `woodev/account/class-installed-plugins.php`
- Modify: `woodev/bootstrap.php` (add `get_active_plugin_instances()`)
- Test: `tests/unit/InstalledPluginsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/InstalledPluginsTest.php`:
```php
<?php
/**
 * Unit tests for Woodev_Installed_Plugins::download_ids — the pure collector that
 * maps active plugin instances to their deduped, positive integer download ids.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-installed-plugins.php';

/**
 * @covers \Woodev_Installed_Plugins
 */
final class InstalledPluginsTest extends TestCase {

	/** Builds a stub plugin returning a given download id. */
	private function plugin( $download_id ): object {
		return new class( $download_id ) {
			private $id;
			public function __construct( $id ) {
				$this->id = $id;
			}
			public function get_download_id() {
				return $this->id;
			}
		};
	}

	public function test_collects_positive_int_ids_deduped(): void {
		$plugins = array(
			$this->plugin( 127940 ),
			$this->plugin( '21' ),     // numeric string → int.
			$this->plugin( 127940 ),   // duplicate → collapsed.
		);

		$this->assertSame(
			array( 127940, 21 ),
			\Woodev_Installed_Plugins::download_ids( $plugins )
		);
	}

	public function test_skips_zero_negative_and_non_plugin_entries(): void {
		$plugins = array(
			$this->plugin( 0 ),
			$this->plugin( -5 ),
			$this->plugin( '' ),
			'not-an-object',
			new \stdClass(), // no get_download_id().
			$this->plugin( 99 ),
		);

		$this->assertSame( array( 99 ), \Woodev_Installed_Plugins::download_ids( $plugins ) );
	}

	public function test_empty_input_yields_empty_array(): void {
		$this->assertSame( array(), \Woodev_Installed_Plugins::download_ids( array() ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/InstalledPluginsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Woodev_Installed_Plugins`**

Create `woodev/account/class-installed-plugins.php`:
```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Installed_Plugins' ) ) :

	/**
	 * Pure collector of installed framework-plugin download ids.
	 *
	 * Maps active plugin instances (each a singleton exposing get_download_id()) to a
	 * deduped list of positive integer EDD download ids — the «installed» set the
	 * «Плагины» catalog uses to badge already-installed products. PURE: depends only
	 * on the passed instances, so it is unit-tested with stubs and the live wiring
	 * just feeds it the bootstrap's resolved instances.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Installed_Plugins {

		/**
		 * Deduped positive-integer download ids from a list of plugin instances.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,mixed> $plugins Plugin instances (objects with get_download_id()).
		 *
		 * @return array<int,int> Deduped, order-preserving list of download ids.
		 */
		public static function download_ids( array $plugins ): array {

			$ids = array();

			foreach ( $plugins as $plugin ) {

				if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_download_id' ) ) {
					continue;
				}

				$id = (int) $plugin->get_download_id();

				if ( $id > 0 ) {
					$ids[ $id ] = $id; // keyed by id → dedup; insertion order preserved.
				}
			}

			return array_values( $ids );
		}
	}

endif;
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/InstalledPluginsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Add `get_active_plugin_instances()` to the bootstrap**

In `woodev/bootstrap.php`, insert after `load_plugins()` (around line 276):
```php
		/**
		 * Resolves the main-class singleton instance of each active framework plugin.
		 *
		 * Skips legacy callback-registered actives (no loader definition) and any
		 * definition whose main class lacks an `instance()` accessor. Used by the
		 * «Плагины» installed-badge collector.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,object> Plugin main-class instances.
		 */
		public function get_active_plugin_instances(): array {

			$instances = array();

			foreach ( $this->active_plugins as $plugin ) {

				$definition = $plugin['definition'] ?? null;

				if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
					continue;
				}

				$main_class = $definition->get_main_class();

				if ( null === $main_class || ! is_callable( array( $main_class, 'instance' ) ) ) {
					continue;
				}

				$instances[] = $main_class::instance();
			}

			return $instances;
		}
```

- [ ] **Step 6: Run the full bootstrap suite to confirm no regression**

Run: `./vendor/bin/phpunit tests/unit/BootstrapTest.php`
Expected: PASS (existing count unchanged — the new method is additive and untested at unit level; it is exercised at the rig).

- [ ] **Step 7: Commit**

```bash
git add woodev/account/class-installed-plugins.php woodev/bootstrap.php tests/unit/InstalledPluginsTest.php
git commit -m "feat(account): installed-download-id collector + bootstrap active-instance resolver"
```

---

## Task 7: REST disconnect controller

**Files:**
- Create: `woodev/rest-api/controllers/class-rest-api-account.php`
- Test: `tests/unit/RestApiAccountTest.php`

- [ ] **Step 1: Write the failing test (permission gate + handler clears state)**

Create `tests/unit/RestApiAccountTest.php`:
```php
<?php
/**
 * Unit tests for Woodev_REST_API_Account — the disconnect route's capability gate
 * and that the handler clears connection state.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-account.php';

/**
 * @covers \Woodev_REST_API_Account
 */
final class RestApiAccountTest extends TestCase {

	public function test_permissions_allow_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( ( new \Woodev_REST_API_Account() )->check_permissions() );
	}

	public function test_permissions_deny_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		$result = ( new \Woodev_REST_API_Account() )->check_permissions();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_disconnect_clears_and_returns_disconnected(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $t, $v = null ) { return $v; } );
		Functions\when( 'untrailingslashit' )->alias( static function ( $u ) { return rtrim( (string) $u, '/' ); } );
		Functions\when( 'get_option' )->justReturn( false ); // not connected → no remote call.
		Functions\when( 'rest_ensure_response' )->returnArg();

		// The handler must clear the option.
		Functions\expect( 'delete_option' )->once()->with( \Woodev_Account_Connection::OPTION_KEY )->andReturn( true );

		$response = ( new \Woodev_REST_API_Account() )->handle_disconnect();

		$this->assertFalse( $response['connected'] );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/RestApiAccountTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Woodev_REST_API_Account`**

Create `woodev/rest-api/controllers/class-rest-api-account.php` (mirrors the extensions controller's boot/permission pattern):
```php
<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Account' ) ) :

	/**
	 * REST controller for the account-connection actions (`woodev/v1` namespace).
	 *
	 * Exposes `POST /woodev/v1/account/disconnect` — capability-gated (manage_options)
	 * and protected by the core REST cookie nonce (apiFetch sends X-WP-Nonce). The
	 * connect/return handshake is NOT here: it needs full-page redirects and lives on
	 * the extensions admin-page load (Woodev_Account_Connection). Registered on core
	 * rest_api_init through Woodev_REST_V1_Registrar.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_REST_API_Account {

		/**
		 * Idempotency guard.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
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
		 * Registers the disconnect route.
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
				'/account/disconnect',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_disconnect' ),
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
				'woodev_account_forbidden',
				esc_html__( 'Недостаточно прав для управления подключением аккаунта.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * POST handler: disconnects and returns the new (disconnected) state.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response|array<string,bool>
		 */
		public function handle_disconnect() {

			( new Woodev_Account_Connection() )->disconnect();

			return rest_ensure_response( array( 'connected' => false ) );
		}
	}

endif;
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/RestApiAccountTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-account.php tests/unit/RestApiAccountTest.php
git commit -m "feat(account): REST disconnect controller (manage_options + nonce)"
```

---

## Task 8: Wire includes(), boot(), and admin-pages integration

**Files:**
- Modify: `woodev/class-plugin.php` (`includes()` + `add_hooks()`)
- Modify: `woodev/admin/class-admin-pages.php` (`extensions_menu()` + `load_plugins_page_scripts()`)

⚠️ Gotcha `framework/includes-wiring`: every new class file MUST be `require_once`'d in `includes()` or production fatals (the test classmap masks it).

- [ ] **Step 1: Wire the new files in `includes()`**

In `woodev/class-plugin.php`, after the extensions controller require (line ~576), add:
```php
			require_once $framework_path . '/account/class-account-signer.php';
			require_once $framework_path . '/account/class-account-connection.php';
			require_once $framework_path . '/account/class-installed-plugins.php';
			require_once $framework_path . '/rest-api/controllers/class-rest-api-account.php';
```

- [ ] **Step 2: Boot the account REST controller in `add_hooks()`**

In `woodev/class-plugin.php`, immediately after the `Woodev_REST_API_Extensions::boot();` line (~299):
```php
			// Register the woodev/v1 account disconnect REST controller (idempotent).
			Woodev_REST_API_Account::boot();
```

- [ ] **Step 3: Register the connect/return page-load handlers in `extensions_menu()`**

In `woodev/admin/class-admin-pages.php`, replace `extensions_menu()` with:
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
			add_action( 'load-' . $extensions_suffix, array( $this, 'handle_account_page_load' ) );
		}

		/**
		 * Drives the account-connection OAuth handlers on the extensions page load.
		 *
		 * Query-flag triggered (mirrors the plugin-install tab redirect). Inert unless
		 * the account feature is enabled, so the handshake stays gated until the rig
		 * flip. The handlers themselves verify nonce + capability and redirect+exit.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function handle_account_page_load(): void {

			if ( ! apply_filters( 'woodev_extensions_account_enabled', false ) ) {
				return;
			}

			$connection = new Woodev_Account_Connection();

			if ( isset( $_GET['woodev-account-connect'] ) ) {
				$connection->handle_connect_init();
			} elseif ( isset( $_GET['woodev-account-return'] ) ) {
				$connection->handle_connect_return();
			}
		}
```

- [ ] **Step 4: Extend the bootstrap data in `load_plugins_page_scripts()`**

In `woodev/admin/class-admin-pages.php`, replace the `wp_add_inline_script` array inside `load_plugins_page_scripts()` with the account + installed additions:
```php
			$connection = new Woodev_Account_Connection();

			$account               = $connection->get_account();
			$account['connectUrl'] = $connection->get_connect_url();
			$account['myAccountUrl'] = apply_filters( 'woodev_account_api_url', 'https://woodev.ru' ) . '/my-account/';

			$installed = Woodev_Installed_Plugins::download_ids(
				Woodev_Plugin_Bootstrap::instance()->get_active_plugin_instances()
			);

			// Inline bootstrap data BEFORE the bundle so it is available on first render.
			wp_add_inline_script(
				'woodev-plugins-app',
				'window.woodevExtensions = ' . wp_json_encode(
					array(
						'restRoot'       => esc_url_raw( rest_url() ),
						'restNonce'      => wp_create_nonce( 'wp_rest' ),
						/**
						 * Gates the woodev.ru account-connection UI (Phase B).
						 *
						 * @since 2.0.2
						 *
						 * @param bool $enabled Whether to enable the account-connection UI.
						 */
						'accountEnabled' => (bool) apply_filters( 'woodev_extensions_account_enabled', false ),
						'account'        => $account,
						'installed'      => $installed,
					)
				) . ';',
				'before'
			);
```

- [ ] **Step 5: Run the full unit suite + static analysis**

Run: `composer check`
Expected: PASS — 668 baseline + new account tests (AccountSigner 3, AccountConnection 9, InstalledPlugins 3, RestApiAccount 3 = +18 → ~686). PHPStan L3 clean. PHPCS clean.

- [ ] **Step 6: Commit**

```bash
git add woodev/class-plugin.php woodev/admin/class-admin-pages.php
git commit -m "feat(account): wire account files + boot disconnect route + bootstrap data (account/installed)"
```

---

## Task 9: `AccountMenu` React component (#6 disconnected / #9 connected)

**Files:**
- Create: `src/plugins-page/account.js`
- Delete: `src/plugins-page/account-panel.js`
- Modify: `src/plugins-page/app.js`

- [ ] **Step 1: Create `src/plugins-page/account.js`**

```jsx
/**
 * Account-connection menu (Phase B). Disconnected (#6): a «Подключить аккаунт»
 * CTA linking to the server-side connect-init URL, plus a my-account link.
 * Connected (#9): avatar + display name with a dropdown to my-account and a
 * disconnect action (POSTs the REST route, then reloads to the disconnected
 * state). Renders nothing when the feature flag is off.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * The account menu.
 *
 * @param {Object}  props         Props.
 * @param {boolean} props.enabled Feature flag (window.woodevExtensions.accountEnabled).
 * @param {Object}  props.account { connected, name, email, avatar, url, connectUrl, myAccountUrl }.
 * @return {JSX.Element|null} The menu, or null when disabled.
 */
export default function AccountMenu( { enabled, account } ) {
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	if ( ! enabled || ! account ) {
		return null;
	}

	const myAccount = account.myAccountUrl || ( account.url ? account.url + '/my-account/' : '#' );

	const disconnect = () => {
		setBusy( true );
		apiFetch( { path: '/woodev/v1/account/disconnect', method: 'POST' } )
			.then( () => window.location.reload() )
			.catch( () => setBusy( false ) );
	};

	// ---- Disconnected (#6) --------------------------------------------------
	if ( ! account.connected ) {
		return (
			<div className="woodev-extensions__account">
				<span className="woodev-extensions__account-text">
					{ __( 'Подключите аккаунт woodev.ru, чтобы видеть купленные плагины.', 'woodev-plugin-framework' ) }
				</span>
				<div className="woodev-account-menu">
					<a className="button button-primary" href={ account.connectUrl }>
						{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
					</a>
					<a
						className="woodev-account-menu__link"
						href={ myAccount }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Личный кабинет на woodev.ru', 'woodev-plugin-framework' ) }
					</a>
				</div>
			</div>
		);
	}

	// ---- Connected (#9) -----------------------------------------------------
	return (
		<div className="woodev-extensions__account is-connected">
			<button
				type="button"
				className="woodev-account-menu__trigger"
				aria-expanded={ open }
				onClick={ () => setOpen( ( v ) => ! v ) }
			>
				{ account.avatar ? (
					<img className="woodev-account-menu__avatar" src={ account.avatar } alt="" />
				) : (
					<span className="woodev-account-menu__avatar woodev-account-menu__avatar--placeholder" aria-hidden="true">
						{ ( account.name || '?' ).trim().charAt( 0 ).toUpperCase() }
					</span>
				) }
				<span className="woodev-account-menu__name">{ account.name || account.email }</span>
				<span className="woodev-account-menu__caret" aria-hidden="true">▾</span>
			</button>

			{ open ? (
				<div className="woodev-account-menu__dropdown">
					<a
						className="woodev-account-menu__item"
						href={ myAccount }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Личный кабинет', 'woodev-plugin-framework' ) }
					</a>
					<button
						type="button"
						className="woodev-account-menu__item woodev-account-menu__item--danger"
						onClick={ disconnect }
						disabled={ busy }
					>
						{ busy
							? __( 'Отключение…', 'woodev-plugin-framework' )
							: __( 'Отключить аккаунт', 'woodev-plugin-framework' ) }
					</button>
				</div>
			) : null }
		</div>
	);
}
```

- [ ] **Step 2: Swap the import + usage in `src/plugins-page/app.js`**

Change the import (line 14):
```js
import AccountMenu from './account';
```
Change the usage (line 47):
```jsx
			<AccountMenu enabled={ !! config.accountEnabled } account={ config.account } />
```

- [ ] **Step 3: Delete the old scaffold**

```bash
git rm src/plugins-page/account-panel.js
```

- [ ] **Step 4: Lint the JS**

Run: `npx wp-scripts lint-js src/plugins-page/account.js src/plugins-page/app.js`
Expected: no errors (fix import ordering / hooks rules if flagged).

- [ ] **Step 5: Commit (build happens in Task 12)**

```bash
git add src/plugins-page/account.js src/plugins-page/app.js
git commit -m "feat(plugins-page): AccountMenu component (#6 connect / #9 connected + disconnect)"
```

---

## Task 10: Installed-badge in `ExtensionCard` (#5)

**Files:**
- Modify: `src/plugins-page/catalog.js`
- Modify: `src/plugins-page/app.js`

- [ ] **Step 1: Thread `installed` from `App` → `ExtensionGrid` → `ExtensionCard`**

In `src/plugins-page/app.js`, change the `ExtensionGrid` usage (line 68) to pass installed ids:
```jsx
						<ExtensionGrid products={ products } installed={ config.installed || [] } />
```

- [ ] **Step 2: Update `ExtensionGrid` + `ExtensionCard` in `src/plugins-page/catalog.js`**

Change `ExtensionGrid` to accept + forward `installed`:
```jsx
export function ExtensionGrid( { products, installed = [] } ) {
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
				<ExtensionCard
					key={ p.id }
					product={ p }
					isInstalled={ installed.includes( p.id ) }
				/>
			) ) }
		</div>
	);
}
```

Change `ExtensionCard` signature + head/footer to show the installed state:
```jsx
export function ExtensionCard( { product, isInstalled = false } ) {
	const buttonText = isInstalled
		? __( 'Посмотреть', 'woodev-plugin-framework' )
		: product.free
		? __( 'Бесплатно', 'woodev-plugin-framework' )
		: // translators: %s is a formatted RUB amount.
		  __( 'Купить за %s ₽', 'woodev-plugin-framework' ).replace(
				'%s',
				formatPrice( product.price )
		  );

	return (
		<div className={ 'woodev-extension-card' + ( isInstalled ? ' is-installed' : '' ) }>
			<div className="woodev-extension-card__head">
				<a
					className="woodev-extension-card__icon"
					href={ product.permalink }
					target="_blank"
					rel="noreferrer"
				>
					{ product.thumbnail ? (
						<img src={ product.thumbnail } alt={ product.title } loading="lazy" />
					) : (
						<span
							className="woodev-extension-card__icon-placeholder"
							aria-hidden="true"
						>
							{ ( product.title || '?' ).trim().charAt( 0 ).toUpperCase() }
						</span>
					) }
				</a>
				<h3 className="woodev-extension-card__title">
					<a href={ product.permalink } target="_blank" rel="noreferrer">
						{ product.title }
					</a>
				</h3>
				{ isInstalled ? (
					<span className="woodev-extension-card__badge">
						{ __( 'Установлен', 'woodev-plugin-framework' ) }
					</span>
				) : null }
			</div>
			{ product.rating ? <RatingStars rating={ product.rating } /> : null }
			<div
				className="woodev-extension-card__excerpt"
				// eslint-disable-next-line react/no-danger -- excerpt sanitized server-side with wp_kses_post.
				dangerouslySetInnerHTML={ { __html: product.excerpt } }
			/>
			<div className="woodev-extension-card__footer">
				<a
					className={
						'woodev-extension-card__buy' +
						( product.free && ! isInstalled ? ' is-free' : '' ) +
						( isInstalled ? ' is-installed' : '' )
					}
					href={ product.permalink }
					target="_blank"
					rel="noreferrer"
				>
					{ buttonText }
				</a>
			</div>
		</div>
	);
}
```

- [ ] **Step 3: Lint the JS**

Run: `npx wp-scripts lint-js src/plugins-page/catalog.js src/plugins-page/app.js`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/plugins-page/catalog.js src/plugins-page/app.js
git commit -m "feat(plugins-page): installed badge + «Посмотреть» button swap (#5)"
```

---

## Task 11: Styles for account menu + installed badge

**Files:**
- Modify: `src/plugins-page/style.scss`

- [ ] **Step 1: Add the account-menu + badge styles**

Append to `src/plugins-page/style.scss` (before the final closing of the file — these are top-level rules):
```scss
// ---- Account menu -----------------------------------------------------------
.woodev-extensions__account.is-connected {
	justify-content: flex-end;
}

.woodev-account-menu {
	position: relative;
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;

	&__link {
		font-size: 13px;
		color: $wd-accent-strong;
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}

	&__trigger {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		padding: 6px 12px;
		background: $wd-surface;
		border: 1px solid $wd-border;
		border-radius: 999px;
		cursor: pointer;
		font-size: 13px;
		color: $wd-ink;
		transition: border-color 0.15s ease, box-shadow 0.15s ease;

		&:hover {
			border-color: $wd-accent;
		}
	}

	&__avatar {
		width: 28px;
		height: 28px;
		border-radius: 50%;
		object-fit: cover;
		display: block;

		&--placeholder {
			display: flex;
			align-items: center;
			justify-content: center;
			font-weight: 700;
			color: $wd-accent-strong;
			background: rgba(0, 201, 253, 0.14);
		}
	}

	&__name {
		font-weight: 600;
		max-width: 180px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__caret {
		color: $wd-muted;
		font-size: 11px;
	}

	&__dropdown {
		position: absolute;
		top: calc(100% + 6px);
		right: 0;
		min-width: 200px;
		background: $wd-surface;
		border: 1px solid $wd-border;
		border-radius: $wd-radius-sm;
		box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
		padding: 6px;
		z-index: 10;
		display: flex;
		flex-direction: column;
	}

	&__item {
		display: block;
		width: 100%;
		box-sizing: border-box;
		text-align: left;
		padding: 9px 12px;
		font-size: 13px;
		color: $wd-ink;
		text-decoration: none;
		background: transparent;
		border: 0;
		border-radius: 6px;
		cursor: pointer;

		&:hover {
			background: rgba(0, 201, 253, 0.08);
		}

		&--danger {
			color: #b32d2e;

			&:hover {
				background: rgba(179, 45, 46, 0.08);
			}

			&:disabled {
				opacity: 0.6;
				cursor: default;
			}
		}
	}
}

// ---- Installed badge --------------------------------------------------------
.woodev-extension-card__badge {
	margin-left: auto;
	align-self: flex-start;
	padding: 3px 9px;
	font-size: 11px;
	font-weight: 600;
	color: #1a6b2f;
	background: rgba(38, 164, 76, 0.14);
	border-radius: 999px;
	white-space: nowrap;
}

.woodev-extension-card__buy.is-installed {
	background: transparent;
	color: $wd-ink;
	border-color: $wd-border;

	&:hover {
		background: rgba(0, 201, 253, 0.08);
		color: $wd-accent-strong;
		border-color: $wd-accent;
		box-shadow: none;
	}
}
```

- [ ] **Step 2: Commit (build in Task 12)**

```bash
git add src/plugins-page/style.scss
git commit -m "style(plugins-page): account menu + installed badge"
```

---

## Task 12: Build assets + green check + single build commit

**Files:**
- Modify: `woodev/assets/build/plugins-page/*` (generated)

⚠️ Gotcha `build-artifacts-eol-lf-windows-parity`: `.gitattributes` pins build output to LF; the build-parity CI rebuilds on Linux and diffs. Commit the build output exactly as produced.

- [ ] **Step 1: Build the bundle**

Run: `npm run build`
Expected: webpack compiles `plugins-page` with the new `account.js`; `account-panel.js` no longer referenced.

- [ ] **Step 2: Confirm the whole suite + lint is green**

Run: `composer check`
Expected: PASS (~686 unit tests, PHPStan L3 clean, PHPCS clean).

- [ ] **Step 3: Commit the build artifacts**

```bash
git add woodev/assets/build/plugins-page
git commit -m "build(plugins-page): rebuild bundle for AccountMenu + installed badge"
```

---

## Task 13: Connector `/oauth/me` avatar (woodev_theme — operator deploys)

**Files (in `D:\projects\woodev_theme`):**
- Modify: `plugins/woodev-account-connector/includes/class-rest-controller.php`
- Test: the connector's existing REST/me test suite

- [ ] **Step 1: Add a failing test for the avatar field**

In the connector test that covers `me()` (find it under `plugins/woodev-account-connector/tests/`), add an assertion that the `me()` response includes a string `avatar` key. Stub `get_avatar_url` to return a known URL and assert it surfaces.

- [ ] **Step 2: Run it to verify it fails**

Run (from `D:\projects\woodev_theme`, the connector's test command): expected FAIL — `avatar` absent.

- [ ] **Step 3: Add `avatar` to `me()`**

In `class-rest-controller.php` `me()`, change the response array to:
```php
			return rest_ensure_response( array(
				'name'   => $user ? (string) $user->display_name : '',
				'email'  => $user ? (string) $user->user_email : '',
				'avatar' => $user ? (string) get_avatar_url( (int) $connection->user_id ) : '',
			) );
```

- [ ] **Step 4: Run it to verify it passes**

Run the connector suite: expected PASS.

- [ ] **Step 5: Commit locally in the woodev_theme monorepo (operator deploys to prod)**

```bash
# in D:\projects\woodev_theme
git add plugins/woodev-account-connector/includes/class-rest-controller.php plugins/woodev-account-connector/tests
git commit -m "feat(account-connector): expose avatar in /oauth/me"
```

> The rig issuer is live-mapped, so this is picked up on the stand immediately after commit; production deploy is the operator's manual step.

---

## Task 14: Rig e2e verification (acceptance gate) + flip the flag

> The harness-less JS + the redirect/handshake are verified only here. Do NOT flip `woodev_extensions_account_enabled` until this passes.

**Rig:** issuer (woodev_theme wp-env) `http://localhost:8090`, CLI container `c8ec47a5035920b76223df8ef5b79e40-cli-1`; stand (framework wp-env) `http://localhost:8888` (`admin`/`password`), CLI container `de59f74e6d3d19d18a7f7b6608fda7e7-cli-1`. `./woodev` + `assets/build` live-mapped. Drive state with `docker exec <cli> wp eval-file` via **PowerShell** (gotcha `wp-safe-remote-request-local-rig` + cyrillic/quoting traps); eval files go in gitignored `.wp-env-stand/`, copied with `docker cp`.

- [ ] **Step 1: Bring both stacks up**

Run (PowerShell): `npx wp-env start` in each project dir, or confirm both are running.

- [ ] **Step 2: Ensure the issuer serves pretty permalinks (so `/wp-json/` resolves)**

Run: `docker exec <issuer-cli> wp rewrite structure '/%postname%/' --hard`
Then: `docker exec <issuer-cli> wp rewrite flush --hard`
Verify: `curl http://localhost:8090/wp-json/woodev-account/v1/oauth/request_token` returns a JSON error (route exists), not a 404 page.

- [ ] **Step 3: Point the stand's store + account URLs at the issuer + allow the local host**

In the gitignored `.wp-env-stand/woodev-stand.php` mu-plugin (or create it), add filters:
```php
add_filter( 'woodev_account_api_url', static fn() => 'http://localhost:8090' );
add_filter( 'woodev_extensions_store_url', static fn() => 'http://localhost:8090' );
add_filter( 'woodev_extensions_account_enabled', '__return_true' );
// Allow wp_safe_remote_* from the stand to reach the local issuer (SSRF guard).
add_filter( 'http_request_host_is_external', static function ( $ext, $host ) {
	return ( 'localhost' === $host ) ? true : $ext;
}, 10, 2 );
add_filter( 'http_allowed_safe_ports', static function ( $ports ) {
	$ports[] = 8090;
	return $ports;
} );
```
⚠️ The stand → issuer request originates from the stand; confirm `wp_safe_remote_post` to `localhost:8090` is NOT blocked (the filters above). Reset the catalog cache: `docker exec <stand-cli> wp transient delete woodev_extensions_catalog_v2`.

- [ ] **Step 4: Create an issuer user to approve with**

Confirm an issuer (woodev.ru-local) account exists you can log into to approve (e.g. issuer `admin`). An EDD purchase is not required for `/oauth/me`.

- [ ] **Step 5: Drive the connect handshake in the browser**

On the stand (`http://localhost:8888/wp-admin/admin.php?page=woodev-extensions`), confirm the «Подключить аккаунт» CTA shows. Click it → expect redirect to issuer `/oauth/authorize` → log in on the issuer → approve → redirected back to the stand showing **avatar + display name**.

- [ ] **Step 6: Verify stored state**

Run: `docker exec <stand-cli> wp eval-file /tmp/dump-account.php` where the file does `print_r( get_option( 'woodev_account_data' ) );`. Expect `auth.access_token`, `auth.access_token_secret`, `auth.site_id`, and `auth_user_data.{name,email,avatar}` populated.

- [ ] **Step 7: Verify the installed badge (#5)**

With at least one active framework plugin whose `get_download_id()` matches a catalog product id, confirm that product card shows «Установлен» + a «Посмотреть» button. (If no live overlap, temporarily filter `woodev_account_api_url`/store to a catalog containing a known installed id, or assert the `installed` array in `window.woodevExtensions` via the browser console.)

- [ ] **Step 8: Disconnect round-trip**

Open the connected dropdown → «Отключить аккаунт». Expect the page to reload to the disconnected CTA. Verify the option is gone: `get_option('woodev_account_data')` is empty, and the connection row on the issuer (`wp_woodev_account_connections` or the connector's table) is deleted.

- [ ] **Step 9: Flip the production default**

Only after Steps 5–8 pass: change the default in `woodev/admin/class-admin-pages.php` from `apply_filters( 'woodev_extensions_account_enabled', false )` → `true` in BOTH the `handle_account_page_load()` gate and the bootstrap-data line. Re-run `composer check`. Commit:
```bash
git add woodev/admin/class-admin-pages.php
git commit -m "feat(account): enable account-connection UI by default after rig e2e"
```

---

## Task 15: Codex adversarial review (mandatory) → PR → merge

> Per the s24 prompt + global Codex rule: adversarial review is REQUIRED on the security-critical surface. Findings are presented verbatim and NOT auto-fixed — ask the operator which to fix (recommended option first). Re-critic own in-place fixes before merging.

- [ ] **Step 1: Run Codex adversarial review on the security surface**

Bundle for `/codex:adversarial-review` (inline bundle — gotcha `codex-shell-sandbox-broken-windows`): the spec, the diffs for `class-account-signer.php`, `class-account-connection.php`, `class-rest-api-account.php`, `class-admin-pages.php`, and the connector `class-rest-controller.php`/`class-signer.php` as reference. Focus prompts:
  - Signature reconstruction: `request_uri` (pretty vs plain permalink), `body` byte-equality, `host` (port handling vs the server's `HTTP_HOST`), timestamp in payload, key order.
  - Connect-return CSRF nonce; handshake transient single-use + TTL; secret never logged (check no secret reaches `woodev_{plugin_id}_api_request_performed`).
  - Disconnect: `manage_options` + REST nonce; clears locally on remote error.
  - Open-redirect: connect-init only redirects to the configured store origin; connect-return only to the local extensions page.

- [ ] **Step 2: Triage findings with the operator**

Present findings verbatim; ask which to fix (recommended option first). Fix the agreed ones in place.

- [ ] **Step 3: Re-critic own fixes**

Run a Codex review pass on the in-place fixes (no self-certify — feedback `recritic_own_fixes`).

- [ ] **Step 4: Open the PR + merge on green**

```bash
git push -u origin <branch>
gh pr create --fill
# after CONFIRMED-green CI (incl. Assets-build-parity), NOT --auto:
gh pr merge <N> --squash --delete-branch
git checkout main && git pull
```

---

## Self-Review (against the spec)

- **Filterable store URL** (`woodev_account_api_url` + `woodev_extensions_store_url`) → Tasks 1, 3 (`api_base`/`store_base`). ✅
- **`Woodev_Account_Signer` byte-exact + unit test** → Task 2. ✅
- **`Woodev_Account_Connection`** connect/return handlers, token exchange, signed transport, `woodev_account_data` option, `woodev_account_handshake` transient → Tasks 3–5. ✅
- **REST disconnect** (`POST woodev/v1/account/disconnect`, manage_options + nonce, best-effort) → Task 7. ✅
- **Installed-id collector** from the bootstrap registry, deduped int>0 → Task 6. ✅
- **Bootstrap data** (`account` + `installed`) → Task 8. ✅
- **`AccountMenu`** #6/#9, gated by `accountEnabled` → Task 9. ✅
- **`ExtensionCard`** installed badge #5 + button swap → Task 10. ✅
- **Connector `/oauth/me` avatar** + test → Task 13. ✅
- **Rig e2e** + flag flip → Task 14. ✅
- **Codex adversarial review** on signing/auth/redirect → Task 15. ✅
- **`@since 2.0.2`**, public `docs/` untouched → throughout. ✅

**Known design notes (carried from the spec):**
- Signing assumes pretty-permalink REST URLs (`/wp-json/…`); `canonical_for()` still derives the signed `request_uri` from the actual URL, so a plain-permalink store only needs `endpoint()` changed, not the signing. The rig enforces pretty permalinks (Task 14 Step 2). Documented as the primary Codex target.
- `get_active_plugin_instances()` skips legacy callback-registered actives (no loader definition) — acceptable for the v2 fleet; rig-verified.

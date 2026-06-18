# «Woodev → Лицензии» Page UI/UX Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the license-management admin page (`admin.php?page=woodev-licenses`) — responsive card grid, a single key form-group (input + 👁 + Проверить), a 7-group state machine on real EDD statuses, a «Продлить» button backed by a new additive `renewal_url` field, RU-localized server messages, and compact quick-link cards.

**Architecture:** Frontend is React (classic JSX runtime, `@wordpress/components`) in `src/license-page/`, compiled by `@wordpress/scripts` to `woodev/assets/build/`. The card's presentation is driven by a new **pure** state-derivation helper (`card-state.js`) so the JSX stays declarative. The only backend change is **additive**: a `renewal_url` field in `Woodev_Plugins_License::get_state()`, sourced from a newly-public `Woodev_License_Messages::get_renewal_url()` (single source of truth). No REST routes, cache keys, option keys, `activate()`, or `deactivate()` change. Server status messages are localized to Russian (the framework's source language).

**Tech Stack:** PHP 7.4–8.1 (PHPUnit + Brain Monkey/Mockery, no WP), React via `@wordpress/element`/`@wordpress/components`, SCSS, `@wordpress/scripts` build.

**Authoritative spec:** `docs-internal/specs/2026-06-18-license-page-ui-ux-redesign.md` (APPROVED).

---

## Planning decisions (resolved, per spec "decide during planning")

1. **`upgrades_url` field — NOT added.** The «увеличить лимит» / upgrades CTA already lives inside the localized `message` (rendered via `RawHTML`). Adding a parallel field is redundant surface (YAGNI). Group D relies on the message link.
2. **«Изменить ключ» affordance — included (Task 7), minimal & client-only.** Shown only in masked, re-activatable groups (B/B′/C/D). Flips the field to editable + empty so the user can enter a fresh key and re-activate; a «Отмена» reverts. `deactivate()` backend behavior is unchanged. Group F (revoked) gets no change-key/activate path (dead key).
3. **`get_license_status()` labels localized to Russian too** (Task 2) — the card computes its own Russian badge text for every real status, but `status_label` is the fallback, so it must not leak English. Cheap, additive; the one shape-test assertion that pins the English string is updated in the same task.

## File structure

| File | Responsibility | Action |
|---|---|---|
| `woodev/licensing/class-plugin-license.php` | `get_state()` adds `renewal_url`; build `Woodev_License_Messages` once and reuse | Modify |
| `woodev/licensing/class-license-messages.php` | New public `get_renewal_url()`; RU-localize all message strings | Modify |
| `src/license-page/card-state.js` | **New.** Pure `getCardView(state)` → presentation descriptor (group, badge, key-field mode, action set) | Create |
| `src/license-page/license-card.js` | Rewrite the card around `getCardView`: key form-group, accent bar, footer actions, Бета tooltip, change-key affordance | Modify |
| `src/license-page/app.js` | Intro becomes an info-notice block (structure only; styling in SCSS) | Modify |
| `src/license-page/style.scss` | Grid 3/2/1, accent bars, key form-group, badges, intro notice, compact quick-link cards 4/2/1 | Modify |
| `woodev/admin/pages/views/html-settings-section.php` | Compact-card markup (icon-left, title→desc→CTA) | Modify |
| `tests/unit/LicensePureOperationsTest.php` | Update `get_state()` shape test; add `renewal_url` test; add URL-fn stubs | Modify |
| `woodev/assets/build/**` | Rebuilt bundle (commit alongside `src/`) | Generated |

---

## Task 1: Backend — `renewal_url` in `get_state()`

**Files:**
- Modify: `woodev/licensing/class-license-messages.php` (add public `get_renewal_url()`)
- Modify: `woodev/licensing/class-plugin-license.php:578-599` (`get_state()`)
- Test: `tests/unit/LicensePureOperationsTest.php`

- [ ] **Step 1: Add URL-building stubs to the test harness**

`get_state()` will now ALWAYS call the renewal-URL builder (previously only the expired/expiring message branches did), which reaches `Woodev_License_Messages::get_link_helper()`. That method calls `wp_parse_url`, `is_admin`, `sanitize_title`, `add_query_arg`, `trailingslashit` (and `esc_url`, already stubbed by `stubEscapeFunctions()`). Add the missing stubs to `stub_message_builder_functions()` in `tests/unit/LicensePureOperationsTest.php` — inside the existing `Functions\stubs([ ... ])` array (after `'site_url' => 'https://example.test',`):

```php
				// renewal_url path (get_state → Woodev_License_Messages::get_renewal_url
				// → get_link_helper) now runs on EVERY get_state() call. Keep it
				// deterministic and side-effect-free in unit context. is_admin()=>false
				// makes get_link_helper use 'organic' and skip Woodev_Helper::get_current_screen().
				'is_admin'        => false,
				'wp_parse_url'    => static function ( $url, $component = -1 ) {
					return wp_parse_url_native( $url, $component );
				},
				'sanitize_title'  => static function ( $title ) {
					return strtolower( (string) $title );
				},
				'trailingslashit' => static function ( $string ) {
					return rtrim( (string) $string, '/' ) . '/';
				},
				'add_query_arg'   => static function ( $args, $url ) {
					return $url . '?' . http_build_query( $args );
				},
```

Then, immediately ABOVE the `class LicensePureOperationsTest extends TestCase {` line, add a tiny native shim so the `wp_parse_url` stub can delegate to PHP's parser without recursing into the Brain Monkey stub:

```php
/**
 * Native parse_url delegate for the wp_parse_url stub (avoids stub recursion).
 *
 * @param string $url       URL to parse.
 * @param int    $component Component constant or -1 for the full array.
 * @return mixed
 */
function wp_parse_url_native( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
```

- [ ] **Step 2: Write the failing test for `renewal_url`**

Add this test method to `tests/unit/LicensePureOperationsTest.php` (near `test_get_state_shape`):

```php
	/**
	 * get_state(): exposes an additive renewal_url built from the same checkout
	 * URL + edd_license_key + download_id as Woodev_License_Messages::get_renewal_url().
	 * Additive field — safe for the bootstrap payload and every REST response.
	 *
	 * @return void
	 */
	public function test_get_state_includes_renewal_url(): void {
		$plugin = $this->make_plugin_stub();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

		$woodev_license            = Mockery::mock( \Woodev_License::class );
		$woodev_license->license   = 'valid';
		$woodev_license->expires   = 'lifetime';
		$woodev_license->item_name = 'Test Plugin';
		$woodev_license->item_id   = 216;
		$woodev_license->shouldReceive( 'get_license_key' )->andReturn( 'KEY-123' );

		$license = $this->make_license( $plugin, 'KEY-123', 'valid', $woodev_license );

		Functions\expect( 'current_time' )->andReturn( 1000 );

		$state = $license->get_state();

		$this->assertArrayHasKey( 'renewal_url', $state );
		$this->assertStringContainsString( 'woodev.ru/checkout', $state['renewal_url'] );
		$this->assertStringContainsString( 'edd_license_key=KEY-123', $state['renewal_url'] );
		$this->assertStringContainsString( 'download_id=216', $state['renewal_url'] );
	}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php --filter test_get_state_includes_renewal_url`
Expected: FAIL — `Failed asserting that array has the key 'renewal_url'` (and `get_renewal_url()` undefined once `get_state()` is wired).

- [ ] **Step 4: Add the public `get_renewal_url()` method**

In `woodev/licensing/class-license-messages.php`, directly ABOVE the existing `private function get_renewal_link()` (line ~222), add:

```php
		/**
		 * Public renewal-checkout URL for the current license.
		 *
		 * Single source of truth for the «Продлить» button in the license page UI
		 * and for the renewal CTAs embedded in the status messages.
		 *
		 * @since 2.0.2
		 *
		 * @return string The checkout URL with edd_license_key + download_id.
		 */
		public function get_renewal_url(): string {
			return $this->get_renewal_link();
		}
```

- [ ] **Step 5: Wire `renewal_url` into `get_state()`**

In `woodev/licensing/class-plugin-license.php`, replace the body of `get_state()` (lines ~578-599) so the message object is built ONCE and reused for both `message` and `renewal_url`:

```php
		public function get_state(): array {

			$status = (string) $this->woodev_license->license;

			$messages = new Woodev_License_Messages( $this->woodev_license );

			return array(
				'plugin_id'       => (string) $this->plugin->get_download_id(),
				'plugin_name'     => $this->plugin->get_plugin_name(),
				'license_key'     => (string) $this->license_key,
				'status'          => $status,
				'status_label'    => '' === $status ? '' : $this->get_license_status( $status ),
				'message'         => wp_kses_post( $messages->get_message() ),
				'message_variant' => $this->get_message_variant(),
				// Raw expiry — a numeric timestamp stays numeric, a 'Y-m-d H:i:s'
				// string stays a string, ''/null stay as-is. Do NOT coerce the type:
				// the React app distinguishes lifetime / date / unknown by raw value.
				'expires'         => $this->woodev_license->expires,
				'is_valid'        => $this->is_license_valid(),
				'is_active'       => $this->is_active(),
				'is_need_license' => (bool) $this->plugin->is_need_license(),
				'beta_enabled'    => (bool) $this->plugin->is_beta_allowed(),
				// Additive (2.0.2): renewal-checkout URL for the «Продлить» button.
				'renewal_url'     => $messages->get_renewal_url(),
			);
		}
```

Also update the `get_state()` docblock `@return` block: add `*     @type string $renewal_url     Renewal-checkout URL (edd_license_key + download_id).` after the `beta_enabled` line.

- [ ] **Step 6: Run the new test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php --filter test_get_state_includes_renewal_url`
Expected: PASS.

- [ ] **Step 7: Update the shape test for the new key**

In `tests/unit/LicensePureOperationsTest.php::test_get_state_shape`, add `'renewal_url',` to the `array_keys` expected array — as the LAST element, after `'beta_enabled',`:

```php
				'beta_enabled',
				'renewal_url',
```

- [ ] **Step 8: Run the full license operations suite**

Run: `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php`
Expected: PASS (all tests green).

- [ ] **Step 9: Commit**

```bash
git add woodev/licensing/class-plugin-license.php woodev/licensing/class-license-messages.php tests/unit/LicensePureOperationsTest.php
git commit -m "feat(licensing): additive renewal_url in get_state() for the Продлить button"
```

---

## Task 2: Backend — RU-localize license messages & status labels

**Files:**
- Modify: `woodev/licensing/class-license-messages.php` (all `__()` strings → Russian)
- Modify: `woodev/licensing/class-plugin-license.php:245-262` (`get_license_status()` labels → Russian)
- Test: `tests/unit/LicensePureOperationsTest.php` (update the one English-string assertion)

- [ ] **Step 1: Update the shape-test assertion to the localized label**

In `tests/unit/LicensePureOperationsTest.php::test_get_state_shape`, change:

```php
		$this->assertSame( 'License is valid', $state['status_label'] );
```

to:

```php
		$this->assertSame( 'Лицензия активна', $state['status_label'] );
```

- [ ] **Step 2: Run the shape test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php --filter test_get_state_shape`
Expected: FAIL — actual still `'License is valid'`.

- [ ] **Step 3: Localize `get_license_status()`**

In `woodev/licensing/class-plugin-license.php`, replace the `$statuses` array in `get_license_status()` (lines ~247-260) with Russian labels:

```php
			$statuses = array(
				'missing'               => __( 'Лицензия не найдена', 'woodev-plugin-framework' ),
				'missing_url'           => __( 'URL сайта не передан', 'woodev-plugin-framework' ),
				'license_not_activable' => __( 'Это родительский ключ комплекта', 'woodev-plugin-framework' ),
				'disabled'              => __( 'Ключ отозван', 'woodev-plugin-framework' ),
				'no_activations_left'   => __( 'Лимит активаций исчерпан', 'woodev-plugin-framework' ),
				'expired'               => __( 'Срок лицензии истёк', 'woodev-plugin-framework' ),
				'key_mismatch'          => __( 'Ключ не подходит для этого плагина', 'woodev-plugin-framework' ),
				'invalid_item_id'       => __( 'Неверный идентификатор плагина', 'woodev-plugin-framework' ),
				'item_name_mismatch'    => __( 'Ключ не подходит для этого плагина', 'woodev-plugin-framework' ),
				'site_inactive'         => __( 'Сайт не активирован для этой лицензии', 'woodev-plugin-framework' ),
				'invalid'               => __( 'Неверный лицензионный ключ', 'woodev-plugin-framework' ),
				'valid'                 => __( 'Лицензия активна', 'woodev-plugin-framework' ),
			);
```

And the fallback return on the next line:

```php
			return isset( $statuses[ $status_name ] ) ? $statuses[ $status_name ] : __( 'Неизвестный статус', 'woodev-plugin-framework' );
```

- [ ] **Step 4: Localize `Woodev_License_Messages` strings**

In `woodev/licensing/class-license-messages.php`, translate every English `__()` string to Russian, keeping the `%1$s`/`%2$s`/`%3$s` placeholders and the embedded `<a …>`/`</a>` link arguments byte-identical. Apply these replacements:

- `get_plugin_name()` fallback: `__( 'plugin', … )` → `__( 'плагина', … )`
- `build_message()` `invalid*` branch: `'This appears to be an invalid license key for %s.'` → `'Похоже, это неверный лицензионный ключ для %s.'`
- `license_not_activable`: `'The key you entered belongs to a bundle, please use the product specific license key.'` → `'Введённый ключ относится к комплекту. Используйте ключ конкретного товара.'`
- `deactivated`: `'Your license key has been deactivated.'` → `'Лицензионный ключ деактивирован.'`
- `default`: `'Unlicensed: currently not receiving updates.'` → `'Без лицензии: обновления сейчас не поступают.'`
- `get_valid_message()` lifetime: `'License key never expires.'` → `'Бессрочный лицензионный ключ.'`
- `get_valid_message()` expiring: `'Your license key expires soon! It expires on %1$s. %2$sRenew your key%3$s before it expires.'` → `'Срок действия ключа скоро истекает — %1$s. %2$sПродлите ключ%3$s заранее.'`
- `get_valid_message()` default: `'Your license key expires on %s.'` → `'Срок действия ключа — до %s.'`
- `get_expired_message()` dated: `'Your license key expired on %1$s. Please %2$srenew your license key%3$s.'` → `'Срок действия ключа истёк %1$s. %2$sПродлите лицензионный ключ%3$s.'`
- `get_expired_message()` undated: `'Your license key has expired. Please %1$srenew your license key%2$s.'` → `'Срок действия лицензионного ключа истёк. %1$sПродлите ключ%2$s.'`
- `get_disabled_message()`: `'Your license key has been disabled. Please %1$scontact support%2$s for more information.'` → `'Лицензионный ключ отключён. %1$sОбратитесь в поддержку%2$s за подробностями.'`
- `get_no_activations_message()`: `'Your license key has reached its activation limit. %1$sView possible upgrades%2$s now.'` → `'Достигнут лимит активаций ключа. %1$sПосмотрите варианты расширения%2$s.'`
- `get_inactive_message()`: `'Your %1$s license key is not active for this URL. Please %2$svisit your account page%3$s to manage your license keys.'` → `'Ключ %1$s не активирован для этого адреса. %2$sПерейдите в личный кабинет%3$s, чтобы управлять ключами.'`
- `get_missing_message()`: `'Invalid license. Please %1$svisit your account page%2$s and verify it.'` → `'Лицензия недействительна. %1$sПерейдите в личный кабинет%2$s и проверьте её.'`

Leave the existing Russian string `'Проблемы с лицензией'` (line ~349) as-is.

- [ ] **Step 5: Run the shape test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php --filter test_get_state_shape`
Expected: PASS.

- [ ] **Step 6: Run the full check gate**

Run: `composer check`
Expected: phpcs + phpstan + unit tests all green (650+ tests). If phpcs flags line length (>120) on a long Russian string, wrap per WPCS or shorten the wording (keep meaning).

- [ ] **Step 7: Commit**

```bash
git add woodev/licensing/class-license-messages.php woodev/licensing/class-plugin-license.php tests/unit/LicensePureOperationsTest.php
git commit -m "i18n(licensing): localize license status labels and messages to Russian"
```

---

## Task 3: Frontend — pure card-state derivation helper

**Files:**
- Create: `src/license-page/card-state.js`

This isolates ALL group/badge/action logic from JSX. No JS test harness exists in this repo (noted in the spec); correctness is verified in the browser (Task 9). Keeping it pure and in one file makes it reviewable and later-testable.

- [ ] **Step 1: Create `src/license-page/card-state.js`**

```javascript
/**
 * License card state derivation — PURE, no React, no side effects.
 *
 * Maps a get_state() object to a presentation descriptor that the LicenseCard
 * renders declaratively. Encodes the approved 7-group state machine on the real
 * EDD status tokens (spec: docs-internal/specs/2026-06-18-license-page-ui-ux-redesign.md).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';

// Raw EDD status tokens where the KEY ITSELF is suspect → field editable (group E).
const BAD_KEY_STATUSES = [
	'invalid',
	'key_mismatch',
	'item_name_mismatch',
	'invalid_item_id',
	'missing',
	'missing_url',
	'license_not_activable',
];

// Revoked/disabled → message only, no activate path (group F).
const REVOKED_STATUSES = [ 'disabled', 'revoked' ];

// Binding/limit → masked, re-activate to claim a slot (group D).
const BINDING_STATUSES = [ 'site_inactive', 'no_activations_left' ];

/**
 * Parses the raw expires value into a JS Date, or null when not a real date.
 *
 * @param {string|number|null} expires Raw expires ('lifetime'|'Y-m-d H:i:s'|timestamp|''|null).
 * @return {Date|null} Parsed date, or null for lifetime/empty/unknown.
 */
export function parseExpiry( expires ) {
	if ( ! expires || expires === 'lifetime' ) {
		return null;
	}
	if ( typeof expires === 'number' || /^\d+$/.test( String( expires ) ) ) {
		const date = new Date( Number( expires ) * 1000 );
		return isNaN( date.getTime() ) ? null : date;
	}
	// 'Y-m-d H:i:s' → ISO-ish for the Date parser.
	const date = new Date( String( expires ).replace( ' ', 'T' ) );
	return isNaN( date.getTime() ) ? null : date;
}

/**
 * Formats a Date as DD.MM.YY (or DD.MM when withYear is false).
 *
 * @param {Date}    date     The date.
 * @param {boolean} withYear Include the 2-digit year.
 * @return {string} Formatted date.
 */
export function formatExpiry( date, withYear = true ) {
	const dd = String( date.getDate() ).padStart( 2, '0' );
	const mm = String( date.getMonth() + 1 ).padStart( 2, '0' );
	if ( ! withYear ) {
		return `${ dd }.${ mm }`;
	}
	const yy = String( date.getFullYear() ).slice( -2 );
	return `${ dd }.${ mm }.${ yy }`;
}

/**
 * True when a valid license expires in under a month (group B′).
 *
 * @param {Date|null} expiry Parsed expiry date (null = lifetime).
 * @return {boolean} Whether it expires within ~30 days.
 */
function expiresSoon( expiry ) {
	if ( ! expiry ) {
		return false;
	}
	const now = Date.now();
	const ms  = expiry.getTime() - now;
	return ms > 0 && ms < 30 * 24 * 60 * 60 * 1000;
}

/**
 * Derives the presentation descriptor for a license card.
 *
 * @param {Object}  state           The get_state() object.
 * @param {boolean} editingKeyForce When true (user clicked «Изменить ключ»), force the editable+empty key path.
 * @return {Object} Descriptor: { group, accent, badge:{label,variant}, keyEditable, controlsEnabled, actions }.
 *                  actions: { activate, verify, renew, deactivate }.
 */
export function getCardView( state, editingKeyForce = false ) {
	const status = state.status || '';
	const hasKey = !! ( state.license_key && state.license_key.length );
	const expiry = parseExpiry( state.expires );

	// User explicitly chose to replace a saved key — behave like group A (no key).
	if ( editingKeyForce ) {
		return {
			group: 'editing',
			accent: 'neutral',
			badge: { label: __( 'Изменение ключа', 'woodev-plugin-framework' ), variant: 'info' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false, cancelEdit: true },
		};
	}

	// Group A — no key stored.
	if ( ! hasKey ) {
		return {
			group: 'no-key',
			accent: 'neutral',
			badge: { label: __( 'Не активирована', 'woodev-plugin-framework' ), variant: 'info' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false },
		};
	}

	// Group E — the key itself is suspect → editable to correct it.
	if ( BAD_KEY_STATUSES.includes( status ) ) {
		return {
			group: 'bad-key',
			accent: 'error',
			badge: { label: __( 'Неверный ключ', 'woodev-plugin-framework' ), variant: 'error' },
			keyEditable: true,
			controlsEnabled: false,
			actions: { activate: true, verify: false, renew: false, deactivate: false },
		};
	}

	// Group F — revoked/disabled → message only, masked, no activate.
	if ( REVOKED_STATUSES.includes( status ) ) {
		return {
			group: 'revoked',
			accent: 'error',
			badge: { label: __( 'Ключ отозван', 'woodev-plugin-framework' ), variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: false, deactivate: false, changeKey: false },
		};
	}

	// Group D — binding / limit → masked, re-activate to claim a slot.
	if ( BINDING_STATUSES.includes( status ) ) {
		const label = status === 'no_activations_left'
			? __( 'Лимит исчерпан', 'woodev-plugin-framework' )
			: __( 'Активна на другом сайте', 'woodev-plugin-framework' );
		return {
			group: 'binding',
			accent: 'error',
			badge: { label, variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: true, verify: true, renew: false, deactivate: false, changeKey: true },
		};
	}

	// Group C — expired → masked, renew (accent) + deactivate.
	if ( status === 'expired' ) {
		const when = expiry ? ` · ${ formatExpiry( expiry, true ) }` : '';
		return {
			group: 'expired',
			accent: 'error',
			badge: { label: __( 'Истекла', 'woodev-plugin-framework' ) + when, variant: 'error' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true, renewAccent: true },
		};
	}

	// Group B / B′ — valid.
	if ( status === 'valid' ) {
		if ( expiresSoon( expiry ) ) {
			return {
				group: 'expiring',
				accent: 'warning',
				badge: { label: __( 'Истекает ', 'woodev-plugin-framework' ) + formatExpiry( expiry, false ), variant: 'warning' },
				keyEditable: false,
				controlsEnabled: true,
				actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true },
			};
		}
		const tail = expiry
			? __( 'Активна · до ', 'woodev-plugin-framework' ) + formatExpiry( expiry, true )
			: __( 'Активна · Бессрочно', 'woodev-plugin-framework' );
		return {
			group: 'active',
			accent: 'success',
			badge: { label: tail, variant: 'success' },
			keyEditable: false,
			controlsEnabled: true,
			actions: { activate: false, verify: true, renew: true, deactivate: true, changeKey: true },
		};
	}

	// Fallback — unknown status: masked, show server label, allow re-verify.
	return {
		group: 'unknown',
		accent: 'info',
		badge: { label: state.status_label || __( 'Неизвестный статус', 'woodev-plugin-framework' ), variant: state.message_variant || 'info' },
		keyEditable: false,
		controlsEnabled: true,
		actions: { activate: false, verify: true, renew: false, deactivate: false, changeKey: true },
	};
}
```

- [ ] **Step 2: Lint the new file**

Run: `npm run lint:js -- src/license-page/card-state.js` (if the script exists; otherwise skip — `npm run build` in Task 8 will surface syntax/lint errors).
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/license-page/card-state.js
git commit -m "feat(license-page): pure card-state derivation for the redesign state machine"
```

---

## Task 4: Frontend — rewrite the license card

**Files:**
- Modify: `src/license-page/license-card.js`

Rebuild the card around `getCardView`: a single key form-group (`[input][👁][Проверить]`), a status badge from the descriptor, a left accent bar, footer actions (Активировать / Продлить / Деактивировать) on the left + Бета toggle pinned right with a tooltip. The license-free (S0) and error-notice branches are preserved.

- [ ] **Step 1: Replace the imports + helpers block**

In `src/license-page/license-card.js`, replace the import of components and the `maskKey` helper region (lines ~33-64) so `Tooltip` is imported and `getCardView` is used:

```javascript
// eslint-disable-next-line no-unused-vars -- classic JSX runtime pragma.
import { createElement, Fragment, RawHTML } from '@wordpress/element';
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	Flex,
	FlexItem,
	TextControl,
	Button,
	Notice,
	ToggleControl,
	Tooltip,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { getCardView } from './card-state';

/**
 * Masks a license key, keeping the first 4 and last 4 characters visible.
 *
 * @param {string} key The full license key.
 * @return {string} Masked key string.
 */
function maskKey( key ) {
	if ( ! key || key.length <= 8 ) {
		return key;
	}
	return key.slice( 0, 4 ) + '•'.repeat( Math.max( 4, key.length - 8 ) ) + key.slice( -4 );
}
```

- [ ] **Step 2: Add the change-key local state**

In `LicenseCard`, just after the existing `const [ error, setError ] = useState( null );` line (~92), add:

```javascript
	// «Изменить ключ» — client-only: replace a saved key without deactivating.
	const [ editingKey, setEditingKey ] = useState( false );
```

The three action handlers (`handleVerify`, `handleDeactivate`, `handleBetaToggle`) are UNCHANGED. After a successful `handleVerify`/`handleDeactivate`, also clear the editing flag — add `setEditingKey( false );` inside the `try` of `handleVerify` right after `setKeyInput( response.license_key || '' );` and the same inside `handleDeactivate` after its `setKeyInput(...)`.

- [ ] **Step 3: Keep the license-free (S0) branch as-is**

The `if ( ! isNeedLicense ) { … }` block (lines ~215-245) stays unchanged. Confirm `isNeedLicense` is still destructured from `state` (it is).

- [ ] **Step 4: Replace the standard-card render (from `const statusBadgeClass …` to the closing `);`)**

Replace everything from the `const statusBadgeClass = …` line (~251) through the final `}` of the component with:

```javascript
	const view = getCardView( state, editingKey );

	// In masked groups keyInput mirrors the stored key; in editable groups it is
	// the user's working value. When the user opts to change a saved key, start
	// from an empty field.
	const fieldValue = view.keyEditable
		? ( editingKey ? keyInput : keyInput )
		: maskKey( licenseKey );

	const showKeyField = view.group !== 'unknown' || licenseKey;

	return (
		<Card className={ `woodev-license-card woodev-license-card--${ view.accent }` }>
			{ confirmOpen && (
				<ConfirmDialog
					onConfirm={ handleDeactivate }
					onCancel={ () => setConfirmOpen( false ) }
				>
					{ __( 'Вы уверены, что хотите деактивировать лицензионный ключ?', 'woodev-plugin-framework' ) }
				</ConfirmDialog>
			) }

			<CardHeader>
				<Flex justify="space-between" align="center">
					<FlexItem>
						<strong>{ pluginName }</strong>
					</FlexItem>
					<FlexItem>
						<span className={ `woodev-license-badge is-${ view.badge.variant }` }>
							{ view.badge.label }
						</span>
					</FlexItem>
				</Flex>
			</CardHeader>

			<CardBody>
				{ message && (
					<Notice status={ messageVariant || 'info' } isDismissible={ false }>
						<RawHTML>{ message }</RawHTML>
					</Notice>
				) }

				{ errorNotice }

				{ showKeyField && (
					<div className="woodev-license-key-group">
						<TextControl
							className="woodev-license-key-input"
							hideLabelFromVision
							label={ __( 'Лицензионный ключ', 'woodev-plugin-framework' ) }
							value={ view.keyEditable
								? ( revealKey || editingKey ? keyInput : keyInput )
								: ( revealKey ? licenseKey : maskKey( licenseKey ) ) }
							readOnly={ ! view.keyEditable }
							onChange={ ( value ) => setKeyInput( value ) }
							placeholder={ __( 'Укажите ваш ключ', 'woodev-plugin-framework' ) }
						/>
						<Button
							className="woodev-license-eye-button"
							variant="secondary"
							disabled={ ! view.controlsEnabled }
							onClick={ () => setRevealKey( ( prev ) => ! prev ) }
							label={ revealKey
								? __( 'Скрыть ключ', 'woodev-plugin-framework' )
								: __( 'Показать ключ', 'woodev-plugin-framework' ) }
						>
							<span
								className={ `dashicons ${ revealKey ? 'dashicons-hidden' : 'dashicons-visibility' }` }
								aria-hidden="true"
							/>
						</Button>
						<Button
							className="woodev-license-verify-button"
							variant="secondary"
							isBusy={ verifying }
							disabled={ busy || ! view.controlsEnabled }
							onClick={ handleVerify }
						>
							{ __( 'Проверить', 'woodev-plugin-framework' ) }
						</Button>
					</div>
				) }
			</CardBody>

			<CardFooter>
				<Flex justify="space-between" align="center" wrap>
					<FlexItem>
						<Flex gap={ 2 } align="center" wrap>
							{ view.actions.activate && (
								<Button
									variant="primary"
									isBusy={ verifying }
									disabled={ busy || ! keyInput.trim() }
									onClick={ handleVerify }
								>
									{ verifying
										? __( 'Проверка…', 'woodev-plugin-framework' )
										: __( 'Активировать', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.renew && state.renewal_url && (
								<Button
									variant={ view.actions.renewAccent ? 'primary' : 'secondary' }
									href={ state.renewal_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'Продлить', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.deactivate && (
								<Button
									isDestructive
									disabled={ busy }
									isBusy={ deactivating }
									onClick={ () => setConfirmOpen( true ) }
								>
									{ __( 'Деактивировать', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.changeKey && ! editingKey && (
								<Button
									variant="link"
									disabled={ busy }
									onClick={ () => { setEditingKey( true ); setKeyInput( '' ); setRevealKey( false ); } }
								>
									{ __( 'Изменить ключ', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.cancelEdit && (
								<Button
									variant="link"
									onClick={ () => { setEditingKey( false ); setKeyInput( licenseKey || '' ); } }
								>
									{ __( 'Отмена', 'woodev-plugin-framework' ) }
								</Button>
							) }
						</Flex>
					</FlexItem>

					<FlexItem className="woodev-license-beta">
						<Tooltip text={ __( 'Разрешает устанавливать бета-версии плагина', 'woodev-plugin-framework' ) }>
							<span className="woodev-license-beta-toggle">
								<ToggleControl
									label={ __( 'Бета', 'woodev-plugin-framework' ) }
									checked={ betaEnabled }
									disabled={ busy }
									onChange={ handleBetaToggle }
								/>
							</span>
						</Tooltip>
						{ togglingBeta && <Spinner /> }
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}
```

> Note: the `fieldValue`/`showKeyField` consts above keep the render readable; if a worker finds them unused after wiring the inline `value={…}`, drop `fieldValue` (the inline ternary is authoritative). `showKeyField` IS used (gates the key group). Keep the JSX free of unused vars to satisfy eslint — remove `fieldValue` if `npm run build` warns.

- [ ] **Step 5: Build to surface JS errors (full build happens in Task 8; quick check here)**

Run: `npm run build`
Expected: build succeeds, no eslint errors. Fix any unused-var/lint complaints (notably remove `fieldValue` if flagged, and the now-unused `statusBadgeClass`/`isValid`/`maskKey`-old references).

- [ ] **Step 6: Commit (source only; the rebuilt bundle is committed in Task 8 with App + SCSS)**

```bash
git add src/license-page/license-card.js
git commit -m "feat(license-page): redesign card — key form-group, badge, actions, beta tooltip"
```

---

## Task 5: Frontend — intro info-notice + responsive grid & card SCSS

**Files:**
- Modify: `src/license-page/app.js`
- Modify: `src/license-page/style.scss`

- [ ] **Step 1: Wrap the intro as an info-notice block**

In `src/license-page/app.js`, replace the `<p className="woodev-licenses-intro">…</p>` element with a structured notice (keep the same text + account link):

```javascript
			<div className="woodev-licenses-intro" role="note">
				<span className="dashicons dashicons-info woodev-licenses-intro__icon" aria-hidden="true" />
				<p className="woodev-licenses-intro__text">
					{ __(
						'Для использования плагинов Woodev укажите и активируйте действующий лицензионный ключ. Ключ был отправлен на вашу электронную почту после покупки. Также его можно найти на ',
						'woodev-plugin-framework'
					) }
					<a href="https://woodev.ru/my-account" target="_blank" rel="noopener noreferrer">
						{ __( 'странице вашего аккаунта', 'woodev-plugin-framework' ) }
					</a>
					{ __( '.', 'woodev-plugin-framework' ) }
				</p>
			</div>
```

- [ ] **Step 2: Replace the layout SCSS (intro, grid, badge, key-group, accent)**

In `src/license-page/style.scss`, replace the block from `.woodev-licenses-intro {` (line ~8) through the end of `.woodev-license-eye-button { … }` (line ~75) with:

```scss
// Intro — info-notice spanning the content width.
.woodev-licenses-intro {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin: 0 0 20px;
	padding: 12px 16px;
	background: #f0f6fc;
	border: 1px solid #c5d9ed;
	border-left: 4px solid #2271b1;
	border-radius: 4px;

	&__icon {
		flex: 0 0 auto;
		margin-top: 2px;
		color: #2271b1;
	}

	&__text {
		margin: 0;
		line-height: 1.6;
	}
}

// Responsive card grid: 1 → 2 → 3 columns.
.woodev-licenses-grid {
	display: grid;
	gap: 16px;
	grid-template-columns: 1fr;
	align-items: start;

	@media ( min-width: 782px ) {
		grid-template-columns: repeat( 2, 1fr );
	}

	@media ( min-width: 1280px ) {
		grid-template-columns: repeat( 3, 1fr );
	}
}

// Left accent bar by status.
.woodev-license-card {
	border-left: 4px solid #c3c4c7; // neutral default

	&--success { border-left-color: #00a32a; }
	&--warning { border-left-color: #dba617; }
	&--error   { border-left-color: #d63638; }
	&--info,
	&--neutral { border-left-color: #72aee6; }
}

// Status badge pill — colored by variant.
.woodev-license-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	line-height: 1.6;
	color: #fff;
	white-space: nowrap;

	&.is-success { background-color: #00a32a; }
	&.is-warning { background-color: #dba617; color: #1d2327; }
	&.is-error   { background-color: #d63638; }
	&.is-info    { background-color: #72aee6; color: #1d2327; }
}

// Key form-group: [ input ][ 👁 ][ Проверить ] as one attached control.
.woodev-license-key-group {
	display: flex;
	align-items: stretch;
	margin: 12px 0 4px;

	.woodev-license-key-input {
		flex: 1 1 auto;
		margin-bottom: 0;

		input {
			height: 100%;
			border-top-right-radius: 0;
			border-bottom-right-radius: 0;
			font-family: monospace;
			letter-spacing: 0.06em;
		}
	}

	.woodev-license-eye-button,
	.woodev-license-verify-button {
		border-radius: 0;
		margin-left: -1px;
	}

	.woodev-license-verify-button {
		border-top-right-radius: 3px;
		border-bottom-right-radius: 3px;
	}

	.woodev-license-eye-button {
		min-width: 40px;
		justify-content: center;

		.dashicons {
			width: 18px;
			height: 18px;
			font-size: 18px;
		}
	}
}

// Beta toggle pinned to the footer's right edge.
.woodev-license-beta {
	.components-toggle-control {
		margin-bottom: 0;
	}
}
```

- [ ] **Step 3: Build to verify SCSS compiles (full build in Task 8)**

Run: `npm run build`
Expected: build succeeds; `woodev/assets/build/style-index.css` regenerates without SCSS errors.

- [ ] **Step 4: Commit (source only)**

```bash
git add src/license-page/app.js src/license-page/style.scss
git commit -m "feat(license-page): intro info-notice + responsive 3/2/1 grid and card styling"
```

---

## Task 6: Server view — compact quick-link cards

**Files:**
- Modify: `woodev/admin/pages/views/html-settings-section.php`
- Modify: `src/license-page/style.scss` (the `.woodev-settings-documentation` block)

The quick-links become compact cards modeled on `woodev.ru` `.card.card-compact`: icon on the LEFT (large, vertically centered), right column stacked title → short description → CTA, equal height, 4/2/1 responsive.

- [ ] **Step 1: Rewrite the markup**

Replace the entire body of `woodev/admin/pages/views/html-settings-section.php` (keep the `defined( 'ABSPATH' )` guard) with the compact-card structure — icon-left + content-right per block:

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="woodev-settings-documentation">
	<div class="woodev-settings-row">
		<div class="woodev-admin-block">
			<span class="woodev-admin-block__icon dashicons dashicons-admin-plugins" aria-hidden="true"></span>
			<div class="woodev-admin-block__content">
				<h4 class="woodev-admin-block__title">Все плагины</h4>
				<p class="woodev-admin-block__text">Ознакомьтесь с нашим ассортиментом плагинов в магазине — возможно, один из них вас заинтересует.</p>
				<a class="woodev-admin-block__cta" href="https://woodev.ru/shop" target="_blank" rel="noopener noreferrer">Перейти в магазин</a>
			</div>
		</div>
		<div class="woodev-admin-block">
			<span class="woodev-admin-block__icon dashicons dashicons-media-document" aria-hidden="true"></span>
			<div class="woodev-admin-block__content">
				<h4 class="woodev-admin-block__title">Документация</h4>
				<p class="woodev-admin-block__text">Перед использованием изучите документацию — основные принципы работы и доступные опции.</p>
				<a class="woodev-admin-block__cta" href="https://woodev.ru/docs-category/plugins" target="_blank" rel="noopener noreferrer">Посмотреть документацию</a>
			</div>
		</div>
		<div class="woodev-admin-block">
			<span class="woodev-admin-block__icon dashicons dashicons-sos" aria-hidden="true"></span>
			<div class="woodev-admin-block__content">
				<h4 class="woodev-admin-block__title">Поддержка пользователей</h4>
				<p class="woodev-admin-block__text">Возникли вопросы по настройке или использованию? Обратитесь в нашу службу поддержки.</p>
				<a class="woodev-admin-block__cta" href="https://woodev.ru/support" target="_blank" rel="noopener noreferrer">Получить поддержку</a>
			</div>
		</div>
		<div class="woodev-admin-block">
			<span class="woodev-admin-block__icon dashicons dashicons-bell" aria-hidden="true"></span>
			<div class="woodev-admin-block__content">
				<h4 class="woodev-admin-block__title">Телеграм-канал</h4>
				<p class="woodev-admin-block__text">Подпишитесь на наш Телеграм-канал — новости о плагинах и обновлениях, без спама.</p>
				<a class="woodev-admin-block__cta" href="https://t.me/wooplug" target="_blank" rel="noopener noreferrer">Присоединиться</a>
			</div>
		</div>
	</div>
</div>
```

- [ ] **Step 2: Replace the `.woodev-settings-documentation` SCSS**

In `src/license-page/style.scss`, replace the entire `.woodev-settings-documentation { … }` block (currently lines ~87-157) with the compact-card styles (icon-left, equal height, 4/2/1):

```scss
.woodev-settings-documentation {
	margin-top: 28px;

	.woodev-settings-row {
		display: grid;
		gap: 16px;
		grid-template-columns: 1fr;

		@media ( min-width: 600px ) {
			grid-template-columns: repeat( 2, 1fr );
		}

		@media ( min-width: 1280px ) {
			grid-template-columns: repeat( 4, 1fr );
		}
	}

	.woodev-admin-block {
		display: flex;
		align-items: flex-start;
		gap: 14px;
		height: 100%; // equal height across the row
		padding: 18px;
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 6px;
		box-sizing: border-box;

		&__icon {
			flex: 0 0 auto;
			width: 32px;
			height: 32px;
			font-size: 32px;
			color: #2271b1;
		}

		&__content {
			display: flex;
			flex: 1 1 auto;
			flex-direction: column;
		}

		&__title {
			margin: 0 0 6px;
			font-size: 14px;
			line-height: 1.4;
		}

		&__text {
			flex: 1 1 auto;
			margin: 0 0 12px;
			color: #50575e;
		}

		&__cta {
			align-self: flex-start;
			margin-top: auto;
			padding: 6px 14px;
			border: 1px solid #2271b1;
			border-radius: 3px;
			color: #2271b1;
			font-weight: 500;
			text-decoration: none;
			transition: background-color 0.15s ease, color 0.15s ease;

			&:hover,
			&:focus {
				background-color: #2271b1;
				color: #fff;
			}
		}
	}
}
```

- [ ] **Step 3: Build to verify SCSS compiles (full build in Task 8)**

Run: `npm run build`
Expected: success.

- [ ] **Step 4: Run phpcs on the changed view**

Run: `composer phpcs -- woodev/admin/pages/views/html-settings-section.php`
Expected: no errors (or auto-fix with `composer phpcbf -- woodev/admin/pages/views/html-settings-section.php`).

- [ ] **Step 5: Commit (source only)**

```bash
git add woodev/admin/pages/views/html-settings-section.php src/license-page/style.scss
git commit -m "feat(license-page): compact quick-link cards (icon-left, equal height, 4/2/1)"
```

---

## Task 7: Build the bundle & verify build parity

**Files:**
- Generated: `woodev/assets/build/**`

- [ ] **Step 1: Clean rebuild**

Run: `npm run build`
Expected: `woodev/assets/build/index.js`, `style-index.css`, and the asset PHP regenerate without errors.

- [ ] **Step 2: Confirm LF line endings on the build output (Windows parity trap)**

The `.gitattributes` pins `woodev/assets/build/** text eol=lf`. After `git add`, verify git normalized EOLs:

Run: `git add woodev/assets/build && git diff --cached --stat woodev/assets/build`
Expected: the build files appear staged; no CRLF warning blocks the add. (Gotcha: `build-artifacts-eol-lf-windows-parity` — CRLF here makes the "Assets build parity" CI job fail on identical content.)

- [ ] **Step 3: Commit src + build together**

```bash
git add src/license-page woodev/assets/build
git commit -m "build(license-page): rebuild bundle for the redesigned license page"
```

- [ ] **Step 4: Run the full gate once more**

Run: `composer check`
Expected: phpcs + phpstan + unit tests green.

---

## Task 8: Codex inline review (substantial JS/PHP change)

The redesign touches the React card, a new state machine, and a backend contract field — well above the review threshold. Use Codex as the critic with an INLINE bundle (the Windows sandbox kills shell-spawn/background runs — gotcha `codex-shell-sandbox-broken-windows`; verify the verdict actually returned — feedback `verify_background_agent_results`).

- [ ] **Step 1: Build the inline review bundle**

Assemble a single prompt containing: (a) the spec file, (b) the final diffs for `card-state.js`, `license-card.js`, `class-plugin-license.php` `get_state()`, `class-license-messages.php`, and (c) the reference `get_state()`/`Woodev_License` shape. Run `/codex:review` (or the inline `codex-companion.mjs review`) with that bundle.

- [ ] **Step 2: Triage findings WITH the operator**

Codex findings are presented verbatim and NEVER auto-fixed. Ask the operator which to fix (per the plugin contract + `feedback_proactive_codex`). Apply only approved fixes; re-run `composer check` + `npm run build`; re-critic your own in-place fixes (`feedback_recritic_own_fixes`).

- [ ] **Step 3: Commit any approved fixes**

```bash
git add -A
git commit -m "fix(license-page): address Codex review findings"
```

---

## Task 9: Browser-verify on the two-stack rig

Drive each state machine group in a real browser (the only verification for the harness-less JS). Stand `:8888` (login `admin`/`password`) already filters `woodev_license_base_url` → issuer `:8090`.

- [ ] **Step 1: Bring both stacks up**

Issuer: `wp-env` in `d:\projects\woodev_theme` (`http://localhost:8090`). Stand: framework `wp-env` (`http://localhost:8888`). Confirm both reachable.

- [ ] **Step 2: Exercise the groups**

Drive states by activating different issuer downloads / editing their expiry, then loading «Woodev → Лицензии» on the stand. Cover: A (no key) · B (valid, far expiry) · B′ (expiring <1mo) · C (expired) · D (`site_inactive` / `no_activations_left`) · E (invalid key) · F (revoked) · S0 (license-free fixture). Drive via `docker exec <cli> wp eval-file …` (NEVER inline `wp eval` — cyrillic/quoting breaks; NEVER `do_action('admin_init')` — WC OrderAttributionController fatals). For each: confirm badge label/color, accent bar, key field mode (editable vs masked-RO), 👁/Проверить enabled state, and the right action buttons. Verify «Продлить» points at `renewal_url`, the Бета tooltip shows, and the quick-link cards render compact + equal-height.

- [ ] **Step 3: Verify the server messages render in Russian**

Confirm the in-card `message` Notice (expired/inactive/limit/revoked) now reads Russian with working CTA links.

- [ ] **Step 4: Clean up rig artifacts**

Remove any temp eval-files, test users, and probe downloads created during verification.

---

## Task 10: PR & merge

- [ ] **Step 1: Push the branch & open the PR**

```bash
git push -u origin <branch>
gh pr create --title "feat(license-page): «Woodev → Лицензии» UI/UX redesign" --body "<summary + verification notes>"
```

- [ ] **Step 2: Merge only after confirmed-green CI**

Wait for GH Actions green (check `gh pr checks <N>` and `gh pr view <N> --json mergeable,mergeStateStatus`). Then:

```bash
gh pr merge <N> --squash --delete-branch
```

Never `--auto` (gotcha-confirmed desync in s11). If the PR is DIRTY/conflicting, rebase onto the new base first (no `pull_request` CI runs on a conflicting PR).

- [ ] **Step 3: Session-end docs**

Update `docs-internal/CURRENT-STATE.md` + `SESSION-LOG.md`; record any new gotcha discovered (e.g. JS date-TZ nuance, form-group styling traps); replace `docs-internal/next-session-prompt.md` with the s21 brief.

---

## Self-review notes (spec coverage)

- **Responsive license grid 3/2/1** → Task 5 (media queries).
- **Intro info-notice, wide** → Task 5 Step 1+2.
- **Compact quick-link cards 4/2/1, icon-left, equal height** → Task 6.
- **Left color accent bar per status** → Task 5 Step 2 (`--success/--warning/--error/--neutral`) driven by `getCardView().accent` (Task 3/4).
- **Single key form-group `[input][👁][Проверить]`, masked-RO when saved, editable in A/E** → Task 3 (`keyEditable`) + Task 4 + Task 5 SCSS.
- **«Проверить» re-validates stored key via /verify** → Task 4 (reuses `handleVerify`, which already posts `keyInput` == stored key in masked groups).
- **7-group state machine on real EDD tokens** → Task 3 (`getCardView`), browser-verified Task 9.
- **Beta pinned right, label «Бета» + tooltip, default off** → Task 4 footer + Task 5 SCSS.
- **Additive `renewal_url` in `get_state()` + test** → Task 1.
- **«Продлить» button → `renewal_url`** → Task 4.
- **RU-localize `Woodev_License_Messages`** → Task 2 (plus `get_license_status()`).
- **Build parity (src + build, LF)** → Task 7.
- **Codex critic + rig browser-verify + explicit merge** → Tasks 8/9/10.
- **No REST/cache/option/`activate`/`deactivate` changes** → respected (Task 1 is additive-only; no other backend touched).
</content>
</invoke>

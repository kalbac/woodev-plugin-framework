# Pickup Point Selection Mechanism — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn choosing a pickup point from an instantaneous, unconditional client action into
a server round-trip whose verdict the plugin's domain owns — with a spinner, a two-step CTA, a
refusal the customer can read, and a selection that is restored when the map is reopened.

**Architecture:** The framework owns the transport (a new `POST` REST route, the button state
machine, the card lock, close and checkout-refresh) and the plugin owns the verdict (a PHP
filter seeded with the framework's own `Constraint_Checker` result). Two flags — `close` and
`refresh_checkout` — take a per-plugin default from the JS config and may be overridden per
response, read with `??` so an explicit `false` survives. Issue #157 is folded in: the REST
nonce is refreshed through a WooCommerce checkout fragment, and a stale-nonce `403` becomes a
readable message instead of a silent empty result.

**Tech Stack:** PHP 7.4+ / WordPress ≥6.6 / WooCommerce ≥7.0 · PSR-4 `Woodev\Framework\*` ·
vanilla ES5-style browser JS (no build step for these files) · PHPUnit + Brain Monkey (unit),
WP test library (integration), Jest + jsdom (JS).

**Spec:** `docs-internal/specs/2026-08-06-sp5-pickup-selection-mechanism-design.md`
**Card:** #169 (folds in #157)

---

## File structure

| File | Responsibility | New? |
|---|---|---|
| `woodev/shipping-method/pickup/class-selection-result.php` | The selection verdict value object: shape, defaults, fail-closed sanitisation of the domain filter's return | **create** |
| `woodev/shipping-method/rest-api/class-pickup-controller.php` | `POST …/select` route, permission, context assembly, seam invocation | modify |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | `selection` config defaults, new i18n keys, the nonce fragment | modify |
| `woodev/shipping-method/assets/js/frontend/pickup-datasource.js` | `selectPoint()`, late-read nonce | modify |
| `woodev/shipping-method/assets/js/frontend/pickup-panels.js` | CTA state machine, card lock, remembered refusal, `is-selected` row | modify |
| `woodev/shipping-method/assets/js/frontend/pickup-mount.js` | New selection flow, the two new events, close/refresh ordering, staleness guard, restore-on-open | modify |
| `woodev/shipping-method/assets/css/frontend/pickup.css` | Button spinner, card lock overlay, selected-row highlight | modify |
| `tests/_fixtures/woodev-test-shipping-method/` | A domain seam the rig can actually exercise (accept / refuse / slow) | modify |

**Naming, deliberately mixed and not to be "fixed":** the REST response uses `snake_case`
(`refresh_checkout`) because it is a PHP/WP payload, while the JS config uses `camelCase`
(`refreshCheckout`) because that is what every existing key in `get_js_config()` uses
(`restRoot`, `pointIcons`, `searchNearestCount`). Both conventions are already established in
this file pair; unifying them would break one of them.

**Class-map, and NOT `includes()`:** `class-selection-result.php` is a new framework class, so
`bin/generate-class-map.php` MUST be re-run (Task 1, step 5) — a class missing from the map is a
fatal on every real vendored boot (gotcha `framework-classmap-autoload-vendored-boot`).

It must **not** be added to `Shipping_Plugin::includes()`. Verified 2026-08-06, after an earlier
draft of this plan claimed the opposite: NOTHING in the SP-5 pickup tree is required there —
`Constraint_Checker`, `Pickup_Handler`, `Point_Query` and `Address_Target` all resolve through the
runtime class-map autoloader, which `class-framework-resolver.php:139` registers before any plugin
class is parsed. Only four pre-SP-5 files (`Pickup_Point` and the warehouse trio) still carry a
`require_once`. One more path-named require would extend the maintenance tail that gotcha
`file-deletion-tail-includes-classmap-fixtures` exists to warn about, for no benefit.

---

## Task 1: `Selection_Result` value object

**Files:**
- Create: `woodev/shipping-method/pickup/class-selection-result.php`
- Create: `tests/unit/Shipping/Pickup/SelectionResultTest.php`
- Modify: `woodev/class-map.php` (regenerated, not hand-edited)

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Framework\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Selection_Result;
use Woodev\Framework\Tests\Unit\TestCase;

class SelectionResultTest extends TestCase {

	public function test_from_verdict_seeds_allowed_and_reason_and_leaves_flags_unspoken(): void {
		$result = Selection_Result::from_verdict( [ 'allowed' => false, 'reason' => 'Тяжело' ] );

		$this->assertSame(
			[
				'allowed'          => false,
				'reason'           => 'Тяжело',
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => null,
			],
			$result
		);
	}

	public function test_sanitize_keeps_a_well_formed_domain_answer(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered = [
			'allowed'          => false,
			'reason'           => 'Пункт временно не принимает заказы',
			'close'            => false,
			'refresh_checkout' => true,
			'point'            => [ 'id' => 'X-1' ],
		];

		$this->assertSame( $filtered, Selection_Result::sanitize( $filtered, $computed ) );
	}

	public function test_sanitize_preserves_an_explicit_false_rather_than_treating_it_as_absent(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered = $computed;
		$filtered['close'] = false;

		$this->assertFalse( Selection_Result::sanitize( $filtered, $computed )['close'] );
	}

	/**
	 * @dataProvider provide_junk_returns
	 */
	public function test_sanitize_falls_back_to_the_computed_result_for_junk( $junk ): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );

		$this->assertSame( $computed, Selection_Result::sanitize( $junk, $computed ) );
	}

	public function provide_junk_returns(): array {
		return [
			'not an array'      => [ 'yes' ],
			'null'              => [ null ],
			'missing allowed'   => [ [ 'reason' => null ] ],
			'allowed not bool'  => [ [ 'allowed' => 1, 'reason' => null ] ],
			'reason not string' => [ [ 'allowed' => true, 'reason' => [ 'x' ] ] ],
		];
	}

	public function test_sanitize_normalises_a_non_bool_flag_to_absent_without_discarding_the_rest(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered = $computed;
		$filtered['close']  = 'yes';
		$filtered['reason'] = 'Годится';

		$sanitized = Selection_Result::sanitize( $filtered, $computed );

		$this->assertNull( $sanitized['close'], 'a non-bool flag is "the domain said nothing"' );
		$this->assertSame( 'Годится', $sanitized['reason'], 'the rest of a usable answer survives' );
	}

	public function test_sanitize_drops_a_non_array_point(): void {
		$computed = Selection_Result::from_verdict( [ 'allowed' => true, 'reason' => null ] );
		$filtered = $computed;
		$filtered['point'] = 'X-1';

		$this->assertNull( Selection_Result::sanitize( $filtered, $computed )['point'] );
	}
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/SelectionResultTest.php`
Expected: FAIL — `Class "Woodev\Framework\Shipping\Pickup\Selection_Result" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Woodev Plugin Framework — pickup point selection result.
 *
 * @package Woodev\Framework\Shipping\Pickup
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Shipping\Pickup\Selection_Result' ) ) {

	/**
	 * The verdict returned when a customer confirms a pickup point.
	 *
	 * Deliberately an array shape rather than an object: it is a REST response body, it is
	 * handed straight to `apply_filters()`, and every consumer either reads it as JSON or as
	 * a plain array. An object here would buy nothing and cost every integrator a `use`.
	 *
	 * THE THREE-STATE FLAGS. `close` and `refresh_checkout` are `bool|null`, and `null` is
	 * load-bearing: it means "the domain did not speak", which is what tells the browser to
	 * fall back to the plugin's configured default. An explicit `false` is a decision and must
	 * survive — hence `sanitize()` never collapses `false` into `null`, and the browser reads
	 * these with `??`, never `||`.
	 *
	 * @since 2.0.2
	 */
	class Selection_Result {

		/**
		 * Seeds a result from the framework's own `Constraint_Checker` verdict, with both
		 * flags unspoken and no refreshed point.
		 *
		 * @since 2.0.2
		 *
		 * @param array{allowed: bool, reason: string|null} $verdict computed verdict.
		 *
		 * @return array{allowed: bool, reason: string|null, close: bool|null, refresh_checkout: bool|null, point: array|null}
		 */
		public static function from_verdict( array $verdict ): array {
			return [
				'allowed'          => (bool) ( $verdict['allowed'] ?? false ),
				'reason'           => isset( $verdict['reason'] ) && is_string( $verdict['reason'] )
					? $verdict['reason']
					: null,
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => null,
			];
		}

		/**
		 * Sanitises whatever the domain filter returned, fail-closed and silently — the same
		 * discipline {@see Constraint_Checker::sanitize_verdict()} already applies, for the
		 * same reason: an integrator's mistake must never be able to widen the framework's
		 * own verdict.
		 *
		 * Two tiers, deliberately different:
		 *
		 * - `allowed`/`reason` are the VERDICT. A malformed one discards the whole answer and
		 *   returns the computed result — a filter that cannot express a verdict correctly is
		 *   not trusted with the rest of it either.
		 * - `close`/`refresh_checkout`/`point` are ADVICE. A malformed one is normalised to
		 *   "not spoken" individually, leaving a usable verdict intact — a typo'd flag must
		 *   not throw away a legitimate refusal reason the customer needs to read.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $filtered what the filter returned.
		 * @param array $computed the framework's own result, used as the fallback.
		 *
		 * @return array{allowed: bool, reason: string|null, close: bool|null, refresh_checkout: bool|null, point: array|null}
		 */
		public static function sanitize( $filtered, array $computed ): array {
			if ( ! is_array( $filtered )
				|| ! array_key_exists( 'allowed', $filtered )
				|| ! is_bool( $filtered['allowed'] )
				|| ! array_key_exists( 'reason', $filtered )
				|| ! ( null === $filtered['reason'] || is_string( $filtered['reason'] ) )
			) {
				return $computed;
			}

			return [
				'allowed'          => $filtered['allowed'],
				'reason'           => $filtered['reason'],
				'close'            => self::sanitize_flag( $filtered['close'] ?? null ),
				'refresh_checkout' => self::sanitize_flag( $filtered['refresh_checkout'] ?? null ),
				'point'            => isset( $filtered['point'] ) && is_array( $filtered['point'] )
					? $filtered['point']
					: null,
			];
		}

		/**
		 * Normalises one three-state flag: a real bool survives (INCLUDING `false`), anything
		 * else becomes `null` — "the domain said nothing".
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value raw flag value.
		 *
		 * @return bool|null
		 */
		private static function sanitize_flag( $value ): ?bool {
			return is_bool( $value ) ? $value : null;
		}
	}
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/SelectionResultTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Regenerate the class map and run the completeness test**

```bash
php bin/generate-class-map.php
./vendor/bin/phpunit tests/unit/ClassMapCompletenessTest.php
```
Expected: PASS — `Selection_Result` present in `woodev/class-map.php`.

Do NOT add a `require_once` to `Shipping_Plugin::includes()` — see the File-structure note above
for why the SP-5 tree deliberately does not use it.

- [ ] **Step 6: Run the FULL unit suite**

Run: `composer test:unit`
Expected: PASS, no new failures — a targeted run can hide breakage elsewhere.

- [ ] **Step 7: Commit**

```bash
git add woodev/shipping-method/pickup/class-selection-result.php         woodev/class-map.php         tests/unit/Shipping/Pickup/SelectionResultTest.php
git commit -m "feat(pickup): add Selection_Result verdict shape with fail-closed sanitisation"
```

---

## Task 2: `POST …/select` route and its permission

**Files:**
- Modify: `woodev/shipping-method/rest-api/class-pickup-controller.php` (after the existing
  `points/(?P<id>…)` registration, ~line 274-296)
- Modify: `tests/unit/Shipping/RestApi/PickupControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_registers_a_post_select_route_for_the_plugin(): void {
	$routes = [];

	\Brain\Monkey\Functions\when( 'register_rest_route' )->alias(
		function ( $namespace, $route, $args ) use ( &$routes ) {
			$routes[ $namespace . $route ] = $args;
		}
	);

	$this->make_controller( 'demo' )->register_routes();

	$key = 'woodev/v1/shipping/pickup/demo/select';

	$this->assertArrayHasKey( $key, $routes );
	$this->assertSame( 'POST', $routes[ $key ][0]['methods'] );
	$this->assertIsCallable(
		$routes[ $key ][0]['permission_callback'],
		'the select route must NOT be __return_true — it writes to the session'
	);
}

public function test_select_permission_rejects_a_missing_or_wrong_nonce(): void {
	\Brain\Monkey\Functions\when( 'wp_verify_nonce' )->justReturn( false );

	$controller = $this->make_controller( 'demo' );
	$request    = new \WP_REST_Request( 'POST', '/woodev/v1/shipping/pickup/demo/select' );

	$result = $controller->check_select_permission( $request );

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'woodev_pickup_invalid_nonce', $result->get_error_code() );
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/RestApi/PickupControllerTest.php --filter select`
Expected: FAIL — the route key is absent, `check_select_permission()` undefined.

- [ ] **Step 3: Register the route**

Append inside `register_routes()`, after the existing `points/(?P<id>…)` block:

```php
register_rest_route(
	'woodev/v1',
	'/shipping/pickup/' . $plugin_segment . '/select',
	[
		[
			'methods' => 'POST',

			/*
			 * NOT `__return_true`, unlike the two GET reads above: this route writes to the
			 * WC session and the domain seam behind it may call the carrier's API, so an
			 * unguarded POST is also a way to burn the merchant's carrier quota through a
			 * visitor's browser. A capability check is impossible — guests place orders — so
			 * the nonce is the whole barrier.
			 *
			 * NOTE this callback does NOT see a stale `X-WP-Nonce`: WordPress's own
			 * `rest_cookie_check_errors()` rejects an INVALID nonce before any
			 * permission_callback runs (gotcha `rest-cookie-nonce-auth-semantics`), which is
			 * exactly why issue #157 reports a bare 403 from a `__return_true` route. This
			 * check therefore catches the OTHER cases — no nonce sent at all, or a nonce for
			 * the wrong action — and the stale case is handled in the browser (Task 6).
			 */
			'permission_callback' => [ $this, 'check_select_permission' ],
			'callback'            => [ $this, 'handle_select_request' ],
			'args'                => [
				'field_id'  => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'point_id'  => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => 'rest_validate_request_arg',
				],

				/*
				 * No `method_id` parameter. The chosen shipping method is already in the WC
				 * session — the server reads it there (see `handle_select_request()`), the
				 * same way it already reads the chosen payment method. Accepting it from the
				 * browser would be both redundant and less trustworthy: the domain would be
				 * handed a method id the customer's page asserted rather than the one the
				 * order will actually be placed with.
				 */
			],
		],
	]
);
```

- [ ] **Step 4: Add the permission callback**

```php
/**
 * Verifies the REST nonce for the select route.
 *
 * @since 2.0.2
 * @internal
 *
 * @param \WP_REST_Request $request the incoming request.
 *
 * @return true|\WP_Error
 */
public function check_select_permission( $request ) {
	$nonce = $request->get_header( 'x_wp_nonce' );

	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new \WP_Error(
			'woodev_pickup_invalid_nonce',
			__( 'Страница устарела. Обновите её и выберите пункт выдачи заново.', 'woodev-plugin-framework' ),
			[ 'status' => 403 ]
		);
	}

	return true;
}
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/RestApi/PickupControllerTest.php --filter select`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/rest-api/class-pickup-controller.php \
        tests/unit/Shipping/RestApi/PickupControllerTest.php
git commit -m "feat(pickup): register the POST select route behind a nonce check"
```

---

## Task 3: The select handler — context, framework verdict, domain seam

**Files:**
- Modify: `woodev/shipping-method/rest-api/class-pickup-controller.php`
- Modify: `tests/unit/Shipping/RestApi/PickupControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_select_returns_the_framework_verdict_when_no_filter_answers(): void {
	$controller = $this->make_controller( 'demo' );
	$request    = new \WP_REST_Request( 'POST', '/x' );
	$request->set_param( 'field_id', 'carrier_pickup_point' );
	$request->set_param( 'point_id', 'DEMO-PVZ-1' );

	$data = $controller->handle_select_request( $request )->get_data();

	$this->assertTrue( $data['allowed'] );
	$this->assertNull( $data['close'], 'unspoken, so the browser falls back to its config default' );
	$this->assertNull( $data['refresh_checkout'] );
}

public function test_select_lets_the_domain_refuse_and_speak_both_flags(): void {
	\Brain\Monkey\Filters\expectApplied( 'woodev_shipping_pickup_point_selection' )
		->once()
		->andReturn(
			[
				'allowed'          => false,
				'reason'           => 'Пункт не принимает такой вес',
				'close'            => false,
				'refresh_checkout' => false,
				'point'            => null,
			]
		);

	$controller = $this->make_controller( 'demo' );
	$request    = new \WP_REST_Request( 'POST', '/x' );
	$request->set_param( 'field_id', 'carrier_pickup_point' );
	$request->set_param( 'point_id', 'DEMO-PVZ-1' );

	$data = $controller->handle_select_request( $request )->get_data();

	$this->assertFalse( $data['allowed'] );
	$this->assertSame( 'Пункт не принимает такой вес', $data['reason'] );
	$this->assertFalse( $data['close'], 'an explicit false must survive, never collapse to null' );
	$this->assertFalse( $data['refresh_checkout'] );
}

public function test_select_404s_an_unknown_point(): void {
	$controller = $this->make_controller( 'demo' );
	$request    = new \WP_REST_Request( 'POST', '/x' );
	$request->set_param( 'field_id', 'carrier_pickup_point' );
	$request->set_param( 'point_id', 'NO-SUCH-POINT' );

	$result = $controller->handle_select_request( $request );

	$this->assertInstanceOf( \WP_Error::class, $result );
	$this->assertSame( 'woodev_pickup_point_not_found', $result->get_error_code() );
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/RestApi/PickupControllerTest.php --filter select`
Expected: FAIL — `handle_select_request()` undefined.

- [ ] **Step 3: Write the handler**

```php
/**
 * Handles a selection confirmation: resolves the point, computes the framework's own
 * verdict against the CURRENT cart, then hands the result to the domain.
 *
 * The framework's verdict is recomputed here rather than trusted from whatever the browser
 * last saw — the cart can change between drawing the map and confirming a point, and this
 * route is the last cheap moment to notice.
 *
 * @since 2.0.2
 * @internal
 *
 * @param \WP_REST_Request $request the incoming request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
public function handle_select_request( $request ) {
	$point_id = (string) $request->get_param( 'point_id' );
	$point    = $this->source->get_point( $point_id );

	if ( ! $point instanceof Pickup_Point ) {
		return new \WP_Error(
			'woodev_pickup_point_not_found',
			__( 'Пункт выдачи не найден.', 'woodev-plugin-framework' ),
			[ 'status' => 404 ]
		);
	}

	$payment_method = (string) $this->chosen_payment_method();
	$cart_weight    = (int) call_user_func( $this->cart_weight );

	$computed = Selection_Result::from_verdict(
		$this->constraint_checker->check( $point, $payment_method, $cart_weight )
	);

	$context = [
		'field_id'       => (string) $request->get_param( 'field_id' ),
		'method_id'      => (string) $this->chosen_shipping_method(),
		'payment_method' => $payment_method,
		'cart_weight'    => $cart_weight,
	];

	/**
	 * Filters the verdict for a pickup point the customer has just confirmed.
	 *
	 * This is the domain's moment: unlike `woodev_shipping_pickup_point_selectable`, which
	 * runs while DRAWING the list and must stay cheap, this runs once per confirmation and
	 * may call the carrier. It is also the only place a constraint the carrier alone knows
	 * can still be caught before the customer walks on — `Constraint_Checker` treats unknown
	 * constraint data as permissive precisely because a list response frequently omits it.
	 *
	 * A filter must return the same array shape it received. Malformed returns fail closed,
	 * silently — see {@see Selection_Result::sanitize()} for the two tiers.
	 *
	 * `close` and `refresh_checkout` are THREE-STATE. Leave a flag `null` to defer to the
	 * plugin's configured default; return an explicit `true`/`false` to decide this one
	 * selection. An explicit `false` is preserved, never treated as "unspoken".
	 *
	 * @since 2.0.2
	 *
	 * @param array{allowed: bool, reason: string|null, close: bool|null, refresh_checkout: bool|null, point: array|null} $result computed result.
	 * @param Pickup_Point                                                                                                $point   the confirmed point.
	 * @param array{field_id: string, method_id: string, payment_method: string, cart_weight: int} $context request context; `cart_weight` is in GRAMS.
	 */
	$filtered = apply_filters(
		'woodev_shipping_pickup_point_selection',
		$computed,
		$point,
		$context
	);

	return rest_ensure_response( Selection_Result::sanitize( $filtered, $computed ) );
}
```

Add the import beside the controller's existing ones:

```php
use Woodev\Framework\Shipping\Pickup\Selection_Result;
```

And add the session reader beside the existing `chosen_payment_method()`, mirroring it exactly:

```php
/**
 * The shipping method the customer currently has chosen, read from the WC session rather
 * than accepted from the browser — the domain must be told the method the order will
 * actually be placed with, not the one a page asserted.
 *
 * @since 2.0.2
 *
 * @return string
 */
private function chosen_shipping_method(): string {
	$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : null;

	return is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '';
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/RestApi/PickupControllerTest.php --filter select`
Expected: PASS.

- [ ] **Step 5: Run the WHOLE unit suite**

Adding a public method to a class that is `Mockery::mock()`-ed elsewhere makes every strict mock
throw `BadMethodCallException`, and a targeted run never sees it
(gotcha `mockery-mock-new-method-full-suite`).

Run: `composer test:unit`
Expected: PASS, no new failures.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/rest-api/class-pickup-controller.php \
        tests/unit/Shipping/RestApi/PickupControllerTest.php
git commit -m "feat(pickup): add the selection seam woodev_shipping_pickup_point_selection"
```

---

## Task 4: Config defaults and the new i18n keys

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php`
  (`get_js_config()` ~line 760, and the `$strings` array above it)
- Modify: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_js_config_carries_the_selection_defaults(): void {
	$config = $this->make_handler( [ 'close_on_select' => true, 'refresh_checkout' => false ] )
		->get_js_config();

	$this->assertSame(
		[
			'close'           => true,
			'refreshCheckout' => false,
		],
		$config['selection']
	);
}

public function test_js_config_defaults_to_the_two_step_flow_and_no_refresh(): void {
	$config = $this->make_handler()->get_js_config();

	$this->assertFalse( $config['selection']['close'], 'two-step is the framework default' );
	$this->assertFalse( $config['selection']['refreshCheckout'] );
}

public function test_js_config_carries_the_new_selection_strings(): void {
	$i18n = $this->make_handler()->get_js_config()['i18n'];

	foreach ( [ 'confirming', 'selectFailed', 'stalePage' ] as $key ) {
		$this->assertArrayHasKey( $key, $i18n );
		$this->assertNotSame( '', $i18n[ $key ] );
	}
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --filter selection`
Expected: FAIL — undefined index `selection`.

- [ ] **Step 3: Add the strings**

In the `$strings` array built just before the `woodev_pickup_map_i18n` filter:

```php
	// Selection confirmation (2026-08-06 spec §5.1). `continueCheckout` already exists above.
	'confirming'   => __( 'Проверяем…', 'woodev-plugin-framework' ),
	'selectFailed' => __( 'Не удалось подтвердить выбор. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
	'stalePage'    => __( 'Страница устарела. Обновите её и выберите пункт выдачи заново.', 'woodev-plugin-framework' ),
```

- [ ] **Step 4: Add the config block**

In the array `get_js_config()` returns, beside `replaceAddress`:

```php
	/*
	 * The plugin's DEFAULT answers to "close the modal now?" and "refresh the checkout?".
	 * The domain may override either per selection (Selection_Result's three-state flags);
	 * the browser reads `response.close ?? config.selection.close`, so an explicit `false`
	 * from the domain wins and an unspoken flag falls back to here.
	 *
	 * Both default to `false`: two-step confirmation is the framework's own behaviour, and a
	 * checkout refresh nobody asked for is wasted work on every selection (a carrier whose
	 * price cannot change within a locality — CDEK — must never pay for it).
	 */
	'selection' => [
		'close'           => (bool) $this->close_on_select,
		'refreshCheckout' => (bool) $this->refresh_checkout,
	],
```

Add the two constructor-injected properties beside the existing `$replace_address`, defaulting
to `false`, with the same docblock discipline the neighbouring properties use.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --filter selection`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/pickup/class-pickup-handler.php \
        tests/unit/Shipping/Pickup/PickupHandlerTest.php
git commit -m "feat(pickup): expose selection defaults and confirmation strings to JS"
```

---

## Task 5: #157a — refresh the nonce through a checkout fragment

> **This task is severable.** It reduces how often the customer meets Task 6's stale-page
> message; it does not replace it. Drop it and the feature still works — see the plan's
> covering note.

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php`
- Modify: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_nonce_fragment_is_keyed_by_the_node_it_replaces(): void {
	\Brain\Monkey\Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );

	$handler   = $this->make_handler();
	$fragments = $handler->inject_nonce_fragment( [] );
	$selector  = '#' . $handler->nonce_node_id();

	$this->assertArrayHasKey( $selector, $fragments );
	$this->assertStringContainsString( 'data-woodev-pickup-nonce="fresh-nonce"', $fragments[ $selector ] );
	$this->assertStringContainsString( 'id="' . $handler->nonce_node_id() . '"', $fragments[ $selector ] );
}

public function test_nonce_fragment_leaves_other_fragments_untouched(): void {
	\Brain\Monkey\Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );

	$fragments = $this->make_handler()->inject_nonce_fragment( [ '.other' => '<div></div>' ] );

	$this->assertSame( '<div></div>', $fragments['.other'] );
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --filter nonce`
Expected: FAIL — `inject_nonce_fragment()` undefined.

- [ ] **Step 3: Implement the node, the fragment and the hooks**

```php
/**
 * The id of the DOM node carrying a currently-valid REST nonce.
 *
 * @since 2.0.2
 *
 * @return string
 */
public function nonce_node_id(): string {
	return 'woodev-pickup-nonce-' . $this->config_object_suffix();
}

/**
 * Prints the nonce node once, so WooCommerce has something to replace later.
 *
 * `wp_localize_script()` prints the JS config ONCE per page load, outside the checkout
 * fragment `update_checkout` refreshes — so the nonce baked into it can never become fresh
 * again, no matter how late the browser reads it (issue #157's own proposed fix rested on
 * the opposite assumption and would not have worked). This node is the refresh channel.
 *
 * Printed in the footer rather than inside the form: WooCommerce applies fragments with a
 * document-wide selector match, so the node does not need to live in the order-review markup
 * — and keeping it out of the form avoids competing with §8's own anchor re-placement.
 *
 * @since 2.0.2
 * @internal
 *
 * @return void
 */
public function print_nonce_node(): void {
	printf(
		'<span id="%1$s" data-woodev-pickup-nonce="%2$s" hidden></span>',
		esc_attr( $this->nonce_node_id() ),
		esc_attr( wp_create_nonce( 'wp_rest' ) )
	);
}

/**
 * Replaces the nonce node on every `update_checkout`, handing the browser a nonce minted
 * in THIS request.
 *
 * Does not cover every case and is not meant to: a login or logout invalidates a nonce
 * immediately (the session token changes), and a page nobody touches never fires
 * `update_checkout` at all. Those land on the browser's stale-page message instead.
 *
 * @since 2.0.2
 * @internal
 *
 * @param array $fragments WooCommerce checkout fragments.
 *
 * @return array
 */
public function inject_nonce_fragment( array $fragments ): array {
	ob_start();
	$this->print_nonce_node();
	$fragments[ '#' . $this->nonce_node_id() ] = ob_get_clean();

	return $fragments;
}
```

Register both in the handler's existing hook wiring:

```php
add_action( 'wp_footer', [ $this, 'print_nonce_node' ] );
add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'inject_nonce_fragment' ] );
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --filter nonce`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/pickup/class-pickup-handler.php \
        tests/unit/Shipping/Pickup/PickupHandlerTest.php
git commit -m "fix(pickup): refresh the REST nonce through a checkout fragment (#157)"
```

---

## Task 6: #157b — read the nonce late, and name a stale one

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-datasource.js`
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (`ERROR_MESSAGE_KEYS`, ~line 271)
- Modify: `tests/js/pickup-datasource.test.js`, `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing tests**

```js
// tests/js/pickup-datasource.test.js
describe( 'nonce freshness', () => {
	it( 'reads the nonce at request time, not at construction', async () => {
		let current = 'stale';
		const ds = WoodevPickupDataSource( {
			restRoot: '/wp-json/woodev/v1/shipping/pickup/demo/points',
			nonce: () => current,
			debounceMs: 0,
		} );

		current = 'fresh';
		await ds.fetchPoints( { locality: 'Москва' } );

		expect( fetch.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'fresh' );
	} );

	it( 'still accepts a plain string nonce', async () => {
		const ds = WoodevPickupDataSource( { restRoot: '/x', nonce: 'plain', debounceMs: 0 } );

		await ds.fetchPoints( {} );

		expect( fetch.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'plain' );
	} );
} );
```

```js
// tests/js/pickup-mount.test.js
it( 'maps a stale-nonce 403 to the stale-page message, not the generic error', () => {
	const config = { i18n: { error: 'Ошибка', stalePage: 'Страница устарела' } };

	expect(
		window.__woodevPickupTestApi.errorMessageKey( config, {
			status: 403,
			code: 'rest_cookie_invalid_nonce',
		} )
	).toBe( 'stalePage' );
} );

it( 'maps our own nonce error code to the same message', () => {
	const config = { i18n: { error: 'Ошибка', stalePage: 'Страница устарела' } };

	expect(
		window.__woodevPickupTestApi.errorMessageKey( config, {
			status: 403,
			code: 'woodev_pickup_invalid_nonce',
		} )
	).toBe( 'stalePage' );
} );
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `npx jest tests/js/pickup-datasource.test.js tests/js/pickup-mount.test.js`
Expected: FAIL — the header carries `stale`; the key resolves to `error`.

- [ ] **Step 3: Make the datasource read the nonce late**

Replace the captured constant in `WoodevPickupDataSource`:

```js
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
```

and thread it through — `fetchPointsOnce( restRoot, readNonce, args )`, `fetchDetailsOnce(
restRoot, readNonce, pointId )`, with `request()` calling it:

```js
	function request( url, readNonce ) {
		return fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': readNonce() },
		} ).then(
```

- [ ] **Step 4: Have the mount supply the live reader**

Replace the datasource construction (`pickup-mount.js:884`):

```js
	var realDataSource = DataSourceFactory( {
		restRoot: config.restRoot,
		nonce: function() {
			return currentNonce( config );
		},
	} );
```

and add the reader beside the other small helpers:

```js
	/**
	 * The freshest REST nonce available: the node WooCommerce replaces on every
	 * `update_checkout` (see `Pickup_Handler::print_nonce_node()`), falling back to the one
	 * baked into the page-load config when that node is absent — a plugin on a non-checkout
	 * surface, or a theme that dropped `wp_footer`.
	 *
	 * @param {Object} config
	 * @returns {string}
	 */
	function currentNonce( config ) {
		var node = config.nonceNodeId ? document.getElementById( config.nonceNodeId ) : null;
		var live = node && node.dataset ? node.dataset.woodevPickupNonce : '';

		return live || String( config.nonce || '' );
	}
```

Emit `nonceNodeId` from `get_js_config()` alongside `nonce` (one line, same task).

- [ ] **Step 5: Map both nonce codes**

```js
	var ERROR_MESSAGE_KEYS = {
		woodev_pickup_upstream_error: 'upstreamError',
		woodev_pickup_rate_limited: 'rateLimited',
		woodev_pickup_point_not_found: 'notFound',

		/*
		 * Two codes, one message. `rest_cookie_invalid_nonce` is WordPress's own, raised by
		 * `rest_cookie_check_errors()` BEFORE any permission_callback runs — which is why a
		 * route declared `__return_true` can still 403 (#157). `woodev_pickup_invalid_nonce`
		 * is ours, from the select route's permission callback, for the cases WordPress lets
		 * through (no nonce header at all).
		 */
		rest_cookie_invalid_nonce: 'stalePage',
		woodev_pickup_invalid_nonce: 'stalePage',
	};
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-datasource.test.js tests/js/pickup-mount.test.js`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-datasource.js \
        woodev/shipping-method/assets/js/frontend/pickup-mount.js \
        woodev/shipping-method/pickup/class-pickup-handler.php \
        tests/js/pickup-datasource.test.js tests/js/pickup-mount.test.js
git commit -m "fix(pickup): read the nonce at request time and name a stale one (#157)"
```

---

## Task 7: `selectPoint()` on the datasource

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-datasource.js`
- Modify: `tests/js/pickup-datasource.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'selectPoint', () => {
	it( 'POSTs to the select route beside the points root, with the live nonce', async () => {
		const ds = WoodevPickupDataSource( {
			restRoot: '/wp-json/woodev/v1/shipping/pickup/demo/points',
			nonce: () => 'fresh',
		} );

		await ds.selectPoint( { pointId: 'DEMO-PVZ-1', fieldId: 'carrier_pickup_point' } );

		const [ url, init ] = fetch.mock.calls[ 0 ];

		expect( url ).toBe( '/wp-json/woodev/v1/shipping/pickup/demo/select' );
		expect( init.method ).toBe( 'POST' );
		expect( init.headers[ 'X-WP-Nonce' ] ).toBe( 'fresh' );
		expect( JSON.parse( init.body ) ).toEqual( {
			point_id: 'DEMO-PVZ-1',
			field_id: 'carrier_pickup_point',
		} );
	} );

	it( 'rejects with the shared error shape on a 403', async () => {
		fetch.mockResolvedValueOnce( {
			ok: false,
			status: 403,
			json: () => Promise.resolve( { code: 'rest_cookie_invalid_nonce', message: 'bad' } ),
		} );

		const ds = WoodevPickupDataSource( { restRoot: '/a/points', nonce: 'n' } );

		await expect(
			ds.selectPoint( { pointId: 'X', fieldId: 'f' } )
		).rejects.toMatchObject( { status: 403, code: 'rest_cookie_invalid_nonce' } );
	} );

	it( 'is never debounced — a confirmation is one deliberate act', async () => {
		const ds = WoodevPickupDataSource( { restRoot: '/a/points', nonce: 'n', debounceMs: 5000 } );

		await ds.selectPoint( { pointId: 'X', fieldId: 'f' } );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx jest tests/js/pickup-datasource.test.js -t selectPoint`
Expected: FAIL — `ds.selectPoint is not a function`.

- [ ] **Step 3: Implement**

```js
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
```

Extract the existing response/error handling out of `request()` into a shared
`requestJson( url, init )` (identical body — the `response.ok` check, the JSON-parse fallback,
the network-failure branch) and have `request()` call it, so both verbs share one error shape.
Return `selectPoint` from the factory alongside `fetchPoints` and `fetchDetails`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-datasource.test.js`
Expected: PASS, including the pre-existing cases.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-datasource.js tests/js/pickup-datasource.test.js
git commit -m "feat(pickup): add dataSource.selectPoint() for confirmation"
```

---

## Task 8: CTA busy state and the card lock

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js` (`buildCardFooter`, ~line 1133)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Modify: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'setSelectionBusy', () => {
	it( 'disables the CTA, swaps its label and locks the card', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь', confirming: 'Проверяем…' } } );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ) ] ) ] );
		panels.openCard( group( 'g1', [ point( 'P1' ) ] ), 'P1', 'list' );

		panels.setSelectionBusy( true );

		const cta = document.querySelector( '.woodev-pickup-card__cta' );

		expect( cta.disabled ).toBe( true );
		expect( cta.textContent ).toBe( 'Проверяем…' );
		expect( document.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-locked' ) ).toBe( true );
	} );

	it( 'restores the CTA when the request settles', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь', confirming: 'Проверяем…' } } );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ) ] ) ] );
		panels.openCard( group( 'g1', [ point( 'P1' ) ] ), 'P1', 'list' );

		panels.setSelectionBusy( true );
		panels.setSelectionBusy( false );

		const cta = document.querySelector( '.woodev-pickup-card__cta' );

		expect( cta.disabled ).toBe( false );
		expect( cta.textContent ).toBe( 'Забрать здесь' );
		expect( document.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-locked' ) ).toBe( false );
	} );

	it( 'does not emit select while busy, even if something clicks the CTA', () => {
		const onSelect = jest.fn();
		const panels = mountPanels( { i18n: { select: 'Забрать здесь', confirming: 'Проверяем…' } } );
		panels.on( 'select', onSelect );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ) ] ) ] );
		panels.openCard( group( 'g1', [ point( 'P1' ) ] ), 'P1', 'list' );

		panels.setSelectionBusy( true );
		document.querySelector( '.woodev-pickup-card__cta' ).click();

		expect( onSelect ).not.toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx jest tests/js/pickup-panels.test.js -t setSelectionBusy`
Expected: FAIL — `panels.setSelectionBusy is not a function`.

- [ ] **Step 3: Implement the state on the instance**

In the constructor, beside `this._selectedId = null`:

```js
		/**
		 * @type {boolean} true while a selection confirmation is in flight — see
		 * {@see Panels.prototype.setSelectionBusy}.
		 */
		this._selectionBusy = false;
```

In `buildCardFooter()`, replace the CTA block:

```js
		var cta = document.createElement( 'button' );
		cta.type = 'button';
		cta.className = 'woodev-pickup-card__cta' + ( self._selectionBusy ? ' is-busy' : '' );
		cta.textContent = self._selectionBusy
			? text( self._config, 'confirming' )
			: ( isSelected ? text( self._config, 'continueCheckout' ) : text( self._config, 'select' ) );
		cta.disabled = ! selectable.allowed || self._selectionBusy;
		cta.addEventListener( 'click', function() {
			/*
			 * Two guards, not one, exactly as the pre-existing `selectable.allowed` guard is
			 * doubled by the `disabled` attribute: `disabled` is presentation, the refusal
			 * here is behaviour, and a programmatic `.click()` respects only the second.
			 */
			if ( ! selectable.allowed || self._selectionBusy ) {
				return;
			}

			self._emit( 'select', point );
		} );
```

Add the public method:

```js
	/**
	 * Marks a selection confirmation as in flight (or settled).
	 *
	 * Locking the card is not merely "do not click twice": it is what makes a SERVER-side
	 * ordering inversion impossible. Without it a customer can confirm point A, switch to B
	 * and confirm that too, B can reach the server first, and the server ends holding A while
	 * the browser shows B. A second request cannot leave while this is true.
	 *
	 * The stage's own `is-busy` is deliberately NOT reused: it is the "no data exists yet"
	 * state and hides the search and filter controls entirely (see `pickup.css`), which would
	 * make the search bar vanish under a customer who is merely confirming a point.
	 *
	 * @since 2.0.2
	 *
	 * @param {boolean} busy
	 * @returns {void}
	 */
	Panels.prototype.setSelectionBusy = function( busy ) {
		this._selectionBusy = !! busy;
		this._cardEl.classList.toggle( 'is-locked', this._selectionBusy );

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};
```

- [ ] **Step 4: Add the CSS**

Append to `pickup.css`, beside the existing `.woodev-pickup-card__cta:disabled` rule:

```css
/* The confirmation lock (2026-08-06 spec D-9). A transparent sheet over the card only — NOT
   the stage's own `is-busy`, which is the "nothing has loaded yet" state and hides the search
   and filter controls outright. Clicks on the map and the sidebar still land; what this stops
   is a second confirmation leaving before the first has settled, which is what would let two
   writes reach the server out of order. */
.woodev-pickup-card.is-locked::after {
	position: absolute;
	inset: 0;
	z-index: 1;
	content: '';
}

/* The spinner rides INSIDE the button rather than replacing its label: the label is what says
   which of the two confirmations is running, and a button that changes size mid-request makes
   the whole footer jump. Same 0.8s linear rotation as `.woodev-pickup-spinner`, at text size,
   in the button's own colour. */
.woodev-pickup-card__cta.is-busy::before {
	display: inline-block;
	width: 14px;
	height: 14px;
	margin-right: 8px;
	border: 2px solid currentColor;
	border-top-color: transparent;
	border-radius: 50%;
	animation: woodev-pickup-spin 0.8s linear infinite;
	content: '';
	vertical-align: -2px;
}

@media ( prefers-reduced-motion: reduce ) {

	.woodev-pickup-card__cta.is-busy::before {
		animation-duration: 3s;
	}
}
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-panels.test.js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js \
        woodev/shipping-method/assets/css/frontend/pickup.css \
        tests/js/pickup-panels.test.js
git commit -m "feat(pickup): CTA busy state and card lock during confirmation"
```

---

## Task 9: Remember a domain refusal, forget a transport failure

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js`
- Modify: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'setPointVerdict', () => {
	it( 'writes the refusal into the held point so it survives a re-render', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь', blocked: 'Нельзя' } } );
		const g = group( 'g1', [ point( 'P1' ) ] );
		panels.setPoints( [ g ] );
		panels.openCard( g, 'P1', 'list' );

		panels.setPointVerdict( 'P1', { allowed: false, reason: 'Слишком тяжело' } );
		panels.openCard( g, 'P1', 'list' ); // full re-render, as a second click would do

		expect( document.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( 'Слишком тяжело' );
		expect( document.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( true );
	} );

	it( 'leaves other points in the same group alone', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь' } } );
		const g = group( 'g1', [ point( 'P1' ), point( 'P2' ) ] );
		panels.setPoints( [ g ] );

		panels.setPointVerdict( 'P1', { allowed: false, reason: 'Нет' } );
		panels.openCard( g, 'P2', 'list' );

		expect( document.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( false );
	} );
} );

describe( 'showSelectionError', () => {
	it( 'shows a transient message without disabling the CTA', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь' } } );
		const g = group( 'g1', [ point( 'P1' ) ] );
		panels.setPoints( [ g ] );
		panels.openCard( g, 'P1', 'list' );

		panels.showSelectionError( 'Не удалось. Попробуйте ещё раз.' );

		expect( document.querySelector( '.woodev-pickup-card__warning' ).textContent )
			.toBe( 'Не удалось. Попробуйте ещё раз.' );
		expect( document.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( false );
	} );

	it( 'clears on the next card render — a failure is not a verdict', () => {
		const panels = mountPanels( { i18n: { select: 'Забрать здесь' } } );
		const g = group( 'g1', [ point( 'P1' ) ] );
		panels.setPoints( [ g ] );
		panels.openCard( g, 'P1', 'list' );

		panels.showSelectionError( 'Не удалось' );
		panels.openCard( g, 'P1', 'list' );

		expect( document.querySelector( '.woodev-pickup-card__warning' ) ).toBeNull();
	} );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx jest tests/js/pickup-panels.test.js -t "setPointVerdict|showSelectionError"`
Expected: FAIL — both methods undefined.

- [ ] **Step 3: Implement**

```js
	/**
	 * Records a domain verdict against one point, so a refusal SURVIVES.
	 *
	 * The framework's fetch-time `selectable` verdict is permissive about data a carrier's
	 * list response omits (see `Constraint_Checker`); a confirmation is where the real answer
	 * arrives. Writing it into the held point means `buildCardFooter()` — which already draws
	 * a warning and a dead CTA for `selectable.allowed === false` — does the rest on its own,
	 * on this render and every later one. No new rendering path exists for a refusal.
	 *
	 * Deliberately NOT reflected in the sidebar row: the list has no notion of a blocked point
	 * and giving it one is new UI surface with new states (spec D-8).
	 *
	 * @since 2.0.2
	 *
	 * @param {string|number}                        pointId
	 * @param {{allowed: boolean, reason: ?string}}  verdict
	 * @returns {void}
	 */
	Panels.prototype.setPointVerdict = function( pointId, verdict ) {
		var id = String( pointId );

		( this._groups || [] ).forEach( function( group ) {
			( group.points || [] ).forEach( function( point ) {
				if ( String( point.id ) === id ) {
					point.selectable = {
						allowed: !! verdict.allowed,
						reason: 'string' === typeof verdict.reason ? verdict.reason : null,
					};
				}
			} );
		} );

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};

	/**
	 * Shows a TRANSIENT selection failure — a dropped request, a timeout, a stale page.
	 *
	 * Deliberately not stored anywhere: nothing about the point was refused, so the next
	 * render must forget this entirely and leave the CTA alive. Conflating it with a verdict
	 * would grey out a perfectly good point because one request was dropped.
	 *
	 * @since 2.0.2
	 *
	 * @param {string} message already-resolved text (the caller owns i18n lookup).
	 * @returns {void}
	 */
	Panels.prototype.showSelectionError = function( message ) {
		var footer = this._cardEl.querySelector( '.woodev-pickup-card__footer' );

		if ( ! footer ) {
			return;
		}

		var existing = footer.querySelector( '.woodev-pickup-card__warning' );

		if ( existing ) {
			existing.textContent = message;

			return;
		}

		var warning = document.createElement( 'div' );
		warning.className = 'woodev-pickup-card__warning';
		warning.textContent = message;
		footer.insertBefore( warning, footer.firstChild );
	};
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-panels.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js tests/js/pickup-panels.test.js
git commit -m "feat(pickup): remember a domain refusal, forget a transport failure"
```

---

## Task 10: `is-selected` row highlight

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-panels.js` (`buildListItem`, ~line 644)
- Modify: `woodev/shipping-method/assets/css/frontend/pickup.css`
- Modify: `tests/js/pickup-panels.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'selected row highlight', () => {
	it( 'marks the selected point row', () => {
		const panels = mountPanels( {} );
		panels.setSelectedId( 'P2' );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ) ] ), group( 'g2', [ point( 'P2' ) ] ) ] );

		const rows = document.querySelectorAll( '.woodev-pickup-list__item' );

		expect( rows[ 0 ].classList.contains( 'is-selected' ) ).toBe( false );
		expect( rows[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'marks only the selected point inside a co-located group', () => {
		const panels = mountPanels( {} );
		panels.setSelectedId( 'P2' );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ), point( 'P2' ) ] ) ] );

		const buttons = document.querySelectorAll( '.woodev-pickup-list__point' );

		expect( buttons[ 0 ].classList.contains( 'is-selected' ) ).toBe( false );
		expect( buttons[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'moves the highlight when the selection changes', () => {
		const panels = mountPanels( {} );
		panels.setPoints( [ group( 'g1', [ point( 'P1' ) ] ), group( 'g2', [ point( 'P2' ) ] ) ] );

		panels.setSelectedId( 'P1' );

		expect( document.querySelectorAll( '.woodev-pickup-list__item.is-selected' ) ).toHaveLength( 1 );
		expect( document.querySelector( '.woodev-pickup-list__item.is-selected' ).dataset.groupKey )
			.toBe( 'g1' );
	} );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx jest tests/js/pickup-panels.test.js -t "selected row"`
Expected: FAIL — no `is-selected` class anywhere.

- [ ] **Step 3: Mark the rows**

In `buildListItem()`, after `item.dataset.groupKey = group.key;`:

```js
		/*
		 * "Selected" lives HERE and in the CTA's label — never as a third marker state on the
		 * map. The plugin-facing icon contract is exactly two images per type
		 * (`pointIcons: { typeCode: { default, active } }`); a third would oblige every plugin
		 * to draw one for every point type, a breaking change to an outward contract for a
		 * nuance this row already carries permanently. On the map, `active` means FOCUSED.
		 */
		var selectedId = self._selectedId;
```

For the single-point branch, mark the item itself; for the co-located branch, mark the
per-point button:

```js
		if ( points.length > 1 ) {
			points.forEach( function( point ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'woodev-pickup-list__point'
					+ ( null !== selectedId && String( point.id ) === selectedId ? ' is-selected' : '' );
				// … unchanged remainder …
			} );

			return item;
		}
```

```js
		// single-point branch, where the ITEM is the row:
		if ( null !== selectedId && points.length === 1 && String( points[ 0 ].id ) === selectedId ) {
			item.classList.add( 'is-selected' );
		}
```

- [ ] **Step 4: Re-render the list when the selection changes**

`setSelectedId()` currently re-renders only the card. Extend it:

```js
	Panels.prototype.setSelectedId = function( id ) {
		this._selectedId = ( undefined !== id && null !== id ) ? String( id ) : null;

		// The list carries the highlight (Task 10), so it must be rebuilt too — not only the
		// card, which is all this method used to touch when `_selectedId` affected nothing
		// but the CTA's label.
		renderList( this );

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};
```

- [ ] **Step 5: Add the CSS**

```css
/* The selected point's row. A left accent bar in the merchant's own colour plus a tinted
   background — deliberately quieter than the card's own selection affordances, since this must
   read as "this is the one you chose" at a glance while scrolling, not compete with hover.
   `currentColor` is not used: the row's text colour is content, this is chrome. */
.woodev-pickup-list__item.is-selected,
.woodev-pickup-list__point.is-selected {
	background: rgba( 6, 174, 221, 0.06 );
	box-shadow: inset 3px 0 0 var( --woodev-pickup-accent, #06aedd );
}
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-panels.test.js`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-panels.js \
        woodev/shipping-method/assets/css/frontend/pickup.css \
        tests/js/pickup-panels.test.js
git commit -m "feat(pickup): highlight the selected point in the sidebar list"
```

---

## Task 11: The new selection flow in the mount

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`
  (`handleSelection`, ~line 1152; `EVENT_*` constants, ~line 248)
- Modify: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing tests**

```js
describe( 'selection confirmation', () => {
	it( 'fires the requested event, locks the card and posts the point', async () => {
		const { panels, dataSource, emitSelect } = openPicker( { selection: { close: false } } );
		const seen = [];
		document.body.addEventListener( 'woodev_pickup_point_select_requested', ( e ) => seen.push( e.detail ) );

		emitSelect( { id: 'P1' } );

		expect( panels.setSelectionBusy ).toHaveBeenCalledWith( true );
		expect( seen[ 0 ].point.id ).toBe( 'P1' );
		expect( dataSource.selectPoint ).toHaveBeenCalledWith(
			expect.objectContaining( { pointId: 'P1' } )
		);
	} );

	it( 'writes the field and shows continueCheckout when close is false', async () => {
		const { emitSelect, resolveSelect, modal, field } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( 'P1' );
		expect( modal.close ).not.toHaveBeenCalled();
	} );

	it( 'closes immediately when the domain says so, overriding a false config default', async () => {
		const { emitSelect, resolveSelect, modal } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: true, refresh_checkout: null } );

		expect( modal.close ).toHaveBeenCalledWith( 'select' );
	} );

	it( 'honours an explicit false over a true config default — ?? not ||', async () => {
		const { emitSelect, resolveSelect, modal } = openPicker( { selection: { close: true } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: false, refresh_checkout: null } );

		expect( modal.close ).not.toHaveBeenCalled();
	} );

	it( 'records a refusal on the point and does not write the field', async () => {
		const { emitSelect, resolveSelect, panels, field } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: false, reason: 'Тяжело', close: null, refresh_checkout: null } );

		expect( panels.setPointVerdict ).toHaveBeenCalledWith( 'P1', { allowed: false, reason: 'Тяжело' } );
		expect( field.value ).toBe( '' );
	} );

	it( 'shows a transient error on a transport failure and keeps the point usable', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {
			i18n: { selectFailed: 'Не удалось', stalePage: 'Устарела' },
		} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 500, code: 'woodev_pickup_upstream_error' } );

		expect( panels.showSelectionError ).toHaveBeenCalledWith( 'Не удалось' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
	} );

	it( 'names a stale page instead of the generic failure', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {
			i18n: { selectFailed: 'Не удалось', stalePage: 'Устарела' },
		} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 403, code: 'rest_cookie_invalid_nonce' } );

		expect( panels.showSelectionError ).toHaveBeenCalledWith( 'Устарела' );
	} );

	it( 'discards an answer for a point the card no longer shows', async () => {
		const { emitSelect, resolveSelect, panels, field, setActivePoint } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		setActivePoint( 'P2' );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( '' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
	} );

	it( 'always clears the busy state, on every outcome', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 500, code: 'woodev_pickup_upstream_error' } );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'triggers update_checkout only when asked', async () => {
		const { emitSelect, resolveSelect, jq } = openPicker( { selection: { refreshCheckout: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: true } );

		expect( jq.triggered ).toContain( 'update_checkout' );
	} );

	it( 'does not trigger update_checkout when nobody asked', async () => {
		const { emitSelect, resolveSelect, jq } = openPicker( { selection: { refreshCheckout: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( jq.triggered ).not.toContain( 'update_checkout' );
	} );

	it( 'a second click on continueCheckout closes without a second request', async () => {
		const { emitSelect, resolveSelect, dataSource, modal } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		emitSelect( { id: 'P1' } ); // the CTA now reads continueCheckout

		expect( dataSource.selectPoint ).toHaveBeenCalledTimes( 1 );
		expect( modal.close ).toHaveBeenCalledWith( 'select' );
	} );
} );
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `npx jest tests/js/pickup-mount.test.js -t "selection confirmation"`
Expected: FAIL across the block — `handleSelection` still closes synchronously.

- [ ] **Step 3: Add the two event names**

```js
	var EVENT_POINT_SELECTED = 'woodev_pickup_point_selected';

	/*
	 * The two confirmation events (2026-08-06 spec D-2). OBSERVATIONAL: the framework neither
	 * waits for a listener nor lets one veto — the veto path is `woodev_modal_before_close`,
	 * which already exists. `_resolved` is what a plugin listens to in order to write the
	 * chosen point's street/house/postcode into the checkout address fields when the merchant
	 * enabled that (spec D-14) — the framework never does it and offers no switch for it.
	 */
	var EVENT_SELECT_REQUESTED = 'woodev_pickup_point_select_requested';
	var EVENT_SELECT_RESOLVED  = 'woodev_pickup_point_select_resolved';
```

- [ ] **Step 4: Replace `handleSelection`**

```js
	/**
	 * Reads a three-state flag: the domain's answer when it gave one, the plugin's configured
	 * default when it did not.
	 *
	 * `??`, NEVER `||` — an explicit `false` from the domain is a decision, and `||` would
	 * silently convert it into a `true` default. Same trap fixed in s40 (fail-closed parity).
	 *
	 * @param {*}       spoken   the response's own value: bool, null, or undefined.
	 * @param {boolean} fallback the plugin's configured default.
	 * @returns {boolean}
	 */
	function resolveFlag( spoken, fallback ) {
		return ( spoken ?? fallback ) === true;
	}

	/**
	 * The i18n key for a failed CONFIRMATION — the stale-page message when the failure was a
	 * nonce one, the confirmation-specific failure otherwise.
	 *
	 * Deliberately not {@see errorMessageKey}: that one falls back to the generic `error`
	 * string, which is written for a failed points FETCH ("не удалось загрузить пункты") and
	 * would be actively misleading under a button the customer just pressed to confirm one.
	 *
	 * @param {Object}      config
	 * @param {Object|null} reason `{ status, code, message }`.
	 * @returns {string}
	 */
	function selectionErrorKey( config, reason ) {
		return 'stalePage' === errorMessageKey( config, reason ) ? 'stalePage' : 'selectFailed';
	}

	/**
	 * Confirms a selection with the server, then applies whatever the domain decided.
	 *
	 * Under `ownsChrome` (an embedded provider reports its own selection and there is no card
	 * of ours to lock or re-render) the round trip still happens, but every panels call below
	 * is skipped — see the guards.
	 *
	 * @param {Object} point
	 * @returns {void}
	 */
	function handleSelection( point ) {
		var pointId = String( point && point.id );

		/*
		 * Already confirmed: this is the «Продолжить оформление» click, not a new choice.
		 * Nothing is asked again — the point is accepted, the field is written, and the only
		 * thing left is to close (spec D-11).
		 */
		if ( fieldValue( config.fieldId ) === pointId ) {
			if ( modal.close( 'select' ) ) {
				closeSession( config.fieldId );
			}

			return;
		}

		fireDocumentEvent( EVENT_SELECT_REQUESTED, { fieldId: config.fieldId, point: point } );

		if ( panels ) {
			panels.setSelectionBusy( true );
		}

		pendingSelectionId = pointId;

		dataSource.selectPoint( {
			pointId: pointId,
			fieldId: config.fieldId,
		} ).then(
			function( result ) {
				finishSelection( point, result, null );
			},
			function( reason ) {
				finishSelection( point, null, reason );
			}
		);
	}

	/**
	 * Applies one settled confirmation — success or failure, both land here so the busy state
	 * is cleared in exactly one place.
	 *
	 * THE STALENESS GUARD. An answer is applied only while the card still shows the point it
	 * was asked about. Locking the card stops a customer from starting a second confirmation,
	 * but it cannot stop the paths that are not clicks inside it: `updated_checkout` re-places
	 * §8's anchor while a session is open (see the file docblock), Escape, the backdrop and
	 * the close button all move on regardless.
	 *
	 * @param {Object}      point
	 * @param {Object|null} result the server's verdict, or null when the request failed.
	 * @param {Object|null} reason the transport failure, or null on success.
	 * @returns {void}
	 */
	function finishSelection( point, result, reason ) {
		var pointId = String( point && point.id );

		if ( pendingSelectionId !== pointId ) {
			return;
		}

		pendingSelectionId = null;

		if ( panels ) {
			panels.setSelectionBusy( false );
		}

		fireDocumentEvent( EVENT_SELECT_RESOLVED, {
			fieldId: config.fieldId,
			point: point,
			result: result,
			error: reason,
		} );

		if ( ! result ) {
			// Transport failure: nothing about the point was refused, so nothing is remembered
			// and the CTA stays alive.
			if ( panels ) {
				panels.showSelectionError( text( config, selectionErrorKey( config, reason ) ) );
			}

			return;
		}

		if ( ! result.allowed ) {
			if ( panels ) {
				panels.setPointVerdict( pointId, {
					allowed: false,
					reason: 'string' === typeof result.reason ? result.reason : null,
				} );
			}

			return;
		}

		var accepted = result.point && 'object' === typeof result.point ? result.point : point;
		var defaults = config.selection || {};

		applySelection( config, accepted );
		syncTriggerLabel( config );

		if ( panels ) {
			panels.setSelectedId( pointId );
		}

		fireDocumentEvent( EVENT_POINT_SELECTED, { fieldId: config.fieldId, point: accepted } );

		// Close BEFORE refresh: the customer gets immediate feedback and the recalculation
		// runs behind a closed modal. When we stay open, the button is held busy until the
		// refresh settles instead — otherwise «Продолжить оформление» is clickable in the
		// middle of a totals update.
		if ( resolveFlag( result.close, defaults.close ) ) {
			if ( modal.close( 'select' ) ) {
				closeSession( config.fieldId );
			}
		}

		if ( resolveFlag( result.refresh_checkout, defaults.refreshCheckout ) ) {
			refreshCheckout( panels );
		}
	}

	/**
	 * Fires WooCommerce's `update_checkout` and holds the card's busy state until the ajax
	 * settles. A no-op without jQuery (WooCommerce's own event is jQuery-only — see the file
	 * docblock's note on the identical asymmetry for `updated_checkout`).
	 *
	 * @param {Object|null} panels
	 * @returns {void}
	 */
	function refreshCheckout( panels ) {
		if ( ! window.jQuery ) {
			return;
		}

		if ( panels ) {
			panels.setSelectionBusy( true );
		}

		window.jQuery( document.body ).one( 'updated_checkout', function() {
			if ( panels ) {
				panels.setSelectionBusy( false );
			}
		} );

		window.jQuery( document.body ).trigger( 'update_checkout' );
	}
```

Declare `pendingSelectionId` beside the session's other closed-over state
(`provider`, `groupsByKey`, `lastBbox` …):

```js
		/** @type {string|null} the point id a confirmation is currently in flight for. */
		var pendingSelectionId = null;
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-mount.test.js`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "feat(pickup): confirm a selection with the server before accepting it"
```

---

## Task 12: Restore the selection when the map reopens

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` (the `alreadySelected`
  block, ~line 1189, and the points-drawn continuation, ~line 1104)
- Modify: `tests/js/pickup-mount.test.js`

- [ ] **Step 1: Write the failing test**

```js
describe( 'restoring a previous selection', () => {
	it( 'focuses the point, opens the sidebar and marks it selected', async () => {
		const { panels, provider, drawPoints, field } = openPicker( {} );
		field.value = 'P2';

		await drawPoints( [ group( 'g1', [ point( 'P1' ) ] ), group( 'g2', [ point( 'P2' ) ] ) ] );

		expect( panels.setSelectedId ).toHaveBeenCalledWith( 'P2' );
		expect( panels.openList ).toHaveBeenCalled();
		expect( provider.focusGroup ).toHaveBeenCalledWith( 'g2', { zoom: true } );
	} );

	it( 'opens normally and silently when the selected point is gone', async () => {
		const { panels, provider, drawPoints, field } = openPicker( {} );
		field.value = 'GONE';

		await drawPoints( [ group( 'g1', [ point( 'P1' ) ] ) ] );

		expect( provider.focusGroup ).not.toHaveBeenCalled();
		expect( panels.showMessage ).not.toHaveBeenCalled();
		expect( field.value ).toBe( 'GONE', 'a stale field is left for the checkout backstop' );
	} );

	it( 'does nothing at all when no point was ever selected', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = '';

		await drawPoints( [ group( 'g1', [ point( 'P1' ) ] ) ] );

		expect( provider.focusGroup ).not.toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx jest tests/js/pickup-mount.test.js -t "restoring a previous"`
Expected: FAIL — nothing focuses or opens the list.

- [ ] **Step 3: Implement**

Add beside the other session helpers:

```js
	/**
	 * Restores a previously chosen point once points have actually been drawn.
	 *
	 * Runs HERE and not at session open because it needs the drawn groups: the camera move
	 * and the group key only exist once `setPoints()` has run. Three of the four things this
	 * has to do are already primitives — `focusGroup()` writes the marker's own
	 * `data-state="active"` as its side effect, `openList()` makes the sidebar's visibility
	 * deterministic, and `setSelectedId()` drives both the CTA label and the row highlight.
	 *
	 * A point that is no longer in the results restores NOTHING, silently: the map opens in
	 * its ordinary default view and the field is left alone for the checkout-processing
	 * backstop to judge (spec D-15). No fourth empty-state message — the three that exist
	 * (`emptyLocality`/`emptyInView`/`noResults`) are deliberately distinct.
	 *
	 * @param {Object} groupsByKey
	 * @returns {void}
	 */
	function restoreSelection( groupsByKey ) {
		var selectedId = fieldValue( config.fieldId );

		if ( ! selectedId || ! panels ) {
			return;
		}

		panels.setSelectedId( selectedId );

		var key = Object.keys( groupsByKey ).filter( function( groupKey ) {
			return ( groupsByKey[ groupKey ].points || [] ).some( function( point ) {
				return String( point.id ) === selectedId;
			} );
		} )[ 0 ];

		if ( ! key ) {
			return;
		}

		panels.openList();

		if ( provider && 'function' === typeof provider.focusGroup ) {
			provider.focusGroup( key, { zoom: true } );
		}
	}
```

Call it from the points-drawn continuation, right after `panels.hideMessage()`:

```js
					if ( points.length > 0 ) {
						hasDrawnPoints = true;

						if ( panels ) {
							panels.hideMessage();
							restoreSelection( groupsByKey );
						}
					} else {
```

The existing `alreadySelected` block at session open stays: it seeds the CTA label before any
fetch settles.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `npx jest tests/js/pickup-mount.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/shipping-method/assets/js/frontend/pickup-mount.js tests/js/pickup-mount.test.js
git commit -m "feat(pickup): restore the chosen point when the map reopens"
```

---

## Task 13: Fixture — a domain seam the rig can actually exercise

**Files:**
- Modify: `tests/_fixtures/woodev-test-shipping-method/` (the plugin's init callback)

- [ ] **Step 1: Add the seam**

Inside the fixture plugin's init callback — **not** at file top level, or the boot fatals before
the autoloader is registered (gotcha `fixture-classes-must-live-inside-plugin-init`):

```php
add_filter(
	'woodev_shipping_pickup_point_selection',
	function ( array $result, $point, array $context ): array {
		$id = method_exists( $point, 'get_id' ) ? (string) $point->get_id() : '';

		// A point that always refuses — the rig's way to see the remembered-refusal path.
		if ( 'DEMO-PVZ-REFUSE' === $id ) {
			$result['allowed'] = false;
			$result['reason']  = 'Этот пункт временно не принимает заказы.';

			return $result;
		}

		// A point that closes immediately, overriding the fixture's two-step default —
		// the rig's way to see `close` actually override the config.
		if ( 'DEMO-PVZ-FAST' === $id ) {
			$result['close'] = true;

			return $result;
		}

		// A point that asks for a checkout refresh, so the ordering can be watched live.
		if ( 'DEMO-POSTAMAT-1' === $id ) {
			$result['refresh_checkout'] = true;
		}

		return $result;
	},
	10,
	3
);
```

- [ ] **Step 2: Add the two extra points to the fixture point source**

Add `DEMO-PVZ-REFUSE` and `DEMO-PVZ-FAST` to the fixture's point list, both in Moscow, both
with coordinates distinct from the existing points so they are individually clickable.

- [ ] **Step 3: Run the integration suite**

Run: `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli --env-cwd=wp-content/plugins/woodev-framework ./vendor/bin/phpunit --testsuite integration`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/_fixtures/woodev-test-shipping-method/
git commit -m "test(pickup): fixture seam covering refusal, immediate close and refresh"
```

---

## Task 14: Full verification

- [ ] **Step 1: The whole suite**

```bash
composer test:unit
npx jest
composer phpcs
composer phpstan -- --memory-limit=4G
```

Expected: unit ≥1352 + the new cases; jest ≥631 + the new cases; phpcs 192 files clean; PHPStan
clean. `--memory-limit=4G` is not optional — the default 2G runs out (#164). PHPStan also
segfaults non-deterministically on Windows (`-1073741819`); Linux CI is the authoritative gate
(gotcha `phpstan-windows-parallel-worker-segfault`).

- [ ] **Step 2: Rig verification — the part no test can do**

The rig is already up; do not restart it. Checkout at `:8973`, country RU, region 77, method
`woodev_test_shipping`; the city resets on every reload, so set it with:

```js
sel.innerHTML='<option value="Москва" selected>Москва</option>';
jQuery(sel).val('Москва').trigger('change');
jQuery(document.body).trigger('update_checkout');
```

and wait for `jQuery.active === 0`.

Verify, by DOM measurement rather than by eye:

1. The spinner appears in the button and the card is locked; the **search bar and filter stay
   visible** (D-13 — this is what proves `is-busy` was not reused).
2. `DEMO-PVZ-REFUSE` shows its reason, the CTA dies, and the reason **survives closing and
   reopening the card**.
3. `DEMO-PVZ-FAST` closes the modal immediately even though the fixture's config default is
   two-step — proving `close` overrides the default.
4. An ordinary point leaves the modal open and the CTA reads «Продолжить оформление»; a second
   click closes with **no second network request** (check the network panel).
5. `DEMO-POSTAMAT-1` fires `update_checkout` and the button stays busy until it settles; the §8
   anchor is re-placed underneath the open modal and the picker still works afterwards.
6. Reopening the map focuses the chosen point, its marker reads `data-state="active"`, the
   sidebar is open and the row carries `is-selected`.
7. Break the nonce deliberately (`document.getElementById('woodev-pickup-nonce-…')
   .dataset.woodevPickupNonce = 'broken'`) and confirm the stale-page message appears and the
   CTA stays alive.

Catch the provider instance the usual way, before opening the modal:

```js
const P = window.WoodevPickupMapProviders.yandex;
const o = P.prototype.init;
P.prototype.init = function(){ window.__p = this; return o.apply(this, arguments); };
```

- [ ] **Step 3: Codex critic**

Per the project's own re-critic rule, hand the full diff to a Codex critic before opening the
PR — inline bundle, ≤~12KB per bundle (gotcha `codex-shell-sandbox-broken-windows`), split into
a PHP bundle and a JS bundle. Do **not** set a model: `gpt-5.6` is unavailable on this ChatGPT
account. Present findings verbatim and ask which to fix; never auto-fix.

- [ ] **Step 4: Open the PR**

```bash
git push -u origin feat/pickup-selection
gh pr create --title "feat(shipping): pickup point selection mechanism" \
  --body "Closes #169
Closes #157"
```

Verify **each** CI job is pass AND `mergeStateStatus` CLEAN as a separate step before merging
— never `gh pr merge --auto`.

- [ ] **Step 5: Stop for the operator**

This is UI/UX work. Per the standing merge policy it stops at «нужна ручная проверка
оператора» — do not merge on green CI alone.

---

## Self-review notes

**Spec coverage.** D-1 → Tasks 2-3. D-2 → Task 11 step 3. D-3/D-5 → Tasks 4, 11 (`resolveFlag`).
D-4 → Tasks 4, 11 (`refreshCheckout`). D-6/D-7 → Task 9, Task 11. D-8 → Task 9 (explicitly not
in the list). D-9 → Tasks 8, 11 (`pendingSelectionId`). D-10 → Task 11 (no abort anywhere).
D-11 → Task 11 step 4 (the early return). D-12 → Task 10 (row, not marker). D-13 → Task 8 CSS.
D-14 → Task 11 (`_resolved` carries the point; framework writes no address). D-15 → Task 12.
D-16 + §4.1.1 → Tasks 2, 5, 6.

**Known gap, deliberate:** the `close: false` + `refresh_checkout: true` combination is
exercised by unit tests and by rig step 5, but nothing pins the §8 anchor re-placement in an
automated test — jsdom does not run WooCommerce. That is stated rather than papered over.

**Task 5 is severable** (the nonce fragment). Dropping it leaves the stale-page message doing
all the work; the feature still functions.

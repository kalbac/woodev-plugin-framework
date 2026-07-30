# SP-5 Pickup Points + Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended)
> or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax
> for tracking.

**Goal:** Let a customer pick a carrier pickup point on the classic WooCommerce checkout — button →
modal → map → selection — and have that point survive `update_checkout`, gate the order correctly, and
land on the order.

**Architecture:** The plugin owns the carrier API and returns normalized points. The framework owns the
point shape, `woodev/v1` REST, a vanilla modal shell, mounting into §8's `data-woodev-pickup-slot`
anchor, constraint checking, address replacement and persistence. The map provider owns everything drawn
inside its container and pulls data through a `dataSource` handed to it, which is what lets one contract
serve both a bulk carrier (Yandex, CDEK) and a bbox carrier (OZON).

**Tech Stack:** PHP 7.4+ (PSR-4 `Woodev\Framework\Shipping\*`), WordPress/WooCommerce, vanilla ES5-safe
JS + jQuery for storefront, Yandex Maps JS API 2.1, PHPUnit + Brain Monkey (unit), WP test library
(integration), Jest (JS).

**Spec:** `docs-internal/specs/2026-07-30-sp5-pickup-map-design.md`

---

## Before you start

- Branch: `feat/pickup-map` off `main`.
- Rig: ports **8973** (dev) / **8974** (tests) — 8888 belongs to another project. `npx wp-env start`.
  admin/password. `woocommerce_coming_soon` must be `no`.
- Drive the browser with **chrome-devtools MCP, never Playwright MCP** — gotcha
  `playwright-mcp-does-not-fire-wc-checkout-ajax`.
- Run integration tests locally, do not "write them for CI":
  `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage`
- Long commit messages: write to a file and `git commit -F <file>` — backticks/parens break bash parsing.
- Every new class → regenerate `woodev/class-map.php` via `php bin/generate-class-map.php`.
- `@since 2.0.2`. Do NOT bump `Woodev_Plugin::VERSION`.

## File structure

**Created**

| File | Responsibility |
|---|---|
| `woodev/shipping-method/pickup/class-pickup-point.php` | Normalized point value object; construction from an array, validation, escaping, `to_array()` |
| `woodev/shipping-method/pickup/class-point-query.php` | Query value object: locality / bbox / search term; bbox parsing + area cap |
| `woodev/shipping-method/pickup/interface-point-source.php` | The plugin seam: `fetch_points()`, `fetch_details()`, `get_strategy()` |
| `woodev/shipping-method/pickup/class-constraint-checker.php` | COD + weight verdict, filterable |
| `woodev/shipping-method/pickup/class-address-target.php` | Resolves whether the delivery address is `billing_*` or `shipping_*` |
| `woodev/shipping-method/pickup/class-pickup-handler.php` | Orchestration: JS config, session, order meta, server-side constraint re-check |
| `woodev/shipping-method/rest-api/class-pickup-controller.php` | `woodev/v1` points + point-details routes |
| `woodev/shipping-method/map/class-yandex-map-provider.php` | PHP descriptor for our ymaps provider (handle, settings field, JS config) |
| `woodev/shipping-method/map/class-embedded-map-provider.php` | PHP descriptor for a carrier widget/iframe provider |
| `woodev/shipping-method/assets/js/frontend/pickup-modal.js` | Vanilla dialog shell: focus trap, Esc, aria, mobile full-screen |
| `woodev/shipping-method/assets/js/frontend/pickup-datasource.js` | REST wrapper: debounce, de-dup by id, error surfacing |
| `woodev/shipping-method/assets/js/frontend/pickup-mount.js` | Mounts the trigger into §8's anchor, opens the modal, wires the provider, writes the selection back through the §8 store |
| `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js` | The reference UX on ymaps 2.1 |
| `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js` | Carrier widget/iframe inside the same shell |
| `woodev/shipping-method/assets/css/frontend/pickup.css` | Shell, drawer, balloon, mobile |

**Modified**

| File | Change |
|---|---|
| `woodev/shipping-method/map/interface-map-provider.php` | Re-pointed contract (see Task 9) |
| `woodev/shipping-method/map/class-map-provider-registry.php` | Register the two new providers; drop Leaflet |
| `woodev/class-map.php` | Regenerated |
| `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php` | Two point sources (bulk + viewport), replaces the demo stub button |

**Deleted** — Task 1.

---

## Task 1: Delete the pre-§8 pickup subsystem

The June skeleton (`8887ce0`) has zero consumers, zero tests and predates §8. Clean-break policy: delete,
do not shim.

**Files:**
- Delete: `woodev/shipping-method/checkout/class-pickup-checkout-handler.php`
- Delete: `woodev/shipping-method/ajax/class-shipping-ajax.php`
- Delete: `woodev/shipping-method/assets/js/frontend/pickup-map.js`
- Delete: `woodev/shipping-method/assets/js/frontend/map-adapter-leaflet.js`
- Delete: `woodev/shipping-method/assets/js/frontend/checkout.js`
- Delete: `woodev/shipping-method/map/class-leaflet-map-provider.php`
- Delete: `woodev/shipping-method/checkout/views/html-pickup-modal.php`
- Delete: `woodev/shipping-method/checkout/views/html-pickup-balloon.php`
- Delete: `woodev/shipping-method/assets/css/frontend/pickup-map.css`
- Modify: `woodev/class-map.php`

- [ ] **Step 1: Confirm nothing references them**

```bash
grep -rn "Pickup_Checkout_Handler\|Shipping_Ajax\|pickup-map\.js\|map-adapter-leaflet\|Leaflet_Map_Provider" \
  --include=*.php --include=*.js woodev/ tests/ \
  | grep -v "^woodev/shipping-method/checkout/class-pickup-checkout-handler.php" \
  | grep -v "^woodev/shipping-method/ajax/class-shipping-ajax.php" \
  | grep -v "^woodev/shipping-method/map/class-leaflet-map-provider.php" \
  | grep -v "^woodev/class-map.php"
```

Expected: no output other than the doc-comment cross-references inside the files being deleted. If a
real consumer appears, STOP and report it — the premise of this task is that there are none.

- [ ] **Step 2: Delete the files**

```bash
git rm woodev/shipping-method/checkout/class-pickup-checkout-handler.php \
       woodev/shipping-method/ajax/class-shipping-ajax.php \
       woodev/shipping-method/assets/js/frontend/pickup-map.js \
       woodev/shipping-method/assets/js/frontend/map-adapter-leaflet.js \
       woodev/shipping-method/assets/js/frontend/checkout.js \
       woodev/shipping-method/map/class-leaflet-map-provider.php \
       woodev/shipping-method/checkout/views/html-pickup-modal.php \
       woodev/shipping-method/checkout/views/html-pickup-balloon.php \
       woodev/shipping-method/assets/css/frontend/pickup-map.css
```

- [ ] **Step 3: Regenerate the class map**

Run: `php bin/generate-class-map.php`
Expected: `Leaflet_Map_Provider` and `Pickup_Checkout_Handler` entries disappear from
`woodev/class-map.php`.

- [ ] **Step 4: Full suite still green**

Run: `composer test:unit && composer phpcs`
Expected: 1026 unit tests pass, phpcs 192/192 clean.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "refactor(shipping)!: remove the pre-checkout-layer pickup subsystem

Zero consumers, zero tests, built on admin-ajax and superseded by the §8 field
layer. Clean-break policy: delete rather than shim. The Map_Provider seam is kept
and re-pointed in a later commit."
```

---

## Task 2: `Pickup_Point` value object

**Files:**
- Create: `woodev/shipping-method/pickup/class-pickup-point.php`
- Test: `tests/unit/Shipping/Pickup/PickupPointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';

class PickupPointTest extends TestCase {

	private function valid(): array {
		return [
			'id'      => 'PVZ-1',
			'name'    => 'ПВЗ на Тверской',
			'lat'     => 55.7558,
			'lng'     => 37.6173,
			'address' => 'Москва, ул. Тверская, 1',
			'type'    => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
		];
	}

	public function test_builds_from_a_complete_payload(): void {
		$point = Pickup_Point::from_array( $this->valid() );
		$this->assertSame( 'PVZ-1', $point->get_id() );
		$this->assertSame( 55.7558, $point->get_lat() );
	}

	public function test_returns_null_when_a_required_field_is_missing(): void {
		foreach ( [ 'id', 'name', 'lat', 'lng', 'address', 'type' ] as $key ) {
			$payload = $this->valid();
			unset( $payload[ $key ] );
			$this->assertNull( Pickup_Point::from_array( $payload ), "missing {$key} must reject" );
		}
	}

	public function test_returns_null_for_out_of_range_coordinates(): void {
		$payload        = $this->valid();
		$payload['lat'] = 91.0;
		$this->assertNull( Pickup_Point::from_array( $payload ) );
	}

	public function test_unknown_constraints_default_to_permissive(): void {
		$point = Pickup_Point::from_array( $this->valid() );
		$this->assertNull( $point->get_accepts_cod() );
		$this->assertNull( $point->get_max_weight() );
	}

	public function test_to_array_escapes_strings(): void {
		$payload         = $this->valid();
		$payload['name'] = '<script>alert(1)</script>';
		$array           = Pickup_Point::from_array( $payload )->to_array();
		$this->assertStringNotContainsString( '<script>', $array['name'] );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupPointTest.php --no-coverage`
Expected: FAIL — `Class "Woodev\Framework\Shipping\Pickup\Pickup_Point" not found`.

- [ ] **Step 3: Implement**

```php
<?php
/**
 * Woodev Pickup Point
 *
 * The normalized carrier pickup point. Plugins translate their carrier's payload into
 * this shape; neither the framework nor a map provider ever sees a raw carrier response.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Point' ) ) :

	/**
	 * Immutable value object describing one pickup point.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Point {

		/** @var array<string, mixed> normalized data */
		private array $data;

		/**
		 * Constructor. Use {@see from_array()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data Pre-validated normalized data.
		 */
		private function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * Builds a point from a plugin-supplied payload.
		 *
		 * Returns null when a required field is missing or a coordinate is out of range —
		 * a malformed point must never reach the map, and a carrier returning junk for one
		 * point must not break the whole list.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $payload Raw normalized payload from the plugin.
		 *
		 * @return self|null
		 */
		public static function from_array( array $payload ): ?self {
			foreach ( [ 'id', 'name', 'lat', 'lng', 'address', 'type' ] as $required ) {
				if ( ! isset( $payload[ $required ] ) || '' === $payload[ $required ] ) {
					return null;
				}
			}

			if ( ! is_array( $payload['type'] ) || ! isset( $payload['type']['code'], $payload['type']['label'] ) ) {
				return null;
			}

			$lat = (float) $payload['lat'];
			$lng = (float) $payload['lng'];

			if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
				return null;
			}

			return new self( [
				'id'              => (string) $payload['id'],
				'name'            => (string) $payload['name'],
				'lat'             => $lat,
				'lng'             => $lng,
				'address'         => (string) $payload['address'],
				'type'            => [
					'code'  => (string) $payload['type']['code'],
					'label' => (string) $payload['type']['label'],
				],
				'short_address'   => isset( $payload['short_address'] ) ? (string) $payload['short_address'] : '',
				'locality'        => isset( $payload['locality'] ) ? (string) $payload['locality'] : '',
				'postal_code'     => isset( $payload['postal_code'] ) ? (string) $payload['postal_code'] : '',
				'phone'           => isset( $payload['phone'] ) ? (string) $payload['phone'] : '',
				'instruction'     => isset( $payload['instruction'] ) ? (string) $payload['instruction'] : '',
				'work_time'       => isset( $payload['work_time'] ) ? (string) $payload['work_time'] : '',
				'payment_methods' => isset( $payload['payment_methods'] ) ? array_map( 'strval', (array) $payload['payment_methods'] ) : [],
				'photos'          => isset( $payload['photos'] ) ? array_map( 'strval', (array) $payload['photos'] ) : [],
				'accepts_cod'     => isset( $payload['accepts_cod'] ) ? (bool) $payload['accepts_cod'] : null,
				'max_weight'      => isset( $payload['max_weight'] ) ? (int) $payload['max_weight'] : null,
			] );
		}

		/**
		 * Gets the carrier point id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->data['id'];
		}

		/**
		 * Gets the latitude.
		 *
		 * @since 2.0.2
		 *
		 * @return float
		 */
		public function get_lat(): float {
			return $this->data['lat'];
		}

		/**
		 * Gets the longitude.
		 *
		 * @since 2.0.2
		 *
		 * @return float
		 */
		public function get_lng(): float {
			return $this->data['lng'];
		}

		/**
		 * Gets the full address.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_address(): string {
			return $this->data['address'];
		}

		/**
		 * Gets the locality, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_locality(): string {
			return $this->data['locality'];
		}

		/**
		 * Gets the postal code, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_postal_code(): string {
			return $this->data['postal_code'];
		}

		/**
		 * Whether the point accepts cash on delivery. Null means the carrier did not say.
		 *
		 * @since 2.0.2
		 *
		 * @return bool|null
		 */
		public function get_accepts_cod(): ?bool {
			return $this->data['accepts_cod'];
		}

		/**
		 * Maximum accepted weight in GRAMS, or null when the carrier did not say.
		 *
		 * @since 2.0.2
		 *
		 * @return int|null
		 */
		public function get_max_weight(): ?int {
			return $this->data['max_weight'];
		}

		/**
		 * Returns the browser-safe representation.
		 *
		 * Every string is escaped here, once, server-side — the same rule the §8 field-source
		 * controller applies to option labels.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			$out = $this->data;

			foreach ( [ 'id', 'name', 'address', 'short_address', 'locality', 'postal_code', 'phone', 'instruction', 'work_time' ] as $key ) {
				$out[ $key ] = esc_html( $out[ $key ] );
			}

			$out['type']['code']  = esc_html( $out['type']['code'] );
			$out['type']['label'] = esc_html( $out['type']['label'] );
			$out['payment_methods'] = array_map( 'esc_html', $out['payment_methods'] );
			$out['photos']          = array_map( 'esc_url_raw', $out['photos'] );

			return $out;
		}
	}

endif;
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupPointTest.php --no-coverage`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): add the normalized Pickup_Point value object"
```

---

## Task 3: `Point_Query` value object

Carries the request from the browser to the plugin's point source. Its job beyond transport is to stop a
client asking for the whole planet.

**Files:**
- Create: `woodev/shipping-method/pickup/class-point-query.php`
- Test: `tests/unit/Shipping/Pickup/PointQueryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';

class PointQueryTest extends TestCase {

	public function test_parses_a_bbox(): void {
		$query = Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80,37.70' ] );
		$this->assertSame( [ 55.70, 37.50, 55.80, 37.70 ], $query->get_bounds() );
	}

	public function test_rejects_a_bbox_with_the_wrong_arity(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.70,37.50,55.80' ] ) );
	}

	public function test_rejects_a_bbox_larger_than_the_area_cap(): void {
		// Whole planet — must be refused, not silently served.
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '-90,-180,90,180' ] ) );
	}

	public function test_rejects_an_inverted_bbox(): void {
		$this->assertNull( Point_Query::from_request( [ 'bbox' => '55.80,37.70,55.70,37.50' ] ) );
	}

	public function test_carries_locality_and_search_term(): void {
		$query = Point_Query::from_request( [ 'locality' => 'Москва', 'q' => 'твер' ] );
		$this->assertSame( 'Москва', $query->get_locality() );
		$this->assertSame( 'твер', $query->get_search() );
		$this->assertNull( $query->get_bounds() );
	}

	public function test_rejects_an_empty_request(): void {
		$this->assertNull( Point_Query::from_request( [] ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PointQueryTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
/**
 * Woodev Pickup Point Query
 *
 * Describes one request for pickup points: either a locality (bulk strategy) or a
 * bounding box (viewport strategy), optionally narrowed by a search term.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Point_Query' ) ) :

	/**
	 * Immutable request for pickup points.
	 *
	 * @since 2.0.2
	 */
	class Point_Query {

		/**
		 * Largest bounding box we will serve, in square degrees.
		 *
		 * A viewport carrier is queried per visible area; without a cap a client could ask
		 * for the entire planet and force the plugin to hammer the carrier API. 100 sq deg
		 * is roughly a 10°x10° window — far larger than any realistic checkout viewport.
		 *
		 * @var float
		 */
		private const MAX_BBOX_AREA = 100.0;

		/** @var string|null */
		private ?string $locality;

		/** @var array{0: float, 1: float, 2: float, 3: float}|null */
		private ?array $bounds;

		/** @var string */
		private string $search;

		/**
		 * Constructor. Use {@see from_request()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param string|null                                  $locality Locality name.
		 * @param array{0: float, 1: float, 2: float, 3: float}|null $bounds   Bounding box.
		 * @param string                                       $search   Search term.
		 */
		private function __construct( ?string $locality, ?array $bounds, string $search ) {
			$this->locality = $locality;
			$this->bounds   = $bounds;
			$this->search   = $search;
		}

		/**
		 * Builds a query from request parameters.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $params Request parameters (`locality`, `bbox`, `q`).
		 *
		 * @return self|null Null when the request is unusable.
		 */
		public static function from_request( array $params ): ?self {
			$search   = isset( $params['q'] ) ? (string) $params['q'] : '';
			$locality = isset( $params['locality'] ) && '' !== $params['locality'] ? (string) $params['locality'] : null;
			$bounds   = null;

			if ( isset( $params['bbox'] ) && '' !== $params['bbox'] ) {
				$bounds = self::parse_bbox( (string) $params['bbox'] );

				if ( null === $bounds ) {
					return null;
				}
			}

			if ( null === $locality && null === $bounds ) {
				return null;
			}

			return new self( $locality, $bounds, $search );
		}

		/**
		 * Parses and validates a `lat1,lng1,lat2,lng2` bounding box.
		 *
		 * @since 2.0.2
		 *
		 * @param string $raw Raw bbox parameter.
		 *
		 * @return array{0: float, 1: float, 2: float, 3: float}|null
		 */
		private static function parse_bbox( string $raw ): ?array {
			$parts = array_map( 'trim', explode( ',', $raw ) );

			if ( 4 !== count( $parts ) ) {
				return null;
			}

			foreach ( $parts as $part ) {
				if ( ! is_numeric( $part ) ) {
					return null;
				}
			}

			[ $lat1, $lng1, $lat2, $lng2 ] = array_map( 'floatval', $parts );

			if ( $lat1 < -90.0 || $lat2 > 90.0 || $lng1 < -180.0 || $lng2 > 180.0 ) {
				return null;
			}

			if ( $lat1 >= $lat2 || $lng1 >= $lng2 ) {
				return null;
			}

			if ( ( $lat2 - $lat1 ) * ( $lng2 - $lng1 ) > self::MAX_BBOX_AREA ) {
				return null;
			}

			return [ $lat1, $lng1, $lat2, $lng2 ];
		}

		/**
		 * Gets the locality, or null under the viewport strategy.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_locality(): ?string {
			return $this->locality;
		}

		/**
		 * Gets the bounding box, or null under the bulk strategy.
		 *
		 * @since 2.0.2
		 *
		 * @return array{0: float, 1: float, 2: float, 3: float}|null
		 */
		public function get_bounds(): ?array {
			return $this->bounds;
		}

		/**
		 * Gets the search term, possibly empty.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_search(): string {
			return $this->search;
		}
	}

endif;
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PointQueryTest.php --no-coverage`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): add Point_Query with bbox validation and an area cap"
```

---

## Task 4: `Point_Source` interface

**Files:**
- Create: `woodev/shipping-method/pickup/interface-point-source.php`

- [ ] **Step 1: Write the interface**

There is no test for an interface on its own; Task 18's fixture implements it and the REST tests exercise
it.

```php
<?php
/**
 * Woodev Pickup Point Source
 *
 * The plugin seam. A shipping plugin implements this to expose its carrier's pickup
 * points; the framework never learns anything about the carrier's API from it.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Point_Source' ) ) :

	/**
	 * Supplies normalized pickup points for one carrier.
	 *
	 * @since 2.0.2
	 */
	interface Point_Source {

		/** Load every point for a locality at once (Yandex, CDEK). */
		public const STRATEGY_BULK = 'bulk';

		/** Load points for the visible bounding box, details on demand (OZON, Pochta). */
		public const STRATEGY_VIEWPORT = 'viewport';

		/**
		 * Returns the loading strategy this source supports.
		 *
		 * Determines whether the map provider queries once by locality or repeatedly by
		 * bounding box as the customer pans.
		 *
		 * @since 2.0.2
		 *
		 * @return string One of the STRATEGY_* constants.
		 */
		public function get_strategy(): string;

		/**
		 * Fetches points matching the query.
		 *
		 * Implementations must return already-normalized points. Malformed entries should be
		 * skipped rather than throwing — one bad point must not empty the map.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query What to fetch.
		 *
		 * @return Pickup_Point[]
		 */
		public function fetch_points( Point_Query $query ): array;

		/**
		 * Fetches one point's full detail.
		 *
		 * Under the viewport strategy the list response is usually sparse and this call adds
		 * opening hours, payment methods and weight limits. Under the bulk strategy it may
		 * simply return the point already known.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id Carrier point id.
		 *
		 * @return Pickup_Point|null Null when the point is unknown.
		 */
		public function fetch_details( string $point_id ): ?Pickup_Point;
	}

endif;
```

- [ ] **Step 2: Verify it parses**

Run: `php -l woodev/shipping-method/pickup/interface-point-source.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ && git commit -m "feat(shipping): add the Point_Source plugin seam"
```

---

## Task 5: `Constraint_Checker`

**Files:**
- Create: `woodev/shipping-method/pickup/class-constraint-checker.php`
- Test: `tests/unit/Shipping/Pickup/ConstraintCheckerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';

class ConstraintCheckerTest extends TestCase {

	private function point( array $extra = [] ): Pickup_Point {
		return Pickup_Point::from_array( array_merge( [
			'id'      => 'P1',
			'name'    => 'Точка',
			'lat'     => 55.75,
			'lng'     => 37.61,
			'address' => 'Москва',
			'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
		], $extra ) );
	}

	public function test_a_plain_point_is_selectable(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
		$this->assertNull( $verdict['reason'] );
	}

	public function test_cod_is_blocked_when_the_point_refuses_it(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'cod', 0 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertNotNull( $verdict['reason'] );
	}

	public function test_cod_refusal_is_irrelevant_for_another_payment_method(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'accepts_cod' => false ] ), 'bacs', 0 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_unknown_cod_support_is_permissive(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'cod', 0 );
		$this->assertTrue( $verdict['allowed'], 'unknown must not be treated as a prohibition' );
	}

	public function test_overweight_cart_is_blocked(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 20000 );
		$this->assertFalse( $verdict['allowed'] );
	}

	public function test_cart_within_the_limit_passes(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$verdict = ( new Constraint_Checker() )->check( $this->point( [ 'max_weight' => 15000 ] ), 'bacs', 15000 );
		$this->assertTrue( $verdict['allowed'] );
	}

	public function test_the_filter_can_override_the_verdict(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $hook, $verdict ) {
			return [ 'allowed' => false, 'reason' => 'нельзя' ];
		} );
		$verdict = ( new Constraint_Checker() )->check( $this->point(), 'bacs', 0 );
		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( 'нельзя', $verdict['reason'] );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/ConstraintCheckerTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
/**
 * Woodev Pickup Constraint Checker
 *
 * Decides whether a given point can be selected for the current cart and payment method.
 * COD support and weight limits exist at every carrier we target, so they are framework
 * mechanism rather than plugin domain.
 *
 * The verdict is computed HERE, server-side, and travels to the browser with the point.
 * The client renders it; it never re-implements it. That avoids the mirrored-evaluator
 * maintenance that conditional fields needed, and keeps one source of truth.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Constraint_Checker' ) ) :

	/**
	 * Applies the framework's built-in pickup point constraints.
	 *
	 * @since 2.0.2
	 */
	class Constraint_Checker {

		/**
		 * Payment method ids treated as cash on delivery.
		 *
		 * @var string[]
		 */
		private array $cod_methods;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $cod_methods Payment method ids that mean "cash on delivery".
		 */
		public function __construct( array $cod_methods = [ 'cod' ] ) {
			$this->cod_methods = $cod_methods;
		}

		/**
		 * Returns the selectability verdict for a point.
		 *
		 * An unknown constraint value is permissive: greying out a point the customer could
		 * legitimately use is worse than a late rejection, and the server re-check at
		 * checkout remains the backstop.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point          The point being judged.
		 * @param string       $payment_method Currently chosen payment method id.
		 * @param int          $cart_weight    Cart weight in GRAMS.
		 *
		 * @return array{allowed: bool, reason: string|null}
		 */
		public function check( Pickup_Point $point, string $payment_method, int $cart_weight ): array {
			$verdict = [ 'allowed' => true, 'reason' => null ];

			if ( false === $point->get_accepts_cod() && in_array( $payment_method, $this->cod_methods, true ) ) {
				$verdict = [
					'allowed' => false,
					'reason'  => __( 'В этом пункте выдачи недоступна оплата при получении. Выберите другой пункт или другой способ оплаты.', 'woodev-plugin-framework' ),
				];
			}

			$max_weight = $point->get_max_weight();

			if ( $verdict['allowed'] && null !== $max_weight && $cart_weight > $max_weight ) {
				$verdict = [
					'allowed' => false,
					'reason'  => sprintf(
						/* translators: 1: cart weight in kg, 2: point weight limit in kg */
						__( 'Вес заказа %1$s кг превышает ограничение пункта выдачи — %2$s кг.', 'woodev-plugin-framework' ),
						number_format_i18n( $cart_weight / 1000, 2 ),
						number_format_i18n( $max_weight / 1000, 2 )
					),
				];
			}

			/**
			 * Filters whether a pickup point may be selected.
			 *
			 * @since 2.0.2
			 *
			 * @param array{allowed: bool, reason: string|null} $verdict        Computed verdict.
			 * @param Pickup_Point                              $point          The point.
			 * @param string                                    $payment_method Chosen payment method id.
			 * @param int                                       $cart_weight    Cart weight in grams.
			 */
			return (array) apply_filters( 'woodev_shipping_pickup_point_selectable', $verdict, $point, $payment_method, $cart_weight );
		}
	}

endif;
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/ConstraintCheckerTest.php --no-coverage`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): add the pickup Constraint_Checker (COD + weight)"
```

---

## Task 6: `Address_Target` resolver

Decides whether the selected point's address goes into `billing_*` or `shipping_*`, by asking the same
question WooCommerce asks itself.

**Files:**
- Create: `woodev/shipping-method/pickup/class-address-target.php`
- Test: `tests/unit/Shipping/Pickup/AddressTargetTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Address_Target;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-address-target.php';

class AddressTargetTest extends TestCase {

	public function test_billing_only_mode_targets_billing(): void {
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$this->assertSame( 'billing', Address_Target::resolve( true ) );
	}

	public function test_billing_only_mode_ignores_the_ship_elsewhere_flag(): void {
		// WooCommerce forces the flag false in billing_only mode; so must we.
		Functions\when( 'get_option' )->justReturn( 'billing_only' );
		$this->assertSame( 'billing', Address_Target::resolve( true ) );
	}

	public function test_unchecked_ship_elsewhere_targets_billing(): void {
		Functions\when( 'get_option' )->justReturn( 'billing' );
		$this->assertSame( 'billing', Address_Target::resolve( false ) );
	}

	public function test_checked_ship_elsewhere_targets_shipping(): void {
		Functions\when( 'get_option' )->justReturn( 'shipping' );
		$this->assertSame( 'shipping', Address_Target::resolve( true ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/AddressTargetTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
/**
 * Woodev Pickup Address Target
 *
 * Answers one question: which checkout fieldset is currently the DELIVERY address?
 *
 * We do not decide this ourselves — WooCommerce already has an answer and we follow it,
 * so the behaviour is right under every store configuration without a setting of our own:
 *
 * - `WC_Checkout::get_posted_address_data()` returns the BILLING value for a shipping key
 *   whenever `ship_to_different_address` is false;
 * - `ship_to_different_address` is forced false in `billing_only` mode
 *   (see `wc_ship_to_billing_address_only()`);
 * - `maybe_skip_fieldset()` drops the shipping fieldset entirely when the flag is false.
 *
 * Usefully, the only configuration where billing and shipping genuinely differ is the one
 * where we write to shipping — so a separate billing address is never overwritten.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Address_Target' ) ) :

	/**
	 * Resolves the checkout fieldset that holds the delivery address.
	 *
	 * @since 2.0.2
	 */
	class Address_Target {

		/**
		 * Returns the field prefix to write the pickup point address into.
		 *
		 * @since 2.0.2
		 *
		 * @param bool $ship_to_different_address Whether the customer ticked "ship to a different address".
		 *
		 * @return string Either `billing` or `shipping`.
		 */
		public static function resolve( bool $ship_to_different_address ): string {
			if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination' ) ) {
				return 'billing';
			}

			return $ship_to_different_address ? 'shipping' : 'billing';
		}
	}

endif;
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/AddressTargetTest.php --no-coverage`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): resolve the pickup address target the way WooCommerce does"
```

---

## Task 7: `Pickup_Controller` REST routes

Mirrors the §8 field-source controller: per-plugin route id, guest accessible, escaped output.
Read `woodev/shipping-method/rest-api/class-field-source-controller.php` first and follow its shape.

**Files:**
- Create: `woodev/shipping-method/rest-api/class-pickup-controller.php`
- Test: `tests/unit/Shipping/Rest_Api/PickupControllerTest.php`
- Test: `tests/integration/Shipping/PickupRouteTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/tests/unit/Shipping/Rest_Api/wp-rest-controller-stub.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';

class PickupControllerTest extends TestCase {

	private function source(): Point_Source {
		return new class implements Point_Source {
			public function get_strategy(): string {
				return self::STRATEGY_BULK;
			}
			public function fetch_points( Point_Query $query ): array {
				return array_filter( [
					Pickup_Point::from_array( [
						'id' => 'P1', 'name' => 'Точка', 'lat' => 55.75, 'lng' => 37.61,
						'address' => 'Москва', 'type' => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
					] ),
				] );
			}
			public function fetch_details( string $point_id ): ?Pickup_Point {
				return 'P1' === $point_id ? $this->fetch_points( Point_Query::from_request( [ 'locality' => 'x' ] ) )[0] : null;
			}
		};
	}

	public function test_points_carries_the_selectable_verdict(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );
		$response   = $controller->get_points_data( [ 'locality' => 'Москва' ] );

		$this->assertArrayHasKey( 'points', $response );
		$this->assertTrue( $response['points'][0]['selectable']['allowed'] );
	}

	public function test_an_unusable_query_yields_an_empty_point_list(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );
		$this->assertSame( [], $controller->get_points_data( [] )['points'] );
	}

	public function test_details_returns_null_for_an_unknown_point(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$controller = new Pickup_Controller( 'test-plugin', $this->source(), static fn() => 0, static fn() => 'bacs' );
		$this->assertNull( $controller->get_point_data( 'NOPE' ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Rest_Api/PickupControllerTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

Model the class on `Field_Source_Controller`: namespace `Woodev\Framework\Shipping\Rest_Api`, namespace
`woodev/v1`, per-plugin `rest_base`. It must expose two testable seams,
`get_points_data( array $params ): array` and `get_point_data( string $id ): ?array`, so the logic is unit
testable without a live REST request; the `register_routes()` callbacks are thin wrappers over them.

Routes:

```
GET woodev/v1/shipping/pickup/{plugin_id}/points          args: locality, bbox, q
GET woodev/v1/shipping/pickup/{plugin_id}/points/(?P<id>[^/]+)
```

`permission_callback` returns `true` — checkout guests need these, and only normalized points are ever
returned. Build a `Point_Query` from the params; a null query yields `[ 'points' => [] ]` rather than an
error, so a client that has not yet resolved a locality does not see a failure state. Each point is
`to_array()`ed and gets a `selectable` key from `Constraint_Checker`, using the cart-weight and
payment-method callables injected in the constructor (so unit tests need no WC).

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Rest_Api/PickupControllerTest.php --no-coverage`
Expected: PASS, 3 tests.

- [ ] **Step 5: Write the integration test**

```php
<?php
namespace Woodev\Tests\Integration\Shipping;

class PickupRouteTest extends \WP_UnitTestCase {

	public function test_points_route_is_registered_for_the_fixture_plugin(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/woodev/v1/shipping/pickup/woodev-test-shipping-method/points', $routes );
	}

	public function test_a_guest_can_read_points(): void {
		wp_set_current_user( 0 );
		$request  = new \WP_REST_Request( 'GET', '/woodev/v1/shipping/pickup/woodev-test-shipping-method/points' );
		$request->set_param( 'locality', 'Москва' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['points'] );
	}

	public function test_an_oversized_bbox_yields_no_points(): void {
		$request = new \WP_REST_Request( 'GET', '/woodev/v1/shipping/pickup/woodev-test-shipping-method/points' );
		$request->set_param( 'bbox', '-90,-180,90,180' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['points'] );
	}
}
```

Note the require depth rule: from `tests/integration/<Group>/` the repo root is `dirname( __DIR__, 3 )`.
Getting it wrong aborts the entire Integration suite before a single test runs.

- [ ] **Step 6: Run the integration suite**

Run the wp-env command from "Before you start".
Expected: PASS. (This task's integration tests only go green once Task 18's fixture source exists — if you
are running tasks in order, expect these three to fail until then and re-run after Task 18.)

- [ ] **Step 7: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): add the woodev/v1 pickup points REST controller"
```

---

## Task 8: `Pickup_Handler`

**Files:**
- Create: `woodev/shipping-method/pickup/class-pickup-handler.php`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-address-target.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-handler.php';

class PickupHandlerTest extends TestCase {

	public function test_config_exposes_the_strategy_and_route_but_no_secrets(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1/shipping/pickup/p/points' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );

		$config = ( new Pickup_Handler( 'p', 'carrier_pickup_point', 'viewport', 'yandex' ) )->get_js_config();

		$this->assertSame( 'viewport', $config['strategy'] );
		$this->assertSame( 'carrier_pickup_point', $config['fieldId'] );
		$this->assertSame( 'yandex', $config['provider'] );
		$this->assertArrayNotHasKey( 'api_secret', $config );
	}

	public function test_a_blocked_point_fails_the_server_recheck(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'number_format_i18n' )->returnArg( 1 );

		$point = Pickup_Point::from_array( [
			'id' => 'P1', 'name' => 'Точка', 'lat' => 55.75, 'lng' => 37.61,
			'address' => 'Москва', 'type' => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
			'accepts_cod' => false,
		] );

		$handler = new Pickup_Handler( 'p', 'carrier_pickup_point', 'bulk', 'yandex' );

		$this->assertFalse( $handler->validate_selected_point( $point, 'cod', 0 ) );
		$this->assertTrue( $handler->validate_selected_point( $point, 'bacs', 0 ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`Pickup_Handler` owns:

- `get_js_config(): array` → `{ fieldId, strategy, provider, restRoot, nonce, i18n, mapConfig, replaceAddress }`.
  It must never emit a carrier credential — the §8 `Checkout_Config` is the precedent; read it.
- `register(): void` → enqueues the shell + datasource + mount + the active provider's script and the
  stylesheet on `wp_enqueue_scripts` when `is_checkout()`; hooks `validate_selected_point()` onto
  `woocommerce_checkout_process` (the server backstop), and stores the full point on
  `woocommerce_checkout_order_processed`.
- `validate_selected_point( Pickup_Point $point, string $payment_method, int $cart_weight ): bool` →
  delegates to `Constraint_Checker`, calls `wc_add_notice()` with the reason on failure.
- Persistence: the point id is already saved by §8 (the field id **is** the meta key). This handler adds
  the full normalized point alongside it, via `Woodev_Order_Compatibility::update_order_meta()` — never
  `get_post_meta`/`update_post_meta` (gotcha `hpos-order-meta-safety`).

It must NOT re-implement "a pickup point is required": `Checkout_Handler` already does that, both as a
conditional-required field and as an independent backstop. Adding a second gate here would double the
error the customer sees.

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "feat(shipping): add Pickup_Handler orchestration and the server-side constraint backstop"
```

---

## Task 9: Re-point the `Map_Provider` seam

**Files:**
- Modify: `woodev/shipping-method/map/interface-map-provider.php`
- Modify: `woodev/shipping-method/map/class-map-provider-registry.php`
- Create: `woodev/shipping-method/map/class-yandex-map-provider.php`
- Create: `woodev/shipping-method/map/class-embedded-map-provider.php`
- Test: `tests/unit/Shipping/Map/MapProviderRegistryTest.php`

- [ ] **Step 1: Read what is there**

Run: `cat woodev/shipping-method/map/interface-map-provider.php woodev/shipping-method/map/class-map-provider-registry.php`

The interface currently describes "which library draws the map". It must now describe "where the map
comes from": `get_id()`, `get_label()`, `get_script_handle()`, `get_settings_fields()`,
`get_js_config( array $context ): array`.

- [ ] **Step 2: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Map;

use Woodev\Framework\Shipping\Map\Map_Provider_Registry;
use Woodev\Framework\Shipping\Map\Yandex_Map_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-map-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';

class MapProviderRegistryTest extends TestCase {

	public function test_yandex_is_the_default_provider(): void {
		$registry = new Map_Provider_Registry();
		$registry->register( new Yandex_Map_Provider() );
		$this->assertInstanceOf( Yandex_Map_Provider::class, $registry->get( 'yandex' ) );
	}

	public function test_an_unknown_provider_id_resolves_to_null(): void {
		$this->assertNull( ( new Map_Provider_Registry() )->get( 'leaflet' ) );
	}

	public function test_yandex_declares_an_optional_api_key_field_that_is_not_sensitive(): void {
		$fields = ( new Yandex_Map_Provider() )->get_settings_fields();
		$this->assertArrayHasKey( 'map_api_key', $fields );
		$this->assertFalse( $fields['map_api_key']['required'] );
		$this->assertArrayNotHasKey( 'sensitive', $fields['map_api_key'] );
	}
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Map/MapProviderRegistryTest.php --no-coverage`
Expected: FAIL.

- [ ] **Step 4: Implement**

`Yandex_Map_Provider::get_js_config()` builds the ymaps script URL exactly as the reference does
(`includes/class-checkout.php:100-110` in `plugins-reference/woocommerce-yandex-delivery`):
`https://api-maps.yandex.ru/2.1/` with `load=package.standard`, `lang` from the locale restricted to
`ru_RU|en_US|en_RU|ru_UA|uk_UA|tr_TR` (default `ru_RU`), `ns=WoodevPickupMap`, and `apikey`.

The key resolves as: the plugin's `map_api_key` setting if non-empty, otherwise

```php
/**
 * Filters the fallback Yandex Maps API key used when a merchant supplies none.
 *
 * Obtaining a key from Yandex is awkward enough that requiring one would block many
 * merchants outright. This is a filter rather than a constant so the key can be rotated
 * without a framework release.
 *
 * @since 2.0.2
 *
 * @param string $key The fallback API key.
 */
$key = (string) apply_filters( 'woodev_shipping_map_fallback_api_key', '' );
```

`get_settings_fields()` returns the optional field descriptor. **Do not** mark it `sensitive`: the key
travels to the browser inside the script URL and cannot be hidden, so masking is theatre and stops the
merchant seeing what they pasted.

- [ ] **Step 5: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Map/MapProviderRegistryTest.php --no-coverage`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
php bin/generate-class-map.php
git add woodev/ tests/ && git commit -m "refactor(shipping): re-point the Map_Provider seam at map SOURCE, add yandex + embedded"
```

---

## Task 10: Vanilla modal shell (JS)

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/pickup-modal.js`
- Test: `tests/js/pickup-modal.test.js`

- [ ] **Step 1: Write the failing test**

```js
/** @jest-environment jsdom */
require( '../../woodev/shipping-method/assets/js/frontend/pickup-modal.js' )

const Modal = window.WoodevPickupModal

describe( 'pickup modal shell', () => {

	beforeEach( () => { document.body.innerHTML = '<button id="trigger">Выбрать</button>' } )

	test( 'open() renders a dialog with the aria contract', () => {
		const modal = new Modal( { title: 'Пункты выдачи' } )
		modal.open()
		const dialog = document.querySelector( '[role="dialog"]' )
		expect( dialog ).not.toBeNull()
		expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' )
	} )

	test( 'Escape closes it', () => {
		const modal = new Modal( { title: 'x' } )
		modal.open()
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) )
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull()
	} )

	test( 'focus returns to the trigger on close', () => {
		const trigger = document.getElementById( 'trigger' )
		trigger.focus()
		const modal = new Modal( { title: 'x', returnFocusTo: trigger } )
		modal.open()
		modal.close()
		expect( document.activeElement ).toBe( trigger )
	} )

	test( 'getContainer() gives the provider its mount point', () => {
		const modal = new Modal( { title: 'x' } )
		modal.open()
		expect( modal.getContainer() ).not.toBeNull()
	} )

	test( 'showError() replaces the body instead of leaving it blank', () => {
		const modal = new Modal( { title: 'x' } )
		modal.open()
		modal.showError( 'Карта не загрузилась', () => {} )
		expect( document.body.textContent ).toContain( 'Карта не загрузилась' )
	} )
} )
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx jest tests/js/pickup-modal.test.js`
Expected: FAIL — `window.WoodevPickupModal` undefined.

- [ ] **Step 3: Implement**

Plain constructor function on `window.WoodevPickupModal`, no jQuery, no Backbone. Methods: `open()`,
`close()`, `getContainer()`, `showError(message, onRetry)`, `showEmpty(message)`, `destroy()`. Behaviour:
backdrop click closes, Esc closes, Tab is trapped inside the dialog, focus moves to the close button on
open and back to `returnFocusTo` on close, and `document.body` gets a scroll-lock class while open.

- [ ] **Step 4: Run it and watch it pass**

Run: `npx jest tests/js/pickup-modal.test.js`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add woodev/ tests/ && git commit -m "feat(shipping): add the vanilla pickup modal shell"
```

---

## Task 11: `dataSource` (JS)

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/pickup-datasource.js`
- Test: `tests/js/pickup-datasource.test.js`

- [ ] **Step 1: Write the failing test**

```js
/** @jest-environment jsdom */
require( '../../woodev/shipping-method/assets/js/frontend/pickup-datasource.js' )

const create = window.WoodevPickupDataSource

describe( 'pickup dataSource', () => {

	beforeEach( () => {
		global.fetch = jest.fn( () => Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( { points: [ { id: 'P1' }, { id: 'P1' }, { id: 'P2' } ] } )
		} ) )
	} )

	test( 'de-duplicates points by id', async () => {
		const ds = create( { restRoot: '/x', debounceMs: 0 } )
		const points = await ds.fetchPoints( { locality: 'Москва' } )
		expect( points.map( p => p.id ) ).toEqual( [ 'P1', 'P2' ] )
	} )

	test( 'collapses rapid bbox calls into one request', async () => {
		jest.useFakeTimers()
		const ds = create( { restRoot: '/x', debounceMs: 300 } )
		ds.fetchPoints( { bounds: [ 1, 1, 2, 2 ] } )
		ds.fetchPoints( { bounds: [ 1, 1, 2, 3 ] } )
		ds.fetchPoints( { bounds: [ 1, 1, 2, 4 ] } )
		jest.advanceTimersByTime( 350 )
		await Promise.resolve()
		expect( global.fetch ).toHaveBeenCalledTimes( 1 )
		jest.useRealTimers()
	} )

	test( 'a failed response rejects rather than returning an empty list', async () => {
		global.fetch = jest.fn( () => Promise.resolve( { ok: false, status: 500 } ) )
		const ds = create( { restRoot: '/x', debounceMs: 0 } )
		await expect( ds.fetchPoints( { locality: 'X' } ) ).rejects.toBeTruthy()
	} )
} )
```

An empty list on failure would be indistinguishable from "no points here" and would show the customer the
wrong message — hence the explicit rejection.

- [ ] **Step 2: Run it and watch it fail**

Run: `npx jest tests/js/pickup-datasource.test.js`
Expected: FAIL — undefined factory.

- [ ] **Step 3: Implement**

Factory on `window.WoodevPickupDataSource` returning `{ fetchPoints, fetchDetails }`. `fetchPoints`
debounces by `debounceMs` (only the last call in a burst is issued; earlier promises resolve with the
same result), serialises `locality` / `bbox` / `q` into the query string, sends the REST nonce as
`X-WP-Nonce`, de-duplicates by `id`, and rejects on a non-ok response. `fetchDetails` is not debounced.

- [ ] **Step 4: Run it and watch it pass**

Run: `npx jest tests/js/pickup-datasource.test.js`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add woodev/ tests/ && git commit -m "feat(shipping): add the pickup dataSource with debounce and de-dup"
```

---

## Task 12: Mount into the §8 anchor (JS)

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/pickup-mount.js`

- [ ] **Step 1: Read how §8 places the anchor**

Run: `sed -n '520,600p' woodev/shipping-method/assets/js/frontend/checkout-field-classic.js`

The anchor is `[data-woodev-pickup-slot="<fieldId>"]`, created next to the pickup field and shown only
when a pickup method is chosen. It is re-placed on every `updated_checkout`, so anything mounted into it
must be re-mounted then too — mount idempotently, exactly as the fixture's demo button does today
(`tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php:216-229`).

- [ ] **Step 2: Implement**

`pickup-mount.js` must:

1. On `updated_checkout` (deferred ~60 ms so it runs after §8 re-places the anchor), ensure a trigger
   button exists inside the anchor — and do nothing if one is already there.
2. On click: open the shell, resolve the provider from config, `init(container, config, dataSource)`.
3. On `select`: write the point id into the §8 field **through the store**, not by touching the DOM —
   `window.woodevCheckoutFieldStore` (see `checkout-field-store.js`) — then trigger `change` so §8's gate
   recomputes, then close the shell.
4. Apply address replacement when enabled: write `address`, `locality`, `postal_code` into the fieldset
   `Address_Target` chose, again through the store. When the city select has no matching `<option>`, add
   it first — the same fix applied to suggest-takeover in s44 — otherwise the value silently will not take.
5. On provider `error`: `modal.showError()` with a retry that re-runs `init`.

- [ ] **Step 3: Syntax check**

Run: `npx eslint woodev/shipping-method/assets/js/frontend/pickup-mount.js || node --check woodev/shipping-method/assets/js/frontend/pickup-mount.js`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add woodev/ && git commit -m "feat(shipping): mount the pickup trigger into the checkout field-layer anchor"
```

---

## Task 13: Yandex map provider (JS) — map, clustering, balloon

Port from `plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`.
Read that file before starting. Do **not** copy it verbatim: it reads points from its own AJAX layer,
whereas this one takes the `dataSource` handed to `init()`.

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/map-provider-yandex.js`

- [ ] **Step 1: Implement the contract skeleton**

```js
;( function ( $, wp ) {
	'use strict'

	function WoodevYandexMapProvider() {
		this.ymaps      = null
		this.map        = null
		this.clusterer  = null
		this.dataSource = null
		this.config     = null
		this.handlers   = { select: [], error: [] }
		this.knownIds   = {}
	}

	WoodevYandexMapProvider.prototype.init = function ( container, config, dataSource ) { /* Step 2 */ }
	WoodevYandexMapProvider.prototype.on = function ( event, cb ) {
		if ( this.handlers[ event ] ) { this.handlers[ event ].push( cb ) }
	}
	WoodevYandexMapProvider.prototype.emit = function ( event, payload ) {
		( this.handlers[ event ] || [] ).forEach( function ( cb ) { cb( payload ) } )
	}
	WoodevYandexMapProvider.prototype.destroy = function () { /* Step 5 */ }

	window.WoodevPickupMapProviders = window.WoodevPickupMapProviders || {}
	window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider

} )( jQuery, wp )
```

- [ ] **Step 2: `init()` — map, clusterer, controls**

Await `window[ config.ns ].ready()`, create the map centred on `config.center` at zoom 12 with
`maxZoom: config.maxZoom`. Add, mirroring the reference (lines 60-125):

- `ymaps.Clusterer` with `clusterIconColor: '#FCE000'` and a custom `clusterBalloonContentLayout`;
- `ymaps.control.ZoomControl` at left/bottom 70;
- `ymaps.control.SearchControl` with a geocode provider bounded by `clusterer.getBounds()` and
  `strictBounds: true`, `noPlacemark: true`, `resultsPerPage: 10`, placeholder from `config.i18n.search`;
- a type-filter control **only when** `config.pointTypes.length > 1`;
- `map.margin.addArea({ top: 0, left: 0, width: '100%', height: '64px' })` so controls do not overlap.

Then load points according to `config.strategy`:

- `bulk` → `dataSource.fetchPoints({ locality: config.locality })` once, then
  `map.setBounds( clusterer.getBounds() )`;
- `viewport` → fetch for the current bounds, and re-fetch on `boundschange` (the dataSource already
  debounces). Merge into `knownIds` so panning back does not duplicate placemarks.

Any rejection from the dataSource → `this.emit( 'error', { code: 'fetch', message: … } )`.

- [ ] **Step 3: The balloon**

Build it with `ymaps.templateLayoutFactory.createClass`, rendering the fields listed in spec §4.4 and
following the reference balloon in `templates/html-modal-map.php`: name, postal code, address,
collapsible "Как добраться", payment methods, phone, weight limit.

The footer button is driven by `point.selectable`: when `selectable.allowed` is false, render
`selectable.reason` as a warning and set the button `disabled`. Do not re-derive the verdict on the
client — the server already computed it.

Clicking the enabled button → `this.emit( 'select', point )`.

Under `viewport` strategy, opening a balloon first calls `dataSource.fetchDetails( point.id )` and
re-renders with the fuller point (including a possibly changed `selectable`).

- [ ] **Step 4: The viewport-synced drawer**

The reference's best idea (lines 290-375): a list docked top-right as a map control, holding only the
points currently visible. Add the drawer control, then on `boundschange` recompute its contents with
`ymaps.geoQuery( clusterer.getGeoObjects() ).searchInside( map )`. Clicking an item pans to that
placemark and opens its balloon. Below 782px render it as a bottom sheet instead.

- [ ] **Step 5: `destroy()`**

Remove the clusterer, destroy the map, drop control references and clear `knownIds`, so re-opening the
modal starts clean.

- [ ] **Step 6: Commit**

```bash
git add woodev/ && git commit -m "feat(shipping): add the Yandex map provider (clustering, viewport drawer, balloon)"
```

---

## Task 14: Embedded provider (JS)

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js`

- [ ] **Step 1: Implement**

Same contract, far smaller: `init()` injects the carrier's widget or an `<iframe>` (URL from
`config.embedUrl`) into the container and listens for the carrier's selection signal — either a
`postMessage` whose origin is checked against `config.embedOrigin`, or a callback the carrier's widget
invokes. Normalize whatever comes back into the framework's point shape before emitting `select`; a
payload that cannot be normalized emits `error` instead.

`destroy()` removes the listener and empties the container.

- [ ] **Step 2: Commit**

```bash
git add woodev/ && git commit -m "feat(shipping): add the embedded (carrier widget/iframe) map provider"
```

---

## Task 15: Styles

**Files:**
- Create: `woodev/shipping-method/assets/css/frontend/pickup.css`

- [ ] **Step 1: Write the styles**

Cover: the shell (backdrop, panel, header, close button, body), the error and empty states, the drawer
(docked panel, list items, active item), the balloon sections, and the mobile breakpoint at 782px where
the panel goes full-screen and the drawer and balloon become bottom sheets with a drag handle.

Prefix every class `woodev-pickup-`. Do not reuse the deleted `pickup-map.css` class names.

- [ ] **Step 2: Commit**

```bash
git add woodev/ && git commit -m "feat(shipping): add pickup modal, drawer and balloon styles"
```

---

## Task 16: Auto-register the map API key setting

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Add the failing test**

```php
public function test_the_map_api_key_field_is_registered_when_the_yandex_provider_is_active(): void {
	Functions\when( 'apply_filters' )->returnArg( 2 );
	$fields = ( new Pickup_Handler( 'p', 'carrier_pickup_point', 'bulk', 'yandex' ) )->get_settings_fields();
	$this->assertArrayHasKey( 'map_api_key', $fields );
}

public function test_no_map_api_key_field_for_the_embedded_provider(): void {
	Functions\when( 'apply_filters' )->returnArg( 2 );
	$fields = ( new Pickup_Handler( 'p', 'carrier_pickup_point', 'bulk', 'embedded' ) )->get_settings_fields();
	$this->assertArrayNotHasKey( 'map_api_key', $fields );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: FAIL — `get_settings_fields()` undefined.

- [ ] **Step 3: Implement**

`Pickup_Handler::get_settings_fields()` delegates to the active provider's `get_settings_fields()`, so a
plugin using the map with the Yandex provider automatically gains the optional key field and one using the
embedded provider does not.

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add woodev/ tests/ && git commit -m "feat(shipping): auto-register the optional map API key setting per provider"
```

---

## Task 17: Address replacement toggle

**Files:**
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php`
- Test: `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

- [ ] **Step 1: Add the failing test**

```php
public function test_address_replacement_is_on_by_default_and_can_be_disabled(): void {
	Functions\when( 'apply_filters' )->returnArg( 2 );
	Functions\when( 'rest_url' )->justReturn( 'https://example.test/' );
	Functions\when( 'wp_create_nonce' )->justReturn( 'N' );
	Functions\when( 'get_option' )->justReturn( 'billing' );

	$on = new Pickup_Handler( 'p', 'carrier_pickup_point', 'bulk', 'yandex' );
	$this->assertTrue( $on->get_js_config()['replaceAddress']['enabled'] );
	$this->assertSame( 'billing', $on->get_js_config()['replaceAddress']['target'] );

	$off = new Pickup_Handler( 'p', 'carrier_pickup_point', 'bulk', 'yandex', false );
	$this->assertFalse( $off->get_js_config()['replaceAddress']['enabled'] );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: FAIL.

- [ ] **Step 3: Implement**

Add a `$replace_address = true` constructor parameter; emit
`replaceAddress => [ 'enabled' => bool, 'target' => Address_Target::resolve( … ) ]` in the JS config.
`pickup-mount.js` (Task 12) already reads it.

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/phpunit tests/unit/Shipping/Pickup/PickupHandlerTest.php --no-coverage`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add woodev/ tests/ && git commit -m "feat(shipping): make pickup address replacement switchable"
```

---

## Task 18: Fixture — both strategies

**Files:**
- Modify: `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php`
- Test: `tests/integration/Shipping/PickupRouteTest.php` (from Task 7)

- [ ] **Step 1: Implement two point sources**

Replace the demo stub button (lines ~210-232) with the real handler, and add two `Point_Source`
implementations over static data:

- `Woodev_Test_Bulk_Point_Source` — `STRATEGY_BULK`, returns 5 Moscow points for `locality`, all fields
  populated including `accepts_cod` (one point `false`, to exercise COD gating) and `max_weight` (one
  point 1000 g, to exercise the weight rule).
- `Woodev_Test_Viewport_Point_Source` — `STRATEGY_VIEWPORT`, returns only the points inside the requested
  bbox, and returns a **sparse** point from `fetch_points()` (no `accepts_cod`, no `max_weight`) with the
  full record only from `fetch_details()`. This is what proves the lazy-detail path and the verdict
  recomputation.

Pick the active source with a `WOODEV_TEST_PICKUP_STRATEGY` constant defaulting to bulk, so the rig can
switch without code edits.

- [ ] **Step 2: Run the integration suite**

Run the wp-env command from "Before you start".
Expected: PASS, including the three `PickupRouteTest` cases from Task 7.

- [ ] **Step 3: Commit**

```bash
git add tests/ && git commit -m "test(shipping): fixture point sources for both loading strategies"
```

---

## Task 19: Full verification and rig e2e

- [ ] **Step 1: Whole suite**

```bash
composer test:unit && composer phpcs && npx jest
```
Expected: unit ≥1026 + the new tests, phpcs clean, all jest suites pass.

- [ ] **Step 2: Integration**

Run the wp-env command from "Before you start".
Expected: all green.

- [ ] **Step 3: Class map is current**

```bash
php bin/generate-class-map.php && git diff --exit-code woodev/class-map.php
```
Expected: no diff. A dirty diff here means a class was added without regenerating.

- [ ] **Step 4: Rig e2e — bulk strategy**

With chrome-devtools MCP on `http://localhost:8973`, add product #12 to the cart, open
`/classic-checkout/`, choose country RU, region and city, then select the `woodev_test_shipping` method.
Verify in order:

1. the pickup trigger appears in the anchor;
2. clicking it opens the modal and the map renders with clustered points;
3. the drawer lists only the points in view, and panning changes the list;
4. clicking a point opens the balloon with address, payment methods and the CTA;
5. choosing COD and opening the point with `accepts_cod: false` disables the CTA and shows the reason;
6. the 1000 g point is refused for the current cart weight with the limit named;
7. selecting a valid point releases the A2 gate;
8. the address fields are updated and the values **survive `update_checkout`** (change the payment
   method and re-check);
9. **the order is placed** and carries the point id and the full point in meta.

- [ ] **Step 5: Rig e2e — viewport strategy**

Set `WOODEV_TEST_PICKUP_STRATEGY` to viewport, reload, and verify that panning issues new bbox requests
(check `list_network_requests`), that details load when a balloon opens, and that the verdict appears
after the detail fetch.

- [ ] **Step 6: Rig e2e — server authority**

Re-enable `#place_order` from the console and submit with a constraint-violating point. Expect
`{"result":"failure"}` from `?wc-ajax=checkout` and no order created.

- [ ] **Step 7: Codex review**

Bundle the diff into ≤~12 KB chunks (a single bundle over ~12 KB stalls) and run:

```bash
node ~/.claude/plugins/cache/openai-codex/codex/1.0.4/scripts/codex-companion.mjs task "$(cat bundle.md)" --json
```

Verify every finding against the source before acting — in s44 one of six was wrong on mechanics and two
were unreachable. Then **re-critic your own fixes** before committing them.

- [ ] **Step 8: PR and merge**

Open the PR, confirm **each** CI job passes and `mergeStateStatus` is `CLEAN` as a separate step, then
`gh pr merge <N> --squash --delete-branch`. Never `--auto`.

---

## Self-review

**Spec coverage.** §2 deletions → Task 1. §4.2 components → Tasks 2-9. §4.3 seam → Task 9 (PHP) + 13/14
(JS). §4.4 point → Task 2. §4.5 constraints incl. viewport timing → Tasks 5, 7, 13 step 3. §4.6 address →
Tasks 6, 12, 17. §4.7 key → Tasks 9, 16. §4.8 shell + mobile → Tasks 10, 15. §4.9 degradation → Tasks 10
(`showError`/`showEmpty`), 11 (reject not empty), 12 (retry), 13 (emit error). §4.10 caching → no task by
design; it is the plugin's. §5 REST → Task 7. §6 persistence → Task 8. §7 testing → every task + Task 19.

**Naming consistency.** `Pickup_Point`, `Point_Query`, `Point_Source`, `Constraint_Checker`,
`Address_Target`, `Pickup_Handler`, `Pickup_Controller`, `Map_Provider_Registry`, `Yandex_Map_Provider`,
`Embedded_Map_Provider`; JS globals `WoodevPickupModal`, `WoodevPickupDataSource`,
`WoodevPickupMapProviders.{yandex,embedded}`. `selectable: {allowed, reason}` is the single verdict shape
across Tasks 5, 7 and 13.

**Known ordering wrinkle.** Task 7's integration tests need Task 18's fixture; this is called out in Task 7
step 6 rather than reordered, because the controller should still be built and unit-tested first.

<?php
/**
 * Plugin Name: Woodev Test Shipping Method
 * Description: Fixture shipping method for Woodev Framework testing. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-test-shipping-method
 *
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

/**
 * SP-5 Task 18: picks which fixture Point_Source (and loading strategy) the rig
 * exercises — 'bulk' (Woodev_Test_Bulk_Point_Source, all points at once) or
 * 'viewport' (Woodev_Test_Viewport_Point_Source, bbox + lazy details). Flip this
 * to 'viewport' — e.g. via a `define( 'WOODEV_TEST_PICKUP_STRATEGY', 'viewport' );`
 * in wp-config.php, or the `.wp-env.json` `config` block — and reload the checkout
 * to switch strategy with no code edit. Defaults to 'bulk'.
 */
if ( ! defined( 'WOODEV_TEST_PICKUP_STRATEGY' ) ) {
	define( 'WOODEV_TEST_PICKUP_STRATEGY', 'bulk' );
}

/**
 * Issue #185: opt-in switch to the LIVE Yandex.Delivery sandbox Point_Source
 * (`Woodev_Test_Live_Yandex_Point_Source`, calling Yandex's own TEST host) instead of the
 * static fixture data. Defaults to `false` — neither the unit suite, the integration suite,
 * nor CI ever defines this, so no test makes a network call. Flip via
 * `define( 'WOODEV_TEST_PICKUP_LIVE_YANDEX', true );` in wp-config.php or the `.wp-env.json`
 * `config` block, same idiom as `WOODEV_TEST_PICKUP_STRATEGY` above. Yandex only exposes a
 * bulk/geo_id-addressed endpoint, so when this is on it WINS over `WOODEV_TEST_PICKUP_STRATEGY`
 * regardless of that constant's value — see the point-source selection below.
 */
if ( ! defined( 'WOODEV_TEST_PICKUP_LIVE_YANDEX' ) ) {
	define( 'WOODEV_TEST_PICKUP_LIVE_YANDEX', false );
}

/**
 * Issue #226: opt-in switch to the LIVE Russian Post (Почта РФ) widget-API viewport
 * Point_Source (`Woodev_Test_Live_Pochta_Point_Source`, calling `widget.pochta.ru`) instead of
 * the static fixture data. Defaults to `false` — neither the unit suite, the integration
 * suite, nor CI ever defines this, so no test makes a network call. Flip via
 * `define( 'WOODEV_TEST_PICKUP_LIVE_POCHTA', true );` in wp-config.php or the `.wp-env.json`
 * `config` block, same idiom as the two constants above.
 *
 * PRECEDENCE (documented, not merely arbitrary — see the source-selection block below):
 * `WOODEV_TEST_PICKUP_LIVE_YANDEX` still wins when BOTH live switches are truthy at once. That
 * combination is a misconfiguration this fixture does not try to prevent (there is only one
 * map on the rig at a time regardless), only to resolve deterministically without ever
 * constructing two live sources — Yandex keeps its existing precedence for backward
 * compatibility, since it was the first live switch and nothing before now depended on Pochta
 * outranking it. When only THIS switch is truthy, it wins over `WOODEV_TEST_PICKUP_STRATEGY`
 * the same way Yandex does: Pochta's real API is viewport-only, so "live Pochta + bulk
 * strategy" is not a combination that exists to choose between.
 */
if ( ! defined( 'WOODEV_TEST_PICKUP_LIVE_POCHTA' ) ) {
	define( 'WOODEV_TEST_PICKUP_LIVE_POCHTA', false );
}

/**
 * Issue #251: opt-in switch to the carrier-EMBEDDED (widget/iframe) `Map_Provider`
 * (`Embedded_Map_Provider`, wrapping the live Почта России widget at
 * `https://widget.pochta.ru/map/`) instead of the framework's own Yandex map.
 * Defaults to `false`. Flip via `define( 'WOODEV_TEST_PICKUP_EMBEDDED', true );`
 * in wp-config.php or the `.wp-env.json` `config` block, same idiom as the
 * constants above.
 *
 * PRECEDENCE: wins over EVERY switch above, including `WOODEV_TEST_PICKUP_LIVE_YANDEX`/
 * `WOODEV_TEST_PICKUP_LIVE_POCHTA` — those two only choose a `Point_Source`, and under
 * `Embedded_Map_Provider` the `Point_Source`'s LISTING half is irrelevant: the provider
 * owns the whole container (`ownsChrome`) and the carrier's own embed fetches and renders
 * its own points, so `fetch_points()` is never actually consulted by anything on the page.
 *
 * CORRECTED (F4, rig finding, s63): the `Point_Source`'s DETAILS half is NOT irrelevant —
 * an earlier version of this comment claimed the whole selection below was "a dead-looking
 * branch... never actually consulted", which was WRONG and is the reason the rig's
 * confirmation round trip 404'd on every real Почта selection. `Pickup_Controller::
 * handle_select_request()` always re-fetches the customer's chosen point id via
 * `Point_Source::fetch_details()`, regardless of which map provider produced that id —
 * that call is provider-agnostic and is the framework's one AUTHORITATIVE gate (see
 * `map-provider-embedded.js`'s own "AUTHORITY for this path" docblock section). A real
 * Почта id (e.g. `"43213"`) is unknown to every OTHER fixture `Point_Source`, so
 * `WOODEV_TEST_PICKUP_EMBEDDED` swaps `$point_source` for
 * `Woodev_Test_Embedded_Confirm_Point_Source` — a rig-only stub whose `fetch_details()`
 * accepts ANY id — below, so the round trip can actually complete. See that class's own
 * docblock for the obligation a REAL embedded-carrier plugin has instead.
 */
if ( ! defined( 'WOODEV_TEST_PICKUP_EMBEDDED' ) ) {
	define( 'WOODEV_TEST_PICKUP_EMBEDDED', false );
}

/**
 * Issue #251: the two-level close/refresh contract the rig has exercised only ONE
 * side of until now — the stock config below is `close_on_select = true`,
 * `refresh_checkout = false`, which makes the "modal stays open after a confirmed
 * selection" path (see `pickup-mount.js`) unreachable from this fixture without a
 * live in-browser patch (done by hand in s62). These two constants make both sides
 * configurable without a code edit, same idiom as the constants above — flip
 * `WOODEV_TEST_PICKUP_SELECTION_CLOSE` to `false` to reach that path. Defaults
 * preserve today's stock rig behaviour exactly.
 */
if ( ! defined( 'WOODEV_TEST_PICKUP_SELECTION_CLOSE' ) ) {
	define( 'WOODEV_TEST_PICKUP_SELECTION_CLOSE', true );
}

if ( ! defined( 'WOODEV_TEST_PICKUP_SELECTION_REFRESH_CHECKOUT' ) ) {
	define( 'WOODEV_TEST_PICKUP_SELECTION_REFRESH_CHECKOUT', false );
}

/**
 * Определяем корневую директорию фреймворка.
 *
 * В wp-env контейнере: WOODEV_FRAMEWORK_DIR задаётся через config в .wp-env.json
 * и записывается в wp-config.php. Путь внутри контейнера: /var/www/html/woodev-framework
 *
 * Локально (unit-тесты): поднимаемся на два уровня из tests/_fixtures/woodev-test-shipping-method/
 * к корню проекта, где лежит папка woodev/.
 */
if ( defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
	$framework_dir = WOODEV_FRAMEWORK_DIR;
} else {
	$framework_dir = dirname( __DIR__, 2 );
}

$framework_bootstrap = $framework_dir . '/woodev/bootstrap.php';

if ( ! file_exists( $framework_bootstrap ) ) {
	return;
}

if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
	require_once $framework_bootstrap;
}

/**
 * Возвращает явное определение загрузчика Platform v2 для тестового метода доставки.
 *
 * @return array<string,mixed>
 */
function woodev_test_shipping_method_plugin_loader_definition(): array {
	return [
		'plugin_id'         => 'woodev-test-shipping-method',
		'plugin_name'       => 'Woodev Test Shipping Method Plugin',
		'plugin_version'    => '1.0.0',
		'framework_version' => '1.4.0',
		'plugin_file'       => __FILE__,
		'platform'          => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
		'requirements'      => [
			'php'         => '7.4',
			'wordpress'   => '6.3',
			'woocommerce' => '7.0',
		],
		'main_class'        => 'Woodev_Test_Shipping_Method_Plugin',
		'callback'          => 'woodev_test_shipping_method_plugin_init',
	];
}

/**
 * Best-effort resolves the display name of the plugin whose outdated (v1) framework
 * copy won the Woodev_Plugin_Bootstrap class rendezvous (B-1 / OB-1).
 *
 * Runs only on the mixed-fleet dormant path, so it relies on WordPress core +
 * reflection alone and never references a framework class — the loaded runtime is the
 * legacy v1 copy. Returns '' when the owner cannot be determined; the caller then
 * falls back to generic wording.
 *
 * @return string Conflicting plugin display name, or '' if undeterminable.
 */
function woodev_test_shipping_method_conflicting_framework_plugin_name(): string {
	if ( ! class_exists( 'Woodev_Plugin_Bootstrap', false ) || ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'wp_normalize_path' ) || ! function_exists( 'get_plugins' ) ) {
		return '';
	}

	try {
		$framework_file = ( new ReflectionClass( 'Woodev_Plugin_Bootstrap' ) )->getFileName();
	} catch ( ReflectionException $e ) {
		return '';
	}

	$plugins_dir = constant( 'WP_PLUGIN_DIR' );

	if ( ! is_string( $framework_file ) || '' === $framework_file || ! is_string( $plugins_dir ) || '' === $plugins_dir ) {
		return '';
	}

	$framework_file = wp_normalize_path( $framework_file );
	$plugins_dir    = wp_normalize_path( $plugins_dir );

	if ( 0 !== strpos( $framework_file, $plugins_dir . '/' ) ) {
		return '';
	}

	$relative = ltrim( substr( $framework_file, strlen( $plugins_dir ) ), '/' );
	$slug     = strstr( $relative . '/', '/', true );

	if ( ! is_string( $slug ) || '' === $slug ) {
		return '';
	}

	foreach ( get_plugins() as $plugin_file => $plugin_data ) {
		if ( 0 === strpos( (string) $plugin_file, $slug . '/' ) && ! empty( $plugin_data['Name'] ) ) {
			return (string) $plugin_data['Name'];
		}
	}

	return '';
}

/**
 * Регистрируем тестовый плагин метода доставки в бутстрапе фреймворка.
 *
 * Mixed-fleet probe (B-1): на сайте, где соседствуют v2-переписанный и ещё-v1 плагин,
 * WordPress грузит плагины по алфавиту, и первая vendored-копия, определившая
 * Woodev_Plugin_Bootstrap, выигрывает rendezvous. Если выиграла легаси (v1) копия — у неё
 * нет register_loader_definition(). Зондируем метод: если его нет, остаёмся в спячке,
 * показываем предупреждение и выходим — никакого фатала.
 */
$woodev_test_shipping_method_bootstrap = Woodev_Plugin_Bootstrap::instance();
if ( ! method_exists( $woodev_test_shipping_method_bootstrap, 'register_loader_definition' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			$this_plugin_name = esc_html__( 'Woodev Test Shipping Method Plugin', 'woodev-plugin-framework' );

			// Best-effort: name the plugin whose outdated (v1) framework copy won the class
			// rendezvous. Uses ONLY WordPress core + reflection — never a framework class,
			// because here the loaded framework runtime is the legacy v1 copy (B-1 / OB-1).
			$conflicting_plugin_name = woodev_test_shipping_method_conflicting_framework_plugin_name();

			if ( '' !== $conflicting_plugin_name ) {
				$message = sprintf(
					/* translators: 1: this plugin name, 2: the conflicting plugin name. */
					esc_html__( 'Плагин %1$s не запущен: на сайте активен плагин %2$s с устаревшей версией фреймворка Woodev, которая мешает его загрузке. Обновите плагин %2$s до последней версии.', 'woodev-plugin-framework' ),
					'<strong>' . $this_plugin_name . '</strong>',
					'<strong>' . esc_html( $conflicting_plugin_name ) . '</strong>'
				);
			} else {
				$message = sprintf(
					/* translators: %s — this plugin name. */
					esc_html__( 'Плагин %s не запущен: на сайте активен другой плагин Woodev с устаревшей версией фреймворка. Обновите все плагины Woodev до последней версии.', 'woodev-plugin-framework' ),
					'<strong>' . $this_plugin_name . '</strong>'
				);
			}

			echo '<div class="error"><p>';
			echo wp_kses( $message, [ 'strong' => [] ] );
			echo '</p></div>';
		}
	);
	return;
}
$woodev_test_shipping_method_bootstrap->register_loader_definition( woodev_test_shipping_method_plugin_loader_definition() );

/**
 * Фабричная функция — инициализирует тестовый плагин метода доставки.
 */
function woodev_test_shipping_method_plugin_init(): void {

	// -----------------------------------------------------------------------
	// SP-5 Task 18: fixture Point_Source implementations over static data,
	// exercising both loading strategies (§4.5). A real shipping plugin talks to
	// its carrier's API here instead of returning hardcoded arrays.
	//
	// Required/declared HERE (inside this callback), not at file top level: this
	// callback runs only once the bootstrap has selected the highest-version
	// framework copy and its autoloader is registered — declaring a class that
	// `implements \Woodev\Framework\Shipping\Pickup\Point_Source` any earlier
	// than that fails with "Interface ... not found", the same reason the main
	// plugin class below is declared inside this callback rather than at file
	// top level.
	//
	// Woodev_Test_Bulk_Point_Source lives in its own file (see the docblock there)
	// specifically so a unit test can `require_once` it directly, bypassing the
	// Woodev_Plugin_Bootstrap singleton's process-wide, one-shot load latch.
	// -----------------------------------------------------------------------

	require_once __DIR__ . '/class-test-bulk-point-source.php';

	// Issue #185: declared unconditionally, same reasoning as Woodev_Test_Viewport_Point_Source
	// just below — declaring a class is free (no I/O), only INSTANTIATING it below does
	// anything, and that only happens when WOODEV_TEST_PICKUP_LIVE_YANDEX is truthy.
	require_once __DIR__ . '/class-test-live-yandex-point-source.php';

	// Issue #226: same reasoning as the live Yandex require just above — only INSTANTIATING it
	// below does anything, and that only happens when WOODEV_TEST_PICKUP_LIVE_POCHTA is truthy.
	require_once __DIR__ . '/class-test-live-pochta-point-source.php';

	// Issue #251 (F4, rig finding): the confirmation-round-trip stub — see its own file
	// docblock. Required unconditionally, same reasoning as the two requires just above:
	// declaring the class is free, only INSTANTIATING it (below, when WOODEV_TEST_PICKUP_EMBEDDED
	// is truthy) does anything.
	require_once __DIR__ . '/class-test-embedded-confirm-point-source.php';

	// Issue #176: the pickup-selection-persistence fixture scope, passed as the
	// Pickup_Handler constructor's own 15th argument below. Required unconditionally,
	// same reasoning as the requires just above.
	require_once __DIR__ . '/class-test-selection-scope.php';

	// Location Provider layer, block PR-C rig-visibility pull-forward: this fixture's
	// Location_Adapter (mandatory obligation for any plugin opting into the layer) and
	// the rig-only DaData credential bridge. Required unconditionally, same reasoning as
	// the requires just above — declaring these classes is free, only using them below
	// does anything.
	require_once __DIR__ . '/class-test-location-adapter.php';
	require_once __DIR__ . '/class-test-credential-seeder.php';

	// Task 13: the `list`-capable fixture provider — the bundled DaData provider has no
	// `list` capability at all, so tests and the rig need a fake that DOES enumerate to
	// exercise `related-list`/`ajax-select2` field modes. Required unconditionally, same
	// reasoning as the two requires just above.
	require_once __DIR__ . '/class-test-list-location-provider.php';

	// Registers the fixture provider alongside the bundled DaData one — NOT made active
	// by default (the store's `active_provider` setting still defaults to `dadata`), an
	// operator opts in explicitly on the "Локация" settings page to see `related-list`/
	// `ajax-select2` on the rig. Safe to register the closure here regardless of the
	// bootstrap's own loading stage (see the `woodev_shipping_pickup_point_selection`
	// filter registration further down in this file for the identical reasoning:
	// registering a callback does not INVOKE it).
	add_filter(
		\Woodev\Framework\Shipping\Location\Location_Provider_Registry::FILTER_PROVIDERS,
		static function ( array $providers ): array {
			$providers[] = new Woodev_Test_List_Location_Provider();

			return $providers;
		}
	);

	if ( ! class_exists( 'Woodev_Test_Viewport_Point_Source' ) ) {

		/**
		 * Class Woodev_Test_Viewport_Point_Source
		 *
		 * STRATEGY_VIEWPORT fixture source (OZON/Pochta shape): `fetch_points()` is
		 * queried per visible bounding box and returns SPARSE points — no
		 * `accepts_cod`, no `max_weight`, exactly what a carrier's list response
		 * frequently omits (spec §4.5). The full record, including both constraint
		 * inputs, is available only from `fetch_details()` — proving the lazy-detail
		 * path and the server-side verdict recomputation on balloon-open.
		 */
		class Woodev_Test_Viewport_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

			/**
			 * Point id whose full record refuses COD — only visible after fetch_details().
			 * The sparse fetch_points() entry carries no accepts_cod, so it is emitted as
			 * selectable (unknown is permissive) until the balloon opens and re-checks.
			 */
			public const COD_REFUSING_POINT_ID = 'FIX-VIEW-2';

			/**
			 * @inheritDoc
			 */
			public function get_strategy(): string {
				return self::STRATEGY_VIEWPORT;
			}

			/**
			 * Returns the points inside the requested bbox, sparse (no accepts_cod, no
			 * max_weight — see class docblock).
			 *
			 * @inheritDoc
			 */
			public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
				// Guaranteed non-null for a STRATEGY_VIEWPORT source — see the Point_Source
				// interface docblock.
				[ $min_lat, $min_lng, $max_lat, $max_lng ] = $query->get_bounds();

				$sparse = [];

				foreach ( $this->all_points() as $payload ) {
					if ( $payload['lat'] < $min_lat || $payload['lat'] > $max_lat
						|| $payload['lng'] < $min_lng || $payload['lng'] > $max_lng ) {
						continue;
					}

					// Strip the constraint inputs — this is what makes the list response
					// SPARSE, mirroring a carrier whose bbox listing omits them.
					unset( $payload['accepts_cod'], $payload['max_weight'] );

					$sparse[] = $payload;
				}

				return array_values( array_filter( array_map(
					[ \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, 'from_array' ],
					$sparse
				) ) );
			}

			/**
			 * Returns the full record for one point, including accepts_cod/max_weight.
			 *
			 * @inheritDoc
			 */
			public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
				foreach ( $this->all_points() as $payload ) {
					if ( $point_id === $payload['id'] ) {
						return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array( $payload );
					}
				}

				return null;
			}

			/**
			 * The static viewport points, FULL record (fetch_points() sparsifies a copy).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function all_points(): array {
				return [
					[
						'id'              => 'FIX-VIEW-1',
						'name'            => 'ПВЗ «Маросейка»',
						'lat'             => 55.7601,
						'lng'             => 37.6367,
						'address'         => 'Москва, ул. Маросейка, д. 3',
						'short_address'   => 'Маросейка, 3',
						'locality'        => 'Москва',
						'postal_code'     => '101000',
						'phone'           => '+7 495 200-00-01',
						'instruction'     => 'Домофон 1В.',
						'work_time'       => 'Пн-Вс 09:00-21:00',
						'payment_methods' => [ 'card', 'cod' ],
						'photos'          => [],
						'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
						'accepts_cod'     => true,
						'max_weight'      => null,
					],
					[
						'id'              => self::COD_REFUSING_POINT_ID,
						'name'            => 'ПВЗ «Сокольники — детали по запросу»',
						'lat'             => 55.7887,
						'lng'             => 37.6789,
						'address'         => 'Москва, ул. Сокольническая Слободка, д. 2',
						'short_address'   => 'Сокольническая Слободка, 2',
						'locality'        => 'Москва',
						'postal_code'     => '107014',
						'phone'           => '+7 495 200-00-02',
						'instruction'     => 'Только предоплата.',
						'work_time'       => 'Пн-Сб 10:00-20:00',
						'payment_methods' => [ 'card' ],
						'photos'          => [],
						'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
						'accepts_cod'     => false,
						'max_weight'      => null,
					],
					[
						// Outside the demo viewport used on the rig — proves bbox filtering.
						'id'              => 'FIX-VIEW-3',
						'name'            => 'ПВЗ «Бутово»',
						'lat'             => 55.5450,
						'lng'             => 37.5270,
						'address'         => 'Москва, ул. Скобелевская, д. 20',
						'short_address'   => 'Скобелевская, 20',
						'locality'        => 'Москва',
						'postal_code'     => '117042',
						'phone'           => '+7 495 200-00-03',
						'instruction'     => '',
						'work_time'       => 'Пн-Вс 09:00-21:00',
						'payment_methods' => [ 'card', 'cod' ],
						'photos'          => [],
						'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
						'accepts_cod'     => true,
						'max_weight'      => null,
					],
				];
			}
		}
	}

	if ( ! class_exists( 'Woodev_Test_Shipping_Method_Plugin' ) ) {

		/**
		 * Class Woodev_Test_Shipping_Method_Plugin
		 */
		final class Woodev_Test_Shipping_Method_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

			/** @var Woodev_Test_Shipping_Method_Plugin|null единственный экземпляр */
			protected static $instance;

			/** @var string уникальный идентификатор плагина */
			const PLUGIN_ID = 'woodev-test-shipping-method';

			/** @var string версия плагина */
			const VERSION = '1.0.0';

			/**
			 * SP-5 Task 18: the real pickup-point handler, replacing the pre-SP-5 demo
			 * stub button.
			 *
			 * @since 2.0.2
			 *
			 * @var \Woodev\Framework\Shipping\Pickup\Pickup_Handler
			 */
			private \Woodev\Framework\Shipping\Pickup\Pickup_Handler $pickup_handler;

			/**
			 * Конструктор.
			 */
			public function __construct() {
				parent::__construct(
					self::PLUGIN_ID,
					self::VERSION,
					[
						'text_domain'      => 'woodev-test-shipping-method',
						'shipping_methods' => [
							'woodev_test_shipping' => 'Woodev_Test_Shipping_Method',
						],
					]
				);

				// SP-5 Task 18: WOODEV_TEST_PICKUP_STRATEGY (defined near the top of this
				// file) selects which fixture Point_Source is active, so the rig can
				// switch loading strategy without a code edit.
				//
				// Issue #185: WOODEV_TEST_PICKUP_LIVE_YANDEX (also defined near the top of
				// this file, default false) wins over everything below when truthy — Yandex
				// only offers a bulk/geo_id-addressed endpoint, so "live Yandex" and
				// "viewport" are not a combination that exists to choose between, and it
				// keeps its precedence over Pochta too (see that constant's own docblock).
				//
				// Issue #226: WOODEV_TEST_PICKUP_LIVE_POCHTA wins over WOODEV_TEST_PICKUP_STRATEGY
				// when truthy, same reasoning as Yandex above but for the opposite strategy —
				// Pochta's real API is viewport-only.
				//
				// Issue #251 (F4 correction, s63): this block's LISTING choice (fetch_points())
				// is genuinely irrelevant once WOODEV_TEST_PICKUP_EMBEDDED overrides
				// $point_source below — the carrier's own embed fetches and renders its own
				// points, and this framework never calls fetch_points() on that path. It is
				// still resolved unconditionally rather than skipped, so this block's own
				// precedence rules stay simple. BUT the override below is NOT about a dead
				// value — see WOODEV_TEST_PICKUP_EMBEDDED's own (corrected) docblock: the
				// DETAILS half (fetch_details()) is very much consulted, by the provider-
				// agnostic confirmation round trip, which is exactly why the override exists.
				$viewport_strategy = \Woodev\Framework\Shipping\Pickup\Point_Source::STRATEGY_VIEWPORT;

				if ( WOODEV_TEST_PICKUP_LIVE_YANDEX ) {
					$point_source = new \Woodev_Test_Live_Yandex_Point_Source();
				} elseif ( WOODEV_TEST_PICKUP_LIVE_POCHTA ) {
					$point_source = new \Woodev_Test_Live_Pochta_Point_Source();
				} else {
					$point_source = ( $viewport_strategy === WOODEV_TEST_PICKUP_STRATEGY )
						? new \Woodev_Test_Viewport_Point_Source()
						: new \Woodev_Test_Bulk_Point_Source();
				}

				// Issue #251 (F4, rig finding): WOODEV_TEST_PICKUP_EMBEDDED overrides whatever
				// was just resolved above with a stub whose fetch_details() accepts ANY id — see
				// Woodev_Test_Embedded_Confirm_Point_Source's own docblock for why this is
				// required (a real Почта selection's id is unknown to every OTHER Point_Source
				// this fixture has) and for the obligation a REAL embedded-carrier plugin has
				// instead (a genuine carrier details lookup, not a fabricated record).
				if ( WOODEV_TEST_PICKUP_EMBEDDED ) {
					$point_source = new \Woodev_Test_Embedded_Confirm_Point_Source();
				}

				// D-8: custom tile layers + their attribution are a PLUGIN decision, and
				// this fixture exercises that seam on purpose. Before this, `layers` and
				// `copyrights` were reachable only from unit tests — no rig traffic ever
				// went through `_addLayers()`/`_addCopyrights()`, and a fixture poorer
				// than production is exactly how s49 hid two of its four map defects.
				//
				// The 2GIS tile source and its `sphericalMercator` projection are copied
				// from the reference plugin (`plugins-reference/woocommerce-edostavka`,
				// `assets/js/frontend/woodev-yandex-map-plugin.js`), which is the real
				// consumer this seam was designed for. `projection` travels as the STRING
				// name — `_addLayers()` resolves it against `ymaps.projection.*` and
				// silently omits it if unrecognised, so the descriptor stays JSON-safe.
				//
				// The copyright line is NOT decoration and not optional in practice:
				// swapping the base tiles away from Yandex without attributing whoever
				// actually served them is a terms-of-use problem for both parties. The
				// framework deliberately ENABLES this rather than enforcing it (nothing
				// requires a plugin passing `layers` to also pass `copyrights`), so the
				// fixture demonstrates the correct pairing rather than the minimum.
				$map_layers = [
					[
						'url'        => 'https://tile%d|4.maps.2gis.com/tiles?%c&v=4.png',
						'projection' => 'sphericalMercator',
					],
				];

				$map_copyrights = [ '© 2ГИС' ];

				// Issue #251: WOODEV_TEST_PICKUP_EMBEDDED wins over everything above —
				// see that constant's own docblock. $point_source has ALREADY been
				// overridden to Woodev_Test_Embedded_Confirm_Point_Source just above
				// (F4 correction, s63) when this constant is on, so the map-provider
				// choice below and the point-source override above agree with each
				// other rather than one silently ignoring the other. `embed_url`/
				// `expected_origin` point straight at the live carrier widget —
				// decision §2 of the #251 spec: no
				// plugin-hosted bridge page is needed, since the widget accepts a
				// `postMessage` handshake from a foreign parent origin (M5) and the
				// framework's own sandbox posture does not break it (M2). The two
				// adapter hooks are dotted global JS paths resolved in the browser —
				// see `assets/js/woodev-test-pochta-embed-adapter.js` (enqueued by
				// `Woodev_Test_Shipping_Method_Plugin::maybe_enqueue_pochta_embed_adapter()`
				// below only when this constant is on), which supplies
				// `WoodevPochtaEmbed.onReady`/`.toPoint` and is the ONLY place the Почта
				// field names/address order/type labels are known — domain knowledge
				// stays out of the framework.
				if ( WOODEV_TEST_PICKUP_EMBEDDED ) {
					$map_provider = new \Woodev\Framework\Shipping\Map\Embedded_Map_Provider(
						'https://widget.pochta.ru/map/',
						'https://widget.pochta.ru',
						'WoodevPochtaEmbed.onReady',
						'WoodevPochtaEmbed.toPoint'
					);
				} else {
					// The Yandex Maps fallback key is a PLUGIN obligation, never the
					// framework's (spec §10.6) — Yandex_Map_Provider's constructor REQUIRES
					// one. This value is an obviously-fake placeholder that only works
					// because the rig never actually needs a live ymaps script under
					// PHPUnit; a real shipping plugin shipping this to production supplies
					// its OWN key here (obtained from the Yandex developer console), not a
					// copy of this placeholder.
					$map_provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider(
						'FIXTURE-FAKE-YANDEX-KEY',
						'',
						'',
						$map_layers,
						$map_copyrights
					);
				}

				// SP-5 Task 8 (D-7): a hardcoded default viewport is now a REQUIRED
				// constructor argument — Moscow, matching every point this fixture serves,
				// so the rig's map opens centred on the fixture's own data instead of the
				// whole world.
				$default_location = [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ];

				// SP-5 Task 8 (D-5), re-pointed by the live-review fix (D7, 05.08.2026), and
				// swapped for the REAL reference icons by issue #162's companion task: both
				// fixture types supply BOTH states — `PVZ` mirrors the Yandex reference's
				// two-image shape, `POSTAMAT` its own — so the rig always has at least one type
				// showing what a real two-image active state looks like. The framework's
				// one-image fallback (`active` mirrors `default` when a plugin supplies only one
				// image, the CDEK shape) is still real and still covered — see
				// `PickupHandlerTest::test_icons_are_passed_through_with_active_falling_back_to_default()`,
				// which exercises it directly against `normalized_point_icons()` rather than via
				// this fixture. A type this fixture never uses (`group`) is intentionally absent,
				// exercising the framework's own group badge fallback on the rig.
				// Keys are the EXACT `type.code` this fixture's own points carry — the framework
				// compares type codes case-sensitively, so `pvz` would never match a point
				// emitting `PVZ` and every marker would silently fall back to the no-icon state.
				// URLs are real files served by this plugin, not a placeholder host: an icon that
				// 404s renders as a broken image, which looks identical to "the framework never
				// applied the icon" and makes rig verification prove nothing.
				// The four SVGs themselves are copies of `plugins-reference/woocommerce-yandex-delivery`'s
				// own `yandex-delivery-map-pin-{office,terminal}[-active].svg` (WooDev's own
				// icons for its Yandex.Delivery integration, not hotlinked — see each file's own
				// attribution comment) — "office" is this fixture's `PVZ`, "terminal" its
				// `POSTAMAT`.
				//
				// This is cascade tier 2 only (domain icons BY TYPE CODE — issue #193). Under
				// WOODEV_TEST_PICKUP_LIVE_YANDEX, tier 1 (a POINT's own icon) is wired
				// separately, in `Woodev_Test_Live_Yandex_Point_Source::icons_for_operator()`:
				// a `5post` point gets its own icon by `operator_id`, reusing these SAME
				// terminal SVGs, because 5post and Yandex.Market points otherwise share this
				// fixture's `PVZ` type code (`pickup_point`) and would draw identically.
				$icons_url = plugins_url( 'assets/images', __FILE__ );

				$point_icons = [
					'PVZ'      => [
						'default' => $icons_url . '/yandex-delivery-map-pin-office.svg',
						'active'  => $icons_url . '/yandex-delivery-map-pin-office-active.svg',
					],
					'POSTAMAT' => [
						'default' => $icons_url . '/yandex-delivery-map-pin-terminal.svg',
						'active'  => $icons_url . '/yandex-delivery-map-pin-terminal-active.svg',
					],
				];

				// `close_on_select` (13th argument) is the CONFIG half of the two-level close
				// contract, and until now the rig only ever exercised the other half. The
				// framework's own default is `false` — a confirmed point leaves the customer in
				// the map with the CTA relabelled «Продолжить оформление заказа» — so the config
				// path had no live coverage at all: everything on the rig went through a domain
				// filter answering `close => true` per point.
				//
				// Turning it on here is not only "what the rig should demo". It is what makes the
				// override direction TESTABLE. The browser reads
				// `resolveFlag( result.close, defaults.close )`, whose contract is `??` and
				// explicitly NEVER `||`: an explicit `false` from the domain is a DECISION ("do
				// not close THIS one") and must beat a `true` config. With the config at `false`
				// and a point answering `true`, `??` and `||` return the SAME value — so the
				// fixture could not have caught that regression. With the config at `true`, a
				// point answering `false` separates them: `??` keeps the picker open, `||` closes
				// it. See DEMO-PVZ-STAY in fixture-points.php, which is that point.
				//
				// Arguments 10-12 are the framework's own defaults, restated only because the
				// constructor is positional and PHP 7.4 (which CI checks) has no named arguments.
				// The accent colour is duplicated as a literal because `DEFAULT_ACCENT_COLOR` is
				// a `private const` the fixture cannot reference — exactly the friction issue
				// #170 tracks. Arguments 13-14 (`close_on_select`/`refresh_checkout`) come from
				// the WOODEV_TEST_PICKUP_SELECTION_* constants (issue #251) rather than literals,
				// so the rig can reach the "modal stays open after a selection" path without a
				// code edit — see those constants' own docblock. Argument 15 (issue #176) is the
				// pickup-selection-persistence scope — see Woodev_Test_Selection_Scope's own
				// docblock for why it deliberately speaks a different locality vocabulary than
				// this fixture's own points. Argument 16 (Task 15; issue #159) is `$this` — this
				// fixture already participates in the Location Provider layer
				// (needs_location_provider() below), so wiring it here is what makes the rig
				// demonstrate #159's actual fix: the pickup REST query is now enriched with the
				// customer's live Location Provider record/resolved identity
				// (Pickup_Handler::location_context()), and the browser config carries
				// `location.current.key` instead of the picker having to read it off the DOM
				// itself. This is DELIBERATELY independent of Woodev_Test_Selection_Scope above —
				// point-selection PERSISTENCE keeps its own, pre-existing `billing_state`
				// vocabulary (a plugin is free to keep a Selection_Scope unrelated to the layer,
				// per Provider_Selection_Scope's own docblock); only the points QUERY and the
				// browser config are wired to the layer here.
				$this->pickup_handler = new \Woodev\Framework\Shipping\Pickup\Pickup_Handler(
					self::PLUGIN_ID,
					'carrier_pickup_point',
					$point_source,
					$map_provider,
					$default_location,
					null,
					null,
					true,
					$point_icons,
					'#06aedd',
					'',
					true,
					WOODEV_TEST_PICKUP_SELECTION_CLOSE,
					WOODEV_TEST_PICKUP_SELECTION_REFRESH_CHECKOUT,
					new \Woodev_Test_Selection_Scope(),
					$this
				);

				// Issue #251: the fixture-owned Почта adapter script — see
				// self::maybe_enqueue_pochta_embed_adapter() — is enqueued on its own
				// `wp_enqueue_scripts` hook, independent of the Pickup_Handler's own
				// enqueue pass, since the framework itself never enqueues plugin-supplied
				// adapter scripts (they are pure domain knowledge, see the #251 spec §5).
				add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_pochta_embed_adapter' ] );
				$this->pickup_handler->register();

				// Location Provider layer, block PR-C rig-visibility pull-forward: bridge the
				// rig's own DaData credential constants into the store option BEFORE anything
				// reads it this request — see maybe_seed_dadata_credentials_from_rig_constants()'s
				// own docblock.
				$this->maybe_seed_dadata_credentials_from_rig_constants();
			}

			/**
			 * Returns the pickup handler wired in the constructor — used by tests.
			 *
			 * @since 2.0.2
			 *
			 * @return \Woodev\Framework\Shipping\Pickup\Pickup_Handler
			 */
			public function get_pickup_handler(): \Woodev\Framework\Shipping\Pickup\Pickup_Handler {
				return $this->pickup_handler;
			}

			/**
			 * Demonstrates the §10.8 obligation:
			 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::get_settings_fields()}
			 * is a pure pass-through to the active map provider, and NOTHING on the
			 * framework side calls it automatically. A real shipping plugin MUST call
			 * this itself and merge the result into its own settings registration
			 * (`Woodev_Register_Settings`) — skip it and every install stays pinned to
			 * the fallback key above, silently, forever. This fixture has no settings
			 * page of its own to merge into, so this accessor exists only to prove the
			 * call happens and to give tests something concrete to assert against.
			 *
			 * @since 2.0.2
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function get_pickup_settings_fields(): array {
				return $this->pickup_handler->get_settings_fields();
			}

			/**
			 * Issue #251: enqueues the fixture-owned Почта widget-embed adapter script
			 * (`assets/js/woodev-test-pochta-embed-adapter.js`) that implements
			 * `window.WoodevPochtaEmbed.onReady`/`.toPoint` — the two functions
			 * `Embedded_Map_Provider`'s `initAdapter`/`selectAdapter` dotted paths point
			 * at (see the map-provider construction in the constructor above). Gated on
			 * `WOODEV_TEST_PICKUP_EMBEDDED` AND `is_checkout()`, same as
			 * `Pickup_Handler::enqueue_assets()` itself — this script is meaningless
			 * anywhere else, and enqueueing it unconditionally would leak a global onto
			 * every page for no reason.
			 *
			 * CREDENTIALS — same rule as `Woodev_Test_Live_Pochta_Point_Source`'s own
			 * docblock: `WOODEV_TEST_POCHTA_ACCOUNT_ID` (the SAME operator account id that
			 * class already reads) and `WOODEV_TEST_POCHTA_ACCOUNT_TYPE` are NEVER committed
			 * to this public repository. Read with a `defined()` guard, degrading to an
			 * empty string, so this file never fatals when neither is set (unit/integration
			 * test runs, which never define either). Define them in `wp-config.php`, or in
			 * the GITIGNORED `.wp-env.override.json`:
			 *
			 *     define( 'WOODEV_TEST_POCHTA_ACCOUNT_ID', '…' );
			 *     define( 'WOODEV_TEST_POCHTA_ACCOUNT_TYPE', '…' );
			 *
			 * `weight`/`sumoc`/`startZip`/`startAddress` (the rest of the widget's
			 * `postData` handshake, spec §1 M3) are FIXTURE placeholders, not real cart
			 * data — a real shipping plugin computes these from the live cart/customer,
			 * which this demo fixture has no reason to model.
			 *
			 * @since 2.0.2
			 *
			 * @internal
			 *
			 * @return void
			 */
			public function maybe_enqueue_pochta_embed_adapter(): void {
				if ( ! WOODEV_TEST_PICKUP_EMBEDDED || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
					return;
				}

				$handle = 'woodev-test-pochta-embed-adapter';
				$path   = __DIR__ . '/assets/js/woodev-test-pochta-embed-adapter.js';

				if ( ! file_exists( $path ) ) {
					return;
				}

				wp_enqueue_script(
					$handle,
					plugins_url( 'assets/js/woodev-test-pochta-embed-adapter.js', __FILE__ ),
					[],
					(string) filemtime( $path ),
					true
				);

				wp_localize_script(
					$handle,
					'WoodevTestPochtaEmbedConfig',
					[
						'accountId'    => defined( 'WOODEV_TEST_POCHTA_ACCOUNT_ID' )
							? (string) constant( 'WOODEV_TEST_POCHTA_ACCOUNT_ID' )
							: '',
						'accountType'  => defined( 'WOODEV_TEST_POCHTA_ACCOUNT_TYPE' )
							? (string) constant( 'WOODEV_TEST_POCHTA_ACCOUNT_TYPE' )
							: '',
						'weight'       => 1000,
						'sumoc'        => 1000,
						'startZip'     => '101000',
						'startAddress' => 'Москва, ул. Тверская, 1',
					]
				);
			}

			/**
			 * Singleton.
			 *
			 * @return Woodev_Test_Shipping_Method_Plugin
			 */
			public static function instance(): Woodev_Test_Shipping_Method_Plugin {
				return self::$instance ??= new self();
			}

			/**
			 * Инициализация плагина — подключаем класс метода доставки.
			 */
			public function init_plugin(): void {
				require_once $this->get_plugin_path() . '/class-woodev-test-shipping-method.php';
			}

			/**
			 * @inheritDoc
			 */
			protected function get_file(): string {
				return __FILE__;
			}

			/**
			 * @inheritDoc
			 */
			public function get_plugin_name(): string {
				return 'Woodev Test Shipping Method Plugin';
			}

			/**
			 * @inheritDoc
			 */
			public function get_download_id(): int {
				return 0;
			}

			/**
			 * @inheritDoc
			 */
			protected function get_shipping_method_classes(): array {
				return [
					'woodev_test_shipping' => 'Woodev_Test_Shipping_Method',
				];
			}

			/**
			 * @inheritDoc
			 */
			public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
				return null;
			}

			// -----------------------------------------------------------------
			// Location Provider layer — block PR-C rig-visibility pull-forward.
			// -----------------------------------------------------------------

			/**
			 * Opts this fixture into the Location Provider layer (spec §4.1) so the
			 * layer's activation gate opens on the rig — without at least one plugin
			 * returning `true` here, {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::is_needed()}
			 * stays `false` for the whole fleet, the "Локация" settings page is never
			 * registered, and PR-C's rig checklist (paste a DaData token, see location
			 * fields on checkout) is unperformable.
			 *
			 * @since 2.0.2
			 *
			 * @inheritDoc
			 */
			public function needs_location_provider(): bool {
				return true;
			}

			/**
			 * Returns this fixture's {@see \Woodev\Framework\Shipping\Location\Location_Adapter}
			 * — a mandatory obligation once {@see self::needs_location_provider()} is `true`
			 * (spec §4.3), never optional. See `class-test-location-adapter.php` for the
			 * fixture's own honest-and-trivial implementation.
			 *
			 * @since 2.0.2
			 *
			 * @inheritDoc
			 */
			public function get_location_adapter(): ?\Woodev\Framework\Shipping\Location\Location_Adapter {
				return new \Woodev_Test_Location_Adapter();
			}

			/**
			 * Bridges the rig's own `WOODEV_TEST_DADATA_TOKEN`/`WOODEV_TEST_DADATA_SECRET`
			 * wp-config constants into the DaData provider's store-level settings options
			 * (`woodev_location_token`/`woodev_location_clean_secret`) via
			 * {@see \Woodev_Test_Credential_Seeder::maybe_seed()} — see that class's own
			 * docblock for the full rationale and the idempotent/non-destructive rule.
			 *
			 * @since 2.0.2
			 *
			 * @return void
			 */
			private function maybe_seed_dadata_credentials_from_rig_constants(): void {
				\Woodev_Test_Credential_Seeder::maybe_seed( 'woodev_location_token', 'WOODEV_TEST_DADATA_TOKEN' );
				\Woodev_Test_Credential_Seeder::maybe_seed( 'woodev_location_clean_secret', 'WOODEV_TEST_DADATA_SECRET' );
			}

			// -----------------------------------------------------------------
			// Checkout handler seam — demo fields for Task 12 fixture wiring.
			// -----------------------------------------------------------------

			/**
			 * Cached checkout handler instance.
			 *
			 * @since 2.0.2
			 *
			 * @var \Woodev\Framework\Shipping\Checkout\Checkout_Handler|null
			 */
			private ?\Woodev\Framework\Shipping\Checkout\Checkout_Handler $checkout_handler_instance = null;

			/**
			 * Returns a Checkout_Handler configured with a small demo field set.
			 *
			 * Fields are wired to exercise every major branch of the layer:
			 *  1. `billing_state`        — root select with static regions, RU/BY/KZ/UZ takeover.
			 *  2. `billing_city`         — dependent suggest filtered by region + query string.
			 *  3. `carrier_pickup_point` — hidden pickup-slot required when the fixture
			 *                              shipping method is chosen.
			 *  4-6. `shipping_state`/`shipping_city`/`shipping_address_1` — Location Provider
			 *       layer, block PR-C rig-visibility pull-forward (spec §4.4): region/settlement/
			 *       address declared via {@see \Woodev\Framework\Shipping\Checkout\Field::source_location()}.
			 *       Deliberately the SHIPPING section, not billing — `billing_state`/`billing_city`
			 *       above already exercise the pre-existing static demo (source/takeover_condition)
			 *       that `CheckoutFieldsFixtureTest` asserts on byte-for-byte; the location fields
			 *       get their own, never-before-used native WC field ids so neither demo disturbs
			 *       the other. The three ids follow WooCommerce's own `_state`/`_city`/`_address_1`
			 *       naming convention on purpose — `location-cascade.js` derives the postcode
			 *       field id from it (see that file's own docblock).
			 *
			 * Domain data (regions, cities, method ids) lives here in the fixture;
			 * the framework stays generic.
			 *
			 * @since 2.0.2
			 *
			 * @return \Woodev\Framework\Shipping\Checkout\Checkout_Handler
			 */
			public function get_checkout_handler(): \Woodev\Framework\Shipping\Checkout\Checkout_Handler {

				if ( null !== $this->checkout_handler_instance ) {
					return $this->checkout_handler_instance;
				}

				$fields = \Woodev\Framework\Shipping\Checkout\Checkout_Fields::from_array(
					[
						// 1. Root region select — takes over `billing_state` for CIS countries.
						\Woodev\Framework\Shipping\Checkout\Field::create( 'billing_state' )
							->set_type( 'select' )
							->set_label( 'Регион' )
							->set_section( 'billing' )
							->set_required( true )
							->set_source(
								static function ( array $context ): array {
									if ( 'RU' !== ( $context['country'] ?? '' ) ) {
										return [];
									}
									return [
										[ 'value' => '77', 'label' => 'Москва' ],
										[ 'value' => '78', 'label' => 'Санкт-Петербург' ],
										[ 'value' => '23', 'label' => 'Краснодарский край' ],
									];
								},
								'options'
							)
							/*
							 * RU is deliberately EXCLUDED (issue #294, operator decision s70).
							 *
							 * `inject_states()` hooks `woocommerce_states`, which is keyed by
							 * COUNTRY and not by field — so this §8 demo taking over RU registered
							 * its three hardcoded regions as RU's states, and WooCommerce then
							 * rendered EVERY `*_state` field for RU as a `<select>` of those three,
							 * including `shipping_state` below, which the location-provider layer
							 * declares as a `text` typeahead. The layer's whole region level was
							 * therefore unobservable on the rig, and backwards fill had nowhere to
							 * write.
							 *
							 * Measured: `WC()->countries->get_states()['RU']` returned exactly
							 * those three entries, i.e. WooCommerce ships NO states of its own for
							 * RU — the entire list came from this fixture.
							 *
							 * Note what this costs: the source callable below only ever returns
							 * options for RU, so dropping RU here leaves the STATE-takeover half of
							 * the §8 demo dormant on the rig (the `billing_city` Dependent_Select
							 * half is untouched and still demonstrates §8). That is the intended
							 * trade until #294 decides how the region level and WooCommerce's own
							 * states concept are meant to coexist.
							 */
							->set_takeover_condition(
								static function ( array $context ): bool {
									return in_array( $context['country'] ?? '', [ 'BY', 'KZ', 'UZ' ], true );
								}
							),

						// 2. Dependent city suggest — driven by parent region + free-text query.
						\Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select::create( 'billing_city', 'billing_state' )
							->set_label( 'Город' )
							->set_section( 'billing' )
							->set_required( true )
							->set_source(
								static function ( array $context ): array {
									$cities_by_region = [
										'77' => [ 'Москва', 'Зеленоград', 'Троицк' ],
										'78' => [ 'Санкт-Петербург', 'Кронштадт', 'Пушкин' ],
										'23' => [ 'Краснодар', 'Сочи', 'Новороссийск' ],
									];

									$region = (string) ( $context['parent'] ?? '' );
									$query  = mb_strtolower( (string) ( $context['q'] ?? '' ) );

									$candidates = $cities_by_region[ $region ] ?? [];

									if ( '' === $query ) {
										return array_map(
											static fn( string $c ) => [ 'value' => $c, 'label' => $c ],
											$candidates
										);
									}

									$result = [];
									foreach ( $candidates as $city ) {
										if ( false !== mb_stripos( $city, $query ) ) {
											$result[] = [ 'value' => $city, 'label' => $city ];
										}
									}
									return $result;
								},
								'suggest'
							)
							// City autocomplete only for the CIS countries this carrier serves; for
							// e.g. the US the field stays WooCommerce's native text input.
							->set_takeover_condition(
								static fn( array $c ): bool => in_array( (string) ( $c['country'] ?? '' ), [ 'RU', 'BY', 'KZ', 'UZ' ], true )
							),

						// 3. Hidden pickup-point slot — required when the fixture method is chosen.
						// Use the literal method id (= Woodev_Test_Shipping_Method::METHOD_ID) —
						// get_checkout_handler() runs on every request (incl. REST) via the
						// plugin's register(), before WC lazily loads the shipping-method class, so
						// referencing that class constant here would fatal "class not found".
						\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create(
							'carrier_pickup_point',
							[ 'woodev_test_shipping' ]
						),

						// 4-6. Location Provider layer fields (block PR-C rig-visibility
						// pull-forward) — shipping-section region/settlement/address, each
						// backed by the store-level Location Provider layer rather than a
						// plugin-supplied source callable. Type stays 'text': spec D7 says
						// text+typeahead is the one mode available regardless of provider
						// capability, and `Checkout_Handler::inject()` would otherwise force
						// an empty-placeholder-only <select> for a 'select'-type field with
						// no static options (this field's options come from the client-side
						// cascade + REST suggest endpoint, never a static list).
						\Woodev\Framework\Shipping\Checkout\Field::create( 'shipping_state' )
							->set_type( 'text' )
							->set_label( 'Регион (Location Provider)' )
							->set_section( 'shipping' )
							->source_location( 'region' ),

						\Woodev\Framework\Shipping\Checkout\Field::create( 'shipping_city' )
							->set_type( 'text' )
							->set_label( 'Город (Location Provider)' )
							->set_section( 'shipping' )
							->source_location( 'settlement' ),

						\Woodev\Framework\Shipping\Checkout\Field::create( 'shipping_address_1' )
							->set_type( 'text' )
							->set_label( 'Адрес (Location Provider)' )
							->set_section( 'shipping' )
							->source_location( 'address' ),
					]
				);

				$handler = new \Woodev\Framework\Shipping\Checkout\Checkout_Handler(
					$fields,
					self::PLUGIN_ID
				);
				$handler->set_requires_pickup_methods( [ 'woodev_test_shipping' ] ); // = Woodev_Test_Shipping_Method::METHOD_ID (literal — see above).

				$this->checkout_handler_instance = $handler;
				return $this->checkout_handler_instance;
			}
		}
	}

	// -----------------------------------------------------------------------
	// SP-5 Task 13: a domain seam over `woodev_shipping_pickup_point_selection`
	// (see class-pickup-controller.php:610), so the rig can see three behaviours
	// no test can show and the framework's own accept-and-stay-open path never
	// exercises: a remembered refusal, an immediate close overriding this
	// fixture's own two-step default, and a checkout refresh.
	//
	// Registered HERE (inside this callback), same reason as the Point_Source
	// classes above: this callback runs only once the bootstrap has selected the
	// highest-version framework copy and registered its autoloader. Strictly, a
	// bare `add_filter()` call is not itself at risk the way a `class ... implements
	// Woodev\Framework\...` declaration is — a closure's parameter type hint is
	// resolved lazily, at call time, not when the closure is declared, so this
	// would not fatal even at file top level. It stays here anyway: this is where
	// every other piece of this fixture's plugin-specific wiring already lives,
	// and `apply_filters()` will not invoke the closure until long after the
	// autoloader is registered regardless.
	//
	// $point is never anything other than a resolved Pickup_Point here: the
	// controller returns 404 before this filter runs when fetch_details() finds
	// nothing (class-pickup-controller.php ~line 509), so it is typed directly
	// rather than hedged with method_exists().
	// -----------------------------------------------------------------------

	add_filter(
		'woodev_shipping_pickup_point_selection',
		function ( array $result, \Woodev\Framework\Shipping\Pickup\Pickup_Point $point, array $context ): array {
			$id = $point->get_id();

			// A point that always refuses — the rig's way to see the remembered-refusal path.
			if ( 'DEMO-PVZ-REFUSE' === $id ) {
				$result['allowed'] = false;
				$result['reason']  = 'Этот пункт временно не принимает заказы.';

				return $result;
			}

			// A point that REFUSES to close, against a config that says close (the handler is
			// constructed above with `close_on_select = true`). This is the direction that
			// actually proves something.
			//
			// The browser resolves the flag as `resolveFlag( result.close, defaults.close )`,
			// whose contract is `??` and explicitly never `||`, because an explicit `false`
			// from the domain is a DECISION — "do not close THIS one" — and has to beat a
			// `true` config. Under the previous arrangement (config `false`, point answering
			// `true`) both operators return the same value, so the rig could not tell a correct
			// implementation from a regressed one. Here they diverge: `??` keeps the picker
			// open, `||` closes it.
			//
			// A real carrier reaches this branch whenever confirmation returns something the
			// customer still has to see — a point that needs a code collected at the counter,
			// say — which is why the framework offers the per-point override at all.
			if ( 'DEMO-PVZ-STAY' === $id ) {
				$result['close'] = false;

				return $result;
			}

			// A point that asks for a checkout refresh, so the ordering can be watched live.
			// Its own demo point (id 'DEMO-PVZ-REFRESH'), like the two branches above, rather
			// than the plan's placeholder 'DEMO-POSTAMAT-1' (no such point) or the substitute
			// FIX-BULK-POSTAMAT-1 this branch first carried: that one is a POSTAMAT with
			// `accepts_cod: false`, so on a rig whose only enabled gateway is COD,
			// Constraint_Checker refused it before this filter could matter — dead CTA, no
			// request, branch unreachable outside PHP unit tests (s52). See the demo points'
			// own block in fixture-points.php.
			if ( 'DEMO-PVZ-REFRESH' === $id ) {
				$result['refresh_checkout'] = true;
			}

			return $result;
		},
		10,
		3
	);

	// -----------------------------------------------------------------------
	// Issue #171: a domain seam over `woodev_pickup_map_point_glyphs`
	// (class-pickup-handler.php's normalized_point_glyphs()) — the framework refuses to
	// guess a glyph from a carrier's type CODE, so a plugin whose `POSTAMAT` type reads as
	// a parcel locker has to say so explicitly, here. `PVZ` needs no entry at all: the
	// framework's own default is `warehouse` for every type it was never told about, which
	// is exactly what a staffed pickup point should show. Gives the seam a real consumer on
	// the rig (rather than shipping it speculative, s32 YAGNI) and lets the operator see two
	// visibly different, legible glyphs in the sidebar list and the point card side by side.
	// Same placement reasoning as the `woodev_shipping_pickup_point_selection` filter above:
	// registering the closure does not INVOKE it, so it is safe here regardless of whether
	// `plugin_init()`'s own deferred callback has run yet.
	// -----------------------------------------------------------------------

	add_filter(
		'woodev_pickup_map_point_glyphs',
		static function ( array $glyphs ): array {
			$glyphs['POSTAMAT'] = 'package';

			return $glyphs;
		}
	);

	/**
	 * Глобальный хелпер для доступа к тестовому плагину из тестов.
	 *
	 * @return Woodev_Test_Shipping_Method_Plugin
	 */
	function woodev_test_shipping_method_plugin(): Woodev_Test_Shipping_Method_Plugin {
		return Woodev_Test_Shipping_Method_Plugin::instance();
	}

	woodev_test_shipping_method_plugin();
}

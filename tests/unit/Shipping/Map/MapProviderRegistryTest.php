<?php
/**
 * Unit tests for the SP-5 Task 9 `Map_Provider` seam: the registry's register()/get()
 * resolution (an unknown id pins `null`, a re-registered id overrides, nothing is
 * auto-registered), the concrete `Yandex_Map_Provider` (script handle, settings field, ymaps
 * URL construction — `load`, `lang` locale-restriction, `ns`, the merchant-setting /
 * plugin-fallback / site-filter `apikey` resolution chain, the REQUIRED fallback-key
 * constructor argument — and that no credential-shaped key ever appears bare in
 * `get_js_config()`), and the concrete `Embedded_Map_Provider` (script handle, no API-key
 * field, its own `get_js_config()` passthrough).
 *
 * @package Woodev\Tests\Unit\Shipping\Map
 */

namespace Woodev\Tests\Unit\Shipping\Map;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Map\Embedded_Map_Provider;
use Woodev\Framework\Shipping\Map\Map_Provider_Registry;
use Woodev\Framework\Shipping\Map\Yandex_Map_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-map-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-embedded-map-provider.php';

/**
 * @covers \Woodev\Framework\Shipping\Map\Map_Provider_Registry
 * @covers \Woodev\Framework\Shipping\Map\Yandex_Map_Provider
 * @covers \Woodev\Framework\Shipping\Map\Embedded_Map_Provider
 */
final class MapProviderRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Faithful stand-in for WordPress's REAL add_query_arg(): it does NOT encode
		// values (WP's own docs say callers must urlencode()/rawurlencode() themselves) —
		// unlike http_build_query(), which encodes and would silently mask a missing
		// rawurlencode() call in the code under test. Concatenates raw key=value pairs, the
		// same way add_query_arg()'s own _http_build_query( ..., false ) does.
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				$pairs = [];

				foreach ( $args as $key => $value ) {
					$pairs[] = $key . '=' . $value;
				}

				return $url . '?' . implode( '&', $pairs );
			}
		);

		Functions\when( 'untrailingslashit' )->alias(
			static fn( string $value ) => rtrim( $value, '/' )
		);
	}

	/**
	 * Parses a built ymaps URL back into its query parameters.
	 *
	 * @return array<string, string>
	 */
	private function query_params_of( string $url ): array {
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );

		/** @var array<string, string> $params */
		return $params;
	}

	// -------------------------------------------------------------------------
	// registry — register()/get() resolution; nothing auto-registered
	// -------------------------------------------------------------------------

	public function test_a_registered_provider_resolves_by_its_id(): void {
		$registry = new Map_Provider_Registry();
		$registry->register( new Yandex_Map_Provider( '' ) );

		$this->assertInstanceOf( Yandex_Map_Provider::class, $registry->get( 'yandex' ) );
	}

	public function test_an_unknown_provider_id_resolves_to_null(): void {
		$registry = new Map_Provider_Registry();
		$registry->register( new Yandex_Map_Provider( '' ) );

		$this->assertNull( $registry->get( 'leaflet' ) );
	}

	/**
	 * The registry auto-registers NOTHING — not even Yandex_Map_Provider, whose fallback
	 * key is now a required, plugin-supplied constructor argument the registry itself has
	 * no source for. Every provider is registered by its owning plugin.
	 */
	public function test_a_registry_with_nothing_registered_resolves_every_id_to_null(): void {
		$registry = new Map_Provider_Registry();

		$this->assertNull( $registry->get( 'yandex' ) );
		$this->assertNull( $registry->get( 'embedded' ) );
	}

	public function test_registering_a_provider_under_an_existing_id_overrides_the_previous_one(): void {
		$registry = new Map_Provider_Registry();
		// Merchant setting (2nd arg) supplied directly and non-empty, so resolution never
		// touches apply_filters() — keeps this test about override behaviour only.
		$registry->register( new Yandex_Map_Provider( 'unused-fallback', 'first' ) );
		$registry->register( new Yandex_Map_Provider( 'unused-fallback', 'second' ) );

		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );

		$this->assertStringContainsString(
			'apikey=second',
			$registry->get( 'yandex' )->get_js_config( [] )['scriptUrl']
		);
	}

	public function test_embedded_is_registrable_under_its_own_id(): void {
		$registry = new Map_Provider_Registry();
		$registry->register( new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' ) );

		$this->assertInstanceOf( Embedded_Map_Provider::class, $registry->get( 'embedded' ) );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — the fallback key is a REQUIRED constructor argument
	// -------------------------------------------------------------------------

	/**
	 * The honest way to pin "required": PHP itself will fatal on a call missing this
	 * argument, so a `ReflectionMethod` parameter check proves the signature demands it
	 * rather than merely documenting that it should be supplied. An optional argument here
	 * would let a plugin author forget to pass it and ship a map that only fails once it
	 * reaches the storefront.
	 */
	public function test_the_fallback_key_constructor_argument_is_required(): void {
		$constructor = new \ReflectionMethod( Yandex_Map_Provider::class, '__construct' );
		$parameters  = $constructor->getParameters();

		$this->assertSame( 'fallback_key', $parameters[0]->getName() );
		$this->assertFalse(
			$parameters[0]->isOptional(),
			'the fallback key must be a REQUIRED constructor argument'
		);
		$this->assertFalse( $parameters[0]->isDefaultValueAvailable() );
	}

	/**
	 * A plugin that resolves its fallback from somewhere unusual (a constant, a remote
	 * config) can subclass and override get_fallback_map_key() rather than being forced
	 * through the constructor argument.
	 */
	public function test_get_fallback_map_key_can_be_overridden_by_a_subclass(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new class( 'constructor-fallback' ) extends Yandex_Map_Provider {
			protected function get_fallback_map_key(): string {
				return 'overridden-fallback';
			}
		};

		$params = $this->query_params_of( $provider->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( 'overridden-fallback', $params['apikey'] );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — identity
	// -------------------------------------------------------------------------

	public function test_yandex_provider_id_is_yandex(): void {
		$this->assertSame( 'yandex', ( new Yandex_Map_Provider( '' ) )->get_id() );
	}

	public function test_yandex_provider_label_is_yandex_maps_in_russian(): void {
		Functions\when( '__' )->returnArg( 1 );

		$this->assertSame( 'Яндекс.Карты', ( new Yandex_Map_Provider( '' ) )->get_label() );
	}

	public function test_embedded_provider_label_names_a_carrier_widget_in_russian(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' );

		$this->assertSame( 'Встроенный виджет перевозчика', $provider->get_label() );
	}

	/**
	 * Coordination proof: {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()}
	 * enqueues `woodev-pickup-map-provider-{id}`; get_script_handle() must return EXACTLY
	 * that, or the handler and the provider silently disagree on which script handle they
	 * mean.
	 */
	public function test_yandex_script_handle_matches_the_handler_enqueue_pattern(): void {
		$this->assertSame(
			'woodev-pickup-map-provider-yandex',
			( new Yandex_Map_Provider( '' ) )->get_script_handle()
		);
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — settings fields: optional, present, NOT sensitive
	// -------------------------------------------------------------------------

	public function test_yandex_declares_an_optional_api_key_field_that_is_not_sensitive(): void {
		Functions\when( '__' )->returnArg( 1 );

		$fields = ( new Yandex_Map_Provider( '' ) )->get_settings_fields();

		$this->assertArrayHasKey( 'map_api_key', $fields );
		$this->assertArrayHasKey( 'sensitive', $fields['map_api_key'] );
		$this->assertFalse(
			$fields['map_api_key']['sensitive'],
			'a JS map key ships to the browser inside the script URL regardless — marking it '
			. 'sensitive would mask it without hiding anything, and stop the merchant seeing '
			. 'what they pasted'
		);
	}

	/**
	 * Optionality currently exists only as Russian prose in the field's description — pin
	 * `required` explicitly so a mutant flipping it to `true` cannot survive silently.
	 */
	public function test_yandex_api_key_field_is_not_required(): void {
		Functions\when( '__' )->returnArg( 1 );

		$fields = ( new Yandex_Map_Provider( '' ) )->get_settings_fields();

		$this->assertArrayHasKey( 'required', $fields['map_api_key'] );
		$this->assertFalse( $fields['map_api_key']['required'] );
	}

	/**
	 * The descriptor is in the Woodev settings-API `register_setting()` args shape (`name`,
	 * `type`, …) — see `woodev/settings-api/abstract-class-settings.php` — not the
	 * WooCommerce `form_fields` shape (`title`, `desc_tip`, …); the two vocabularies must
	 * never be mixed, since only one is actually consumed by `register_setting()`.
	 */
	public function test_yandex_api_key_field_uses_the_woodev_settings_api_shape(): void {
		Functions\when( '__' )->returnArg( 1 );

		$field = ( new Yandex_Map_Provider( '' ) )->get_settings_fields()['map_api_key'];

		$this->assertArrayHasKey( 'name', $field );
		$this->assertArrayNotHasKey( 'title', $field, 'WC form_fields key, not the Woodev settings-API shape' );
		$this->assertArrayNotHasKey( 'desc_tip', $field, 'WC form_fields key, not the Woodev settings-API shape' );
	}

	public function test_yandex_api_key_field_type_is_the_woodev_string_setting_type(): void {
		Functions\when( '__' )->returnArg( 1 );

		$fields = ( new Yandex_Map_Provider( '' ) )->get_settings_fields();

		$this->assertSame( \Woodev_Setting::TYPE_STRING, $fields['map_api_key']['type'] );
	}

	public function test_embedded_declares_no_settings_fields_at_all(): void {
		$fields = ( new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' ) )
			->get_settings_fields();

		$this->assertSame( [], $fields );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — settings-field description: shared-key warning + docs link
	// -------------------------------------------------------------------------

	public function test_settings_field_description_warns_about_the_shared_fallback_key(): void {
		Functions\when( '__' )->returnArg( 1 );

		$description = ( new Yandex_Map_Provider( '' ) )->get_settings_fields()['map_api_key']['description'];

		$this->assertStringContainsString( 'общий ключ', $description );
		$this->assertStringContainsString( 'собственный ключ', $description );
	}

	public function test_settings_field_description_renders_without_a_docs_link_by_default(): void {
		Functions\when( '__' )->returnArg( 1 );

		$description = ( new Yandex_Map_Provider( '' ) )->get_settings_fields()['map_api_key']['description'];

		$this->assertStringNotContainsString( 'Инструкция', $description );
	}

	public function test_settings_field_description_includes_the_docs_link_when_supplied(): void {
		Functions\when( '__' )->returnArg( 1 );

		$description = ( new Yandex_Map_Provider( '', '', 'https://example.test/docs' ) )
			->get_settings_fields()['map_api_key']['description'];

		$this->assertStringContainsString( 'https://example.test/docs', $description );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — ymaps script URL: load, ns, lang, apikey
	// -------------------------------------------------------------------------

	public function test_default_install_script_url_carries_the_expected_fixed_parameters(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->justReturn( '' );

		$url    = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'];
		$params = $this->query_params_of( $url );

		$this->assertStringStartsWith( 'https://api-maps.yandex.ru/2.1/', $url );
		$this->assertSame( 'package.standard', $params['load'] );
		$this->assertSame( 'WoodevPickupMap', $params['ns'] );
		$this->assertSame( 'ru_RU', $params['lang'] );
		$this->assertSame( '', $params['apikey'] );
	}

	/**
	 * Value-mutant guard: `ns` must be EXACTLY `WoodevPickupMap` — this is a contract with
	 * the JS provider task, which reads `window.WoodevPickupMap`. A changed `ns` breaks
	 * that contract silently (no PHP error, no visible symptom until the browser script
	 * looks for the wrong global).
	 */
	public function test_ns_is_exactly_woodev_pickup_map(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$config = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] );
		$params = $this->query_params_of( $config['scriptUrl'] );

		$this->assertSame( 'WoodevPickupMap', $params['ns'] );
		$this->assertSame( 'WoodevPickupMap', $config['ns'], 'the top-level ns key must match the URL ns param' );
	}

	/**
	 * Value-mutant guard: `load` must be exactly `package.standard`, matching the
	 * reference implementation.
	 */
	public function test_load_is_exactly_package_standard(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( 'package.standard', $params['load'] );
	}

	/**
	 * @dataProvider provide_accepted_locales
	 */
	public function test_an_accepted_locale_is_used_verbatim( string $locale ): void {
		Functions\when( 'get_locale' )->justReturn( $locale );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( $locale, $params['lang'] );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function provide_accepted_locales(): array {
		return [
			'ru_RU' => [ 'ru_RU' ],
			'en_US' => [ 'en_US' ],
			'en_RU' => [ 'en_RU' ],
			'ru_UA' => [ 'ru_UA' ],
			'uk_UA' => [ 'uk_UA' ],
			'tr_TR' => [ 'tr_TR' ],
		];
	}

	/**
	 * Value-mutant guard on the fallback default itself: an unaccepted locale must fall
	 * back to EXACTLY `en_US` (D-12) — `en_US` is WordPress's own default locale, so the
	 * fallback is only reached for genuinely foreign locales.
	 */
	public function test_an_unaccepted_locale_falls_back_to_en_us(): void {
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( 'en_US', $params['lang'] );
	}

	public function test_an_empty_locale_falls_back_to_en_us(): void {
		Functions\when( 'get_locale' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( 'en_US', $params['lang'] );
	}

	/**
	 * `en_GB`/`en_CA` used to hit a dedicated `en_*` prefix branch that special-cased
	 * them to `en_US` before falling through to a Russian default. That branch is now
	 * dead code — `en_US` IS the default — and was removed (D-12); these locales still
	 * resolve to `en_US`, but via the SAME generic "not accepted -> default" rule as
	 * `de_DE`, not a separate code path.
	 */
	public function test_a_near_english_locale_falls_back_to_en_us_via_the_generic_default(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_GB' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( 'en_US', $params['lang'] );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — Task 7: resolved locale (single source), layers, copyrights
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provide_locales
	 */
	public function test_locale_resolution( string $site_locale, string $expected ): void {
		Functions\when( 'get_locale' )->justReturn( $site_locale );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( $expected, ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['lang'] );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function provide_locales(): array {
		return [
			'exact ru_RU'       => [ 'ru_RU', 'ru_RU' ],
			'exact en_US'       => [ 'en_US', 'en_US' ],
			'exact en_RU'       => [ 'en_RU', 'en_RU' ],
			'exact ru_UA'       => [ 'ru_UA', 'ru_UA' ],
			'exact uk_UA'       => [ 'uk_UA', 'uk_UA' ],
			'exact tr_TR'       => [ 'tr_TR', 'tr_TR' ],
			'unsupported de_DE' => [ 'de_DE', 'en_US' ],
			'unsupported en_GB' => [ 'en_GB', 'en_US' ],
			'empty'             => [ '', 'en_US' ],
		];
	}

	public function test_layers_and_copyrights_default_to_empty(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$config = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] );

		$this->assertSame( [], $config['layers'] );
		$this->assertSame( [], $config['copyrights'] );
	}

	/**
	 * `scriptUrl`'s `lang` query param and the top-level `lang` field are computed by
	 * two different CONSUMERS ({@see Yandex_Map_Provider::build_script_url()} and
	 * {@see Yandex_Map_Provider::get_js_config()}) — this pins that both read the SAME
	 * resolved value, so the two cannot silently drift apart.
	 */
	public function test_the_lang_in_the_script_url_matches_the_lang_field(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// An ACCEPTED, non-default locale is the only case that proves anything here. With
		// `de_DE` both sides land on the default `en_US`, so a build_script_url() that ignored
		// the resolved value and hardcoded `en_US` would pass — and the two computations
		// drifting apart is exactly what this test exists to catch.
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );

		$config = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] );

		$this->assertStringContainsString( 'lang=ru_RU', $config['scriptUrl'] );
		$this->assertSame( 'ru_RU', $config['lang'] );
		$this->assertStringNotContainsString( 'lang=en_US', $config['scriptUrl'] );

		// And the fallback path agrees too, so neither side special-cases the default.
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );

		$fallback = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] );

		$this->assertStringContainsString( 'lang=en_US', $fallback['scriptUrl'] );
		$this->assertSame( 'en_US', $fallback['lang'] );
	}

	/**
	 * `layers` is a list of arrays (each an arbitrary ymaps tile-layer descriptor). A
	 * malformed (non-array) entry is dropped rather than passed through to
	 * `wp_json_encode()`, and the surviving entries are reindexed with
	 * `array_values()` — `array_filter()` preserves keys, so without reindexing a
	 * gap-y list JSON-encodes as an object, not an array (see gotcha
	 * `php-stdlib-traps-that-survive-tests`).
	 */
	public function test_layers_passes_through_valid_entries_and_drops_malformed_ones(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Yandex_Map_Provider(
			'',
			'',
			'',
			[ [ 'type' => 'tile#tile', 'zIndex' => 1 ], 'not-an-array', [ 'type' => 'other' ] ]
		);

		$config = $provider->get_js_config( [] );

		$this->assertSame(
			[
				[ 'type' => 'tile#tile', 'zIndex' => 1 ],
				[ 'type' => 'other' ],
			],
			$config['layers']
		);
		$this->assertSame( [ 0, 1 ], array_keys( $config['layers'] ), 'must be reindexed, not gap-y' );
	}

	/**
	 * `copyrights` is a list of strings. A malformed (non-string) entry is dropped and
	 * the rest reindexed, for the same `array_filter()`-preserves-keys reason as
	 * `layers`.
	 */
	public function test_copyrights_passes_through_valid_entries_and_drops_malformed_ones(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Yandex_Map_Provider( '', '', '', [], [ '© 2Gis', 42, '© Test' ] );

		$config = $provider->get_js_config( [] );

		$this->assertSame( [ '© 2Gis', '© Test' ], $config['copyrights'] );
		$this->assertSame( [ 0, 1 ], array_keys( $config['copyrights'] ), 'must be reindexed, not gap-y' );
	}

	/**
	 * `copyrights` is rendered by ymaps' own `copyrights.add()`, not by our JS via
	 * `innerHTML` — and, unlike a carrier API's point data, its values are supplied by
	 * the PLUGIN AUTHOR at construction time (config, not third-party/customer input).
	 * PHP therefore does NOT `esc_html()` it: doing so would corrupt any markup the
	 * plugin author deliberately includes (e.g. a link to the tile provider's terms),
	 * producing a visible `&amp;`/`&lt;` in the map's copyright corner instead of the
	 * intended link — the exact "backwards" failure this decision is pinned against.
	 */
	public function test_copyrights_are_not_html_escaped(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Yandex_Map_Provider( '', '', '', [], [ '<a href="https://example.test">© Example</a>' ] );

		$this->assertSame(
			[ '<a href="https://example.test">© Example</a>' ],
			$provider->get_js_config( [] )['copyrights']
		);
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — apikey resolution chain: setting -> plugin fallback -> filter
	// -------------------------------------------------------------------------

	public function test_the_merchant_setting_wins_over_the_plugin_fallback(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\expect( 'apply_filters' )->never();

		$params = $this->query_params_of(
			( new Yandex_Map_Provider( 'PLUGIN-FALLBACK', 'MERCHANT-KEY' ) )->get_js_config( [] )['scriptUrl']
		);

		$this->assertSame( 'MERCHANT-KEY', $params['apikey'] );
	}

	public function test_the_plugin_fallback_is_used_when_no_merchant_setting_is_configured(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of(
			( new Yandex_Map_Provider( 'PLUGIN-FALLBACK' ) )->get_js_config( [] )['scriptUrl']
		);

		$this->assertSame( 'PLUGIN-FALLBACK', $params['apikey'] );
	}

	/**
	 * The site-level filter is an OVERRIDE on top of the plugin's fallback, not a second
	 * independent source: it can replace the fallback with something else entirely,
	 * regardless of what the plugin supplied.
	 */
	public function test_a_site_level_filter_can_override_the_plugin_fallback(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->justReturn( 'SITE-OVERRIDE-KEY' );

		$params = $this->query_params_of(
			( new Yandex_Map_Provider( 'PLUGIN-FALLBACK' ) )->get_js_config( [] )['scriptUrl']
		);

		$this->assertSame( 'SITE-OVERRIDE-KEY', $params['apikey'] );
	}

	/**
	 * Value-mutant guard: the filter's DEFAULT argument must be the plugin's own fallback
	 * key, not a hardcoded `''` — a mutant reverting to `apply_filters( $tag, '' )` would
	 * silently discard the plugin's fallback the moment a merchant filter returns its own
	 * `$default` unchanged (as `returnArg( 2 )` does here).
	 */
	public function test_the_fallback_filter_receives_the_plugins_fallback_key_as_its_default(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );

		$captured = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) use ( &$captured ) {
				$captured[] = [ $tag, $default ];

				return $default;
			}
		);

		( new Yandex_Map_Provider( 'PLUGIN-FALLBACK-KEY' ) )->get_js_config( [] );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'woodev_shipping_map_fallback_api_key', $captured[0][0] );
		$this->assertSame(
			'PLUGIN-FALLBACK-KEY',
			$captured[0][1],
			'the filter default must be the PLUGIN fallback key, not a hardcoded empty string'
		);
	}

	public function test_the_apikey_param_is_empty_when_neither_the_setting_nor_the_plugin_fallback_supply_one(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$params = $this->query_params_of( ( new Yandex_Map_Provider( '' ) )->get_js_config( [] )['scriptUrl'] );

		$this->assertSame( '', $params['apikey'] );
	}

	// -------------------------------------------------------------------------
	// no bare credential-shaped key ever leaves get_js_config()
	// -------------------------------------------------------------------------

	/**
	 * The api key must reach the browser ONLY inside `scriptUrl` — never under a separate
	 * top-level key that looks like a secret (which would invite masking it, contradicting
	 * the "not sensitive" decision, since it is not actually hideable).
	 */
	public function test_yandex_js_config_emits_no_bare_credential_shaped_key(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );

		$config = ( new Yandex_Map_Provider( '', 'SOME-KEY' ) )->get_js_config( [] );

		$this->assertSame(
			[ 'scriptUrl', 'ns', 'hasApiKey', 'lang', 'layers', 'copyrights' ],
			array_keys( $config )
		);

		// hasApiKey is a plain boolean, not the credential itself — explicitly confirm it
		// is not a string that could carry a leaked key value.
		$this->assertIsBool( $config['hasApiKey'] );

		foreach ( array_keys( $config ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/^(api[_-]?key|secret|token|password)/i', $key );
		}
	}

	public function test_embedded_js_config_declares_no_api_key_of_any_kind(): void {
		$config = ( new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' ) )
			->get_js_config( [] );

		foreach ( array_keys( $config ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/^(api[_-]?key|secret|token|password)/i', $key );
		}
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — hasApiKey: lets the JS detect a missing key directly
	// -------------------------------------------------------------------------

	public function test_has_api_key_is_true_when_a_merchant_key_is_configured(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\expect( 'apply_filters' )->never();

		$config = ( new Yandex_Map_Provider( '', 'MERCHANT-KEY' ) )->get_js_config( [] );

		$this->assertTrue( $config['hasApiKey'] );
	}

	public function test_has_api_key_is_false_when_neither_the_setting_nor_the_plugin_fallback_supply_one(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$config = ( new Yandex_Map_Provider( '' ) )->get_js_config( [] );

		$this->assertFalse( $config['hasApiKey'] );
	}

	public function test_has_api_key_is_true_when_only_the_plugin_fallback_supplies_one(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$config = ( new Yandex_Map_Provider( 'PLUGIN-FALLBACK' ) )->get_js_config( [] );

		$this->assertTrue( $config['hasApiKey'] );
	}

	// -------------------------------------------------------------------------
	// Yandex_Map_Provider — apikey must be percent-encoded, not corrupt the query string
	// -------------------------------------------------------------------------

	/**
	 * `add_query_arg()` does not encode its values (WordPress's own docs say callers must).
	 * A key containing `&` or a space must be `rawurlencode()`d before it reaches the URL,
	 * or it forks off a bogus extra query param / breaks the URL. The test setup's
	 * add_query_arg() stub deliberately mirrors that real, non-encoding behaviour (unlike
	 * http_build_query(), which would silently mask a missing rawurlencode() call).
	 */
	public function test_a_key_containing_ampersand_and_space_does_not_corrupt_the_query_string(): void {
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );

		$url    = ( new Yandex_Map_Provider( '', 'a&b c' ) )->get_js_config( [] )['scriptUrl'];
		$params = $this->query_params_of( $url );

		$this->assertSame( 'a&b c', $params['apikey'] );
		$this->assertArrayNotHasKey(
			'b',
			$params,
			'an unencoded "&" in the key must not fork off a bogus extra query param'
		);
	}

	// -------------------------------------------------------------------------
	// Embedded_Map_Provider
	// -------------------------------------------------------------------------

	public function test_embedded_provider_id_is_embedded(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' );

		$this->assertSame( 'embedded', $provider->get_id() );
	}

	public function test_embedded_script_handle_matches_the_handler_enqueue_pattern(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' );

		$this->assertSame( 'woodev-pickup-map-provider-embedded', $provider->get_script_handle() );
	}

	public function test_embedded_js_config_carries_the_embed_url_and_expected_origin_verbatim(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget.js', 'https://carrier.example' );

		$this->assertSame(
			[
				'embedUrl'       => 'https://carrier.example/widget.js',
				'expectedOrigin' => 'https://carrier.example',
				// Issue #251: null when the constructor's two optional adapter
				// arguments are omitted — "no adapter, framework protocol only".
				'initAdapter'    => null,
				'selectAdapter'  => null,
				// Task 20: mirrors owns_chrome() verbatim — pickup-mount.js reads this to decide
				// whether to construct the framework's own list/card panels at all.
				'ownsChrome'     => true,
			],
			$provider->get_js_config( [] )
		);
	}

	/**
	 * Issue #251: the two optional adapter-hook constructor arguments are carried
	 * VERBATIM into `get_js_config()` as strings — never resolved, called, or
	 * otherwise touched by PHP. They cross into the browser as JSON dotted global
	 * paths; only `map-provider-embedded.js` resolves them.
	 */
	public function test_embedded_js_config_carries_the_adapter_hooks_verbatim_when_supplied(): void {
		$provider = new Embedded_Map_Provider(
			'https://widget.pochta.ru/map/',
			'https://widget.pochta.ru',
			'WoodevPochtaEmbed.onReady',
			'WoodevPochtaEmbed.toPoint'
		);

		$config = $provider->get_js_config( [] );

		$this->assertSame( 'WoodevPochtaEmbed.onReady', $config['initAdapter'] );
		$this->assertSame( 'WoodevPochtaEmbed.toPoint', $config['selectAdapter'] );
	}

	/**
	 * Security-relevant for the JS task's `postMessage` origin check: `event.origin` never
	 * carries a trailing slash, so an unnormalized one here would silently mismatch it.
	 */
	public function test_embedded_provider_strips_a_trailing_slash_from_the_expected_origin(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget.js', 'https://carrier.example/' );

		$this->assertSame( 'https://carrier.example', $provider->get_js_config( [] )['expectedOrigin'] );
	}

	// -------------------------------------------------------------------------
	// owns_chrome() — who owns the container: whole widget vs. map canvas only
	// -------------------------------------------------------------------------

	/**
	 * The Yandex provider draws only the map canvas; the framework owns the list
	 * panel, the point card, the search view and the type filter around it (D-3).
	 */
	public function test_the_yandex_provider_does_not_own_the_chrome(): void {
		$this->assertFalse( ( new Yandex_Map_Provider( '' ) )->owns_chrome() );
	}

	/**
	 * The embedded provider's carrier widget already comes with its own list,
	 * search and selection UI — the framework renders no panels around it (D-3).
	 */
	public function test_the_embedded_provider_owns_the_whole_container(): void {
		$provider = new Embedded_Map_Provider( 'https://carrier.example/widget', 'https://carrier.example' );

		$this->assertTrue( $provider->owns_chrome() );
	}
}

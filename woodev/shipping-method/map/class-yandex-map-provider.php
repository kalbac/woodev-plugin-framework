<?php
/**
 * Woodev Yandex Map Provider
 *
 * Our own Yandex.Maps-rendered pickup-point map — the "own map" end of the
 * {@see Map_Provider} seam (the other end being a carrier's own embedded
 * widget/iframe, see {@see Embedded_Map_Provider}).
 *
 * **The fallback key is a plugin obligation, not a framework one.** The framework ships no
 * Yandex Maps key and cannot even construct this class without one — the fallback key is a
 * REQUIRED constructor argument (see {@see self::__construct()}), not an optional one an
 * author could forget to pass. Resolution order is: the merchant's own `map_api_key` setting
 * ({@see self::$api_key}) first, then the PLUGIN's fallback
 * ({@see self::get_fallback_map_key()}), then nothing. A site-level
 * `woodev_shipping_map_fallback_api_key` filter can still override the plugin's fallback —
 * see {@see self::resolve_api_key()}.
 *
 * **Accepted risk:** the plugin's fallback key is shared across every install of THAT plugin
 * — its quota is a shared pool, and abuse or heavy use on one site can exhaust or get the key
 * throttled/revoked for every other install relying on the same fallback. This is exactly the
 * reference plugin's own approach (`plugins-reference/woocommerce-yandex-delivery`) and has
 * worked for it for years, so it is a watch item for the plugin author, not a blocker — but it
 * does mean the map must degrade VISIBLY (a message, not an empty rectangle) when the key is
 * rejected; {@see self::get_js_config()}'s `hasApiKey` flag exists so the JS provider can
 * detect a missing key directly instead of guessing from a script load failure.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Map\\Yandex_Map_Provider' ) ) :

	/**
	 * Yandex.Maps pickup-point map provider.
	 *
	 * Not `final`, deliberately: {@see self::get_fallback_map_key()} exists precisely so a
	 * plugin whose fallback key comes from somewhere unusual (a constant, a remote config)
	 * can subclass this provider and override that ONE accessor.
	 *
	 * @since 2.0.2
	 */
	class Yandex_Map_Provider implements Map_Provider {

		/** @var string provider identifier */
		private const PROVIDER_ID = 'yandex';

		/** @var string base ymaps API script URL, matching the reference implementation. */
		private const API_BASE_URL = 'https://api-maps.yandex.ru/2.1/';

		/**
		 * ymaps namespace this provider's script registers its API surface under.
		 *
		 * A contract with the JS provider task: the browser reads
		 * `window.WoodevPickupMap` — changing this string is a breaking change for
		 * that script.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const JS_NAMESPACE = 'WoodevPickupMap';

		/**
		 * Locales the ymaps `lang` query parameter actually accepts verbatim (D-12). Any
		 * other site locale falls back to {@see self::DEFAULT_LANG} — see
		 * {@see self::resolve_lang()}.
		 *
		 * Known, accepted consequence: the REGION half of `lang` drives the UNITS ymaps
		 * shows distances in, not just the language — `RU`/`UA`/`TR` give kilometres,
		 * `US` gives miles. A store whose site locale is not in this list therefore falls
		 * back to `en_US` and gets a map labelled in miles, even though our own distances
		 * (sidebar, search results) are computed by us and always formatted from the SAME
		 * region, so the two never disagree on screen.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private const ACCEPTED_LANGS = [ 'ru_RU', 'en_US', 'en_RU', 'ru_UA', 'uk_UA', 'tr_TR' ];

		/**
		 * Default `lang` when the site locale is not in {@see self::ACCEPTED_LANGS}.
		 *
		 * `en_US` is WordPress's own default locale (D-12), so this fallback is only
		 * reached for genuinely foreign locales — deliberately NOT `ru_RU`; there is no
		 * `en_*` -> `en_RU` remapping.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const DEFAULT_LANG = 'en_US';

		/**
		 * The plugin's own shared fallback Yandex Maps API key.
		 *
		 * REQUIRED — this class cannot be constructed without one. That is deliberate: an
		 * optional argument here would let a plugin author forget to pass it and ship a map
		 * that only fails once it reaches the storefront. See
		 * {@see self::get_fallback_map_key()} for the override seam and this class's
		 * docblock for the shared-quota risk this key carries.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $fallback_key;

		/**
		 * The plugin's own `map_api_key` setting value, or empty string when unset.
		 *
		 * Resolved by the OWNING PLUGIN (it knows how to read its own settings) and
		 * handed in here — this class stays settings-system-agnostic and unit
		 * testable without a WordPress options layer.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $api_key;

		/**
		 * URL to instructions for obtaining a Yandex Maps API key, or `''` to render the
		 * settings-field description without a link.
		 *
		 * TODO: the operator will supply the canonical URL for these instructions — do not
		 * guess at a Yandex documentation address in the meantime; leave this `''` (or let a
		 * plugin supply its own) until that URL is provided.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $key_docs_url;

		/**
		 * Optional custom ymaps tile layers to draw over the base map (D-8), e.g. to
		 * layer 2GIS tiles the way the reference CDEK plugin does
		 * (`map.layers.add(...)`). Empty by default — Yandex's own tiles. Each entry is
		 * an arbitrary ymaps layer descriptor (array); this class does not interpret its
		 * shape, only validates that it IS an array — see {@see self::sanitize_layers()}.
		 * Choosing what to layer, and whether the terms of use of both parties permit
		 * it, is the PLUGIN's decision.
		 *
		 * @since 2.0.2
		 * @var array<int, array<string, mixed>>
		 */
		private array $layers;

		/**
		 * Optional custom map copyright/attribution strings (D-8), e.g. `'© 2Gis'`
		 * alongside a custom tile layer. Empty by default. Rendered by ymaps' own
		 * `copyrights.add()`, NOT by our JS via `innerHTML` — and supplied by the PLUGIN
		 * AUTHOR at construction time, not by a carrier API or customer input — so this
		 * class deliberately does NOT `esc_html()` these values; see
		 * {@see self::get_js_config()}.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private array $copyrights;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string                   $fallback_key the plugin's own shared fallback Yandex Maps
		 *                                               API key — REQUIRED, see this class's docblock
		 *                                               and {@see self::get_fallback_map_key()}.
		 * @param string                   $api_key      the plugin's own `map_api_key` setting value,
		 *                                               or `''` when the merchant has not configured
		 *                                               one — in which case
		 *                                               {@see self::resolve_api_key()} falls back to
		 *                                               `$fallback_key` (filterable via
		 *                                               `woodev_shipping_map_fallback_api_key`).
		 * @param string                   $key_docs_url URL to instructions for obtaining a key, or
		 *                                               `''` to omit the link from the settings-field
		 *                                               description.
		 * @param array<int, array<mixed>> $layers       optional custom ymaps tile layers (D-8);
		 *                                               non-array entries are dropped, see
		 *                                               {@see self::sanitize_layers()}.
		 * @param string[]                 $copyrights   optional custom map copyright/attribution
		 *                                               strings (D-8); non-string entries are dropped,
		 *                                               see {@see self::sanitize_copyrights()}.
		 */
		public function __construct(
			string $fallback_key,
			string $api_key = '',
			string $key_docs_url = '',
			array $layers = [],
			array $copyrights = []
		) {
			$this->fallback_key = $fallback_key;
			$this->api_key      = $api_key;
			$this->key_docs_url = $key_docs_url;
			$this->layers       = self::sanitize_layers( $layers );
			$this->copyrights   = self::sanitize_copyrights( $copyrights );
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return self::PROVIDER_ID;
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_label(): string {
			return __( 'Яндекс.Карты', 'woodev-plugin-framework' );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Built from {@see self::get_id()} by convention (matching
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()}'s own
		 * `woodev-pickup-map-provider-{provider}` pattern), not an enforced invariant — a
		 * provider whose handle strayed from that pattern would silently point at a
		 * differently-named script file.
		 *
		 * @since 2.0.2
		 */
		public function get_script_handle(): string {
			return 'woodev-pickup-map-provider-' . $this->get_id();
		}

		/**
		 * Gets the yandex provider settings fields.
		 *
		 * Returned in the Woodev settings-API `register_setting()` args shape (see
		 * `woodev/settings-api/abstract-class-settings.php`) — the framework's own settings
		 * surface, with real masking, validation, and the React settings page behind it.
		 * Contributes ONE optional field: the merchant's own Yandex.Maps API key.
		 *
		 * This descriptor does not register itself anywhere on its own. {@see
		 * \Woodev\Framework\Shipping\Pickup\Pickup_Handler::get_settings_fields()} exposes
		 * it as a pure pass-through, and the PLUGIN that owns the shipping integration must
		 * call that and merge the result into its own settings registration — otherwise the
		 * `map_api_key` field this method describes never reaches a merchant. See that
		 * method's docblock and spec §10.8 for the full obligation.
		 *
		 * Deliberately NOT marked `sensitive`. The framework's `sensitive` flag keeps a
		 * `Woodev_Setting` value server-side (masked in the settings UI, stripped from REST
		 * responses) — appropriate for a value that is genuinely secret. A JS map API key is
		 * not that: {@see self::get_js_config()} embeds it directly in the ymaps script URL
		 * the browser loads, in plain view of anyone who opens devtools. Marking it
		 * `sensitive` would be security theatre (it does not actually hide anything
		 * reachable) while ALSO stopping the merchant seeing what they pasted when they come
		 * back to check it — a strictly worse outcome than leaving it visible.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public function get_settings_fields(): array {
			return [
				'map_api_key' => [
					'name'        => __( 'Ключ API Яндекс.Карт', 'woodev-plugin-framework' ),
					'type'        => \Woodev_Setting::TYPE_STRING,
					'description' => $this->build_field_description(),
					'default'     => '',
					'required'    => false,
					// Deliberately not sensitive/masked — see method docblock.
					'sensitive'   => false,
				],
			];
		}

		/**
		 * Builds the settings-field description, warning the merchant about the shared
		 * fallback key and pointing at instructions for obtaining their own — only when
		 * {@see self::$key_docs_url} was actually supplied (see the TODO on that property).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function build_field_description(): string {
			$description = __(
				'Если не указан, используется общий ключ поставщика плагина. Общий ключ '
				. 'расходует одну квоту на все магазины и может быть заблокирован. '
				. 'Рекомендуем указать собственный ключ.',
				'woodev-plugin-framework'
			);

			if ( '' === $this->key_docs_url ) {
				return $description;
			}

			return $description . ' ' . sprintf(
				/* translators: %s: URL to instructions for obtaining a Yandex Maps API key. */
				__( 'Инструкция по получению ключа: %s.', 'woodev-plugin-framework' ),
				$this->key_docs_url
			);
		}

		/**
		 * {@inheritDoc}
		 *
		 * Builds the ymaps script URL exactly as the reference implementation does
		 * (`plugins-reference/woocommerce-yandex-delivery/includes/class-checkout.php`)
		 * — `load=package.standard`, a locale-restricted `lang`, this provider's own
		 * `ns` ({@see self::JS_NAMESPACE}), and `apikey` resolved by
		 * {@see self::resolve_api_key()} and percent-encoded (`add_query_arg()` does not
		 * encode its values — WordPress's own docs say callers must). Emits the built URL as
		 * ONE opaque string under `scriptUrl` — never a bare `apiKey`/`api_key` field — so
		 * nothing downstream is tempted to treat the key as a value worth hiding separately;
		 * see the {@see self::get_settings_fields()} docblock. `hasApiKey` is a plain
		 * boolean — not credential-shaped — so the JS provider can detect a missing key
		 * directly instead of inferring one from a load failure.
		 *
		 * `lang` is resolved exactly ONCE here, via {@see self::resolve_lang()}, and the
		 * SAME value is handed to both {@see self::build_script_url()} (baked into
		 * `scriptUrl`) and emitted as the top-level `lang` field the panels read for
		 * distance formatting (D-12) — a single source of truth, so the two can never
		 * silently drift apart. `layers`/`copyrights` are this provider's own optional,
		 * already-sanitized construction values (D-8); see
		 * {@see self::sanitize_layers()}/{@see self::sanitize_copyrights()}.
		 *
		 * @since 2.0.2
		 */
		public function get_js_config( array $context ): array {
			$api_key = $this->resolve_api_key();
			$lang    = self::resolve_lang();

			return [
				'scriptUrl'  => $this->build_script_url( $api_key, $lang ),
				'ns'         => self::JS_NAMESPACE,
				'hasApiKey'  => '' !== $api_key,
				'lang'       => $lang,
				'layers'     => $this->layers,
				'copyrights' => $this->copyrights,
			];
		}

		/**
		 * {@inheritDoc}
		 *
		 * Draws only the map canvas — camera, markers, clustering. The framework owns
		 * the list panel, the point card, the search view and the type filter around
		 * it; see the interface docblock and decision D-3.
		 *
		 * @since 2.0.2
		 */
		public function owns_chrome(): bool {
			return false;
		}

		/**
		 * Builds the ymaps API script URL for an already-resolved API key and lang.
		 *
		 * Takes both as parameters (rather than re-resolving them) so
		 * {@see self::get_js_config()} is the ONE place that calls
		 * {@see self::resolve_api_key()} (applying the
		 * `woodev_shipping_map_fallback_api_key` filter exactly once per call) and
		 * {@see self::resolve_lang()} — keeping this method's `lang` query param and
		 * `get_js_config()`'s top-level `lang` field reading the SAME resolved value.
		 *
		 * @since 2.0.2
		 *
		 * @param string $api_key the already-resolved API key (see {@see self::resolve_api_key()}).
		 * @param string $lang    the already-resolved `lang` (see {@see self::resolve_lang()}).
		 *
		 * @return string
		 */
		private function build_script_url( string $api_key, string $lang ): string {
			return add_query_arg(
				[
					'load'   => 'package.standard',
					'lang'   => $lang,
					'ns'     => self::JS_NAMESPACE,
					'apikey' => rawurlencode( $api_key ),
				],
				self::API_BASE_URL
			);
		}

		/**
		 * Resolves the ymaps `lang` parameter from the site locale, restricted to
		 * the set ymaps actually accepts (D-12).
		 *
		 * An exact match in {@see self::ACCEPTED_LANGS} wins; otherwise
		 * {@see self::DEFAULT_LANG}. No `en_*` prefix special-case: with `en_US` as the
		 * default, a dedicated branch for `en_GB`/`en_CA`/etc. would only have obscured
		 * the rule while producing the identical result.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function resolve_lang(): string {
			$locale = (string) get_locale();

			if ( in_array( $locale, self::ACCEPTED_LANGS, true ) ) {
				return $locale;
			}

			return self::DEFAULT_LANG;
		}

		/**
		 * Sanitizes the optional custom ymaps tile layers (D-8): keeps only entries
		 * that are themselves arrays (an arbitrary ymaps layer descriptor), dropping
		 * anything else rather than passing junk into `wp_json_encode()`.
		 *
		 * Reindexes with `array_values()` — `array_filter()` preserves keys, so a
		 * gap-y list would `wp_json_encode()` as a JSON OBJECT instead of an array the
		 * moment one entry is dropped (see gotcha `php-stdlib-traps-that-survive-tests`).
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, mixed> $layers raw constructor argument.
		 *
		 * @return array<int, array<mixed>>
		 */
		private static function sanitize_layers( array $layers ): array {
			return array_values( array_filter( $layers, 'is_array' ) );
		}

		/**
		 * Sanitizes the optional custom map copyright/attribution strings (D-8): keeps
		 * only string entries, dropping anything else. Reindexed for the same
		 * `array_filter()`-preserves-keys reason as {@see self::sanitize_layers()}.
		 *
		 * Deliberately does NOT `esc_html()` the surviving strings — see
		 * {@see self::get_js_config()}'s docblock and the `$copyrights` property
		 * docblock for why: these are rendered by ymaps' own `copyrights.add()`, not by
		 * our JS via `innerHTML`, and are supplied by the PLUGIN AUTHOR at construction
		 * time, not by a carrier API or customer input.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, mixed> $copyrights raw constructor argument.
		 *
		 * @return string[]
		 */
		private static function sanitize_copyrights( array $copyrights ): array {
			return array_values( array_filter( $copyrights, 'is_string' ) );
		}

		/**
		 * Gets the plugin's own shared fallback Yandex Maps API key.
		 *
		 * `protected`, not `private`: a plugin that resolves its fallback from somewhere
		 * unusual (a constant, a remote config) can subclass this provider and override this
		 * accessor instead of being forced through the constructor argument.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_fallback_map_key(): string {
			return $this->fallback_key;
		}

		/**
		 * Resolves the Yandex.Maps API key: the merchant's own setting when non-empty,
		 * otherwise the plugin's fallback — itself overridable by a site-level filter.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function resolve_api_key(): string {
			if ( '' !== $this->api_key ) {
				return $this->api_key;
			}

			/**
			 * Filters the fallback Yandex Maps API key used when a merchant supplies none.
			 *
			 * The default is the PLUGIN's own fallback key
			 * ({@see Yandex_Map_Provider::get_fallback_map_key()}) — the framework itself
			 * still hooks nothing here. This filter is a site-level override ON TOP of that
			 * plugin-supplied fallback (e.g. to swap in an operator-managed key without a
			 * plugin release), not the framework's own source of one.
			 *
			 * @since 2.0.2
			 *
			 * @param string $key The fallback API key. Default: the plugin's own fallback key.
			 */
			return (string) apply_filters( 'woodev_shipping_map_fallback_api_key', $this->get_fallback_map_key() );
		}
	}

endif;

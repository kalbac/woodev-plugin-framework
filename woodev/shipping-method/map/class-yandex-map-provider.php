<?php
/**
 * Woodev Yandex Map Provider
 *
 * Our own Yandex.Maps-rendered pickup-point map — the "own map" end of the
 * {@see Map_Provider} seam (the other end being a carrier's own embedded
 * widget/iframe, see {@see Embedded_Map_Provider}).
 *
 * **Fallback key filter — no default shipped.** {@see self::resolve_api_key()} applies
 * `woodev_shipping_map_fallback_api_key`, but THIS FRAMEWORK hooks nothing on it. With no
 * merchant-set key and no plugin-supplied filter, `apikey=` is emitted empty and the map will
 * not load — {@see self::get_js_config()}'s `hasApiKey` flag exists so the JS provider can
 * detect that and degrade VISIBLY (a message, not an empty rectangle) instead of guessing from
 * a script load failure.
 *
 * A plugin that DOES hook this filter with a shared key inherits a documented risk: one
 * shared key is a single quota-failure point across every install that relies on it — if
 * Yandex throttles or revokes it, all of them lose their map at once. This is exactly the
 * reference plugin's own approach (`plugins-reference/woocommerce-yandex-delivery`) and has
 * worked for it for years, so it is a watch item for whichever plugin chooses it, not a
 * blocker — but that plugin still owns making the degradation visible; this class only builds
 * the URL and reports whether a key was supplied.
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
	 * @since 2.0.2
	 */
	final class Yandex_Map_Provider implements Map_Provider {

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
		 * Locales the ymaps `lang` query parameter actually accepts verbatim. Any other
		 * site locale falls back through {@see self::resolve_lang()}'s `en_*` prefix
		 * check, then to {@see self::DEFAULT_LANG}.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private const ACCEPTED_LANGS = [ 'ru_RU', 'en_US', 'en_RU', 'ru_UA', 'uk_UA', 'tr_TR' ];

		/** @var string default `lang` when the site locale is not in {@see self::ACCEPTED_LANGS}. */
		private const DEFAULT_LANG = 'ru_RU';

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
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string $api_key the plugin's own `map_api_key` setting value, or
		 *                        `''` when the merchant has not configured one — in
		 *                        which case {@see self::resolve_api_key()} falls
		 *                        back to the `woodev_shipping_map_fallback_api_key`
		 *                        filter.
		 */
		public function __construct( string $api_key = '' ) {
			$this->api_key = $api_key;
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
					'description' => __(
						'Необязательно. Фреймворк предоставляет фильтр '
						. 'woodev_shipping_map_fallback_api_key для резервного ключа, но не '
						. 'задаёт его по умолчанию — если ни этот ключ, ни фильтр не заданы, '
						. 'карта не загрузится.',
						'woodev-plugin-framework'
					),
					'default'     => '',
					'required'    => false,
					// Deliberately not sensitive/masked — see method docblock.
					'sensitive'   => false,
				],
			];
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
		 * @since 2.0.2
		 */
		public function get_js_config( array $context ): array {
			$api_key = $this->resolve_api_key();

			return [
				'scriptUrl'  => $this->build_script_url( $api_key ),
				'ns'         => self::JS_NAMESPACE,
				'hasApiKey'  => '' !== $api_key,
			];
		}

		/**
		 * Builds the ymaps API script URL for an already-resolved API key.
		 *
		 * Takes the key as a parameter (rather than re-resolving it) so
		 * {@see self::get_js_config()} applies the `woodev_shipping_map_fallback_api_key`
		 * filter exactly once per call.
		 *
		 * @since 2.0.2
		 *
		 * @param string $api_key the already-resolved API key (see {@see self::resolve_api_key()}).
		 *
		 * @return string
		 */
		private function build_script_url( string $api_key ): string {
			return add_query_arg(
				[
					'load'   => 'package.standard',
					'lang'   => self::resolve_lang(),
					'ns'     => self::JS_NAMESPACE,
					'apikey' => rawurlencode( $api_key ),
				],
				self::API_BASE_URL
			);
		}

		/**
		 * Resolves the ymaps `lang` parameter from the site locale, restricted to
		 * the set ymaps actually accepts.
		 *
		 * An exact match wins; otherwise any `en_*` locale (e.g. `en_GB`, `en_CA`) falls
		 * back to `en_US` rather than straight to {@see self::DEFAULT_LANG} — ymaps has no
		 * British/Canadian English variant, and `en_US` is the closer match than Russian.
		 * Anything else falls back to {@see self::DEFAULT_LANG}.
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

			if ( 'en' === $locale || 0 === strpos( $locale, 'en_' ) ) {
				return 'en_US';
			}

			return self::DEFAULT_LANG;
		}

		/**
		 * Resolves the Yandex.Maps API key: the plugin's own setting when
		 * non-empty, otherwise the fallback filter.
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
			 * The framework itself hooks nothing here — obtaining a key from Yandex is
			 * awkward enough that requiring one would block many merchants outright, so a
			 * plugin MAY hook this to supply a shared key, but is not required to. When
			 * neither the merchant's own setting nor this filter supplies one, `apikey=` is
			 * emitted empty and the map will not load; see this class's docblock for the
			 * shared-key quota risk a plugin that DOES hook this takes on.
			 *
			 * @since 2.0.2
			 *
			 * @param string $key The fallback API key. Default `''`.
			 */
			return (string) apply_filters( 'woodev_shipping_map_fallback_api_key', '' );
		}
	}

endif;

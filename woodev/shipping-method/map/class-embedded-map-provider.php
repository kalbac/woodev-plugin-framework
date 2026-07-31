<?php
/**
 * Woodev Embedded Map Provider
 *
 * A carrier's own widget or `<iframe>`, embedded inside the same modal shell —
 * the other end of the {@see Map_Provider} seam from {@see Yandex_Map_Provider}'s
 * "our own map". This class only supplies the two plugin-supplied values the JS
 * half (`map-provider-embedded.js`) needs — where to embed from, and which origin
 * to trust a message back from.
 *
 * THE EMBEDDED PAGE MUST SPEAK THE FRAMEWORK'S OWN PROTOCOL, NOT THE CARRIER'S
 * NATIVE ONE. `$embed_url` is NOT simply "paste the carrier's widget URL here" —
 * a carrier's own page has no reason to know anything about Woodev and will
 * never spontaneously talk to it. Whatever page `$embed_url` points at MUST,
 * once the customer has picked a point, do ONE of:
 *   1. `postMessage` this EXACT envelope to the parent window (verbatim, every
 *      key required, `point` shaped per
 *      {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()} —
 *      `id`/`name`/`lat`/`lng`/`address`/`type.code`/`type.label` required, the
 *      rest optional):
 *      ```
 *      {
 *          source: 'woodev-pickup-embedded',
 *          type:   'select',
 *          point:  { id, name, lat, lng, address, type: { code, label }, ... }
 *      }
 *      ```
 *      — required for a cross-origin `<iframe>` (the normal case: the carrier's
 *      widget runs in an `<iframe>` whose `src` is `$embed_url`);
 *   2. call `window.WoodevPickupEmbedded.select( point )` with the same `point`
 *      shape, when the embed instead runs SAME-ORIGIN as the checkout page (a
 *      first-party `<script>` widget, not an iframe).
 * A carrier's own widget speaks neither of these — it is the CARRIER's protocol,
 * not ours. In practice this means `$embed_url` usually cannot point straight at
 * the carrier's own page: the owning plugin hosts a small bridge page (or
 * bridges an inline `<script>`) that embeds/initializes the carrier's real
 * widget and translates ITS selection callback into one of the two shapes
 * above. See `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js`
 * for the full receiving-side contract (origin/source checks, normalization,
 * the exact rejection rules) — this class only carries the two config values;
 * the JS file is the only place that consumes them.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Map\\Embedded_Map_Provider' ) ) :

	/**
	 * Carrier-embedded (widget/iframe) pickup-point map provider.
	 *
	 * @since 2.0.2
	 */
	final class Embedded_Map_Provider implements Map_Provider {

		/** @var string provider identifier */
		private const PROVIDER_ID = 'embedded';

		/**
		 * The embed URL — an `<iframe>` `src` — supplied by the owning plugin.
		 *
		 * NOT necessarily the carrier's own widget URL: the page this loads MUST
		 * speak the framework's own selection protocol (see the class docblock's
		 * "THE EMBEDDED PAGE MUST SPEAK THE FRAMEWORK'S OWN PROTOCOL" section for
		 * the exact `postMessage` envelope / callback shape). A carrier's native
		 * widget URL almost always needs a small plugin-hosted bridge page in
		 * front of it, not this property pointed at it directly.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $embed_url;

		/**
		 * The origin the browser must trust a `postMessage` response from, supplied
		 * by the owning plugin — i.e. the origin `$embed_url` itself is served
		 * from, since that is the only origin the framework's own envelope (see
		 * the class docblock) can legitimately arrive `postMessage`d from.
		 *
		 * Normalized via {@see untrailingslashit()} at construction — the JS task's
		 * `postMessage` origin check is a strict string/prefix comparison, and an
		 * unnormalized trailing slash here would silently mismatch the browser's own
		 * `event.origin` (which never carries one), quietly breaking the trust check.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $expected_origin;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string $embed_url       the embed `<iframe>` `src` — a page that
		 *                                speaks the framework's own selection
		 *                                protocol (see the class docblock), not
		 *                                necessarily the carrier's own widget URL.
		 * @param string $expected_origin the origin to trust a `postMessage` back
		 *                                from — the origin `$embed_url` is served
		 *                                from.
		 */
		public function __construct( string $embed_url, string $expected_origin ) {
			$this->embed_url       = $embed_url;
			$this->expected_origin = untrailingslashit( $expected_origin );
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
			return __( 'Встроенный виджет перевозчика', 'woodev-plugin-framework' );
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_script_handle(): string {
			return 'woodev-pickup-map-provider-' . $this->get_id();
		}

		/**
		 * {@inheritDoc}
		 *
		 * No API key of any kind — the carrier's own widget/iframe authenticates
		 * itself; this provider declares no credential field.
		 *
		 * @since 2.0.2
		 */
		public function get_settings_fields(): array {
			return [];
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_js_config( array $context ): array {
			return [
				'embedUrl'       => $this->embed_url,
				'expectedOrigin' => $this->expected_origin,
			];
		}
	}

endif;

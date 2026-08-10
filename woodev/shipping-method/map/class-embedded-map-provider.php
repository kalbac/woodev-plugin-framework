<?php
/**
 * Woodev Embedded Map Provider
 *
 * A carrier's own widget or `<iframe>`, embedded inside the same modal shell —
 * the other end of the {@see Map_Provider} seam from {@see Yandex_Map_Provider}'s
 * "our own map". This class only supplies the plugin-supplied values the JS
 * half (`map-provider-embedded.js`) needs — where to embed from, which origin
 * to trust a message back from, and (optionally) the two adapter hooks that
 * translate a carrier's OWN protocol instead of requiring it to speak ours.
 *
 * TWO WAYS FOR THE EMBEDDED PAGE TO REACH THE FRAMEWORK — pick one:
 *
 *   1. THE FRAMEWORK'S OWN PROTOCOL. `$embed_url` points at a page that,
 *      once the customer has picked a point, does ONE of:
 *        a. `postMessage` this EXACT envelope to the parent window (verbatim,
 *           every key required, `point` shaped per
 *           {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()} —
 *           `id`/`name`/`address`/`type.code`/`type.label` required, `lat`/`lng`
 *           optional-but-validated since issue #251, the rest optional):
 *           ```
 *           {
 *               source: 'woodev-pickup-embedded',
 *               type:   'select',
 *               point:  { id, name, address, type: { code, label }, ... }
 *           }
 *           ```
 *           — the normal shape for a cross-origin `<iframe>`;
 *        b. call `window.WoodevPickupEmbedded.select( point )` with the same
 *           `point` shape, when the embed instead runs SAME-ORIGIN as the
 *           checkout page (a first-party `<script>` widget, not an iframe).
 *      In practice this means the owning plugin hosts a small bridge page (or
 *      inline `<script>`) that embeds/initializes the carrier's real widget
 *      and translates ITS selection callback into one of the two shapes
 *      above — a carrier's own widget speaks neither of these natively.
 *
 *   2. `$init_adapter` / `$select_adapter` (issue #251). `$embed_url` points
 *      DIRECTLY at the carrier's own widget — no bridge page — and these two
 *      optional dotted-global-path hooks translate the carrier's OWN protocol
 *      messages in the browser instead. This path exists because a bridge
 *      page turned out to buy no safety a direct embed does not already have:
 *      measured against the live Почта России widget (see
 *      `docs-internal/specs/2026-08-10-embedded-map-provider-adapter-seam.md`
 *      §1), the framework's `sandbox` posture does not break the widget (M2),
 *      the widget accepts a `postMessage` handshake from a foreign parent
 *      origin (M5), and the framework's own origin + `event.source` trust gate
 *      holds against it unchanged (M6). See `map-provider-embedded.js`'s
 *      `handleMessage()` for exactly where these hooks run: strictly AFTER
 *      the origin/source gate, so they never widen the trust boundary — they
 *      only translate messages already proven to come from this instance's
 *      own iframe at the expected origin.
 *
 * See `woodev/shipping-method/assets/js/frontend/map-provider-embedded.js`
 * for the full receiving-side contract (origin/source checks, normalization,
 * the exact rejection rules, and the adapter resolution/throw rules) — this
 * class only carries the config values; the JS file is the only place that
 * consumes them.
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
		 * Either a page that speaks the framework's own selection protocol
		 * directly (see the class docblock's "TWO WAYS" section, option 1 — the
		 * exact `postMessage` envelope / callback shape), or the carrier's own
		 * widget URL when {@see self::$init_adapter}/{@see self::$select_adapter}
		 * translate its native protocol instead (option 2, issue #251).
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
		 * Optional dotted global JS path (e.g. `'WoodevPochtaEmbed.onReady'`) to a
		 * plugin-supplied function that translates the carrier's OWN handshake
		 * message into a payload this provider posts back into the iframe — see
		 * `map-provider-embedded.js`'s `handleMessage()` step 2 (issue #251). `null`
		 * when `$embed_url` already speaks the framework's own protocol directly
		 * (the class docblock's option 1) and needs no translation.
		 *
		 * Carried verbatim into {@see self::get_js_config()} as a STRING, never a
		 * callable — the value crosses into the browser as JSON; the browser
		 * resolves it by walking `window` on `.`, never `eval`/`new Function`.
		 *
		 * @since 2.0.2
		 * @var string|null
		 */
		private ?string $init_adapter;

		/**
		 * Optional dotted global JS path to a plugin-supplied function that
		 * translates the carrier's OWN selection message into this provider's raw
		 * point payload — see `map-provider-embedded.js`'s `handleMessage()` step 3
		 * (issue #251). `null` under the same condition as {@see self::$init_adapter}.
		 *
		 * @since 2.0.2
		 * @var string|null
		 */
		private ?string $select_adapter;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string      $embed_url       the embed `<iframe>` `src` — a page that
		 *                                     speaks the framework's own selection
		 *                                     protocol (see the class docblock's option
		 *                                     1), or the carrier's OWN widget URL
		 *                                     directly when `$init_adapter`/
		 *                                     `$select_adapter` translate its native
		 *                                     protocol instead (option 2).
		 * @param string      $expected_origin the origin to trust a `postMessage` back
		 *                                     from — the origin `$embed_url` is served
		 *                                     from.
		 * @param string|null $init_adapter    optional dotted global JS path (see
		 *                                     {@see self::$init_adapter}) that answers
		 *                                     the carrier's own handshake instead of the
		 *                                     framework's envelope. Default `null`.
		 * @param string|null $select_adapter  optional dotted global JS path (see
		 *                                     {@see self::$select_adapter}) that
		 *                                     translates the carrier's own selection
		 *                                     message instead of the framework's
		 *                                     envelope. Default `null`.
		 */
		public function __construct(
			string $embed_url,
			string $expected_origin,
			?string $init_adapter = null,
			?string $select_adapter = null
		) {
			$this->embed_url       = $embed_url;
			$this->expected_origin = untrailingslashit( $expected_origin );
			$this->init_adapter    = $init_adapter;
			$this->select_adapter  = $select_adapter;
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
		 * `ownsChrome` mirrors {@see self::owns_chrome()} verbatim — Task 20's mount reads
		 * `mapConfig.ownsChrome` to decide whether to construct the framework's own list/card
		 * panels at all, so this provider (the one real consumer that actually owns the whole
		 * container) is the one that must carry `true` into the browser, not just report it
		 * over an interface method nothing JS-side ever reads.
		 *
		 * `initAdapter`/`selectAdapter` (issue #251) carry {@see self::$init_adapter} and
		 * {@see self::$select_adapter} verbatim — `null` when unset, which
		 * `map-provider-embedded.js` treats as "no adapter, framework protocol only".
		 *
		 * @since 2.0.2
		 */
		public function get_js_config( array $context ): array {
			return [
				'embedUrl'       => $this->embed_url,
				'expectedOrigin' => $this->expected_origin,
				'initAdapter'    => $this->init_adapter,
				'selectAdapter'  => $this->select_adapter,
				'ownsChrome'     => $this->owns_chrome(),
			];
		}

		/**
		 * {@inheritDoc}
		 *
		 * Owns the WHOLE container — the carrier's own widget/iframe already comes
		 * with its own list, search and selection UI; the framework renders no panels
		 * around it. See the interface docblock and decision D-3.
		 *
		 * @since 2.0.2
		 */
		public function owns_chrome(): bool {
			return true;
		}
	}

endif;

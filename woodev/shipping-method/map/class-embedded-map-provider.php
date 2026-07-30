<?php
/**
 * Woodev Embedded Map Provider
 *
 * A carrier's own widget or `<iframe>`, embedded inside the same modal shell —
 * the other end of the {@see Map_Provider} seam from {@see Yandex_Map_Provider}'s
 * "our own map". Kept genuinely minimal: the JS half (trusting a `postMessage`
 * from the embedded origin, wiring the picked point back to the framework's
 * `dataSource`) is a later task; this class only supplies the two plugin-supplied
 * values that JS will need — where to embed from, and which origin to trust a
 * message back from.
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
		 * The carrier's embed URL (widget script or iframe `src`), supplied by the
		 * owning plugin.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $embed_url;

		/**
		 * The origin the browser must trust a `postMessage` response from, supplied
		 * by the owning plugin.
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
		 * @param string $embed_url       the carrier's embed URL.
		 * @param string $expected_origin the origin to trust a message back from.
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

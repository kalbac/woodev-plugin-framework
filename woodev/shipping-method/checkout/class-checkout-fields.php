<?php
/**
 * Woodev Checkout Fields Definition
 *
 * Declarative, WooCommerce-free description of the custom checkout fields a
 * shipping plugin adds to the checkout (e.g. a hidden selected-pickup-point
 * field). It is a pure definition object: it holds field descriptors and
 * normalizes them, but performs no WooCommerce I/O — injection, reading posted
 * data, validation and saving are the checkout handler's job (spec §4.2).
 *
 * No field-name strings are hardcoded here: the host plugin supplies its own
 * field ids (cf. yandex `yandex_pickup_point`), keeping this class free of any
 * installed-site contract value.
 *
 * Pure PHP — no WooCommerce calls. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.2.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Fields' ) ) :

	/**
	 * Custom checkout-field definitions.
	 *
	 * A small collection of declarative field descriptors. Each descriptor is
	 * normalized to a fixed core schema — `id`, `type`, `label`, `required`,
	 * `sanitize_callback`, `validate_callback` — so the checkout handler can
	 * consume them uniformly without re-checking shape. The `sanitize_callback`
	 * and `validate_callback` seams let the host plugin own field-specific
	 * sanitization/validation while this object stays WooCommerce-free.
	 *
	 * @since 1.5.0
	 */
	class Checkout_Fields {

		/**
		 * Normalized field descriptors keyed by field id.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $fields = [];

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int|string, array<string, mixed>> $definitions list of raw
		 *        field definitions; each is normalized and keyed by its `id`.
		 *        Definitions without a non-empty `id` are skipped.
		 */
		public function __construct( array $definitions = [] ) {
			foreach ( $definitions as $definition ) {
				$this->add( $definition );
			}
		}

		/**
		 * Builds a definition set from a plain list of field definitions.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int|string, array<string, mixed>> $definitions raw field definitions
		 *
		 * @return self
		 */
		public static function from_array( array $definitions ): self {
			return new self( $definitions );
		}

		/**
		 * Adds (or replaces) a single field definition.
		 *
		 * The definition is normalized to the core schema before storage. A
		 * definition with an empty `id` is ignored — the host plugin owns the
		 * field id, so a missing one is a no-op rather than a contract value.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $definition raw field definition
		 *
		 * @return self
		 */
		public function add( array $definition ): self {
			$field = self::normalize( $definition );

			if ( '' !== $field['id'] ) {
				$this->fields[ $field['id'] ] = $field;
			}

			return $this;
		}

		/**
		 * Normalizes a raw field definition to the core schema.
		 *
		 * Casts every known key to its declared type and fills defaults so the
		 * result is always well-formed. Callback seams are kept only when they are
		 * actually callable; otherwise they are normalized to `null`.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $definition raw field definition
		 *
		 * @return array{id: string, type: string, label: string, required: bool, sanitize_callback: callable|null, validate_callback: callable|null}
		 */
		public static function normalize( array $definition ): array {
			$sanitize = $definition['sanitize_callback'] ?? null;
			$validate = $definition['validate_callback'] ?? null;

			return [
				'id'                => (string) ( $definition['id'] ?? '' ),
				'type'              => (string) ( $definition['type'] ?? 'text' ),
				'label'             => (string) ( $definition['label'] ?? '' ),
				'required'          => (bool) ( $definition['required'] ?? false ),
				'sanitize_callback' => is_callable( $sanitize ) ? $sanitize : null,
				'validate_callback' => is_callable( $validate ) ? $validate : null,
			];
		}

		/**
		 * Gets all normalized field definitions keyed by field id.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public function get_fields(): array {
			return $this->fields;
		}

		/**
		 * Gets a single normalized field definition by id.
		 *
		 * @since 1.5.0
		 *
		 * @param string $id field id
		 *
		 * @return array<string, mixed>|null the field, or null if not defined
		 */
		public function get_field( string $id ): ?array {
			return $this->fields[ $id ] ?? null;
		}

		/**
		 * Determines whether a field with the given id is defined.
		 *
		 * @since 1.5.0
		 *
		 * @param string $id field id
		 *
		 * @return bool
		 */
		public function has_field( string $id ): bool {
			return isset( $this->fields[ $id ] );
		}

		/**
		 * Exports the definitions as a plain ordered list.
		 *
		 * Insertion order is preserved; field ids become the `id` key of each
		 * entry rather than the array key, suitable for serialization.
		 *
		 * @since 1.5.0
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function to_array(): array {
			return array_values( $this->fields );
		}
	}

endif;

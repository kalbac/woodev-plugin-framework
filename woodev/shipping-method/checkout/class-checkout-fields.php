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

require_once __DIR__ . '/class-field.php';

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Fields' ) ) :

	/**
	 * Custom checkout-field definitions.
	 *
	 * A small collection of declarative field descriptors. Each descriptor is
	 * normalized to a fixed core schema so the checkout handler can consume them
	 * uniformly without re-checking shape.
	 *
	 * Core schema keys (all present after {@see normalize()}):
	 *  - `id`                 — field identifier supplied by the host plugin.
	 *  - `type`               — input type, e.g. `'text'`, `'hidden'`.
	 *  - `label`              — human-readable label.
	 *  - `section`            — checkout section: `'order'` (default), `'billing'`, `'shipping'`.
	 *  - `required`           — `bool` (coerced) OR an array condition-spec when the host plugin
	 *                           passes a structured condition array; preserved verbatim so the
	 *                           checkout handler can evaluate it.
	 *  - `depends_on`         — id of a parent field this field depends on, or `null`.
	 *  - `source`             — callable producing option/suggestion items, or `null`.
	 *  - `source_kind`        — `'options'` or `'suggest'`, or `null` when `source` is absent.
	 *  - `takeover_condition` — callable deciding whether this field should take over native
	 *                           WC output, or `null`.
	 *  - `sanitize_callback`  — callable for sanitizing posted value, or `null`.
	 *  - `validate_callback`  — callable for validating posted value, or `null`.
	 *  - `is_pickup_slot`     — `true` when this field is a pickup slot anchor for the SP-5 adapter.
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
		 * @since 2.0.2 Each entry may also be a {@see Field} builder instance.
		 *
		 * @param array<int|string, Field|array<string, mixed>> $definitions list of raw
		 *        field definitions or Field instances; each is normalized and keyed by
		 *        its `id`. Definitions without a non-empty `id` are skipped.
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
		 * @since 2.0.2 Each entry may also be a {@see Field} builder instance.
		 *
		 * @param array<int|string, Field|array<string, mixed>> $definitions raw field
		 *        definitions or Field instances.
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
		 * Accepts either a raw associative array or a {@see Field} builder instance;
		 * when a `Field` is passed, {@see Field::to_array()} is called first so the
		 * same normalization path is used regardless of how the definition was
		 * assembled.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Also accepts a {@see Field} instance.
		 *
		 * @param Field|array<string, mixed> $definition raw field definition or a
		 *        Field builder instance.
		 *
		 * @return self
		 */
		public function add( $definition ): self {
			if ( $definition instanceof Field ) {
				$definition = $definition->to_array();
			}

			$field = self::normalize( $definition );

			self::validate_required_spec( $field['required'] );

			if ( '' !== $field['id'] ) {
				$this->fields[ $field['id'] ] = $field;
			}

			return $this;
		}

		/**
		 * Validates the shape of a condition-spec `required` value at registration time.
		 *
		 * A boolean `required` is always valid and returns immediately. An array spec must
		 * be either a single `{state, operator, value}` triplet (identified by having an
		 * `operator` key) or a multi-condition `{relation, conditions[]}` object (identified
		 * by having a `conditions` key). Every `operator` value must be in the closed set
		 * `{=, !=, in, not_in}`. Any violation fires `_doing_it_wrong()` so the problem
		 * is caught in development and CI rather than silently at checkout.
		 *
		 * @since 2.0.2
		 *
		 * @param bool|array<string, mixed> $required Normalized `required` value from the descriptor.
		 *
		 * @return void
		 */
		private static function validate_required_spec( $required ): void {
			if ( ! is_array( $required ) ) {
				return; // Plain bool — always valid.
			}

			$valid_operators = [ '=', '!=', 'in', 'not_in' ];
			$caller          = 'Woodev\\Framework\\Shipping\\Checkout\\Checkout_Fields::add';

			if ( isset( $required['operator'] ) ) {
				// Single-condition spec: {state, operator, value}.
				if ( ! in_array( $required['operator'], $valid_operators, true ) ) {
					_doing_it_wrong(
						$caller,
						sprintf(
							/* translators: 1: supplied operator, 2: comma-separated list of valid operators */
							'Invalid condition-spec operator "%1$s". Allowed operators: %2$s.',
							(string) $required['operator'],
							implode( ', ', $valid_operators )
						),
						'2.0.2'
					);
				}

				return;
			}

			if ( isset( $required['conditions'] ) && is_array( $required['conditions'] ) ) {
				// Multi-condition spec: {relation, conditions[]}.
				foreach ( $required['conditions'] as $condition ) {
					if ( ! is_array( $condition ) ) {
						_doing_it_wrong(
							$caller,
							'Each entry in a multi-condition required spec must be an array.',
							'2.0.2'
						);
						continue;
					}

					if ( isset( $condition['operator'] ) && ! in_array( $condition['operator'], $valid_operators, true ) ) {
						_doing_it_wrong(
							$caller,
							sprintf(
								/* translators: 1: supplied operator, 2: comma-separated list of valid operators */
								'Invalid condition-spec operator "%1$s". Allowed operators: %2$s.',
								(string) $condition['operator'],
								implode( ', ', $valid_operators )
							),
							'2.0.2'
						);
					}
				}

				return;
			}

			// Non-empty array that is neither a single-condition spec nor a multi-condition spec.
			_doing_it_wrong(
				$caller,
				'A required condition-spec array must contain either an "operator" key (single condition) or a "conditions" key (multi-condition).',
				'2.0.2'
			);
		}

		/**
		 * Normalizes a raw field definition to the core schema.
		 *
		 * Casts every known key to its declared type and fills defaults so the result
		 * is always well-formed. Callable seams are kept only when they are actually
		 * callable; otherwise they are normalized to `null`. The `required` key is
		 * special: when the host plugin supplies an array condition-spec it is preserved
		 * verbatim; scalar values are coerced to `bool`.
		 *
		 * New generic keys added in 2.0.2: `section`, `depends_on`, `source`,
		 * `source_kind`, `takeover_condition`. `is_pickup_slot` added in 2.0.2
		 * (Task 4): marks a field as a pickup slot anchor for the SP-5 adapter.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `section`, `depends_on`, `source`, `source_kind`,
		 *              `takeover_condition`; `required` array preserved verbatim;
		 *              `is_pickup_slot` bool (default false).
		 *
		 * @param array<string, mixed> $definition raw field definition
		 *
		 * @return array{
		 *     id: string,
		 *     type: string,
		 *     label: string,
		 *     section: string,
		 *     required: bool|array<string, mixed>,
		 *     depends_on: string|null,
		 *     source: callable|null,
		 *     source_kind: string|null,
		 *     takeover_condition: callable|null,
		 *     sanitize_callback: callable|null,
		 *     validate_callback: callable|null,
		 *     is_pickup_slot: bool
		 * }
		 */
		public static function normalize( array $definition ): array {
			$sanitize = $definition['sanitize_callback'] ?? null;
			$validate = $definition['validate_callback'] ?? null;
			$source   = $definition['source'] ?? null;
			$takeover = $definition['takeover_condition'] ?? null;
			$required = $definition['required'] ?? false;

			return [
				'id'                 => (string) ( $definition['id'] ?? '' ),
				'type'               => (string) ( $definition['type'] ?? 'text' ),
				'label'              => (string) ( $definition['label'] ?? '' ),
				'section'            => (string) ( $definition['section'] ?? 'order' ),
				'required'           => is_array( $required ) ? $required : (bool) $required,
				'depends_on'         => isset( $definition['depends_on'] ) && '' !== (string) $definition['depends_on']
					? (string) $definition['depends_on']
					: null,
				'source'             => is_callable( $source ) ? $source : null,
				'source_kind'        => isset( $definition['source_kind'] ) && '' !== (string) $definition['source_kind']
					? (string) $definition['source_kind']
					: null,
				'takeover_condition' => is_callable( $takeover ) ? $takeover : null,
				'sanitize_callback'  => is_callable( $sanitize ) ? $sanitize : null,
				'validate_callback'  => is_callable( $validate ) ? $validate : null,
				'is_pickup_slot'     => (bool) ( $definition['is_pickup_slot'] ?? false ),
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

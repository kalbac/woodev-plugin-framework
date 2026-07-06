<?php
/**
 * Woodev Checkout Field Builder
 *
 * Fluent builder that assembles a raw field definition array for passing into
 * {@see Checkout_Fields::add()}. The builder holds the definition in its raw
 * form; normalization (type-coercion, callable guards, default filling) is
 * performed by {@see Checkout_Fields::normalize()} when the array is added to
 * the collection.
 *
 * Usage:
 *
 *   $field = Field::create( 'billing_city' )
 *       ->set_type( 'select' )
 *       ->set_label( 'Город' )
 *       ->set_required( true )
 *       ->depends_on( 'billing_state' )
 *       ->set_source( $src, 'suggest' );
 *
 *   $checkout_fields->add( $field );
 *
 * Pure PHP — no WooCommerce calls. See
 * docs-internal/specs/2026-07-06-checkout-field-layer-design.md §5.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Field' ) ) :

	/**
	 * Fluent builder for a single checkout field definition.
	 *
	 * Accumulates raw field keys and returns them via {@see to_array()} for
	 * consumption by {@see Checkout_Fields::add()}, which normalizes the result.
	 * No type coercion or default-filling is done here — the builder only records
	 * what the host plugin explicitly sets.
	 *
	 * @since 2.0.2
	 */
	class Field {

		/**
		 * Raw definition accumulator.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, mixed>
		 */
		private array $def;

		/**
		 * Constructor — private, use {@see create()} instead.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id field identifier supplied by the host plugin.
		 */
		private function __construct( string $id ) {
			$this->def = [ 'id' => $id ];
		}

		/**
		 * Creates a new Field builder for the given field id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id field identifier supplied by the host plugin.
		 *
		 * @return self
		 */
		public static function create( string $id ): self {
			return new self( $id );
		}

		/**
		 * Sets the input type (e.g. `'text'`, `'hidden'`, `'select'`).
		 *
		 * @since 2.0.2
		 *
		 * @param string $type input type.
		 *
		 * @return self
		 */
		public function set_type( string $type ): self {
			$this->def['type'] = $type;
			return $this;
		}

		/**
		 * Sets the human-readable label shown in the checkout form.
		 *
		 * @since 2.0.2
		 *
		 * @param string $label field label.
		 *
		 * @return self
		 */
		public function set_label( string $label ): self {
			$this->def['label'] = $label;
			return $this;
		}

		/**
		 * Sets the checkout section this field belongs to.
		 *
		 * Accepted values: `'order'` (default after normalization), `'billing'`,
		 * `'shipping'`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $section checkout section slug.
		 *
		 * @return self
		 */
		public function set_section( string $section ): self {
			$this->def['section'] = $section;
			return $this;
		}

		/**
		 * Sets whether the field is required.
		 *
		 * Accepts either a plain `bool` or an array condition-spec. The condition-spec
		 * is preserved verbatim by {@see Checkout_Fields::normalize()} so the checkout
		 * handler can evaluate it at runtime.
		 *
		 * @since 2.0.2
		 *
		 * @param bool|array<string, mixed> $required `true`/`false`, or a condition-spec array.
		 *
		 * @return self
		 */
		public function set_required( $required ): self {
			$this->def['required'] = $required;
			return $this;
		}

		/**
		 * Declares that this field depends on another field's value.
		 *
		 * The checkout handler uses this to hide/show the field when the parent
		 * field's value changes.
		 *
		 * @since 2.0.2
		 *
		 * @param string $parent_id id of the parent field.
		 *
		 * @return self
		 */
		public function depends_on( string $parent_id ): self {
			$this->def['depends_on'] = $parent_id;
			return $this;
		}

		/**
		 * Attaches a callable that provides option or suggestion items.
		 *
		 * @since 2.0.2
		 *
		 * @param callable $source callable returning the items array.
		 * @param string   $kind   `'options'` (default) or `'suggest'`.
		 *
		 * @return self
		 */
		public function set_source( callable $source, string $kind = 'options' ): self {
			$this->def['source']      = $source;
			$this->def['source_kind'] = $kind;
			return $this;
		}

		/**
		 * Attaches a callable that decides whether this field should take over
		 * native WooCommerce checkout output.
		 *
		 * @since 2.0.2
		 *
		 * @param callable $predicate receives WC context; returns bool.
		 *
		 * @return self
		 */
		public function set_takeover_condition( callable $predicate ): self {
			$this->def['takeover_condition'] = $predicate;
			return $this;
		}

		/**
		 * Attaches a callable that sanitizes the posted field value.
		 *
		 * @since 2.0.2
		 *
		 * @param callable $cb sanitize callback.
		 *
		 * @return self
		 */
		public function set_sanitize_callback( callable $cb ): self {
			$this->def['sanitize_callback'] = $cb;
			return $this;
		}

		/**
		 * Attaches a callable that validates the posted field value.
		 *
		 * @since 2.0.2
		 *
		 * @param callable $cb validate callback.
		 *
		 * @return self
		 */
		public function set_validate_callback( callable $cb ): self {
			$this->def['validate_callback'] = $cb;
			return $this;
		}

		/**
		 * Returns the raw definition array accumulated by the builder.
		 *
		 * The returned array is intentionally un-normalized — keys set via the
		 * builder methods are present as-is; keys never set are absent. Pass the
		 * result to {@see Checkout_Fields::add()}, which fills defaults and
		 * performs type coercion.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return $this->def;
		}
	}

endif;

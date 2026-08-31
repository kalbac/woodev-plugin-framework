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
		 * IMPORTANT — this is INERT on a field WooCommerce declares itself, and that is
		 * deliberate (#483, operator decision 31.08.2026). The label does reach
		 * `WC()->checkout()->get_checkout_fields()`, but for a native address field
		 * (`billing_city`, `billing_state`, `billing_address_1`, `billing_postcode`, and
		 * their `shipping_*` twins) WooCommerce's own
		 * `assets/js/frontend/address-i18n.js` overwrites the rendered `<label>` on
		 * `country_to_state_changing` with the country locale's label — and
		 * `WC_Countries::get_country_locale()['default']` carries a label for exactly
		 * those fields (measured on the rig, 31.08.2026). Taking such a field over with
		 * {@see source_location()} does not change that.
		 *
		 * That is the wanted behaviour, not a defect to fix: shops routinely rename
		 * WooCommerce's address labels from their own code or a third-party plugin, and a
		 * framework-supplied label would compete with that rename. So this setter applies
		 * only to fields WooCommerce does NOT define itself — the host plugin's own.
		 *
		 * Deliberately no `_doing_it_wrong()`: the call is harmless, and the notice would
		 * be production noise.
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
		 * Sets a dedicated label for generated error messages, independent of
		 * {@see set_label()}'s visual label.
		 *
		 * Use this when the field's visible control is not its own native input
		 * (e.g. a hidden pickup-point field driven by a "Choose pickup point"
		 * button): {@see set_label()} is then legitimately left blank, but error
		 * messages still need a human name instead of falling back to the raw
		 * field `id` (#299, #134). See
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::message_label()}
		 * for the full fallback order (`error_label` → `label` → `id`).
		 *
		 * @since 2.0.2
		 *
		 * @param string $label messages-only label.
		 *
		 * @return self
		 */
		public function set_error_label( string $label ): self {
			$this->def['error_label'] = $label;
			return $this;
		}

		/**
		 * Replaces the WHOLE "you must supply this" checkout error message for this
		 * field, rather than only the label substituted into the framework's own
		 * template.
		 *
		 * Applies to ANY field carrying it — the framework keeps one seam rather than
		 * a second, pickup-only one — but the case it exists for is the button-driven
		 * field. An ordinary typed input is usually better served by
		 * {@see set_error_label()}, which keeps the framework's template and so stays
		 * consistent with every other field on the checkout.
		 *
		 * It exists because a template cannot be made carrier-neutral in the one case
		 * that needs it (#327). A field whose visible control is a BUTTON has no
		 * value to specify, so the framework says «Вы не выбрали пункт выдачи
		 * заказов.» instead of «Укажите значение поля «…».» — but «пункт выдачи» is
		 * OUR vocabulary, and Почта РФ has отделения. Same ownership split #323
		 * settled for the trigger button itself: the framework owns where and when a
		 * message appears, the plugin owns the words. {@see set_error_label()} is not
		 * enough here — substituting «Отделение» into a sentence built around the word
		 * «поле» still describes an input.
		 *
		 * Honoured by both paths that can report a missing pickup point: the
		 * per-field required loop and the independent backstop
		 * ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::validate()}) —
		 * the override is a statement about the FIELD, not about a code path.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message the complete message shown to the customer.
		 *
		 * @return self
		 */
		public function set_required_message( string $message ): self {
			$this->def['required_message'] = $message;
			return $this;
		}

		/**
		 * Replaces the WHOLE "this value is wrong" checkout error message for this field —
		 * the sibling seam to {@see self::set_required_message()}, for the OTHER outcome
		 * (#328).
		 *
		 * Reached only when this field's own {@see self::set_validate_callback()} returns a
		 * bare `false`. A callback returning a `WP_Error` already carries its own words and
		 * is never overridden; a callback returning `true` never reaches a message at all.
		 * So this is a seam for plugins that validate with a boolean and want the failure
		 * described in their own vocabulary.
		 *
		 * WHY THE FRAMEWORK SHIPS NO BUTTON-SPECIFIC DEFAULT HERE, unlike its sibling: for a
		 * field whose visible control is a BUTTON, «Поле «Пункт выдачи» заполнено
		 * некорректно.» sends the customer looking for an input that is not on the page —
		 * the same defect #327 fixed for the "you must supply this" message. But the honest
		 * replacement is not a rewording: for an already-CHOSEN point, "filled in
		 * incorrectly" most likely means "that point is unavailable", which is a different
		 * statement, and only the domain knows whether it is the true one. So the framework
		 * offers the seam and lets the plugin say it (#328's own recommendation), rather
		 * than coining a sentence it cannot know is accurate.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message the complete message shown to the customer.
		 *
		 * @return self
		 */
		public function set_invalid_message( string $message ): self {
			$this->def['invalid_message'] = $message;
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
		 * Declares this field as backed by the store-level Location Provider layer at
		 * the given cascade level (location-provider spec §4.4, D1).
		 *
		 * Sets `source_kind` to `'location'` and records the level so
		 * {@see Checkout_Fields::normalize()} carries both through to the checkout
		 * config. The framework maps whichever location-kind fields the host plugin
		 * declares onto the cascade chain country → region → settlement → address,
		 * skipping absent links — field presence stays the plugin's own decision,
		 * this builder only labels ONE field's place in that chain.
		 *
		 * The checkout SECTION this field attaches to is NOT the plugin's decision
		 * either (AGENT-RULES.md Rule 7b, issue #458): whatever {@see self::set_section()}
		 * is called with here is overridden by
		 * `Checkout_Handler::effective_fields()`, which derives it from the store's
		 * `woocommerce_ship_to_destination` setting instead — `billing` alone when
		 * shipping is forced to the billing address, `billing` AND `shipping`
		 * otherwise. That fan-out derives the sibling id by swapping a leading
		 * `billing_`/`shipping_` prefix off THIS field's own id, so declare the id
		 * following WooCommerce's own `_state`/`_city`/`_address_1` convention (e.g.
		 * `shipping_city`, matching the id `billing_city`/`shipping_city` would take
		 * natively) — an id with neither prefix still works, but yields the same
		 * derived pair either way, so the prefix is only for the id to read sensibly
		 * on its own.
		 *
		 * Mutually exclusive with {@see self::set_source()} in practice: a
		 * location-backed field's data comes from the framework's own Location
		 * Provider REST seam (`woodev/v1/location/*`), never a plugin-supplied
		 * callable — calling both simply lets the later call win, same as setting
		 * `source_kind` twice via any other builder method.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Section is now framework-derived per Rule 7b rather than the
		 *              plugin's own {@see self::set_section()} value (issue #458).
		 *
		 * @param string $level One of `'region'`, `'settlement'`, `'address'` — mirrors
		 *                      `Woodev\Framework\Shipping\Location\Location_Record::LEVELS`.
		 *                      Not type-checked against that class here: this builder stays
		 *                      free of any WooCommerce/location-layer dependency (class
		 *                      docblock, "Pure PHP — no WooCommerce calls"), so an unknown
		 *                      level string is carried through unchanged rather than
		 *                      rejected — the consuming layers validate it.
		 *
		 * @return self
		 */
		public function source_location( string $level ): self {
			$this->def['source_kind']    = 'location';
			$this->def['location_level'] = $level;
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
		 * Marks this field as a pickup slot anchor.
		 *
		 * Sets `is_pickup_slot = true` in the raw definition so the checkout
		 * adapter can locate the correct injection point for the SP-5 slot.
		 * After normalization via {@see Checkout_Fields::normalize()}, the flag
		 * is always present as a `bool`.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public function mark_pickup_slot(): self {
			$this->def['is_pickup_slot'] = true;
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

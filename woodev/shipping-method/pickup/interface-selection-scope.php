<?php
/**
 * Woodev Pickup Selection Scope
 *
 * The plugin seam behind pickup-selection persistence (SP-5 T5, issue #176). A shipping
 * plugin implements this to tell the framework's session-backed selection map what a
 * "locality" and a "type" mean for its carrier; the framework itself never learns anything
 * about either.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Selection_Scope' ) ) :

	/**
	 * Answers the questions {@see Pickup_Selection} needs to key and restore a
	 * remembered pickup-point selection, without the framework ever interpreting what
	 * a locality or a type IS.
	 *
	 * THE LOCALITY KEY IS A DOMAIN PRIMARY KEY, NOT A PLACE NAME. Carriers do not agree
	 * on what a locality is — Почта РФ addresses by settlement name, СДЭК by `city_id`
	 * in its own database, Яндекс Доставка by `geo_id` in its own — and a customer also
	 * spells one city several ways ("Санкт-Петербург" / "Санкт Петербург" / "Питер").
	 * The framework therefore never derives the key, never normalizes it, and never
	 * compares it to anything but another string produced by THIS SAME scope.
	 *
	 * {@see self::locality_for_point()} and {@see self::current_locality()} are
	 * asymmetric ON PURPOSE, and that asymmetry is taken from the reference
	 * implementation: `woocommerce-edostavka` writes under
	 * `$data['location']['city_code']` (off the point being confirmed) and reads with
	 * `$customer_handler->get_city_code()` (off live checkout state) —
	 * `functions.php:896` and `:919`. One answers a question about a POINT, the other a
	 * question about an ORDER, and a plugin is free to answer them from entirely
	 * different data.
	 *
	 * @since 2.0.2
	 */
	interface Selection_Scope {

		/**
		 * {@see self::type_for_method()} return value meaning "restore the most
		 * recently written entry for the locality, regardless of type" — a pickup
		 * method that is not itself type-specific.
		 *
		 * @since 2.0.2
		 */
		public const TYPE_ANY = '*';

		/**
		 * Returns the session key under which this plugin's selection map lives.
		 *
		 * This is an installed-site data contract owned by the plugin, not the
		 * framework — the framework must never coin one of its own (gotcha
		 * `session-key-vs-order-meta-prefix`). Called on every write and every
		 * restore.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function session_key(): string;

		/**
		 * Returns the locality key that the given, just-confirmed point belongs to.
		 *
		 * Called on write, once per confirmed selection. Answers a question about the
		 * POINT — typically a field already present on it (a `city_id`, a `geo_id`,
		 * whatever the carrier's own payload carries) — not about the current
		 * checkout state; see {@see self::current_locality()} for that half.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point The point that was just confirmed.
		 *
		 * @return string
		 */
		public function locality_for_point( Pickup_Point $point ): string;

		/**
		 * Returns the locality key the order is being placed to RIGHT NOW.
		 *
		 * Called on restore. Answers a question about live checkout state — the
		 * customer's currently entered address, a chosen region, whatever the plugin's
		 * own customer-location tracking already resolves — never about a specific
		 * point; see {@see self::locality_for_point()} for that half. The two need not
		 * share an implementation.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function current_locality(): string;

		/**
		 * Returns the point TYPE this shipping method restores a selection for.
		 *
		 * Called on restore, with the method id ALREADY NORMALIZED — the
		 * `:instance_id` suffix WooCommerce appends is stripped before this method
		 * ever sees it, the same normalization
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler} applies
		 * everywhere else it compares a method id.
		 *
		 * Three return cases, all load-bearing:
		 *
		 * - a type CODE (matching {@see Pickup_Point}'s own `type.code`) — this method
		 *   wants a point of that exact type; the framework restores
		 *   `map[ locality ][ code ]`.
		 * - {@see self::TYPE_ANY} — a pickup method that is not itself type-specific;
		 *   the framework restores the most recently written entry for the locality,
		 *   regardless of type.
		 * - `null` — this method gets NO restored selection at all: a courier method,
		 *   or the plugin deliberately opting out. Nothing is read, and nothing already
		 *   stored is touched.
		 *
		 * A plugin whose carrier has exactly one point type may simply always return
		 * that one code — types are domain values, so it always has one to name.
		 *
		 * `$method_id` IS `''` WHENEVER WOOCOMMERCE CANNOT YET TELL US which method the
		 * customer chose — no session started, or no shipping rate resolved for this
		 * request. `''` means "unknown", never "no method": match it as unknown and
		 * return `null`, and in particular never let it fall through a default branch
		 * that answers with a real type. The same sentinel, with the same rule, is
		 * documented on `woodev_shipping_pickup_point_selection`'s `$context['method_id']`
		 * — an implementation written as `return 'courier' === $method_id ? null : 'PVZ';`
		 * satisfies neither, because it answers `'PVZ'` for a method nobody has chosen.
		 * Prefer matching your own pickup method ids positively.
		 *
		 * @since 2.0.2
		 *
		 * @param string $method_id Bare shipping-method id (no `:instance_id` suffix), or
		 *                          `''` when the chosen method is not yet known — see above.
		 *
		 * @return string|null A type code, {@see self::TYPE_ANY}, or `null`.
		 */
		public function type_for_method( string $method_id ): ?string;
	}

endif;

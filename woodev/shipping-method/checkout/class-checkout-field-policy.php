<?php
/**
 * Woodev Checkout Field Policy
 *
 * Store-level singleton that turns the «Поля» settings (Task 5's
 * {@see Checkout_Field_Settings}) into real WooCommerce checkout behaviour (issue #362,
 * design §4.3). ONE policy object, TWO instruments:
 *
 *  - `woocommerce_get_country_locale` (Instrument A) — reaches BOTH the classic and the
 *    block checkout (measured on WC 11.0.1: `CartCheckoutUtils::get_country_data()` maps
 *    the locale entry's `priority` to the client's `index`, and treats `hidden` as
 *    `required=false` + not rendered). {@see self::locale_contribution()} contributes
 *    per-field `priority`/`hidden`/`required` for every shipping country of the store.
 *  - `woocommerce_checkout_fields` (Instrument B) — the classic checkout ONLY, by
 *    construction (`CheckoutFields::get_core_fields()` hard-codes the core address
 *    fields on the block checkout — gotcha
 *    `block-checkout-reads-country-locale-not-checkout-fields`).
 *    {@see self::checkout_fields_contribution()} `unset()`s a field the merchant chose
 *    to REMOVE from both the `billing` and `shipping` sections, so it leaves the DOM
 *    entirely and its value never reaches the order.
 *
 * Removal is never done in JS; hiding is never done by unsetting (T1/T2). Both filters
 * run at {@see self::LATE} — "after everyone who has an opinion" (T4) — so WooCommerce's
 * own defaults and any third-party field-manager plugin have already had their say by
 * the time this policy runs, and Task 7's settlement-invariant RESTORATION (which
 * extends this same class rather than duplicating it) can still see a later hook if it
 * ever needs one.
 *
 * `address_field=hide_for_pickup`, `postcode_field=hide_for_pickup` and
 * `country_field=hide` are classic-only, JS-driven (Task 9's `checkout-field-classic.js`)
 * — this class never acts on them; {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config}
 * only PUBLISHES their effective values to the browser.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping\Checkout
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Field_Policy' ) ) :

	/**
	 * Store-level singleton applying the «Поля» policy to the real WooCommerce checkout.
	 *
	 * @since 2.0.2
	 */
	final class Checkout_Field_Policy {

		/**
		 * Filter priority both instruments run at — "after everyone who has an opinion"
		 * (T4): after WooCommerce's own defaults and after a typical third-party
		 * field-manager plugin, while still leaving `PHP_INT_MAX - 10` of headroom below
		 * `PHP_INT_MAX` for Task 7's invariant-restoration hook to run even later if it
		 * ever needs to. The exact number is arbitrary; the ordering rule it encodes is
		 * not.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		const LATE = PHP_INT_MAX - 10;

		/**
		 * Option name persisting the last non-empty settlement-invariant override
		 * report (S8) across requests: {@see self::restore_invariants()} observes it
		 * on a FRONTEND checkout render, but {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings::get_section_note()}
		 * reads it from a completely different (wp-admin) request — so it must be
		 * persisted somewhere. A plain option, not a transient: {@see self::persist_overrides()}
		 * actively `delete_option()`s it the moment a checkout render finds nothing
		 * left to restore, which clears a stale note on the VERY NEXT observation
		 * rather than waiting out a transient's expiry — a stale note accusing a
		 * plugin that has since been deactivated is worse than no note at all.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		const OPTION_LAST_OVERRIDES = 'woodev_checkout_fields_last_overrides';

		/**
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * The live «Поля» settings handler {@see self::register()} was last given.
		 *
		 * @var Checkout_Field_Settings|null
		 */
		private $settings = null;

		/**
		 * The `effective()` map for all five owned settings, computed once per request
		 * (design intent: the two filter callbacks below must never see two different
		 * answers within the same request). Reset whenever {@see self::register()} is
		 * called with a (possibly new) settings handler.
		 *
		 * @var array<string, bool|string>|null
		 */
		private $effective_cache = null;

		/**
		 * Whether the two filters have already been added.
		 *
		 * @var bool
		 */
		private $hooked = false;

		/**
		 * The settlement-invariant overrides {@see self::restore_invariants()} recorded
		 * on its most recent call — reset at the START of every call, never
		 * accumulated across repeat filter applications within the same request.
		 *
		 * @since 2.0.2
		 * @var array<int, array{field: string, section: string, what: string}>
		 */
		private $overrides = [];

		/**
		 * Returns the singleton instance.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Resets all state. Test-only — same shape as
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests()},
		 * including its `remove_filter()` half: `self::$instance = null` alone discards
		 * the PHP-level singleton reference but leaves any `add_filter()` call the OLD
		 * instance made intact in WordPress's own hook table (`add_filter()`/
		 * `remove_filter()` match by object identity, not by class), so a lingering
		 * registration would keep firing against the discarded instance on every later
		 * `apply_filters()` call in the same test process.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::remove_hooked_instances( 'woocommerce_get_country_locale', self::class, 'filter_country_locale' );
			self::remove_hooked_instances( 'woocommerce_checkout_fields', self::class, 'filter_checkout_fields' );

			self::$instance = null;
		}

		/**
		 * Removes every `$class::$method` callback registered on `$hook`, whichever
		 * instance registered it. A no-op outside a full WordPress runtime (unit tests
		 * run on Brain Monkey, where `$wp_filter` does not exist).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param string $hook   hook name to scrub.
		 * @param string $class  fully-qualified class whose callbacks should be removed.
		 * @param string $method method name to match.
		 *
		 * @return void
		 */
		private static function remove_hooked_instances( string $hook, string $class, string $method ): void {
			$hooks = $GLOBALS['wp_filter'] ?? [];

			if ( ! isset( $hooks[ $hook ] ) || ! is_object( $hooks[ $hook ] ) || ! isset( $hooks[ $hook ]->callbacks ) ) {
				return;
			}

			foreach ( $hooks[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;

					if ( ! is_array( $function ) || 2 !== count( $function ) ) {
						continue;
					}

					if ( $function[0] instanceof $class && $method === $function[1] ) {
						remove_filter( $hook, $function, $priority );
					}
				}
			}
		}

		/**
		 * Boots the policy against the given settings handler and hooks the two
		 * instruments — exactly once. Called from
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::register()},
		 * which runs on every request (including admin and REST, `init` priority 25);
		 * harmless — the callbacks below are cheap, and re-registration is guarded so a
		 * double call never double-adds the filters.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Field_Settings $settings the live «Поля» settings handler — always
		 *                                           {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::get_field_settings()},
		 *                                           never a fresh instance (its availability
		 *                                           rules must not be computed twice with
		 *                                           different answers).
		 *
		 * @return void
		 */
		public function register( Checkout_Field_Settings $settings ): void {
			$this->settings        = $settings;
			$this->effective_cache = null;

			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_filter( 'woocommerce_get_country_locale', [ $this, 'filter_country_locale' ], self::LATE );
			add_filter( 'woocommerce_checkout_fields', [ $this, 'filter_checkout_fields' ], self::LATE );
		}

		/**
		 * `woocommerce_get_country_locale` callback (Instrument A) — reaches both the
		 * classic and the block checkout.
		 *
		 * @internal bound at {@see self::LATE} by {@see self::register()}.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $locale the locale array WooCommerce/third-party plugins built so far.
		 *
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public function filter_country_locale( $locale ): array {
			return self::locale_contribution( $this->effective(), (array) $locale, $this->shipping_countries() );
		}

		/**
		 * `woocommerce_checkout_fields` callback (Instrument B) — classic checkout only,
		 * by construction (the block checkout never reads this filter). Also runs
		 * Task 7's settlement-invariant RESTORATION (S8) AFTER this policy's own
		 * contribution, so it sees the final `city`/`billing_city`/`shipping_city`
		 * state a third-party field-manager plugin left behind and restores exactly
		 * what that plugin removed or relaxed — see {@see self::restore_invariants()}.
		 *
		 * @internal bound at {@see self::LATE} by {@see self::register()}.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Restores the settlement-field invariants and persists the
		 *              override report (Task 7, issue #362, design S8).
		 *
		 * @param mixed $fields the checkout fields array WooCommerce/third-party plugins built so far.
		 *
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public function filter_checkout_fields( $fields ): array {
			$fields = self::checkout_fields_contribution( $this->effective(), (array) $fields );

			/**
			 * Filters whether {@see self::restore_invariants()} should run on this
			 * request. A legitimate escape hatch for a carrier plugin whose own field
			 * manager deliberately needs `city` non-required for a specific flow (e.g.
			 * a cash/self-pickup order) — the framework's own default is always to
			 * restore. Never a new SETTING (design's standing rule): a filter only a
			 * developer can reach, not a merchant-facing control.
			 *
			 * @since 2.0.2
			 *
			 * @param bool                                                 $restore whether to restore the settlement invariants.
			 * @param array<string, array<string, array<string, mixed>>> $fields  the checkout fields array so far (after this policy's own contribution).
			 */
			if ( apply_filters( 'woodev_checkout_field_policy_restore_invariants', true, $fields ) ) {
				$fields = $this->restore_invariants( $fields, $this->default_address_fields() );

				$this->persist_overrides();
			}

			return $fields;
		}

		/**
		 * The `effective()` value of all five owned settings, computed once per request
		 * and cached — see {@see self::$effective_cache}'s own docblock.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, bool|string>
		 */
		private function effective(): array {
			if ( null === $this->effective_cache ) {
				$values = [];

				if ( null !== $this->settings ) {
					foreach ( $this->settings->get_owned_setting_ids() as $id ) {
						$values[ $id ] = $this->settings->effective( $id );
					}
				}

				$this->effective_cache = $values;
			}

			return $this->effective_cache;
		}

		/**
		 * The store's shipping countries (S5 — the preset/removal rules apply to ALL of
		 * them, not a fixed CIS list and not "countries the location layer serves").
		 * Guarded for the unit suite (no `WC()` there): returns `[]`, which makes
		 * {@see self::locale_contribution()} a no-op — an unconfigured store gets no
		 * contribution, matching S5's own "if that list is empty, the preset contributes
		 * nothing" clause.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		private function shipping_countries(): array {
			if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
				return [];
			}

			return array_keys( WC()->countries->get_shipping_countries() );
		}

		/**
		 * Instrument A's pure contribution. Every shipping country's locale entry gets
		 * the `field_order_preset` priorities and/or the region/postcode `remove`
		 * hide+unrequire, plus the settlement invariant (`city.required = true`, always,
		 * re-asserted LAST so nothing above it can clobber it — Task 7's restoration
		 * extends this same seam).
		 *
		 * Creates a fresh per-country entry — and fresh per-field sub-arrays — for a
		 * country/field WC's own base locale has none for, rather than only merging into
		 * ones that already exist: {@see self::LATE}'s whole point is running AFTER
		 * WooCommerce's own `get_country_locale()` base map is already in `$locale`, and
		 * that method merges this filtered array back over its base map BY COUNTRY KEY —
		 * a new key for a real ISO country WC recognises (e.g. every one of the store's
		 * own shipping countries) is a completely normal per-country override, the exact
		 * same shape any third-party plugin's own `woocommerce_get_country_locale`
		 * callback already produces. Every field key is pre-seeded to `[]` when absent —
		 * not only the ones a setting actually touches — so a caller can always safely
		 * read e.g. `$out[$country]['country']` even when `field_order_preset` is off.
		 *
		 * Pure — touches no WordPress function, so unit tests call it directly.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, bool|string>                         $settings           `effective()` values, keyed by setting id
		 *                                                                                 (only `field_order_preset`/`region_field`/
		 *                                                                                 `postcode_field` are read).
		 * @param array<string, array<string, array<string, mixed>>> $locale             the locale array to contribute onto.
		 * @param string[]                                           $shipping_countries every shipping country of the store (S5).
		 *
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public static function locale_contribution( array $settings, array $locale, array $shipping_countries ): array {
			$preset           = ! empty( $settings['field_order_preset'] );
			$region_removed   = 'remove' === ( $settings['region_field'] ?? 'show' );
			$postcode_removed = 'remove' === ( $settings['postcode_field'] ?? 'show' );

			foreach ( $shipping_countries as $country ) {
				if ( ! isset( $locale[ $country ] ) ) {
					$locale[ $country ] = [];
				}

				foreach ( [ 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ] as $field ) {
					if ( ! isset( $locale[ $country ][ $field ] ) ) {
						$locale[ $country ][ $field ] = [];
					}
				}

				if ( $preset ) {
					/*
					 * The preset reorders the ADDRESS BLOCK only, and stays inside the band
					 * WooCommerce already reserves for it (measured on WC 11.0.1 via
					 * `WC_Countries::get_default_address_fields()`): first_name 10, last_name 20,
					 * company 30, country 40, address_1 50, address_2 60, city 70, state 80,
					 * postcode 90, phone 100, email 110.
					 *
					 * The design document proposed 10/20/30/40/50/60 for this block. Those numbers
					 * COLLIDE with the name block and were measured on the rig producing
					 * «Имя · Страна · Фамилия · Регион · Город …» — the customer's name split in
					 * half by address fields. Keeping the same relative order the design asks for
					 * (Страна > Регион > Город > Адрес > Кв. > Индекс) inside WC's own 40–90 band
					 * fixes that without touching any field the preset has no opinion about, and
					 * leaves a third-party field slotted at e.g. 45 sitting inside the address
					 * block rather than jumping ahead of the name.
					 */
					$locale[ $country ]['country']['priority']   = 40;
					$locale[ $country ]['state']['priority']     = 50;
					$locale[ $country ]['city']['priority']      = 60;
					$locale[ $country ]['address_1']['priority'] = 70;
					$locale[ $country ]['address_2']['priority'] = 80;
					$locale[ $country ]['postcode']['priority']  = 90;
				}

				if ( $region_removed ) {
					$locale[ $country ]['state']['hidden']   = true;
					$locale[ $country ]['state']['required'] = false;
				}

				if ( $postcode_removed ) {
					$locale[ $country ]['postcode']['hidden']   = true;
					$locale[ $country ]['postcode']['required'] = false;
				}

				// Settlement invariant — always, re-asserted LAST. Task 7 extends this
				// same seam with restoration of a third-party-removed city field; this
				// line is the already-required baseline it builds on.
				$locale[ $country ]['city']['required'] = true;
			}

			return $locale;
		}

		/**
		 * Instrument B's pure contribution — a field the merchant set to `remove` is
		 * `unset()` from BOTH the `billing` and `shipping` sections, so it leaves the DOM
		 * entirely (T1/T2: removal is never done in JS, hiding is never done by
		 * unsetting). Classic checkout only, by construction — the block checkout never
		 * reads `woocommerce_checkout_fields`.
		 *
		 * Pure — touches no WordPress function, so unit tests call it directly.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, bool|string>                         $settings `effective()` values, keyed by setting id
		 *                                                                      (only `region_field`/`postcode_field` are read).
		 * @param array<string, array<string, array<string, mixed>>> $fields   the checkout fields array to contribute onto.
		 *
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public static function checkout_fields_contribution( array $settings, array $fields ): array {
			$region_removed   = 'remove' === ( $settings['region_field'] ?? 'show' );
			$postcode_removed = 'remove' === ( $settings['postcode_field'] ?? 'show' );

			foreach ( [ 'billing', 'shipping' ] as $section ) {
				if ( ! isset( $fields[ $section ] ) || ! is_array( $fields[ $section ] ) ) {
					continue;
				}

				if ( $region_removed ) {
					unset( $fields[ $section ][ $section . '_state' ] );
				}

				if ( $postcode_removed ) {
					unset( $fields[ $section ][ $section . '_postcode' ] );
				}
			}

			return $fields;
		}

		/**
		 * Restores the settlement-field invariants a third-party field-manager plugin
		 * broke (S8, design §4.4): `*_city` must EXIST in both the `billing` and
		 * `shipping` sections of the final checkout fields array, and must be
		 * `required`. Runs after {@see self::checkout_fields_contribution()} — and,
		 * by virtue of {@see self::LATE}, after WooCommerce's own defaults and any
		 * third-party field manager — so it restores exactly what got removed or
		 * relaxed. Everything else about `city` (label, class, priority, …) and every
		 * OTHER field is left untouched — that is the field manager's business, not
		 * this framework's (S8's own words: the framework overrides only the values
		 * that are not WC defaults, nothing more).
		 *
		 * Every restoration is recorded into {@see self::$overrides} (never applied
		 * silently) so {@see self::persist_overrides()} can turn it into a report the
		 * admin «Доставка» tab surfaces via
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings::get_section_note()}.
		 * {@see self::$overrides} is reset at the START of every call — this method
		 * describes the outcome of THIS pass over `$fields` only.
		 *
		 * Deliberately an INSTANCE method, not one of this class's other `_contribution()`
		 * statics: unlike those, it has an observable side effect ({@see self::$overrides})
		 * that is itself part of the contract (design §4.4 names
		 * `Checkout_Field_Policy::get_overrides()` as the read side of it) — Task 7's
		 * unit tests construct a plain `new self()` and call this method directly,
		 * exactly like the existing pure statics are called directly, without needing
		 * a WordPress runtime.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<string, array<string, mixed>>> $fields                 the checkout fields array to restore invariants onto — normally
		 *                                                                                     the result of {@see self::checkout_fields_contribution()}.
		 * @param array<string, array<string, mixed>>                $default_address_fields WC's default address-field template
		 *                                                                                    ({@see \WC_Countries::get_default_address_fields()}), UNPREFIXED
		 *                                                                                    keys (`city`, not `billing_city`) — the source for re-inserting
		 *                                                                                    a `city` field a plugin removed entirely.
		 *
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public function restore_invariants( array $fields, array $default_address_fields ): array {
			$this->overrides = [];

			$default_city = $default_address_fields['city'] ?? [];

			foreach ( [ 'billing', 'shipping' ] as $section ) {
				if ( ! isset( $fields[ $section ] ) || ! is_array( $fields[ $section ] ) ) {
					continue;
				}

				$key = $section . '_city';

				if ( ! isset( $fields[ $section ][ $key ] ) || ! is_array( $fields[ $section ][ $key ] ) ) {
					/*
					 * BOTH halves of the invariant are asserted here, not just presence.
					 * `$default_address_fields` comes from `WC_Countries::get_default_address_fields()`,
					 * which is itself filterable through `woocommerce_default_address_fields` — the very
					 * hook a third-party field manager would use to relax `city`. Re-inserting that
					 * template verbatim could therefore restore an OPTIONAL settlement field while
					 * reporting a successful restoration. The same hole opens when the template is
					 * unavailable at all (no WooCommerce runtime), where `$default_city` is `[]`.
					 */
					$fields[ $section ][ $key ]               = $default_city;
					$fields[ $section ][ $key ]['required']   = true;

					$this->overrides[] = [
						'field' => 'city',
						'section' => $section,
						'what' => 'restored',
					];

					continue;
				}

				if ( empty( $fields[ $section ][ $key ]['required'] ) ) {
					$fields[ $section ][ $key ]['required'] = true;

					$this->overrides[] = [
						'field' => 'city',
						'section' => $section,
						'what' => 'required',
					];
				}
			}

			return $fields;
		}

		/**
		 * The overrides {@see self::restore_invariants()} recorded on its most recent
		 * call — part of this class's public contract (design §4.4).
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{field: string, section: string, what: string}>
		 */
		public function get_overrides(): array {
			return $this->overrides;
		}

		/**
		 * WC's default address-field template ({@see \WC_Countries::get_default_address_fields()}),
		 * the source {@see self::restore_invariants()} re-inserts a removed `city`
		 * field from. Guarded for the unit suite (no `WC()` there), same shape as
		 * {@see self::shipping_countries()}: returns `[]`, which degrades
		 * `restore_invariants()`'s re-insertion to an empty stub rather than failing —
		 * harmless outside a real WooCommerce runtime.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private function default_address_fields(): array {
			if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
				return [];
			}

			return WC()->countries->get_default_address_fields();
		}

		/**
		 * Persists {@see self::$overrides} to {@see self::OPTION_LAST_OVERRIDES} for
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings::get_section_note()}
		 * to read from a later (wp-admin) request — a write on a frontend checkout
		 * READ path, so guarded hard: only writes when the report is non-empty AND
		 * differs from what is already stored.
		 *
		 * The control case (S8): once a checkout render finds NOTHING to restore, any
		 * previously stored report is actively deleted — the note disappears on the
		 * very next observation once the offending plugin stops interfering, rather
		 * than lingering and accusing a plugin that may no longer even be active.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function persist_overrides(): void {
			$stored = (array) get_option( self::OPTION_LAST_OVERRIDES, [] );

			if ( empty( $this->overrides ) ) {
				if ( ! empty( $stored ) ) {
					delete_option( self::OPTION_LAST_OVERRIDES );
				}

				return;
			}

			if ( $this->overrides !== $stored ) {
				update_option( self::OPTION_LAST_OVERRIDES, $this->overrides, false );
			}
		}
	}

endif;

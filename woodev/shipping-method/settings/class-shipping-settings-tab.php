<?php
/**
 * Woodev Shipping Settings Tab
 *
 * Registrar of the store-level «Доставка» tab (design S1/S9, issue #362). Consolidates
 * three sections that used to be their own settings surfaces (or, for «Поля»/«Карта»,
 * never had one at all): «Локация» (Task 3's `Location_Provider_Registry`, demoted from
 * its own tab to this tab's first section), «Поля» (checkout field policy, Task 5) and
 * «Карта» (pickup map behaviour, Task 8).
 *
 * Section visibility is DERIVED from what plugins actually supply, never configured
 * (spec S9) — the same `declare_needed()` shape `Location_Provider_Registry` already
 * uses: any {@see \Woodev\Framework\Shipping\Shipping_Plugin} → the tab itself and its
 * «Поля» section; a plugin that also needs the Location Provider layer → «Локация»
 * (handed over by `Location_Provider_Registry::register_settings()` instead of that
 * class registering a tab of its own); a constructed
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler} → «Карта».
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Shipping_Settings_Tab' ) ) :

	/**
	 * Registrar of the store-level «Доставка» tab.
	 *
	 * @since 2.0.2
	 */
	final class Shipping_Settings_Tab {

		/** @var string tab/service id on the SP-1 surface. */
		public const SERVICE_ID = 'shipping';

		/** @var self|null */
		private static $instance = null;

		/** @var bool whether ANY Shipping_Plugin declared itself (opens the tab). */
		private $shipping_plugin_declared = false;

		/** @var bool whether a constructed Pickup_Handler declared it needs the «Карта» section. */
		private $map_needed = false;

		/**
		 * The location layer's settings handler, handed over by
		 * `Location_Provider_Registry::register_settings()` instead of that class
		 * registering a tab of its own. Null when no plugin needs the location layer.
		 *
		 * @var \Woodev_Abstract_Settings|null
		 */
		private $location_handler = null;

		/**
		 * The location handler's owned setting ids, in display order — handed over
		 * alongside `$location_handler` so this class never has to know the location
		 * layer's internal field vocabulary.
		 *
		 * @var string[]
		 */
		private $location_setting_ids = [];

		/** @var Checkout_Field_Settings|null lazily built so tests can read it without WP. */
		private $field_settings = null;

		/** @var Pickup_Map_Settings|null lazily built so tests can read it without WP. */
		private $map_settings = null;

		/** @var bool whether the `init` registration hook has already been added. */
		private $hooked = false;

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
		 * Resets all state. Test-only — mirrors
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::reset_for_tests()},
		 * including its `remove_action()` half: `self::$instance = null` alone discards
		 * the PHP-level singleton reference but leaves any `add_action()` call the OLD
		 * instance made intact in WordPress's own hook table (`add_action()`/
		 * `remove_action()` match by object identity, not by class), so a lingering
		 * registration would keep firing against the discarded instance on every later
		 * `do_action( 'init' )` — see that method's docblock for the full, measured
		 * account of why this matters in the integration suite specifically.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::remove_hooked_instances( 'init', self::class, 'register' );

			self::$instance = null;

			// Checkout_Field_Policy (Task 6, issue #362) is booted from register() above,
			// but is its OWN singleton with its own hooked filters — reset it here too so
			// a test that calls register() more than once never leaks a stale filter
			// registration into a later test in the same process.
			Checkout_Field_Policy::reset_for_tests();
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
						remove_action( $hook, $function, $priority );
					}
				}
			}
		}

		/**
		 * Declares that a shipping plugin exists — opens the tab and its «Поля» section.
		 * Idempotent, same shape as `Location_Provider_Registry::declare_needed()`.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function declare_shipping_plugin(): void {
			$this->shipping_plugin_declared = true;
			$this->hook_once();
		}

		/**
		 * Declares that the «Карта» section is needed — called from the last line of
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::__construct()}.
		 *
		 * A `Pickup_Handler` built AFTER `register()` already fired (`init` priority 25)
		 * — i.e. lazily, instead of during plugin construction at `plugins_loaded` like
		 * every current caller does — is too late to make «Карта» appear; this method
		 * stays a harmless no-op in that case rather than inventing a re-registration
		 * mechanism (YAGNI). Every `Pickup_Handler` in this codebase today is built from
		 * within a `Shipping_Plugin` constructor, which always runs before `init`.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function declare_map_needed(): void {
			$this->map_needed = true;
		}

		/**
		 * Hands the location layer's settings handler over to this tab, instead of
		 * `Location_Provider_Registry` registering a tab of its own. Called from
		 * `Location_Provider_Registry::register_settings()` (`init` priority 20 — before
		 * this class's own `register()`, hooked at priority 25).
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Abstract_Settings $handler the Location_Settings handler.
		 * @param string[]                  $ids     its owned setting ids, in display order.
		 *
		 * @return void
		 */
		public function set_location_section( $handler, array $ids ): void {
			$this->location_handler     = $handler;
			$this->location_setting_ids = $ids;
		}

		/**
		 * Whether the tab is needed at all (a shipping plugin declared itself).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_needed(): bool {
			return $this->shipping_plugin_declared;
		}

		/**
		 * Lazily built so tests can read the handler without a WordPress runtime.
		 *
		 * @since 2.0.2
		 *
		 * @return Checkout_Field_Settings
		 */
		public function get_field_settings(): Checkout_Field_Settings {
			if ( null === $this->field_settings ) {
				$this->field_settings = new Checkout_Field_Settings();
			}

			return $this->field_settings;
		}

		/**
		 * Lazily built so tests can read the handler without a WordPress runtime.
		 *
		 * @since 2.0.2
		 *
		 * @return Pickup_Map_Settings
		 */
		public function get_map_settings(): Pickup_Map_Settings {
			if ( null === $this->map_settings ) {
				$this->map_settings = new Pickup_Map_Settings();
			}

			return $this->map_settings;
		}

		/**
		 * Builds the section list from the declarations made so far. Pure — touches no
		 * WordPress function, so unit tests can call it directly.
		 *
		 * @since 2.0.2
		 *
		 * @return Settings_Section[]
		 */
		public function build_sections(): array {
			if ( ! $this->shipping_plugin_declared ) {
				return [];
			}

			$sections = [];

			if ( null !== $this->location_handler ) {
				$sections[] = Settings_Section::create(
					'location',
					__( 'Локация', 'woodev-plugin-framework' ),
					$this->location_setting_ids
				);
			}

			$sections[] = Settings_Section::create(
				'fields',
				__( 'Поля', 'woodev-plugin-framework' ),
				$this->get_field_settings()->get_owned_setting_ids(),
				$this->get_field_settings()->get_section_note()
			);

			if ( $this->map_needed ) {
				$sections[] = Settings_Section::create(
					'map',
					__( 'Карта', 'woodev-plugin-framework' ),
					$this->get_map_settings()->get_owned_setting_ids()
				);
			}

			return $sections;
		}

		/**
		 * Hooks {@see self::register()} on `init` at priority 25 — exactly once.
		 *
		 * Priority 25: AFTER `Location_Provider_Registry::collect()` (`init` priority 20),
		 * so a plugin that needs the location layer has already handed its handler over
		 * via {@see self::set_location_section()} by the time this fires.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function hook_once(): void {
			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_action( 'init', [ $this, 'register' ], 25 );
		}

		/**
		 * Builds the composite handler and registers the «Доставка» tab on the SP-1
		 * surface. Also boots {@see Checkout_Field_Policy} (Task 6, issue #362) against
		 * the same «Поля» handler this tab's own section is built from — never a fresh
		 * instance, so its availability rules are computed exactly once.
		 *
		 * Runs on EVERY request this hook fires on (`init` priority 25, including admin
		 * and REST) — harmless: {@see Checkout_Field_Policy::register()} is itself
		 * idempotent (guards its own `add_filter()` calls behind a `$hooked` flag), so a
		 * repeat call here only refreshes its settings reference, never double-adds a
		 * filter.
		 *
		 * @internal bound to `init` priority 25 by {@see self::hook_once()}.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Boots {@see Checkout_Field_Policy} (Task 6, issue #362).
		 *
		 * @return void
		 */
		public function register(): void {
			$children = array_filter(
				[
					$this->location_handler,
					$this->get_field_settings(),
					$this->map_needed ? $this->get_map_settings() : null,
				]
			);

			Checkout_Field_Policy::instance()->register( $this->get_field_settings() );

			Settings_Page_Registry::instance()->register_service(
				Settings_Provider::create(
					self::SERVICE_ID,
					__( 'Доставка', 'woodev-plugin-framework' ),
					new Composite_Settings_Handler( self::SERVICE_ID, array_values( $children ) ),
					$this->build_sections()
				)
			);
		}
	}

endif;

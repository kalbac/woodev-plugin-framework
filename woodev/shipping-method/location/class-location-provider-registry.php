<?php
/**
 * Woodev Location Provider Registry
 *
 * Registration, the activation gate, and active-provider resolution for the
 * Location Provider layer (Task 3; spec §4.1, D3, D4, D15).
 *
 * **Activation gate.** The framework bundles a default provider (DaData, Task 7)
 * but the whole layer stays completely inert — no provider collection, no store
 * setting, nothing on the SP-1 settings surface — until at least one shipping
 * plugin declares "I need a location provider" via
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()}.
 * This mirrors the repo's own established pattern for "a service learns about a
 * plugin because the plugin declares itself" ({@see \Woodev_Plugin::init_settings_page()}
 * calling `Settings_Page_Registry::instance()->register_plugin( $this )`) rather
 * than inventing a central enumeration of live plugin instances.
 *
 * **Collection timing.** Providers are collected on `init`, NEVER gated by
 * `is_checkout()` — a REST request (the DaData suggest/select endpoints, Task 8)
 * must see a fully collected registry, and an `is_checkout()` guard would hide it
 * from exactly those requests (the same lesson spec §8 already learned once for
 * the checkout-field layer).
 *
 * **Login migration wiring.** {@see self::add_hooks()} also wires
 * {@see Customer_Location_Store::handle_wp_login()} onto `wp_login`, exactly
 * once per request regardless of how many plugins declare need — this registry's
 * own once-per-fleet gate is what a hook needing that exact guarantee reuses,
 * rather than each plugin (or the store) inventing its own dedup mechanism.
 *
 * **REST wiring (Task 8).** {@see self::add_hooks()} also wires
 * {@see self::register_rest()} onto `rest_api_init`, registering
 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}'s
 * `woodev/v1/location/(suggest|select)` routes. This registry — not
 * {@see \Woodev\Framework\Shipping\Rest_Api\Shipping_REST_API} — is the correct
 * owner: that bootstrap is constructed PER PLUGIN and namespaces its routes
 * under that plugin's own dasherized id, but there is exactly one active
 * location provider per STORE (spec §4.1), so the route carries no
 * `plugin_id` segment at all. The two per-plugin `woodev/v1` controllers this
 * codebase already ships ({@see \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller},
 * {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller}) do not go
 * through `Shipping_REST_API::get_rest_controllers()` either — each
 * self-registers via its own owning handler's
 * `add_action( 'rest_api_init', … )` — and Location has no per-plugin handler
 * to be that owner, so this registry, already the fleet-wide owner of the
 * `init`/`wp_login` hooks above, takes the role instead. `rest_api_init`
 * always fires after `init` in a real request, so {@see self::collect()} (hooked
 * on `init` at priority 20) has always already run by the time
 * {@see self::register_rest()} builds the controller — the controller sees a
 * fully collected registry, never gated by `is_checkout()` (a REST request must
 * see it — the same reasoning as the collection timing above).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Provider_Registry' ) ) :

	/**
	 * Singleton registry: provider registration, the activation gate, and
	 * active-provider resolution.
	 *
	 * @since 2.0.2
	 */
	final class Location_Provider_Registry {

		/**
		 * The bundled provider's id, and the fallback when the store's
		 * `active_provider` setting names an id nothing is registered under.
		 *
		 * MUST match {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::get_id()}
		 * once Task 7 ships it — this registry does not import that class (it may
		 * not exist yet, see {@see self::bundled_provider_classes()}), so the two
		 * strings are two independent literals that Task 7 is responsible for
		 * keeping in agreement.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const DEFAULT_PROVIDER_ID = 'dadata';

		/**
		 * Settings-service id this registry registers on the SP-1 surface — also
		 * the option-name namespace ({@see \Woodev_Abstract_Settings::get_option_name_prefix()}
		 * resolves it to `woodev_location_*`).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTINGS_SERVICE_ID = 'location';

		/**
		 * The store setting id holding the chosen provider's id.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_ACTIVE_PROVIDER = 'active_provider';

		/**
		 * Filter tag: lets a plugin register its own {@see Location_Provider}
		 * instances alongside the bundled ones. Receives (and must return) a plain
		 * list — any entry not implementing {@see Location_Provider} is rejected
		 * and logged (`_doing_it_wrong`), the rest of the list still registers.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_PROVIDERS = 'woodev_location_providers';

		/**
		 * Filter tag: lets a site override the RESOLVED active provider instance
		 * (e.g. to swap in a decorator/spy) — see {@see self::get_active_provider()}.
		 * Left in place even though nothing in this codebase consumes it yet
		 * (project preference: extension hooks are not gated on having a consumer).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_ACTIVE_PROVIDER = 'woodev_location_active_provider';

		/** @var self|null singleton. */
		private static ?self $instance = null;

		/**
		 * Whether at least one shipping plugin has declared it needs this layer —
		 * the raw gate flag. `false` until {@see self::declare_needed()} is called.
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $needed = false;

		/**
		 * Whether {@see self::add_hooks()} already ran. Guards against hooking
		 * `init` twice when more than one plugin declares need (idempotent, same
		 * pattern as {@see \Woodev\Framework\Settings\Settings_Page_Registry::add_hooks()}).
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $hooked = false;

		/**
		 * Whether {@see self::collect()} already ran this request. Guards against
		 * re-collecting (and re-registering the settings service) if `init` fires
		 * more than once in a test/edge-case context.
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $collected = false;

		/**
		 * Registered providers, `id => instance`. Empty until {@see self::collect()}
		 * runs — see the gate discipline in the class docblock.
		 *
		 * @since 2.0.2
		 * @var array<string, Location_Provider>
		 */
		private array $providers = [];

		/**
		 * The store-level settings handler, built by {@see self::register_settings()}.
		 * Null until the gate opens AND `init` has fired.
		 *
		 * @since 2.0.2
		 * @var Location_Settings|null
		 */
		private ?Location_Settings $settings_handler = null;

		/**
		 * Use {@see self::instance()}.
		 *
		 * @since 2.0.2
		 */
		private function __construct() {}

		/**
		 * Gets the singleton instance.
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
		 * {@see \Woodev\Framework\Settings\Settings_Page_Registry::reset_for_tests()},
		 * including its `remove_action()` half (added once a fixture plugin loaded
		 * unconditionally by the integration bootstrap — `woodev-test-shipping-method`,
		 * location-provider layer rig pull-forward — started declaring need at
		 * `plugins_loaded` time, exactly like a real shipping plugin would; before that,
		 * `declare_needed()` was only ever called from WITHIN a test, after THAT test's
		 * own reset, so the stale-hook problem below could not surface).
		 *
		 * `self::$instance = null` alone discards the PHP-level singleton reference but
		 * leaves any `add_action()` calls the OLD instance made intact in WordPress's own
		 * hook table — `add_action()`/`remove_action()` match by object identity, not by
		 * class, so a lingering registration keeps firing against the discarded instance
		 * on every `do_action( 'init' | 'rest_api_init' )` a LATER test issues, silently
		 * reopening state this method is supposed to erase.
		 *
		 * Removal therefore matches by CLASS + METHOD across the whole hook table rather
		 * than by the instance this reset happens to hold, because in the integration suite
		 * it holds the wrong one by construction: `WP_UnitTestCase` snapshots `$wp_filter`
		 * once, at the first test of the run, and RESTORES that snapshot after every single
		 * test. The fixture plugin's `plugins_loaded`-time registration is part of that
		 * snapshot, so it is re-instated after each teardown — while `self::$instance`,
		 * nulled by that same teardown, is a brand-new unhooked object by the time the next
		 * `setUp()` calls this method. Measured, not reasoned: the hook surviving the reset
		 * belonged to a registry instance the reset could not name, and the "routes are
		 * absent when nobody declared need" test consequently saw both routes registered.
		 *
		 * The `wp_login` registration in {@see self::add_hooks()} is removed the same way.
		 * It binds a `new Customer_Location_Store()` instance never stored anywhere, so it
		 * is unreachable by identity too — the class+method match is what makes it
		 * removable at all.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function reset_for_tests(): void {
			self::remove_hooked_instances( 'init', self::class, 'collect' );
			self::remove_hooked_instances( 'rest_api_init', self::class, 'register_rest' );
			self::remove_hooked_instances( 'wp_login', Customer_Location_Store::class, 'handle_wp_login' );

			self::$instance = null;
		}

		/**
		 * Removes every `$class::$method` callback registered on `$hook`, whichever
		 * instance registered it.
		 *
		 * Iterating `WP_Hook::$callbacks` by value is safe against the `remove_action()`
		 * calls made inside the loop: `foreach` walks a copy, so the mutation lands on the
		 * live table without disturbing the iteration.
		 *
		 * A no-op outside a full WordPress runtime (unit tests run on Brain Monkey, where
		 * `$wp_filter` does not exist) — there is no hook table to scrub there, and the
		 * `self::$instance = null` half of the reset is the whole job.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param string $hook   Hook name to scrub.
		 * @param string $class  Fully-qualified class whose callbacks should be removed.
		 * @param string $method Method name to match.
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
		 * Declares that the calling plugin needs the Location Provider layer,
		 * opening the activation gate.
		 *
		 * Idempotent — safe to call from every plugin whose
		 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()}
		 * returns `true`; the first call opens the gate and hooks collection, every
		 * subsequent call is a no-op beyond re-confirming the flag.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function declare_needed(): void {
			$this->needed = true;
			$this->add_hooks();
		}

		/**
		 * Whether the activation gate is open (at least one plugin declared need).
		 *
		 * This is the raw gate flag, NOT "a provider is active and configured" —
		 * that stronger question belongs to Task 6's `Location_Service::is_active()`,
		 * which is why this accessor is deliberately named differently rather than
		 * reusing `is_active()` for a weaker meaning.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_needed(): bool {
			return $this->needed;
		}

		/**
		 * Hooks provider collection on `init`, and the guest-to-account login
		 * migration on `wp_login`, exactly once.
		 *
		 * Only ever called from {@see self::declare_needed()} — if NO plugin ever
		 * declares need, this method never runs and NEITHER hook is registered at
		 * all, which is the strongest form of "inert": not merely a no-op callback,
		 * but no hook registration in the first place.
		 *
		 * Priority 20 for `init`: comfortably after `plugins_loaded`-time plugin
		 * construction (where {@see self::declare_needed()} itself is called, see
		 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_hooks()}) and after
		 * WooCommerce's own `init`-time setup, so every plugin's declaration and
		 * every filter callback a plugin wants to attach to
		 * {@see self::FILTER_PROVIDERS} on `init` is guaranteed to have already run.
		 *
		 * `wp_login` (P1 finding): {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::handle_wp_login()}
		 * migrates a guest's session-held record onto the account just logged into,
		 * and its own docblock spells out the exact `add_action( 'wp_login', [
		 * $store, 'handle_wp_login' ], 10, 2 )` call needed to wire it — but nothing
		 * ever made that call anywhere in the codebase (gotcha
		 * `built-on-both-sides-with-no-caller-in-the-middle`: fully implemented,
		 * fully tested, zero production callers). It is wired HERE, alongside
		 * `init`, rather than directly inside `Shipping_Plugin::add_hooks()` where
		 * {@see self::declare_needed()} is called, because THIS method is already
		 * the fleet-wide once-only gate every declaring plugin funnels through via
		 * the {@see self::$hooked} guard below: several shipping plugins can be
		 * active simultaneously, each constructing its own objects, so a naive
		 * `add_action()` call in a per-plugin path would register N callbacks bound
		 * to N distinct `Customer_Location_Store` instances — WordPress dedups
		 * `add_action()` by object hash + method, so distinct instances do NOT
		 * dedupe — and the migration would run N times per login. Registered
		 * synchronously here (not deferred to `init` like `collect()` is) because
		 * `add_hooks()` itself only runs from `declare_needed()`, called from
		 * `Shipping_Plugin::add_hooks()` at `plugins_loaded` — a stage that DOES run
		 * on `wp-login.php` (plugins load there like on any other request), always
		 * before `wp_login` fires on that same request.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function add_hooks(): void {
			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_action( 'init', [ $this, 'collect' ], 20 );
			add_action( 'wp_login', [ new Customer_Location_Store(), 'handle_wp_login' ], 10, 2 );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );
		}

		/**
		 * Registers the location REST routes.
		 *
		 * Builds and registers a fresh {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}
		 * (which in turn builds its own default {@see Location_Service}, itself
		 * defaulting to this SAME registry singleton — see
		 * {@see Location_Service::__construct()}). No gate check here beyond what
		 * {@see self::add_hooks()} already enforces: this method is only ever
		 * hooked from `declare_needed()`, i.e. only after the activation gate is
		 * already open — see the class docblock's "REST wiring" section for why
		 * this registry, not `Shipping_REST_API`, is the correct owner.
		 *
		 * @internal Hooked to `rest_api_init`; not part of the public consumption surface.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			( new \Woodev\Framework\Shipping\Rest_Api\Location_Controller() )->register_routes();
		}

		/**
		 * Collects providers and registers the store-level settings service.
		 *
		 * No-ops when the gate is closed (defensive — {@see self::add_hooks()}
		 * never hooks this when the gate never opened, but `collect()` is public
		 * and this guard keeps a direct call safe too) or when already collected
		 * this request. Deliberately contains NO `is_checkout()` (or any other
		 * request-shape) check — see the class docblock.
		 *
		 * @internal Hooked to `init`; not part of the public consumption surface.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function collect(): void {
			if ( ! $this->needed || $this->collected ) {
				return;
			}
			$this->collected = true;

			foreach ( self::bundled_provider_classes() as $class ) {
				if ( class_exists( $class ) ) {
					$this->register_provider( new $class() );
				}
			}

			/**
			 * Filters the list of registered location providers.
			 *
			 * A plugin appends its own {@see Location_Provider} instance(s) here
			 * (first candidate: СДЭК's own city/region dictionary, spec D3). Any
			 * returned entry that does not implement {@see Location_Provider} is
			 * rejected and logged via `_doing_it_wrong()`; the rest of the list
			 * still registers (one bad entry does not poison the others).
			 *
			 * @since 2.0.2
			 *
			 * @param Location_Provider[] $providers Providers to register, in addition
			 *                                        to the bundled default(s).
			 */
			$candidates = (array) apply_filters( self::FILTER_PROVIDERS, [] );

			foreach ( $candidates as $candidate ) {
				if ( ! $candidate instanceof Location_Provider ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'A "%s" filter callback returned a value that does not implement Location_Provider; it was ignored.',
							self::FILTER_PROVIDERS
						),
						'2.0.2'
					);
					continue;
				}

				$this->register_provider( $candidate );
			}

			$this->register_settings();
		}

		/**
		 * Lists the FQCNs of bundled providers the framework itself registers.
		 *
		 * A documented seam, not a stub: Task 7 appends the DaData provider's FQCN
		 * to this list when it ships that class. `class_exists()` guards every
		 * entry, so this list is safe to carry an as-yet-nonexistent class name
		 * during Task 3 — {@see self::collect()} simply registers nothing for it
		 * until the file exists.
		 *
		 * @since 2.0.2
		 *
		 * @return class-string[]
		 */
		private static function bundled_provider_classes(): array {
			// The assertion is deliberate and temporary: PHPStan resolves `class-string`
			// against classes it can actually see, and the DaData provider ships in a
			// later block, so the literal below is not yet resolvable for it. The strings
			// ARE class names — `class_exists()` in self::collect() is what makes an
			// unresolvable one harmless — and the moment the provider file lands this
			// annotation becomes literally checkable rather than merely asserted.
			/** @var class-string[] $classes */
			$classes = [
				'\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Provider',
			];

			return $classes;
		}

		/**
		 * Registers one provider. A duplicate id is rejected — the FIRST
		 * registration wins and `_doing_it_wrong()` reports the conflict.
		 *
		 * This is the OPPOSITE of {@see \Woodev\Framework\Shipping\Map\Map_Provider_Registry::register()},
		 * which deliberately lets a later registration override an earlier one so a
		 * host plugin can override a bundled provider under the SAME id. The two
		 * registries differ in what a duplicate id actually MEANS: `Map_Provider_Registry`
		 * has exactly one caller — the OWNING plugin — so a second `register()` call
		 * under an id already taken is that same plugin deliberately re-registering
		 * (e.g. swapping in its own `Yandex_Map_Provider` subclass). This registry
		 * instead aggregates from the bundled list PLUS every plugin's
		 * {@see self::FILTER_PROVIDERS} callback — independent parties that do not
		 * coordinate with each other — so a collision is far more likely to be an
		 * accidental id clash between two unrelated plugins (or a plugin colliding
		 * with the bundled `dadata` id) than an intentional override. First-wins
		 * protects whichever registration is earliest (the bundled default, or
		 * whichever plugin's filter callback ran first) and surfaces the conflict
		 * loudly instead of letting the second entrant silently win.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider $provider provider to register.
		 *
		 * @return void
		 */
		private function register_provider( Location_Provider $provider ): void {
			$id = $provider->get_id();

			if ( isset( $this->providers[ $id ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'A second location provider was registered under id "%s"; the first registration wins.',
						$id
					),
					'2.0.2'
				);

				return;
			}

			$this->providers[ $id ] = $provider;
		}

		/**
		 * Gets every registered provider, `id => instance`.
		 *
		 * Empty when the gate is closed, or when the gate is open but `init` has
		 * not yet fired ({@see self::collect()} not yet run).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, Location_Provider>
		 */
		public function get_providers(): array {
			return $this->providers;
		}

		/**
		 * Whether a provider is registered under the given id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id provider id.
		 *
		 * @return bool
		 */
		public function has_provider( string $id ): bool {
			return isset( $this->providers[ $id ] );
		}

		/**
		 * Gets the settings handler this registry built, or null before the gate
		 * opens (or before `init` has collected). Task 6's `Location_Service` and
		 * later tasks (field mode, default-locality policy) read additional
		 * settings from this SAME handler rather than each building their own.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Settings|null
		 */
		public function get_settings_handler(): ?Location_Settings {
			return $this->settings_handler;
		}

		/**
		 * Resolves the active provider: the store setting's value, falling back to
		 * {@see self::DEFAULT_PROVIDER_ID} when unset OR when the stored id names a
		 * provider nothing is registered under (e.g. the store previously chose a
		 * provider a plugin no longer registers). When even the default id has
		 * nothing registered under it (Task 7 not yet shipped, or the bundled
		 * provider is unconfigured) this resolves to `null` — degrading to native
		 * fields per spec §4.7 rather than inventing a provider that is not there.
		 *
		 * Returns `null` outright while the gate is closed — no settings handler
		 * exists to read a value from, and there is nothing to be "active" about.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Provider|null
		 */
		public function get_active_provider(): ?Location_Provider {
			if ( ! $this->needed || null === $this->settings_handler ) {
				return null;
			}

			$active_id = (string) $this->settings_handler->get_value( self::SETTING_ACTIVE_PROVIDER );
			$provider  = $this->providers[ $active_id ] ?? $this->providers[ self::DEFAULT_PROVIDER_ID ] ?? null;

			/**
			 * Filters the resolved active location provider instance.
			 *
			 * Left in place even though nothing in this codebase consumes it yet
			 * (project preference: extension hooks are not gated on a consumer).
			 *
			 * @since 2.0.2
			 *
			 * @param Location_Provider|null $provider  The resolved provider, or null
			 *                                           when the (possibly-fallen-back-to)
			 *                                           id has nothing registered.
			 * @param string                 $active_id The store setting's raw value
			 *                                           (before the default-id fallback
			 *                                           above was applied).
			 */
			return apply_filters( self::FILTER_ACTIVE_PROVIDER, $provider, $active_id );
		}

		/**
		 * Builds the settings handler and registers it as a framework service on
		 * the SP-1 surface.
		 *
		 * Resolves which provider is ACTIVE by reading the raw stored option value
		 * directly ({@see self::resolve_stored_active_provider_id()}) rather than
		 * through the handler this method is about to build — the handler does not
		 * exist yet at this point (constructing it is literally what this method
		 * does), so there is nothing to read the value from otherwise. Both paths
		 * read the exact same option name, so they can never disagree.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function register_settings(): void {
			$active_id       = $this->resolve_stored_active_provider_id();
			$active_provider = $this->providers[ $active_id ] ?? null;
			$provider_fields = null !== $active_provider ? $active_provider->get_settings_fields() : [];

			$provider_options = [];
			foreach ( $this->providers as $id => $provider ) {
				$provider_options[ $id ] = $provider->get_name();
			}

			$this->settings_handler = new Location_Settings( self::SETTINGS_SERVICE_ID, $provider_options, $provider_fields );

			\Woodev\Framework\Settings\Settings_Page_Registry::instance()->register_service(
				\Woodev\Framework\Settings\Settings_Provider::create(
					self::SETTINGS_SERVICE_ID,
					__( 'Локация', 'woodev-plugin-framework' ),
					$this->settings_handler,
					[
						\Woodev\Framework\Settings\Settings_Section::create(
							'general',
							__( 'Провайдер', 'woodev-plugin-framework' ),
							$this->settings_handler->get_owned_setting_ids()
						),
					]
				)
			);
		}

		/**
		 * Reads the store's raw `active_provider` option value, without going
		 * through a settings handler (see {@see self::register_settings()}).
		 *
		 * Mirrors {@see \Woodev_Abstract_Settings::load_settings()}'s own option-name
		 * construction (`{prefix}_{setting_id}`) exactly, so this always resolves the
		 * SAME value the handler itself will load moments later.
		 *
		 * @since 2.0.2
		 *
		 * @return string A registered provider id, or {@see self::DEFAULT_PROVIDER_ID}
		 *                when unset or naming a provider nothing is registered under.
		 */
		private function resolve_stored_active_provider_id(): string {
			$option_name = 'woodev_' . self::SETTINGS_SERVICE_ID . '_' . self::SETTING_ACTIVE_PROVIDER;
			$stored      = get_option( $option_name, null );

			if ( is_string( $stored ) && isset( $this->providers[ $stored ] ) ) {
				return $stored;
			}

			return self::DEFAULT_PROVIDER_ID;
		}
	}

endif;

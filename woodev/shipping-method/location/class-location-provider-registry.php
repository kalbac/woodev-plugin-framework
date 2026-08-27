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
		 * The LEGACY store option id that held a single field-presentation
		 * mode for BOTH the region and settlement levels at once, before
		 * issue #380 split it into {@see self::SETTING_FIELD_MODE_REGION} and
		 * {@see self::SETTING_FIELD_MODE_SETTLEMENT} — the operator found a
		 * case neither one shared value could express (НП = `ajax-select2`
		 * while region stays `typeahead`, or the reverse).
		 *
		 * No control is registered under this id any more; kept only so
		 * {@see self::migrate_legacy_field_mode()} has a single named literal
		 * for the legacy DB option it reads once, on the migration path.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_FIELD_MODE = 'field_mode';

		/**
		 * The store setting id holding the REGION level's field-presentation
		 * mode (issue #380 — split from the legacy {@see self::SETTING_FIELD_MODE}).
		 * See {@see self::get_offered_field_modes()} for how the OFFERED
		 * options are gated by the active provider's capabilities, and
		 * {@see self::get_field_mode_region()} for the read-side clamp —
		 * which ALSO forces {@see self::MODE_TYPEAHEAD} once `region_field`
		 * is removed (issue #369 closure), on top of the provider-capability
		 * clamp every axis shares.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_FIELD_MODE_REGION = 'field_mode_region';

		/**
		 * The store setting id holding the SETTLEMENT (НП) level's
		 * field-presentation mode (issue #380 — split from the legacy
		 * {@see self::SETTING_FIELD_MODE}). Gated the same way as
		 * {@see self::SETTING_FIELD_MODE_REGION} — see
		 * {@see self::get_field_mode_settlement()} — but carries no
		 * `region_field` clamp of its own: the settlement level has nothing
		 * analogous to remove it.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_FIELD_MODE_SETTLEMENT = 'field_mode_settlement';

		/**
		 * The store setting id holding the "allow a settlement not on the
		 * provider's own list" opt-in (#528, closing the half of #517
		 * `ajax-select2` left open: unlocking `address` is not enough when
		 * the settlement field is a real `<select>` with nothing to hold the
		 * customer's free-typed text, so an unlock-only fix submits an empty
		 * required field). Default OFF — the operator's own reasoning
		 * (25.08.2026): an unlisted settlement is rare (DaData covers nearly
		 * everything), and every carrier needs an exact location value, so
		 * turning this on is a deliberate merchant risk, not a safe default.
		 *
		 * Meaningful ONLY for {@see self::MODE_AJAX_SELECT2} — the settlement
		 * axis's `related-list` mode does not exist (clamped away
		 * unconditionally, see {@see self::get_field_mode_settlement()}) and
		 * `typeahead` already carries free text in a plain `<input>`, needing
		 * no opt-in at all. See {@see self::is_custom_settlement_allowed()}
		 * for the read-side accessor and
		 * `woodev/shipping-method/assets/js/frontend/location-select-modes.js`'s
		 * `selectConfigFor()` ajax branch for the client-side gate this
		 * setting drives (select2 `tags` + the abandon-recording gate
		 * together).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_ALLOW_CUSTOM_SETTLEMENT = 'allow_custom_settlement';

		/**
		 * The store setting id holding the `address_suggestions` switch (Task 10;
		 * issue #362; design S3/§3.1/§3.2/§4.2/§7): whether the location layer
		 * serves the `address` suggest level AT ALL. Registered right after
		 * the two field-mode axes above — design S3 puts it directly under
		 * the provider block on the settings surface.
		 *
		 * See {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_for_level()}
		 * for the gate this setting drives, {@see self::get_address_suggestions_raw()}
		 * for why that gate reads the RAW stored value rather than the
		 * merchant-facing {@see self::is_address_suggestions_enabled()}, and
		 * {@see self::is_address_suggestions_available()} for the read-side clamp
		 * that keeps a stored `true` honest once nobody can serve `address` any
		 * more — the same "OFFERED / read-side clamp" discipline
		 * {@see self::get_field_mode_region()}/{@see self::get_field_mode_settlement()}
		 * already apply to their own settings.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_ADDRESS_SUGGESTIONS = 'address_suggestions';

		/**
		 * Field mode: always-available free-text typeahead (spec D7 baseline —
		 * every provider can serve this, `suggest()` is REQUIRED of all of them).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const MODE_TYPEAHEAD = 'typeahead';

		/**
		 * Field mode: the region level is rendered THROUGH WooCommerce's own
		 * `woocommerce_states` filter (native `<select>`), with the active
		 * provider's own enumerated regions injected into it — see
		 * {@see self::inject_related_list_states()}. Descendant levels (e.g.
		 * settlement within a chosen region) are populated via `select2` fed by
		 * `GET woodev/v1/location/list` (Task 13). Only offered when the active
		 * provider declares {@see Location_Provider::CAPABILITY_LIST} — DaData
		 * cannot enumerate (query-driven API only).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const MODE_RELATED_LIST = 'related-list';

		/**
		 * Field mode: `select2` with remote data through the existing
		 * `GET woodev/v1/location/suggest` seam — same underlying data source as
		 * typeahead, a different client renderer (spec D7: "three renderers over
		 * one data source"). Only offered when the active provider declares
		 * {@see Location_Provider::CAPABILITY_LIST} — matching D7's own gate for
		 * `related-list`, since a provider that can enumerate can always also be
		 * queried, but the reverse is not true (DaData: query-only).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const MODE_AJAX_SELECT2 = 'ajax-select2';

		/**
		 * Every field mode this layer knows about, in the fixed offering order
		 * {@see self::get_offered_field_modes()} always returns them in.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		public const FIELD_MODES = [ self::MODE_TYPEAHEAD, self::MODE_RELATED_LIST, self::MODE_AJAX_SELECT2 ];

		/**
		 * The store setting id holding the default-locality policy (Task 14; spec
		 * D11): `off` | `fixed` | `geoip`. See {@see self::get_offered_default_locality_policies()}
		 * for how `geoip` is gated by the active provider's `locate` capability, and
		 * {@see self::get_default_locality_policy()} for the read-side clamp.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_DEFAULT_LOCALITY_POLICY = 'default_locality_policy';

		/**
		 * The store setting id holding the merchant-picked FIXED default locality
		 * (Task 14; spec D11), serialized as JSON ({@see Location_Record::to_array()}).
		 * Only meaningful when {@see self::SETTING_DEFAULT_LOCALITY_POLICY} is
		 * {@see self::DEFAULT_LOCALITY_POLICY_FIXED}; picked through the admin-only
		 * suggest context on {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_DEFAULT_LOCALITY_RECORD = 'default_locality_record';

		/**
		 * The store setting id holding the "needs re-picking" flag (spec §4.6/D15
		 * amendment): set when the FIXED default's provider namespace is stranded by
		 * a provider switch and re-resolution through the new provider fails — see
		 * {@see Location_Service::resolve_default()}. Purely informational for the
		 * settings surface; never gates resolution itself.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const SETTING_DEFAULT_LOCALITY_NEEDS_REPICK = 'default_locality_needs_repick';

		/**
		 * Default-locality policy: no default is ever resolved
		 * ({@see Location_Service::resolve_default()} returns `null`).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const DEFAULT_LOCALITY_POLICY_OFF = 'off';

		/**
		 * Default-locality policy: the merchant-picked
		 * {@see self::SETTING_DEFAULT_LOCALITY_RECORD} is served (re-resolved through
		 * the current provider first, when its namespace was stranded by a provider
		 * switch — spec §4.6/D15 amendment).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const DEFAULT_LOCALITY_POLICY_FIXED = 'fixed';

		/**
		 * Default-locality policy: the active provider's `locate( $ip )` capability
		 * resolves a default from the customer's IP address. Only OFFERED when the
		 * active provider declares {@see Location_Provider::CAPABILITY_LOCATE} — see
		 * {@see self::get_offered_default_locality_policies()}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const DEFAULT_LOCALITY_POLICY_GEOIP = 'geoip';

		/**
		 * Every default-locality policy this layer knows about, in the fixed
		 * offering order {@see self::get_offered_default_locality_policies()} always
		 * returns them in.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		public const DEFAULT_LOCALITY_POLICIES = [
			self::DEFAULT_LOCALITY_POLICY_OFF,
			self::DEFAULT_LOCALITY_POLICY_FIXED,
			self::DEFAULT_LOCALITY_POLICY_GEOIP,
		];

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

		/**
		 * Option name storing the popular-settlements table's installed schema
		 * version (#488) — the gate {@see self::maybe_install_popular_settlements_table()}
		 * checks so `dbDelta()` runs once per version change, not on every request.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const POPULAR_SETTLEMENTS_SCHEMA_VERSION_OPTION = 'woodev_popular_settlements_schema_version';

		/**
		 * Current popular-settlements table schema version. Bump when
		 * {@see Popular_Settlement_Store::get_schema()} changes.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const POPULAR_SETTLEMENTS_SCHEMA_VERSION = '2';

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
		 * The exact `woocommerce_states` OPTIONS {@see self::inject_related_list_states()}
		 * itself successfully wrote for a country, THIS request — the ownership
		 * record {@see self::owns_region_states()} compares against the FINAL
		 * registered states to answer from.
		 *
		 * Recorded rather than inferred from the field-mode setting alone
		 * (`mode === 'related-list'`) because that alone cannot distinguish "we
		 * injected this country's regions" from "related-list mode is on, but a
		 * plugin's own §8 carrier takeover reached this country's states first,
		 * or this specific country had nothing to enumerate" — both leave the
		 * mode flag identical but the OWNERSHIP genuinely differs, and
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}'s
		 * `_doing_it_wrong()` (issue #294) must fire in the second case, not the
		 * first.
		 *
		 * Storing the OPTIONS themselves (not merely a `true` flag) is what lets
		 * {@see self::owns_region_states()} detect a LATER filter callback
		 * (e.g. a §8 carrier takeover hooked after this injector) clobbering this
		 * country's states after this method already ran — see that method's own
		 * docblock, PR #304 review finding 3.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Stores the injected OPTIONS rather than a bare `true` flag
		 *              (PR #304 review finding 3).
		 * @var array<string, array<string, string>>
		 */
		private array $related_list_states_countries = [];


		/**
		 * Notice ids already claimed via {@see self::claim_not_configured_notice()}
		 * THIS request — the fleet-wide "one plugin renders it, every other
		 * participating plugin stands down" dedup for the shared "active
		 * location provider is not configured" notice. Reset for free on every
		 * new request (a fresh PHP process) and on {@see self::reset_for_tests()}
		 * (which discards the whole singleton instance).
		 *
		 * @since 2.0.2
		 * @var array<string, true>
		 */
		private array $claimed_notice_ids = [];

		/**
		 * Lazily-constructed shared {@see Popular_Settlement_Store} instance (#488) —
		 * see {@see self::popular_settlement_store()}. Null until first requested.
		 *
		 * @since 2.0.2
		 * @var Popular_Settlement_Store|null
		 */
		private ?Popular_Settlement_Store $popular_settlement_store = null;

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
			self::remove_hooked_instances( 'woocommerce_states', self::class, 'inject_related_list_states' );

			// The popular-settlements pair, added in #488 slice 2. Every INSTANCE-BOUND callback
			// `add_hooks()` registers must be removable here, or an integration test keeps a stale
			// registry instance alive across a reset (Codex critic, final pass on #488 slice 2).
			//
			// The rule is about INSTANCES, not about callbacks in general — a distinction #514/m1 asked
			// for once `add_hooks()` grew its first callback that is deliberately NOT removed here. A
			// callback bound as a CLASS+METHOD pair (`[ Some_Class::class, 'method' ]`) holds no instance
			// to strand: WordPress keys it by that string, so a repeat `add_hooks()` on a fresh instance
			// overwrites rather than accumulates, and this reset has nothing to detach. Removing one here
			// would be worse than pointless — it would tear down a registration the fixture plugin makes
			// at `plugins_loaded`, which `WP_UnitTestCase` restores from its hook snapshot regardless.
			//
			// Today there is exactly one such callback: the `Shipping_Tools_Registry::FILTER_TOOLS`
			// filter, whose site in {@see self::add_hooks()} carries the same reasoning. A future
			// callback added INSTANCE-bound belongs in the list below.
			self::remove_hooked_instances( 'init', self::class, 'maybe_install_popular_settlements_table' );
			self::remove_hooked_instances( 'woocommerce_checkout_order_processed', self::class, 'handle_checkout_order_processed_for_popular_settlements' );

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
		 * `woocommerce_states` (Task 13, issue #294): {@see self::inject_related_list_states()}
		 * is hooked here too, unconditionally and unfiltered by mode/capability —
		 * the same "hook is cheap, the callback itself gates on the live setting"
		 * discipline {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject_states()}
		 * already follows. Registering it here (once, fleet-wide) rather than from
		 * a per-plugin `Location_Service` instance avoids the exact multi-instance
		 * dedup trap the `wp_login` paragraph above already solved for
		 * `Customer_Location_Store` — `add_action()`/`add_filter()` dedup by object
		 * identity, not by class, so N shipping plugins each building their own
		 * `Location_Service` would double-inject. No explicit priority: the #294
		 * decision's own measured ordering fact (this file's class docblock does
		 * not repeat it — see `Checkout_Config::build_location_block()`'s own
		 * docblock) only requires this filter to be attached before ANYTHING calls
		 * `WC_Countries::get_states()`, which `add_hooks()` — called synchronously
		 * from `Shipping_Plugin::add_hooks()` at `plugins_loaded` — always
		 * satisfies regardless of priority.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Also hooks {@see self::inject_related_list_states()} onto
		 *              `woocommerce_states` (Task 13, issue #294).
		 * @since 2.0.2 Round 2 (#488 slice 2, HIGH 1/HIGH 2): also hooks
		 *              {@see self::maybe_install_popular_settlements_table()} onto
		 *              `init` (deferred, same as {@see self::collect()} — NOT called
		 *              synchronously here, which is exactly what broke ~150 unrelated
		 *              unit tests in round 1 by touching a possibly-polluted global
		 *              `$wpdb` mid-`add_hooks()`) and
		 *              {@see self::handle_checkout_order_processed_for_popular_settlements()}
		 *              onto `woocommerce_checkout_order_processed`.
		 *
		 * @return void
		 */
		public function add_hooks(): void {
			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_action( 'init', [ $this, 'collect' ], 20 );
			add_action( 'init', [ $this, 'maybe_install_popular_settlements_table' ], 20 );
			add_action( 'wp_login', [ new Customer_Location_Store(), 'handle_wp_login' ], 10, 2 );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );
			add_filter( 'woocommerce_states', [ $this, 'inject_related_list_states' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed_for_popular_settlements' ], 20, 3 );

			// #505 D6/4f: registers the D8 merchant actions through the shipping-tools
			// registry's own public filter, the same seam any carrier plugin uses. A
			// STATIC callback (class+method string, not an instance) — WordPress dedupes
			// `add_filter()` by that string, so a later `add_hooks()` call on a fresh
			// instance (a test reset) never adds a second copy, and there is nothing
			// instance-bound here for `reset_for_tests()` to remove.
			add_filter(
				\Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry::FILTER_TOOLS,
				[ Popular_Settlements_Tools::class, 'register_tools' ]
			);
		}

		/**
		 * Gets the lazily-constructed, shared {@see Popular_Settlement_Store}
		 * instance (#488).
		 *
		 * Public: {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order} (the
		 * one real framework caller of
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()})
		 * is expected to be constructed with this SAME instance, so a settlement
		 * stamped here via {@see self::handle_checkout_order_processed_for_popular_settlements()}
		 * and one recalled there refer to the same store.
		 *
		 * @since 2.0.2
		 *
		 * @return Popular_Settlement_Store
		 */
		public function popular_settlement_store(): Popular_Settlement_Store {
			if ( null === $this->popular_settlement_store ) {
				$this->popular_settlement_store = new Popular_Settlement_Store();
			}

			return $this->popular_settlement_store;
		}

		/**
		 * Ensures the popular-settlements table (#488) exists, once per schema
		 * version.
		 *
		 * Hooked onto `init` at priority 20 (round 2, HIGH 1) — deferred, exactly
		 * like {@see self::collect()} — NOT called synchronously from
		 * {@see self::add_hooks()} itself. That distinction is load-bearing: dozens
		 * of unit tests call `declare_needed()` → `add_hooks()` directly (to reach
		 * {@see self::collect()} without a real WP request), and a synchronous call
		 * here would run this method — and therefore touch `$wpdb` — in every one of
		 * them; `add_action()` merely REGISTERS the callback, so those same tests
		 * stay unaffected unless something actually fires `init`.
		 *
		 * `dbDelta()` itself is not cheap enough to run on every real request either,
		 * so the option-stored schema version is the real gate; the cheap
		 * `get_option()` check running every request (while the table itself is only
		 * ever created/migrated once per version bump) is the same "hook is cheap,
		 * the callback itself gates" discipline this class already follows elsewhere
		 * (see this method's own docblock on `woocommerce_states`).
		 *
		 * @internal Hooked to `init`; not part of the public consumption surface.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function maybe_install_popular_settlements_table(): void {
			if ( self::POPULAR_SETTLEMENTS_SCHEMA_VERSION === get_option( self::POPULAR_SETTLEMENTS_SCHEMA_VERSION_OPTION ) ) {
				return;
			}

			$this->popular_settlement_store()->install();

			update_option( self::POPULAR_SETTLEMENTS_SCHEMA_VERSION_OPTION, self::POPULAR_SETTLEMENTS_SCHEMA_VERSION );
		}

		/**
		 * Stamps the settlement the customer picked at checkout onto the just-saved
		 * order, for {@see Popular_Settlement_Store::recall_candidate()} to read
		 * back later (#488 slice 2, round 2, HIGH 2).
		 *
		 * This is the ONLY place in the framework that genuinely knows the picked
		 * settlement without any carrier-plugin cooperation: `Customer_Location_Store`
		 * holds it in the LIVE session/user-meta, which is only reliably available
		 * during THIS synchronous request — an async retry of a failed carrier export
		 * (see {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::schedule_retry()})
		 * runs later, with no customer session at all. Stamping the record onto the
		 * order NOW, while the session is live, is what makes it recoverable then.
		 *
		 * Deliberately does NOT enrol anything itself — see
		 * {@see Popular_Settlement_Store::remember_candidate()}'s own docblock. Only
		 * a REAL, successful carrier export
		 * ({@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()})
		 * ever calls {@see Popular_Settlement_Store::enroll()} — this avoids double
		 * counting the same order from two independent triggers.
		 *
		 * A silent no-op when there is no active provider, or the customer's chain has
		 * no settlement-level record — most orders under a non-address-cascade
		 * shipping method, or a guest whose session already expired.
		 *
		 * Also gated by the SAME D4/D4a rules {@see Popular_Settlement_Store::enroll()}
		 * enforces (round 3, MEDIUM 3): a provider without
		 * {@see Location_Provider::CAPABILITY_RESOLVE_KEY} gets no popular list at
		 * all, and a derived key (see {@see Locality_Key::is_derived()}) is never
		 * enrolled. `remember_candidate()` is not itself a table write — the data
		 * contract is not at stake either way — but stamping meta that can never
		 * lead to a row contradicts the "gets nothing" rule this listener would
		 * otherwise silently violate.
		 *
		 * @internal Hooked to `woocommerce_checkout_order_processed`; not part of the
		 *           public consumption surface.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 3 (MEDIUM 3): gated by the same D4/D4a rules `enroll()`
		 *              enforces, instead of stamping a candidate for every active
		 *              provider unconditionally.
		 *
		 * @param int                  $order_id    the created order id (unused; the order object is used)
		 * @param array<string, mixed> $posted_data the posted checkout data (unused)
		 * @param \WC_Order            $order       the created, saved order
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed_for_popular_settlements( int $order_id, array $posted_data, \WC_Order $order ): void {
			$chain = ( new Customer_Location_Store() )->get_chain();

			if ( null === $chain || ! isset( $chain['records'][ Location_Record::LEVEL_SETTLEMENT ] ) ) {
				return;
			}

			$record = $chain['records'][ Location_Record::LEVEL_SETTLEMENT ];

			// The record is read BEFORE any capability check, and the provider is looked up by the
			// record's OWN id — never by `get_active_provider()`. A level is resolved per level with
			// a bundled fallback (gotcha
			// `a-level-served-can-come-from-the-fallback-not-the-active-provider`), so with CDEK
			// active and DaData configured a settlement record can legitimately belong to DaData.
			// Gating on the ACTIVE provider therefore asks the wrong question in both directions:
			// it drops a perfectly enrollable fallback-owned record when the active provider cannot
			// resolve by key, and it stamps a candidate `enroll()` will later refuse when only the
			// active one can. `enroll()` gates by `record->provider_id()`, and this must agree with
			// it exactly (Codex critic, final pass on #488 slice 2).
			$provider = $this->get_providers()[ $record->provider_id() ] ?? null;

			if ( null === $provider ) {
				return;
			}

			if ( ! in_array( Location_Provider::CAPABILITY_RESOLVE_KEY, $provider->get_capabilities(), true ) ) {
				return; // D4: no popular list at all for a provider that cannot resolve by key.
			}

			if ( Locality_Key::is_derived( $record->key() ) ) {
				return; // D4a: a derived key can never be resolved again, so it is never enrolled.
			}

			$this->popular_settlement_store()->remember_candidate( $order, $record );
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
		 * Claims `$notice_id` for the FIRST caller this request — the fleet-wide
		 * dedup for the shared "active location provider is not configured"
		 * notice ({@see \Woodev\Framework\Shipping\Shipping_Plugin::add_location_provider_not_configured_notice()}).
		 * Every participating plugin's own handler computes the SAME notice id
		 * for the SAME unconfigured provider (that method's own docblock), so
		 * without this gate every plugin in the fleet would render its own copy
		 * of the identical notice on the same screen. The registry — the one
		 * fleet-wide singleton every participating plugin already shares — is
		 * what can answer "has anyone already claimed this id THIS request",
		 * which no single plugin's own (per-plugin)
		 * {@see \Woodev_Admin_Notice_Handler} can.
		 *
		 * @since 2.0.2
		 *
		 * @param string $notice_id the notice id a caller is about to register.
		 *
		 * @return bool `true` the first time `$notice_id` is claimed this
		 *              request (the caller should proceed and register its
		 *              notice); `false` on every subsequent claim of the SAME
		 *              id (the caller must stand down).
		 */
		public function claim_not_configured_notice( string $notice_id ): bool {
			if ( isset( $this->claimed_notice_ids[ $notice_id ] ) ) {
				return false;
			}

			$this->claimed_notice_ids[ $notice_id ] = true;

			return true;
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
		 * Delegates the id -> instance resolution (+ {@see self::FILTER_ACTIVE_PROVIDER})
		 * to {@see self::resolve_active_provider_for_id()} — the SAME resolution
		 * {@see self::register_settings()} now goes through, so the settings page
		 * (built once, at collection time) and every runtime caller of this method
		 * can never disagree about which provider is active (PR #304 review
		 * finding 6: `register_settings()` used to look the id up directly against
		 * `$this->providers`, skipping the filter entirely, so a plugin using
		 * {@see self::FILTER_ACTIVE_PROVIDER} to swap the resolved provider could
		 * make the admin settings page offer a narrower set of field modes than
		 * the runtime would actually accept).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Delegates to {@see self::resolve_active_provider_for_id()},
		 *              shared with {@see self::register_settings()} (PR #304
		 *              review finding 6).
		 *
		 * @return Location_Provider|null
		 */
		public function get_active_provider(): ?Location_Provider {
			if ( ! $this->needed || null === $this->settings_handler ) {
				return null;
			}

			$active_id = (string) $this->settings_handler->get_value( self::SETTING_ACTIVE_PROVIDER );

			return $this->resolve_active_provider_for_id( $active_id );
		}

		/**
		 * Gets the field-presentation modes the REGION axis
		 * ({@see self::SETTING_FIELD_MODE_REGION}) is allowed to offer right
		 * now (Task 13; spec D7), gated by the ACTIVE provider's OWN
		 * capabilities — never the whole D15 chain: `related-list` alone
		 * feeds `list_localities()`, which is not a D15 fallback-chained
		 * capability the way `suggest()` is (spec D15 is about per-LEVEL
		 * suggest support; a provider either can or cannot enumerate at
		 * all).
		 *
		 * `typeahead` and `ajax-select2` are BOTH unconditional — every
		 * provider implements `suggest()` (spec D7 baseline: it is REQUIRED,
		 * not a capability), and `ajax-select2`'s client renderer queries
		 * that exact same seam, never `list_localities()` (issue #380
		 * correction — see {@see self::MODE_AJAX_SELECT2}'s own docblock).
		 * Only `related-list` is gated on {@see Location_Provider::CAPABILITY_LIST}.
		 *
		 * The SETTLEMENT axis ({@see self::SETTING_FIELD_MODE_SETTLEMENT})
		 * used to share this exact gate (issue #380: "each axis carries the
		 * same three values") but no longer does in full — issue #404 added
		 * a cross-axis condition ON TOP of it, see
		 * {@see self::offered_field_modes_for()}'s own `$requires_region_list`
		 * parameter and {@see self::get_field_mode_settlement()}. This method
		 * stays the REGION axis's own, unconditional answer.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 `ajax-select2` is no longer gated on `CAPABILITY_LIST`
		 *              (issue #380) — only `related-list` still needs it.
		 * @since 2.0.2 No longer shared verbatim by the settlement axis
		 *              (issue #404) — kept as the region axis's own gate.
		 *
		 * @return string[] Subset of {@see self::FIELD_MODES}, always containing
		 *                  {@see self::MODE_TYPEAHEAD} and {@see self::MODE_AJAX_SELECT2}.
		 */
		public function get_offered_field_modes(): array {
			return self::offered_field_modes_for( $this->get_active_provider() );
		}

		/**
		 * The `related-list` gate. It is a REGION-AXIS-ONLY mode.
		 *
		 * **The settlement axis has exactly two modes and never a third**
		 * (operator decision, 24.08.2026): `typeahead` ("Текст с
		 * подсказками") and `ajax-select2` ("Список с поиском").
		 * `related-list` ("Предустановленный список") is not offered for
		 * settlements under any provider or any region-axis setting.
		 *
		 * Issue #404 previously offered it, gated on the region axis being
		 * `related-list` itself, on the reasoning that a region-SCOPED
		 * settlement list "is far more likely to genuinely be the whole
		 * set" than a country-wide one — `related-list` promises a list
		 * loaded ONCE and searched locally, and
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}
		 * caps every `/location/list` response at `LIST_HARD_CAP` and flags
		 * `truncated: true`, a flag this axis's client drops. That premise
		 * was DISPROVEN by measurement on 24.08.2026: a region-scoped
		 * settlement list came back at exactly 500 = `LIST_HARD_CAP`, i.e.
		 * silently truncated, with the customer given no way to reach the
		 * rest. A mode whose one promise cannot be kept is not a mode, so
		 * the gate is gone rather than tightened — the same conclusion
		 * `specs/2026-08-21-settlement-search-design.md` (#437) reaches for
		 * its own reasons.
		 *
		 * A store that had already stored `related-list` for settlements is
		 * not rewritten; {@see self::get_field_mode_settlement()} clamps it
		 * away on READ against this very list (design §7's "clamp on read"),
		 * so it degrades to `typeahead` and the stored option stays inert.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the settlement-axis condition (issue #404).
		 * @since 2.0.2 The settlement axis no longer offers `related-list` at
		 *              all; `$region_mode` is gone with it (operator decision,
		 *              24.08.2026).
		 *
		 * @param Location_Provider|null $provider           The provider to gate `related-list` against.
		 * @param bool                   $is_settlement_axis True for the SETTLEMENT axis, which never
		 *                                                   offers `related-list`.
		 *
		 * @return string[]
		 */
		private static function offered_field_modes_for( ?Location_Provider $provider, bool $is_settlement_axis = false ): array {
			$modes = [ self::MODE_TYPEAHEAD ];

			$provider_has_list = null !== $provider && in_array( Location_Provider::CAPABILITY_LIST, $provider->get_capabilities(), true );

			if ( $provider_has_list && ! $is_settlement_axis ) {
				$modes[] = self::MODE_RELATED_LIST;
			}

			// Unconditional (issue #380) — see self::MODE_AJAX_SELECT2's own docblock
			// for why this value no longer needs CAPABILITY_LIST.
			$modes[] = self::MODE_AJAX_SELECT2;

			return $modes;
		}

		/**
		 * User-facing labels for every mode in {@see self::FIELD_MODES} — the
		 * single place both the OFFERED-options builder below (shared by
		 * BOTH axes, issue #380) and (if a future task needs it) any other
		 * mode-labeling call site reads from, so the Russian copy exists
		 * exactly once. Wording matches the three canonical value names the
		 * operator settled on for the axis split (issue #380).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string> mode => label.
		 */
		private static function field_mode_labels(): array {
			return [
				self::MODE_TYPEAHEAD    => __( 'Текст с подсказками', 'woodev-plugin-framework' ),
				self::MODE_RELATED_LIST => __( 'Предустановленный список', 'woodev-plugin-framework' ),
				self::MODE_AJAX_SELECT2 => __( 'Список с поиском', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * Builds an `id => label` options map for a field-mode select (issue
		 * #380 — both axes started out offering the same three values from
		 * this one builder). The SETTLEMENT caller passes
		 * `$is_settlement_axis = true`, which drops `related-list` entirely —
		 * see {@see self::offered_field_modes_for()} for why.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the settlement-axis narrowing (issue #404).
		 *
		 * @param Location_Provider|null $provider           The provider to gate against.
		 * @param bool                   $is_settlement_axis True for the SETTLEMENT axis.
		 *
		 * @return array<string, string>
		 */
		private static function offered_field_mode_options( ?Location_Provider $provider, bool $is_settlement_axis = false ): array {
			$labels = self::field_mode_labels();

			$options = [];

			foreach ( self::offered_field_modes_for( $provider, $is_settlement_axis ) as $mode ) {
				$options[ $mode ] = $labels[ $mode ];
			}

			return $options;
		}

		/**
		 * Gets the store's REGION-level field-presentation mode (issue #380 —
		 * split from the legacy single `field_mode`), clamped against
		 * {@see self::get_offered_field_modes()} so a previously-saved value
		 * that the CURRENT active provider no longer supports (e.g. the store
		 * switched from a `list`-capable provider back to DaData) never
		 * silently serves a mode the provider cannot back — falls back to
		 * {@see self::MODE_TYPEAHEAD}, the one mode every provider can always
		 * serve, exactly like {@see self::get_active_provider()} falls back
		 * to {@see self::DEFAULT_PROVIDER_ID} for the analogous "stored value
		 * now names something unavailable" case.
		 *
		 * ALSO clamps to {@see self::MODE_TYPEAHEAD} once `region_field` is
		 * removed (issue #369 closure — design §7's "clamp on read" pattern,
		 * copied from {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings::effective()}):
		 * with no region field left on the checkout at all, the derived
		 * "предустановленный список" overlay this axis drives for the
		 * settlement level (see {@see self::inject_related_list_states()}'s
		 * own gate) can never engage, so `region_field=remove` combined with
		 * a list-mode region — today's silent НП breakage — becomes
		 * unconstructible. Deliberately NOT enforced via a cross-handler
		 * `show_if` alone: `region_field` belongs to
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings},
		 * this axis belongs to this registry, and `Composite_Settings_Handler::
		 * filter_visible_values()` splits a submission by owning child BEFORE
		 * evaluating conditions — a cross-handler `show_if` only hides the
		 * ADMIN control, it is not a correctness mechanism (design §7).
		 *
		 * Returns {@see self::MODE_TYPEAHEAD} outright while the gate is
		 * closed — mirrors {@see self::get_active_provider()}'s own "nothing
		 * to read from yet" early return.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_field_mode_region(): string {
			if ( null === $this->settings_handler ) {
				return self::MODE_TYPEAHEAD;
			}

			if ( 'remove' === $this->region_field_effective_value() ) {
				return self::MODE_TYPEAHEAD;
			}

			$stored  = (string) $this->settings_handler->get_value( self::SETTING_FIELD_MODE_REGION );
			$offered = $this->get_offered_field_modes();

			return in_array( $stored, $offered, true ) ? $stored : self::MODE_TYPEAHEAD;
		}

		/**
		 * Gets the store's SETTLEMENT (НП) level field-presentation mode
		 * (issue #380 — split from the legacy single `field_mode`), clamped
		 * against the provider-capability gate exactly like
		 * {@see self::get_field_mode_region()} — but WITHOUT that method's
		 * `region_field` clamp: the settlement level has no analogous "field
		 * removed" state of its own.
		 *
		 * ALSO clamps `related-list` away UNCONDITIONALLY: the settlement axis
		 * has exactly two modes, `typeahead` and `ajax-select2`, and never a
		 * third (operator decision, 24.08.2026 — see
		 * {@see self::offered_field_modes_for()} for the measurement that
		 * settled it). A store that had already stored `related-list` is NOT
		 * rewritten; it degrades to `typeahead` here and the stored option
		 * stays inert (design §7's "clamp on read" pattern, the same
		 * discipline {@see self::get_field_mode_region()} already applies for
		 * `region_field=remove`). Deliberately a READ-side clamp rather than a
		 * migration: narrowing the OFFERED values (this method, and
		 * {@see self::register_settings()} for the select's own options) is the
		 * mechanism already established in this file for the
		 * provider-capability gate, and one mechanism per concern beats two
		 * that must agree.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Also clamped against the region axis's own effective
		 *              mode (issue #404).
		 * @since 2.0.2 That cross-axis condition is gone — `related-list` is now
		 *              clamped away unconditionally (operator decision, 24.08.2026).
		 *
		 * @return string
		 */
		public function get_field_mode_settlement(): string {
			if ( null === $this->settings_handler ) {
				return self::MODE_TYPEAHEAD;
			}

			$stored  = (string) $this->settings_handler->get_value( self::SETTING_FIELD_MODE_SETTLEMENT );
			$offered = self::offered_field_modes_for( $this->get_active_provider(), true );

			return in_array( $stored, $offered, true ) ? $stored : self::MODE_TYPEAHEAD;
		}

		/**
		 * Whether the merchant has opted in to letting the customer submit a
		 * settlement value the active provider does not carry (#528).
		 *
		 * Deliberately UNCLAMPED against {@see self::get_field_mode_settlement()}
		 * — but this is safe ONLY because the client-side reader is scoped to
		 * BOTH the `ajax-select2` branch AND `'settlement' === seed.level`
		 * (`location-select-modes.js`'s `selectConfigFor()`, fixed against
		 * critic MJ-A on the #528 round-2 pass: the region axis can ALSO be
		 * `ajax-select2`, and this flag is read off the shared, level-blind
		 * `options.location` block — WITHOUT the level check, a stale `true`
		 * left over from a settlement-mode switch would still let the REGION
		 * field free-type, a value that posts as `billing_state`/
		 * `shipping_state`, permanent order data this setting's own label and
		 * `show_if` never promised to touch). With that level check in place,
		 * a stale `true` really is inert for every level other than
		 * settlement, and reaching `selectConfigFor()`'s ajax branch AT ALL
		 * for the settlement node already implies the settlement axis's OWN
		 * mode is `ajax-select2` — `location-cascade.js`'s own
		 * `resolveModeRenderer()` picks the renderer from
		 * `axisModeForLevel( entry, node.level )`, so a settlement node can
		 * never reach this branch under any other stored mode. A server-side
		 * clamp on THIS accessor would therefore change nothing a real
		 * customer can reach; it was considered and declined for exactly that
		 * reason (#528 round-2 report) rather than added as belt-and-braces.
		 * Same "narrow the OFFERED surface, not every reader" split
		 * {@see self::get_field_mode_settlement()}'s own docblock argues for
		 * `related-list`. Mirrors {@see self::get_address_suggestions_raw()}'s
		 * "no settings handler yet" shape: answers this setting's own
		 * default (`false`) rather than throwing.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Documented the level-scoping the client-side MJ-A fix
		 *              now depends on for this docblock's own claim to be true.
		 *
		 * @return bool
		 */
		public function is_custom_settlement_allowed(): bool {
			if ( null === $this->settings_handler ) {
				return false;
			}

			return (bool) $this->settings_handler->get_value( self::SETTING_ALLOW_CUSTOM_SETTLEMENT );
		}

		/**
		 * The stored `region_field` checkout-fields setting's EFFECTIVE value
		 * (`Checkout_Field_Settings::effective( 'region_field' )` —
		 * `'show'`/`'remove'`), the cross-handler read
		 * {@see self::get_field_mode_region()}'s own `region_field=remove`
		 * clamp needs (issue #369 closure). Reached via
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::get_field_settings()}
		 * — the SAME lazily-built, store-level singleton
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::register()}
		 * and this registry's own {@see self::register_settings()} (via
		 * `set_location_section()`) already both depend on — never a second,
		 * separately-constructed `Checkout_Field_Settings` instance.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function region_field_effective_value(): string {
			return (string) \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()
				->get_field_settings()
				->effective( 'region_field' );
		}



		/**
		 * The RAW stored value of the `address_suggestions` switch (Task 10;
		 * issue #362; design S3/§4.2/§7) — before availability is even
		 * considered. Deliberately NOT the merchant-facing answer (that is
		 * {@see self::is_address_suggestions_enabled()}); this exists
		 * specifically as {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_for_level()}'s
		 * GATE INPUT.
		 *
		 * Reading through the EFFECTIVE (clamped) value there would be
		 * circular: the effective value is `stored && available`, and
		 * `available` ({@see self::is_address_suggestions_available()}) is
		 * itself computed by walking the very D15 chain `provider_for_level()`
		 * is in the middle of resolving. Consulting the raw stored flag here
		 * breaks that circle — the gate only ever needs to know "did the
		 * merchant turn this off", never "is it currently possible", which is
		 * exactly what `provider_for_level()`'s own chain walk is about to
		 * determine on its own.
		 *
		 * Mirrors {@see self::get_field_mode_region()}'s "no settings handler yet"
		 * shape: returns `true` (this setting's own default) rather than
		 * `false` when `$this->settings_handler` is still null, so a caller
		 * reached before `init` priority 20 has collected sees the SAME
		 * "nothing is gated off yet" answer `get_field_mode_region()` gives for its
		 * own setting — an ungated `address` level, exactly like an unclamped
		 * `typeahead` field mode, is the safe default while there is
		 * genuinely nothing to read from.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function get_address_suggestions_raw(): bool {
			if ( null === $this->settings_handler ) {
				return true;
			}

			return (bool) $this->settings_handler->get_value( self::SETTING_ADDRESS_SUGGESTIONS );
		}

		/**
		 * Whether the D15 chain could serve the `address` suggest level for AT
		 * LEAST ONE country ANY registered provider declares (Task 10; issue
		 * #362; design S3/§3.2) — the availability half of "active provider
		 * serves `address`, OR the bundled DaData is configured".
		 *
		 * Deliberately bypasses the `address_suggestions` store gate — via
		 * {@see \Woodev\Framework\Shipping\Location\Location_Service::is_level_servable()},
		 * never {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_for_level()} —
		 * because this method answers "could the chain serve `address` if the
		 * switch were on", exactly what {@see self::register_settings()} needs
		 * to decide whether the control is disabled, and what
		 * {@see self::is_address_suggestions_enabled()} needs as the other half
		 * of its clamp. Also bypasses {@see \Woodev\Framework\Shipping\Location\Location_Service::FILTER_PROVIDER_FOR_LEVEL}
		 * for the same reason: a plugin swapping the resolved provider for one
		 * already-open request must not make the settings page lie about what
		 * is fundamentally possible.
		 *
		 * Candidate countries are gathered directly from every REGISTERED
		 * provider's own {@see Location_Provider::get_countries()} — NOT from
		 * {@see \Woodev\Framework\Shipping\Location\Location_Service::get_supported_countries()},
		 * even though {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}
		 * reads its own `countries` block that way. `get_supported_countries()`
		 * resolves each level through the PUBLIC, GATED
		 * {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_for_level()}
		 * (no `$country` argument) — for the `address` level specifically, that
		 * is the exact gate this method exists to answer independently of, so
		 * using it here would reintroduce the same circularity
		 * {@see self::get_address_suggestions_raw()}'s docblock explains: a
		 * provider that served ONLY `address` would silently vanish from the
		 * union the instant the switch went off, making "is it available"
		 * falsely depend on "is it currently on". Reading the registered
		 * providers' declared countries directly has no such dependency.
		 *
		 * Builds its OWN {@see \Woodev\Framework\Shipping\Location\Location_Service}
		 * passing `$this` explicitly rather than relying on that façade's
		 * default {@see self::instance()} lookup — this registry instance may
		 * not (yet) BE the singleton in a test, and even in production this
		 * keeps the dependency explicit rather than a settings-computation
		 * method reaching for global state.
		 *
		 * An empty candidate list (the gate is closed, so nothing has been
		 * registered at all) answers `false`: there is nothing to be
		 * available for, so this returns `false` rather than iterating zero
		 * candidates and landing on `false` by accident of an empty loop.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_address_suggestions_available(): bool {
			$candidate_countries = [];

			foreach ( $this->providers as $provider ) {
				foreach ( $provider->get_countries() as $code ) {
					$candidate_countries[ strtoupper( trim( (string) $code ) ) ] = true;
				}
			}

			if ( [] === $candidate_countries ) {
				return false;
			}

			$service = new Location_Service( $this );

			foreach ( array_keys( $candidate_countries ) as $country ) {
				if ( $service->is_level_servable( Location_Record::LEVEL_ADDRESS, $country ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The EFFECTIVE `address_suggestions` value (Task 10; issue #362;
		 * design S3/§4.2/§7): `stored && available`, clamped on read exactly
		 * like {@see self::get_field_mode_region()} clamps a stored mode the active
		 * provider no longer backs. A merchant's saved `true` never overrides
		 * reality: once nobody can serve `address` any more, this answers
		 * `false` regardless of what is literally stored — WITHOUT ever
		 * rewriting the stored value itself (spec §7), so switching providers
		 * back restores the merchant's earlier preference automatically.
		 *
		 * This is the honest, merchant-facing answer; it is deliberately NOT
		 * what {@see \Woodev\Framework\Shipping\Location\Location_Service::provider_for_level()}'s
		 * gate calls — see {@see self::get_address_suggestions_raw()}'s own
		 * docblock for why that gate needs the RAW value instead.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_address_suggestions_enabled(): bool {
			return $this->get_address_suggestions_raw() && $this->is_address_suggestions_available();
		}

		// -----------------------------------------------------------------
		// Default-locality policy (Task 14; spec D11) — same offered/clamp
		// shape as get_offered_field_modes()/get_field_mode_region() above, gated
		// by the CAPABILITY_LOCATE capability instead of CAPABILITY_LIST.
		// -----------------------------------------------------------------

		/**
		 * The actual gate {@see self::get_offered_default_locality_policies()}
		 * answers from — factored out as a static, provider-in/policies-out pure
		 * function so {@see self::register_settings()} can compute the SAME answer
		 * at construction time, mirroring {@see self::offered_field_modes_for()}
		 * exactly.
		 *
		 * `off` and `fixed` are unconditional — picking a fixed locality needs no
		 * provider capability beyond `suggest()`, which every provider implements.
		 * `geoip` is offered only when `$provider` declares
		 * {@see Location_Provider::CAPABILITY_LOCATE}.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider|null $provider The provider to gate against, or
		 *                                          `null` (no provider active).
		 *
		 * @return string[] Subset of {@see self::DEFAULT_LOCALITY_POLICIES}, always
		 *                  containing {@see self::DEFAULT_LOCALITY_POLICY_OFF} and
		 *                  {@see self::DEFAULT_LOCALITY_POLICY_FIXED}.
		 */
		private static function offered_default_locality_policies_for( ?Location_Provider $provider ): array {
			$policies = [ self::DEFAULT_LOCALITY_POLICY_OFF, self::DEFAULT_LOCALITY_POLICY_FIXED ];

			if ( null !== $provider && in_array( Location_Provider::CAPABILITY_LOCATE, $provider->get_capabilities(), true ) ) {
				$policies[] = self::DEFAULT_LOCALITY_POLICY_GEOIP;
			}

			return $policies;
		}

		/**
		 * Gets the default-locality policies the STORE SETTING is allowed to offer
		 * right now (Task 14; spec D11), gated by the ACTIVE provider's OWN
		 * `locate` capability.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] Subset of {@see self::DEFAULT_LOCALITY_POLICIES}.
		 */
		public function get_offered_default_locality_policies(): array {
			return self::offered_default_locality_policies_for( $this->get_active_provider() );
		}

		/**
		 * User-facing labels for every policy in {@see self::DEFAULT_LOCALITY_POLICIES}
		 * — mirrors {@see self::field_mode_labels()}'s own single-source-of-Russian-copy
		 * shape.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string> policy => label.
		 */
		private static function default_locality_policy_labels(): array {
			return [
				self::DEFAULT_LOCALITY_POLICY_OFF   => __( 'Отключено', 'woodev-plugin-framework' ),
				self::DEFAULT_LOCALITY_POLICY_FIXED => __( 'Фиксированная локация', 'woodev-plugin-framework' ),
				self::DEFAULT_LOCALITY_POLICY_GEOIP => __( 'По IP-адресу покупателя', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * Builds the `default_locality_policy` select's `id => label` options map,
		 * gated against `$provider` via {@see self::offered_default_locality_policies_for()}
		 * — mirrors {@see self::offered_field_mode_options()}.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider|null $provider The provider to gate against.
		 *
		 * @return array<string, string>
		 */
		private static function offered_default_locality_policy_options_for( ?Location_Provider $provider ): array {
			$labels  = self::default_locality_policy_labels();
			$options = [];

			foreach ( self::offered_default_locality_policies_for( $provider ) as $policy ) {
				$options[ $policy ] = $labels[ $policy ];
			}

			return $options;
		}

		/**
		 * Gets the store's default-locality policy (Task 14; spec D11), clamped
		 * against {@see self::get_offered_default_locality_policies()} so a
		 * previously-saved `geoip` value the CURRENT active provider no longer
		 * backs (e.g. the store switched away from a `locate`-capable provider)
		 * never silently keeps resolving through a capability that is no longer
		 * there — falls back to {@see self::DEFAULT_LOCALITY_POLICY_OFF}, exactly
		 * like {@see self::get_field_mode_region()} falls back to {@see self::MODE_TYPEAHEAD}.
		 *
		 * Returns {@see self::DEFAULT_LOCALITY_POLICY_OFF} outright while the gate
		 * is closed — mirrors {@see self::get_field_mode_region()}'s own early return.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_default_locality_policy(): string {
			if ( null === $this->settings_handler ) {
				return self::DEFAULT_LOCALITY_POLICY_OFF;
			}

			$stored  = (string) $this->settings_handler->get_value( self::SETTING_DEFAULT_LOCALITY_POLICY );
			$offered = $this->get_offered_default_locality_policies();

			return in_array( $stored, $offered, true ) ? $stored : self::DEFAULT_LOCALITY_POLICY_OFF;
		}

		/**
		 * Gets the merchant-picked FIXED default locality record, or `null` when
		 * unset, the gate is closed, or the stored value is not valid JSON /
		 * does not build a valid {@see Location_Record} — never throws (same
		 * degrade-to-null discipline {@see Customer_Location_Store::parse_stored()}
		 * applies to a corrupt stored blob).
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		public function get_default_locality_record(): ?Location_Record {
			if ( null === $this->settings_handler ) {
				return null;
			}

			$raw = $this->settings_handler->get_value( self::SETTING_DEFAULT_LOCALITY_RECORD, false );

			if ( ! is_string( $raw ) || '' === $raw ) {
				return null;
			}

			$decoded = json_decode( $raw, true );

			if ( ! is_array( $decoded ) ) {
				return null;
			}

			try {
				return Location_Record::from_array( $decoded );
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		/**
		 * Writes the merchant-picked FIXED default locality record, serialized as
		 * JSON — through the settings handler's own {@see \Woodev_Abstract_Settings::update_value()}
		 * (gotcha `woodev-setting-get-value-is-cached-not-a-live-option-read`:
		 * writing the option directly would leave this SAME request's cached
		 * {@see \Woodev_Setting::$value} stale). A no-op while the gate is closed
		 * — there is no settings handler to write through — and ALSO a no-op
		 * (issue #406 defect 3) when `$record` is foreign to the CURRENT active
		 * provider, mirroring {@see \Woodev\Framework\Shipping\Location\Location_Settings::validate_values()}'s
		 * REST-path rule so every writer, not only the form, is bound by the
		 * same invariant.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Refuses (no-op) a record foreign to the current active
		 *              provider (issue #406 defect 3) — previously wrote
		 *              unconditionally, the only writer that bypassed the
		 *              new `Location_Settings::validate_values()` check.
		 *
		 * @param Location_Record $record The record to store.
		 *
		 * @return void
		 */
		public function set_default_locality_record( Location_Record $record ): void {
			if ( null === $this->settings_handler ) {
				return;
			}

			// Issue #406 defect 3: this is the ONE public writer that bypasses
			// Location_Settings::validate_values() entirely — update_value()
			// only runs the record's own per-field string validation, never the
			// map-level cross-field check the REST save path is now gated by.
			// No in-repo caller exists today, but leaving this writer able to
			// persist a foreign record would silently overstate "the server is
			// authoritative for every writer" the moment one is added. Refusing
			// here — rather than throwing — matches this SAME method's existing
			// no-op-on-unmet-precondition style for the closed-gate case above.
			$active = $this->get_active_provider();

			if ( null === $active || $active->get_id() !== $record->provider_id() ) {
				return;
			}

			$this->settings_handler->update_value( self::SETTING_DEFAULT_LOCALITY_RECORD, wp_json_encode( $record->to_array() ) );
		}

		/*
		 * REMOVED (issue #406): get_default_locality_needs_repick() / set_default_locality_needs_repick()
		 * — spec §4.6/D15's "stranded record" flag, added by Task 14 for a
		 * form-external provider switch (wp option update, plugin
		 * deactivation) to signal. Deleted rather than wired up: its ONE
		 * historical write site was inside the customer-facing
		 * {@see Location_Service::resolve_fixed_default()}, and review
		 * finding F2 deliberately removed that call — a getter reachable by
		 * anonymous checkout traffic must never mutate a merchant setting —
		 * with nothing replacing it since (Task 14's own commit history: zero
		 * production callers, only round-trip tests). Wiring the setter alone
		 * would still be inert: {@see self::apply_default_locality_status_note()}
		 * already surfaces the SAME "stranded" condition, computed LIVE
		 * against {@see self::get_active_provider()} on every settings-page
		 * load — independent of any stored flag, so it already covers a
		 * form-external switch too, the merchant just sees it on next page
		 * load rather than immediately. A genuine form-external-change ALERT
		 * (a dashboard/system-status notice firing before the merchant
		 * thinks to open Location settings) is a real, separate feature —
		 * filed as issue #410 rather than half-built here as a flag nothing
		 * reads. The setting id itself, {@see self::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK},
		 * stays registered (still writable through the generic
		 * {@see \Woodev_Abstract_Settings} accessors, never rendered) — only
		 * these two dead typed wrappers are gone.
		 */

		/**
		 * Whether {@see self::inject_related_list_states()} itself successfully
		 * injected `$country`'s `woocommerce_states` options THIS request AND
		 * those options are still what WooCommerce is serving right now — the
		 * precise "is this non-empty state list ours" answer
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}
		 * needs for the issue #294 arbitration (see {@see self::inject_related_list_states()}'s
		 * own docblock for why the field-mode setting alone cannot answer this).
		 *
		 * `$final_states` is the country's FINAL `woocommerce_states` read —
		 * after every filter callback that ran this request, including any §8
		 * carrier takeover ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject_states()})
		 * that runs AFTER this injector (both are hooked at the default
		 * priority; this injector merely runs first) and clobbers its result
		 * unconditionally. Recording "we wrote it" alone (the pre-PR-304
		 * behaviour) answered a question that had already gone stale by the
		 * time it mattered: a later §8 takeover silently overwrote this
		 * layer's own injection while the recorded flag stayed `true`, so the
		 * #294 conflict this rule exists to surface never fired for the exact
		 * collision it was written for (PR #304 review finding 3) — the client
		 * was then offered carrier option TEXTS that appear in no `/location/list`
		 * response, so the label -> record lookup found nothing and nothing
		 * ever reached `/location/select`. Comparing against the FINAL read
		 * closes that gap, and covers any third party filtering
		 * `woocommerce_states` directly the same way.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Takes the caller's own FINAL `woocommerce_states` read
		 *              and compares it against what was actually injected,
		 *              rather than trusting the recorded flag alone (PR #304
		 *              review finding 3).
		 *
		 * @param string                $country      ISO-3166 alpha-2 country code, any case/whitespace.
		 * @param array<string, string> $final_states The country's FINAL registered WC states
		 *                                             (e.g. {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::wc_states()}'s
		 *                                             own read), compared against what this
		 *                                             injector itself wrote.
		 *
		 * @return bool
		 */
		public function owns_region_states( string $country, array $final_states ): bool {
			$country = strtoupper( trim( $country ) );

			return isset( $this->related_list_states_countries[ $country ] )
				&& $this->related_list_states_countries[ $country ] === $final_states;
		}

		/**
		 * Injects the active provider's enumerated regions as WooCommerce native
		 * states — the `related-list` mode's region renderer (Task 13; spec D7,
		 * issue #294 decision comment point 4: "related-list ... is the region
		 * level rendered THROUGH `woocommerce_states`, not a competitor to that
		 * filter").
		 *
		 * Hooked unconditionally (see {@see self::add_hooks()}); this callback
		 * itself is what actually gates on the live field-mode setting and the
		 * active provider's `list` capability + configuration, so a mode/provider
		 * switch takes effect on the very next `woocommerce_states` read with no
		 * re-hooking needed.
		 *
		 * Iterates the active provider's OWN static {@see Location_Provider::get_countries()}
		 * list (not the D15 chain-wide union {@see \Woodev\Framework\Shipping\Location\Location_Service::get_supported_countries()}
		 * computes — `list_localities()` is not D15 fallback-chained, see
		 * {@see self::get_offered_field_modes()}'s own docblock) and, for each
		 * country:
		 *
		 * - Skips it when `$states[$country]` is ALREADY non-empty — first-wins,
		 *   the same discipline {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject_states()}
		 *   already applies for two conflicting §8 carrier takeovers; whichever
		 *   filter callback runs first (bootstrap/plugin-construction order) owns
		 *   the country, and this injector never clobbers it.
		 * - NEVER writes an empty array when {@see Location_Provider::list_localities()}
		 *   returns nothing for a country the provider otherwise claims to cover —
		 *   an empty array tells WooCommerce "this country has no states at all"
		 *   and HIDES the region field entirely (gotcha
		 *   `checkout-field-takeover-woocommerce-states`); the country is simply
		 *   left untouched, falling back to whatever `woocommerce_states` already
		 *   held (native WC list, or nothing — WC then renders the region as a
		 *   plain text input, which the ALWAYS-AVAILABLE typeahead baseline can
		 *   still enhance).
		 * - Records every country it DID successfully inject into
		 *   {@see self::$related_list_states_countries}, so {@see self::owns_region_states()}
		 *   can answer precisely.
		 * - A provider's own `list_localities()` throwing is swallowed — a
		 *   misbehaving provider must never break checkout page rendering
		 *   (mirrors {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_suggest_request()}'s
		 *   own catch-and-degrade discipline for the equivalent REST path).
		 *
		 * The WC state array's VALUE is the record's own {@see Location_Record::label()}
		 * (trimmed) — a human-readable region name — never the record's
		 * {@see Location_Record::key()} (`provider_id:native_id`). That value is
		 * what {@see \WC_Countries::get_formatted_address()} resolves through the
		 * KEY to render on the order, and it persists PERMANENTLY into order
		 * data — a provider-namespaced key stored there renders as raw,
		 * meaningless text the instant this injector is not present to translate
		 * it back (a provider switch renamespaces the keys, a mode switch back to
		 * `typeahead` stops injecting entirely, and a deactivated plugin obviously
		 * never runs at all) — order history must stay meaningful without this
		 * plugin (measured on the rig, s71: an uninjected `dadata:0c089b04-…` key
		 * rendered verbatim in a formatted address). Identity already lives where
		 * it belongs — in our own customer location record, persisted through
		 * `/location/select` — so a second copy of identity inside a WooCommerce
		 * field this layer does not own would be both redundant and permanent.
		 * See `Checkout_Config`'s own class docblock for how the client-side
		 * related-list renderer maps the selected label back to a full record via
		 * `/location/list` instead.
		 *
		 * The KEY is the label UPPERCASED via WooCommerce's own `wc_strtoupper()`
		 * — never the bare (possibly mixed-case) label — because
		 * `WC_Checkout::validate_posted_data()` uppercases whatever the customer
		 * posted before matching it against the registered state keys
		 * (`includes/class-wc-checkout.php`); for WC's own native states this is a
		 * no-op (their keys are already uppercase codes), but a mixed-case human
		 * label used bare AS the key gets shouted into an UPPERCASE value that no
		 * longer equals the option's own key, so the next render's `selected()`
		 * check never matches and the field silently reverts to the placeholder
		 * (PR #304 review finding 2, measured on the rig: posting `Московская
		 * область` stored `МОСКОВСКАЯ ОБЛАСТЬ`, which the mixed-case key could
		 * never match again). Pre-uppercasing the key makes the round trip a
		 * no-op: the posted value already equals the key, WC's own uppercase
		 * normalization maps it straight back to itself, and
		 * `get_formatted_address()` still resolves the pretty (human-case) LABEL
		 * through that key.
		 *
		 * A record whose (trimmed) label is empty is skipped outright — an empty
		 * string is indistinguishable from WooCommerce's own "select an option…"
		 * placeholder and must never become a selectable option (PR #304 review
		 * finding 4).
		 *
		 * Two records legitimately colliding on the same UPPERCASED label within
		 * one country ARE possible (a provider's own data, not a coding mistake,
		 * and now also two labels differing only in case) and are a real
		 * ambiguity: WooCommerce's state array is keyed by value, so only one of
		 * them could ever be selected. The first one (provider's own enumeration
		 * order) wins; every later collision is dropped and reported via
		 * `_doing_it_wrong()` — the label-identity discipline this layer enforces
		 * makes a synthetic disambiguating suffix (e.g. appending the key) the
		 * wrong fix, since that suffix would itself leak into `billing_state` and
		 * recreate the exact opaque-value problem this method exists to remove.
		 *
		 * @internal Hooked to `woocommerce_states`; not part of the public
		 *           consumption surface.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 The injected VALUE changed from the record's `key()` to its
		 *              `label()` — a `billing_state`/`shipping_state` value is
		 *              permanent order data, and a provider-namespaced key has no
		 *              meaning once this injector stops running (rig measurement,
		 *              s71). Duplicate labels within one country are now detected
		 *              and reported instead of silently colliding.
		 * @since 2.0.2 The KEY is now `wc_strtoupper( trim( $label ) )` rather than
		 *              the bare label, and an empty/whitespace-only label is
		 *              skipped rather than registered — PR #304 review findings 2
		 *              and 4.
		 *
		 * @param mixed $states WC states keyed by country code, as received from
		 *                      whichever `woocommerce_states` callback ran before
		 *                      this one (or WooCommerce's own native list).
		 *
		 * @return array<string, array<string, string>>
		 */
		public function inject_related_list_states( $states ): array {
			$states = is_array( $states ) ? $states : [];

			// KNOWN LATENT CONSTRAINT (PR #304 review finding 7, not fixed — see
			// that review's own "not in scope" note): this method is hooked at
			// `plugins_loaded` ({@see self::add_hooks()}), but {@see self::get_field_mode_region()}
			// answers `self::MODE_TYPEAHEAD` until `$this->settings_handler` exists,
			// which {@see self::register_settings()} only builds at `init` priority
			// 20 ({@see self::collect()}). So ANY `woocommerce_states` read that
			// happens between `plugins_loaded` and `init:20` — even with the store
			// option genuinely set to `related-list` — sees this gate closed and
			// gets the un-injected list; because WordPress does not "replay" a
			// filter for a caller that already consumed its result, that caller's
			// copy of the list stays un-injected for the rest of the request. No
			// WooCommerce-core caller was found running that early (WC's own
			// checkout/order code all runs well after `init`), so this is latent,
			// not observed — left as a comment rather than restructured.
			//
			// Gated on the REGION axis alone (issue #380) — this injector governs
			// ONLY the region `<select>`'s options; whatever the settlement axis
			// carries is irrelevant here.
			if ( self::MODE_RELATED_LIST !== $this->get_field_mode_region() ) {
				return $states;
			}

			$provider = $this->get_active_provider();

			if ( null === $provider || ! $provider->is_configured()
				|| ! in_array( Location_Provider::CAPABILITY_LIST, $provider->get_capabilities(), true ) ) {
				return $states;
			}

			foreach ( $provider->get_countries() as $country ) {
				$country = strtoupper( trim( (string) $country ) );

				if ( isset( $states[ $country ] ) && [] !== $states[ $country ] ) {
					continue;
				}

				try {
					$records = $provider->list_localities( Location_Scope::for_country( $country, Location_Record::LEVEL_REGION ) );
				} catch ( \Throwable $exception ) {
					continue;
				}

				$options = [];

				foreach ( $records as $record ) {
					if ( ! $record instanceof Location_Record ) {
						continue;
					}

					$label = trim( $record->label() );

					if ( '' === $label ) {
						continue; // Finding 4: never a selectable-but-blank option.
					}

					$key = wc_strtoupper( $label );

					if ( isset( $options[ $key ] ) ) {
						_doing_it_wrong(
							__METHOD__,
							sprintf(
								"provider '%s' returned two regions labeled '%s' for country '%s'; only the first is offered in the related-list select, since the label is the value the customer submits and a second option under the same text would be indistinguishable to them",
								$provider->get_id(),
								$label,
								$country
							),
							'2.0.2'
						);

						continue;
					}

					$options[ $key ] = $label;
				}

				if ( [] === $options ) {
					continue;
				}

				$states[ $country ] = $options;

				$this->related_list_states_countries[ $country ] = $options;
			}

			return $states;
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
		 * read the exact same option name, so they can never disagree on WHICH ID
		 * is stored.
		 *
		 * The id -> instance resolution itself now goes through
		 * {@see self::resolve_active_provider_for_id()} — the SAME resolution
		 * {@see self::get_active_provider()} uses, {@see self::FILTER_ACTIVE_PROVIDER}
		 * included. Previously this method looked the id up directly against
		 * `$this->providers`, entirely skipping that filter — so a site using it to
		 * swap the resolved provider could make this settings page offer a
		 * NARROWER set of field modes than the runtime would actually accept for
		 * the very same store setting (PR #304 review finding 6).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Resolves the active provider through
		 *              {@see self::resolve_active_provider_for_id()} instead of a
		 *              direct `$this->providers[ $id ]` lookup (PR #304 review
		 *              finding 6).
		 * @since 2.0.2 Also builds the `default_locality_policy` select's OFFERED
		 *              options (Task 14; spec D11), gated exactly like
		 *              `field_mode` is above.
		 * @since 2.0.2 `$provider_fields` now comes from
		 *              {@see self::collect_all_provider_fields()} — EVERY
		 *              registered provider's declared fields, each carrying a
		 *              `show_if` condition, rather than only the active
		 *              provider's (#375/#377: "dynamic, without saving").
		 * @since 2.0.2 Runs {@see self::migrate_legacy_field_mode()} first —
		 *              issue #380 split the single `field_mode` option into
		 *              two axes; an untagged vendored snapshot may already
		 *              hold the legacy value.
		 * @since 2.0.2 The settlement axis's OFFERED options are built
		 *              separately from the region axis's own (issue #404), and
		 *              since 24.08.2026 simply never include `related-list` —
		 *              see {@see self::offered_field_modes_for()}.
		 * @since 2.0.2 Also hands `Location_Settings` a resolver CALLABLE
		 *              wrapping {@see self::resolve_active_provider_for_id()}
		 *              (issue #406 follow-up) — its `validate_values()`
		 *              cross-field check needs the SAME runtime resolution
		 *              {@see self::get_active_provider()} applies (including
		 *              {@see self::FILTER_ACTIVE_PROVIDER}) for BOTH a
		 *              submitted id and a stored one, never a raw string
		 *              compare against either.
		 *
		 * @return void
		 */
		private function register_settings(): void {
			// Issue #380: read the LEGACY single `field_mode` option once, if it is
			// still present, and decompose it onto the two new axes — BEFORE the two
			// new settings are registered below, since Woodev_Abstract_Settings::
			// register_setting() reads its own stored option value synchronously, at
			// registration time (see self::migrate_legacy_field_mode()'s own docblock).
			$this->migrate_legacy_field_mode();

			$active_id        = $this->resolve_stored_active_provider_id();
			$active_provider  = $this->resolve_active_provider_for_id( $active_id );
			$provider_fields  = $this->collect_all_provider_fields();

			$provider_options = [];
			foreach ( $this->providers as $id => $provider ) {
				$provider_options[ $id ] = $provider->get_name();
			}

			// The settlement axis never offers `related-list` (operator decision,
			// 24.08.2026) — see offered_field_modes_for()'s own docblock.
			$field_mode_region_options        = self::offered_field_mode_options( $active_provider );
			$field_mode_settlement_options    = self::offered_field_mode_options( $active_provider, true );
			$default_locality_policy_options  = self::offered_default_locality_policy_options_for( $active_provider );

			$this->settings_handler = new Location_Settings(
				self::SETTINGS_SERVICE_ID,
				$provider_options,
				$provider_fields,
				$field_mode_region_options,
				$field_mode_settlement_options,
				$default_locality_policy_options,
				// Issue #406 follow-up (second pass): a CALLABLE, not a
				// pre-computed id — resolve_active_provider_for_id() must
				// run per SUBMITTED id too (not only as a stored-value
				// fallback), including the FILTER_ACTIVE_PROVIDER filter,
				// exactly like self::get_active_provider() itself resolves.
				// A pre-computed snapshot could only ever answer for ONE id
				// (this request's stored one); validate_values() needs the
				// SAME answer for whatever id a submission moves to.
				function ( string $id ): string {
					$provider = $this->resolve_active_provider_for_id( $id );

					return null !== $provider ? $provider->get_id() : '';
				}
			);

			$this->apply_default_locality_status_note();
			$this->apply_address_suggestions_availability_gate();

			// Hand the handler over to Shipping_Settings_Tab (issue #362; design S1/S9)
			// instead of registering a tab of its own — «Локация» is now that tab's first
			// section. That class hooks its own registration on `init` priority 25, AFTER
			// this method's own `init` priority 20 (see collect()), so the handler handed
			// over here is already set by the time it builds the composite handler and
			// section list. The tab id changes from `location` to `shipping` (design S1);
			// this handler's own id — self::SETTINGS_SERVICE_ID, still 'location' — is an
			// installed-site option-name namespace (ADR-005) and stays exactly as-is.
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()->set_location_section(
				$this->settings_handler,
				$this->settings_handler->get_owned_setting_ids()
			);
		}


		/**
		 * One-time migration (issue #380): the single `field_mode` option
		 * this layer used to store became two — {@see self::SETTING_FIELD_MODE_REGION}
		 * and {@see self::SETTING_FIELD_MODE_SETTLEMENT}. Git tags prove only
		 * that no TAGGED release ever carried `woodev_location_field_mode` —
		 * this framework ships VENDORED inside every plugin, so an UNTAGGED
		 * snapshot may already be installed on a real site with that option
		 * set. Cheap insurance: read it once, decompose it losslessly onto
		 * both new axes (the exact table issue #380 verified: `typeahead` ->
		 * both typeahead, `related-list` -> both related-list, `ajax-select2`
		 * -> both ajax-select2), then delete the legacy option so this never
		 * runs twice.
		 *
		 * Deliberately NOT threaded through a per-plugin
		 * {@see Woodev_Lifecycle::upgrade_to_X_Y_Z()} routine: that mechanism
		 * is keyed to EACH PLUGIN's own `$upgrade_versions` list and version
		 * numbering, populated separately by every plugin's own Lifecycle
		 * subclass (e.g. `WC_Edostavka_Lifecycle`) — but `woodev_location_*`
		 * is a STORE-LEVEL option shared by the whole fleet (Location_Provider_Registry
		 * is a store-wide singleton, spec §4.1), with no single plugin version
		 * it is tied to. A per-plugin routine cannot reliably run exactly
		 * once for a value no plugin-specific version names. Gating on the
		 * legacy option's own PRESENCE instead — read here, on every
		 * `register_settings()` call, but a no-op the instant it is gone —
		 * is version-independent and self-throttling: after the first
		 * successful migration the legacy `get_option()` read below always
		 * returns `null` immediately.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function migrate_legacy_field_mode(): void {
			$prefix      = 'woodev_' . self::SETTINGS_SERVICE_ID;
			$legacy_name = $prefix . '_' . self::SETTING_FIELD_MODE;

			$legacy_value = get_option( $legacy_name, null );

			if ( null === $legacy_value || '' === $legacy_value ) {
				return; // nothing to migrate — a fresh install, or already migrated and deleted.
			}

			// Losslessly decompose (issue #380's own verified table): a legacy value
			// this layer no longer recognizes clamps to the always-safe typeahead,
			// exactly like every other read-side clamp in this class does.
			$decomposed = in_array( $legacy_value, self::FIELD_MODES, true ) ? $legacy_value : self::MODE_TYPEAHEAD;

			update_option( $prefix . '_' . self::SETTING_FIELD_MODE_REGION, $decomposed );
			update_option( $prefix . '_' . self::SETTING_FIELD_MODE_SETTLEMENT, $decomposed );

			delete_option( $legacy_name );
		}

		/**
		 * Collects EVERY registered provider's declared settings fields
		 * (#375/#377), each tagged with a `show_if` condition (ADR-008) on
		 * {@see self::SETTING_ACTIVE_PROVIDER} so the client shows/hides the
		 * right fields the instant the select changes — no save round-trip.
		 *
		 * Two shapes of condition:
		 *
		 * - A field belonging to any OTHER provider gets the plain equality
		 *   condition `{ setting: active_provider, value: <that provider's id> }`
		 *   — visible only while that exact provider is active.
		 * - A field belonging to {@see self::DEFAULT_PROVIDER_ID} (the
		 *   framework's OWN bundled provider — today DaData's `token` and
		 *   `clean_secret`) gets the WIDER `in` condition built by
		 *   {@see self::non_address_provider_ids()}: visible when the bundled
		 *   provider is itself active, OR when the active provider cannot
		 *   serve the `address` level at all — because then the bundled
		 *   provider is the only thing the D15 fallback chain
		 *   ({@see Location_Service::resolve_provider_for_level()}) can still
		 *   use for addresses, and its keys need to be reachable to enter.
		 *   Operator's variant 2 for #377, not variant 1 ("always show DaData's
		 *   keys") or variant 3 ("only when the `address_suggestions` switch is
		 *   on") — see that issue's own comment thread.
		 *
		 * Field-id COLLISIONS are now possible: the option namespace
		 * (`woodev_location_*`) is shared by every provider, so two unrelated
		 * providers declaring the same field id would otherwise silently
		 * overwrite one field's definition with the other's. First-registered
		 * wins and the conflict is reported via `_doing_it_wrong()` — the exact
		 * same discipline {@see self::inject_related_list_states()} already
		 * applies to a duplicate region LABEL, and {@see self::register_provider()}
		 * applies to a duplicate provider ID.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>> field id => field descriptor
		 *                                              (settings-API `register_setting()`
		 *                                              args shape), each carrying a
		 *                                              `show_if` key.
		 */
		private function collect_all_provider_fields(): array {
			$service            = new Location_Service( $this );
			$store_country      = $service->resolve_default_country();
			$wide_condition_ids = $this->non_address_provider_ids( $service, $store_country );

			$fields = [];

			foreach ( $this->providers as $provider_id => $provider ) {
				foreach ( $provider->get_settings_fields() as $field_id => $field ) {
					$field_id = (string) $field_id;

					if ( isset( $fields[ $field_id ] ) ) {
						_doing_it_wrong(
							__METHOD__,
							sprintf(
								'Two location providers declare a settings field under the same id "%s"; the first registration wins.',
								$field_id
							),
							'2.0.2'
						);

						continue;
					}

					$field           = (array) $field;
					$field['show_if'] = self::DEFAULT_PROVIDER_ID === $provider_id
						? [
							'operator' => 'in',
							'setting'  => self::SETTING_ACTIVE_PROVIDER,
							'value'    => $wide_condition_ids,
						]
						: [
							'setting' => self::SETTING_ACTIVE_PROVIDER,
							'value'   => $provider_id,
						];

					$fields[ $field_id ] = $field;
				}
			}

			return $fields;
		}

		/**
		 * The provider id list {@see self::collect_all_provider_fields()} uses
		 * as the WIDE `show_if` condition's `in` value for
		 * {@see self::DEFAULT_PROVIDER_ID}'s own fields: that provider's own id,
		 * plus every OTHER registered provider that either is NOT configured or
		 * does NOT serve the `address` level FOR THE STORE'S OWN COUNTRY.
		 *
		 * Country-scoped, deliberately — NOT a country-blind union over
		 * {@see Location_Provider::get_suggest_levels()} with no `$country`
		 * argument. "Serves address" genuinely varies by country for a real
		 * provider (the bundled DaData provider itself serves `address` in
		 * RU/BY/KZ/UZ but not in AM/AZ/KG/TJ/TM — {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider}'s
		 * own docblock), so a country-blind answer would ask a different
		 * question than the one the merchant is actually configuring for: THIS
		 * store, which has exactly one base country. `$store_country` is
		 * resolved by the caller via {@see Location_Service::resolve_default_country()}
		 * — the SAME checkout-field -> WooCommerce-store-setting -> `RU` chain
		 * every other country-scoped decision in this layer already goes
		 * through, never a second hand-rolled cascade.
		 *
		 * Reuses {@see Location_Service::provider_serves_level()} — never a
		 * hand-rolled `in_array( ..., $provider->get_suggest_levels( $country ) )`
		 * — because that predicate ALSO gates on {@see Location_Provider::get_countries()}
		 * coverage, which `get_suggest_levels()` alone does not encode. Together
		 * with {@see Location_Provider::is_configured()}, this is the same public
		 * predicate pair {@see Location_Service::resolve_provider_for_level()}
		 * uses before it stops the fallback chain — an address-capable carrier
		 * that is not YET configured must not be treated as covering address
		 * here either, or DaData's own keys would be hidden behind a provider
		 * the runtime fallback chain has already stopped trusting for that
		 * exact reason.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Service $service       Façade to reuse {@see Location_Service::provider_serves_level()} from.
		 * @param string           $store_country  ISO-3166 alpha-2 store country.
		 *
		 * @return string[] Provider ids, {@see self::DEFAULT_PROVIDER_ID} first.
		 */
		private function non_address_provider_ids( Location_Service $service, string $store_country ): array {
			$ids = [ self::DEFAULT_PROVIDER_ID ];

			foreach ( $this->providers as $id => $provider ) {
				if ( self::DEFAULT_PROVIDER_ID === $id ) {
					continue;
				}

				if ( ! $provider->is_configured() || ! $service->provider_serves_level( $provider, Location_Record::LEVEL_ADDRESS, $store_country ) ) {
					$ids[] = $id;
				}
			}

			return $ids;
		}

		/**
		 * Surfaces the `fixed` default-locality policy's "nothing usable is
		 * actually configured" state on the settings page (review finding F4),
		 * rather than leaving it silent: no picker UI ships in this task (a
		 * separate, later card), so a merchant who selects `fixed` and never
		 * picks a record — or whose picked record no longer matches the
		 * currently ACTIVE provider — would otherwise see nothing telling them
		 * the policy is not actually doing anything for a customer right now.
		 *
		 * Appends to the `default_locality_policy` select's own description
		 * (read by {@see \Woodev\Framework\Settings\Field_Schema::from_handler()}
		 * exactly like any other setting's description) rather than exposing
		 * {@see self::SETTING_DEFAULT_LOCALITY_RECORD} /
		 * {@see self::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK} as editable
		 * controls — see {@see Location_Settings::register_settings()}'s own
		 * docblock for why those two stay machine-owned, never merchant-typed.
		 *
		 * Computed LIVE against {@see self::get_active_provider()} rather than
		 * read from the (still-registered, but no longer written by
		 * {@see Location_Service}) `needs_repick` flag: review finding F2
		 * forbids the customer-facing getter that used to keep that flag
		 * honest from writing store settings at all, so nothing updates a
		 * stored flag any more — a LIVE check can never go stale the way a
		 * stored one, now orphaned, would. This deliberately compares against
		 * the ACTIVE provider alone rather than replicating
		 * {@see Location_Service::provider_for_level()}'s full D15
		 * chosen-then-bundled-fallback walk: duplicating that chain here would
		 * only drift from it, and for an informational admin note (not a
		 * runtime gate) "did the store switch away from the provider this was
		 * picked under" is the common case this exists to catch.
		 *
		 * Called from {@see self::register_settings()} once `$this->settings_handler`
		 * exists — {@see self::get_default_locality_policy()} /
		 * {@see self::get_default_locality_record()} / {@see self::get_active_provider()}
		 * all read through it.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function apply_default_locality_status_note(): void {
			if ( self::DEFAULT_LOCALITY_POLICY_FIXED !== $this->get_default_locality_policy() ) {
				return;
			}

			$setting = $this->settings_handler->get_setting( self::SETTING_DEFAULT_LOCALITY_POLICY );

			if ( null === $setting ) {
				return; // Defensive: register_settings() above always registers this id.
			}

			$record = $this->get_default_locality_record();

			if ( null === $record ) {
				$setting->set_description(
					__( 'Локация по умолчанию не настроена: пока не будет сохранена корректная запись, эта политика ничего не применит.', 'woodev-plugin-framework' )
				);

				return;
			}

			$active = $this->get_active_provider();

			if ( null === $active || $active->get_id() !== $record->provider_id() ) {
				$setting->set_description(
					__( 'Зафиксированная локация была выбрана через другого провайдера и может не подойти текущему — рекомендуется выбрать её заново.', 'woodev-plugin-framework' )
				);
			}
		}


		private function apply_address_suggestions_availability_gate(): void {
			if ( $this->is_address_suggestions_available() ) {
				return;
			}

			$setting = $this->settings_handler->get_setting( self::SETTING_ADDRESS_SUGGESTIONS );

			if ( null === $setting ) {
				return; // Defensive: register_settings() above always registers this id.
			}

			$control = $setting->get_control();

			/*
			 * A registered setting does NOT imply a registered control in this
			 * framework — {@see Location_Settings::register_settings()} deliberately
			 * registers two of its own settings (`default_locality_record`,
			 * `default_locality_needs_repick`) with no control at all, and
			 * {@see \Woodev_Setting::$control} is simply uninitialised until
			 * {@see \Woodev_Setting::set_control()} runs. `address_suggestions` does
			 * get a control today, so this guard never fires now; it exists because
			 * the failure mode if that ever changes is a FATAL
			 * ("call to a member function set_disabled() on null") on every admin
			 * request, and {@see \Woodev\Framework\Settings\Field_Schema::from_handler()}
			 * already null-checks the same accessor for the same reason (issue #362
			 * task 10, Codex critic finding P1).
			 */
			if ( null === $control ) {
				return;
			}

			$control->set_disabled(
				true,
				__( 'Выбранный провайдер не отдаёт адреса, а учётные данные DaData не заполнены.', 'woodev-plugin-framework' )
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


		/**
		 * Resolves a provider id to its registered instance, falling back to
		 * {@see self::DEFAULT_PROVIDER_ID} and applying {@see self::FILTER_ACTIVE_PROVIDER}
		 * — the single resolution both {@see self::get_active_provider()} and
		 * {@see self::register_settings()} now go through (PR #304 review finding
		 * 6), so the admin settings surface and the runtime can never disagree
		 * about which provider is active.
		 *
		 * @since 2.0.2
		 *
		 * @param string $active_id A provider id — typically the store setting's
		 *                          raw value, already resolved to a KNOWN id by
		 *                          the caller where possible (not required: an
		 *                          unknown id here still falls back safely).
		 *
		 * @return Location_Provider|null
		 */
		private function resolve_active_provider_for_id( string $active_id ): ?Location_Provider {
			$provider = $this->providers[ $active_id ] ?? $this->providers[ self::DEFAULT_PROVIDER_ID ] ?? null;

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
			 * @param string                 $active_id The id being resolved (before
			 *                                           the default-id fallback above
			 *                                           was applied).
			 */
			return apply_filters( self::FILTER_ACTIVE_PROVIDER, $provider, $active_id );
		}
	}

endif;

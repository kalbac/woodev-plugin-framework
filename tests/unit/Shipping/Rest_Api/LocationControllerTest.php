<?php
/**
 * Tests for Location_Controller — the woodev/v1 location REST routes (Task 8;
 * spec D1, D4, D8, D15).
 *
 * Covers: `q` length boundaries (both sides), `level` enum validation, the
 * "unknown/stale `within` key is treated as absent, not an error" rule, the
 * escaped `label` alongside the untouched round-trippable `record`, the
 * "no provider for this level" and "layer inactive" cases both degrading to
 * `{ suggestions: [] }` (200, never 404/500), a client-supplied `provider`
 * param being silently ignored, `/select`'s malformed-record 400 (nothing
 * written), `/select`'s inactive-layer 404, the nonce permission gate, and the
 * no-token/secret-leak guarantee (D4).
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Rest_Api\Location_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verification.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verifier.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

// Task 14: handle_admin_locate_request()'s own `WC_Geolocation::get_ip_address()`
// fallback needs this double — same stub RestRateLimitTraitTest already uses (see
// that file's own require for the full rationale).
if ( ! class_exists( '\\WC_Geolocation' ) ) {
	require_once __DIR__ . '/wc-geolocation-stub.php';
}

/**
 * Minimal \WP_REST_Request stand-in — identical shape/rationale to
 * PickupControllerTest's own namespace-scoped double (see that file's
 * docblock for why this is namespace-scoped rather than global).
 */
if ( ! class_exists( __NAMESPACE__ . '\\WP_REST_Request', false ) ) {
	class WP_REST_Request {

		/** @var array<string, mixed> */
		private array $params;

		/** @var array<string, string> */
		private array $headers;

		/**
		 * @param array<string, mixed>  $params  request params.
		 * @param array<string, string> $headers request headers.
		 */
		public function __construct( array $params = [], array $headers = [] ) {
			$this->params  = $params;
			$this->headers = $headers;
		}

		/**
		 * @param string $key param name.
		 *
		 * @return mixed|null
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param string $key header name.
		 *
		 * @return string|null
		 */
		public function get_header( $key ) {
			return $this->headers[ $key ] ?? null;
		}
	}
}

require_once dirname( __DIR__, 4 ) . '/woodev/http/trait-rest-rate-limit.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-location-controller.php';

/**
 * Popular-settlements (#488 slice 3) provider double: `resolve_key()` and
 * `suggest()` answers are both fully controlled per test — a
 * {@see Location_Record} (returned), `null` (returned), or a `\Throwable`
 * (thrown) for `resolve_key()`; an array of records (returned) or a
 * `\Throwable` (thrown) for `suggest()`.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Location_Controller_Popular_Provider_Fixture', false ) ) {
	class Location_Controller_Popular_Provider_Fixture extends Abstract_Location_Provider {

		private string $id;

		/** @var Location_Record|\Throwable|null */
		private $resolve_key_answer;

		/** @var Location_Record[]|\Throwable */
		private $suggest_answer;

		/** @var array<int, array{0: string, 1: Location_Scope}> */
		public array $suggest_calls = [];

		/**
		 * @param string                        $id                 provider id.
		 * @param Location_Record|\Throwable|null $resolve_key_answer what resolve_key() returns/throws.
		 * @param Location_Record[]|\Throwable    $suggest_answer     what suggest() returns/throws.
		 */
		public function __construct( string $id, $resolve_key_answer, $suggest_answer = [] ) {
			$this->id                 = $id;
			$this->resolve_key_answer = $resolve_key_answer;
			$this->suggest_answer     = $suggest_answer;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return 'Popular Provider Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			$this->suggest_calls[] = [ $query, $scope ];

			if ( $this->suggest_answer instanceof \Throwable ) {
				throw $this->suggest_answer;
			}

			return $this->suggest_answer;
		}

		public function resolve_key( string $key ): ?Location_Record {
			if ( $this->resolve_key_answer instanceof \Throwable ) {
				throw $this->resolve_key_answer;
			}

			return $this->resolve_key_answer;
		}
	}
}

/**
 * Popular-settlements (#488 slice 3) store double: answers `find_entry_by_key()`
 * / `is_stale()` from constructor-supplied fixed values (never a real `\wpdb`
 * lookup — the real store's own constructor is always safe to build with none,
 * see that class's docblock) and spies on every mutation
 * {@see Popular_Settlement_Verifier} might make, so a controller-level test can
 * assert exactly which one (if any) fired.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Location_Controller_Fake_Popular_Store', false ) ) {
	class Location_Controller_Fake_Popular_Store extends Popular_Settlement_Store {

		private ?Popular_Settlement_Entry $entry;
		private bool $stale;

		/** @var array<int, int> */
		public array $touch_verified_calls = [];

		/** @var array<int, array{0: int, 1: Location_Record}> */
		public array $replace_record_calls = [];

		/** @var array<int, int> */
		public array $delete_entry_calls = [];

		public function __construct( ?Popular_Settlement_Entry $entry = null, bool $stale = true ) {
			$this->entry = $entry;
			$this->stale = $stale;
		}

		public function find_entry_by_key( string $provider_id, string $key ): ?Popular_Settlement_Entry {
			if ( null === $this->entry ) {
				return null;
			}

			if ( $this->entry->provider_id() !== $provider_id || $this->entry->record()->key() !== $key ) {
				return null;
			}

			return $this->entry;
		}

		public function is_stale( Popular_Settlement_Entry $entry, ?int $ttl_seconds = null ): bool {
			return $this->stale;
		}

		public function touch_verified( int $id, ?int $timestamp = null ): void {
			$this->touch_verified_calls[] = $id;
		}

		public function replace_record( int $id, Location_Record $record, ?int $timestamp = null ): bool {
			$this->replace_record_calls[] = [ $id, $record ];

			return true;
		}

		public function delete_entry( int $id ): void {
			$this->delete_entry_calls[] = $id;
		}
	}
}

/**
 * Lightweight {@see Location_Service} test double: overrides the constructor
 * entirely (never calling the parent's, so none of its heavy collaborators —
 * the provider registry singleton, a real Customer_Location_Store — are ever
 * touched) and the four methods Location_Controller actually calls. Mirrors
 * the "Probe subclass" discipline used throughout this codebase
 * (Customer_Location_Store_Probe, Field_Source_Controller_Probe, …) rather
 * than re-proving Location_Service's own internals, which LocationServiceTest
 * already covers exhaustively.
 */
final class Location_Controller_Fake_Service extends Location_Service {

	private bool $active;
	private ?Location_Provider $provider;
	private ?array $customer_record;
	private bool $persist_result;
	private bool $country_supported;

	/**
	 * Task 13: {@see self::provider_for_list()}'s own return value — a
	 * SEPARATE fake from {@see self::$provider} (the `/suggest`/`/select`
	 * seam's own resolution), since `/list` resolves through
	 * {@see Location_Service::provider_for_list()}, a genuinely different
	 * D15-adjacent chain. Defaults to `null` — every pre-existing test in this
	 * file never touches `/list` and is unaffected.
	 *
	 * @var Location_Provider|null
	 */
	private ?Location_Provider $list_provider;

	/** @var array<int, string|null> */
	public array $provider_for_list_calls = [];

	/**
	 * Optional level => provider map (D15 gate fix, block PR-B test seam).
	 * When set, {@see self::provider_for_level()} resolves THROUGH this map
	 * instead of always returning the single {@see self::$provider} — lets a
	 * test simulate the D15 chain resolving a DIFFERENT provider (with its
	 * own, independently-configured `get_countries()`) per level, exactly
	 * the shape the real {@see Location_Service::provider_for_level()} chain
	 * produces. `null` (the default) preserves the original single-provider
	 * behaviour every pre-existing test in this file relies on.
	 *
	 * @var array<string, Location_Provider>|null
	 */
	private ?array $providers_by_level;

	/**
	 * Simulates {@see Location_Service::is_country_supported()}'s LEVEL-BLIND
	 * (`$level === null`) branch when {@see self::$providers_by_level} is set
	 * — i.e. what the ACTIVE provider alone would answer, the exact call
	 * shape the pre-fix controller used. Only meaningful together with
	 * {@see self::$providers_by_level}: it is what a regression test asserts
	 * the controller must NO LONGER fall back to.
	 *
	 * @var Location_Provider|null
	 */
	private ?Location_Provider $active_provider_for_level_blind_check;

	/** @var array<int, array{0: Location_Record, 1: bool}> */
	public array $set_calls = [];

	/**
	 * Issue #650: records the `$country` argument too (previously
	 * level-only) — a regression test asserts it is no longer `null`.
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	public array $provider_for_level_calls = [];

	/** @var array<int, array{0: string, 1: string|null}> */
	public array $is_country_supported_calls = [];

	/**
	 * Admin provider-override test seam (issue #380): the SET of provider ids
	 * {@see self::has_provider()} answers `true` for — a test simulating an
	 * "unknown id" simply never lists it here.
	 *
	 * @var array<int, string>
	 */
	private array $registered_provider_ids;

	/**
	 * Admin provider-override test seam (issue #380): id => what
	 * {@see self::provider_by_id()} answers for that id. An id present in
	 * {@see self::$registered_provider_ids} but ABSENT (or explicitly `null`)
	 * here simulates "registered but not currently eligible" (unconfigured,
	 * or does not serve the requested level/country) — the real method's own
	 * documented degradation.
	 *
	 * @var array<string, Location_Provider|null>
	 */
	private array $providers_by_id;

	/** @var array<int, string> */
	public array $has_provider_calls = [];

	/** @var array<int, array{0: string, 1: string, 2: string|null}> */
	public array $provider_by_id_calls = [];

	/**
	 * Task 14: {@see self::supports_locate()}'s own return value, and
	 * {@see self::locate()}'s own return value/spy — a SEPARATE pair from the
	 * `/suggest`/`/select`/`/list` fakes above, since the admin-only
	 * `/default-locality/locate` route resolves through these two
	 * {@see Location_Service} methods directly, not through `$provider`.
	 *
	 * @var bool
	 */
	private bool $supports_locate;

	/** @var Location_Record|null */
	private ?Location_Record $locate_result;

	/** @var array<int, string> */
	public array $locate_calls = [];

	/**
	 * Issue #330 (location-chain design §6): the FULL chain
	 * {@see self::get_customer_chain()} answers — a map `level =>
	 * Location_Record`. Defaults to `null`, in which case
	 * {@see self::get_customer_chain()} derives a ONE-ENTRY chain from
	 * {@see self::$customer_record} (the pre-existing, single-record fake
	 * behaviour every test predating #330 relies on) — so only a test that
	 * actually needs a MULTI-level chain (the bug this card fixes: `within`
	 * matching a NON-current chain record) passes this explicitly.
	 *
	 * @var array<string, Location_Record>|null
	 */
	private ?array $chain_records;

	/**
	 * @param array<string, Location_Provider>|null $providers_by_level                    Optional level => provider map — see {@see self::$providers_by_level}.
	 * @param Location_Provider|null                 $active_provider_for_level_blind_check See {@see self::$active_provider_for_level_blind_check}.
	 * @param Location_Provider|null                 $list_provider                         Task 13: {@see self::provider_for_list()}'s return value.
	 * @param bool                                    $supports_locate                       Task 14: {@see self::supports_locate()}'s own return value.
	 * @param Location_Record|null                   $locate_result                         Task 14: {@see self::locate()}'s own return value.
	 * @param array<string, Location_Record>|null    $chain_records                         Issue #330: see {@see self::$chain_records}.
	 * @param array<int, string>                     $registered_provider_ids               Issue #380: see {@see self::$registered_provider_ids}.
	 * @param array<string, Location_Provider|null>  $providers_by_id                       Issue #380: see {@see self::$providers_by_id}.
	 * @param Popular_Settlement_Store|null          $popular_store                         #488 slice 3: see {@see self::$popular_store}.
	 * @param Location_Provider|null                 $popular_provider                      #488 slice 3: see {@see self::$popular_provider}.
	 */
	public function __construct(
		bool $active = true,
		?Location_Provider $provider = null,
		?array $customer_record = null,
		bool $persist_result = true,
		bool $country_supported = true,
		?array $providers_by_level = null,
		?Location_Provider $active_provider_for_level_blind_check = null,
		?Location_Provider $list_provider = null,
		bool $supports_locate = false,
		?Location_Record $locate_result = null,
		?array $chain_records = null,
		array $registered_provider_ids = [],
		array $providers_by_id = [],
		?Popular_Settlement_Store $popular_store = null,
		?Location_Provider $popular_provider = null
	) {
		$this->active                                = $active;
		$this->provider                               = $provider;
		$this->customer_record                        = $customer_record;
		$this->persist_result                         = $persist_result;
		$this->country_supported                      = $country_supported;
		$this->providers_by_level                     = $providers_by_level;
		$this->active_provider_for_level_blind_check = $active_provider_for_level_blind_check;
		$this->list_provider                          = $list_provider;
		$this->supports_locate                        = $supports_locate;
		$this->locate_result                          = $locate_result;
		$this->chain_records                          = $chain_records;
		$this->registered_provider_ids                = $registered_provider_ids;
		$this->providers_by_id                        = $providers_by_id;
		$this->popular_store                          = $popular_store;
		$this->popular_provider                       = $popular_provider;
	}

	/**
	 * #488 slice 3 (D5): what {@see self::popular_settlement_store()} answers.
	 * `null` (the default) makes that method fall back to a fresh, entry-less
	 * {@see Location_Controller_Fake_Popular_Store} — i.e. `find_entry_by_key()`
	 * always misses, so the D5 step is a complete no-op and every test in this
	 * file predating #488 slice 3 is unaffected.
	 *
	 * @var Popular_Settlement_Store|null
	 */
	private ?Popular_Settlement_Store $popular_store;

	/**
	 * #488 slice 3 (D5): what {@see self::get_registered_provider()} answers —
	 * `null` (the default) simulates "no such provider registered at all".
	 *
	 * @var Location_Provider|null
	 */
	private ?Location_Provider $popular_provider;

	/**
	 * #488 slice 3 (D5): thin proxy for {@see Location_Service::popular_settlement_store()}.
	 * See {@see self::$popular_store}'s own docblock for the safe default.
	 *
	 * @return Popular_Settlement_Store
	 */
	public function popular_settlement_store(): Popular_Settlement_Store {
		return $this->popular_store ?? new Location_Controller_Fake_Popular_Store();
	}

	/**
	 * #488 slice 3 (D5): thin proxy for {@see Location_Service::get_registered_provider()}.
	 *
	 * @param string $provider_id Provider id (ignored — this fake answers the
	 *                             SAME {@see self::$popular_provider} regardless
	 *                             of which id was asked for; no existing/new
	 *                             test in this file needs per-id branching here).
	 *
	 * @return Location_Provider|null
	 */
	public function get_registered_provider( string $provider_id ): ?Location_Provider {
		return $this->popular_provider;
	}

	/**
	 * `$for_country` (#350/#352 follow-up, FIX 3): this fake never gates
	 * anything by country at all — it exists purely as a recording spy so a
	 * controller-level test can assert `build_scope()` actually PASSES its
	 * own already-normalized `$country` through here, rather than letting
	 * the read fall back to the ambient customer country. The deeper
	 * behavioural proof (that `Location_Service::get_customer_chain()`
	 * itself USES `$for_country` instead of the ambient
	 * `customer_shipping_country()`) lives in `LocationServiceTest` against
	 * the REAL method, not this fake.
	 *
	 * @var array<int, string|null>
	 */
	public array $get_customer_chain_calls = [];

	/**
	 * @param string|null $for_country See {@see self::$get_customer_chain_calls}.
	 *
	 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
	 */
	public function get_customer_chain( ?string $for_country = null ): ?array {
		$this->get_customer_chain_calls[] = $for_country;

		if ( null !== $this->chain_records ) {
			return [
				'records'  => $this->chain_records,
				'current'  => null !== $this->customer_record ? $this->customer_record['record']->level() : '',
				'implicit' => null !== $this->customer_record ? $this->customer_record['implicit'] : false,
				'saved_at' => null !== $this->customer_record ? $this->customer_record['saved_at'] : 0,
			];
		}

		if ( null === $this->customer_record ) {
			return null;
		}

		return [
			'records'  => [ $this->customer_record['record']->level() => $this->customer_record['record'] ],
			'current'  => $this->customer_record['record']->level(),
			'implicit' => $this->customer_record['implicit'],
			'saved_at' => $this->customer_record['saved_at'],
		];
	}

	public function supports_locate(): bool {
		return $this->supports_locate;
	}

	public function locate( string $ip ): ?Location_Record {
		$this->locate_calls[] = $ip;

		return $this->locate_result;
	}

	public function provider_for_list( ?string $country = null ): ?Location_Provider {
		$this->provider_for_list_calls[] = $country;

		return $this->list_provider;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
		$this->provider_for_level_calls[] = [ $level, $country ];

		if ( null !== $this->providers_by_level ) {
			return $this->providers_by_level[ $level ] ?? null;
		}

		return $this->provider;
	}

	public function has_provider( string $provider_id ): bool {
		$this->has_provider_calls[] = $provider_id;

		return in_array( $provider_id, $this->registered_provider_ids, true );
	}

	public function provider_by_id( string $provider_id, string $level, ?string $country = null ): ?Location_Provider {
		$this->provider_by_id_calls[] = [ $provider_id, $level, $country ];

		return $this->providers_by_id[ $provider_id ] ?? null;
	}

	public function get_customer_record( ?string $for_country = null ): ?array {
		return $this->customer_record;
	}

	public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
		$this->set_calls[] = [ $record, $implicit ];

		// Issue #330: a SUCCESSFUL write updates both "current" and the chain
		// entry at the written record's own level — a minimal simulation of
		// {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::set()}'s
		// own `records[ level ] = $record; current = level` (the ancestor-pruning
		// nuance beyond that is CustomerLocationStoreTest's own concern, not this
		// controller's) — so a test can assert on `handle_select_request()`'s
		// `chain` response key, which is read straight from
		// {@see self::get_customer_chain()} AFTER this call runs.
		if ( $this->persist_result ) {
			$this->customer_record = [
				'record'   => $record,
				'implicit' => $implicit,
				'saved_at' => 0,
			];

			$chain_records = $this->chain_records ?? [];
			$chain_records[ $record->level() ] = $record;
			$this->chain_records = $chain_records;
		}

		return $this->persist_result;
	}

	/**
	 * Without a {@see self::$providers_by_level} map, mirrors the original
	 * fixed-answer fake exactly (level-blind — the pre-existing behaviour
	 * every unchanged test in this file relies on). WITH the map, resolves
	 * the SAME level-specific provider {@see self::provider_for_level()}
	 * itself would return (or, for a `null` `$level`,
	 * {@see self::$active_provider_for_level_blind_check}) and checks the
	 * (normalized) country against THAT provider's OWN `get_countries()` —
	 * i.e. it genuinely exercises the D15 gate fix rather than returning a
	 * canned boolean, so a test using the map is proving the controller
	 * passes `$level` through correctly (and that doing so changes the
	 * answer versus the old level-blind call), not merely that this fake was
	 * told to say "true".
	 */
	public function is_country_supported( string $country, ?string $level = null ): bool {
		$this->is_country_supported_calls[] = [ $country, $level ];

		if ( null === $this->providers_by_level ) {
			return $this->country_supported;
		}

		$provider = null !== $level
			? ( $this->providers_by_level[ $level ] ?? null )
			: $this->active_provider_for_level_blind_check;

		if ( null === $provider ) {
			return false;
		}

		return in_array( strtoupper( trim( $country ) ), $provider->get_countries(), true );
	}
}

/**
 * Configurable fake provider: a closure decides what `suggest()` returns (or
 * throws), and every call is spied so tests can assert the exact query/scope
 * the controller built. `$countries` (D15 gate fix, block PR-B) lets a test
 * give a "chosen" and a "fallback" fake DIFFERENT country coverage — the
 * default `[ 'RU' ]` preserves every pre-existing call site unchanged.
 */
final class Location_Controller_Fake_Provider extends Abstract_Location_Provider {

	/** @var callable */
	private $suggest_callback;

	/** @var string[] */
	private array $countries;

	/** @var array<int, array{0: string, 1: Location_Scope}> */
	public array $suggest_calls = [];

	/**
	 * @param callable $suggest_callback Decides suggest()'s return value (or throws).
	 * @param string[] $countries        ISO-3166 alpha-2 codes this fake covers.
	 */
	public function __construct( callable $suggest_callback, array $countries = [ 'RU' ] ) {
		$this->suggest_callback = $suggest_callback;
		$this->countries        = $countries;
	}

	public function get_id(): string {
		return 'fake';
	}

	public function get_name(): string {
		return 'Fake';
	}

	public function get_countries(): array {
		return $this->countries;
	}

	protected function declare_suggest_levels(): array {
		return Location_Record::LEVELS;
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		$this->suggest_calls[] = [ $query, $scope ];

		return ( $this->suggest_callback )( $query, $scope );
	}
}

/**
 * Task 13: a {@see Location_Controller_Fake_Provider} sibling that DOES
 * override `list_localities()` — kept as a SEPARATE class rather than a
 * conditional branch inside the one above, so reflection-derived capability
 * discovery ({@see Abstract_Location_Provider::get_capabilities()}) correctly
 * reports `list` present only for instances that genuinely need it; a
 * conditionally-no-op override on the shared class would report the
 * capability for every pre-existing test too, which is not what any of them
 * intend to exercise.
 */
final class Location_Controller_Fake_List_Provider extends Abstract_Location_Provider {

	/** @var callable */
	private $list_callback;

	/** @var string[] */
	private array $countries;

	/** @var array<int, Location_Scope> */
	public array $list_calls = [];

	public function __construct( callable $list_callback, array $countries = [ 'RU' ] ) {
		$this->list_callback = $list_callback;
		$this->countries     = $countries;
	}

	public function get_id(): string {
		return 'fake-list';
	}

	public function get_name(): string {
		return 'Fake List';
	}

	public function get_countries(): array {
		return $this->countries;
	}

	protected function declare_suggest_levels(): array {
		return Location_Record::LEVELS;
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function list_localities( Location_Scope $scope ): array {
		$this->list_calls[] = $scope;

		return ( $this->list_callback )( $scope );
	}
}

/**
 * Probe bypassing the rate limiter — mirrors Field_Source_Controller_Probe /
 * Pickup_Controller's own probe pattern; the rate-limit MECHANISM itself is
 * exhaustively covered by RestRateLimitTraitTest, not re-proven here.
 */
final class Location_Controller_Probe extends Location_Controller {

	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		return false;
	}
}

/**
 * Review finding F1(b): spies on {@see Location_Controller::bridge_wc_session()}
 * without needing `WC()`/`wc_load_cart()` to be real functions in the
 * unit-test process — mirrors {@see Location_Controller_Probe}'s own
 * rate-limit bypass, plus this one extra seam.
 */
final class Location_Controller_Session_Bridge_Probe extends Location_Controller {

	/** @var int */
	public int $bridge_calls = 0;

	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		return false;
	}

	protected function bridge_wc_session(): void {
		++$this->bridge_calls;
	}
}

/**
 * Issue #324: records WHEN the session bridge ran relative to the write, not merely
 * that it ran. Holds its own reference to the fake service because
 * {@see Location_Controller::$service} is private — the probe is the only thing that
 * can see both sides of the ordering.
 */
final class Location_Controller_Select_Order_Probe extends Location_Controller {

	/** @var string[] */
	public array $order = [];

	/** @var Location_Controller_Fake_Service */
	private Location_Controller_Fake_Service $spy;

	public function __construct( Location_Controller_Fake_Service $service ) {
		parent::__construct( $service );

		$this->spy = $service;
	}

	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		return false;
	}

	protected function bridge_wc_session(): void {
		$this->order[] = 'bridge@' . count( $this->spy->set_calls ) . '-writes';
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Location_Controller
 */
final class LocationControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
		// stubEscapeFunctions() returns the input verbatim; override esc_html so the
		// escaping contract on the top-level `label` is actually exercised.
		Functions\when( 'esc_html' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES );
			}
		);
		Functions\when( 'rest_ensure_response' )->returnArg();
		// Task 14: check_admin_permission()'s own denial status, and
		// handle_admin_locate_request()'s WC_Geolocation fallback path.
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	private function record( string $key = 'dadata:fias-1', string $level = Location_Record::LEVEL_SETTLEMENT ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $key,
				'provider_id' => explode( ':', $key )[0],
				'level'       => $level,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
	}

	private function region_record( string $key = 'dadata:region-1' ): Location_Record {
		return $this->record( $key, Location_Record::LEVEL_REGION );
	}

	// -------------------------------------------------------------------
	// /suggest — `q` length boundaries (BOTH sides — a rejection-only test
	// would pass even with the wrong limit)
	// -------------------------------------------------------------------

	public function test_suggest_rejects_a_query_one_char_under_the_minimum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'a', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_suggest_accepts_a_query_exactly_at_the_minimum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'ab', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
	}

	public function test_suggest_accepts_a_query_exactly_at_the_maximum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$q       = str_repeat( 'a', 128 );
		$request = new WP_REST_Request( [ 'q' => $q, 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_suggest_rejects_a_query_one_char_over_the_maximum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$q       = str_repeat( 'a', 129 );
		$request = new WP_REST_Request( [ 'q' => $q, 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// /suggest — `level` enum
	// -------------------------------------------------------------------

	public function test_suggest_rejects_an_unknown_level(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// /suggest — happy path: escaped label, untouched round-trippable record
	// -------------------------------------------------------------------

	public function test_suggest_happy_path_returns_shaped_suggestions(): void {
		$record   = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => '<b>Москва</b>',
			]
		);
		$provider = new Location_Controller_Fake_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertSame( 1, count( $result['suggestions'] ) );
		$suggestion = $result['suggestions'][0];

		$this->assertSame( 'dadata:fias-1', $suggestion['key'] );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $suggestion['level'] );
		$this->assertStringContainsString( '&lt;b&gt;', $suggestion['label'], 'top-level label must be escaped' );
		$this->assertStringNotContainsString( '<b>', $suggestion['label'] );

		// The `record` payload must round-trip UNTOUCHED — Location_Record::from_array()
		// must accept it back verbatim (D12/D5's own contract).
		$this->assertSame( $record->to_array(), $suggestion['record'] );
		$round_tripped = Location_Record::from_array( $suggestion['record'] );
		$this->assertSame( $record->key(), $round_tripped->key() );
	}

	public function test_suggest_never_reads_a_client_supplied_provider_param(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		// A request naming a provider must be dispatched through the SAME
		// server-resolved provider regardless — the param is never consulted.
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU', 'provider' => 'cdek' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $service->provider_for_level_calls );
	}

	// -------------------------------------------------------------------
	// Issue #405: a RESOLVED provider's `suggest()` call must answer
	// DIFFERENTLY depending on whether the request FAILED (throws -> 502)
	// or COMPLETED with nothing to show (returns `[]` -> 200 + empty) — the
	// whole gap the issue closes. Mirrors `/list`'s own
	// `test_list_provider_exception_returns_502()` below.
	// -------------------------------------------------------------------

	public function test_suggest_provider_resolves_to_zero_matches_returns_empty_200(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Заброшенный', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertSame( [], $result['suggestions'] );
	}

	public function test_suggest_provider_exception_returns_502(): void {
		$provider = new Location_Controller_Fake_Provider(
			static function () {
				throw new \RuntimeException( 'wrong keys — upstream rejected the request' );
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// log_failure() — the fourth log boundary (#585, #593): a foreign
	// exception message is redacted before it reaches error_log(), through
	// Woodev_API_Base::redact_secret_log_text() — same mechanism as
	// Pickup_Controller/Pickup_Handler/Woodev_Plugin_Updater. Uses the plain
	// Location_Controller_Probe (bypasses only the rate limiter; log_failure()
	// is private and untouched, so this exercises the real method).
	// -------------------------------------------------------------------

	public function test_suggest_provider_failure_redacts_a_secret_in_the_exception_message(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				\Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$provider = new Location_Controller_Fake_Provider(
			static function () {
				throw new \RuntimeException( 'carrier rejected api_key=LIVESECRET' );
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertSame(
			'[woodev] location suggest (fake) failed: carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	/**
	 * Control: an exception message carrying NO secret must reach the
	 * rendered error_log() line byte-for-byte — asserted on the COMPLETE
	 * rendered line, not merely a substring.
	 */
	public function test_suggest_provider_failure_leaves_a_message_without_a_secret_untouched(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				\Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$provider = new Location_Controller_Fake_Provider(
			static function () {
				throw new \RuntimeException( 'wrong keys — upstream rejected the request' );
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertSame(
			'[woodev] location suggest (fake) failed: wrong keys — upstream rejected the request',
			$captured
		);
	}

	/**
	 * The do_action() fired alongside error_log() must still hand the
	 * consumer the RAW, unredacted exception — the docblock's own contract:
	 * that action is not itself a log boundary, and a consumer may need the
	 * real exception object. Only error_log() redacts.
	 */
	public function test_suggest_provider_failure_action_still_receives_the_raw_unredacted_exception(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'error_log' )->justReturn( true );

		$exception = new \RuntimeException( 'carrier rejected api_key=LIVESECRET' );
		$provider  = new Location_Controller_Fake_Provider(
			static function () use ( $exception ) {
				throw $exception;
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$logged = [];
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$logged ) {
				$logged[] = $args;
			}
		);

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $logged );
		$this->assertSame( $exception, $logged[0][3], 'the action must still receive the raw exception object, unredacted' );
		$this->assertSame( 'carrier rejected api_key=LIVESECRET', $logged[0][3]->getMessage() );
	}

	// -------------------------------------------------------------------
	// /suggest — degradation: no provider for the level, and the whole
	// layer inactive, BOTH collapse to 200 + empty (never 404/500)
	// -------------------------------------------------------------------

	public function test_suggest_no_provider_for_level_returns_empty_200(): void {
		$service = new Location_Controller_Fake_Service( true, null ); // active, but no provider serves this level
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
	}

	public function test_suggest_inactive_layer_returns_empty_200_not_404(): void {
		// Location_Service::provider_for_level() itself returns null while the
		// gate is closed (get_active_provider() returns null) — the fake mirrors
		// that by handing back a null provider regardless of $active.
		$service = new Location_Controller_Fake_Service( false, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
	}

	/**
	 * P2 review finding (the other half): a well-formed but UNSUPPORTED
	 * country must degrade the same way "no provider for this level" already
	 * does — 200 + empty — WITHOUT ever reaching the provider, so an
	 * unsupported-country request never consumes upstream quota. A malformed
	 * country keeps its own dedicated 400 (build_scope's own validation),
	 * unaffected by this check.
	 */
	public function test_suggest_unsupported_country_returns_empty_200_without_calling_the_provider(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, false );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'US' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
		$this->assertCount( 0, $provider->suggest_calls, 'an unsupported country must never reach the provider' );
	}

	public function test_suggest_supported_country_still_reaches_the_provider(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, true );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $provider->suggest_calls );
	}

	/**
	 * Issue #650: `provider_for_level()` used to be called level-only —
	 * country-blind — so a provider that declares a level but only covers
	 * SOME OTHER country could be chosen for a request naming this one. The
	 * resolved `$country` must now reach that call.
	 */
	public function test_suggest_passes_the_resolved_country_into_provider_for_level(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertSame( [ [ Location_Record::LEVEL_REGION, 'RU' ] ], $service->provider_for_level_calls );
	}

	public function test_suggest_a_malformed_country_still_returns_400_not_the_unsupported_degradation(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		// country_supported=false must NOT be why this 400s — is_country_supported()
		// itself degrades to false for malformed input too, but build_scope()'s own
		// format validation must win and return 400 before that check is even reached.
		$service = new Location_Controller_Fake_Service( true, $provider, null, true, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'not-a-code' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $provider->suggest_calls );
	}

	// -------------------------------------------------------------------
	// /suggest — issue #296: an empty `country` param (a checkout with no
	// country field at all sends exactly this — see location-cascade.js's own
	// countryFor()) falls back through Location_Service::resolve_default_country()
	// instead of hitting build_scope()'s 400. Location_Controller_Fake_Service
	// does NOT override resolve_default_country(), so every test below runs
	// the REAL method body (wc_get_base_location()/apply_filters(), stubbed
	// per test — PR #320 review, finding 3: wc_get_base_location(), never a
	// raw get_option() read) — never a canned fixture value.
	// -------------------------------------------------------------------

	public function test_suggest_with_no_country_param_falls_back_to_the_wc_store_setting(): void {
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'KZ', 'state' => 'north' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Location_Controller_Fake_Provider( static fn() => [], [ 'KZ' ] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, true );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Ал', 'level' => Location_Record::LEVEL_REGION, 'country' => '' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'an empty country must resolve through the fallback chain, never 400' );
		$this->assertCount( 1, $provider->suggest_calls );
		$this->assertSame(
			'KZ',
			$provider->suggest_calls[0][1]->country(),
			'must scope by the WooCommerce base country, splitting the "COUNTRY:STATE" option on the first ":"'
		);
		$this->assertSame(
			[ 'KZ', Location_Record::LEVEL_REGION ],
			$service->is_country_supported_calls[0],
			'the RESOLVED country must be what is checked for support, not the empty request param'
		);
	}

	public function test_suggest_with_no_country_param_and_no_store_setting_falls_back_to_ru(): void {
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => '', 'state' => '' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Location_Controller_Fake_Provider( static fn() => [], [ 'RU' ] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, true );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => '' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $provider->suggest_calls );
		$this->assertSame( 'RU', $provider->suggest_calls[0][1]->country() );
	}

	public function test_suggest_a_present_country_param_is_never_overridden_by_the_fallback(): void {
		// Would answer 'KZ' if the fallback were wrongly consulted for a NON-empty
		// country — the request's own value must always win when present.
		Functions\when( 'get_option' )->justReturn( 'KZ' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$provider = new Location_Controller_Fake_Provider( static fn() => [], [ 'RU' ] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, true );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $provider->suggest_calls );
		$this->assertSame( 'RU', $provider->suggest_calls[0][1]->country() );
	}

	// -------------------------------------------------------------------
	// /suggest — D15 gate fix (block PR-B): the country check must be gated
	// by the provider that ACTUALLY serves the requested level (chosen, or
	// the D15 fallback), never by the active provider unconditionally — both
	// wrong directions, pinned with independently-countried fake providers.
	// -------------------------------------------------------------------

	/**
	 * False-suppression direction: the chosen provider does not cover "address"
	 * at all (only the fallback resolves for that level), and the fallback
	 * covers a country ("BY") the chosen provider does not. The request must
	 * still reach the fallback and return its suggestions — gating against the
	 * ACTIVE provider's own country list would have wrongly suppressed this.
	 */
	public function test_suggest_reaches_the_fallback_for_a_country_only_the_fallback_covers(): void {
		$suggested = Location_Record::from_array(
			[
				'key'         => 'fallback:by-1',
				'provider_id' => 'fallback',
				'level'       => Location_Record::LEVEL_ADDRESS,
				'country'     => 'BY',
				'label'       => 'Минск',
			]
		);
		$fallback = new Location_Controller_Fake_Provider( static fn() => [ $suggested ], [ 'RU', 'BY' ] );

		$service = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			[ Location_Record::LEVEL_ADDRESS => $fallback ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мин', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'BY' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['suggestions'], 'a country the resolved fallback covers must not be suppressed' );
		$this->assertCount( 1, $fallback->suggest_calls, 'the provider that actually serves this level must be called' );
		$this->assertSame(
			[ [ 'BY', Location_Record::LEVEL_ADDRESS ] ],
			$service->is_country_supported_calls,
			'the country check must be made WITH the requested level, not level-blind'
		);
	}

	/**
	 * False-admission direction: the level's resolved provider (the fallback)
	 * does NOT cover the requested country, even though the ACTIVE (chosen)
	 * provider elsewhere in the chain DOES — `$chosen` stands in for what the
	 * pre-fix controller's level-blind `is_country_supported( $country )` call
	 * would have consulted, and it would have wrongly admitted this request.
	 * The request must degrade to empty WITHOUT ever reaching the resolved
	 * provider — gating against a provider that happens to cover the country,
	 * rather than the one that actually resolved for this level, would have
	 * wasted upstream quota on a lookup that cannot succeed.
	 */
	public function test_suggest_never_reaches_a_provider_for_a_country_it_does_not_cover_even_when_resolved_for_the_level(): void {
		$chosen   = new Location_Controller_Fake_Provider( static fn() => [], [ 'RU', 'KZ' ] );
		$fallback = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ], [ 'RU' ] );

		$service = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			[ Location_Record::LEVEL_ADDRESS => $fallback ],
			$chosen
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'KZ' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
		$this->assertCount( 0, $fallback->suggest_calls, 'a country the resolved provider does not cover must never reach it' );
		$this->assertCount( 0, $chosen->suggest_calls, 'the active provider is never the one dispatched to for this level' );
	}

	public function test_suggest_never_fatals_for_an_unsupported_level(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		foreach ( Location_Record::LEVELS as $level ) {
			$request = new WP_REST_Request( [ 'q' => 'ab', 'level' => $level, 'country' => 'RU' ] );
			$result  = $ctrl->handle_suggest_request( $request );

			$this->assertNotInstanceOf( \WP_Error::class, $result, "level \"$level\" must not error when unsupported" );
		}
	}

	// -------------------------------------------------------------------
	// /suggest — `within` narrowing: matches, mismatches, and level-ordering
	// mismatches ALL degrade silently to a country-wide scope — never an error
	// -------------------------------------------------------------------

	public function test_suggest_within_matching_current_record_narrows_the_scope(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent() );
		$this->assertSame( $parent, $captured->parent_record() );
	}

	public function test_suggest_unknown_within_key_is_treated_as_absent_not_an_error(): void {
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		// No customer record at all stored server-side.
		$service = new Location_Controller_Fake_Service( true, $provider, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:some-stale-key' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'a stale within key must never error the field' );
		$this->assertNotNull( $captured );
		$this->assertFalse( $captured->has_parent(), 'an unmatched within key must fall back to a country-wide scope' );
		$this->assertSame( 'RU', $captured->country() );
	}

	public function test_suggest_within_key_mismatching_the_current_record_is_treated_as_absent(): void {
		$stored   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $stored, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		// Client believes the parent is "region-2"; server actually holds "region-1".
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-2' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertFalse( $captured->has_parent() );
	}

	public function test_suggest_within_key_with_wrong_level_ordering_is_treated_as_absent(): void {
		// The stored "current" record is itself a REGION — narrowing a region-level
		// search by a region parent is nonsensical (no level is shallower than
		// region); Location_Scope::within() refuses it, and the controller must
		// swallow that refusal exactly like an unmatched key.
		$stored   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $stored, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $captured->has_parent() );
		$this->assertSame( 'bad_level', $result['within_status'], '#333: a level-ordering mismatch reports bad_level, not just a swallowed within_applied' );
	}

	// -------------------------------------------------------------------
	// /suggest — issue #330: `within` must resolve against ANY record in the
	// customer's chain, not only the CURRENT one. Before the fix, a settlement
	// `within` sent alongside an address-level search (the ordinary shape of
	// an address lookup, since `current` is address-level at that point) was
	// silently ignored and the search fell through to a country-wide scope.
	// -------------------------------------------------------------------

	/**
	 * THE bug this card fixes: `within` names the SETTLEMENT the customer
	 * picked, but the customer's CURRENT record is already address-level (the
	 * ordinary shape of an address-field search) — the settlement is still in
	 * the chain, just not `current`, and must still resolve.
	 */
	public function test_suggest_within_matching_a_non_current_chain_record_resolves(): void {
		$settlement = $this->record( 'dadata:settlement-1', Location_Record::LEVEL_SETTLEMENT );
		$address    = $this->record( 'dadata:address-1', Location_Record::LEVEL_ADDRESS );
		$captured   = null;
		$provider   = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service(
			true,
			$provider,
			[ 'record' => $address, 'implicit' => false, 'saved_at' => 0 ], // "current" is the ADDRESS.
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			[
				Location_Record::LEVEL_SETTLEMENT => $settlement,
				Location_Record::LEVEL_ADDRESS     => $address,
			]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Твер', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU', 'within' => 'dadata:settlement-1' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent(), 'a within key matching a NON-current chain record must still resolve' );
		$this->assertSame( $settlement, $captured->parent_record() );
	}

	/**
	 * #350/#352 follow-up (FIX 3): `build_scope()` must pass ITS OWN
	 * already-normalized `$country` through to
	 * `Location_Service::get_customer_chain()` as `$for_country`, rather
	 * than letting that read fall back to whatever the ambient WooCommerce
	 * customer answers — a `/suggest` request's own `country` param is the
	 * stronger authority for THIS request (see gotcha
	 * `wc-customer-default-location-geolocation-fallback` for why the
	 * ambient customer can disagree with it even on a freshly-booted guest).
	 * `LocationServiceTest` proves the deeper mechanism (that
	 * `get_customer_chain( $for_country )` actually USES it instead of the
	 * ambient country); this test proves the CONTROLLER wiring — that
	 * `build_scope()` actually passes it.
	 */
	public function test_suggest_passes_its_own_normalized_country_into_get_customer_chain(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		// get_customer_chain() is only reached when build_scope() has a
		// `within` key to resolve at all (see build_scope()'s own early
		// return for '' === $within_key) — a real, non-empty value is
		// load-bearing here, not incidental.
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertSame(
			[ 'RU' ],
			$service->get_customer_chain_calls,
			'build_scope() must thread its own normalized country into get_customer_chain() as $for_country'
		);
	}

	public function test_suggest_within_naming_a_chain_record_from_another_country_is_refused(): void {
		// Adversarial review: Location_Scope::within() takes the scope's COUNTRY FROM
		// THE PARENT — there is deliberately no $country argument there — so honouring a
		// cross-country `within` would silently move the WHOLE search to the parent's
		// country while the customer types an address in the one they selected. Refused
		// exactly like an unknown key: silent country-wide fall-through, and
		// `within_applied` reports the truth.
		$russian_settlement = $this->record( 'dadata:moscow', Location_Record::LEVEL_SETTLEMENT );
		$uzbek_address      = Location_Record::from_array(
			[
				'key'         => 'dadata:tashkent-addr',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_ADDRESS,
				'country'     => 'UZ',
			]
		);
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service(
			true,
			$provider,
			[ 'record' => $uzbek_address, 'implicit' => false, 'saved_at' => 0 ],
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			[
				Location_Record::LEVEL_SETTLEMENT => $russian_settlement,
				Location_Record::LEVEL_ADDRESS    => $uzbek_address,
			]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Юнус', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'UZ', 'within' => 'dadata:moscow' ]
		);
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotNull( $captured );
		$this->assertFalse( $captured->has_parent(), 'a parent from another country must not constrain the search' );
		$this->assertSame( 'UZ', $captured->country(), 'and the search must stay in the country the customer asked for' );
		$this->assertFalse( $result['within_applied'] );
		$this->assertSame( 'cross_country', $result['within_status'], '#333: a cross-country parent is named explicitly, not merely swallowed' );
	}

	public function test_suggest_within_still_resolves_when_the_requested_country_is_lower_case(): void {
		// The country guard above compares against a param that is cleaned but NOT
		// upper-cased, while a record's own country() always is — comparing them raw
		// would silently drop a perfectly good parent for any client sending `ru`.
		$settlement = $this->record( 'dadata:settlement-1', Location_Record::LEVEL_SETTLEMENT );
		$captured   = null;
		$provider   = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service(
			true,
			$provider,
			[ 'record' => $settlement, 'implicit' => false, 'saved_at' => 0 ],
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			[ Location_Record::LEVEL_SETTLEMENT => $settlement ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Твер', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'ru', 'within' => 'dadata:settlement-1' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent(), 'a lower-case country param must not defeat the cross-country guard' );
	}

	// -------------------------------------------------------------------
	// /suggest — issue #330's third point: `within_applied` makes a `within`
	// that failed to resolve VISIBLE, instead of indistinguishable from a
	// genuine country-wide search.
	// -------------------------------------------------------------------

	public function test_suggest_within_applied_is_true_when_within_resolves(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertTrue( $result['within_applied'] );
		$this->assertSame( 'applied', $result['within_status'] );
	}

	/**
	 * #358: `scope_narrowing` is the missing second half of `within_status` —
	 * what the PROVIDER did with the parent constraint, independent of whether
	 * the controller resolved one. Proves the controller stamps the scope via
	 * `for_provider()` BEFORE calling `suggest()`: without that stamp,
	 * `report_narrowing()` would refuse the call and this would read
	 * `unreported` instead.
	 */
	public function test_suggest_response_carries_the_providers_reported_scope_narrowing(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) {
				$scope->report_narrowing( Location_Provider::NARROWING_DEGRADED );

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertSame( Location_Provider::NARROWING_DEGRADED, $result['scope_narrowing'] );
	}

	/**
	 * A provider that never calls `report_narrowing()` at all (predates the
	 * contract, or is a third-party extension unaware of it) must read as
	 * `unreported` — the framework never claims a verdict on the provider's
	 * behalf.
	 */
	public function test_suggest_response_scope_narrowing_defaults_to_unreported(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertSame( Location_Provider::NARROWING_UNREPORTED, $result['scope_narrowing'] );
	}

	public function test_suggest_within_applied_is_false_when_an_unknown_within_falls_through(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:some-stale-key' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertFalse( $result['within_applied'] );
		$this->assertSame( 'unknown_key', $result['within_status'] );
	}

	/**
	 * Documents the chosen semantics for the case the brief left open: no
	 * `within` at all is `false`, the SAME value as a failed resolution — this
	 * field answers "is the response scoped to a parent", not "did the
	 * client's within get honored" (see build_scope()'s own docblock).
	 */
	public function test_suggest_within_applied_is_false_when_no_within_was_requested_at_all(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertFalse( $result['within_applied'] );
		$this->assertSame( 'not_requested', $result['within_status'] );
	}

	public function test_suggest_within_applied_is_false_when_no_provider_serves_the_level(): void {
		$service = new Location_Controller_Fake_Service( true, null ); // no provider for this level
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU', 'within' => 'dadata:x' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertFalse( $result['within_applied'] );
		// The client DID send a `within` here, and this branch never builds a
		// scope at all (see perform_suggest()'s own comment on this exact
		// branch) — but `within_status` must NOT claim `not_requested`
		// (adversarial review finding, s78 — FIX 4): that constant's own
		// docblock promises "no `within` param was sent at all", which is
		// false here. `unserved_level` is the honest answer: a `within` was
		// sent, but there was nothing to resolve it against.
		$this->assertSame( 'unserved_level', $result['within_status'] );
	}

	/**
	 * The mirror of the test above (adversarial review finding, s78 — FIX 4):
	 * the SAME no-provider branch, but the client sent NO `within` at all —
	 * `within_status` must still answer `not_requested` here, exactly as
	 * before this fix, since that value's own promise ("no `within` param
	 * was sent at all") is actually true on THIS branch.
	 */
	public function test_suggest_within_status_is_not_requested_when_no_provider_serves_the_level_and_no_within_was_sent(): void {
		$service = new Location_Controller_Fake_Service( true, null ); // no provider for this level
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertFalse( $result['within_applied'] );
		$this->assertSame( 'not_requested', $result['within_status'] );
	}

	public function test_suggest_within_applied_reflects_the_scope_in_the_unsupported_country_branch(): void {
		// The parent is in the SAME country the request asks for (`US`) — this test is
		// about the unsupported-country degradation, and pairing a US request with a RU
		// parent would instead exercise the cross-country refusal above, which is a
		// different rule and would make this test pass or fail for the wrong reason.
		$parent   = Location_Record::from_array(
			[
				'key'         => 'dadata:region-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => 'US',
			]
		);
		$provider = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ] );
		// country_supported=false forces the 200+empty degradation AFTER build_scope() ran.
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ], true, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'US', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertSame( [], $result['suggestions'] );
		$this->assertTrue( $result['within_applied'], 'the scope was still built (and had a parent) before the unsupported-country check ran' );
		$this->assertSame( 'applied', $result['within_status'] );
		// #358: the provider is NEVER called on this branch (see the comment above)
		// — has_parent() is true, but no report was ever made, so this must read
		// unreported, not none/exact/degraded.
		$this->assertSame( Location_Provider::NARROWING_UNREPORTED, $result['scope_narrowing'] );
	}

	// -------------------------------------------------------------------
	// /suggest — no token/secret leak (D4): the controller only ever touches
	// a provider's suggest() RETURN VALUE (Location_Record instances), never
	// its credentials/settings — proven by a fake carrying a "secret" the
	// response must never contain.
	// -------------------------------------------------------------------

	public function test_suggest_response_never_leaks_provider_credentials(): void {
		$secret_holder = new class( 'SECRET-TOKEN-XYZ' ) extends Abstract_Location_Provider {
			private string $token;

			public function __construct( string $token ) {
				$this->token = $token;
			}

			public function get_id(): string {
				return 'fake';
			}

			public function get_name(): string {
				return 'Fake';
			}

			public function get_countries(): array {
				return [ 'RU' ];
			}

			protected function declare_suggest_levels(): array {
				return Location_Record::LEVELS;
			}

			public function suggest( string $query, Location_Scope $scope ): array {
				// The token/secret is NEVER placed into a returned record — this is
				// the real DaData provider's own contract too (its `raw` carries only
				// DaData's own response `data` fields, never the request credentials).
				return [
					Location_Record::from_array(
						[
							'key'         => 'dadata:fias-1',
							'provider_id' => 'dadata',
							'level'       => Location_Record::LEVEL_SETTLEMENT,
							'country'     => 'RU',
							'label'       => 'Москва',
							'raw'         => [ 'value' => 'Москва', 'fias_id' => 'fias-1' ],
						]
					),
				];
			}
		};

		$service = new Location_Controller_Fake_Service( true, $secret_holder );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$json = (string) json_encode( $result );

		$this->assertStringNotContainsString( 'SECRET-TOKEN-XYZ', $json );
	}

	// -------------------------------------------------------------------
	// /select — malformed record: 400, nothing written
	// -------------------------------------------------------------------

	public function test_select_malformed_record_returns_400_and_writes_nothing(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => [ 'level' => 'settlement', 'country' => 'RU' ] ] ); // no key/provider_id
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	public function test_select_non_array_record_returns_400(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => 'not-an-array' ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — layer inactive: 404
	// -------------------------------------------------------------------

	public function test_select_inactive_layer_returns_404(): void {
		$service = new Location_Controller_Fake_Service( false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — happy path: stored EXPLICIT, response coherent with the D8
	// client flow (client needs to know it can now fire update_checkout)
	// -------------------------------------------------------------------

	public function test_select_happy_path_stores_explicit_and_returns_current(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$record  = $this->record();
		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $service->set_calls );
		[ $stored_record, $implicit ] = $service->set_calls[0];
		$this->assertSame( $record->key(), $stored_record->key() );
		$this->assertFalse( $implicit, 'a customer selection through /select must be stored EXPLICIT' );

		$this->assertSame( $record->key(), $result['current']['key'] );
		$this->assertSame( $record->level(), $result['current']['level'] );
		$this->assertTrue( $result['persisted'] );
	}

	public function test_select_round_trips_a_record_suggest_itself_returned(): void {
		// The exact shape /suggest hands back as `suggestions[].record` must be
		// accepted verbatim by /select — the two endpoints must not disagree.
		$suggested_record = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-7',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				'postcode'    => '101000',
				'lat'         => 55.75,
				'lon'         => 37.61,
				'label'       => 'г Москва',
				'raw'         => [ 'city_kladr_id' => '7700000000000' ],
			]
		);

		$suggest_provider = new Location_Controller_Fake_Provider( static fn() => [ $suggested_record ] );
		$suggest_service  = new Location_Controller_Fake_Service( true, $suggest_provider );
		$suggest_ctrl     = new Location_Controller_Probe( $suggest_service );

		$suggest_request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$suggest_result  = $suggest_ctrl->handle_suggest_request( $suggest_request );
		$posted_record   = $suggest_result['suggestions'][0]['record'];

		$select_service = new Location_Controller_Fake_Service( true );
		$select_ctrl    = new Location_Controller_Probe( $select_service );

		$select_request = new WP_REST_Request( [ 'record' => $posted_record ] );
		$select_result  = $select_ctrl->handle_select_request( $select_request );

		$this->assertNotInstanceOf( \WP_Error::class, $select_result );
		$this->assertSame( 'dadata:fias-7', $select_result['current']['key'] );
		$this->assertCount( 1, $select_service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — issue #330: response `chain` contains every level in the
	// customer's chain AFTER this write, read straight from
	// Location_Service::get_customer_chain() (never re-derived from `$record`
	// alone) — so the client can adopt the server's own rebuilt chain and
	// never keep sending a `within` the server will not resolve.
	// -------------------------------------------------------------------

	public function test_select_response_chain_contains_every_level_after_the_write(): void {
		$region     = $this->region_record( 'dadata:region-1' );
		$settlement = $this->record( 'dadata:settlement-1', Location_Record::LEVEL_SETTLEMENT );

		// The chain already holds region+settlement from earlier cascade steps —
		// this write is the ADDRESS step.
		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null,
			[
				Location_Record::LEVEL_REGION     => $region,
				Location_Record::LEVEL_SETTLEMENT => $settlement,
			]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$address = Location_Record::from_array(
			[
				'key'         => 'dadata:address-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_ADDRESS,
				'country'     => 'RU',
				'label'       => 'ул. Тверская, 1',
			]
		);

		$request = new WP_REST_Request( [ 'record' => $address->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[ 'key' => 'dadata:region-1', 'level' => Location_Record::LEVEL_REGION ],
			$result['chain'][ Location_Record::LEVEL_REGION ]
		);
		$this->assertSame(
			[ 'key' => 'dadata:settlement-1', 'level' => Location_Record::LEVEL_SETTLEMENT ],
			$result['chain'][ Location_Record::LEVEL_SETTLEMENT ]
		);
		$this->assertSame(
			[ 'key' => 'dadata:address-1', 'level' => Location_Record::LEVEL_ADDRESS ],
			$result['chain'][ Location_Record::LEVEL_ADDRESS ]
		);
	}

	/**
	 * Issue #502, s91 critic finding MAJOR-1. `/select` used to answer with a
	 * `chain` and no word about its provenance, and the client therefore had to
	 * guess — it guessed "a select response is a customer's own pick, so this is
	 * explicit". That guess is wrong on two shapes, because the chain is read from
	 * `Location_Service::get_customer_chain()`, which is the LAZY TRIGGER for the
	 * store-level default-locality policy rather than an echo of what was just
	 * written. Publishing the flag is what lets the client tell the merchant's
	 * default guess from the customer's answer, which spec §4.6/D11 requires:
	 * "Implicit records participate in rate calculation but never suppress 'please
	 * choose your locality' prompts."
	 */
	public function test_select_response_publishes_the_chains_implicit_flag(): void {
		// `persist_result = false` is not incidental — it is one of the exact two
		// shapes where the flag survives to reach the client. A guest whose
		// session/cart cookie has not initialized cannot be written to (gotcha
		// `guest-session-write-needs-the-cart-cookie`), so the store keeps answering
		// with the merchant's default and this route reports it unchanged.
		$service = new Location_Controller_Fake_Service(
			true,
			null,
			[ 'record' => $this->record(), 'implicit' => true, 'saved_at' => 0 ],
			false
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertArrayHasKey( 'implicit', $result, 'Without this key the client cannot tell the store default from a real pick.' );
		$this->assertTrue( $result['implicit'] );
	}

	public function test_select_response_reports_an_explicit_chain_as_explicit(): void {
		// The control: same call, same shape, same failed write, only the stored
		// flag differs.
		$service = new Location_Controller_Fake_Service(
			true,
			null,
			[ 'record' => $this->record(), 'implicit' => false, 'saved_at' => 0 ],
			false
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $result['implicit'] );
	}

	public function test_a_successful_write_reports_the_chain_as_explicit_even_over_an_implicit_one(): void {
		// The store's own precedence rule (spec D11: "A real customer selection
		// overwrites it and drops the flag") seen from the outside: the customer had
		// only the merchant's default, picked for real, and the response must now
		// say explicit — otherwise the address field would stay locked after the very
		// pick that was supposed to free it.
		$service = new Location_Controller_Fake_Service(
			true,
			null,
			[ 'record' => $this->record(), 'implicit' => true, 'saved_at' => 0 ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['persisted'] );
		$this->assertFalse( $result['implicit'] );
	}

	public function test_select_response_implicit_is_false_when_there_is_no_chain_at_all(): void {
		// No stored record: the client must read "nothing implicit here", not a
		// missing key it would have to interpret.
		$service = new Location_Controller_Fake_Service( true, null, null, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [], $result['chain'] );
		$this->assertFalse( $result['implicit'] );
	}

	public function test_select_response_chain_is_empty_array_when_the_write_did_not_persist(): void {
		// persist_result = false, and no pre-existing chain — mirrors a guest
		// whose session/cart cookie has not initialized yet (issue #324).
		$service = new Location_Controller_Fake_Service( true, null, null, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $result['persisted'] );
		$this->assertSame( [], $result['chain'] );
	}

	// -------------------------------------------------------------------
	// /select — #488 slice 3, D5/D6/D7: lazy verification of a popular-list
	// pick. "Not found" is already proven by every /select test above this
	// point (the default fake popular store always misses) — this section
	// covers "found and fresh" plus every D6 outcome for "found and stale".
	// -------------------------------------------------------------------

	private function settlement_record( string $key, string $settlement_name = 'Москва', string $region_name = 'Московская область', string $label = '' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $key,
				'provider_id' => explode( ':', $key )[0],
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'region'      => [ 'name' => $region_name, 'type' => 'обл' ],
				'settlement'  => [ 'name' => $settlement_name, 'type' => 'г' ],
				'label'       => $label,
			]
		);
	}

	public function test_select_a_fresh_popular_entry_is_left_completely_untouched(): void {
		$record = $this->record( 'dadata:fias-1' );
		$entry  = new Popular_Settlement_Entry( 1, 'dadata', 'RU', $record, 5, time(), time(), time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, false ); // not stale
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', $record );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'cancelled', $result );
		$this->assertSame( $record->key(), $result['current']['key'] );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame( $record->key(), $service->set_calls[0][0]->key() );
		$this->assertCount( 0, $popular_store->touch_verified_calls, 'A fresh entry must never even call the provider.' );
		$this->assertCount( 0, $popular_store->replace_record_calls );
		$this->assertCount( 0, $popular_store->delete_entry_calls );
	}

	public function test_select_unchanged_verification_bumps_the_clock_and_persists_the_posted_record(): void {
		$record = $this->record( 'dadata:fias-1' );
		$entry  = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $record, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		// Same record back — spec D6 "alive, unchanged".
		$provider = new Location_Controller_Popular_Provider_Fixture( 'dadata', $record );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $record->key(), $result['current']['key'] );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame( $record->key(), $service->set_calls[0][0]->key(), 'D5 table: unchanged persists the CUSTOMER\'s record, as today.' );
		$this->assertSame( [ 7 ], $popular_store->touch_verified_calls );
		$this->assertCount( 0, $popular_store->replace_record_calls );
		$this->assertCount( 0, $popular_store->delete_entry_calls );
	}

	public function test_select_updated_verification_persists_the_providers_fresh_record_not_the_posted_one(): void {
		$posted = $this->record( 'dadata:fias-1' );
		$fresh  = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва (переименовано)',
			]
		);
		$entry = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $posted, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', $fresh );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $posted->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame( 'Москва (переименовано)', $service->set_calls[0][0]->label(), 'D5 table: updated persists the PROVIDER\'s fresh record.' );
		$this->assertCount( 1, $popular_store->replace_record_calls );
		$this->assertCount( 0, $popular_store->touch_verified_calls );
		$this->assertCount( 0, $popular_store->delete_entry_calls );
	}

	public function test_select_failed_verification_never_blocks_the_purchase_and_is_logged(): void {
		$record    = $this->record( 'dadata:fias-1' );
		$entry     = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $record, 5, time(), null, time() );
		$exception = new \RuntimeException( 'DaData resolve_key request failed.' );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', $exception );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$logged = [];
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$logged ) {
				$logged[] = $args;
			}
		);

		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame(
			$record->key(),
			$service->set_calls[0][0]->key(),
			'D5 table: failed persists the customer\'s record, as today — a provider outage must never block a purchase.'
		);
		$this->assertCount( 0, $popular_store->touch_verified_calls );
		$this->assertCount( 0, $popular_store->replace_record_calls );
		$this->assertCount( 0, $popular_store->delete_entry_calls, '"failed" is not "gone".' );
		$this->assertCount( 1, $logged, 'The provider failure must be logged via log_failure().' );
		$this->assertSame( 'woodev_location_provider_operation_failed', $logged[0][0] );
		$this->assertSame( $exception, $logged[0][3] );
	}

	public function test_select_a_stale_entry_with_no_registered_provider_is_treated_like_failed(): void {
		$record = $this->record( 'dadata:fias-1' );
		$entry  = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $record, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, null // no provider registered for this id any more
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame( $record->key(), $service->set_calls[0][0]->key() );
		$this->assertCount( 0, $popular_store->delete_entry_calls );
	}

	public function test_select_gone_verification_silently_adopts_an_unambiguous_search_match(): void {
		$stored    = $this->settlement_record( 'dadata:fias-1' );
		$candidate = $this->settlement_record( 'dadata:fias-2', ' МОСКВА ', ' московская область ' );

		$entry = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $stored, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		// resolve_key() => null ("gone"); suggest() => exactly one exact match.
		$provider = new Location_Controller_Popular_Provider_Fixture( 'dadata', null, [ $candidate ] );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $stored->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'cancelled', $result );
		$this->assertSame( $candidate->key(), $result['current']['key'], 'D7: current must carry the ADOPTED key.' );
		$this->assertCount( 1, $service->set_calls );
		$this->assertSame( $candidate->key(), $service->set_calls[0][0]->key() );
		$this->assertSame( [ 7 ], $popular_store->delete_entry_calls, 'D6: the row is deleted before D7 runs its own search.' );
	}

	public function test_select_gone_verification_cancels_the_pick_on_zero_matches(): void {
		$stored = $this->settlement_record( 'dadata:fias-1' );
		$entry  = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $stored, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', null, [] );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $stored->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( 'stale_record', $result['reason'] );
		$this->assertNotSame( '', $result['message'], 'D7: the message is not optional.' );
		$this->assertNull( $result['current'] );
		$this->assertFalse( $result['persisted'] );
		$this->assertSame( [], $result['chain'] );
		// Issue #502: the cancel shape writes NOTHING before reading the chain, so it
		// is the sharper of the two paths that can hand the client the merchant's
		// default. The key must be present on this shape too, not only the ordinary one.
		$this->assertArrayHasKey( 'implicit', $result );
		$this->assertFalse( $result['implicit'] );
		$this->assertCount( 0, $service->set_calls, 'Nothing may be written to the customer store on cancel.' );
	}

	public function test_select_gone_verification_cancels_the_pick_on_two_ambiguous_matches(): void {
		$stored      = $this->settlement_record( 'dadata:fias-1' );
		$candidate_a = $this->settlement_record( 'dadata:fias-2' );
		$candidate_b = $this->settlement_record( 'dadata:fias-3' );

		$entry = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $stored, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', null, [ $candidate_a, $candidate_b ] );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $stored->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertTrue( $result['cancelled'], 'Two indistinguishable candidates must never be substituted (gotcha: a locality display name is not an identifier).' );
		$this->assertCount( 0, $service->set_calls );
	}

	public function test_select_gone_verification_cancels_the_pick_when_the_replacement_search_throws(): void {
		$stored = $this->settlement_record( 'dadata:fias-1' );
		$entry  = new Popular_Settlement_Entry( 7, 'dadata', 'RU', $stored, 5, time(), null, time() );

		$popular_store = new Location_Controller_Fake_Popular_Store( $entry, true );
		$provider      = new Location_Controller_Popular_Provider_Fixture( 'dadata', null, new \RuntimeException( 'suggest boom' ) );

		$service = new Location_Controller_Fake_Service(
			true, null, null, true, true, null, null, null, false, null, null, [], [],
			$popular_store, $provider
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $stored->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'A suggest() failure during D7 must degrade to cancel, never a 500.' );
		$this->assertTrue( $result['cancelled'] );
		$this->assertCount( 0, $service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — nonce permission gate (mirrors Pickup_Controller precedent)
	// -------------------------------------------------------------------

	public function test_check_select_permission_rejects_a_missing_nonce(): void {
		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_select_permission_rejects_an_invalid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'bad-nonce' ] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_check_select_permission_accepts_a_valid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'good-nonce' ] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------
	// /list (Task 13; spec D7) — level enum, malformed country 400, no
	// provider -> 404 (NOT /suggest's 200+empty), happy path, provider
	// exception -> 502, `provider` param never read.
	// -------------------------------------------------------------------

	public function test_list_rejects_an_unknown_level(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_list_a_malformed_country_returns_400(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'not-a-code' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->provider_for_list_calls, 'a malformed country must never even reach provider_for_list()' );
	}

	/**
	 * The deliberate asymmetry with `/suggest`: no provider anywhere in the
	 * D15-adjacent `list` chain resolves for this (well-formed) country ->
	 * 404, never `/suggest`'s 200+empty (see handle_list_request()'s own
	 * docblock for why).
	 */
	public function test_list_no_provider_resolves_returns_404_not_200_empty(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null ); // list_provider = null
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_list_happy_path_returns_shaped_localities(): void {
		$record = Location_Record::from_array(
			[
				'key'         => 'fake-list:mo',
				'provider_id' => 'fake-list',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => '<b>Москва</b>',
			]
		);
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['localities'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 'not_requested', $result['within_status'], '#333: /list carries within_status too, not only /suggest' );

		$locality = $result['localities'][0];
		$this->assertSame( 'fake-list:mo', $locality['key'] );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $locality['level'] );
		$this->assertStringContainsString( '&lt;b&gt;', $locality['label'], 'label must be escaped, same as /suggest' );

		// The record must round-trip UNTOUCHED, same D12/D5 contract as /suggest.
		$round_tripped = Location_Record::from_array( $locality['record'] );
		$this->assertSame( $record->key(), $round_tripped->key() );

		$this->assertCount( 1, $provider->list_calls );
	}

	// -------------------------------------------------------------------
	// /list — PR #304 review finding 5: an unbounded enumeration is capped,
	// an optional `limit` narrows it further (clamped), and the response is
	// honest about truncation rather than silently cutting.
	// -------------------------------------------------------------------

	/**
	 * @param int $count how many fake records the fixture provider returns.
	 *
	 * @return Location_Record[]
	 */
	private function many_records( int $count ): array {
		$records = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$records[] = Location_Record::from_array(
				[
					'key'         => 'fake-list:' . $i,
					'provider_id' => 'fake-list',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'label'       => 'City ' . $i,
				]
			);
		}

		return $records;
	}

	public function test_list_caps_the_response_at_the_hard_limit_and_reports_truncation(): void {
		// One MORE than the hard cap — the exact neighbouring value a mutant
		// removing/loosening the cap would fail against.
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 501 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 500, $result['localities'], 'the response must never exceed LIST_HARD_CAP' );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_list_exactly_at_the_hard_cap_is_not_reported_truncated(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 500 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 500, $result['localities'] );
		$this->assertFalse( $result['truncated'], 'the provider handed back exactly the cap, nothing was actually cut' );
	}

	public function test_list_limit_arg_narrows_the_response_below_the_hard_cap(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 20 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => 5 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 5, $result['localities'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * A client cannot use `limit` to ask for MORE than the hard cap — the
	 * mutant this pins: `min( $limit, self::LIST_HARD_CAP )` reverted to a
	 * bare `$limit` would let this request through at 100000 records.
	 */
	public function test_list_limit_arg_above_the_hard_cap_is_clamped_to_it(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 501 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => 100000 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 500, $result['localities'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_list_limit_arg_zero_or_negative_falls_back_to_the_hard_cap(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 10 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => -5 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 10, $result['localities'], 'a non-positive limit is not a valid narrowing — falls back to the hard cap' );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * PR #304 review's own test-gap finding: nothing previously pinned
	 * `LIST_RATE_LIMIT_MAX` itself, or even proved the limiter is wired into
	 * `/list` at all — every other `/list` test in this file goes through
	 * {@see Location_Controller_Probe}, which BYPASSES the limiter entirely.
	 * This test uses the REAL {@see Location_Controller} (no probe) with the
	 * real rate-limit storage stubbed (mirrors `RestRateLimitTraitTest`'s own
	 * fixture setup), so it fails both against a mutant that unhooks the
	 * limiter from `/list` and against a mutant that changes the budget away
	 * from 60 (the 61st call is the neighbouring value that pins the exact
	 * number, not merely "some limit exists").
	 */
	public function test_list_rate_limit_is_pinned_at_the_real_budget(): void {
		$store = [];

		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller( $service ); // the REAL controller — rate limiting genuinely runs.

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );

		for ( $i = 0; $i < 60; $i++ ) {
			$result = $ctrl->handle_list_request( $request );
			$this->assertNotInstanceOf( \WP_Error::class, $result, "request {$i} (1-based " . ( $i + 1 ) . ') must still be within the 60/min budget' );
		}

		$result = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, 'the 61st request must be the one that trips the limiter' );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_list_never_reads_a_client_supplied_provider_param(): void {
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'cdek' ]
		);
		$ctrl->handle_list_request( $request );

		$this->assertCount( 1, $service->provider_for_list_calls );
	}

	public function test_list_provider_exception_returns_502(): void {
		$provider = new Location_Controller_Fake_List_Provider(
			static function () {
				throw new \RuntimeException( 'upstream boom' );
			}
		);
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_list_within_matching_current_record_narrows_the_scope(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_List_Provider(
			static function ( Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, null, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ], true, true, null, null, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_list_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent() );
		$this->assertSame( $parent, $captured->parent_record() );
		$this->assertSame( 'applied', $result['within_status'] );
	}

	/**
	 * #358: `/list` never had a `within_applied` boolean, so `scope_narrowing` is
	 * its ONLY signal for what the provider did with the parent — proving the
	 * controller stamps the scope via `for_provider()` BEFORE calling
	 * `list_localities()` (otherwise `report_narrowing()` would refuse and this
	 * would read `unreported`).
	 */
	public function test_list_response_carries_the_providers_reported_scope_narrowing(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$provider = new Location_Controller_Fake_List_Provider(
			static function ( Location_Scope $scope ) {
				$scope->report_narrowing( Location_Provider::NARROWING_EXACT );

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, null, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ], true, true, null, null, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_list_request( $request );

		$this->assertSame( Location_Provider::NARROWING_EXACT, $result['scope_narrowing'] );
	}

	public function test_list_response_scope_narrowing_is_not_applicable_without_a_within_param(): void {
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertSame( Location_Provider::NARROWING_NOT_APPLICABLE, $result['scope_narrowing'] );
	}

	public function test_list_within_status_is_unknown_key_when_within_matches_nothing(): void {
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:some-stale-key' ]
		);
		$result = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unknown_key', $result['within_status'] );
	}

	public function test_list_within_status_is_cross_country_when_the_parent_is_in_another_country(): void {
		$region_ru = $this->region_record( 'dadata:region-1' );
		$provider  = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service   = new Location_Controller_Fake_Service( true, null, [ 'record' => $region_ru, 'implicit' => false, 'saved_at' => 0 ], true, true, null, null, $provider );
		$ctrl      = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'UZ', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cross_country', $result['within_status'] );
	}

	// -------------------------------------------------------------------
	// check_admin_permission() (Task 14) — capability gate for the two
	// admin-only /default-locality/* routes.
	// -------------------------------------------------------------------

	public function test_check_admin_permission_rejects_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$result = $ctrl->check_admin_permission( new WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_check_admin_permission_accepts_with_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$result = $ctrl->check_admin_permission( new WP_REST_Request() );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------
	// /default-locality/suggest (Task 14) — the admin picker's own search;
	// shares perform_suggest() with the public /suggest route, so this only
	// spot-checks the shared behaviour still holds through the admin entry
	// point rather than re-proving every case LocationControllerTest already
	// covers for handle_suggest_request() above.
	// -------------------------------------------------------------------

	public function test_admin_suggest_rejects_an_unknown_level(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_admin_suggest_happy_path_returns_shaped_suggestions(): void {
		$record   = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
		$provider = new Location_Controller_Fake_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame( 'dadata:fias-1', $result['suggestions'][0]['key'] );
	}

	public function test_admin_suggest_no_provider_for_level_returns_empty_200(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
	}

	// -------------------------------------------------------------------
	// Admin `provider` override (issue #380, closes #375's residual gap: the
	// picker used to keep asking whichever provider was STORED, ignoring the
	// merchant's own live, unsaved select change).
	// -------------------------------------------------------------------

	public function test_admin_suggest_override_provider_is_honoured_instead_of_the_d15_chain(): void {
		$record          = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
		$chain_provider  = new Location_Controller_Fake_Provider( static fn() => [] );
		$override_provider = new Location_Controller_Fake_Provider( static fn() => [ $record ] );

		// $chain_provider is what the D15 chain would resolve — proves the
		// override BYPASSES it entirely rather than merely running first.
		$service = new Location_Controller_Fake_Service(
			true,
			$chain_provider,
			null,
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			null,
			[ 'dadata' ],
			[ 'dadata' => $override_provider ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'dadata' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame( 'dadata:fias-1', $result['suggestions'][0]['key'] );

		$this->assertCount( 1, $override_provider->suggest_calls, 'the override must be the one actually queried' );
		$this->assertCount( 0, $chain_provider->suggest_calls, 'the D15-chain-resolved provider must never be queried once an override is given' );
		$this->assertSame( [], $service->provider_for_level_calls, 'the chain must never even be walked when an override is given' );
		$this->assertSame( [ 'dadata' ], $service->has_provider_calls );
		$this->assertSame( [ [ 'dadata', Location_Record::LEVEL_SETTLEMENT, null ] ], $service->provider_by_id_calls );
	}

	public function test_admin_suggest_unknown_override_provider_returns_400_never_falling_back_to_the_stored_provider(): void {
		$chain_provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service        = new Location_Controller_Fake_Service( true, $chain_provider );
		$ctrl           = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'not-a-real-provider' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'woodev_location_unknown_provider', $result->get_error_code() );
		$this->assertCount( 0, $chain_provider->suggest_calls, 'an unknown override must never silently fall back to the stored/chain provider' );
		$this->assertSame( [], $service->provider_by_id_calls, 'a registration check that already failed must never proceed to resolve eligibility' );
	}

	/**
	 * Issue #650: a request that gets BOTH the override provider id AND the
	 * country wrong must still 400 as "unknown provider", not "invalid
	 * country" — the registry-membership check runs before country is even
	 * read (this method's own `@since` note on {@see Location_Controller::perform_suggest()}
	 * records this precedence as deliberate: the override is an explicit,
	 * admin-only instruction, and "that provider id does not exist" is the
	 * more actionable message when both inputs are wrong).
	 */
	public function test_admin_suggest_unknown_provider_and_malformed_country_returns_the_unknown_provider_400(): void {
		$chain_provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service        = new Location_Controller_Fake_Service( true, $chain_provider );
		$ctrl           = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'not-a-code', 'provider' => 'not-a-real-provider' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_location_unknown_provider', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_admin_suggest_a_registered_but_ineligible_override_degrades_like_no_provider_for_the_level(): void {
		// Registered (has_provider() -> true) but provider_by_id() itself
		// answers null — simulates "unconfigured" or "does not serve this
		// level" (Location_Service::provider_by_id()'s own documented
		// degradation), which must NOT be a 400 — only an UNKNOWN id is.
		$service = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			null,
			[ 'dadata' ],
			[]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'dadata' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			[
				'suggestions'     => [],
				'within_applied'  => false,
				'within_status'   => 'not_requested',
				'scope_narrowing' => Location_Provider::NARROWING_NOT_APPLICABLE,
			],
			$result
		);
	}

	public function test_admin_suggest_override_provider_not_covering_the_requested_country_returns_empty_200_without_calling_it(): void {
		// Eligible for the LEVEL (level-blind check), but its OWN country list
		// does not cover the request's country — must degrade exactly like the
		// D15-chain's own "unsupported country" branch, never reach suggest().
		$override_provider = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ], [ 'RU' ] );
		$service            = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			null,
			[ 'dadata' ],
			[ 'dadata' => $override_provider ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'US', 'provider' => 'dadata' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [], $result['suggestions'] );
		$this->assertCount( 0, $override_provider->suggest_calls, 'an unsupported country must never reach the override provider either' );
	}

	/**
	 * Issue #650 (settled decision, pinned): `provider_by_id()` stays
	 * country-blind. An override that serves the level but not the
	 * requested country is refused by `provider_serves_level()` AFTER
	 * `$scope` (built from the real country) already exists, so the
	 * response's `within_status` must report the REAL resolved scope —
	 * never `unserved_level`, the coarser value the `null === $provider`
	 * branch would force if resolution were made country-aware instead.
	 */
	public function test_admin_suggest_override_not_covering_the_country_reports_the_real_within_status(): void {
		$override_provider = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ], [ 'RU' ] );
		$service            = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			null,
			null,
			null,
			false,
			null,
			null,
			[ 'dadata' ],
			[ 'dadata' => $override_provider ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'US', 'provider' => 'dadata' ]
		);
		$result = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [], $result['suggestions'] );
		$this->assertSame(
			[ [ 'dadata', Location_Record::LEVEL_SETTLEMENT, null ] ],
			$service->provider_by_id_calls,
			'provider_by_id() must stay country-blind — the country mismatch is caught later, via provider_serves_level()'
		);
		$this->assertSame(
			'not_requested',
			$result['within_status'],
			'the real resolved scope must be reported, never the coarser unserved_level'
		);
	}

	public function test_admin_suggest_without_a_provider_param_never_touches_the_override_seam(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$ctrl->handle_admin_suggest_request( $request );

		$this->assertSame( [], $service->has_provider_calls, 'an absent provider param must resolve through the ordinary D15 chain untouched' );
		$this->assertSame( [], $service->provider_by_id_calls );
		$this->assertCount( 1, $service->provider_for_level_calls );
	}

	public function test_suggest_public_route_never_touches_the_override_seam_even_with_a_provider_param(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		// D4: a shopper naming a provider must never even reach the
		// registration check — the public route structurally never reads
		// this param at all (self::handle_suggest_request() never extracts
		// it), unlike the admin route above.
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'dadata' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertSame( [], $service->has_provider_calls );
		$this->assertSame( [], $service->provider_by_id_calls );
	}

	// -------------------------------------------------------------------
	// /default-locality/locate (Task 14) — the admin picker's geo-IP preview.
	// -------------------------------------------------------------------

	public function test_admin_locate_returns_404_when_the_active_provider_has_no_locate_capability(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->locate_calls, 'locate() must never even be called once the capability check fails' );
	}

	public function test_admin_locate_happy_path_returns_shaped_location(): void {
		$record  = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $record );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ '203.0.113.9' ], $service->locate_calls, 'the EXPLICIT ip param must be used verbatim, not overridden by the request IP' );
		$this->assertSame( 'dadata:fias-1', $result['location']['key'] );
	}

	public function test_admin_locate_returns_null_location_as_200_not_an_error(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'location' => null ], $result );
	}

	public function test_admin_locate_falls_back_to_the_request_ip_when_no_ip_param_is_given(): void {
		$record  = $this->record();
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $record );
		$ctrl    = new Location_Controller_Probe( $service );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$request = new WP_REST_Request( [] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ '198.51.100.7' ], $service->locate_calls );
	}

	/**
	 * `get_client_ip()` (the rate-limit trait's own helper) deliberately falls
	 * back to the literal string `'unknown'` rather than `''` — this route
	 * must NOT reuse it for this reason: handing `'unknown'` to a provider's
	 * `locate()` as though it were a real IP would be worse than refusing.
	 */
	public function test_admin_locate_returns_400_when_no_ip_can_be_determined_at_all(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $this->record() );
		$ctrl    = new Location_Controller_Probe( $service );

		unset( $_SERVER['REMOTE_ADDR'] ); // WC_Geolocation stub falls back to this.

		$request = new WP_REST_Request( [] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->locate_calls );
	}

	// -------------------------------------------------------------------
	// Review finding F1(b): the WooCommerce cart/session must be BRIDGED
	// (mirrors Pickup_Controller/Pickup_Handler's own wc_load_cart() bridge)
	// on every customer-facing route that can reach
	// Location_Service::get_customer_record() — without it, a guest's
	// session never starts on a plain REST request and the lazy
	// default-locality trigger can never persist what it resolves.
	// -------------------------------------------------------------------

	public function test_suggest_bridges_the_wc_session(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Session_Bridge_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertSame( 1, $ctrl->bridge_calls, 'mutant: dropping the bridge_wc_session() call from perform_suggest()' );
	}

	public function test_admin_suggest_also_bridges_the_wc_session(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Session_Bridge_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_admin_suggest_request( $request );

		$this->assertSame( 1, $ctrl->bridge_calls, 'the admin picker shares perform_suggest() with the public route, so it bridges too' );
	}

	/**
	 * Issue #324, found by the operator on the rig as a GUEST. The bridge above was
	 * wired to every route that READS a customer record and to none that WRITES one —
	 * and `/select` is the only write. A guest's `Customer_Location_Store::set()` has
	 * nowhere to put the record but `WC()->session`, which WooCommerce does not start
	 * on a plain REST request (`class-woocommerce.php:315` excludes REST from
	 * session/cart init unless it is a Store API route), so the write returned `false`,
	 * `/select` honestly answered `persisted: false`, and `build_scope()` then silently
	 * ignored `within` — the customer chose «Жуковский» and got address suggestions
	 * from the whole country.
	 *
	 * The logged-in path hid it completely: `set()` writes user meta and returns `true`
	 * without touching the session at all, and every rig pass on this layer had been
	 * made as a logged-in admin.
	 */
	public function test_select_bridges_the_wc_session(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Session_Bridge_Probe( $service );

		$request = new WP_REST_Request(
			[
				'record' => [
					'key'         => 'dadata:fias-1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				],
			]
		);
		$ctrl->handle_select_request( $request );

		$this->assertSame( 1, $ctrl->bridge_calls, 'mutant: dropping the bridge_wc_session() call from handle_select_request()' );
	}

	/**
	 * ORDER is the whole point, not the call: bridging AFTER the write would leave the
	 * write itself with no session to land in, which is exactly the state #324 reported.
	 * The probe records how many writes had already happened when it ran.
	 */
	public function test_select_bridges_the_session_BEFORE_it_writes(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Select_Order_Probe( $service );

		$request = new WP_REST_Request(
			[
				'record' => [
					'key'         => 'dadata:fias-1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				],
			]
		);
		$ctrl->handle_select_request( $request );

		$this->assertSame( [ 'bridge@0-writes' ], $ctrl->order );
		$this->assertCount( 1, $service->set_calls, 'the write must still happen' );
	}

	public function test_list_bridges_the_wc_session(): void {
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Session_Bridge_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$ctrl->handle_list_request( $request );

		$this->assertSame( 1, $ctrl->bridge_calls, 'mutant: dropping the bridge_wc_session() call from handle_list_request()' );
	}

	// {@see Location_Controller::bridge_wc_session()}'s own internal
	// `WC()`/`wc_load_cart()` branch mirrors
	// {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_cart_weight_grams()}'s
	// already-proven bridge verbatim (same "already loaded? no-op; function
	// missing? no-op; otherwise call it" shape) — not re-tested here via a
	// real `WC()` stub, deliberately: Brain Monkey's Patchwork-based
	// `Functions\when( 'WC' )` redefinition leaks `function_exists( 'WC' )`
	// as permanently `true` for the REST of that PHPUnit process (the same
	// reason Customer_Location_Store::session()'s own docblock gives for why
	// ITS test seam is a protected accessor instead). The tests above already
	// prove every customer-facing handler that can reach
	// Location_Service::get_customer_record() calls the bridge — that is the
	// part specific to this controller.
}

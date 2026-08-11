<?php
/**
 * Tests for Pickup_Selection — the session-backed mechanism behind pickup-selection
 * persistence (SP-5 T5, issue #176): remember/recall/recall_latest/forget_all, the
 * recency sequence (an overwrite of an existing (locality, type) entry must still read
 * as the newest, never the array-order-inferred oldest — see the class docblock), the
 * `woodev_pickup_max_remembered_selections` cap (default, filterable, non-numeric and
 * negative fallbacks) and eviction (oldest-first, never the just-written entry), and the
 * "no WooCommerce session → no crash, no write" degradation.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup {

	use Brain\Monkey\Filters;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
	use Woodev\Framework\Shipping\Pickup\Selection_Scope;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';

	/**
	 * Minimal {@see Selection_Scope} test double. Only {@see self::session_key()} is
	 * ever read by {@see Pickup_Selection} itself — the other three methods exist
	 * purely to satisfy the interface and are never called by the class under test.
	 */
	final class Pickup_Selection_Test_Scope implements Selection_Scope {

		/** @var string */
		private string $key;

		public function __construct( string $key = 'woodev_test_selection_map' ) {
			$this->key = $key;
		}

		public function session_key(): string {
			return $this->key;
		}

		public function locality_for_point( Pickup_Point $point ): string {
			return $point->get_locality();
		}

		public function current_locality(): string {
			return '';
		}

		public function type_for_method( string $method_id ): ?string {
			return null;
		}
	}

	/**
	 * Minimal `\WC_Session` stand-in: an array-backed get()/set() pair, nothing else —
	 * {@see Pickup_Selection} never calls any other `WC_Session` method.
	 */
	final class Pickup_Selection_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     session key.
		 * @param mixed  $default fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   session key.
		 * @param mixed  $value value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}

		/**
		 * Reads the raw stored map directly — lets a test assert eviction/shape without
		 * going through {@see Pickup_Selection}'s own read methods (which would hide a
		 * bug in the very code being tested).
		 *
		 * @param string $key session key.
		 *
		 * @return mixed
		 */
		public function raw( string $key ) {
			return $this->store[ $key ] ?? null;
		}
	}

	/**
	 * Probe substituting a {@see Pickup_Selection_Fake_Session} (or `null`, to simulate
	 * WooCommerce/the session being unavailable) for the real `WC()->session` global —
	 * the same "override the protected seam, never mock WC() itself" discipline
	 * {@see \Woodev\Tests\Unit\Shipping\Pickup\Pickup_Handler_Probe} and its siblings in
	 * `PickupHandlerTest.php` already use, and for the identical reason: Brain Monkey's
	 * Patchwork-based `Functions\when( 'WC' )` redefinition would leak
	 * `function_exists( 'WC' ) === true` to the REST of the PHPUnit process.
	 */
	final class Pickup_Selection_Probe extends Pickup_Selection {

		/** @var Pickup_Selection_Fake_Session|null */
		private ?Pickup_Selection_Fake_Session $fake_session;

		public function __construct( Selection_Scope $scope, ?Pickup_Selection_Fake_Session $fake_session ) {
			parent::__construct( $scope );
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Selection
	 */
	final class PickupSelectionTest extends TestCase {

		private function scope( string $key = 'woodev_test_selection_map' ): Pickup_Selection_Test_Scope {
			return new Pickup_Selection_Test_Scope( $key );
		}

		private function probe( ?Pickup_Selection_Fake_Session $session ): Pickup_Selection_Probe {
			return new Pickup_Selection_Probe( $this->scope(), $session );
		}

		// -------------------------------------------------------------------------
		// remember() / recall() — the basic round trip
		// -------------------------------------------------------------------------

		public function test_remember_and_recall_round_trips(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );

			$selection->remember( 'msk', 'pvz', 'P1' );

			$this->assertSame( 'P1', $selection->recall( 'msk', 'pvz' ) );
		}

		public function test_recall_returns_null_for_an_absent_locality(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$this->assertNull( $selection->recall( 'nowhere', 'pvz' ) );
		}

		public function test_recall_returns_null_for_an_absent_type_in_a_known_locality(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$selection->remember( 'msk', 'pvz', 'P1' );

			$this->assertNull( $selection->recall( 'msk', 'postamat' ) );
		}

		// -------------------------------------------------------------------------
		// An empty key is the scope FAILING to name one, not a key. A scope returns
		// '' for a point whose locality it cannot map, and current_locality() returns
		// '' when WooCommerce cannot answer yet — if both were treated as ordinary
		// keys, every unnameable locality would collapse into one shared bucket and a
		// later unanswerable current_locality() would recall a point from a DIFFERENT
		// locality. Refused on both sides so that is unreachable, not just unlikely.
		// -------------------------------------------------------------------------

		/**
		 * Asserting the RAW stored map (not the semantic entry count) matters: a
		 * `count_entries()` of 0 is also produced by a stray `[ '' => [] ]` bucket
		 * (an empty per-locality array counts zero entries but is NOT "nothing was
		 * written") — only reading the raw session value directly rules that out.
		 */
		public function test_an_empty_locality_is_never_written(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );

			$selection->remember( '', 'pvz', 'P1' );

			$this->assertNull(
				$session->raw( 'woodev_test_selection_map' ),
				'nothing must be written at all — not even an empty bucket'
			);
		}

		public function test_an_empty_type_is_never_written(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );

			$selection->remember( 'msk', '', 'P1' );

			$this->assertNull(
				$session->raw( 'woodev_test_selection_map' ),
				'nothing must be written at all — not even an empty bucket'
			);
		}

		public function test_an_empty_point_id_is_never_written(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );

			$selection->remember( 'msk', 'pvz', '' );

			$this->assertNull(
				$session->raw( 'woodev_test_selection_map' ),
				'nothing must be written at all — not even an empty bucket'
			);
		}

		/**
		 * The load-bearing one: even with an unnameable-locality entry already in the
		 * map (written by an older framework version, or by hand), an unanswerable
		 * `current_locality()` must not recall it.
		 */
		public function test_an_empty_locality_never_recalls_a_foreign_point(): void {
			$session = new Pickup_Selection_Fake_Session();

			// Seed the map directly — the write side now refuses this shape, so the
			// only way to have it is from outside.
			$session->set(
				'woodev_test_selection_map',
				[ '' => [ 'pvz' => [ 'id' => 'FOREIGN', 'seq' => 1 ] ] ]
			);

			$selection = $this->probe( $session );

			$this->assertNull( $selection->recall( '', 'pvz' ) );
			$this->assertNull( $selection->recall_latest( '' ) );
		}

		public function test_remember_overwrites_an_existing_pair(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$selection->remember( 'msk', 'pvz', 'P1' );
			$selection->remember( 'msk', 'pvz', 'P2' );

			$this->assertSame( 'P2', $selection->recall( 'msk', 'pvz' ) );
		}

		// -------------------------------------------------------------------------
		// recall_latest() / TYPE_ANY fallback and the recency sequence (spec §6)
		// -------------------------------------------------------------------------

		public function test_recall_latest_returns_null_for_an_empty_locality(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$this->assertNull( $selection->recall_latest( 'msk' ) );
		}

		public function test_recall_latest_returns_the_most_recently_written_type(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$selection->remember( 'msk', 'pvz', 'P1' );
			$selection->remember( 'msk', 'postamat', 'P2' );

			$this->assertSame( 'P2', $selection->recall_latest( 'msk' ) );
		}

		/**
		 * THE recency regression test (spec §6's own explicit warning): a PHP array
		 * re-assignment keeps a key's ORIGINAL position, so re-writing the FIRST type
		 * written must still make it read as the newest — proving recency is tracked by
		 * an explicit `seq`, not inferred from array/insertion order. A test that only
		 * ever writes distinct keys (like the two tests above) cannot catch a
		 * regression here; this one must overwrite.
		 */
		public function test_overwriting_an_existing_entry_refreshes_its_recency(): void {
			$selection = $this->probe( new Pickup_Selection_Fake_Session() );

			$selection->remember( 'msk', 'pvz', 'P1' );          // seq 1
			$selection->remember( 'msk', 'postamat', 'P2' );     // seq 2 — currently "latest"

			$this->assertSame( 'P2', $selection->recall_latest( 'msk' ), 'sanity: postamat is latest before the re-write' );

			$selection->remember( 'msk', 'pvz', 'P1-again' );    // seq 3 — re-write of the FIRST type

			$this->assertSame(
				'P1-again',
				$selection->recall_latest( 'msk' ),
				'the re-written pvz entry must now be latest, even though it occupies the FIRST array position'
			);
		}

		// -------------------------------------------------------------------------
		// Selection_Scope::session_key() — per-plugin isolation
		// -------------------------------------------------------------------------

		/**
		 * Every other test in this file builds the scope through {@see self::scope()},
		 * which always uses the SAME default key — so a {@see Pickup_Selection} that
		 * ignored {@see Selection_Scope::session_key()} entirely and hardcoded that one
		 * literal string would still pass every one of them. Two plugins sharing one
		 * `WC()->session` (the real deployment shape — a site can run more than one
		 * pickup-capable shipping plugin, spec §3.2) must not see each other's
		 * selections.
		 */
		public function test_two_differently_keyed_scopes_do_not_see_each_others_selections(): void {
			$session = new Pickup_Selection_Fake_Session();

			$selection_a = new Pickup_Selection_Probe( $this->scope( 'plugin_a_selection_map' ), $session );
			$selection_b = new Pickup_Selection_Probe( $this->scope( 'plugin_b_selection_map' ), $session );

			$selection_a->remember( 'msk', 'pvz', 'A1' );
			$selection_b->remember( 'msk', 'pvz', 'B1' );

			$this->assertSame( 'A1', $selection_a->recall( 'msk', 'pvz' ), 'plugin A must not see plugin B\'s write' );
			$this->assertSame( 'B1', $selection_b->recall( 'msk', 'pvz' ), 'plugin B must not see plugin A\'s write' );
		}

		// -------------------------------------------------------------------------
		// forget_all()
		// -------------------------------------------------------------------------

		public function test_forget_all_clears_every_locality_and_type(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'msk', 'pvz', 'P1' );
			$selection->remember( 'spb', 'postamat', 'P2' );

			$selection->forget_all();

			$this->assertNull( $selection->recall( 'msk', 'pvz' ) );
			$this->assertNull( $selection->recall( 'spb', 'postamat' ) );
			$this->assertNull( $selection->recall_latest( 'msk' ) );

			// The raw stored map, not just the recalls: leaving `[ 'msk' => [], 'spb'
			// => [] ]` behind would still pass every recall assertion above.
			$this->assertSame( [], $session->raw( $scope->session_key() ) );
		}

		// -------------------------------------------------------------------------
		// No WooCommerce / no session — silent no-op, never a crash
		// -------------------------------------------------------------------------

		public function test_remember_without_a_session_does_not_crash_and_does_not_write(): void {
			$selection = $this->probe( null );

			// Must not throw.
			$selection->remember( 'msk', 'pvz', 'P1' );

			$this->assertNull( $selection->recall( 'msk', 'pvz' ) );
		}

		public function test_forget_all_without_a_session_does_not_crash(): void {
			$selection = $this->probe( null );

			// Must not throw.
			$selection->forget_all();

			$this->assertTrue( true );
		}

		public function test_recall_without_a_session_returns_null(): void {
			$selection = $this->probe( null );

			$this->assertNull( $selection->recall( 'msk', 'pvz' ) );
			$this->assertNull( $selection->recall_latest( 'msk' ) );
		}

		// -------------------------------------------------------------------------
		// woodev_pickup_max_remembered_selections — the cap, and eviction
		// -------------------------------------------------------------------------

		/**
		 * Pins the DEFAULT cap at exactly 20 — no filter registered. Writing 20 distinct
		 * (locality, type) pairs must keep all 20; the 21st write must evict exactly one
		 * (the oldest), leaving the count at 20. This test is the one whose PASS/FAIL
		 * would change if `Pickup_Selection::DEFAULT_MAX_ENTRIES` were mutated to a
		 * neighbouring value — see the implementation report for the manual mutation run
		 * that proved it (19 and 21 both fail this assertion).
		 */
		public function test_default_cap_is_twenty_entries(): void {
			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			for ( $i = 1; $i <= 20; $i++ ) {
				$selection->remember( 'loc' . $i, 'pvz', 'P' . $i );
			}

			$this->assertSame( 20, $this->count_entries( $session, $scope->session_key() ) );

			$selection->remember( 'loc21', 'pvz', 'P21' );

			$this->assertSame( 20, $this->count_entries( $session, $scope->session_key() ) );
			$this->assertSame( 'P21', $selection->recall( 'loc21', 'pvz' ), 'the just-written entry must survive eviction' );
			$this->assertNull( $selection->recall( 'loc1', 'pvz' ), 'the OLDEST entry must be the one evicted' );
		}

		public function test_cap_filter_bounds_the_map(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 3 )
				->with( 20 )
				->andReturn( 2 );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'a', 'pvz', 'P1' );
			$selection->remember( 'b', 'pvz', 'P2' );
			$selection->remember( 'c', 'pvz', 'P3' );

			$this->assertSame( 2, $this->count_entries( $session, $scope->session_key() ) );
			$this->assertSame( 'P3', $selection->recall( 'c', 'pvz' ), 'the just-written entry must never be evicted' );
			$this->assertNull( $selection->recall( 'a', 'pvz' ), 'the oldest entry must be the one evicted' );
			$this->assertSame( 'P2', $selection->recall( 'b', 'pvz' ) );
		}

		/**
		 * Reviewer's exact scenario (spec §6's own warning): distinct-keys-only
		 * eviction tests cannot tell "evict the lowest `seq`" from "evict the array's
		 * first entry" apart, because a re-assigned key keeps its ORIGINAL array
		 * position. Write A, write B, then OVERWRITE A (refreshing its `seq` while it
		 * stays at array position 0), then write C at cap 2: the correct
		 * implementation evicts B (the genuinely oldest by `seq`); a mutant evicting
		 * array position 0 would evict the just-refreshed A instead.
		 */
		public function test_eviction_follows_recency_not_array_position(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 4 )
				->with( 20 )
				->andReturn( 2 );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'a', 'pvz', 'A1' );  // seq 1
			$selection->remember( 'b', 'pvz', 'B1' );  // seq 2
			$selection->remember( 'a', 'pvz', 'A2' );  // seq 3 — overwrite; stays at array position 0
			$selection->remember( 'c', 'pvz', 'C1' );  // seq 4 — cap 2 must evict the lowest seq (b)

			$this->assertSame( 2, $this->count_entries( $session, $scope->session_key() ) );
			$this->assertSame(
				'A2',
				$selection->recall( 'a', 'pvz' ),
				'the overwritten (and thus most recently written) A entry must survive'
			);
			$this->assertSame( 'C1', $selection->recall( 'c', 'pvz' ), 'the just-written entry must never be evicted' );
			$this->assertNull( $selection->recall( 'b', 'pvz' ), 'B — the actual oldest by seq — must be the one evicted' );
		}

		/**
		 * The original version of this test wrote exactly 25 entries and asserted
		 * exactly 25 survived — proving the cap is AT LEAST 25, not that it is truly
		 * unbounded: a bug resolving `cap === 0` to a hardcoded value that happened to
		 * equal this test's own loop bound would have passed unnoticed. Writing a
		 * count with no relationship to any constant in the implementation (37,
		 * arbitrary), and asserting every single one of them individually recalls —
		 * not just the aggregate count — closes that gap.
		 */
		public function test_cap_zero_means_unbounded(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 37 )
				->with( 20 )
				->andReturn( 0 );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			for ( $i = 1; $i <= 37; $i++ ) {
				$selection->remember( 'loc' . $i, 'pvz', 'P' . $i );
			}

			$this->assertSame( 37, $this->count_entries( $session, $scope->session_key() ) );

			for ( $i = 1; $i <= 37; $i++ ) {
				$this->assertSame( 'P' . $i, $selection->recall( 'loc' . $i, 'pvz' ), "loc{$i} must not have been evicted" );
			}
		}

		/**
		 * A non-numeric filter return falls back to the DEFAULT cap (20), not to
		 * unbounded — deliberately the OPPOSITE fallback from
		 * `woodev_pickup_max_accumulated_points`, whose own safe default already IS
		 * unbounded. See {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection::max_entries()}'s
		 * own docblock.
		 */
		public function test_non_numeric_cap_falls_back_to_the_default_not_unbounded(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 3 )
				->with( 20 )
				->andReturn( 'unlimited' );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'a', 'pvz', 'P1' );
			$selection->remember( 'b', 'pvz', 'P2' );
			$selection->remember( 'c', 'pvz', 'P3' );

			// Falls back to 20 (the default), so nothing is evicted at only 3 entries —
			// this proves the fallback is NOT 0 (unbounded is indistinguishable from a
			// large-enough default at only 3 writes); the test below actually exhausts
			// the fallback cap to pin "20", not just "at least 3".
			$this->assertSame( 3, $this->count_entries( $session, $scope->session_key() ) );
		}

		/**
		 * Exhausts the fallback cap for real: 21 writes under a non-numeric filter
		 * return must still evict down to exactly 20 — proving the fallback is the
		 * DEFAULT (20), not unbounded. The test above only proves "at least 3 survive
		 * a non-numeric cap", which cannot distinguish a 20-entry fallback from an
		 * unbounded one; this one can.
		 */
		public function test_non_numeric_cap_falls_back_to_exactly_the_default_cap(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 21 )
				->with( 20 )
				->andReturn( 'unlimited' );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			for ( $i = 1; $i <= 21; $i++ ) {
				$selection->remember( 'loc' . $i, 'pvz', 'P' . $i );
			}

			$this->assertSame( 20, $this->count_entries( $session, $scope->session_key() ) );
			$this->assertSame( 'P21', $selection->recall( 'loc21', 'pvz' ), 'the just-written entry must survive eviction' );
			$this->assertNull( $selection->recall( 'loc1', 'pvz' ), 'the oldest entry must be evicted under the fallback cap' );
		}

		/**
		 * Codex adversarial review, issue #176: a POSITIVE fractional cap must still
		 * bound. `is_numeric( 0.5 )` is true, and an `(int)` cast taken first folds it to
		 * `0` — which this filter reads as "unbounded", silently switching off the very
		 * bound the caller just asked for. A malformed value may fail in either
		 * direction except that one, so a value above zero bounds at 1.
		 *
		 * Both a float and its string form: a filter reading a stored option gets the
		 * string, and `is_numeric()` accepts both.
		 *
		 * @dataProvider provide_fractional_caps
		 *
		 * @param mixed $cap The filter's return value.
		 */
		public function test_a_positive_fractional_cap_still_bounds( $cap ): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 3 )
				->with( 20 )
				->andReturn( $cap );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'a', 'pvz', 'P1' );
			$selection->remember( 'b', 'pvz', 'P2' );
			$selection->remember( 'c', 'pvz', 'P3' );

			// Bounded at 1, not unbounded: only the just-written entry survives.
			$this->assertSame( 1, $this->count_entries( $session, $scope->session_key() ) );
			$this->assertSame( 'P3', $selection->recall( 'c', 'pvz' ) );
		}

		/**
		 * @return array<string, array{0: mixed}>
		 */
		public function provide_fractional_caps(): array {
			return [
				'float'  => [ 0.5 ],
				'string' => [ '0.5' ],
			];
		}

		/**
		 * A negative filter return clips to 0 — unbounded — mirroring
		 * `woodev_pickup_max_accumulated_points`'s own "a negative bound is
		 * meaningless" clipping.
		 */
		public function test_negative_cap_clips_to_unbounded(): void {
			Filters\expectApplied( 'woodev_pickup_max_remembered_selections' )
				->times( 3 )
				->with( 20 )
				->andReturn( -5 );

			$session   = new Pickup_Selection_Fake_Session();
			$selection = $this->probe( $session );
			$scope     = $this->scope();

			$selection->remember( 'a', 'pvz', 'P1' );
			$selection->remember( 'b', 'pvz', 'P2' );
			$selection->remember( 'c', 'pvz', 'P3' );

			$this->assertSame( 3, $this->count_entries( $session, $scope->session_key() ) );
		}

		/**
		 * Counts every (locality, type) entry in the raw stored map — a direct read of
		 * the fake session's storage, independent of {@see Pickup_Selection}'s own
		 * recall methods.
		 *
		 * @param Pickup_Selection_Fake_Session $session Fake session to read.
		 * @param string                        $key     Session key to read.
		 *
		 * @return int
		 */
		private function count_entries( Pickup_Selection_Fake_Session $session, string $key ): int {
			$map = $session->raw( $key );

			if ( ! is_array( $map ) ) {
				return 0;
			}

			$count = 0;

			foreach ( $map as $types ) {
				$count += is_array( $types ) ? count( $types ) : 0;
			}

			return $count;
		}
	}
}

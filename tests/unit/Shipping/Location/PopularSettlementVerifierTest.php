<?php
/**
 * Unit tests for Popular_Settlement_Verifier — #488 slice 3, spec D6: the
 * lazy-verification engine applying "alive unchanged / alive changed / gone
 * (null) / failed (threw)" to one entry, and sweep() applying it to every row
 * of a provider (D8).
 *
 * THE load-bearing invariant under test throughout: a thrown exception, for
 * ANY reason, leaves the row COMPLETELY untouched — no delete, no clock bump.
 * `failed` is not `gone`. Only a confirmed `null` from `resolve_key()` deletes
 * a row.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Verification;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Verifier;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verification.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verifier.php';

/**
 * Provider double whose resolve_key() answer is fully controlled per test —
 * either ONE fixed answer for every call, or a `key => answer` map for a test
 * (sweep) that needs a different answer per row. An answer is a
 * {@see Location_Record} (returned), `null` (returned — "gone"), or a
 * `\Throwable` (thrown).
 */
final class Popular_Settlement_Verifier_Fixture_Provider extends Abstract_Location_Provider {

	private string $id;

	/** @var Location_Record|\Throwable|null|array<string, Location_Record|\Throwable|null> */
	private $answer;

	/** @var array<int, string> */
	public array $resolve_key_calls = [];

	/**
	 * @param string                                                          $id     provider id.
	 * @param Location_Record|\Throwable|null|array<string, Location_Record|\Throwable|null> $answer see class docblock.
	 */
	public function __construct( string $id, $answer ) {
		$this->id     = $id;
		$this->answer = $answer;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return 'Verifier Fixture';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function resolve_key( string $key ): ?Location_Record {
		$this->resolve_key_calls[] = $key;

		$answer = is_array( $this->answer ) ? ( $this->answer[ $key ] ?? null ) : $this->answer;

		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return $answer;
	}
}

/**
 * Store spy: records every mutation call the Verifier makes, and answers
 * all_for_provider() from a fixed, test-supplied list — WITHOUT ever
 * touching a real \wpdb. Safe to construct directly (the real store's own
 * constructor never touches \wpdb either — see that class's docblock); every
 * method overridden here is exactly the set {@see Popular_Settlement_Verifier}
 * is allowed to call.
 */
final class Popular_Settlement_Verifier_Store_Spy extends Popular_Settlement_Store {

	/** @var Popular_Settlement_Entry[] */
	private array $entries;

	/** @var array<int, int> */
	public array $touch_verified_calls = [];

	/** @var array<int, array{0: int, 1: Location_Record}> */
	public array $replace_record_calls = [];

	/** @var array<int, int> */
	public array $delete_entry_calls = [];

	/**
	 * @var bool What replace_record() returns — set false to model some
	 *           write-side failure OTHER than a mergeable
	 *           `(provider_id, locality_key)` collision, which the real store
	 *           now reconciles internally and reports as success (#499).
	 */
	public bool $replace_record_result = true;

	/**
	 * @param Popular_Settlement_Entry[] $entries What all_for_provider() answers (sweep() tests only).
	 */
	public function __construct( array $entries = [] ) {
		$this->entries = $entries;
	}

	public function all_for_provider( string $provider_id ): array {
		return $this->entries;
	}

	public function touch_verified( int $id, ?int $timestamp = null ): void {
		$this->touch_verified_calls[] = $id;
	}

	public function replace_record( int $id, Location_Record $record, ?int $timestamp = null ): bool {
		$this->replace_record_calls[] = [ $id, $record ];

		return $this->replace_record_result;
	}

	public function delete_entry( int $id ): void {
		$this->delete_entry_calls[] = $id;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Popular_Settlement_Verifier
 */
final class PopularSettlementVerifierTest extends TestCase {

	private function record( string $provider_id, string $native_id, string $label = '' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $provider_id . ':' . $native_id,
				'provider_id' => $provider_id,
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Moscow', 'type' => 'city' ],
				'label'       => $label,
			]
		);
	}

	private function entry( Location_Record $record, int $id = 1 ): Popular_Settlement_Entry {
		return new Popular_Settlement_Entry( $id, $record->provider_id(), $record->country(), $record, 5, 1700000000, null, 1600000000 );
	}

	/**
	 * Spec D6: "alive, unchanged" bumps only last_verified_at — the freshly
	 * resolved record is still carried on the Verification (for a caller like
	 * a sweep report), it is simply the STORE mutation that is narrow.
	 */
	public function test_unchanged_record_bumps_only_last_verified_at(): void {
		$record   = $this->record( 'dadata', '1' );
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $record );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $record ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_UNCHANGED, $verification->outcome() );
		$this->assertNotNull( $verification->record() );
		$this->assertSame( $record->key(), $verification->record()->key() );
		$this->assertSame( [ 1 ], $store->touch_verified_calls );
		$this->assertCount( 0, $store->replace_record_calls );
		$this->assertCount( 0, $store->delete_entry_calls );
	}

	/**
	 * Spec D6: "alive, changed" overwrites the record in place via
	 * replace_record() — never touch_verified() alone.
	 */
	public function test_changed_record_replaces_it_in_place(): void {
		$stored   = $this->record( 'dadata', '1', 'Old' );
		$fresh    = $this->record( 'dadata', '1', 'New' );
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $fresh );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_UPDATED, $verification->outcome() );
		$this->assertSame( 'New', $verification->record()->label() );
		$this->assertCount( 1, $store->replace_record_calls );
		$this->assertSame( 1, $store->replace_record_calls[0][0] );
		$this->assertSame( 'New', $store->replace_record_calls[0][1]->label() );
		$this->assertCount( 0, $store->touch_verified_calls );
		$this->assertCount( 0, $store->delete_entry_calls );
	}

	/**
	 * Spec D6: a changed KEY still counts as "changed" (never "gone") — the
	 * provider answered our query for the OLD key, so it is asserting
	 * continuity; the row keeps its surrogate id and its order history.
	 */
	public function test_a_changed_key_still_counts_as_updated_and_replaces_the_row(): void {
		$stored   = $this->record( 'dadata', 'old-native-id' );
		$fresh    = $this->record( 'dadata', 'new-native-id' );
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $fresh );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_UPDATED, $verification->outcome() );
		$this->assertSame( $fresh->key(), $verification->record()->key() );
		$this->assertCount( 0, $store->delete_entry_calls, 'A key change must never be read as gone.' );
	}

	/**
	 * The write half of a "changed" outcome can be REJECTED for reasons other
	 * than a mergeable `(provider_id, locality_key)` collision — the store now
	 * folds THAT case internally and still reports success (#499); a `false`
	 * here means something else went wrong (e.g. a genuinely concurrent delete
	 * racing the write). This must be reported as `failed`, never `updated` —
	 * a caller (the `/select` route's D5 step) that trusted `updated` here
	 * would persist the NEW key back to the customer while the row it thinks
	 * it updated was never actually reconciled.
	 */
	public function test_a_rejected_write_reports_failed_not_updated(): void {
		$stored   = $this->record( 'dadata', 'old-native-id' );
		$fresh    = $this->record( 'dadata', 'new-native-id' );
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $fresh );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$store->replace_record_result = false;

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored, 7 ) );

		$this->assertSame(
			Popular_Settlement_Verification::OUTCOME_FAILED,
			$verification->outcome(),
			'A rejected write must never be reported as updated — the row was NOT actually overwritten.'
		);
		$this->assertNotNull( $verification->error() );
		$this->assertNull( $verification->record() );
		$this->assertCount( 1, $store->replace_record_calls, 'The write must still have been attempted.' );
		$this->assertCount( 0, $store->touch_verified_calls, 'A failed write must never bump the clock either.' );
		$this->assertCount( 0, $store->delete_entry_calls, 'A failed write must NEVER delete — failed is not gone.' );
	}

	/**
	 * Spec D6: `null` is the ONE outcome that deletes the row.
	 */
	public function test_null_deletes_the_row_and_reports_gone(): void {
		$stored   = $this->record( 'dadata', '1' );
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', null );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored, 42 ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_GONE, $verification->outcome() );
		$this->assertNull( $verification->record() );
		$this->assertSame( [ 42 ], $store->delete_entry_calls );
		$this->assertCount( 0, $store->touch_verified_calls );
		$this->assertCount( 0, $store->replace_record_calls );
	}

	/**
	 * THE load-bearing invariant (spec D4/D6): a thrown exception leaves the
	 * row COMPLETELY untouched — no delete, no clock bump. `failed` is not
	 * `gone`.
	 */
	public function test_a_thrown_exception_leaves_the_row_completely_untouched(): void {
		$stored    = $this->record( 'dadata', '1' );
		$exception = new \RuntimeException( 'DaData resolve_key request failed.' );
		$provider  = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $exception );
		$store     = new Popular_Settlement_Verifier_Store_Spy();
		$verifier  = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_FAILED, $verification->outcome() );
		$this->assertSame( $exception, $verification->error() );
		$this->assertNull( $verification->record() );
		$this->assertCount( 0, $store->touch_verified_calls, 'A failed verification must never bump the clock.' );
		$this->assertCount( 0, $store->replace_record_calls, 'A failed verification must never overwrite the record.' );
		$this->assertCount( 0, $store->delete_entry_calls, 'A failed verification must NEVER delete — failed is not gone.' );
	}

	/**
	 * A provider lacking CAPABILITY_RESOLVE_KEY throws \BadMethodCallException
	 * (Abstract_Location_Provider's default) rather than returning null — this
	 * must ALSO be `failed`, never misread as `gone`.
	 */
	public function test_a_bad_method_call_exception_is_also_treated_as_failed_not_gone(): void {
		$stored    = $this->record( 'dadata', '1' );
		$exception = new \BadMethodCallException( 'does not implement resolve_key()' );
		$provider  = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $exception );
		$store     = new Popular_Settlement_Verifier_Store_Spy();
		$verifier  = new Popular_Settlement_Verifier( $store );

		$verification = $verifier->verify_entry( $provider, $this->entry( $stored ) );

		$this->assertSame( Popular_Settlement_Verification::OUTCOME_FAILED, $verification->outcome() );
		$this->assertCount( 0, $store->delete_entry_calls );
	}

	/**
	 * sweep() (D8's "Проверить актуальность популярных городов"): applies D6
	 * to every row and tallies each outcome — proven with ONE of each outcome
	 * present at once, driven through the real per-key resolve_key() dispatch,
	 * not a single canned answer.
	 */
	public function test_sweep_tallies_every_outcome_across_the_providers_rows(): void {
		$unchanged      = $this->record( 'dadata', '1' );
		$changed_stored = $this->record( 'dadata', '2', 'Old' );
		$changed_fresh  = $this->record( 'dadata', '2', 'New' );
		$gone_stored    = $this->record( 'dadata', '3' );
		$failed_stored  = $this->record( 'dadata', '4' );

		$answers = [
			$unchanged->key()      => $unchanged,
			$changed_stored->key() => $changed_fresh,
			$gone_stored->key()    => null,
			$failed_stored->key()  => new \RuntimeException( 'boom' ),
		];

		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', $answers );

		$entries = [
			new Popular_Settlement_Entry( 1, 'dadata', 'RU', $unchanged, 1, null, null, 0 ),
			new Popular_Settlement_Entry( 2, 'dadata', 'RU', $changed_stored, 1, null, null, 0 ),
			new Popular_Settlement_Entry( 3, 'dadata', 'RU', $gone_stored, 1, null, null, 0 ),
			new Popular_Settlement_Entry( 4, 'dadata', 'RU', $failed_stored, 1, null, null, 0 ),
		];

		$store    = new Popular_Settlement_Verifier_Store_Spy( $entries );
		$verifier = new Popular_Settlement_Verifier( $store );

		$counts = $verifier->sweep( $provider );

		$this->assertSame(
			[
				'checked'   => 4,
				'unchanged' => 1,
				'updated'   => 1,
				'deleted'   => 1,
				'failed'    => 1,
			],
			$counts
		);
		$this->assertSame( [ 1 ], $store->touch_verified_calls );
		$this->assertSame( [ 3 ], $store->delete_entry_calls );
		$this->assertCount( 1, $store->replace_record_calls );
		$this->assertSame( 2, $store->replace_record_calls[0][0] );
	}

	public function test_sweep_of_an_empty_provider_reports_all_zeros(): void {
		$provider = new Popular_Settlement_Verifier_Fixture_Provider( 'dadata', null );
		$store    = new Popular_Settlement_Verifier_Store_Spy();
		$verifier = new Popular_Settlement_Verifier( $store );

		$counts = $verifier->sweep( $provider );

		$this->assertSame(
			[
				'checked'   => 0,
				'unchanged' => 0,
				'updated'   => 0,
				'deleted'   => 0,
				'failed'    => 0,
			],
			$counts
		);
	}
}

<?php
/**
 * Unit: the analytics cache (#711, spec §4.2): hit / miss / refresh, the version in the key, the debounced bump and the
 * TTL by whether the range still contains today.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Analytics_Cache;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Analytics_Cache
 */
final class AnalyticsCacheTest extends TestCase {

	/** @var array<string,mixed> */
	private $transients = [];

	/** @var array<string,mixed> */
	private $options = [];

	/** @var array<int,array<string,mixed>> */
	private $option_writes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transients    = [];
		$this->options       = [];
		$this->option_writes = [];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) {
				$this->options[ $key ]  = $value;
				$this->option_writes[] = [ $key, $autoload ];

				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ]['value'] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = [
					'value' => $value,
					'ttl'   => $ttl,
				];

				return true;
			}
		);
	}

	public function test_a_miss_builds_stores_and_a_hit_skips_the_build(): void {
		$builds = 0;
		$build  = function () use ( &$builds ): array {
			++$builds;

			return [ 'orders' => 5 ];
		};

		$first  = Analytics_Cache::fetch( [ 'a' => 1 ], 600, $build );
		$second = Analytics_Cache::fetch( [ 'a' => 1 ], 600, $build );

		$this->assertSame( [ 'data' => [ 'orders' => 5 ], 'cached' => false ], $first );
		$this->assertSame( [ 'data' => [ 'orders' => 5 ], 'cached' => true ], $second );
		$this->assertSame( 1, $builds );
		$this->assertSame( 600, array_values( $this->transients )[0]['ttl'] );
	}

	public function test_refresh_bypasses_the_stored_answer_and_replaces_it(): void {
		$n     = 0;
		$build = function () use ( &$n ): array {
			return [ 'n' => ++$n ];
		};

		Analytics_Cache::fetch( [ 'a' => 1 ], 600, $build );
		$refreshed = Analytics_Cache::fetch( [ 'a' => 1 ], 600, $build, true );
		$after     = Analytics_Cache::fetch( [ 'a' => 1 ], 600, $build );

		$this->assertFalse( $refreshed['cached'] );
		$this->assertSame( 2, $after['data']['n'] );
		$this->assertTrue( $after['cached'] );
	}

	public function test_different_requests_do_not_share_an_answer(): void {
		Analytics_Cache::fetch( [ 'a' => 1 ], 600, static fn(): array => [ 'v' => 'one' ] );
		$other = Analytics_Cache::fetch( [ 'a' => 2 ], 600, static fn(): array => [ 'v' => 'two' ] );

		$this->assertSame( 'two', $other['data']['v'] );
		$this->assertCount( 2, $this->transients );
	}

	public function test_bumping_the_version_retires_every_stored_answer(): void {
		Analytics_Cache::fetch( [ 'a' => 1 ], 600, static fn(): array => [ 'v' => 'old' ] );

		Analytics_Cache::bump();
		$after = Analytics_Cache::fetch( [ 'a' => 1 ], 600, static fn(): array => [ 'v' => 'new' ] );

		$this->assertFalse( $after['cached'] );
		$this->assertSame( 'new', $after['data']['v'] );
	}

	public function test_two_events_inside_the_lock_write_the_version_once(): void {
		Analytics_Cache::bump();
		Analytics_Cache::bump();
		Analytics_Cache::bump();

		$this->assertCount( 1, $this->option_writes );
		$this->assertFalse( $this->option_writes[0][1], 'the version option is not autoloaded' );
		$this->assertSame( Analytics_Cache::BUMP_LOCK_SECONDS, $this->transients[ Analytics_Cache::LOCK_TRANSIENT ]['ttl'] );
	}

	public function test_a_range_containing_today_is_cached_briefly_and_a_past_one_longer(): void {
		$zone  = new \DateTimeZone( 'Europe/Moscow' );
		$today = ( new \DateTimeImmutable( 'now', $zone ) )->format( 'Y-m-d' );

		$this->assertSame( Analytics_Cache::TTL_LIVE, Analytics_Cache::ttl_for( $today, $zone ) );
		$this->assertSame( Analytics_Cache::TTL_PAST, Analytics_Cache::ttl_for( '2020-01-01', $zone ) );
		$this->assertLessThan( Analytics_Cache::TTL_PAST, Analytics_Cache::TTL_LIVE );
	}

	/** @return array<string,mixed> hook => callback of what {@see Analytics_Cache::register()} subscribed. */
	private function registered_hooks(): array {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ] = $callback;
			}
		);

		Analytics_Cache::register();

		return $hooks;
	}

	public function test_every_event_that_changes_the_aggregates_is_subscribed(): void {
		$hooks = $this->registered_hooks();

		$this->assertEqualsCanonicalizing(
			[
				'woodev_shipping_delivery_status_changed',
				'woodev_shipping_order_exported',
				'woocommerce_new_order',
				'woocommerce_order_status_changed',
				'woocommerce_update_order',
				'woocommerce_trash_order',
				'woocommerce_untrash_order',
				'woocommerce_delete_order',
			],
			array_keys( $hooks )
		);
		$this->assertSame( [ Analytics_Cache::class, 'bump' ], $hooks['woocommerce_update_order'] );
	}

	/**
	 * The critic's case (#711 round 1, finding 5): a merchant edits last month's order — its shipping price or its method — without changing a
	 * status. Only the persisted-order event fires, and the cached answer must be retired by it.
	 */
	public function test_saving_an_order_without_a_status_change_retires_the_cached_answer(): void {
		$hooks = $this->registered_hooks();

		Analytics_Cache::fetch( [ 'range' => 'last month' ], 3600, static fn(): array => [ 'shipping_paid' => 1500 ] );
		$this->assertTrue( Analytics_Cache::fetch( [ 'range' => 'last month' ], 3600, static fn(): array => [] )['cached'] );

		call_user_func( $hooks['woocommerce_update_order'], 4711 ); // a money / method-only update: no status event fires.

		$after = Analytics_Cache::fetch( [ 'range' => 'last month' ], 3600, static fn(): array => [ 'shipping_paid' => 900 ] );
		$this->assertFalse( $after['cached'] );
		$this->assertSame( 900, $after['data']['shipping_paid'] );
	}

	public function test_a_deletion_is_invalidating_and_the_debounce_still_holds_across_event_kinds(): void {
		$hooks = $this->registered_hooks();

		call_user_func( $hooks['woocommerce_delete_order'], 1 );
		call_user_func( $hooks['woocommerce_update_order'], 1 );
		call_user_func( $hooks['woocommerce_trash_order'], 1 );

		$this->assertCount( 1, $this->option_writes, 'one version write inside the lock, whatever the event' );
	}

	/**
	 * Rig smoke round 3: the data version moves with ORDERS only, so an answer computed by an older BUILD (the broken round-1 SQL) was still
	 * served after the deploy. The code's schema version is part of the key: a different one misses, even for the same request and data.
	 */
	public function test_an_answer_stored_by_another_code_schema_is_not_served(): void {
		$parts = [ 'range' => 'Q3' ];

		set_transient( Analytics_Cache::key( $parts, Analytics_Cache::SCHEMA_VERSION - 1 ), [ 'orders' => 0 ], 3600 ); // the old build's zero.

		$after = Analytics_Cache::fetch( $parts, 3600, static fn(): array => [ 'orders' => 88 ] );

		$this->assertFalse( $after['cached'] );
		$this->assertSame( 88, $after['data']['orders'] );
	}

	public function test_the_key_changes_with_the_schema_and_with_nothing_else_it_should_not(): void {
		$parts = [ 'range' => 'Q3' ];

		$this->assertNotSame( Analytics_Cache::key( $parts, 1 ), Analytics_Cache::key( $parts, 2 ) );
		$this->assertSame( Analytics_Cache::key( $parts ), Analytics_Cache::key( $parts, Analytics_Cache::SCHEMA_VERSION ), 'the default is the current schema' );
		$this->assertSame( Analytics_Cache::key( $parts ), Analytics_Cache::key( $parts ), 'deterministic' );
		$this->assertStringStartsWith( Analytics_Cache::KEY_PREFIX, Analytics_Cache::key( $parts ) );
	}
}

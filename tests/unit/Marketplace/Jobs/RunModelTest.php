<?php
/**
 * The run model: the run, the job gate, the pause, the licence gate and the first remote write.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\First_Remote_Write;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Error_Classifier;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `AccountPauseTest` and `LicenseGateTest`, with the run, the gate and the downgrade boundary the
 * donor covered only through its flows.
 */
final class RunModelTest extends Sync_Test_Case {
	/**
	 * A run counts its requests against the budget and stops starting them at its deadline.
	 *
	 * @return void
	 */
	public function test_a_run_has_one_request_and_time_budget(): void {
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$run   = $this->account_run( $token, 2 );

		$this->assertSame( $token, $run->token() );
		$this->assertSame( $this->account_id, $run->account()->id() );
		$this->assertTrue( $run->can_request() );
		$this->assertSame( 20.0, $run->remaining_seconds() );

		$run->spend_request();
		$this->assertSame( [ 1, 1 ], [ $run->requests(), $run->remaining() ] );

		$run->spend_request();
		$this->assertFalse( $run->can_request(), 'the budget is spent' );
		$this->assertSame( 0, $run->remaining() );

		$timed      = $this->account_run( $token, 5 );
		$this->now += 20;

		$this->assertFalse( $timed->can_request(), 'the deadline has passed' );
		$this->assertSame( 0.0, $timed->remaining_seconds() );
	}

	/**
	 * Every side effect of a run follows a fresh proof of ownership: a run whose claim was taken over stops right there.
	 *
	 * @return void
	 */
	public function test_a_run_that_lost_its_claim_stops_before_its_next_side_effect(): void {
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$run   = $this->account_run( $token );

		$run->guard();
		$run->write( true );

		$this->now += Job_Gate::CLAIM_TTL + 1;
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'another run takes the expired claim' );

		foreach ( [ 'guard', 'spend_request' ] as $method ) {
			try {
				$run->$method();
				$this->fail( $method . '() must stop the stale run.' );
			} catch ( Claim_Lost $e ) {
				$this->assertStringContainsString( 'taken over', $e->getMessage() );
			}
		}

		$this->assertSame( 0, $run->requests(), 'a refused request is not counted' );

		$this->expectException( Claim_Lost::class );
		$run->write( false );
	}

	/**
	 * Renewing extends the claim, so a long run keeps the account as long as it is alive.
	 *
	 * @return void
	 */
	public function test_guarding_renews_the_claim(): void {
		$gate  = $this->job_gate();
		$fence = $gate->claim( $this->account_id );

		$this->assertInstanceOf( Claim_Fence::class, $fence );
		$this->assertSame( $this->accounts()->table(), $fence->table() );
		$this->assertNull( $gate->claim( $this->account_id ), 'one run per account' );

		$this->now += Job_Gate::CLAIM_TTL - 1;
		$this->assertTrue( $gate->still_holds( $this->account_id, $fence ) );

		$this->now += Job_Gate::CLAIM_TTL - 1;
		$this->assertNull( $gate->claim( $this->account_id ), 'the renewed claim outlives its first lease' );

		$gate->release( $this->account_id, $fence );
		$this->assertNotNull( $gate->claim( $this->account_id ) );
	}

	/**
	 * The gate's checks in their order: a disabled or deleted account ends the job silently, a lapsed licence waits an hour, a
	 * paused account waits out its pause.
	 *
	 * @return void
	 */
	public function test_the_gate_checks_the_account_the_licence_and_the_pause(): void {
		$this->assertSame( [ null, 0 ], $this->job_gate()->check( 999 ) );

		list( $account, $wait ) = $this->job_gate()->check( $this->account_id );
		$this->assertSame( $this->account_id, $account->id() );
		$this->assertSame( 0, $wait );

		$this->licensed = false;
		$this->assertSame( Job_Gate::LICENCE_DELAY, $this->job_gate()->check( $this->account_id )[1] );

		$this->licensed = true;
		$this->pause()->apply( $this->account_now(), new Remote_Error( Remote_Error::PAUSE_ACCOUNT, 'auth', 'key revoked' ) );
		$this->assertSame( Account_Pause::SECONDS, $this->job_gate()->check( $this->account_id )[1] );

		$this->now += Account_Pause::SECONDS - 10;
		$this->assertSame( 60, $this->job_gate()->check( $this->account_id )[1], 'a pause about to lapse is rechecked no sooner than a minute' );

		$this->now += 10;
		$this->assertSame( 0, $this->job_gate()->check( $this->account_id )[1] );

		$this->accounts()->set_enabled( $this->account_id, false );
		$this->licensed = false;
		$this->assertSame( [ null, 0 ], $this->job_gate()->check( $this->account_id ), 'a disabled account is not even told to wait' );
	}

	/**
	 * The plugin's write gate holds an account's writes back; without one every account may write, and only an explicit `true`
	 * lets a write through.
	 *
	 * @return void
	 */
	public function test_the_write_gate_is_the_plugins(): void {
		$account = $this->account_now();

		$this->assertTrue( $this->job_gate()->allows_writes( $account ), 'no gate: nothing is held back' );

		$asked            = [];
		$this->write_gate = static function ( Account $account ) use ( &$asked ): bool {
			$asked[] = $account->id();

			return false;
		};
		$this->assertFalse( $this->job_gate()->allows_writes( $account ) );
		$this->assertSame( [ $this->account_id ], $asked );

		$this->write_gate = static function () {
			return 1;
		};
		$this->assertFalse( $this->job_gate()->allows_writes( $account ), 'a truthy answer is not a yes' );

		$this->write_gate = static function (): bool {
			return true;
		};
		$this->assertTrue( $this->job_gate()->allows_writes( $account ) );
	}

	/**
	 * A refusal stores a failed health and pauses for the full six hours; the account stays enabled.
	 *
	 * @return void
	 */
	public function test_a_refusal_stores_the_health_and_pauses_for_six_hours(): void {
		$account = $this->account_now();

		$this->assertSame( 0, $this->pause()->remaining( $account ) );

		$this->pause()->apply( $account, new Remote_Error( Remote_Error::PAUSE_ACCOUNT, 'auth_invalid', 'refused' ) );

		$this->assertSame( Health_Result::AUTH_FAILED, $this->accounts()->health( $this->account_id )->status() );
		$this->assertSame( 21600, $this->pause()->remaining( $account ) );
		$this->assertTrue( $this->account_now()->is_enabled() );
	}

	/**
	 * The pause counts down, lapses after six hours, and a successful connection test (health `ok`) ends it at once.
	 *
	 * @return void
	 */
	public function test_the_pause_lapses_after_six_hours_or_after_a_successful_test(): void {
		$account = $this->account_now();
		$refusal = new Remote_Error( Remote_Error::PAUSE_ACCOUNT, 'auth_invalid', 'refused' );

		$this->pause()->apply( $account, $refusal );
		$this->now += 3600;
		$this->assertSame( 18000, $this->pause()->remaining( $account ) );

		$this->accounts()->set_health( $this->account_id, new Health_Result( Health_Result::OK, 'ok' ) );
		$this->assertSame( 0, $this->pause()->remaining( $account ), 'a successful connection test resumes the jobs' );

		$this->pause()->apply( $account, $refusal );
		$this->now += 21600;
		$this->assertSame( 0, $this->pause()->remaining( $account ), 'six hours later the key is tried again' );
	}

	/**
	 * An account that is merely unreachable, or has no health yet, is not paused.
	 *
	 * @return void
	 */
	public function test_unreachable_and_unknown_health_do_not_pause(): void {
		$stored = [
			5 => new Health_Result( Health_Result::UNREACHABLE, 'down', null, [ 'checked_at' => new \DateTimeImmutable( '@' . $this->now ) ] ),
			7 => new Health_Result( Health_Result::AUTH_FAILED, 'no time' ),
		];
		$pause  = new Account_Pause(
			new Fake_Error_Classifier(),
			static function ( int $id ) use ( $stored ): ?Health_Result {
				return $stored[ $id ] ?? null;
			},
			static function (): bool {
				return true;
			},
			$this->clock()
		);

		$this->assertSame( 0, $pause->remaining( new Account( 5, 'fake', 'a', true ) ) );
		$this->assertSame( 0, $pause->remaining( new Account( 6, 'fake', 'b', true ) ) );
		$this->assertSame( 0, $pause->remaining( new Account( 7, 'fake', 'c', true ) ), 'a refusal with no check time cannot be timed' );
	}

	/**
	 * The health of a refused key is written only while the account still holds that key: a key the merchant replaced meanwhile
	 * is not marked refused.
	 *
	 * @return void
	 */
	public function test_a_replaced_key_is_not_marked_refused(): void {
		$stale = $this->account_now();

		$this->accounts()->replace_secrets( $this->account_id, [ 'api_token' => 'tok-new-9999' ] );
		$this->pause()->apply( $stale, new Remote_Error( Remote_Error::PAUSE_ACCOUNT, 'auth_invalid', 'refused' ) );

		$this->assertSame( 0, $this->pause()->remaining( $this->account_now() ) );
	}

	/**
	 * The refusal text matches the carriers' word for word.
	 *
	 * @return void
	 */
	public function test_the_refusal_text_matches_the_shipping_gates(): void {
		$this->stub_admin_url();

		$this->assertSame( \Woodev\Framework\Shipping\License_Gate::refusal( 'выгрузить товар' ), License_Gate::refusal( 'выгрузить товар' ) );
		$this->assertSame(
			'Лицензия плагина не действует, поэтому нельзя отправить остатки. Введите или продлите ключ на странице лицензий: https://shop.test/wp-admin/admin.php?page=woodev-licenses',
			License_Gate::refusal( 'отправить остатки' )
		);
	}

	/**
	 * A licence in force passes; one that is not gives the sentence. Only an explicit `true` is «in force».
	 *
	 * @return void
	 */
	public function test_refusal_for_is_null_only_while_the_licence_is_in_force(): void {
		$this->stub_admin_url();

		$yes = static function (): bool {
			return true;
		};
		$no  = static function (): bool {
			return false;
		};

		$this->assertNull( ( new License_Gate( $yes ) )->refusal_for( 'отправить цены' ) );
		$this->assertTrue( ( new License_Gate( $yes ) )->is_in_force() );
		$this->assertFalse( ( new License_Gate( $no ) )->is_in_force() );
		$this->assertSame( License_Gate::refusal( 'отправить цены' ), ( new License_Gate( $no ) )->refusal_for( 'отправить цены' ) );
		$this->assertFalse(
			( new License_Gate(
				static function () {
					return 1;
				}
			) )->is_in_force()
		);
	}

	/**
	 * A plugin with no store product has nothing to license; otherwise the gate follows the framework engine, and an engine
	 * that throws must not let a remote action through.
	 *
	 * @return void
	 */
	public function test_the_gate_of_a_plugin_follows_the_framework_engine(): void {
		$free = \Mockery::mock( \Woodev_Plugin::class );
		$free->shouldReceive( 'get_download_id' )->andReturn( '' );
		$free->shouldNotReceive( 'get_license_instance' );

		$this->assertTrue( License_Gate::for_plugin( $free )->is_in_force() );

		foreach ( [ true, false ] as $in_force ) {
			$engine = \Mockery::mock();
			$engine->shouldReceive( 'is_license_in_force' )->once()->andReturn( $in_force );

			$plugin = \Mockery::mock( \Woodev_Plugin::class );
			$plugin->shouldReceive( 'get_download_id' )->andReturn( '9765' );
			$plugin->shouldReceive( 'get_license_instance' )->andReturn( $engine );

			$this->assertSame( $in_force, License_Gate::for_plugin( $plugin )->is_in_force() );
		}

		$broken = \Mockery::mock( \Woodev_Plugin::class );
		$broken->shouldReceive( 'get_download_id' )->andReturn( '9765' );
		$broken->shouldReceive( 'get_license_instance' )->andThrow( new \RuntimeException( 'engine down' ) );

		$this->assertFalse( License_Gate::for_plugin( $broken )->is_in_force() );
	}

	/**
	 * The first write of an account is recorded once, before it leaves; an unsaved account records nothing, and a write that
	 * cannot be recorded is refused.
	 *
	 * @return void
	 */
	public function test_the_first_remote_write_is_recorded_once_before_it_leaves(): void {
		$record = First_Remote_Write::recorder( $this->accounts(), $this->clock() );

		$this->assertNull( First_Remote_Write::at( $this->account_now() ) );

		$record( 0 );
		$record( $this->account_id );
		$first = First_Remote_Write::at( $this->account_now() );

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', self::NOW ), $first );

		$this->now += 500;
		$record( $this->account_id );
		$this->assertSame( $first, First_Remote_Write::at( $this->account_now() ), 'the boundary never moves' );

		$this->expectException( \RuntimeException::class );
		$record( 999 );
	}

	/**
	 * Job names keep two marketplace plugins apart: the hook carries the plugin id with underscores, the group with dashes.
	 *
	 * @return void
	 */
	public function test_job_names_carry_the_plugin_id(): void {
		$names = new Job_Names( 'ozon-seller' );

		$this->assertSame( 'woodev_ozon_seller_flush_sync', $names->hook( 'flush_sync' ) );
		$this->assertSame( 'woodev-ozon-seller', $names->group() );
		$this->assertSame( 'woodev-wb-seller', ( new Job_Names( 'wb_seller' ) )->group() );
		$this->assertNotSame( $names->hook( 'drain_events' ), ( new Job_Names( 'wb-seller' ) )->hook( 'drain_events' ) );

		$this->expectException( \InvalidArgumentException::class );
		new Job_Names( 'Ozon Seller' );
	}

	/**
	 * The ladder: 1, 3, 7, 15, 30 minutes; the last step repeats and nothing waits less than the first.
	 *
	 * @return void
	 */
	public function test_the_retry_ladder(): void {
		$this->assertSame( [ 60, 60, 180, 420, 900, 1800, 1800 ], array_map( [ Backoff::class, 'seconds_after' ], [ 0, 1, 2, 3, 4, 5, 40 ] ) );
	}

	/**
	 * A remote error carries one of five consequences and nothing else.
	 *
	 * @return void
	 */
	public function test_a_remote_error_names_its_consequence(): void {
		$error = new Remote_Error( Remote_Error::THROTTLE, 'rate_limited', 'slow down', 40, false );

		$this->assertTrue( $error->is( Remote_Error::THROTTLE ) );
		$this->assertFalse( $error->is( Remote_Error::RETRY ) );
		$this->assertSame( [ 'throttle', 'rate_limited', 'slow down', 40, false ], [ $error->consequence(), $error->kind(), $error->message(), $error->retry_after(), $error->was_sent() ] );
		$this->assertSame( 'rate_limited: slow down', $error->describe() );
		$this->assertTrue( ( new Remote_Error( Remote_Error::FAIL, 'validation', 'bad' ) )->was_sent() );

		$this->expectException( \InvalidArgumentException::class );
		new Remote_Error( 'shrug', 'x', 'y' );
	}

	/**
	 * Makes `admin_url()` answer for the licence page.
	 *
	 * @return void
	 */
	private function stub_admin_url(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
	}
}

<?php
/**
 * Unit tests for the admin-ajax side of cancelling a posting.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Ajax;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Order_Canceller;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Json_Answer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\TestCase;

/**
 * The order is read through `wc_get_order()` only, so the same code serves the posts table and HPOS; the nonce is the order's own,
 * and a user without `edit_shop_orders` or an order that is not this marketplace's never reaches the marketplace.
 */
final class CancelAjaxTest extends TestCase {
	private const ORDER_ID = 5501;

	/**
	 * Accounts.
	 *
	 * @var Fake_Account_Store
	 */
	private $accounts;

	/**
	 * Order meta.
	 *
	 * @var Fake_Order_Meta
	 */
	private $meta;

	/**
	 * The scripted marketplace.
	 *
	 * @var Fake_Order_Canceller
	 */
	private $canceller;

	/**
	 * Orders by id.
	 *
	 * @var array<int,\WC_Order>
	 */
	private $orders = [];

	/**
	 * Notes added to the order.
	 *
	 * @var string[]
	 */
	private $notes = [];

	/**
	 * Whether the licence is in force.
	 *
	 * @var bool
	 */
	private $licensed = true;

	/**
	 * Lines logged.
	 *
	 * @var string[]
	 */
	private $logged = [];

	/**
	 * Boots the doubles: a licensed user, a valid nonce, one enabled account, one marketplace order.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->licensed = true;
		$this->logged   = [];

		Json_Answer::install();
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php?page=x' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'nonce-for-' . self::ORDER_ID === $nonce && 'woodev_fake_cancel_' . self::ORDER_ID === $action ? 1 : false;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->orders[ (int) $id ] ?? false;
			}
		);

		$this->accounts  = new Fake_Account_Store();
		$this->meta      = new Fake_Order_Meta();
		$this->canceller = new Fake_Order_Canceller();
		$this->add_account( true, [] );
		$this->add_order( self::ORDER_ID, 'cabinet-1', 'P-100', false );

		$_POST = [
			'order_id' => (string) self::ORDER_ID,
			'nonce'    => 'nonce-for-' . self::ORDER_ID,
		];
	}

	/**
	 * Clears the request.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_reasons_are_listed_for_an_owned_open_order(): void {
		$answer = $this->call( 'handle_reasons' );

		$this->assertTrue( $answer->success );
		$this->assertSame( $this->canceller->reasons, $answer->data['reasons'] );
		$this->assertSame( 9, $answer->data['other_reason'] );
	}

	public function test_cancel_calls_the_marketplace_adds_a_note_and_queues_the_refresh(): void {
		$_POST['reason_id'] = '9';
		$_POST['message']   = 'Склад закрыт';

		$answer = $this->call( 'handle_cancel' );

		$this->assertTrue( $answer->success );
		$this->assertSame( [ [ 1, 'P-100', 9, 'Склад закрыт' ] ], $this->canceller->cancelled );
		$this->assertSame( [ [ 1, 'P-100' ] ], $this->canceller->refreshed );
		$this->assertCount( 1, $this->notes );
		$this->assertStringContainsString( 'P-100', $this->notes[0] );
		$this->assertStringContainsString( 'Другое', $this->notes[0] );
	}

	public function test_a_wrong_nonce_is_refused_before_anything_else(): void {
		$_POST['nonce'] = 'forged';

		foreach ( [ 'handle_reasons', 'handle_cancel' ] as $handler ) {
			$answer = $this->call( $handler );

			$this->assertFalse( $answer->success, $handler );
			$this->assertSame( 403, $answer->status, $handler );
		}

		$this->assertSame( 0, $this->canceller->reason_calls );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_the_nonce_of_another_order_does_not_authorise_this_one(): void {
		$this->add_order( 5502, 'cabinet-1', 'P-200', false );
		$_POST['order_id'] = '5502'; // The nonce in the request belongs to order 5501.

		$answer = $this->call( 'handle_cancel' );

		$this->assertSame( 403, $answer->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_a_missing_nonce_or_order_id_is_refused(): void {
		unset( $_POST['nonce'] );
		$this->assertSame( 403, $this->call( 'handle_cancel' )->status );

		$_POST['nonce']    = 'nonce-for-' . self::ORDER_ID;
		$_POST['order_id'] = [ '5501' ];
		$this->assertSame( 403, $this->call( 'handle_cancel' )->status );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$answer = $this->call( 'handle_cancel' );

		$this->assertSame( 403, $answer->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_an_order_of_nobody_s_account_is_a_404_and_the_marketplace_is_not_asked(): void {
		$this->add_order( self::ORDER_ID, 'cabinet-other', 'P-100', false );

		$answer = $this->call( 'handle_cancel' );

		$this->assertSame( 404, $answer->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_an_order_without_a_posting_is_a_404(): void {
		$this->add_order( self::ORDER_ID, 'cabinet-1', '', false );

		$this->assertSame( 404, $this->call( 'handle_reasons' )->status );
		$this->assertSame( 0, $this->canceller->reason_calls );
	}

	public function test_an_unknown_order_is_a_404(): void {
		$this->orders = [];

		$this->assertSame( 404, $this->call( 'handle_reasons' )->status );
	}

	public function test_a_disabled_account_is_a_409(): void {
		$this->accounts = new Fake_Account_Store();
		$this->add_account( false, [] );

		$this->assertSame( 409, $this->call( 'handle_cancel' )->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_an_account_that_does_not_receive_orders_is_a_409(): void {
		$this->accounts = new Fake_Account_Store();
		$this->add_account( true, [ 'orders_enabled' => false ] );

		$this->assertSame( 409, $this->call( 'handle_cancel' )->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_a_finished_posting_cannot_be_cancelled(): void {
		$this->add_order( self::ORDER_ID, 'cabinet-1', 'P-100', true );
		$_POST['reason_id'] = '1';

		$answer = $this->call( 'handle_cancel' );

		$this->assertSame( 409, $answer->status );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_a_refusal_of_the_marketplace_is_passed_on_with_its_status(): void {
		$_POST['reason_id'] = '77'; // The fake refuses an unknown reason with 422.

		$answer = $this->call( 'handle_cancel' );

		$this->assertFalse( $answer->success );
		$this->assertSame( 422, $answer->status );
		$this->assertSame( [], $this->notes );
	}

	public function test_without_a_licence_neither_the_reasons_nor_the_cancellation_reach_the_marketplace(): void {
		$this->licensed     = false;
		$_POST['reason_id'] = '1';

		foreach ( [ 'handle_reasons', 'handle_cancel' ] as $handler ) {
			$answer = $this->call( $handler );

			$this->assertFalse( $answer->success, $handler );
			$this->assertSame( 403, $answer->status, $handler );
		}

		$this->assertSame( 0, $this->canceller->reason_calls );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_a_failure_that_is_not_a_refusal_is_a_502_and_is_logged(): void {
		$this->canceller = new class() extends Fake_Order_Canceller {
			public function reasons( \Woodev\Framework\Marketplace\Account\Account $account, string $posting_number ): array {
				throw new \RuntimeException( 'HTTP 503' );
			}
		};

		$answer = $this->call( 'handle_reasons' );

		$this->assertFalse( $answer->success );
		$this->assertSame( 502, $answer->status );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'HTTP 503', $this->logged[0] );
	}

	public function test_an_empty_reason_list_is_a_502(): void {
		$this->canceller->reasons = [];

		$this->assertSame( 502, $this->call( 'handle_reasons' )->status );
	}

	/**
	 * Runs a handler and returns the answer it sent.
	 *
	 * @param string $handler `handle_reasons` or `handle_cancel`.
	 * @return Json_Answer
	 */
	private function call( string $handler ): Json_Answer {
		$ajax = new Cancel_Ajax( new Admin_Guard(), $this->accounts, $this->meta, $this->canceller,
			new Screen_Names( 'fake', 'Fake' ),
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			function ( string $line ): void {
				$this->logged[] = $line;
			}
		);

		try {
			$ajax->{$handler}();
		} catch ( Json_Answer $answer ) {
			return $answer;
		}

		$this->fail( 'The handler sent no answer.' );
	}

	/**
	 * Adds the account whose owner stamp is `cabinet-1`.
	 *
	 * @param bool                $enabled  Whether it is switched on.
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function add_account( bool $enabled, array $settings ): void {
		$this->accounts->save( new Account( 0, 'fake', 'Основной', $enabled, [ 'cabinet_ref' => 'cabinet-1' ], null, $settings ) );
	}

	/**
	 * Registers an order double.
	 *
	 * @param int    $id       Order id.
	 * @param string $owner    Owner stamp.
	 * @param string $posting  Posting number.
	 * @param bool   $finished Whether the posting is finished.
	 * @return void
	 */
	private function add_order( int $id, string $owner, string $posting, bool $finished ): void {
		$meta  = [
			Fake_Order_Meta::CABINET                => $owner,
			Fake_Order_Canceller::META_POSTING      => $posting,
			Fake_Order_Canceller::META_FINISHED     => $finished ? '1' : '0',
		];
		$order = \Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) {
				$this->notes[] = $note;

				return 1;
			}
		);

		$this->orders[ $id ] = $order;
	}
}

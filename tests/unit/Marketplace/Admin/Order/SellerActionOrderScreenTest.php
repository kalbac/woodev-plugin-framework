<?php
/**
 * Unit tests for the seller actions of the order screen: the buttons of the details box and the AJAX they send.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Ajax;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Buttons;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Json_Answer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Ajax
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Buttons
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Input
 */
final class SellerActionOrderScreenTest extends TestCase {
	private const ORDER_ID = 5501;

	/**
	 * A marking code with its group separators, a percent sequence and an angle bracket: all must arrive untouched.
	 */
	private const MARKING_CODE = "010460043993125621ab%2f<x\x1D91EE06\x1D92dGVzdA==";

	/**
	 * @var Fake_Account_Store
	 */
	private $accounts;

	/**
	 * @var Fake_Order_Meta
	 */
	private $meta;

	/**
	 * @var Fake_Seller_Adapter
	 */
	private $adapter;

	/**
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * Notes added to the order.
	 *
	 * @var string[]
	 */
	private $notes = [];

	/**
	 * The goods of the order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $units = [];

	/**
	 * Owner stamp of the order.
	 *
	 * @var string
	 */
	private $owner = 'cabinet-1';

	/**
	 * Whether the licence is in force.
	 *
	 * @var bool
	 */
	private $licensed = true;

	protected function setUp(): void {
		parent::setUp();

		$this->notes    = [];
		$this->owner    = 'cabinet-1';
		$this->licensed = true;
		$this->units    = [
			[ 'u-1', 'Футболка, M', 'confirm' ],
			[ 'u-2', 'Футболка, M', 'deliver' ],
		];

		Json_Answer::install();
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php?page=x' );
		Functions\when( 'sanitize_text_field' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'nonce' === $nonce && 'woodev_fake_seller_' . self::ORDER_ID === $action ? 1 : false;
			}
		);

		$order = \Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( $key ) {
				if ( Fake_Seller_Adapter::META_UNITS === $key ) {
					return $this->units;
				}

				return Fake_Order_Meta::CABINET === $key ? $this->owner : '';
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) {
				$this->notes[] = $note;

				return 1;
			}
		);

		$this->order = $order;

		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return self::ORDER_ID === (int) $id ? $this->order : false;
			}
		);

		$this->accounts = new Fake_Account_Store();
		$this->meta     = new Fake_Order_Meta();
		$this->adapter  = new Fake_Seller_Adapter();
		$this->accounts->save( new Account( 0, 'fake', 'Основной', true, [ 'cabinet_ref' => 'cabinet-1' ], null, [] ) );

		$_POST = [
			'order_id'      => (string) self::ORDER_ID,
			'nonce'         => 'nonce',
			'seller_action' => 'marking',
			'unit'          => 'u-1',
			'fields'        => [ 'sgtin' => ' ' . self::MARKING_CODE . ' ' ],
		];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_an_action_on_one_unit_reaches_the_marketplace_with_the_code_exactly_as_typed(): void {
		$answer = $this->call( 'handle_run' );

		$this->assertTrue( $answer->success );
		$this->assertSame( [ 'Готово.', [], true ], [ $answer->data['message'], $answer->data['lines'], $answer->data['changed'] ] );
		$this->assertSame( [ 'u-1' ], Fake_Seller_Adapter::unit_ids( $this->adapter->calls[0] ) );
		$this->assertSame( self::MARKING_CODE, $this->adapter->calls[0]->value( 'sgtin' ), 'Only the surrounding spaces are removed.' );
		$this->assertSame( [ self::ORDER_ID ], $this->adapter->refreshed );
		$this->assertStringContainsString( 'Футболка, M №1', $this->notes[0], 'Two goods of one name are numbered.' );
		$this->assertStringNotContainsString( 'u-1', $this->notes[0] );
	}

	public function test_a_refused_unit_shows_the_marketplaces_text(): void {
		$this->adapter->refusals['u-1'] = 'Код уже выведен из оборота.';

		$answer = $this->call( 'handle_run' );

		$this->assertTrue( $answer->success );
		$this->assertSame( [ 'Футболка, M №1 — Код уже выведен из оборота.' ], $answer->data['lines'] );
		$this->assertFalse( $answer->data['changed'] );
	}

	public function test_a_unit_in_a_status_that_does_not_allow_the_action_is_refused_without_a_remote_call(): void {
		$_POST['unit'] = 'u-2';

		$answer = $this->call( 'handle_run' );

		$this->assertSame( [ 'Футболка, M №2 — В статусе «Статус deliver» это действие недоступно.' ], $answer->data['lines'] );
		$this->assertFalse( $answer->data['changed'] );
		$this->assertSame( [], $this->adapter->calls );
		$this->assertSame( [], $this->adapter->checked );
	}

	public function test_an_empty_required_code_and_a_control_character_are_field_errors_before_any_call(): void {
		foreach ( [ '', "0104\x00600" ] as $code ) {
			$_POST['fields'] = [ 'sgtin' => $code ];

			$answer = $this->call( 'handle_run' );

			$this->assertSame( 422, $answer->status );
			$this->assertArrayHasKey( 'sgtin', $answer->data['errors'] );
		}

		$this->assertSame( [], $this->adapter->checked );
		$this->assertSame( [], $this->adapter->calls );
	}

	public function test_the_marketplaces_own_input_rule_is_a_field_error_before_the_remote_call(): void {
		$this->adapter->input_errors = [ 'sgtin' => 'В коде нет разделителей: отсканируйте его ещё раз.' ];

		$answer = $this->call( 'handle_run' );

		$this->assertSame( 422, $answer->status );
		$this->assertSame( $this->adapter->input_errors, $answer->data['errors'] );
		$this->assertSame( [], $this->adapter->calls );
	}

	public function test_the_whole_order_takes_only_an_action_offered_on_an_order(): void {
		unset( $_POST['unit'] );

		$this->assertSame( 404, $this->call( 'handle_run' )->status, 'The marking code is offered on a unit only.' );

		$_POST['seller_action'] = 'receive';
		$_POST['fields']        = [];

		$this->assertTrue( $this->call( 'handle_run' )->success );
		$this->assertSame( [ 'u-2' ], Fake_Seller_Adapter::unit_ids( $this->adapter->calls[0] ), 'Every unit of the order that takes it.' );
	}

	public function test_a_wrong_nonce_a_foreign_order_and_an_unknown_action_never_reach_the_marketplace(): void {
		$_POST['nonce'] = 'forged';
		$this->assertSame( 403, $this->call( 'handle_run' )->status );

		$_POST['nonce'] = 'nonce';
		$this->owner    = 'cabinet-other';
		$this->assertSame( 404, $this->call( 'handle_run' )->status );

		$this->owner            = 'cabinet-1';
		$_POST['seller_action'] = 'nothing';
		$this->assertSame( 404, $this->call( 'handle_run' )->status );

		$this->assertSame( [], $this->adapter->calls );
	}

	public function test_without_a_licence_the_action_is_a_403(): void {
		$this->licensed = false;

		$this->assertSame( 403, $this->call( 'handle_run' )->status );
		$this->assertSame( [], $this->adapter->calls );
	}

	public function test_the_choices_of_a_list_field_come_from_the_adapter(): void {
		$_POST['seller_action'] = 'supply';
		$_POST['field']         = 'batch';

		$this->assertSame( [ [ 'value' => 'WB-1', 'label' => 'Поставка от 26.11' ] ], $this->call( 'handle_options' )->data['options'] );
	}

	public function test_the_box_shows_every_unit_with_its_buttons_a_refused_one_off_with_the_reason(): void {
		$html = $this->buttons()->unit_list( $this->order );

		$this->assertStringContainsString( 'Футболка, M №1', $html );
		$this->assertStringContainsString( 'Статус deliver', $html );
		$this->assertSame( 4, substr_count( $html, '<button ' ), '«Вручён» and «Код маркировки» for each of the two units.' );
		$this->assertSame( 2, substr_count( $html, 'aria-disabled="true"' ), 'One of the two is off for each unit.' );
		$this->assertStringContainsString( 'title="В статусе «Статус confirm» это действие недоступно."', $html );
		$this->assertStringNotContainsString( '>u-1<', $html );
	}

	public function test_the_box_shows_the_order_buttons_of_the_declared_actions(): void {
		$html = $this->buttons()->order_buttons( $this->order );

		$this->assertStringContainsString( '>Принять в работу<', $html );
		$this->assertStringContainsString( 'title="В статусе «Статус confirm» это действие недоступно."', $html );
		$this->assertStringContainsString( 'data-run-action="woodev_fake_seller_action"', $html );
		$this->assertStringNotContainsString( 'Добавить в поставку', $html, 'A bulk action is not an order button.' );
	}

	/**
	 * @return Seller_Action_Runner
	 */
	private function runner(): Seller_Action_Runner {
		return new Seller_Action_Runner(
			$this->adapter,
			new Posting_Action_Gate( new Label_Availability( null ), null, null, null, $this->adapter ),
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			'Фейк'
		);
	}

	/**
	 * @return Seller_Action_Buttons
	 */
	private function buttons(): Seller_Action_Buttons {
		return new Seller_Action_Buttons(
			new Screen_Names( 'fake', 'Фейк' ),
			$this->runner(),
			function (): ?Account {
				return $this->accounts->get( 1 );
			},
			'https://example.test/assets/',
			'1.0.0'
		);
	}

	/**
	 * Runs a handler and returns the answer it sent.
	 *
	 * @param string $handler `handle_run` or `handle_options`.
	 * @return Json_Answer
	 */
	private function call( string $handler ): Json_Answer {
		$ajax = new Seller_Action_Ajax(
			new Admin_Guard(),
			$this->accounts,
			$this->meta,
			$this->runner(),
			new Screen_Names( 'fake', 'Фейк' ),
			static function (): array {
				return [ 'account_id' => 1 ];
			}
		);

		try {
			$ajax->{$handler}();
		} catch ( Json_Answer $answer ) {
			return $answer;
		}

		$this->fail( 'The handler sent no answer.' );
	}
}

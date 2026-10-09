<?php
/**
 * Unit: Toolbar_Actions — the page-level actions above the orders table (s164).
 *
 * Pins the three things a carrier plugin leans on: what a declaration may look like (buttons, tabs, fields, rows),
 * how a submit is validated and fanned out to one handler call per order with per-order results, and that a row
 * button of the list tab is refused unless the carrier's CURRENT dialog offers it.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Admin\Orders\Toolbar_Actions;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Toolbar_Actions
 */
final class ToolbarActionsTest extends TestCase {

	/** @var array<string,callable> hook => callback( $value, ...$args ), set per test. */
	private $hooks = [];

	/** @var array<int,array> every call of the per-order handler: [ action id, order id, provider id, payload ]. */
	private $performed = [];

	protected function setUp(): void {
		parent::setUp();

		$this->hooks     = [];
		$this->performed = [];

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'error_log' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return isset( $this->hooks[ $hook ] ) ? ( $this->hooks[ $hook ] )( $value, ...$args ) : $value;
			}
		);
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] );
	}

	/**
	 * A toolbar over a registry that resolves every order to the SDEK provider — except the ids in `$no_carrier`,
	 * and `wc_get_order()` knowing the ids in `$known` (default: 1047, 1050, 1051).
	 *
	 * @param int[] $known      order ids that exist.
	 * @param int[] $no_carrier order ids whose carrier cannot be resolved.
	 * @param int[] $foreign    order ids that resolve to ANOTHER carrier («yandex»).
	 */
	private function toolbar( array $known = [ 1047, 1050, 1051 ], array $no_carrier = [], array $foreign = [] ): Toolbar_Actions {
		$provider = $this->provider();
		$other    = Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ] );
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'resolve_provider_for_order' )->andReturnUsing(
			static function ( $order ) use ( $no_carrier, $foreign, $provider, $other ) {
				if ( in_array( $order->get_id(), $no_carrier, true ) ) {
					return null;
				}

				return in_array( $order->get_id(), $foreign, true ) ? $other : $provider;
			}
		);

		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $known ) {
				if ( ! in_array( $id, $known, true ) ) {
					return false;
				}

				$order = Mockery::mock( '\WC_Order' );
				$order->shouldReceive( 'get_id' )->andReturn( $id );
				$order->shouldReceive( 'get_order_number' )->andReturn( (string) $id );

				return $order;
			}
		);

		return new Toolbar_Actions( $registry );
	}

	/** Declares one button, `call_courier`, and its dialog, by the callbacks given. */
	private function declare_courier( ?callable $dialog = null, ?callable $perform = null ): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static function ( $value ) {
			$value[] = [
				'id'       => 'call_courier',
				'provider' => 'cdek',
				'label'    => 'Вызвать курьера',
				'icon'     => 'car',
				'count'    => 3,
			];

			return $value;
		};
		$this->hooks['woodev_shipping_orders_toolbar_dialog']  = $dialog ?? fn( $value, $id ) => 'call_courier' === $id ? $this->courier_dialog() : $value;

		if ( null !== $perform ) {
			$this->hooks['woodev_shipping_perform_toolbar_action'] = $perform;
		}
	}

	/** @return array<string,mixed> the courier call's dialog: the form of the brief and the «Заявки» list. */
	private function courier_dialog(): array {
		return [
			'title' => 'Вызвать курьера',
			'tabs'  => [
				[
					'id'           => 'call',
					'type'         => 'form',
					'label'        => 'Вызов',
					'submit_label' => 'Вызвать',
					'fields'       => [
						[
							'id'      => 'orders',
							'type'    => 'orders',
							'label'   => 'Заказы',
							'options' => [
								[
									'value' => '1047',
									'label' => '#1047 · Екатеринбург',
								],
								[
									'value' => '1050',
									'label' => '#1050 · Москва',
								],
								[
									'value' => '1051',
									'label' => '#1051 · Казань',
								],
							],
						],
						[
							'id'       => 'day',
							'type'     => 'date',
							'label'    => 'День',
							'required' => true,
							'help'     => 'На один адрес — один вызов в день.',
						],
						[
							'id'    => 'comment',
							'type'  => 'textarea',
							'label' => 'Комментарий',
						],
					],
				],
				[
					'id'      => 'intakes',
					'type'    => 'list',
					'label'   => 'Заявки',
					'columns' => [
						[
							'id'    => 'number',
							'label' => '№',
						],
						[
							'id'    => 'day',
							'label' => 'День',
						],
					],
					'rows'    => [
						[
							'id'      => 'uuid-1',
							'cells'   => [
								'number' => '13312783',
								'day'    => '12.10',
							],
							'actions' => [
								[
									'action'      => 'cancel',
									'label'       => 'Отменить',
									'destructive' => true,
									'confirm'     => 'Отменить вызов?',
								],
							],
						],
					],
				],
			],
		];
	}

	/** A per-order handler that records the call and answers `$answer( $order_id )`. */
	private function recording_handler( callable $answer ): callable {
		return function ( $value, $action, $order, $provider, $payload ) use ( $answer ) {
			$this->performed[] = [ $action, $order->get_id(), $provider->get_id(), $payload ];

			return $answer( $order->get_id() );
		};
	}

	/** @return array<string,mixed> */
	private function payload( array $over = [] ): array {
		return array_merge(
			[
				'orders'  => [ '1047', '1050' ],
				'day'     => '2026-10-12',
				'comment' => 'Позвонить за час',
			],
			$over
		);
	}

	// ----- the buttons -----

	public function test_a_button_needs_a_usable_unique_id_a_label_and_an_owning_carrier(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			'text',
			[
				'label'    => 'No id',
				'provider' => 'cdek',
			],
			[
				'id'       => 'Bad Id',
				'label'    => 'Upper case and a space',
				'provider' => 'cdek',
			],
			[
				'id'       => 'nolabel',
				'provider' => 'cdek',
			],
			[
				'id'    => 'noowner',
				'label' => 'No carrier claims it',
			],
			[
				'id'       => 'blankowner',
				'label'    => 'Blank carrier',
				'provider' => '  ',
			],
			[
				'id'       => 'arrayowner',
				'label'    => 'Not a string',
				'provider' => [ 'cdek' ],
			],
			[
				'id'       => 'call_courier',
				'label'    => 'Вызвать курьера',
				'provider' => 'cdek',
			],
			[
				'id'       => 'call_courier',
				'label'    => 'Same id again',
				'provider' => 'cdek',
			],
		];

		$this->assertSame( [ 'call_courier' ], array_column( $this->toolbar()->declared(), 'id' ) );
		$this->assertSame( 'Вызвать курьера', $this->toolbar()->declared()[0]['label'], 'the first of two buttons with one id wins' );
		$this->assertSame( 'cdek', $this->toolbar()->declared()[0]['provider'] );
	}

	public function test_the_owning_carrier_is_the_servers_business_and_not_sent_to_the_page(): void {
		$this->declare_courier();

		$shown = $this->toolbar()->for_page();

		$this->assertSame( [ 'call_courier' ], array_column( $shown, 'id' ) );
		$this->assertArrayNotHasKey( 'provider', $shown[0] );
	}

	public function test_a_button_is_shown_unless_its_count_is_zero(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			[
				'id'       => 'a',
				'provider' => 'cdek',
				'label'    => 'A',
				'count'    => 4,
			],
			[
				'id'       => 'b',
				'provider' => 'cdek',
				'label'    => 'B',
				'count'    => 0,
			],
			[
				'id'       => 'c',
				'provider' => 'cdek',
				'label'    => 'C',
			],
		];

		$shown = $this->toolbar()->for_page();

		$this->assertSame( [ 'a', 'c' ], array_column( $shown, 'id' ) );
		$this->assertSame( 4, $shown[0]['count'] );
		$this->assertNull( $shown[1]['count'], 'no count declared => none is invented' );
		$this->assertArrayNotHasKey( 'visible', $shown[0], 'visibility is the server\'s decision, not a field the client reads' );
	}

	public function test_an_explicit_visible_overrides_the_count(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			[
				'id'       => 'a',
				'provider' => 'cdek',
				'label'    => 'A',
				'count'    => 0,
				'visible'  => true,
			],
			[
				'id'       => 'b',
				'provider' => 'cdek',
				'label'    => 'B',
				'count'    => 5,
				'visible'  => false,
			],
		];

		$this->assertSame( [ 'a' ], array_column( $this->toolbar()->for_page(), 'id' ) );
	}

	public function test_a_hidden_button_can_still_be_found_for_its_dialog(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			[
				'id'       => 'a',
				'provider' => 'cdek',
				'label'    => 'A',
				'count'    => 0,
			],
		];

		$this->assertSame( [], $this->toolbar()->for_page() );
		$this->assertSame( 'a', $this->toolbar()->find( 'a' )['id'] );
		$this->assertNull( $this->toolbar()->find( 'zzz' ) );
	}

	public function test_nothing_hooked_means_no_buttons(): void {
		$this->assertSame( [], $this->toolbar()->for_page() );
		$this->assertSame( [], $this->toolbar()->declared() );
	}

	public function test_a_button_carries_a_clean_icon_and_a_non_negative_count(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			[
				'id'       => 'a',
				'provider' => 'cdek',
				'label'    => 'A',
				'icon'     => 'car',
				'count'    => -3,
				'title'    => '<b>Курьер</b>',
			],
			[
				'id'       => 'b',
				'provider' => 'cdek',
				'label'    => 'B',
				'icon'     => 'Not A Slug!',
			],
		];

		$shown = $this->toolbar()->declared();

		$this->assertSame( 'car', $shown[0]['icon'] );
		$this->assertSame( 0, $shown[0]['count'] );
		$this->assertSame( 'Курьер', $shown[0]['title'] );
		$this->assertSame( '', $shown[1]['icon'] );
	}

	// ----- the dialog -----

	public function test_a_dialog_is_asked_for_only_by_an_action_that_is_declared(): void {
		$asked = 0;

		$this->hooks['woodev_shipping_orders_toolbar_dialog'] = function ( $value ) use ( &$asked ) {
			++$asked;

			return $this->courier_dialog();
		};

		$this->assertNull( $this->toolbar()->dialog( 'call_courier' ) );
		$this->assertSame( 0, $asked );
	}

	public function test_no_answer_from_the_carrier_is_no_dialog(): void {
		$this->declare_courier( static fn( $value ) => $value );

		$this->assertNull( $this->toolbar()->dialog( 'call_courier' ) );
	}

	public function test_a_dialog_keeps_its_tabs_and_asks_with_the_action_id(): void {
		$asked = null;

		$this->declare_courier(
			function ( $value, $id ) use ( &$asked ) {
				$asked = $id;

				return $this->courier_dialog();
			}
		);

		$dialog = $this->toolbar()->dialog( 'call_courier' );

		$this->assertSame( 'call_courier', $asked );
		$this->assertSame( 'Вызвать курьера', $dialog['title'] );
		$this->assertSame( [ 'call', 'intakes' ], array_column( $dialog['tabs'], 'id' ) );
		$this->assertSame( [ 'form', 'list' ], array_column( $dialog['tabs'], 'type' ) );
	}

	public function test_a_dialog_with_no_title_takes_the_buttons_label(): void {
		$this->declare_courier(
			function () {
				$dialog = $this->courier_dialog();
				unset( $dialog['title'] );

				return $dialog;
			}
		);

		$this->assertSame( 'Вызвать курьера', $this->toolbar()->dialog( 'call_courier' )['title'] );
	}

	public function test_the_form_tab_carries_its_fields_sanitised_with_the_help_and_the_preselected_orders(): void {
		$this->declare_courier();

		$form = $this->toolbar()->dialog( 'call_courier' )['tabs'][0];

		$this->assertSame( 'Вызвать', $form['submit_label'] );
		$this->assertSame( [ 'orders', 'day', 'comment' ], array_column( $form['fields'], 'id' ) );
		$this->assertSame( [ '1047', '1050', '1051' ], $form['fields'][0]['default'], 'every order is preselected' );
		$this->assertSame( 'На один адрес — один вызов в день.', $form['fields'][1]['help'] );
	}

	public function test_a_form_tab_without_an_orders_field_is_dropped_and_a_second_one_too(): void {
		$this->declare_courier(
			static fn() => [
				'tabs' => [
					[
						'id'     => 'noorders',
						'type'   => 'form',
						'label'  => 'Без заказов',
						'fields' => [
							[
								'id'    => 'day',
								'type'  => 'date',
								'label' => 'День',
							],
						],
					],
					[
						'id'     => 'twice',
						'type'   => 'form',
						'label'  => 'Дважды',
						'fields' => [
							[
								'id'      => 'one',
								'type'    => 'orders',
								'label'   => 'Первые',
								'options' => [
									[
										'value' => '1',
										'label' => '#1',
									],
								],
							],
							[
								'id'      => 'two',
								'type'    => 'orders',
								'label'   => 'Вторые',
								'options' => [
									[
										'value' => '2',
										'label' => '#2',
									],
								],
							],
						],
					],
				],
			]
		);

		$tabs = $this->toolbar()->dialog( 'call_courier' )['tabs'];

		$this->assertSame( [ 'twice' ], array_column( $tabs, 'id' ) );
		$this->assertSame( [ 'one' ], array_column( $tabs[0]['fields'], 'id' ) );
	}

	public function test_a_dialog_keeps_only_its_first_form_tab(): void {
		$this->declare_courier(
			function () {
				$dialog         = $this->courier_dialog();
				$second         = $dialog['tabs'][0];
				$second['id']   = 'second';
				$dialog['tabs'][] = $second;

				return $dialog;
			}
		);

		$this->assertSame( [ 'call', 'intakes' ], array_column( $this->toolbar()->dialog( 'call_courier' )['tabs'], 'id' ), 'a submit runs ONE form; a second would look live and do nothing' );
	}

	public function test_a_dialog_left_with_no_usable_tab_is_no_dialog(): void {
		$this->declare_courier(
			static fn() => [
				'tabs' => [
					'text',
					[
						'id'    => 'x',
						'type'  => 'chart',
						'label' => 'Unknown type',
					],
					[
						'id'    => 'Bad Id',
						'type'  => 'list',
						'label' => 'Bad id',
					],
					[
						'id'   => 'nolabel',
						'type' => 'list',
					],
					[
						'id'      => 'nocolumns',
						'type'    => 'list',
						'label'   => 'No columns',
						'columns' => [],
					],
				],
			]
		);

		$this->assertNull( $this->toolbar()->dialog( 'call_courier' ) );
	}

	public function test_a_dialog_is_not_a_list_of_tabs_when_it_has_none(): void {
		$this->declare_courier( static fn() => [ 'title' => 'x' ] );

		$this->assertNull( $this->toolbar()->dialog( 'call_courier' ) );
	}

	public function test_tabs_are_capped_and_a_repeated_tab_id_is_dropped(): void {
		$this->declare_courier(
			static function () {
				$tabs = [];

				for ( $i = 1; $i <= 7; $i++ ) {
					$tabs[] = [
						'id'      => 'tab' . min( $i, 2 ),
						'type'    => 'list',
						'label'   => 'Вкладка',
						'columns' => [
							[
								'id'    => 'a',
								'label' => 'A',
							],
						],
					];
				}

				return [ 'tabs' => $tabs ];
			}
		);

		$this->assertSame( [ 'tab1', 'tab2' ], array_column( $this->toolbar()->dialog( 'call_courier' )['tabs'], 'id' ) );
	}

	public function test_the_list_tab_fills_every_column_and_keeps_only_the_buttons_that_are_usable(): void {
		$this->declare_courier(
			function () {
				$dialog                               = $this->courier_dialog();
				$dialog['tabs'][1]['rows']            = [
					[
						'id'      => 7,
						'cells'   => [
							'number' => 13312783,
							'extra'  => 'not a column',
						],
						'actions' => [
							[
								'action'      => 'cancel',
								'label'       => 'Отменить',
								'destructive' => true,
								'confirm'     => 'Точно?',
								'icon'        => 'dismiss',
							],
							[
								'action' => 'Bad Id',
								'label'  => 'Bad',
							],
							[
								'action' => 'note',
								'label'  => 'Заметка',
								'confirm' => 'Не нужно',
							],
							[
								'action' => 'cancel',
								'label'  => 'Дубль',
							],
						],
					],
					[ 'id' => '' ],
					[ 'id' => 7 ],
					'text',
				];
				$dialog['tabs'][1]['empty']           = 'Заявок пока нет.';

				return $dialog;
			}
		);

		$list = $this->toolbar()->dialog( 'call_courier' )['tabs'][1];

		$this->assertSame( 'Заявок пока нет.', $list['empty'] );
		$this->assertCount( 1, $list['rows'], 'an empty id, a repeated id and a non-array row are dropped' );
		$this->assertSame( '7', $list['rows'][0]['id'] );
		$this->assertSame(
			[
				'number' => '13312783',
				'day'    => '',
			],
			$list['rows'][0]['cells'],
			'every column has a cell; a value for no column is dropped'
		);
		$this->assertSame( [ 'cancel', 'note' ], array_column( $list['rows'][0]['actions'], 'action' ) );
		$this->assertSame( 'Точно?', $list['rows'][0]['actions'][0]['confirm'] );
		$this->assertArrayNotHasKey( 'confirm', $list['rows'][0]['actions'][1], 'a harmless button has nothing to confirm' );
		$this->assertSame( 'dismiss', $list['rows'][0]['actions'][0]['icon'] );
	}

	public function test_rows_are_capped(): void {
		$this->declare_courier(
			function () {
				$dialog                    = $this->courier_dialog();
				$dialog['tabs'][1]['rows'] = [];

				for ( $i = 1; $i <= Toolbar_Actions::MAX_ROWS + 30; $i++ ) {
					$dialog['tabs'][1]['rows'][] = [ 'id' => 'r' . $i ];
				}

				return $dialog;
			}
		);

		$this->assertCount( Toolbar_Actions::MAX_ROWS, $this->toolbar()->dialog( 'call_courier' )['tabs'][1]['rows'] );
	}

	// ----- submit -----

	public function test_submit_is_unavailable_for_an_unknown_action_or_a_dialog_without_a_form(): void {
		$this->assertNull( $this->toolbar()->submit( 'call_courier', $this->payload() ) );

		$this->declare_courier(
			function () {
				$dialog         = $this->courier_dialog();
				$dialog['tabs'] = [ $dialog['tabs'][1] ];

				return $dialog;
			}
		);

		$this->assertNull( $this->toolbar()->submit( 'call_courier', $this->payload() ), 'a list-only dialog has nothing to submit' );
	}

	public function test_a_payload_that_does_not_fit_is_refused_before_the_carrier_is_called(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'day' => '' ] ) );

		$this->assertSame( [ 'day' ], array_column( $result['errors'], 'field' ) );
		$this->assertSame( 'required', $result['errors'][0]['code'] );
		$this->assertSame( [], $this->performed );
	}

	public function test_an_order_the_dialog_never_offered_refuses_the_whole_submit(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'orders' => [ '1047', '9999' ] ] ) );

		$this->assertSame( 'invalid_option', $result['errors'][0]['code'] );
		$this->assertSame( 'orders', $result['errors'][0]['field'] );
		$this->assertSame( [], $this->performed, 'nothing runs for the orders that were fine either' );
	}

	public function test_no_orders_chosen_is_an_error_even_when_the_field_is_not_required(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		foreach ( [ [], null ] as $none ) {
			$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'orders' => $none ] ) );

			$this->assertSame( 'orders', $result['errors'][0]['field'] );
			$this->assertSame( 'required', $result['errors'][0]['code'] );
		}

		$this->assertSame( [], $this->performed );
	}

	public function test_the_handler_runs_once_per_chosen_order_in_the_order_sent_with_the_shared_payload(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'orders' => [ '1050', '1047' ] ] ) );

		$this->assertSame(
			[
				[
					'call_courier',
					1050,
					'cdek',
					[
						'day'     => '2026-10-12',
						'comment' => 'Позвонить за час',
					],
				],
				[
					'call_courier',
					1047,
					'cdek',
					[
						'day'     => '2026-10-12',
						'comment' => 'Позвонить за час',
					],
				],
			],
			$this->performed,
			'the payload is the same for every order, and does not carry the choice of orders'
		);
		$this->assertSame( 2, $result['requested'] );
		$this->assertSame( 2, $result['succeeded'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( [ 'success' => 'Выполнено 2 из 2' ], $result['messages'] );
		$this->assertSame( [ 1050, 1047 ], array_column( $result['results'], 'id' ) );
		$this->assertSame( [ true, true ], array_column( $result['results'], 'ok' ) );
		$this->assertSame( [ '', '' ], array_column( $result['results'], 'message' ), 'a plain success has nothing to say beside the order' );
	}

	public function test_an_empty_optional_field_reaches_the_handler_as_an_empty_string(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		$this->toolbar()->submit( 'call_courier', $this->payload( [ 'comment' => '' ] ) );

		$this->assertSame( '', $this->performed[0][3]['comment'] );
	}

	public function test_a_failure_carries_the_carriers_reason_with_its_name_and_the_others_still_run(): void {
		$this->declare_courier(
			null,
			$this->recording_handler(
				static fn( $id ) => 1047 === $id
					? Action_Result::failure( 'На этот адрес вызов уже есть' )
					: Action_Result::success( '', 'Заявка № 13312784' )
			)
		);

		$result = $this->toolbar()->submit( 'call_courier', $this->payload() );

		$this->assertSame( 1, $result['succeeded'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'СДЭК: На этот адрес вызов уже есть', $result['results'][0]['message'] );
		$this->assertFalse( $result['results'][0]['ok'] );
		$this->assertTrue( $result['results'][1]['ok'] );
		$this->assertSame( 'СДЭК: Заявка № 13312784', $result['results'][1]['message'], 'a success note is shown beside the order' );
		$this->assertSame(
			[
				'success' => 'Выполнено 1 из 2',
				'error'   => 'Не удалось выполнить действие для 1 из 2',
			],
			$result['messages']
		);
	}

	public function test_a_failure_without_a_reason_gets_the_generic_sentence(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::failure() ) );

		$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'orders' => [ '1047' ] ] ) );

		$this->assertSame( 'Действие не выполнено.', $result['results'][0]['message'] );
		$this->assertSame( [ 'error' => 'Не удалось выполнить действие для 1 из 1' ], $result['messages'] );
	}

	public function test_a_handler_that_throws_fails_that_order_with_a_generic_sentence_and_not_the_batch(): void {
		$this->declare_courier(
			null,
			$this->recording_handler(
				static function ( $id ) {
					if ( 1047 === $id ) {
						throw new \RuntimeException( 'secret=abc123 refused' );
					}

					return Action_Result::success();
				}
			)
		);

		$result = $this->toolbar()->submit( 'call_courier', $this->payload() );

		$this->assertSame( [ false, true ], array_column( $result['results'], 'ok' ) );
		$this->assertStringNotContainsString( 'abc123', $result['results'][0]['message'], 'the browser never sees the exception text' );
		$this->assertStringContainsString( 'временно недоступен', $result['results'][0]['message'] );
	}

	public function test_an_answer_that_is_not_an_action_result_is_a_failure(): void {
		$this->declare_courier( null, static fn() => true );

		$result = $this->toolbar()->submit( 'call_courier', $this->payload( [ 'orders' => [ '1047' ] ] ) );

		$this->assertFalse( $result['results'][0]['ok'] );
	}

	public function test_nothing_hooked_means_every_order_fails_honestly(): void {
		$this->declare_courier();

		$result = $this->toolbar()->submit( 'call_courier', $this->payload() );

		$this->assertSame( 0, $result['succeeded'] );
		$this->assertSame( 2, $result['failed'] );
	}

	public function test_an_order_that_vanished_or_has_no_carrier_fails_without_calling_the_handler(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success() ) );

		$result = $this->toolbar( [ 1050 ], [ 1050 ] )->submit( 'call_courier', $this->payload() );

		$this->assertSame( [], $this->performed );
		$this->assertSame( [ 'Заказ не найден.', 'Не удалось определить перевозчика для этого заказа.' ], array_column( $result['results'], 'message' ) );
	}

	public function test_an_order_of_another_carrier_is_refused_and_the_handler_never_sees_it(): void {
		$this->declare_courier( null, $this->recording_handler( static fn() => Action_Result::success( '', 'Заявка № 1' ) ) );

		// 1050 is offered by the dialog but resolves to Yandex (an over-broad eligibility query, or a reassignment).
		$result = $this->toolbar( [ 1047, 1050, 1051 ], [], [ 1050 ] )->submit( 'call_courier', $this->payload( [ 'orders' => [ '1047', '1050', '1051' ] ] ) );

		$this->assertSame( [ 1047, 1051 ], array_column( $this->performed, 1 ), 'the foreign order is never forwarded' );
		$this->assertSame( [ 'cdek', 'cdek' ], array_column( $this->performed, 2 ) );
		$this->assertSame( 2, $result['succeeded'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertFalse( $result['results'][1]['ok'] );
		$this->assertSame( 1050, $result['results'][1]['id'] );
		$this->assertSame( 'Это действие относится к другому перевозчику и недоступно для этого заказа.', $result['results'][1]['message'] );
	}

	public function test_two_carriers_each_own_their_actions_and_neither_reaches_the_others_orders(): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn() => [
			[
				'id'       => 'call_courier',
				'provider' => 'cdek',
				'label'    => 'СДЭК',
			],
			[
				'id'       => 'call_yandex',
				'provider' => 'yandex',
				'label'    => 'Яндекс',
			],
		];
		$this->hooks['woodev_shipping_orders_toolbar_dialog']  = fn( $value ) => $this->courier_dialog();
		$this->hooks['woodev_shipping_perform_toolbar_action'] = $this->recording_handler( static fn() => Action_Result::success() );

		$toolbar = $this->toolbar( [ 1047, 1050, 1051 ], [], [ 1050 ] );
		$toolbar->submit( 'call_yandex', $this->payload( [ 'orders' => [ '1047', '1050' ] ] ) );

		$this->assertSame( [ [ 'call_yandex', 1050, 'yandex' ] ], array_map( static fn( $call ) => array_slice( $call, 0, 3 ), $this->performed ), 'only the Yandex order reaches the Yandex action' );
	}

	// ----- the list tab's buttons -----

	/** @var array<int,array> */
	private $row_calls = [];

	private function hook_row_action( callable $answer ): void {
		$this->row_calls = [];
		$this->declare_courier();
		$this->hooks['woodev_shipping_perform_toolbar_row_action'] = function ( $value, ...$args ) use ( $answer ) {
			$this->row_calls[] = $args;

			return $answer();
		};
	}

	public function test_a_row_button_is_run_with_the_ids_the_carrier_declared(): void {
		$this->hook_row_action( static fn() => Action_Result::success( '', 'Вызов отменён' ) );

		$result = $this->toolbar()->perform_row_action( 'call_courier', 'intakes', 'uuid-1', 'cancel' );

		$this->assertSame( [ [ 'call_courier', 'intakes', 'uuid-1', 'cancel' ] ], $this->row_calls );
		$this->assertSame(
			[
				'available' => true,
				'ok'        => true,
				'message'   => 'Вызов отменён',
			],
			$result
		);
	}

	public function test_a_row_button_the_dialog_does_not_offer_is_refused_and_never_forwarded(): void {
		$this->hook_row_action( static fn() => Action_Result::success() );
		$toolbar = $this->toolbar();

		foreach (
			[
				[ 'call_courier', 'intakes', 'uuid-1', 'erase' ],
				[ 'call_courier', 'intakes', 'uuid-2', 'cancel' ],
				[ 'call_courier', 'call', 'uuid-1', 'cancel' ],
				[ 'call_courier', 'nope', 'uuid-1', 'cancel' ],
				[ 'other', 'intakes', 'uuid-1', 'cancel' ],
			] as $args
		) {
			$this->assertSame( [ 'available' => false ], $toolbar->perform_row_action( ...$args ), implode( '/', $args ) );
		}

		$this->assertSame( [], $this->row_calls );
	}

	public function test_a_row_button_failure_carries_the_reason_and_a_throw_a_generic_sentence(): void {
		$this->hook_row_action( static fn() => Action_Result::failure( 'Заявка уже в работе' ) );

		$failed = $this->toolbar()->perform_row_action( 'call_courier', 'intakes', 'uuid-1', 'cancel' );

		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'Заявка уже в работе', $failed['message'] );

		$this->hook_row_action(
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		$threw = $this->toolbar()->perform_row_action( 'call_courier', 'intakes', 'uuid-1', 'cancel' );

		$this->assertTrue( $threw['available'] );
		$this->assertFalse( $threw['ok'] );
		$this->assertStringContainsString( 'временно недоступен', $threw['message'] );
	}

	public function test_a_row_button_nothing_hooks_fails_honestly(): void {
		$this->declare_courier();

		$result = $this->toolbar()->perform_row_action( 'call_courier', 'intakes', 'uuid-1', 'cancel' );

		$this->assertTrue( $result['available'] );
		$this->assertFalse( $result['ok'] );
	}
}

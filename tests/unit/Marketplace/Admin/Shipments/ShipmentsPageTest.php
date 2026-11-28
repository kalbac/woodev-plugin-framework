<?php
/**
 * The page of shipments against the records engine: the whole path of a shipment, from creating it to its document.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shipments;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Definition;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Rows
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Shipments
 * @covers \Woodev\Framework\Marketplace\Flows\Shipment_Service
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store
 */
final class ShipmentsPageTest extends Shipments_Test_Case {
	/**
	 * The definition passes the engine and speaks the marketplace's words.
	 *
	 * @return void
	 */
	public function test_the_definition_is_accepted_and_uses_the_marketplaces_words(): void {
		$definition = $this->definition()->to_array();
		$columns    = array_column( $definition['columns'], 'label', 'id' );
		$bulk       = array_column( $definition['bulk_actions'], 'label', 'id' );

		$this->assertSame( 'Поставка', $columns['name'] );
		$this->assertSame( 'Создать поставку', $bulk['create'] );
		$this->assertSame( [ 'shipping', 'cargo', 'doc-qr', 'handover', 'move' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [ 'create', 'refresh', 'bulk-move' ], array_column( $definition['bulk_actions'], 'id' ) );
		$this->assertSame( [ 'q', 'state', 'reship' ], array_column( $definition['filters'], 'id' ) );
		$this->assertNotNull( $definition['children'] );
		$this->assertStringNotContainsString( 'Ozon', (string) wp_json_encode( $definition ) );
	}

	/**
	 * Create → add from «Заказы» → choose the point and date → see what is missing → hand over → get the document.
	 *
	 * @return void
	 */
	public function test_the_whole_path_of_a_shipment(): void {
		$provider = $this->provider();

		// Create.
		$result = $provider->apply( $this->context(), 'create', [], [ 'name' => 'Понедельник' ] );
		$this->assertSame( 1, $result['accepted'] );

		$body = $this->list_body();
		$this->assertSame( 1, $body['total'] );
		$this->assertSame( $this->row_id( 'S-1' ), $body['rows'][0]['id'] );

		// Add the goods of orders 101 and 102 into it, from the orders table.
		$this->marketplace->needs = [ 'u101' => [ [ 'Код «Честный знак»', 'missing', true ] ] ];
		$action                   = $this->orders_action();
		$target                   = Wpdb_Shipment_Store::key( 'S-1' );
		$orders                   = [ $this->wc_orders[101], $this->wc_orders[102] ];

		$plan = $action->preview( $this->account(), $orders, [ 'target' => $target ] );
		$this->assertSame( 2, $plan['eligible'] );
		$this->assertNotContains( 'add_units', $this->marketplace->remote, 'A preview sends nothing.' );

		$added = $action->apply( $this->account(), $orders, [ 'target' => $target ] );
		$this->assertSame( 2, $added['accepted'] );
		$this->assertSame( 0, $added['failed'] );

		$rows = $this->child_rows( 'S-1' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Заказ № 101', $rows[0]['cells']['name'][1]['value']['label'], 'A unit is tied to its order through the registry.' );

		// What is missing keeps the hand-over off, on the button and on the server.
		$row      = $this->list_body()['rows'][0];
		$handover = array_column( $row['actions'], null, 'id' )['handover'];
		$this->assertFalse( $handover['enabled'] );
		$this->assertStringContainsString( 'Не хватает обязательных данных: Футболка, M — Код «Честный знак»', $handover['reason'] );
		$this->assertSame( 'Не заполнено: 1', $row['cells']['missing'][0]['value']['label'] );

		// Choose the pickup point (the list comes from the marketplace) and the date.
		$options = $provider->get_field_options( $this->context(), 'shipping', 'point', [ 'row_id' => $this->row_id( 'S-1' ), 'search' => '', 'cursor' => '' ] );
		$this->assertSame( [ [ 'value' => 'p-1', 'label' => 'Склад на Лесной' ] ], $options['options'] );

		$provider->apply( $this->context(), 'shipping', [ $this->row_id( 'S-1' ) ], [ 'point' => 'p-1', 'date' => '2026-11-28' ] );
		$this->assertSame( [ 'point' => 'p-1', 'date' => '2026-11-28' ], $this->marketplace->chosen );
		$this->assertSame( 'Склад на Лесной, 28 ноября', $this->list_body()['rows'][0]['cells']['shipping'][0]['value'] );

		// The document does not exist before the hand-over.
		$document = array_column( $this->list_body()['rows'][0]['actions'], null, 'id' )['doc-qr'];
		$this->assertFalse( $document['enabled'] );

		try {
			$provider->apply( $this->context(), 'handover', [ $this->row_id( 'S-1' ) ], [] );
			$this->fail( 'A missing code must stop the hand-over.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'Не хватает обязательных данных', $e->getMessage() );
		}

		$this->assertNotContains( 'hand_over', $this->marketplace->remote, 'Nothing is sent while something required is missing.' );

		// The code is entered on the marketplace's side; the hand-over reads the shipment first and sees it.
		$this->marketplace->shipments['S-1']['units']['u101']['requirements'] = [ [ 'Код «Честный знак»', 'met', true ] ];

		$handed = $provider->apply( $this->context(), 'handover', [ $this->row_id( 'S-1' ) ], [] );
		$this->assertSame( 1, $handed['accepted'] );
		$this->assertFalse( $this->marketplace->shipments['S-1']['open'] );

		$closed = $this->list_body()['rows'][0];
		$ids    = array_column( $closed['actions'], 'id' );
		$this->assertSame( [ 'doc-qr' ], $ids, 'A handed-over shipment keeps its documents only.' );
		$this->assertTrue( $closed['actions'][0]['enabled'] );

		// The document: the action gives the address, the download reads the file.
		$answer = $provider->apply( $this->context(), 'doc-qr', [ $this->row_id( 'S-1' ) ], [] );
		$this->assertStringContainsString( 'shipment=' . $target, $answer['download']['url'] );
		$this->assertSame( 'image/png', $this->shipment_service()->document( $this->account(), $target, 'qr' )->content_type() );
	}

	/**
	 * Reading the table, its rows, children and activity never calls the marketplace.
	 *
	 * @return void
	 */
	public function test_reading_the_table_never_calls_the_marketplace(): void {
		$this->stocked();
		$this->seller              = new Fake_Seller_Adapter();
		$this->marketplace->remote = [];
		$provider                  = $this->provider();

		$this->definition();
		$this->list_body( [ 'search' => 'S-1' ] );
		$this->list_body( [ 'filters' => [ 'state' => 'open', 'reship' => true ] ] );
		$this->child_rows( 'S-1' );
		$provider->get_activity( $this->context(), [ $this->row_id( 'S-1' ), $this->row_id( 'S-1', 'u101' ) ] );
		$provider->preview( $this->context(), 'cargo', [ $this->row_id( 'S-2' ) ], [ 'total' => 1 ] );
		$this->orders_action()->row_states( $this->account(), $this->wc_orders[102] );

		$this->assertSame( [], $this->marketplace->remote );
	}

	/**
	 * The two filters of the page and the search over names, numbers and order ids.
	 *
	 * @return void
	 */
	public function test_filters_and_search(): void {
		$this->stocked();
		$this->marketplace->shipments['S-2']['open'] = false;
		$this->marketplace->reship                   = [ 'S-1' => [ 'u101' ] ];
		$this->refresh();

		$this->assertSame( [ 'S-1' ], $this->names_of( [ 'filters' => [ 'reship' => true ] ] ) );
		$this->assertSame( [ 'S-2' ], $this->names_of( [ 'filters' => [ 'state' => 'closed' ] ] ) );
		$this->assertSame( [ 'S-1' ], $this->names_of( [ 'search' => '101' ] ), 'The order id of a unit finds its shipment.' );
		$this->assertSame( [ 'S-2' ], $this->names_of( [ 'search' => 'Вторая' ] ) );

		$body = $this->list_body();
		$this->assertSame( [ 1, 1 ], array_column( $body['facets']['state'], 'count' ) );

		$children = $this->child_rows( 'S-1' );
		$this->assertSame( 'Нужна повторная отгрузка', $children[0]['cells']['state'][1]['value']['label'] );
	}

	/**
	 * A movable unit goes into another open shipment; one the marketplace does not let move has no button.
	 *
	 * @return void
	 */
	public function test_moving_units_between_shipments(): void {
		$this->stocked();
		$this->marketplace->shipments['S-1']['open']                    = false;
		$this->marketplace->shipments['S-1']['units']['u101']['movable'] = true;
		$this->refresh();

		$rows = $this->child_rows( 'S-1' );
		$this->assertTrue( $rows[0]['selectable'] );
		$this->assertSame( [ 'move' ], array_column( $rows[0]['actions'], 'id' ) );

		$options = $this->provider()->get_field_options( $this->context(), 'move', 'target', [ 'row_id' => $rows[0]['id'], 'search' => '', 'cursor' => '' ] );
		$this->assertSame( [ Wpdb_Shipment_Store::key( 'S-2' ) ], array_column( $options['options'], 'value' ), 'Open shipments, the source left out.' );

		$payload = [ 'target' => Wpdb_Shipment_Store::key( 'S-2' ) ];
		$plan    = $this->provider()->preview( $this->context(), 'bulk-move', [ $rows[0]['id'] ], $payload );
		$this->assertSame( 1, $plan['eligible'] );

		$result = $this->provider()->apply( $this->context(), 'bulk-move', [ $rows[0]['id'] ], $payload );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( [ $rows[0]['id'] ], $result['removed_ids'] );
		$this->assertArrayHasKey( 'u101', $this->marketplace->shipments['S-2']['units'] );
		$this->assertCount( 2, $this->child_rows( 'S-2' ) );
		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'bulk-move' ), $result );
		$this->assertIsArray( $body, 'The engine accepts the answer.' );
	}

	/**
	 * A unit not mentioned by the answer is failed, never added; a refused one keeps its reason.
	 *
	 * @return void
	 */
	public function test_the_answer_is_taken_unit_by_unit(): void {
		$this->provider()->apply( $this->context(), 'create', [], [ 'name' => 'Одна' ] );
		$this->marketplace->unanswered = [ 'u101' ];
		$this->marketplace->refusals   = [ 'u102' => 'Заказ уже отменён.' ];

		$result = $this->orders_action()->apply( $this->account(), [ $this->wc_orders[101], $this->wc_orders[102] ], [ 'target' => Wpdb_Shipment_Store::key( 'S-1' ) ] );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertContains( 'Заказ уже отменён.', array_column( $result['groups'], 'reason' ) );
		$this->assertSame( 2, $result['requested'] );
	}

	/**
	 * A new shipment made from «Заказы», and a selection that mixes cargo kinds is refused before any request.
	 *
	 * @return void
	 */
	public function test_a_new_shipment_from_the_orders_and_a_mixed_selection(): void {
		$mixed = $this->orders_action()->apply( $this->account(), [ $this->wc_orders[101], $this->wc_orders[103] ], [ 'target' => 'new', 'name' => 'Новая' ] );

		$this->assertSame( 0, $mixed['accepted'] );
		$this->assertSame( 2, $mixed['skipped'] );
		$this->assertSame( [], $this->marketplace->remote, 'Not even the shipment is created for a selection the marketplace would refuse.' );

		$added = $this->orders_action()->apply( $this->account(), [ $this->wc_orders[101] ], [ 'target' => 'new', 'name' => 'Новая' ] );

		$this->assertSame( 1, $added['accepted'] );
		$this->assertSame( 'Новая', $this->marketplace->shipments['S-1']['name'] );
		$this->assertCount( 1, $this->child_rows( 'S-1' ) );
	}

	/**
	 * The orders action: its place in the definition, the list of choices, and no button for an order with no unit.
	 *
	 * @return void
	 */
	public function test_the_orders_table_gains_the_action(): void {
		$action     = $this->orders_action();
		$definition = $action->extend_definition(
			[
				'row_actions'  => [ [ 'id' => 'open' ], [ 'id' => 'cancel' ] ],
				'bulk_actions' => [],
				'messages'     => [],
			]
		);

		$orders = Site_Orders_Definition::build( [ [ 'value' => '1', 'label' => 'Основной' ] ], [], [ 'open', 'cancel' ] );
		$this->assertContains( 'ship-add', array_column( Records_Definition::from_array( $action->extend_definition( $orders ) )->to_array()['row_actions'], 'id' ), 'The engine accepts the extended «Заказы» table.' );

		$this->assertSame( [ 'open', 'ship-add', 'cancel' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [ 'bulk-ship-add' ], array_column( $definition['bulk_actions'], 'id' ) );
		$this->assertSame( 'Добавить в поставку', $definition['row_actions'][1]['label'] );
		$this->assertNotEmpty( $definition['messages']['bulk_apply'] );

		$this->assertSame( [ 'new' ], array_column( $action->field_options( $this->account(), 'target', '' ), 'value' ) );
		$this->assertSame( [], $action->row_states( $this->account(), $this->wc_orders[104] ) );
		$this->assertTrue( $action->row_states( $this->account(), $this->wc_orders[101] )[0]['enabled'] );

		$this->expectException( Action_Input_Exception::class );
		$action->apply( $this->account(), [ $this->wc_orders[101] ], [ 'target' => 'new', 'name' => '  ' ] );
	}

	/**
	 * Without a licence every change is off on the screen and refused before any remote call.
	 *
	 * @return void
	 */
	public function test_without_a_licence_nothing_is_sent(): void {
		$this->stocked();
		$this->licensed            = false;
		$this->marketplace->remote = [];

		$actions = array_column( $this->list_body()['rows'][0]['actions'], null, 'id' );
		$this->assertFalse( $actions['shipping']['enabled'] );
		$this->assertFalse( $actions['doc-qr']['enabled'] );
		$this->assertStringContainsString( 'Лицензия', $actions['handover']['reason'] );

		foreach ( [ [ 'create', [], [ 'name' => 'Х' ] ], [ 'handover', [ $this->row_id( 'S-1' ) ], [] ], [ 'refresh', [], [] ] ] as list( $id, $ids, $payload ) ) {
			try {
				$this->provider()->apply( $this->context(), $id, $ids, $payload );
				$this->fail( $id . ' must be refused.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsString( 'Лицензия', $e->getMessage() );
			}
		}

		$this->assertSame( [], $this->marketplace->remote );
	}

	/**
	 * While an action is at the marketplace the shipment is held: a second one is refused and the row says so.
	 *
	 * @return void
	 */
	public function test_a_shipment_takes_one_action_at_a_time(): void {
		$this->stocked();

		$seen                          = [];
		$this->marketplace->meanwhile = function () use ( &$seen ): void {
			$seen['activity'] = $this->list_body( [ 'search' => 'S-1' ] )['rows'][0]['activity'];

			try {
				$this->provider()->apply( $this->context(), 'cargo', [ $this->row_id( 'S-1' ) ], [ 'total' => 1 ] );
			} catch ( Action_State_Exception $e ) {
				$seen['refused'] = $e->getMessage();
			}
		};

		$this->provider()->apply( $this->context(), 'shipping', [ $this->row_id( 'S-1' ) ], [ 'point' => 'p-1', 'date' => '2026-11-28' ] );

		$this->assertTrue( $seen['activity']['locked'] );
		$this->assertStringContainsString( 'занята', $seen['refused'] );
		$this->assertNotContains( 'add_cargo_places', $this->marketplace->remote );
		$this->assertFalse( $this->list_body( [ 'search' => 'S-1' ] )['rows'][0]['activity']['locked'], 'The lock goes with the action.' );
	}

	/**
	 * A lost answer is never reported as success; the marketplace's own refusal is read as it is.
	 *
	 * @return void
	 */
	public function test_failures_of_the_marketplace(): void {
		$this->stocked();
		$id = [ $this->row_id( 'S-1' ) ];

		$this->marketplace->failures['add_cargo_places'] = new Shipment_Refused( 'Поставка не на пункт выдачи.' );

		try {
			$this->provider()->apply( $this->context(), 'cargo', $id, [ 'total' => 2 ] );
			$this->fail( 'Refused.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertSame( 'Поставка не на пункт выдачи.', $e->getMessage() );
		}

		$this->marketplace->failures['add_cargo_places'] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );

		try {
			$this->provider()->apply( $this->context(), 'cargo', $id, [ 'total' => 2 ] );
			$this->fail( 'Unanswered.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'действие могло выполниться', $e->getMessage() );
		}

		$this->assertFalse( $this->list_body( [ 'search' => 'S-1' ] )['rows'][0]['activity']['locked'] );
	}

	/**
	 * Input is judged before anything is sent.
	 *
	 * @return void
	 */
	public function test_input_is_judged_locally(): void {
		$this->stocked();
		$this->marketplace->remote = [];

		foreach ( [ [ 'create', [], [ 'name' => ' ' ] ], [ 'cargo', [ $this->row_id( 'S-1' ) ], [ 'total' => 0 ] ], [ 'cargo', [ $this->row_id( 'S-1' ) ], [ 'total' => 1.5 ] ], [ 'bulk-move', [ $this->row_id( 'S-1', 'u101' ) ], [ 'target' => 'nonsense' ] ] ] as list( $id, $ids, $payload ) ) {
			try {
				$this->provider()->apply( $this->context(), $id, $ids, $payload );
				$this->fail( $id . ' must be refused.' );
			} catch ( Action_Input_Exception $e ) {
				$this->assertNotEmpty( $e->get_errors() );
			}
		}

		$this->assertSame( [], $this->marketplace->remote );
	}

	/**
	 * A row id of another account, an unknown shipment and a unit id where a shipment is expected are refused.
	 *
	 * @return void
	 */
	public function test_ids_are_authorised(): void {
		$this->stocked();
		$provider = $this->provider();

		$this->assertTrue( $provider->authorize( 'handover', $this->context(), [ $this->row_id( 'S-1' ) ] ) );
		$this->assertFalse( $provider->authorize( 'handover', $this->context(), [ ( $this->account_id + 1 ) . ':' . Wpdb_Shipment_Store::key( 'S-1' ) ] ) );
		$this->assertFalse( $provider->authorize( 'handover', $this->context(), [ $this->row_id( 'S-9' ) ] ) );
		$this->assertTrue( $provider->authorize( 'activity', $this->context(), [ $this->row_id( 'S-9' ) ] ) );

		$this->expectException( Action_State_Exception::class );
		$provider->apply( $this->context(), 'handover', [ $this->row_id( 'S-1', 'u101' ) ], [] );
	}

	/**
	 * A seller action offered on a unit shows on the child row and runs on that unit alone, for an order of the account the user
	 * may edit.
	 *
	 * @return void
	 */
	public function test_a_unit_action_on_a_child_row(): void {
		$this->stocked();
		$this->seller      = new Fake_Seller_Adapter();
		$this->units[101]  = [ [ 'u101', 'Футболка, M', 'confirm', null ] ];

		$actions = $this->actions_of( $this->child_rows( 'S-1' ), $this->row_id( 'S-1', 'u101' ) );
		$this->assertArrayHasKey( 'unit-marking', $actions );
		$this->assertTrue( $actions['unit-marking']['enabled'] );

		$result = $this->provider()->apply( $this->context(), 'unit-marking', [ $this->row_id( 'S-1', 'u101' ) ], [ 'sgtin' => '0104600000000000' ] );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 'marking', $this->seller->calls[0]->action_id() );

		$this->forbidden = [ 101 ];
		$this->assertArrayNotHasKey( 'unit-marking', $this->actions_of( $this->child_rows( 'S-1' ), $this->row_id( 'S-1', 'u101' ) ) );

		$this->expectException( Action_State_Exception::class );
		$this->provider()->apply( $this->context(), 'unit-marking', [ $this->row_id( 'S-1', 'u101' ) ], [ 'sgtin' => '0104600000000000' ] );
	}

	/**
	 * Two shipments, `S-1` holding order 101's goods and `S-2` holding order 102's, read into the shop.
	 *
	 * @return void
	 */
	private function stocked(): void {
		$this->marketplace->shipments['S-1'] = Fake_Shipment_Marketplace::remote_shipment( 'Первая' );
		$this->marketplace->shipments['S-2'] = Fake_Shipment_Marketplace::remote_shipment( 'Вторая' );

		foreach ( [
			'S-1' => 101,
			'S-2' => 102,
		] as $shipment => $order ) {
			$this->marketplace->shipments[ $shipment ]['units'][ 'u' . $order ] = [
				'posting'      => 'P-' . $order,
				'label'        => 'Футболка, M',
				'movable'      => false,
				'requirements' => [],
			];
		}

		$this->refresh();
	}

	/**
	 * @param array<string,mixed> $query Query.
	 * @return string[] Ids of the listed shipments.
	 */
	private function names_of( array $query ): array {
		return array_map(
			static function ( array $row ): string {
				return $row['cells']['name'][1]['value'];
			},
			$this->list_body( $query )['rows']
		);
	}
}

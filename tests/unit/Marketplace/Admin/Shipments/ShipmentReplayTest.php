<?php
/**
 * A change of a shipment is sent once: a double click or a repeated request does nothing twice.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shipments;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * Every request here goes through the records provider (or the «Заказы» action), the way a repeated browser request would.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Shipment_Service
 */
final class ShipmentReplayTest extends Shipments_Test_Case {
	/**
	 * The same creation sent again makes no second shipment; another name does.
	 *
	 * @return void
	 */
	public function test_a_repeated_creation_makes_one_shipment(): void {
		$this->act( 'create', [], [ 'name' => 'Понедельник' ] );

		$this->assertStringContainsString( 'уже создана', $this->refusal_of( 'create', [], [ 'name' => 'Понедельник' ] ) );
		$this->assertSame( 1, $this->calls( 'create_shipment' ) );
		$this->assertCount( 1, $this->marketplace->shipments );

		$this->act( 'create', [], [ 'name' => 'Вторник' ] );
		$this->assertCount( 2, $this->marketplace->shipments );

		$this->now += Wpdb_Seller_Action_Store::KEPT_TTL + 1;
		$this->act( 'create', [], [ 'name' => 'Понедельник' ] );
		$this->assertCount( 3, $this->marketplace->shipments, 'An hour later the same name is a new shipment.' );
	}

	/**
	 * A creation whose answer was lost is not sent again: the merchant is asked to check.
	 *
	 * @return void
	 */
	public function test_a_creation_without_an_answer_is_not_repeated(): void {
		$this->marketplace->lost['create_shipment'] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );

		$this->assertStringContainsString( 'могло выполниться', $this->refusal_of( 'create', [], [ 'name' => 'Понедельник' ] ) );

		$this->marketplace->lost = [];

		$this->assertStringContainsString( 'ответа не было', $this->refusal_of( 'create', [], [ 'name' => 'Понедельник' ] ) );
		$this->assertSame( 1, $this->calls( 'create_shipment' ) );
		$this->assertCount( 1, $this->marketplace->shipments );
	}

	/**
	 * A change the marketplace refused, throttled, or that never left the shop can be sent again at once.
	 *
	 * @return void
	 */
	public function test_a_change_that_did_not_happen_can_be_repeated(): void {
		$failures = [
			new Shipment_Refused( 'Нельзя.' ),
			Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow', 30 ),
			Fake_Remote_Exception::of( Remote_Error::RETRY, 'held back', null, false ),
		];

		foreach ( $failures as $failure ) {
			$this->marketplace->failures['create_shipment'] = $failure;
			$this->refusal_of( 'create', [], [ 'name' => 'Понедельник' ] );
		}

		$this->marketplace->failures = [];
		$this->act( 'create', [], [ 'name' => 'Понедельник' ] );

		$this->assertSame( 4, $this->calls( 'create_shipment' ) );
		$this->assertCount( 1, $this->marketplace->shipments );
	}

	/**
	 * The same number of cargo places asked again adds nothing; a larger one adds the difference.
	 *
	 * @return void
	 */
	public function test_repeated_cargo_places_are_added_once(): void {
		$this->stocked();
		$id = [ $this->row_id( 'S-1' ) ];

		$this->act( 'cargo', $id, [ 'total' => 2 ] );
		$again = $this->act( 'cargo', $id, [ 'total' => 2 ] );

		$this->assertStringContainsString( 'ничего не отправлено', $again['messages']['action_status_done'] );
		$this->assertSame( 1, $this->calls( 'add_cargo_places' ) );
		$this->assertCount( 2, $this->marketplace->shipments['S-1']['places'] );

		$this->act( 'cargo', $id, [ 'total' => 3 ] );
		$this->assertCount( 3, $this->marketplace->shipments['S-1']['places'] );

		$this->assertStringContainsString( 'убрать лишние отсюда нельзя', $this->refusal_of( 'cargo', $id, [ 'total' => 1 ] ) );
		$this->assertStringContainsString( 'не больше 10', $this->refusal_of( 'cargo', $id, [ 'total' => 14 ] ) );
		$this->assertSame( 2, $this->calls( 'add_cargo_places' ) );
	}

	/**
	 * Cargo places without an answer are not asked for again, even while the marketplace does not show them yet.
	 *
	 * @return void
	 */
	public function test_cargo_places_without_an_answer_are_not_repeated(): void {
		$this->stocked();
		$id                                          = [ $this->row_id( 'S-1' ) ];
		$this->marketplace->failures['add_cargo_places'] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		$this->refusal_of( 'cargo', $id, [ 'total' => 2 ] );
		$this->marketplace->failures = [];

		$this->assertStringContainsString( 'ответа не было', $this->refusal_of( 'cargo', $id, [ 'total' => 2 ] ) );
		$this->assertSame( 1, $this->calls( 'add_cargo_places' ) );
	}

	/**
	 * The shipping choice and the hand-over: sent once while their outcome is unknown.
	 *
	 * @return void
	 */
	public function test_the_shipping_choice_and_the_hand_over_are_sent_once(): void {
		$this->stocked();
		$id      = [ $this->row_id( 'S-1' ) ];
		$choice  = [ 'point' => 'p-1', 'date' => '2026-11-28' ];

		$this->act( 'shipping', $id, $choice );
		$this->assertStringContainsString( 'уже отправлено', $this->refusal_of( 'shipping', $id, array_reverse( $choice, true ) ), 'The order of the fields is no new choice.' );
		$this->assertSame( 1, $this->calls( 'choose_shipping' ) );

		$this->marketplace->failures['hand_over'] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		$this->refusal_of( 'handover', $id, [] );
		$this->marketplace->failures = [];

		$this->assertStringContainsString( 'ответа не было', $this->refusal_of( 'handover', $id, [] ) );
		$this->assertSame( 1, $this->calls( 'hand_over' ) );
	}

	/**
	 * Units whose add got no answer are left out of the next request; a refused unit can be sent again.
	 *
	 * @return void
	 */
	public function test_units_are_added_once(): void {
		$this->act( 'create', [], [ 'name' => 'Одна' ] );
		$orders  = [ $this->wc_orders[101], $this->wc_orders[102] ];
		$payload = [ 'target' => Wpdb_Shipment_Store::key( 'S-1' ) ];

		$this->marketplace->unanswered = [ 'u101' ];
		$this->marketplace->refusals   = [ 'u102' => 'Пока нельзя.' ];
		$this->orders_action()->apply( $this->account(), $orders, $payload );

		$this->marketplace->unanswered = [];
		$this->marketplace->refusals   = [];
		$second                        = $this->orders_action()->apply( $this->account(), $orders, $payload );

		$this->assertSame( 1, $second['accepted'], 'The refused unit goes.' );
		$this->assertSame( 1, $second['skipped'], 'The unanswered one is held.' );
		$this->assertSame( [ 'u102' ], array_keys( $this->marketplace->shipments['S-1']['units'] ) );

		$third = $this->orders_action()->apply( $this->account(), $orders, $payload );

		$this->assertSame( 0, $third['accepted'] );
		$this->assertSame( 2, $this->calls( 'add_units' ), 'Nothing left to send: no request.' );
	}

	/**
	 * A move without an answer is not repeated, and a unit moved away can be moved back.
	 *
	 * @return void
	 */
	public function test_moves_are_sent_once_and_can_go_back(): void {
		$this->stocked();
		$this->marketplace->shipments['S-1']['units']['u101']['movable'] = true;
		$this->refresh();

		$there = [ 'target' => Wpdb_Shipment_Store::key( 'S-2' ) ];
		$back  = [ 'target' => Wpdb_Shipment_Store::key( 'S-1' ) ];

		$this->act( 'move', [ $this->row_id( 'S-1', 'u101' ) ], $there );
		$this->act( 'move', [ $this->row_id( 'S-2', 'u101' ) ], $back );
		$result = $this->act( 'move', [ $this->row_id( 'S-1', 'u101' ) ], $there );

		$this->assertSame( 1, $result['accepted'], 'There, back and there again are three moves.' );

		$this->act( 'move', [ $this->row_id( 'S-2', 'u101' ) ], $back );
		$this->marketplace->failures['move_units'] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		$this->refusal_of( 'move', [ $this->row_id( 'S-1', 'u101' ) ], $there );
		$this->marketplace->failures = [];
		$calls                       = $this->calls( 'move_units' );
		$repeat                      = $this->act( 'move', [ $this->row_id( 'S-1', 'u101' ) ], $there );

		$this->assertSame( 0, $repeat['accepted'] );
		$this->assertStringContainsString( 'ответа не было', $repeat['groups'][0]['reason'] );
		$this->assertSame( $calls, $this->calls( 'move_units' ) );
	}

	/**
	 * When the claim cannot be written, nothing is sent.
	 *
	 * @return void
	 */
	public function test_nothing_is_sent_without_a_claim(): void {
		$this->db->fail_query = static function ( string $query ): bool {
			return false !== strpos( $query, Sqlite_Tables::table( 'seller_actions' ) ) && 0 === strpos( $query, 'INSERT' );
		};

		$this->assertStringContainsString( 'ничего не отправлено', $this->refusal_of( 'create', [], [ 'name' => 'Одна' ] ) );
		$this->assertSame( [], $this->marketplace->remote );
	}

	/**
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 */
	private function act( string $action, array $ids, array $payload ): array {
		return $this->provider()->apply( $this->context(), $action, $ids, $payload );
	}

	/**
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return string The refusal.
	 */
	private function refusal_of( string $action, array $ids, array $payload ): string {
		try {
			$this->act( $action, $ids, $payload );
		} catch ( Action_State_Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( $action . ' must be refused.' );
	}

	/**
	 * @param string $method Remote method.
	 * @return int How often it was called.
	 */
	private function calls( string $method ): int {
		return count( array_keys( $this->marketplace->remote, $method, true ) );
	}

	/**
	 * Two open shipments, each with the goods of one order, read into the shop.
	 *
	 * @return void
	 */
	private function stocked(): void {
		foreach ( [
			'S-1' => 101,
			'S-2' => 102,
		] as $shipment => $order ) {
			$this->marketplace->shipments[ $shipment ] = Fake_Shipment_Marketplace::remote_shipment( $shipment );

			$this->marketplace->shipments[ $shipment ]['units'][ 'u' . $order ] = [
				'posting'      => 'P-' . $order,
				'label'        => 'Футболка, M',
				'movable'      => false,
				'requirements' => [],
			];
		}

		$this->refresh();
	}
}

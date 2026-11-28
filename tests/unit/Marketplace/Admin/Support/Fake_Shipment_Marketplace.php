<?php
/**
 * A marketplace with shipments, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;
use Woodev\Framework\Marketplace\Shipments\Shipment;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Candidate;
use Woodev\Framework\Marketplace\Shipments\Shipment_Cargo_Place;
use Woodev\Framework\Marketplace\Shipments\Shipment_Contents;
use Woodev\Framework\Marketplace\Shipments\Shipment_Document_Type;
use Woodev\Framework\Marketplace\Shipments\Shipment_File;
use Woodev\Framework\Marketplace\Shipments\Shipment_Listing;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;
use Woodev\Framework\Marketplace\Shipments\Shipment_Requirement;
use Woodev\Framework\Marketplace\Shipments\Shipment_Result;
use Woodev\Framework\Marketplace\Shipments\Shipment_Unit;

/**
 * Keeps «the marketplace» in {@see self::$shipments} and counts every call that would go over the network in {@see self::$remote}
 * (the method name per call). The answers from stored data are not counted: the screens may ask them as often as they like.
 *
 * The cargo of a shipment is one kind: units of a second kind are refused for it, and a selection that mixes kinds is refused
 * whole. A unit of an order is read from the order's id: order 101 holds unit `u101` of kind `small`, order 102 unit `u102` of
 * kind `small`, order 103 unit `u103` of kind `big`; any other order holds none.
 */
final class Fake_Shipment_Marketplace implements Shipment_Batches {
	/**
	 * Network calls, in order.
	 *
	 * @var string[]
	 */
	public $remote = [];

	/**
	 * Shipments by id: `name`, `open`, `shipping`, `kind`, `places` (ids) and `units` (id => `posting`, `label`, `movable`,
	 * `requirements` as `[ label, state, blocking ]`).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public $shipments = [];

	/**
	 * Shipment id => unit ids to ship again.
	 *
	 * @var array<string,string[]>
	 */
	public $reship = [];

	/**
	 * Requirements a unit gets when it is added: unit id => list of `[ label, state, blocking ]`.
	 *
	 * @var array<string,array<int,array{0:string,1:string,2:bool}>>
	 */
	public $needs = [];

	/**
	 * Page size of the list.
	 *
	 * @var int
	 */
	public $page = 100;

	/**
	 * Most cargo places per request.
	 *
	 * @var int
	 */
	public $places = 10;

	/**
	 * Thrown after a change was made, per method: the marketplace did it and the answer was lost.
	 *
	 * @var array<string,\Throwable>
	 */
	public $lost = [];

	/**
	 * Thrown by the next remote call instead of an answer, per method.
	 *
	 * @var array<string,\Throwable>
	 */
	public $failures = [];

	/**
	 * Unit ids the answer of an add or a move does not mention.
	 *
	 * @var string[]
	 */
	public $unanswered = [];

	/**
	 * Unit id => the refusal of an add or a move.
	 *
	 * @var array<string,string>
	 */
	public $refusals = [];

	/**
	 * Runs while a call is at the marketplace, once: another request arriving meanwhile.
	 *
	 * @var callable|null
	 */
	public $meanwhile;

	/**
	 * The values of the last shipping choice.
	 *
	 * @var array<string,string>
	 */
	public $chosen = [];

	/**
	 * @var int
	 */
	private $next = 1;

	public function list_shipments( Account $account, string $cursor ): Shipment_Listing {
		$this->called( 'list_shipments' );

		$ids    = array_keys( $this->shipments );
		$offset = (int) $cursor;
		$slice  = array_slice( $ids, $offset, $this->page );
		$next   = $offset + $this->page < count( $ids ) ? (string) ( $offset + $this->page ) : '';

		return new Shipment_Listing(
			array_map(
				function ( $id ): Shipment {
					return $this->header( (string) $id );
				},
				$slice
			),
			$next
		);
	}

	public function read_shipment( Account $account, Shipment $shipment ): Shipment_Contents {
		$this->called( 'read_shipment' );

		$id    = $shipment->id();
		$units = [];

		foreach ( $this->shipments[ $id ]['units'] as $unit_id => $unit ) {
			$units[] = new Shipment_Unit(
				(string) $unit_id,
				$unit['posting'],
				$unit['label'],
				[
					'status_label' => 'Собирается',
					'movable'      => $unit['movable'],
					'requirements' => array_map(
						static function ( array $need ): Shipment_Requirement {
							return new Shipment_Requirement( $need[0], $need[1], $need[2] );
						},
						$unit['requirements']
					),
				]
			);
		}

		return new Shipment_Contents(
			$this->header( $id ),
			$units,
			array_map(
				static function ( string $place ): Shipment_Cargo_Place {
					return new Shipment_Cargo_Place( $place );
				},
				$this->shipments[ $id ]['places']
			)
		);
	}

	public function units_to_reship( Account $account ): array {
		$this->called( 'units_to_reship' );

		return $this->reship;
	}

	public function shipment_candidates( Account $account, array $orders, ?Shipment $target ): array {
		$found = [];

		foreach ( $orders as $order ) {
			$id    = (int) $order->get_id();
			$kinds = [
				101 => 'small',
				102 => 'small',
				103 => 'big',
			];

			if ( isset( $kinds[ $id ] ) ) {
				$found[] = [ $id, 'u' . $id, $kinds[ $id ] ];
			}
		}

		$mixed = count( array_unique( array_column( $found, 2 ) ) ) > 1;

		return array_map(
			static function ( array $unit ) use ( $mixed, $target ): Shipment_Candidate {
				$refusal = $mixed ? 'В одной поставке товары одного типа груза.' : '';

				if ( '' === $refusal && null !== $target && '' !== $target->fact( 'kind' ) && $target->fact( 'kind' ) !== $unit[2] ) {
					$refusal = 'Тип груза не подходит этой поставке.';
				}

				return new Shipment_Candidate( $unit[0], $unit[1], 'Футболка, M', $refusal );
			},
			$found
		);
	}

	public function move_refusal( Shipment $from, Shipment $to, Shipment_Unit $unit ): string {
		return '' !== $to->fact( 'kind' ) && '' !== $from->fact( 'kind' ) && $to->fact( 'kind' ) !== $from->fact( 'kind' ) ? 'Тип груза не подходит этой поставке.' : '';
	}

	public function shipping_fields( Account $account ): array {
		return [
			new Seller_Action_Field( 'point', 'Пункт отгрузки', Seller_Action_Field::SELECT, [ 'required' => true, 'options_source' => true ] ),
			new Seller_Action_Field( 'date', 'Дата отгрузки', Seller_Action_Field::SELECT, [ 'required' => true, 'options' => [ '2026-11-28' => '28 ноября' ] ] ),
		];
	}

	public function hand_over_refusal( Shipment $shipment, Shipment_Contents $contents ): string {
		return '' === $shipment->shipping_label() ? 'Выберите пункт и дату отгрузки.' : '';
	}

	public function max_cargo_places(): int {
		return $this->places;
	}

	public function cargo_place_refusal( Shipment $shipment, Shipment_Contents $contents ): string {
		return [] === $contents->units() ? 'Сначала добавьте товары.' : '';
	}

	public function shipment_documents(): array {
		return [ new Shipment_Document_Type( 'qr', 'Скачать QR-код', 'QR' ) ];
	}

	public function document_refusal( Shipment $shipment, Shipment_Contents $contents, string $document_id ): string {
		return $shipment->is_open() ? 'QR-код появится после передачи.' : '';
	}

	public function create_shipment( Account $account, string $name ): Shipment {
		$this->called( 'create_shipment' );

		$id = 'S-' . $this->next++;

		$this->shipments[ $id ] = self::remote_shipment( $name );

		$this->lose_answer( 'create_shipment' );

		return $this->header( $id );
	}

	public function add_units( Account $account, Shipment $shipment, array $candidates ): array {
		$this->called( 'add_units' );

		$results = [];

		foreach ( $candidates as $candidate ) {
			$unit_id = $candidate->unit_id();

			if ( in_array( $unit_id, $this->unanswered, true ) ) {
				continue;
			}

			if ( isset( $this->refusals[ $unit_id ] ) ) {
				$results[] = Shipment_Result::refused( $unit_id, $this->refusals[ $unit_id ] );
				continue;
			}

			$this->shipments[ $shipment->id() ]['kind']              = 'u103' === $unit_id ? 'big' : 'small';
			$this->shipments[ $shipment->id() ]['units'][ $unit_id ] = [
				'posting'      => 'P-' . $candidate->order_id(),
				'label'        => $candidate->label(),
				'movable'      => false,
				'requirements' => $this->needs[ $unit_id ] ?? [],
			];
			$results[] = Shipment_Result::done( $unit_id );
		}

		$this->lose_answer( 'add_units' );

		return $results;
	}

	public function move_units( Account $account, Shipment $from, Shipment $to, array $units ): array {
		$this->called( 'move_units' );

		$results = [];

		foreach ( $units as $unit ) {
			if ( isset( $this->refusals[ $unit->id() ] ) ) {
				$results[] = Shipment_Result::refused( $unit->id(), $this->refusals[ $unit->id() ] );
				continue;
			}

			$this->shipments[ $to->id() ]['units'][ $unit->id() ] = $this->shipments[ $from->id() ]['units'][ $unit->id() ];
			unset( $this->shipments[ $from->id() ]['units'][ $unit->id() ] );
			$results[] = Shipment_Result::done( $unit->id() );
		}

		$this->lose_answer( 'move_units' );

		return $results;
	}

	public function shipping_options( Account $account, Shipment $shipment, string $field_id, string $search ): array {
		$this->called( 'shipping_options' );

		return 'point' === $field_id ? [ 'p-1' => 'Склад на Лесной' ] : [];
	}

	public function choose_shipping( Account $account, Shipment $shipment, array $values ): void {
		$this->called( 'choose_shipping' );

		$this->chosen                                   = $values;
		$this->shipments[ $shipment->id() ]['shipping'] = 'Склад на Лесной, 28 ноября';

		$this->lose_answer( 'choose_shipping' );
	}

	public function add_cargo_places( Account $account, Shipment $shipment, int $count ): void {
		$this->called( 'add_cargo_places' );

		for ( $i = 0; $i < $count; $i++ ) {
			$this->shipments[ $shipment->id() ]['places'][] = 'BOX-' . ( count( $this->shipments[ $shipment->id() ]['places'] ) + 1 );
		}

		$this->lose_answer( 'add_cargo_places' );
	}

	public function hand_over( Account $account, Shipment $shipment ): void {
		$this->called( 'hand_over' );

		$this->shipments[ $shipment->id() ]['open'] = false;

		$this->lose_answer( 'hand_over' );
	}

	public function shipment_document( Account $account, Shipment $shipment, string $document_id ): Shipment_File {
		$this->called( 'shipment_document' );

		if ( $shipment->is_open() ) {
			throw new Shipment_Refused( 'Поставка ещё открыта.' );
		}

		return new Shipment_File( 'PNG', 'image/png', $shipment->id() . '-qr.png' );
	}

	/**
	 * A shipment as the marketplace holds it.
	 *
	 * @param string $name Name.
	 * @param bool   $open Whether it is open.
	 * @return array<string,mixed>
	 */
	public static function remote_shipment( string $name, bool $open = true ): array {
		return [
			'name'     => $name,
			'open'     => $open,
			'shipping' => '',
			'kind'     => '',
			'places'   => [],
			'units'    => [],
		];
	}

	/**
	 * @param string $id Shipment id.
	 * @return Shipment
	 */
	private function header( string $id ): Shipment {
		$shipment = $this->shipments[ $id ];

		return new Shipment(
			$id,
			$shipment['name'],
			$shipment['open'],
			[
				'status_label'   => $shipment['open'] ? 'Собирается' : 'Принята',
				'shipping_label' => $shipment['shipping'],
				'created_at'     => 1794139200 + (int) substr( $id, -1 ),
				'facts'          => [ 'kind' => $shipment['kind'] ],
				'still_changing' => $shipment['changing'] ?? false,
			]
		);
	}

	/**
	 * Throws what the test scripted for a change that was made but not answered.
	 *
	 * @param string $method Method.
	 * @return void
	 */
	private function lose_answer( string $method ): void {
		if ( isset( $this->lost[ $method ] ) ) {
			throw $this->lost[ $method ];
		}
	}

	/**
	 * Records a call and does what the test scripted for it.
	 *
	 * @param string $method Method.
	 * @return void
	 */
	private function called( string $method ): void {
		$this->remote[] = $method;

		if ( isset( $this->failures[ $method ] ) ) {
			throw $this->failures[ $method ];
		}

		if ( null !== $this->meanwhile && in_array( $method, [ 'add_units', 'hand_over', 'choose_shipping' ], true ) ) {
			$hook            = $this->meanwhile;
			$this->meanwhile = null;
			$hook();
		}
	}
}

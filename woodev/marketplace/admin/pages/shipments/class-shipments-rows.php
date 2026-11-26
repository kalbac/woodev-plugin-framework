<?php
/**
 * The rows of the table of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Contents;
use Woodev\Framework\Marketplace\Shipments\Shipment_Requirement;
use Woodev\Framework\Marketplace\Shipments\Shipment_Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored shipments and their units into cells, action states and activity. An action that cannot run stays on the row, off,
 * with the reason; an action that no longer exists for a handed-over shipment is left out. Everything comes from the shop's copy.
 *
 * @since 2.1.28
 */
final class Shipments_Rows {
	/**
	 * Most items of one stack cell.
	 */
	private const STACK = 5;

	/**
	 * Most lines of a badge tooltip.
	 */
	private const TOOLTIP_LINES = 6;

	/**
	 * Longest tooltip, below the engine's limit.
	 */
	private const TOOLTIP_LENGTH = 590;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * @var Shipment_Batches
	 */
	private $marketplace;

	/**
	 * @var Shipment_Service
	 */
	private $service;

	/**
	 * @var Shipment_Noun
	 */
	private $noun;

	/**
	 * The adapter's seller actions; null for an adapter that declares none.
	 *
	 * @var Site_Orders_Seller|null
	 */
	private $seller;

	/**
	 * Whether the user may edit an order: `function ( int $order_id ): bool`.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * @since 2.1.28
	 *
	 * @param Wpdb_Shipment_Store     $store       The shop's copy.
	 * @param Shipment_Batches        $marketplace The marketplace's rules over the stored copy.
	 * @param Shipment_Service        $service     The framework's rules.
	 * @param Shipment_Noun           $noun        Words.
	 * @param Site_Orders_Seller|null $seller      The adapter's seller actions, offered on a unit's row.
	 * @param callable                $can_edit    `function ( int $order_id ): bool`.
	 */
	public function __construct( Wpdb_Shipment_Store $store, Shipment_Batches $marketplace, Shipment_Service $service, Shipment_Noun $noun, ?Site_Orders_Seller $seller, callable $can_edit ) {
		$this->store       = $store;
		$this->marketplace = $marketplace;
		$this->service     = $service;
		$this->noun        = $noun;
		$this->seller      = $seller;
		$this->can_edit    = $can_edit;
	}

	/**
	 * Builds the row of a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $row     Shipment row.
	 * @param string              $refusal Why the account cannot change shipments; empty when it can.
	 * @return array<string,mixed>
	 */
	public function row( Account $account, array $row, string $refusal ): array {
		$contents = $this->store->contents( $row );
		$shipment = $contents->shipment();
		$cells    = [
			'name'     => $this->name_cell( $contents ),
			'state'    => $this->state_cell( $row, $contents ),
			'units'    => (int) $row['unit_count'],
			'missing'  => $this->missing_cell( $row, $contents ),
			'shipping' => $this->shipping_cell( $contents ),
			'created'  => $shipment->created_at() > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $shipment->created_at() ) : null,
		];
		$actions  = $this->actions( $account, $contents, $refusal );
		$activity = $this->activity( $row );

		return [
			'id'           => Shipments_Ids::row( $account->id(), (string) $row['shipment_key'] ),
			'revision'     => self::revision( $cells, $activity, $actions ),
			'cells'        => $cells,
			'actions'      => $actions,
			'selectable'   => false,
			'has_children' => (int) $row['unit_count'] > 0,
			'activity'     => $activity,
		];
	}

	/**
	 * Builds the row of a unit under a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account             $account     Account.
	 * @param array<string,mixed> $shipment    Shipment row.
	 * @param array<string,mixed> $row         Unit row.
	 * @param string              $refusal     Why the account cannot change shipments; empty when it can.
	 * @param bool                $has_target  Whether another open shipment exists to move into.
	 * @return array<string,mixed>
	 */
	public function unit_row( Account $account, array $shipment, array $row, string $refusal, bool $has_target ): array {
		$unit     = $this->store->unit_of( $row );
		$order_id = (int) $row['order_id'];
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$order    = $order instanceof \WC_Order && true === call_user_func( $this->can_edit, $order_id ) ? $order : null;
		$actions  = [];

		if ( $unit->is_movable() ) {
			$reason = $refusal;

			if ( '' === $reason && ! $has_target ) {
				/* translators: %s: the marketplace's word for a shipment in the genitive, «поставки». */
				$reason = sprintf( __( 'Нет открытой %s, куда перенести: создайте её.', 'woodev-plugin-framework' ), $this->noun->of_one() );
			}

			$actions[] = self::action_state( Shipments_Definition::MOVE, $reason );
		}

		$actions = array_merge( $actions, $this->seller_actions( $account, $unit, $order ) );

		$cells    = [
			'name'     => $this->unit_name_cell( $unit, $order ),
			'state'    => $this->unit_state_cell( $unit, 1 === (int) $row['needs_reshipment'] ),
			'units'    => null,
			'missing'  => $this->requirements_cell( $unit ),
			'shipping' => null,
			'created'  => null,
		];
		$activity = $this->activity( $shipment );

		return [
			'id'           => Shipments_Ids::row( $account->id(), (string) $row['shipment_key'], (string) $row['unit_key'] ),
			'revision'     => self::revision( $cells, $activity, $actions ),
			'cells'        => $cells,
			'actions'      => $actions,
			'selectable'   => $unit->is_movable() && '' === $refusal && $has_target,
			'has_children' => false,
			'activity'     => $activity,
		];
	}

	/**
	 * @param Shipment_Contents $contents Shipment.
	 * @return array<int,array<string,mixed>>
	 */
	private function name_cell( Shipment_Contents $contents ): array {
		$shipment = $contents->shipment();
		$cell     = [ self::text( $shipment->title() ) ];

		if ( '' !== $shipment->name() ) {
			$cell[] = self::text( $shipment->id() );
		}

		if ( '' !== $shipment->kind_label() ) {
			$cell[] = self::text( $shipment->kind_label() );
		}

		return $cell;
	}

	/**
	 * @param array<string,mixed> $row      Row.
	 * @param Shipment_Contents   $contents Shipment.
	 * @return array<int,array<string,mixed>>
	 */
	private function state_cell( array $row, Shipment_Contents $contents ): array {
		$shipment = $contents->shipment();
		$cell     = [
			$shipment->is_open()
				? self::badge( __( 'Открыта', 'woodev-plugin-framework' ), 'info' )
				: self::badge( __( 'Передана', 'woodev-plugin-framework' ), 'success' ),
		];

		if ( '' !== $shipment->status_label() ) {
			$cell[] = self::text( $shipment->status_label() );
		}

		$again = (int) $row['reship_count'];

		if ( $again > 0 ) {
			/* translators: %d: number of goods to ship again. */
			$cell[] = self::badge( sprintf( __( 'Повторная отгрузка: %d', 'woodev-plugin-framework' ), $again ), 'warning' );
		}

		return $cell;
	}

	/**
	 * @param array<string,mixed> $row      Row.
	 * @param Shipment_Contents   $contents Shipment.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function missing_cell( array $row, Shipment_Contents $contents ): ?array {
		if ( null === $row['read_at'] ) {
			return [ self::text( __( 'Ещё не прочитано', 'woodev-plugin-framework' ) ) ];
		}

		if ( ! $contents->shipment()->is_open() || [] === $contents->units() ) {
			return null;
		}

		$blocking = (int) $row['blocking_count'];
		$warning  = (int) $row['warning_count'];
		$lines    = self::missing_lines( $contents, $blocking > 0 );

		if ( $blocking > 0 ) {
			/* translators: %d: number of goods. */
			return [ self::badge( sprintf( __( 'Не заполнено: %d', 'woodev-plugin-framework' ), $blocking ), 'danger', $lines ) ];
		}

		if ( $warning > 0 ) {
			/* translators: %d: number of goods. */
			return [ self::badge( sprintf( __( 'Проверьте: %d', 'woodev-plugin-framework' ), $warning ), 'warning', $lines ) ];
		}

		return [ self::badge( __( 'Всё заполнено', 'woodev-plugin-framework' ), 'success' ) ];
	}

	/**
	 * The tooltip of the «what is missing» badge: «goods — what» per unmet requirement of the shown level.
	 *
	 * @param Shipment_Contents $contents Shipment.
	 * @param bool              $blocking Whether to list the blocking ones, else the warnings.
	 * @return string
	 */
	private static function missing_lines( Shipment_Contents $contents, bool $blocking ): string {
		$lines = [];

		foreach ( $contents->units() as $unit ) {
			foreach ( $unit->requirements() as $requirement ) {
				if ( ! $requirement->is_met() && $requirement->is_blocking() === $blocking ) {
					$lines[] = trim( $unit->label() . ' — ' . $requirement->label(), ' —' );
				}
			}
		}

		if ( count( $lines ) > self::TOOLTIP_LINES ) {
			$rest    = count( $lines ) - self::TOOLTIP_LINES;
			$lines   = array_slice( $lines, 0, self::TOOLTIP_LINES );
			/* translators: %d: number of further missing items. */
			$lines[] = sprintf( __( 'и ещё %d', 'woodev-plugin-framework' ), $rest );
		}

		return mb_substr( implode( "\n", $lines ), 0, self::TOOLTIP_LENGTH );
	}

	/**
	 * @param Shipment_Contents $contents Shipment.
	 * @return array<int,array<string,mixed>>
	 */
	private function shipping_cell( Shipment_Contents $contents ): array {
		$shipment = $contents->shipment();
		$cell     = [ self::text( '' === $shipment->shipping_label() ? __( 'Не выбраны', 'woodev-plugin-framework' ) : $shipment->shipping_label() ) ];
		$places   = count( $contents->cargo_places() );

		if ( $places > 0 ) {
			$cell[] = self::text( Posting_Noun::capitalised( $this->noun->places() ) . ': ' . $places );
		}

		return $cell;
	}

	/**
	 * @param Account           $account  Account.
	 * @param Shipment_Contents $contents Shipment.
	 * @param string            $refusal  Account refusal.
	 * @return array<int,array<string,mixed>>
	 */
	private function actions( Account $account, Shipment_Contents $contents, string $refusal ): array {
		$shipment = $contents->shipment();
		$actions  = [];

		if ( $shipment->is_open() ) {
			if ( [] !== $this->marketplace->shipping_fields( $account ) ) {
				$actions[] = self::action_state( Shipments_Definition::SHIPPING, $refusal );
			}

			if ( $this->marketplace->max_cargo_places() > 0 ) {
				$actions[] = self::action_state( Shipments_Definition::CARGO, '' !== $refusal ? $refusal : $this->service->cargo_reason( $contents ) );
			}
		}

		$unreadable = $this->service->download_refusal( $account );

		foreach ( $this->marketplace->shipment_documents() as $document ) {
			$reason    = $this->marketplace->document_refusal( $shipment, $contents, $document->id() );
			$actions[] = self::action_state( Shipments_Definition::DOCUMENT . $document->id(), '' !== $reason ? $reason : $unreadable );
		}

		if ( $shipment->is_open() ) {
			$actions[] = self::action_state( Shipments_Definition::HAND_OVER, '' !== $refusal ? $refusal : $this->service->hand_over_reason( $contents ) );
		}

		return $actions;
	}

	/**
	 * The seller actions of a unit, by the rule of the order screens.
	 *
	 * @param Account        $account Account.
	 * @param Shipment_Unit  $unit    Unit.
	 * @param \WC_Order|null $order   Its order, when the user may edit it.
	 * @return array<int,array{id:string,enabled:bool,reason:string}>
	 */
	private function seller_actions( Account $account, Shipment_Unit $unit, ?\WC_Order $order ): array {
		if ( null === $this->seller || null === $order ) {
			return [];
		}

		return $this->seller->unit_states( $account, $order, true )[ '#' . $unit->id() ] ?? [];
	}

	/**
	 * @param Shipment_Unit  $unit  Unit.
	 * @param \WC_Order|null $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function unit_name_cell( Shipment_Unit $unit, ?\WC_Order $order ): array {
		$cell = [ self::text( '' === $unit->label() ? __( 'Позиция', 'woodev-plugin-framework' ) : $unit->label() ) ];

		if ( null !== $order ) {
			$cell[] = [
				'type'  => 'link',
				'value' => [
					/* translators: %s: order number. */
					'label' => sprintf( __( 'Заказ № %s', 'woodev-plugin-framework' ), $order->get_order_number() ),
					'url'   => $order->get_edit_order_url(),
				],
			];
		}

		return $cell;
	}

	/**
	 * @param Shipment_Unit $unit  Unit.
	 * @param bool          $again Whether it must be shipped again.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function unit_state_cell( Shipment_Unit $unit, bool $again ): ?array {
		$cell = [];

		if ( '' !== $unit->status_label() ) {
			$cell[] = self::text( $unit->status_label() );
		}

		if ( $again ) {
			$cell[] = self::badge( __( 'Нужна повторная отгрузка', 'woodev-plugin-framework' ), 'warning' );
		}

		return [] === $cell ? null : $cell;
	}

	/**
	 * What the unit needs, one badge per requirement with the marketplace's reason in the tooltip.
	 *
	 * @param Shipment_Unit $unit Unit.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function requirements_cell( Shipment_Unit $unit ): ?array {
		$requirements = $unit->requirements();

		if ( [] === $requirements ) {
			return null;
		}

		usort(
			$requirements,
			static function ( Shipment_Requirement $a, Shipment_Requirement $b ): int {
				return self::rank( $a ) <=> self::rank( $b );
			}
		);

		$shown = count( $requirements ) > self::STACK ? self::STACK - 1 : count( $requirements );
		$cell  = [];

		foreach ( array_slice( $requirements, 0, $shown ) as $requirement ) {
			$cell[] = self::requirement_badge( $requirement );
		}

		if ( count( $requirements ) > $shown ) {
			/* translators: %d: number of further items. */
			$cell[] = self::text( sprintf( __( 'и ещё %d', 'woodev-plugin-framework' ), count( $requirements ) - $shown ) );
		}

		return $cell;
	}

	/**
	 * @param Shipment_Requirement $requirement Requirement.
	 * @return int Order of importance: what blocks first, what is met last.
	 */
	private static function rank( Shipment_Requirement $requirement ): int {
		if ( $requirement->blocks() ) {
			return 0;
		}

		return $requirement->is_met() ? 2 : 1;
	}

	/**
	 * @param Shipment_Requirement $requirement Requirement.
	 * @return array<string,mixed>
	 */
	private static function requirement_badge( Shipment_Requirement $requirement ): array {
		$words = [
			Shipment_Requirement::MET     => [ __( 'заполнено', 'woodev-plugin-framework' ), 'success' ],
			Shipment_Requirement::WAITING => [ __( 'проверяется', 'woodev-plugin-framework' ), 'info' ],
			Shipment_Requirement::REFUSED => [ __( 'не принято', 'woodev-plugin-framework' ), 'danger' ],
			Shipment_Requirement::MISSING => [ __( 'не заполнено', 'woodev-plugin-framework' ), 'danger' ],
		];

		list( $state, $tone ) = $words[ $requirement->state() ];

		if ( ! $requirement->is_blocking() && 'danger' === $tone ) {
			$tone = 'warning';
		}

		return self::badge( mb_substr( $requirement->label() . ': ' . $state, 0, 200 ), $tone, mb_substr( $requirement->note(), 0, self::TOOLTIP_LENGTH ) );
	}

	/**
	 * @param array<string,mixed> $row Shipment row.
	 * @return array<string,mixed> The lock of the shipment, or nothing going on.
	 */
	private function activity( array $row ): array {
		if ( ! $this->store->is_locked( $row ) ) {
			return Activity_Normalizer::idle();
		}

		return [
			'locked'          => true,
			'reason'          => $this->service->busy_sentence(),
			'blocked_actions' => [],
			'processes'       => [
				[
					'id'    => 'shipment-lock',
					'state' => 'running',
					'label' => __( 'Выполняется…', 'woodev-plugin-framework' ),
				],
			],
		];
	}

	/**
	 * What of a row makes the screen draw it again.
	 *
	 * @param array<string,mixed>            $cells    Cells.
	 * @param array<string,mixed>            $activity Activity.
	 * @param array<int,array<string,mixed>> $actions  Actions.
	 * @return string
	 */
	private static function revision( array $cells, array $activity, array $actions ): string {
		return substr( md5( (string) wp_json_encode( [ $cells, $activity['processes'], $actions ] ) ), 0, 16 );
	}

	/**
	 * @param string $id     Action id.
	 * @param string $reason Why it is off; empty when it is on.
	 * @return array<string,mixed>
	 */
	private static function action_state( string $id, string $reason ): array {
		return [
			'id'      => $id,
			'enabled' => '' === $reason,
			'reason'  => mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH ),
		];
	}

	/**
	 * @param string $text Text.
	 * @return array<string,mixed>
	 */
	private static function text( string $text ): array {
		return [
			'type'  => 'text',
			'value' => $text,
		];
	}

	/**
	 * @param string $label   Label.
	 * @param string $tone    Tone.
	 * @param string $tooltip Tooltip.
	 * @return array<string,mixed>
	 */
	private static function badge( string $label, string $tone, string $tooltip = '' ): array {
		return [
			'type'  => 'badge',
			'value' => [
				'label'   => $label,
				'tone'    => $tone,
				'tooltip' => $tooltip,
			],
		];
	}
}

<?php
/**
 * «Add to a shipment» on the table of orders.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipment_Report_Groups;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Report;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment_Candidate;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;

defined( 'ABSPATH' ) || exit;

/**
 * The row and bulk action that puts the goods of orders into a new or an open shipment, on the «Заказы» table of a marketplace
 * that ships in shipments. It declares the action, tells how each row's button looks from stored data, and runs it through
 * {@see Shipment_Service}. The counts of a plan and a report are goods (units), as for the seller actions.
 *
 * @since 2.1.28
 */
final class Site_Orders_Shipments {
	public const ROW_ACTION  = 'ship-add';
	public const BULK_ACTION = 'bulk-ship-add';

	public const FIELD_TARGET = 'target';
	public const FIELD_NAME   = 'name';

	/**
	 * The target that means «make a new shipment».
	 */
	public const NEW_SHIPMENT = 'new';

	/**
	 * @var Shipment_Service
	 */
	private $service;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * @var Shipment_Noun
	 */
	private $noun;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment_Service    $service The actions.
	 * @param Wpdb_Shipment_Store $store   The shop's copy: the open shipments to choose from.
	 * @param Shipment_Noun       $noun    The marketplace's words.
	 */
	public function __construct( Shipment_Service $service, Wpdb_Shipment_Store $store, Shipment_Noun $noun ) {
		$this->service = $service;
		$this->store   = $store;
		$this->noun    = $noun;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $action Action id of a request.
	 * @return bool Whether it is one of these actions.
	 */
	public function handles( string $action ): bool {
		return self::ROW_ACTION === $action || self::BULK_ACTION === $action;
	}

	/**
	 * Adds the row action, before the cancellation, and the bulk action to the table's definition.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,mixed> $definition The «Заказы» definition.
	 * @param string              $capability Capability the actions ask for.
	 * @return array<string,mixed>
	 */
	public function extend_definition( array $definition, string $capability = 'edit_shop_orders' ): array {
		$rows = $definition['row_actions'];
		$at   = array_search( Site_Orders_Definition::CANCEL, array_column( $rows, 'id' ), true );

		array_splice( $rows, false === $at ? count( $rows ) : $at, 0, [ $this->action( self::ROW_ACTION, $capability ) + [ 'icon' => 'archive' ] ] );

		$definition['row_actions']    = $rows;
		$definition['bulk_actions'][] = $this->action( self::BULK_ACTION, $capability ) + [ 'scope' => 'selection' ];
		$definition['messages']      += Standard_Messages::bulk();

		return $definition;
	}

	/**
	 * How the button of one order looks: on when a unit of it can join a shipment, off with the reason when none can, and absent
	 * when the order has no unit a shipment takes.
	 *
	 * @since 2.1.28
	 *
	 * @param Account   $account Account of the order.
	 * @param \WC_Order $order   The order.
	 * @return array<int,array{id:string,enabled:bool,reason:string}>
	 */
	public function row_states( Account $account, \WC_Order $order ): array {
		$candidates = $this->service->candidates( $account, [ $order ] );

		if ( [] === $candidates ) {
			return [];
		}

		$reason = $this->refusal( $account );

		$eligible = array_filter(
			$candidates,
			static function ( Shipment_Candidate $candidate ): bool {
				return $candidate->is_eligible();
			}
		);

		if ( '' === $reason && [] === $eligible ) {
			$reason = $candidates[0]->refusal();
		}

		return [
			[
				'id'      => self::ROW_ACTION,
				'enabled' => '' === $reason,
				'reason'  => mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH ),
			],
		];
	}

	/**
	 * The plan over the asked orders. Calls no marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param Account             $account Account.
	 * @param \WC_Order[]         $orders  Orders of the account.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account does not allow it or the shipment is not available.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function preview( Account $account, array $orders, array $payload ): array {
		list( $key ) = $this->read( $payload );

		try {
			$refusal = $this->refusal( $account );

			if ( '' !== $refusal ) {
				throw new Shipment_Refused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			$target     = '' === $key ? null : $this->store->shipment( $this->service->open_row( $account, $key ) );
			$candidates = $this->service->candidates( $account, $orders, $target );
		} catch ( Shipment_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$report   = new Shipment_Report( $key );
		$eligible = 0;

		foreach ( $candidates as $candidate ) {
			if ( $candidate->is_eligible() ) {
				++$eligible;
			} else {
				$report->add_skipped( $candidate->refusal(), Shipment_Service::example( $orders, $candidate ) );
			}
		}

		return [
			'requested' => count( $candidates ),
			'eligible'  => $eligible,
			'skipped'   => count( $report->skipped() ),
			'groups'    => Shipment_Report_Groups::of( $report ),
			'messages'  => [ 'summary' => self::counted_sentence() ],
		];
	}

	/**
	 * Puts the goods of the asked orders into the chosen shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account             $account Account.
	 * @param \WC_Order[]         $orders  Orders of the account.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed> The engine's apply answer, without rows.
	 * @throws Action_State_Exception When nothing was done.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function apply( Account $account, array $orders, array $payload ): array {
		list( $key, $name ) = $this->read( $payload );

		try {
			$report = $this->service->add( $account, $orders, $key, $name );
		} catch ( Shipment_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return [
			'requested' => $report->done() + count( $report->failed() ) + count( $report->skipped() ),
			'accepted'  => $report->done(),
			'skipped'   => count( $report->skipped() ),
			'failed'    => count( $report->failed() ),
			'groups'    => Shipment_Report_Groups::of( $report ),
			'messages'  => [ 'summary' => self::counted_sentence() ],
		];
	}

	/**
	 * The choices of the target list: a new shipment, then the open ones.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $field   Field id.
	 * @param string  $search  What the merchant typed.
	 * @return array<int,array{value:string,label:string}>
	 */
	public function field_options( Account $account, string $field, string $search ): array {
		if ( self::FIELD_TARGET !== $field ) {
			return [];
		}

		/* translators: %s: the marketplace's word for a shipment, «поставка». */
		$new     = sprintf( __( 'Новая %s', 'woodev-plugin-framework' ), $this->noun->one() );
		$options = '' === $search || false !== mb_stripos( $new, $search ) ? [
			[
				'value' => self::NEW_SHIPMENT,
				'label' => $new,
			],
		] : [];

		foreach ( $this->store->open( $account->id(), $search ) as $row ) {
			$options[] = [
				'value' => (string) $row['shipment_key'],
				'label' => mb_substr( '' === $row['name'] ? (string) $row['remote_id'] : $row['name'] . ' (' . $row['remote_id'] . ')', 0, 200 ),
			];
		}

		return array_slice( $options, 0, Wpdb_Shipment_Store::CHOICES );
	}

	/**
	 * @param string $id         Action id.
	 * @param string $capability Capability.
	 * @return array<string,mixed>
	 */
	private function action( string $id, string $capability ): array {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$label = sprintf( __( 'Добавить в %s', 'woodev-plugin-framework' ), $this->noun->acc() );

		return [
			'id'             => $id,
			'label'          => $label,
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'capability'     => $capability,
			'confirm_label'  => __( 'Добавить', 'woodev-plugin-framework' ),
			/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
			'scope_sentence' => sprintf( __( 'Товары выбранных заказов будут добавлены в %s на маркетплейсе.', 'woodev-plugin-framework' ), $this->noun->acc() ),
			'fields'         => [
				[
					'id'             => self::FIELD_TARGET,
					'label'          => Posting_Noun::capitalised( $this->noun->one() ),
					'type'           => 'select',
					'required'       => true,
					'options_source' => true,
					'searchable'     => true,
				],
				[
					'id'    => self::FIELD_NAME,
					/* translators: %s: the marketplace's word for a shipment in the genitive, «поставки». */
					'label' => sprintf( __( 'Название новой %s', 'woodev-plugin-framework' ), $this->noun->of_one() ),
					'type'  => 'text',
					/* translators: %s: the marketplace's word for a shipment, «поставка». */
					'help'  => sprintf( __( 'Заполните, если выбрана новая %s.', 'woodev-plugin-framework' ), $this->noun->one() ),
				],
			],
		];
	}

	/**
	 * @param Account $account Account.
	 * @return string Why the account cannot add goods to a shipment; empty when it can.
	 */
	private function refusal( Account $account ): string {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		return $this->service->refusal( $account, sprintf( __( 'добавить товары в %s на маркетплейсе', 'woodev-plugin-framework' ), $this->noun->acc() ) );
	}

	/**
	 * @param array<string,mixed> $payload Fields.
	 * @return array{0:string,1:string} Key of the chosen shipment (empty for a new one) and the new one's name.
	 * @throws Action_Input_Exception When no target was chosen, or a new shipment has no usable name.
	 */
	private function read( array $payload ): array {
		$target = (string) ( $payload[ self::FIELD_TARGET ] ?? '' );
		$name   = trim( (string) ( $payload[ self::FIELD_NAME ] ?? '' ) );

		if ( self::NEW_SHIPMENT === $target ) {
			if ( '' === $name || mb_strlen( $name ) > Shipment_Service::MAX_NAME_LENGTH ) {
				/* translators: %d: most characters. */
				throw new Action_Input_Exception( [ self::FIELD_NAME => sprintf( __( 'Введите название не длиннее %d символов.', 'woodev-plugin-framework' ), Shipment_Service::MAX_NAME_LENGTH ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			return [ '', $name ];
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $target ) ) {
			throw new Action_Input_Exception( [ self::FIELD_TARGET => __( 'Выберите значение из списка.', 'woodev-plugin-framework' ) ] );
		}

		return [ $target, '' ];
	}

	/**
	 * @return string What the numbers of a plan and a report count.
	 */
	private static function counted_sentence(): string {
		return __( 'Числа ниже — товары в выбранных заказах.', 'woodev-plugin-framework' );
	}
}

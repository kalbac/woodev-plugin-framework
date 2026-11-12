<?php
/**
 * What is going on with the rows of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Activity_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * The local read behind the table's locks and processes. It is built from what the rows already carry — the registry row and the
 * label state of its posting number ({@see Site_Orders_Labels::states()}) — so it claims nothing, queues nothing and calls no
 * marketplace.
 *
 * | Source | Projection |
 * |---|---|
 * | a label task being made | a waiting process; «Скачать этикетки» is blocked, so one posting is not asked for twice |
 * | the newest label task ended without a document | a failed process that carries the merchant's sentence, for a day |
 * | the registry row is claimed by a writer | a running process: the order is being refreshed from the marketplace; nothing is blocked |
 *
 * @since 2.1.5
 */
final class Site_Orders_Activity {
	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.5
	 *
	 * @param callable|null $clock Returns the Unix time; the system clock when null.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * The activity of the rows of a page.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array<string,mixed>>                                    $items  Items, as {@see Site_Orders_Reader} reads them.
	 * @param array<string,array{document_id:int,open_task:int,failure:string}> $states Label state by posting number.
	 * @return array<int,array<string,mixed>> Row activity by order id, in the shape {@see Activity_Normalizer::row()} accepts.
	 */
	public function for_items( array $items, array $states ): array {
		$now = Activity_Normalizer::now();
		$out = [];

		foreach ( $items as $item ) {
			$out[ $item['order_id'] ] = $this->row( $item, $states[ $item['external_key'] ] ?? Site_Orders_Labels::none(), $now );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed>                                 $item  Item.
	 * @param array{document_id:int,open_task:int,failure:string} $state Label state of its posting number.
	 * @param string                                              $now   `observed_at`.
	 * @return array<string,mixed>
	 */
	private function row( array $item, array $state, string $now ): array {
		$processes = array_merge( $this->label_processes( $state ), $this->refresh_processes( $item ) );

		if ( [] === $processes ) {
			return Activity_Normalizer::idle( $now );
		}

		$preparing = $state['open_task'] > 0;

		return [
			'locked'          => false,
			'reason'          => $preparing ? __( 'Этикетки для этого заказа уже готовятся — дождитесь результата.', 'woodev-plugin-framework' ) : '',
			'blocked_actions' => $preparing ? [ Site_Orders_Definition::LABEL ] : [],
			'processes'       => $processes,
			'observed_at'     => $now,
		];
	}

	/**
	 * @param array{document_id:int,open_task:int,failure:string} $state Label state.
	 * @return array<int,array<string,mixed>>
	 */
	private function label_processes( array $state ): array {
		if ( $state['open_task'] > 0 ) {
			return [ $this->process( 'label-' . $state['open_task'], 'waiting', __( 'Этикетка', 'woodev-plugin-framework' ), __( 'Маркетплейс готовит этикетки', 'woodev-plugin-framework' ) ) ];
		}

		if ( '' !== $state['failure'] ) {
			return [ $this->process( 'label-failed', 'failed', __( 'Этикетка', 'woodev-plugin-framework' ), mb_substr( $state['failure'], 0, 200 ) ) ];
		}

		return [];
	}

	/**
	 * @param array<string,mixed> $item Item.
	 * @return array<int,array<string,mixed>>
	 */
	private function refresh_processes( array $item ): array {
		$until = '' === $item['claim_until'] ? 0 : (int) strtotime( $item['claim_until'] . ' UTC' );

		if ( $until <= (int) call_user_func( $this->clock ) ) {
			return [];
		}

		return [ $this->process( 'refresh', 'running', __( 'Заказ', 'woodev-plugin-framework' ), __( 'Заказ обновляется по данным маркетплейса', 'woodev-plugin-framework' ) ) ];
	}

	/**
	 * A process without a measurable size: the counts and the percent stay null.
	 *
	 * @param string $id    Observation id.
	 * @param string $state `Activity_Normalizer::STATES`.
	 * @param string $label Label.
	 * @param string $text  Detail.
	 * @return array<string,mixed>
	 */
	private function process( string $id, string $state, string $label, string $text ): array {
		return [
			'id'        => $id,
			'state'     => $state,
			'label'     => $label,
			'completed' => null,
			'total'     => null,
			'percent'   => null,
			'text'      => $text,
		];
	}
}

<?php
/**
 * The «Статус данных» block of the page of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Marketplace\Admin\Shared\Date_Text;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;

defined( 'ABSPATH' ) || exit;

/**
 * When the shop last read the account's shipments, when it reads them again and why the last try failed — the block of
 * «Заказы доставки». Nothing here calls the marketplace.
 *
 * @since 2.1.28
 */
final class Shipments_Data_Status {
	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * `function ( int $account_id ): int|bool` — Action Scheduler's next run of the account's poll: a Unix time, `true` while it
	 * runs, `false` when none is scheduled.
	 *
	 * @var callable
	 */
	private $next_run;

	/**
	 * @since 2.1.28
	 *
	 * @param Wpdb_Shipment_Store $store    The shop's copy.
	 * @param callable            $next_run `function ( int $account_id ): int|bool`.
	 */
	public function __construct( Wpdb_Shipment_Store $store, callable $next_run ) {
		$this->store    = $store;
		$this->next_run = $next_run;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int $account_id Account.
	 * @return array{items:array<int,array{label:string,value:string}>,title:string}
	 */
	public function for_account( int $account_id ): array {
		$last = $this->store->refreshed_at( $account_id );
		$next = call_user_func( $this->next_run, $account_id );

		if ( true === $next ) {
			$next_text = __( 'Сейчас', 'woodev-plugin-framework' );
		} else {
			$next_text = is_int( $next ) && $next > 0 ? Date_Text::datetime( $next ) : __( 'Не по расписанию', 'woodev-plugin-framework' );
		}

		$items = [
			[
				'label' => __( 'Последнее обновление', 'woodev-plugin-framework' ),
				'value' => $last > 0 ? Date_Text::datetime( $last ) : __( 'Ни разу', 'woodev-plugin-framework' ),
			],
			[
				'label' => __( 'Следующее обновление', 'woodev-plugin-framework' ),
				'value' => $next_text,
			],
		];
		$error = $this->store->last_error( $account_id );

		if ( '' !== $error ) {
			$items[] = [
				'label' => __( 'Ошибка', 'woodev-plugin-framework' ),
				'value' => mb_substr( $error, 0, 400 ),
			];
		}

		return [
			'items' => $items,
			'title' => __( 'Таблица показывает копию, которую сайт читает с маркетплейса по расписанию и после каждого действия.', 'woodev-plugin-framework' ),
		];
	}
}

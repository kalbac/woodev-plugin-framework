<?php
/**
 * The definition of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine: columns, filters, sorts, the row actions and every sentence the page shows. The engine
 * validates the result ({@see \Woodev\Framework\Admin\Records\Records_Definition::from_array()}), so a typo here is a developer error
 * caught on the first request.
 *
 * @since 2.1.5
 */
final class Site_Orders_Definition {
	/**
	 * Row action: open the WooCommerce order.
	 */
	public const OPEN = 'open';

	/**
	 * Row action: download the labels of the posting, asking the marketplace for them when there are none yet.
	 */
	public const LABEL = 'label';

	/**
	 * Row action: cancel the posting on the marketplace, with a reason.
	 */
	public const CANCEL = 'cancel';

	/**
	 * Capability the label and cancel actions ask for (the object check is `edit_shop_order`).
	 */
	private const EDIT = 'edit_shop_orders';

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array{value:string,label:string}> $accounts   Accounts the user may open: the account selector's options.
	 * @param array<int,array{value:string,label:string}> $statuses   WooCommerce order statuses for the filter.
	 * @param string[]                                    $offered    Row action ids the adapter can serve: `open`, and `label` and `cancel` when it has them.
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, array $statuses, array $offered ): array {
		return [
			'context'     => [
				'label'   => __( 'Аккаунт', 'woodev-plugin-framework' ),
				'options' => $accounts,
				'default' => (string) ( $accounts[0]['value'] ?? '' ),
			],
			'columns'     => self::columns( in_array( self::LABEL, $offered, true ) ),
			'filters'     => self::filters( $statuses ),
			'sorts'       => [
				'keys'    => Site_Orders_Storage::SORT_KEYS,
				'default' => [
					'key'       => 'date',
					'direction' => 'desc',
				],
			],
			'pagination'  => [
				'default_per_page' => 20,
				'per_page_options' => [ 20, 50, 100 ],
				'max_per_page'     => 100,
				'max_selection'    => 100,
			],
			'row_actions' => self::row_actions( $offered ),
			'activity'    => [
				'refresh_ms'     => 5000,
				'max_refresh_ms' => 60000,
				'stale_after_ms' => 30000,
				'id_limit'       => 100,
			],
			'messages'    => self::messages(),
			'analytics'   => null,
		];
	}

	/**
	 * @param bool $with_label Whether the table has the label column.
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( bool $with_label ): array {
		$columns = [
			[
				'id'        => 'order',
				'label'     => __( 'Заказ', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
				'sort_key'  => 'order',
				'required'  => true,
			],
			[
				'id'        => 'posting',
				'label'     => __( 'Отправление', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'sort_key'  => 'posting',
			],
			[
				'id'        => 'status',
				'label'     => __( 'Статус', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
				'sort_key'  => 'status',
			],
			[
				'id'        => 'date',
				'label'     => __( 'Дата', 'woodev-plugin-framework' ),
				'cell_type' => 'date',
				'sort_key'  => 'date',
			],
			[
				'id'        => 'customer',
				'label'     => __( 'Покупатель', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
			],
			[
				'id'        => 'total',
				'label'     => __( 'Сумма', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'sort_key'  => 'total',
				'align'     => 'right',
			],
		];

		if ( $with_label ) {
			$columns[] = [
				'id'        => 'label',
				'label'     => __( 'Этикетки', 'woodev-plugin-framework' ),
				'cell_type' => 'link',
			];
		}

		$columns[] = [
			'id'        => 'actions',
			'label'     => '',
			'cell_type' => 'actions',
		];

		return $columns;
	}

	/**
	 * @param array<int,array{value:string,label:string}> $statuses WooCommerce order statuses.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filters( array $statuses ): array {
		$filters = [
			[
				'id'    => 'q',
				'label' => __( 'Поиск по отправлению или ID заказа', 'woodev-plugin-framework' ),
				'type'  => 'search',
			],
			[
				'id'      => 'state',
				'label'   => __( 'Состояние отправления', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => [
					[
						'value' => Site_Orders_Filter::STATE_OPEN,
						'label' => __( 'В работе', 'woodev-plugin-framework' ),
					],
					[
						'value' => Site_Orders_Filter::STATE_FINISHED,
						'label' => __( 'Завершены', 'woodev-plugin-framework' ),
					],
				],
			],
		];

		if ( [] !== $statuses ) {
			$filters[] = [
				'id'      => 'wc_status',
				'label'   => __( 'Статус заказа', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => $statuses,
			];
		}

		$filters[] = [
			'id'    => 'date',
			'label' => __( 'Дата заказа', 'woodev-plugin-framework' ),
			'type'  => 'date_range',
		];

		return $filters;
	}

	/**
	 * The row actions the adapter earns, in the order the row shows them.
	 *
	 * @param string[] $offered Action ids to declare.
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_actions( array $offered ): array {
		$actions = [
			self::OPEN   => [
				'id'          => self::OPEN,
				'label'       => __( 'Открыть заказ', 'woodev-plugin-framework' ),
				'effect'      => 'read',
				'interaction' => 'link',
			],
			self::LABEL  => [
				'id'           => self::LABEL,
				'label'        => __( 'Скачать этикетки', 'woodev-plugin-framework' ),
				'effect'       => 'mutate',
				'interaction'  => 'download',
				'capability'   => self::EDIT,
				'skip_preview' => true,
			],
			self::CANCEL => [
				'id'             => self::CANCEL,
				'label'          => __( 'Отменить отправление', 'woodev-plugin-framework' ),
				'tone'           => 'danger',
				'effect'         => 'mutate',
				'interaction'    => 'dialog',
				'capability'     => self::EDIT,
				'scope_sentence' => __( 'Отправление будет отменено на маркетплейсе. Заказ на сайте обновится в течение нескольких минут.', 'woodev-plugin-framework' ),
				'confirm_label'  => __( 'Отменить отправление', 'woodev-plugin-framework' ),
				'fields'         => [
					[
						'id'             => 'reason',
						'label'          => __( 'Причина отмены', 'woodev-plugin-framework' ),
						'type'           => 'select',
						'required'       => true,
						'options_source' => true,
					],
					[
						'id'    => 'message',
						'label' => __( 'Пояснение', 'woodev-plugin-framework' ),
						'type'  => 'textarea',
						'help'  => __( 'Нужно, если маркетплейс просит пояснить выбранную причину.', 'woodev-plugin-framework' ),
					],
				],
			],
		];

		return array_values( array_intersect_key( $actions, array_flip( $offered ) ) );
	}

	/**
	 * Every sentence the page shows. The shell, the action dialog and the activity part read their strings from here.
	 *
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return [
			'empty'                  => __( 'Заказов не найдено. Заказы появятся здесь, когда маркетплейс пришлёт их на сайт.', 'woodev-plugin-framework' ),
			'empty_filtered'         => __( 'По этим фильтрам заказов нет.', 'woodev-plugin-framework' ),
			'error'                  => __( 'Не удалось загрузить заказы. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'retry'                  => __( 'Повторить', 'woodev-plugin-framework' ),
			'reset_filters'          => __( 'Сбросить фильтры', 'woodev-plugin-framework' ),
			'actions_column'         => __( 'Действия', 'woodev-plugin-framework' ),
			'filter_any'             => __( 'Любое', 'woodev-plugin-framework' ),
			'per_page'               => __( 'Заказов на странице', 'woodev-plugin-framework' ),
			'date_from'              => __( 'С', 'woodev-plugin-framework' ),
			'date_to'                => __( 'По', 'woodev-plugin-framework' ),
			'action_cancel'          => __( 'Отмена', 'woodev-plugin-framework' ),
			'action_close'           => __( 'Закрыть', 'woodev-plugin-framework' ),
			'action_preview'         => __( 'Проверить', 'woodev-plugin-framework' ),
			'action_planning'        => __( 'Проверяем заказ…', 'woodev-plugin-framework' ),
			'action_applying'        => __( 'Выполняем…', 'woodev-plugin-framework' ),
			'action_retry'           => __( 'Повторить', 'woodev-plugin-framework' ),
			'action_requested'       => __( 'Выбрано', 'woodev-plugin-framework' ),
			'action_eligible'        => __( 'Будет обработано', 'woodev-plugin-framework' ),
			'action_skipped'         => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_accepted'        => __( 'Принято', 'woodev-plugin-framework' ),
			'action_failed'          => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_status_done'     => __( 'Готово.', 'woodev-plugin-framework' ),
			'action_status_partial'  => __( 'Выполнено частично: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_status_queued'   => __( 'Этикетки запрошены у маркетплейса. Ссылка появится в столбце «Этикетки», когда они будут готовы.', 'woodev-plugin-framework' ),
			'action_status_failed'   => __( 'Ничего не выполнено: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_group_skipped'   => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_group_failed'    => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_group_warning'   => __( 'С предупреждением', 'woodev-plugin-framework' ),
			'action_warnings'        => __( 'Предупреждения', 'woodev-plugin-framework' ),
			'action_recovery'        => __( 'Ответа нет: действие могло выполниться. Мы обновили таблицу — проверьте состояние заказа.', 'woodev-plugin-framework' ),
			'action_error'           => __( 'Не удалось выполнить запрос. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'action_download'        => __( 'Скачать этикетки', 'woodev-plugin-framework' ),
			'action_download_blocked' => __( 'Адрес этикеток не прошёл проверку, поэтому ссылка не показана.', 'woodev-plugin-framework' ),
			'action_choose'          => __( 'Выберите…', 'woodev-plugin-framework' ),
			'action_options_more'    => __( 'Показать ещё', 'woodev-plugin-framework' ),
			'action_options_error'   => __( 'Не удалось загрузить список.', 'woodev-plugin-framework' ),
			'action_options_loading' => __( 'Загружаем список…', 'woodev-plugin-framework' ),
			'activity_stale'         => __( 'Данные о ходе работы устарели: обновляем.', 'woodev-plugin-framework' ),
			'activity_failed'        => __( 'Не удаётся получить ход работы. Попробуем ещё раз.', 'woodev-plugin-framework' ),
		];
	}
}

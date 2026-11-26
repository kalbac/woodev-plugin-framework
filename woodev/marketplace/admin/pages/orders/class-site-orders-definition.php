<?php
/**
 * The definition of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

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
	 * @since 2.1.27 Takes the seller actions and a cancellation that asks for no reason.
	 *
	 * @param array<int,array{value:string,label:string}> $accounts       Accounts the user may open: the account selector's options.
	 * @param array<int,array{value:string,label:string}> $statuses       WooCommerce order statuses for the filter.
	 * @param string[]                                    $offered        Row action ids the adapter can serve: `open`, and `label` and `cancel` when it has them.
	 * @param Posting_Noun|null                           $noun           The marketplace's word for a posting; null for «отправление».
	 * @param Site_Orders_Seller|null                     $seller         The adapter's seller actions; null for an adapter that declares none.
	 * @param string|null                                 $cancel_warning Null for a cancellation that asks for a reason; otherwise what the merchant should know before confirming one that asks for none (empty for nothing).
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, array $statuses, array $offered, ?Posting_Noun $noun = null, ?Site_Orders_Seller $seller = null, ?string $cancel_warning = null ): array {
		$noun = $noun ?? Posting_Noun::shipment();
		$bulk = null === $seller ? [] : $seller->bulk_definitions( self::EDIT );

		return [
			'context'     => [
				'label'   => __( 'Аккаунт', 'woodev-plugin-framework' ),
				'options' => $accounts,
				'default' => (string) ( $accounts[0]['value'] ?? '' ),
			],
			'columns'     => self::columns( $noun ),
			'filters'     => self::filters( $statuses, $noun ),
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
			'row_actions' => self::with_seller_actions( self::row_actions( $offered, $noun, $cancel_warning ), $seller ),
			'bulk_actions' => $bulk,
			'activity'    => [
				'refresh_ms'     => 5000,
				'max_refresh_ms' => 60000,
				'stale_after_ms' => 30000,
				'id_limit'       => 100,
			],
			'messages'    => array_merge( self::messages(), [] === $bulk ? [] : Standard_Messages::bulk() ),
			'analytics'   => null,
		];
	}

	/**
	 * Puts the seller actions between the label and the cancellation, so the destructive built-in action stays last.
	 *
	 * @param array<int,array<string,mixed>> $actions Built-in row actions.
	 * @param Site_Orders_Seller|null        $seller  Seller actions.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_seller_actions( array $actions, ?Site_Orders_Seller $seller ): array {
		if ( null === $seller ) {
			return $actions;
		}

		$ids = array_column( $actions, 'id' );
		$at  = array_search( self::CANCEL, $ids, true );

		array_splice( $actions, false === $at ? count( $actions ) : $at, 0, $seller->row_definitions( self::EDIT ) );

		return $actions;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( Posting_Noun $noun ): array {
		return [
			[
				'id'        => 'order',
				'label'     => __( 'Заказ', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
				'sort_key'  => 'order',
				'required'  => true,
			],
			[
				'id'        => 'posting',
				'label'     => Posting_Noun::capitalised( $noun->one() ),
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
			[
				'id'        => 'actions',
				'label'     => '',
				'cell_type' => 'actions',
			],
		];
	}

	/**
	 * @param array<int,array{value:string,label:string}> $statuses WooCommerce order statuses.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filters( array $statuses, Posting_Noun $noun ): array {
		$filters = [
			[
				'id'    => 'q',
				/* translators: %s: the marketplace's word for a posting in the dative, «отправлению». */
				'label' => sprintf( __( 'Поиск по %s или ID заказа', 'woodev-plugin-framework' ), $noun->to_one() ),
				'type'  => 'search',
			],
			[
				'id'      => 'state',
				/* translators: %s: the marketplace's word for a posting in the genitive, «отправления». */
				'label'   => sprintf( __( 'Состояние %s', 'woodev-plugin-framework' ), $noun->of_one() ),
				'type'    => 'select',
				'views'   => true,
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

		$filters[] = [
			'id'    => 'date',
			'label' => __( 'Дата заказа', 'woodev-plugin-framework' ),
			'type'  => 'date_range',
			// The period picker counts «Сегодня» and the rest from the site's day, which is the day the server reads the range in.
			'today' => wp_date( 'Y-m-d' ),
		];

		// The two statuses are the rarer questions: under «Расширенные фильтры», as on «Заказы доставки». The marketplace's own
		// statuses come with every list read (the provider's facets), so only the ones the shop's orders are in are offered.
		$filters[] = [
			'id'             => 'posting_status',
			'label'          => __( 'Статус на маркетплейсе', 'woodev-plugin-framework' ),
			'type'           => 'select',
			'options_source' => true,
			'advanced'       => true,
		];

		if ( [] !== $statuses ) {
			$filters[] = [
				'id'       => 'wc_status',
				'label'    => __( 'Статус заказа', 'woodev-plugin-framework' ),
				'type'     => 'select',
				'options'  => $statuses,
				'advanced' => true,
			];
		}

		return $filters;
	}

	private static function row_actions( array $offered, Posting_Noun $noun, ?string $cancel_warning ): array {
		$actions = [
			self::OPEN   => [
				'id'          => self::OPEN,
				'label'       => __( 'Открыть заказ', 'woodev-plugin-framework' ),
				'short_label' => __( 'Открыть', 'woodev-plugin-framework' ),
				'icon'        => 'visibility',
				'effect'      => 'read',
				'interaction' => 'link',
			],
			self::LABEL  => [
				'id'           => self::LABEL,
				'label'        => __( 'Скачать этикетки', 'woodev-plugin-framework' ),
				'short_label'  => __( 'Этикетки', 'woodev-plugin-framework' ),
				'icon'         => 'media-document',
				'effect'       => 'mutate',
				'interaction'  => 'download',
				'capability'   => self::EDIT,
				'skip_preview' => true,
			],
			self::CANCEL => self::cancel_action( $noun, $cancel_warning ),
		];

		return array_values( array_intersect_key( $actions, array_flip( $offered ) ) );
	}

	/**
	 * The cancellation: with the reason list and its explanation, or, for a marketplace that takes no reason, a confirmation only.
	 *
	 * @param Posting_Noun $noun           The marketplace's word for a posting.
	 * @param string|null  $cancel_warning Null when a reason is asked; otherwise the warning of the confirmation.
	 * @return array<string,mixed>
	 */
	private static function cancel_action( Posting_Noun $noun, ?string $cancel_warning ): array {
		/* translators: %s: the marketplace's word for a posting, «отправление». */
		$cancel = sprintf( __( 'Отменить %s', 'woodev-plugin-framework' ), $noun->one() );
		/* translators: %s: the marketplace's word for a posting, capitalised: «Отправление». */
		$scope  = sprintf( __( '%s будет отменено на маркетплейсе. Заказ на сайте обновится в течение нескольких минут.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $noun->one() ) );

		return [
			'id'             => self::CANCEL,
			'label'          => $cancel,
			'short_label'    => __( 'Отменить', 'woodev-plugin-framework' ),
			'icon'           => 'remove',
			'tone'           => 'danger',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'capability'     => self::EDIT,
			'scope_sentence' => mb_substr( trim( $scope . ' ' . $cancel_warning ), 0, Records_Definition::MAX_STRING_LENGTH ),
			'confirm_label'  => $cancel,
			'fields'         => null === $cancel_warning ? self::cancel_fields() : [],
		];
	}

	/**
	 * @return array<int,array<string,mixed>> The reason of a cancellation and its explanation.
	 */
	private static function cancel_fields(): array {
		return [
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
		];
	}

	/**
	 * Every sentence the page shows. The shell, the action dialog and the activity part read their strings from here.
	 *
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return array_merge(
			Standard_Messages::views(),
			Standard_Messages::pager(),
			Standard_Messages::period(),
			self::own_messages()
		);
	}

	/**
	 * The sentences of this table and of its dialog.
	 *
	 * @return array<string,string>
	 */
	private static function own_messages(): array {
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
			'action_status_queued'   => __( 'Этикетки запрошены у маркетплейса. Когда они будут готовы, нажмите «Скачать этикетки» ещё раз.', 'woodev-plugin-framework' ),
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
			'advanced_filters'       => __( 'Расширенные фильтры', 'woodev-plugin-framework' ),
			'data_status'            => __( 'Статус данных', 'woodev-plugin-framework' ),
		];
	}
}

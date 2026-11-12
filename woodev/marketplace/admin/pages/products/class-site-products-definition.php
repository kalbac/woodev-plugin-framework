<?php
/**
 * The definition of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine: columns, filters, sorts, the 12 bulk actions and every sentence the page shows. The
 * engine validates the result ({@see \Woodev\Framework\Admin\Records\Records_Definition::from_array()}), so a typo here is a developer
 * error caught on the first request.
 *
 * @since 2.1.3
 */
final class Site_Products_Definition {
	/**
	 * Product capability the write actions ask for.
	 */
	private const EDIT = 'edit_products';

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.3
	 *
	 * @param array<int,array{value:string,label:string}> $accounts    Accounts the user may open: the account selector's options.
	 * @param string                                      $marketplace Merchant-facing marketplace name.
	 * @param array<int,array{value:string,label:string}> $categories  Product categories for the filter.
	 * @param string[]                                    $withheld    Action ids the adapter cannot serve (for example linking without the import capability).
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, string $marketplace, array $categories, array $withheld = [] ): array {
		$state_options = [];

		foreach ( State_Groups::labels() as $group => $label ) {
			$state_options[] = [
				'value' => $group,
				'label' => $label,
			];
		}

		$filters = [
			[
				'id'    => 'q',
				'label' => __( 'Поиск по названию, артикулу', 'woodev-plugin-framework' ),
				'type'  => 'search',
			],
			[
				'id'      => 'state',
				'label'   => __( 'Состояние', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => $state_options,
			],
			[
				'id'    => 'only_errors',
				'label' => __( 'Только с ошибками', 'woodev-plugin-framework' ),
				'type'  => 'boolean',
			],
		];

		if ( [] !== $categories ) {
			$filters[] = [
				'id'      => 'category',
				'label'   => __( 'Категория', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => $categories,
			];
		}

		return [
			'context'      => [
				'label'   => __( 'Аккаунт', 'woodev-plugin-framework' ),
				'options' => $accounts,
				'default' => (string) ( $accounts[0]['value'] ?? '' ),
			],
			'columns'      => self::columns( $marketplace ),
			'filters'      => $filters,
			'sorts'        => [
				'keys'    => array_keys( Site_Products_Filter::SORTS ),
				'default' => [
					'key'       => 'name',
					'direction' => 'asc',
				],
			],
			'pagination'   => [
				'default_per_page' => 20,
				'per_page_options' => [ 20, 50, 100 ],
				'max_per_page'     => 100,
				'max_selection'    => 200,
			],
			'row_actions'  => [
				[
					'id'          => 'edit',
					'label'       => __( 'Изменить товар', 'woodev-plugin-framework' ),
					'effect'      => 'read',
					'interaction' => 'link',
				],
			],
			'bulk_actions' => self::bulk_actions( $marketplace, $withheld ),
			'children'     => [
				'default_per_page' => 20,
				'max_per_page'     => 100,
			],
			'activity'     => [
				'refresh_ms'     => 5000,
				'max_refresh_ms' => 60000,
				'stale_after_ms' => 30000,
				'id_limit'       => 100,
			],
			'messages'     => self::messages(),
			'analytics'    => null,
		];
	}

	/**
	 * @param string $marketplace Marketplace name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( string $marketplace ): array {
		return [
			[
				'id'        => 'name',
				'label'     => __( 'Товар', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'sort_key'  => 'name',
				'required'  => true,
			],
			[
				'id'        => 'sku',
				'label'     => __( 'Артикул', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
				'sort_key'  => 'sku',
			],
			[
				'id'        => 'state',
				'label'     => __( 'Состояние', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'stock',
				/* translators: %s: marketplace name. */
				'label'     => sprintf( __( 'Остаток: сайт / %s', 'woodev-plugin-framework' ), $marketplace ),
				'cell_type' => 'stack',
				'sort_key'  => 'stock',
			],
			[
				'id'        => 'price',
				/* translators: %s: marketplace name. */
				'label'     => sprintf( __( 'Цена: сайт / %s', 'woodev-plugin-framework' ), $marketplace ),
				'cell_type' => 'stack',
				'sort_key'  => 'price',
			],
			[
				'id'        => 'warehouse',
				'label'     => __( 'Склад', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
			],
			[
				'id'        => 'sync',
				'label'     => __( 'Синхронизация', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
			],
			[
				'id'        => 'error',
				'label'     => __( 'Ошибка', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
			],
			[
				'id'        => 'actions',
				'label'     => '',
				'cell_type' => 'actions',
			],
		];
	}

	/**
	 * The twelve bulk actions. Each asks for a preview and a confirmation; the preview is the validation report.
	 *
	 * @param string   $marketplace Marketplace name.
	 * @param string[] $withheld    Action ids to leave out.
	 * @return array<int,array<string,mixed>>
	 */
	private static function bulk_actions( string $marketplace, array $withheld ): array {
		$actions = [
			self::action( Site_Products_Bulk::EXPORT, __( 'Экспортировать / Обновить карточку', 'woodev-plugin-framework' ), __( 'Товары, прошедшие проверку, уйдут в очередь на отправку. Остальные останутся как есть — исправьте причину и повторите.', 'woodev-plugin-framework' ) ),
			[
				'id'             => Site_Products_Bulk::LINK_BY_ARTICLE,
				'label'          => __( 'Связать по артикулу', 'woodev-plugin-framework' ),
				'effect'         => 'mutate',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'capability'     => 'manage_woocommerce',
				'scope_sentence' => __( 'Связывание идёт по всему магазину, не только по отмеченным товарам. По артикулу сопоставляются все карточки аккаунта.', 'woodev-plugin-framework' ),
			],
			self::action( Site_Products_Bulk::SEND_STOCK, __( 'Отправить остатки', 'woodev-plugin-framework' ), __( 'Текущий остаток с сайта будет отправлен на маркетплейс в ближайшее время.', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SEND_PRICE, __( 'Отправить цены', 'woodev-plugin-framework' ), __( 'Текущая цена с сайта будет отправлена на маркетплейс в ближайшее время.', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SYNC_STOCK_ON, __( 'Включить синхронизацию остатков', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SYNC_STOCK_OFF, __( 'Выключить синхронизацию остатков', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SYNC_PRICE_ON, __( 'Включить синхронизацию цен', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SYNC_PRICE_OFF, __( 'Выключить синхронизацию цен', 'woodev-plugin-framework' ) ),
			array_merge(
				self::action( Site_Products_Bulk::SET_WAREHOUSE, __( 'Выбрать склад', 'woodev-plugin-framework' ), __( 'Остатки этих товаров будут отправляться на выбранный склад.', 'woodev-plugin-framework' ) ),
				[
					'fields' => [
						[
							'id'             => 'warehouse',
							'label'          => __( 'Склад', 'woodev-plugin-framework' ),
							'type'           => 'select',
							'required'       => true,
							'options_source' => true,
						],
					],
				]
			),
			array_merge(
				self::action(
					Site_Products_Bulk::ARCHIVE,
					__( 'Архивировать', 'woodev-plugin-framework' ),
					/* translators: %s: marketplace name. */
					sprintf( __( 'Остаток на маркетплейсе станет нулевым, затем карточка уйдёт в архив %s. Товар на сайте не меняется.', 'woodev-plugin-framework' ), $marketplace )
				),
				[ 'tone' => 'danger' ]
			),
			array_merge(
				self::action( Site_Products_Bulk::DETACH, __( 'Снять связь', 'woodev-plugin-framework' ), __( 'Сайт забудет карточку: товар можно будет выгрузить заново или связать с другой карточкой. Карточка на маркетплейсе не меняется.', 'woodev-plugin-framework' ) ),
				[ 'tone' => 'danger' ]
			),
			self::action( Site_Products_Bulk::RESET, __( 'Сбросить состояние', 'woodev-plugin-framework' ), __( 'Товар снова станет «Не связан». На маркетплейсе ничего не меняется: карточка остаётся там как была.', 'woodev-plugin-framework' ) ),
		];

		return array_values(
			array_filter(
				$actions,
				static function ( array $action ) use ( $withheld ): bool {
					return ! in_array( $action['id'], $withheld, true );
				}
			)
		);
	}

	/**
	 * A selection action that previews and then confirms.
	 *
	 * @param string $id       Action id.
	 * @param string $label    Label.
	 * @param string $sentence Sentence under the title of the confirmation window, empty for none.
	 * @return array<string,mixed>
	 */
	private static function action( string $id, string $label, string $sentence = '' ): array {
		return [
			'id'             => $id,
			'label'          => $label,
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => self::EDIT,
			'scope_sentence' => $sentence,
		];
	}

	/**
	 * Every sentence the page shows. The shell, the action dialog and the activity part read their strings from here.
	 *
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return [
			'empty'                 => __( 'Товаров не найдено. Добавьте аккаунт в настройках или измените фильтры.', 'woodev-plugin-framework' ),
			'empty_filtered'        => __( 'По этим фильтрам товаров нет.', 'woodev-plugin-framework' ),
			'error'                 => __( 'Не удалось загрузить товары. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'retry'                 => __( 'Повторить', 'woodev-plugin-framework' ),
			'reset_filters'         => __( 'Сбросить фильтры', 'woodev-plugin-framework' ),
			'expand_column'         => __( 'Варианты', 'woodev-plugin-framework' ),
			'expand'                => __( 'Показать варианты', 'woodev-plugin-framework' ),
			'collapse'              => __( 'Скрыть варианты', 'woodev-plugin-framework' ),
			'children_loading'      => __( 'Загружаем варианты…', 'woodev-plugin-framework' ),
			'children_more'         => __( 'Показать ещё варианты', 'woodev-plugin-framework' ),
			'actions_column'        => __( 'Действия', 'woodev-plugin-framework' ),
			'select_all'            => __( 'Выбрать все товары на странице', 'woodev-plugin-framework' ),
			'select_row'            => __( 'Выбрать', 'woodev-plugin-framework' ),
			'selection_limit'       => __( 'Выбрано предельное число товаров. Выполните действие и выберите остальные.', 'woodev-plugin-framework' ),
			'filter_any'            => __( 'Любое', 'woodev-plugin-framework' ),
			'per_page'              => __( 'Товаров на странице', 'woodev-plugin-framework' ),
			'action_cancel'         => __( 'Отмена', 'woodev-plugin-framework' ),
			'action_close'          => __( 'Закрыть', 'woodev-plugin-framework' ),
			'action_preview'        => __( 'Проверить', 'woodev-plugin-framework' ),
			'action_planning'       => __( 'Проверяем товары…', 'woodev-plugin-framework' ),
			'action_applying'       => __( 'Выполняем…', 'woodev-plugin-framework' ),
			'action_retry'          => __( 'Повторить', 'woodev-plugin-framework' ),
			'action_requested'      => __( 'Выбрано', 'woodev-plugin-framework' ),
			'action_eligible'       => __( 'Будет обработано', 'woodev-plugin-framework' ),
			'action_skipped'        => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_accepted'       => __( 'Принято', 'woodev-plugin-framework' ),
			'action_failed'         => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_status_done'    => __( 'Готово.', 'woodev-plugin-framework' ),
			'action_status_partial' => __( 'Выполнено частично: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_status_queued'  => __( 'Принято в работу. Ход работы виден в столбце «Состояние».', 'woodev-plugin-framework' ),
			'action_status_failed'  => __( 'Ничего не выполнено: причины перечислены ниже.', 'woodev-plugin-framework' ),
			'action_group_skipped'  => __( 'Пропущено', 'woodev-plugin-framework' ),
			'action_group_failed'   => __( 'Не удалось', 'woodev-plugin-framework' ),
			'action_group_warning'  => __( 'С предупреждением', 'woodev-plugin-framework' ),
			'action_warnings'       => __( 'Предупреждения', 'woodev-plugin-framework' ),
			'action_recovery'       => __( 'Ответа нет: действие могло выполниться. Мы обновили таблицу — проверьте состояние товаров.', 'woodev-plugin-framework' ),
			'action_error'          => __( 'Не удалось выполнить запрос. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'action_choose'         => __( 'Выберите…', 'woodev-plugin-framework' ),
			'action_options_more'   => __( 'Показать ещё', 'woodev-plugin-framework' ),
			'action_options_error'  => __( 'Не удалось загрузить список.', 'woodev-plugin-framework' ),
			'action_options_loading' => __( 'Загружаем список…', 'woodev-plugin-framework' ),
			'activity_stale'        => __( 'Данные о ходе работы устарели: обновляем.', 'woodev-plugin-framework' ),
			'activity_failed'       => __( 'Не удаётся получить ход работы. Попробуем ещё раз.', 'woodev-plugin-framework' ),
		];
	}
}

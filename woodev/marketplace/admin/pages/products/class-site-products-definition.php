<?php
/**
 * The definition of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Standard_Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine: columns, filters, sorts, the 12 bulk actions and every sentence the page shows. The
 * engine validates the result ({@see \Woodev\Framework\Admin\Records\Records_Definition::from_array()}), so a typo here is a developer
 * error caught on the first request.
 *
 * @since 2.1.4
 */
final class Site_Products_Definition {
	/**
	 * Product capability the write actions ask for.
	 *
	 * @since 2.1.8 Public: a bulk run checks it for the user who started it.
	 */
	public const EDIT = 'edit_products';

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.4
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
				'views'   => true,
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
	 * «Связать по артикулу»: the account-wide job that matches the cabinet's cards to the shop's products by article. It reaches the
	 * whole shop or, when the table lets the merchant choose, one category with its subcategories — never the ticked rows.
	 *
	 * @since 2.1.5
	 * @since 2.1.8 `$by_category` adds the choice «весь магазин / одна категория».
	 *
	 * @param string|null $scope_sentence The sentence that names the reach of the job, for a table whose rows cannot be selected. The
	 *                                    products table's own wording (which speaks of the marked products) when omitted.
	 * @param bool        $by_category    Whether the dialog asks «весь магазин / одна категория». Only the products table does: the
	 *                                    category list is served by its provider.
	 * @return array<string,mixed>
	 */
	public static function link_by_article( ?string $scope_sentence = null, bool $by_category = false ): array {
		$action = [
			'id'             => Site_Products_Bulk::LINK_BY_ARTICLE,
			'label'          => __( 'Связать по артикулу', 'woodev-plugin-framework' ),
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'account',
			'capability'     => 'manage_woocommerce',
			'scope_sentence' => $scope_sentence ?? __( 'Связывание идёт по всему магазину или по одной категории, не только по отмеченным товарам. По артикулу сопоставляются все карточки аккаунта.', 'woodev-plugin-framework' ),
		];

		if ( $by_category ) {
			$action['fields'] = self::link_scope_fields();
		}

		return $action;
	}

	/**
	 * The two fields of «Связать по артикулу»: where to link and, for one category, which. An empty «where» means the whole shop, so
	 * the one-click path of the old dialog stays.
	 *
	 * @since 2.1.8
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function link_scope_fields(): array {
		return [
			[
				'id'      => Site_Products_Bulk::LINK_WHERE,
				'label'   => __( 'Где связывать', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => [
					[
						'value' => Site_Products_Bulk::LINK_WHERE_SHOP,
						'label' => __( 'Весь магазин', 'woodev-plugin-framework' ),
					],
					[
						'value' => Site_Products_Bulk::LINK_WHERE_CATEGORY,
						'label' => __( 'Одна категория (вместе с подкатегориями)', 'woodev-plugin-framework' ),
					],
				],
				'help'    => __( 'Если ничего не выбрано, связываются товары всего магазина.', 'woodev-plugin-framework' ),
			],
			[
				'id'             => Site_Products_Bulk::LINK_CATEGORY,
				'label'          => __( 'Категория', 'woodev-plugin-framework' ),
				'type'           => 'select',
				'options_source' => true,
				'searchable'     => true,
				'help'           => __( 'Нужна, когда выбрана одна категория. Начните вводить название.', 'woodev-plugin-framework' ),
			],
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
			self::action( Site_Products_Bulk::EXPORT, self::label_of( Site_Products_Bulk::EXPORT ), __( 'Товары, прошедшие проверку, уйдут в очередь на отправку. Остальные останутся как есть — исправьте причину и повторите.', 'woodev-plugin-framework' ) ),
			self::link_by_article( null, true ),
			self::action( Site_Products_Bulk::SEND_STOCK, self::label_of( Site_Products_Bulk::SEND_STOCK ), __( 'Текущий остаток с сайта будет отправлен на маркетплейс в ближайшее время.', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SEND_PRICE, self::label_of( Site_Products_Bulk::SEND_PRICE ), __( 'Текущая цена с сайта будет отправлена на маркетплейс в ближайшее время.', 'woodev-plugin-framework' ) ),
			self::action( Site_Products_Bulk::SYNC_STOCK_ON, self::label_of( Site_Products_Bulk::SYNC_STOCK_ON ) ),
			self::action( Site_Products_Bulk::SYNC_STOCK_OFF, self::label_of( Site_Products_Bulk::SYNC_STOCK_OFF ) ),
			self::action( Site_Products_Bulk::SYNC_PRICE_ON, self::label_of( Site_Products_Bulk::SYNC_PRICE_ON ) ),
			self::action( Site_Products_Bulk::SYNC_PRICE_OFF, self::label_of( Site_Products_Bulk::SYNC_PRICE_OFF ) ),
			array_merge(
				self::action( Site_Products_Bulk::SET_WAREHOUSE, self::label_of( Site_Products_Bulk::SET_WAREHOUSE ), __( 'Остатки этих товаров будут отправляться на выбранный склад.', 'woodev-plugin-framework' ) ),
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
					self::label_of( Site_Products_Bulk::ARCHIVE ),
					/* translators: %s: marketplace name. */
					sprintf( __( 'Остаток на маркетплейсе станет нулевым, затем карточка уйдёт в архив %s. Товар на сайте не меняется.', 'woodev-plugin-framework' ), $marketplace )
				),
				[ 'tone' => 'danger' ]
			),
			array_merge(
				self::action( Site_Products_Bulk::DETACH, self::label_of( Site_Products_Bulk::DETACH ), __( 'Сайт забудет карточку: товар можно будет выгрузить заново или связать с другой карточкой. Карточка на маркетплейсе не меняется.', 'woodev-plugin-framework' ) ),
				[ 'tone' => 'danger' ]
			),
			self::action( Site_Products_Bulk::RESET, self::label_of( Site_Products_Bulk::RESET ), __( 'Товар снова станет «Не связан». На маркетплейсе ничего не меняется: карточка остаётся там как была.', 'woodev-plugin-framework' ) ),
		];

		$actions = array_merge( $actions, self::run_actions() );

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
			'all_matching'   => true,
		];
	}

	/**
	 * The merchant's name of a bulk action: the toolbar's label, and the name the account line gives a run.
	 *
	 * @since 2.1.8
	 *
	 * @param string $action Action id.
	 * @return string The id itself for an action with no label here.
	 */
	public static function label_of( string $action ): string {
		$labels = [
			Site_Products_Bulk::EXPORT        => __( 'Экспортировать / Обновить карточку', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_STOCK    => __( 'Отправить остатки', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_PRICE    => __( 'Отправить цены', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_STOCK_ON => __( 'Включить синхронизацию остатков', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_STOCK_OFF => __( 'Выключить синхронизацию остатков', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_PRICE_ON => __( 'Включить синхронизацию цен', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_PRICE_OFF => __( 'Выключить синхронизацию цен', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SET_WAREHOUSE => __( 'Выбрать склад', 'woodev-plugin-framework' ),
			Site_Products_Bulk::ARCHIVE       => __( 'Архивировать', 'woodev-plugin-framework' ),
			Site_Products_Bulk::DETACH        => __( 'Снять связь', 'woodev-plugin-framework' ),
			Site_Products_Bulk::RESET         => __( 'Сбросить состояние', 'woodev-plugin-framework' ),
		];

		return $labels[ $action ] ?? $action;
	}

	/**
	 * The two buttons of a bulk run's account line, declared as account actions the line runs with the id of the run it shows: they
	 * carry a required `hidden` field, so the toolbar never draws them on their own (#1795).
	 *
	 * «Остановить» stops the run; «Подробнее» is read-only and shows the stored report through the preview contract.
	 *
	 * @since 2.1.8
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function run_actions(): array {
		$run = [
			[
				'id'       => Site_Products_Bulk::RUN_FIELD,
				'label'    => __( 'Запуск', 'woodev-plugin-framework' ),
				'type'     => 'hidden',
				'required' => true,
			],
		];

		return [
			[
				'id'             => Site_Products_Bulk::RUN_STOP,
				'label'          => __( 'Остановить', 'woodev-plugin-framework' ),
				'effect'         => 'mutate',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'capability'     => self::EDIT,
				'scope_sentence' => __( 'Обработка остановится после текущей части. То, что уже поставлено в очередь, будет отправлено.', 'woodev-plugin-framework' ),
				'fields'         => $run,
			],
			[
				'id'             => Site_Products_Bulk::RUN_REPORT,
				'label'          => __( 'Подробнее', 'woodev-plugin-framework' ),
				'effect'         => 'read',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'capability'     => self::EDIT,
				'scope_sentence' => __( 'Итог действия по фильтру: что сделано и что пропущено.', 'woodev-plugin-framework' ),
				'fields'         => $run,
			],
		];
	}

	/**
	 * Every sentence the page shows. The shell, the action dialog and the activity part read their strings from here.
	 *
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return array_merge( self::shell_messages(), Standard_Messages::bulk(), Standard_Messages::views(), self::action_messages() );
	}

	/**
	 * The sentences of the table itself.
	 *
	 * @return array<string,string>
	 */
	private static function shell_messages(): array {
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
			'summary_label'         => __( 'Товаров', 'woodev-plugin-framework' ),
			'activity_stale'        => __( 'Данные о ходе работы устарели: обновляем.', 'woodev-plugin-framework' ),
			'activity_failed'       => __( 'Не удаётся получить ход работы. Попробуем ещё раз.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The sentences of the action dialog (the preview, the confirmation, the result), the same on every table of the page.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,string>
	 */
	public static function action_messages(): array {
		return [
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
		];
	}
}

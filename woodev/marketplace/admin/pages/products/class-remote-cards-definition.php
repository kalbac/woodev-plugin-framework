<?php
/**
 * The definition of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine: columns, the filters (the «Показать» filter swaps the cards for the near-match report), the two
 * account-wide actions, the row action «Связать с…», the report's two answers and every sentence the page shows. The engine validates the result ({@see \Woodev\Framework\Admin\Records\Records_Definition::from_array()}).
 *
 * The table lists a snapshot, in the marketplace's order, so it declares no sorting.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Definition {
	/**
	 * Row action: link one card to a product of the shop.
	 */
	public const LINK_TO = 'link_to';

	/**
	 * Row action: import this one card as a shop product. The records engine keeps one id space for the row and the bulk actions, so
	 * the bulk twin of this action has an id of its own ({@see self::IMPORT_SELECTED}); the server handles both alike.
	 */
	public const IMPORT = 'import';

	/**
	 * Bulk action: import the selected cards as shop products.
	 */
	public const IMPORT_SELECTED = 'import_selected';

	/**
	 * Most cards one import action takes: one page.
	 */
	public const MAX_IMPORT = 100;

	/**
	 * Row actions of the near-match report: «Это тот же товар» links the pair, «Нет, это разные» dismisses it.
	 */
	public const NEAR_CONFIRM = 'near_confirm';
	public const NEAR_DISMISS = 'near_dismiss';

	/**
	 * Filter that swaps the cards for the near-match report.
	 */
	public const SHOW_FILTER = 'show';

	/**
	 * Account action: read the cabinet's cards again (a background job; the table itself never calls the marketplace).
	 */
	public const REFRESH = 'refresh_list';

	/**
	 * Field of {@see self::LINK_TO}: the shop product.
	 */
	public const PRODUCT_FIELD = 'product';

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.5
	 *
	 * @param array<int,array{value:string,label:string}> $accounts    Accounts the user may open: the account selector's options.
	 * @param string                                      $marketplace Merchant-facing marketplace name.
	 * @param bool                                        $importable  Whether the adapter can import cards: only then the table offers «Импортировать» and selects rows.
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, string $marketplace, bool $importable = false ): array {
		return [
			'context'      => [
				'label'   => __( 'Аккаунт', 'woodev-plugin-framework' ),
				'options' => $accounts,
				'default' => (string) ( $accounts[0]['value'] ?? '' ),
			],
			'columns'      => self::columns(),
			'filters'      => self::filters(),
			'sorts'        => [ 'keys' => [] ],
			'pagination'   => [
				'default_per_page' => 20,
				'per_page_options' => [ 20, 50, 100 ],
				'max_per_page'     => 100,
				'max_selection'    => $importable ? self::MAX_IMPORT : 1,
			],
			'row_actions'  => self::row_actions( $importable ),
			'bulk_actions' => self::bulk_actions( $marketplace, $importable ),
			'activity'     => [
				'refresh_ms'     => 10000,
				'max_refresh_ms' => 60000,
				'stale_after_ms' => 60000,
				'id_limit'       => 100,
			],
			'messages'     => self::messages( $marketplace ),
			'analytics'    => null,
		];
	}

	/**
	 * @param bool $importable Whether the adapter can import cards.
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_actions( bool $importable ): array {
		$actions = [ self::link_to(), self::near_confirm(), self::near_dismiss() ];

		return $importable ? array_merge( [ self::import( self::IMPORT ) ], $actions ) : $actions;
	}

	/**
	 * @param string $marketplace Marketplace name.
	 * @param bool   $importable  Whether the adapter can import cards.
	 * @return array<int,array<string,mixed>>
	 */
	private static function bulk_actions( string $marketplace, bool $importable ): array {
		$actions = [ self::refresh( $marketplace ), Site_Products_Definition::link_by_article( __( 'Связывание идёт по всему магазину. По артикулу сопоставляются все карточки аккаунта.', 'woodev-plugin-framework' ) ) ];

		return $importable ? array_merge( [ self::import( self::IMPORT_SELECTED ) ], $actions ) : $actions;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns(): array {
		return [
			[
				'id'        => 'offer_id',
				'label'     => __( 'Артикул', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'required'  => true,
			],
			[
				'id'        => 'name',
				'label'     => __( 'Название', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
			],
			[
				'id'        => 'ids',
				'label'     => __( 'Номера на маркетплейсе', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'state',
				'label'     => __( 'Статус карточки', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'on_site',
				'label'     => __( 'На сайте', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'actions',
				'label'     => '',
				'cell_type' => 'actions',
			],
		];
	}

	/**
	 * The search box and «На сайте», which opens on «Нет на сайте»: the cards worth a look are the ones no product carries yet.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function filters(): array {
		return [
			[
				'id'    => 'q',
				'label' => __( 'Поиск по артикулу и названию', 'woodev-plugin-framework' ),
				'type'  => 'search',
			],
			[
				'id'      => 'on_site',
				'label'   => __( 'На сайте', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => [
					[
						'value' => Remote_Cards_Query::NOT_ON_SITE,
						'label' => __( 'Нет на сайте', 'woodev-plugin-framework' ),
					],
					[
						'value' => Remote_Cards_Query::ON_SITE,
						'label' => __( 'Уже на сайте', 'woodev-plugin-framework' ),
					],
				],
				'default' => Remote_Cards_Query::NOT_ON_SITE,
				'views'   => true,
			],
			[
				'id'      => self::SHOW_FILTER,
				'label'   => __( 'Показать', 'woodev-plugin-framework' ),
				'type'    => 'select',
				'options' => [
					[
						'value' => Remote_Cards_Query::SHOW_NEAR,
						'label' => __( 'Похожие артикулы — нужно решение', 'woodev-plugin-framework' ),
					],
				],
			],
		];
	}

	/**
	 * «Импортировать»: creates a shop product from each selected card, or links it to the product that already carries its article.
	 * It starts a remote read and creates products, so it needs the same right as «Обновить список».
	 *
	 * @param string $id {@see self::IMPORT} or {@see self::IMPORT_SELECTED}.
	 * @return array<string,mixed>
	 */
	private static function import( string $id ): array {
		return [
			'id'             => $id,
			'label'          => __( 'Импортировать', 'woodev-plugin-framework' ),
			'tone'           => 'primary',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'manage_woocommerce',
			'scope_sentence' => __( 'Для выбранных карточек будут созданы товары на сайте; если товар с таким артикулом уже есть, карточка свяжется с ним. Импорт идёт в фоне. На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Импортировать', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * «Это тот же товар»: links a pair of the near-match report. The pair is a local record of the last «Связать по артикулу» run, so
	 * nothing is sent to the marketplace.
	 *
	 * @return array<string,mixed>
	 */
	private static function near_confirm(): array {
		return [
			'id'             => self::NEAR_CONFIRM,
			'label'          => __( 'Это тот же товар', 'woodev-plugin-framework' ),
			'tone'           => 'primary',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'edit_products',
			'scope_sentence' => __( 'Карточка будет связана с товаром сайта, на который она похожа. На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Связать', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * «Нет, это разные»: removes a pair from the report; the next «Связать по артикулу» run does not offer it again until an article changes.
	 *
	 * @return array<string,mixed>
	 */
	private static function near_dismiss(): array {
		return [
			'id'             => self::NEAR_DISMISS,
			'label'          => __( 'Нет, это разные', 'woodev-plugin-framework' ),
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'edit_products',
			'scope_sentence' => __( 'Пара будет убрана из списка, товар и карточка останутся несвязанными.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Убрать из списка', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * «Связать с…»: the merchant picks the product the card belongs to. The product field searches the shop's products.
	 *
	 * @return array<string,mixed>
	 */
	private static function link_to(): array {
		return [
			'id'             => self::LINK_TO,
			'label'          => __( 'Связать с…', 'woodev-plugin-framework' ),
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'edit_products',
			'scope_sentence' => __( 'Карточка будет связана с выбранным товаром сайта. На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Связать', 'woodev-plugin-framework' ),
			'fields'         => [
				[
					'id'             => self::PRODUCT_FIELD,
					'label'          => __( 'Товар сайта', 'woodev-plugin-framework' ),
					'type'           => 'select',
					'required'       => true,
					'options_source' => true,
					'searchable'     => true,
					'help'           => __( 'Начните вводить название или артикул. У вариативного товара выберите вариант.', 'woodev-plugin-framework' ),
				],
			],
		];
	}

	/**
	 * «Обновить список»: starts the job that reads the cabinet; the table shows what it stored.
	 *
	 * @param string $marketplace Marketplace name.
	 * @return array<string,mixed>
	 */
	private static function refresh( string $marketplace ): array {
		return [
			'id'             => self::REFRESH,
			'label'          => __( 'Обновить список', 'woodev-plugin-framework' ),
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'account',
			'capability'     => 'manage_woocommerce',
			'scope_sentence' => sprintf(
				/* translators: 1: marketplace name, 2: how many minutes the list is kept. */
				__( 'Весь аккаунт — список карточек загрузится с маркетплейса %1$s заново и будет храниться %2$d минут. Товары на сайте и карточки на маркетплейсе не меняются.', 'woodev-plugin-framework' ),
				$marketplace,
				(int) ( Cards_Snapshot_Store::TTL / 60 )
			),
		];
	}

	/**
	 * Every sentence the page shows: the table's own, and the action dialog's, which the products table shares.
	 *
	 * @param string $marketplace Marketplace name.
	 * @return array<string,string>
	 */
	private static function messages( string $marketplace ): array {
		return array_merge(
			Site_Products_Definition::action_messages(),
			Standard_Messages::bulk(),
			Standard_Messages::views(),
			[
				'empty'                  => __( 'Карточек не найдено. Нажмите «Обновить список» или измените фильтры.', 'woodev-plugin-framework' ),
				'empty_filtered'         => __( 'По этим фильтрам карточек нет.', 'woodev-plugin-framework' ),
				/* translators: %s: marketplace name. */
				'error'                  => sprintf( __( 'Не удалось показать список карточек %s. Повторите чуть позже.', 'woodev-plugin-framework' ), $marketplace ),
				'retry'                  => __( 'Повторить', 'woodev-plugin-framework' ),
				'reset_filters'          => __( 'Сбросить фильтры', 'woodev-plugin-framework' ),
				'actions_column'         => __( 'Действия', 'woodev-plugin-framework' ),
				'select_all'             => __( 'Выбрать все карточки на странице', 'woodev-plugin-framework' ),
				'select_row'             => __( 'Выбрать', 'woodev-plugin-framework' ),
				'selection_limit'        => __( 'Выбрано предельное число карточек. Выполните действие и выберите остальные.', 'woodev-plugin-framework' ),
				'filter_any'             => __( 'Все карточки', 'woodev-plugin-framework' ),
				'per_page'               => __( 'Карточек на странице', 'woodev-plugin-framework' ),
				'summary_label'          => __( 'Карточек', 'woodev-plugin-framework' ),
				'action_planning'        => __( 'Проверяем…', 'woodev-plugin-framework' ),
				'action_recovery'        => __( 'Ответа нет: действие могло выполниться. Мы обновили таблицу — проверьте её.', 'woodev-plugin-framework' ),
				'action_options_loading' => __( 'Ищем товары…', 'woodev-plugin-framework' ),
				'activity_stale'         => __( 'Данные устарели: обновляем.', 'woodev-plugin-framework' ),
				'activity_failed'        => __( 'Не удаётся узнать, загружается ли список. Попробуем ещё раз.', 'woodev-plugin-framework' ),
			]
		);
	}
}

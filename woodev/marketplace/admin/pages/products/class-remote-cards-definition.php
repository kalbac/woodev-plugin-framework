<?php
/**
 * The definition of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Standard_Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the table for the records engine: columns, the filters (the «Показать» filter swaps the cards for the near-match report), the two
 * account-wide actions, the row action «Связать с…», the report's two answers and every sentence the page shows. The engine validates the result ({@see \Woodev\Framework\Admin\Records\Records_Definition::from_array()}).
 *
 * The table lists the stored list, in the marketplace's order, so it declares no sorting.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Definition {
	/**
	 * Row action: link one card to a product of the shop.
	 */
	public const LINK_TO = 'link_to';

	/**
	 * Row action: open the shop product that carries the card.
	 */
	public const OPEN_PRODUCT = 'open_product';

	/**
	 * Row action: open the card in the seller's cabinet on the marketplace.
	 */
	public const OPEN_REMOTE = 'open_remote';

	/**
	 * Row action: the site forgets the card; the product can then be exported again or linked to another card.
	 */
	public const UNLINK = 'unlink';

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
			'row_actions'  => self::row_actions( $marketplace, $importable ),
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
	 * @param string $marketplace Marketplace name.
	 * @param bool   $importable  Whether the adapter can import cards.
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_actions( string $marketplace, bool $importable ): array {
		$actions = [ self::open_remote( $marketplace ), self::open_product(), self::unlink(), self::link_to(), self::near_confirm(), self::near_dismiss() ];

		return $importable ? array_merge( [ self::import( self::IMPORT ) ], $actions ) : $actions;
	}

	/**
	 * @param string $marketplace Marketplace name.
	 * @param bool   $importable  Whether the adapter can import cards.
	 * @return array<int,array<string,mixed>>
	 */
	private static function bulk_actions( string $marketplace, bool $importable ): array {
		$actions = [ self::refresh( $marketplace ), Site_Products_Definition::link_by_article( __( 'Связывание идёт по всему магазину или по одной категории. По артикулу сопоставляются все карточки аккаунта.', 'woodev-plugin-framework' ), true ) ];

		return $importable ? array_merge( [ self::import( self::IMPORT_SELECTED ) ], $actions, self::run_actions() ) : $actions;
	}

	/**
	 * Photo, name (a link to the card in the cabinet), article, state, price, stock, the product that carries the card, and the actions.
	 * The marketplace's numbers of a card are not a column: they are in its state badge's tooltip.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns(): array {
		return [
			[
				'id'        => 'image',
				'label'     => __( 'Фото', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'name',
				'label'     => __( 'Название', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
				'required'  => true,
			],
			[
				'id'        => 'offer_id',
				'label'     => __( 'Артикул', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'required'  => true,
			],
			[
				'id'        => 'state',
				'label'     => __( 'Состояние', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'price',
				'label'     => __( 'Цена', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'help'      => __( 'Цена карточки на маркетплейсе. «—» значит, что значения нет.', 'woodev-plugin-framework' ),
			],
			[
				'id'        => 'stock',
				'label'     => __( 'Остаток', 'woodev-plugin-framework' ),
				'cell_type' => 'text',
				'help'      => __( 'Остаток карточки на маркетплейсе. «—» значит, что значения нет.', 'woodev-plugin-framework' ),
			],
			[
				'id'        => 'on_site',
				'label'     => __( 'На сайте', 'woodev-plugin-framework' ),
				'cell_type' => 'stack',
			],
			[
				'id'        => 'actions',
				'label'     => __( 'Действия', 'woodev-plugin-framework' ),
				'cell_type' => 'actions',
			],
		];
	}

	/**
	 * The search box and «На сайте», which opens on «Все»: a view that opens on «Нет на сайте» showed «В кабинете нет ни одной карточки.» for a cabinet whose cards were all on the site.
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
	 * It starts a remote read and creates products, so it needs the same right as «Обновить список». The bulk twin also runs over every
	 * card under the filter, in the background (#1796).
	 *
	 * @param string $id {@see self::IMPORT} or {@see self::IMPORT_SELECTED}.
	 * @return array<string,mixed>
	 */
	private static function import( string $id ): array {
		$action = [
			'id'             => $id,
			'label'          => __( 'Импортировать', 'woodev-plugin-framework' ),
			'icon'           => 'download',
			'tone'           => 'primary',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'manage_woocommerce',
			'scope_sentence' => __( 'Для выбранных карточек будут созданы товары на сайте; если товар с таким артикулом уже есть, карточка свяжется с ним. Импорт идёт в фоне.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Импортировать', 'woodev-plugin-framework' ),
		];

		return self::IMPORT_SELECTED === $id ? $action + [ 'all_matching' => true ] : $action;
	}

	/**
	 * The two buttons of the account line of a bulk run — «Остановить» and «Подробнее» — declared as account actions the line runs
	 * with the id of the run it shows. They carry a required `hidden` field, so the toolbar never draws them on their own. The line
	 * shows whichever run the account has, so the table carries them whenever it can start the import.
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
				'capability'     => 'manage_woocommerce',
				'scope_sentence' => __( 'Обработка остановится после текущей части. То, что уже поставлено в очередь, будет обработано.', 'woodev-plugin-framework' ),
				'fields'         => $run,
			],
			[
				'id'             => Site_Products_Bulk::RUN_REPORT,
				'label'          => __( 'Подробнее', 'woodev-plugin-framework' ),
				'effect'         => 'read',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'capability'     => 'manage_woocommerce',
				'scope_sentence' => __( 'Итог действия по фильтру: что сделано и что пропущено.', 'woodev-plugin-framework' ),
				'fields'         => $run,
			],
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
			'scope_sentence' => __( 'Карточка будет связана с товаром сайта, на который она похожа.', 'woodev-plugin-framework' ),
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
	 * «Открыть товар»: the edit screen of the product that carries the card. A link, so it needs no dialog.
	 *
	 * @return array<string,mixed>
	 */
	private static function open_product(): array {
		return [
			'id'          => self::OPEN_PRODUCT,
			'label'       => __( 'Открыть товар', 'woodev-plugin-framework' ),
			'icon'        => 'edit',
			'effect'      => 'read',
			'interaction' => 'link',
		];
	}

	/**
	 * «Открыть на {marketplace}»: the card in the seller's cabinet, in a new tab. A link, so it needs no dialog.
	 *
	 * @param string $marketplace Marketplace name.
	 * @return array<string,mixed>
	 */
	private static function open_remote( string $marketplace ): array {
		return [
			'id'          => self::OPEN_REMOTE,
			/* translators: %s: marketplace name. */
			'label'       => sprintf( __( 'Открыть на %s', 'woodev-plugin-framework' ), $marketplace ),
			'icon'        => 'external',
			'effect'      => 'read',
			'interaction' => 'link',
		];
	}

	/**
	 * «Отвязать»: the site forgets the card. It is the card's twin of the products table's «Снять связь»; the same service does it, and
	 * nothing is sent to the marketplace.
	 *
	 * @return array<string,mixed>
	 */
	private static function unlink(): array {
		return [
			'id'             => self::UNLINK,
			'label'          => __( 'Отвязать', 'woodev-plugin-framework' ),
			'icon'           => 'editor-unlink',
			'tone'           => 'danger',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'edit_products',
			'scope_sentence' => __( 'Сайт забудет карточку: товар можно будет выгрузить заново или связать с другой карточкой. Карточка на маркетплейсе не меняется.', 'woodev-plugin-framework' ),
			'confirm_label'  => __( 'Отвязать', 'woodev-plugin-framework' ),
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
			'label'          => __( 'Связать с товаром…', 'woodev-plugin-framework' ),
			'icon'           => 'admin-links',
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => 'edit_products',
			'scope_sentence' => __( 'Карточка будет связана с выбранным товаром сайта.', 'woodev-plugin-framework' ),
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
			'tone'           => 'primary',
			// Account-wide and harmless: it starts on the click, and the account line above the table shows that it is running.
			'skip_preview'   => true,
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'account',
			'capability'     => 'manage_woocommerce',
			'scope_sentence' => sprintf(
				/* translators: %s: marketplace name. */
				__( 'Весь аккаунт — список карточек загрузится с маркетплейса %s заново. Он обновляется и сам: раз в сутки и когда маркетплейс сообщает о новой или изменённой карточке. Товары на сайте и карточки на маркетплейсе не меняются.', 'woodev-plugin-framework' ),
				$marketplace
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
			Standard_Messages::matching( __( 'карточки', 'woodev-plugin-framework' ) ),
			Standard_Messages::views(),
			Standard_Messages::pager(),
			[
				/* translators: %s: marketplace name. */
				'description'            => sprintf( __( 'На этой странице отображаются карточки товаров из вашего кабинета %s. Карточки можно загрузить в магазин или связать с товарами сайта, чтобы остатки и цены обновлялись автоматически.', 'woodev-plugin-framework' ), $marketplace ),
				'empty'                  => __( 'Список карточек ещё не загружен.', 'woodev-plugin-framework' ),
				'empty_action'           => self::REFRESH,
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
				'action_planning'        => __( 'Проверяем…', 'woodev-plugin-framework' ),
				'action_recovery'        => __( 'Ответа нет: действие могло выполниться. Мы обновили таблицу — проверьте её.', 'woodev-plugin-framework' ),
				'action_options_loading' => __( 'Ищем товары…', 'woodev-plugin-framework' ),
				'activity_stale'         => __( 'Данные устарели: обновляем.', 'woodev-plugin-framework' ),
				'activity_failed'        => __( 'Не удаётся узнать, загружается ли список. Попробуем ещё раз.', 'woodev-plugin-framework' ),
			]
		);
	}
}

<?php
/**
 * The definition of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

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
	 * @return array<string,mixed>
	 */
	public static function build( array $accounts, string $marketplace ): array {
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
				'max_selection'    => 1,
			],
			'row_actions'  => [ self::link_to(), self::near_confirm(), self::near_dismiss() ],
			'bulk_actions' => [ self::refresh( $marketplace ), Site_Products_Definition::link_by_article( __( 'Связывание идёт по всему магазину. По артикулу сопоставляются все карточки аккаунта.', 'woodev-plugin-framework' ) ) ],
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
			[
				'empty'                  => __( 'Карточек не найдено. Нажмите «Обновить список» или измените фильтры.', 'woodev-plugin-framework' ),
				'empty_filtered'         => __( 'По этим фильтрам карточек нет.', 'woodev-plugin-framework' ),
				/* translators: %s: marketplace name. */
				'error'                  => sprintf( __( 'Не удалось показать список карточек %s. Повторите чуть позже.', 'woodev-plugin-framework' ), $marketplace ),
				'retry'                  => __( 'Повторить', 'woodev-plugin-framework' ),
				'reset_filters'          => __( 'Сбросить фильтры', 'woodev-plugin-framework' ),
				'actions_column'         => __( 'Действия', 'woodev-plugin-framework' ),
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

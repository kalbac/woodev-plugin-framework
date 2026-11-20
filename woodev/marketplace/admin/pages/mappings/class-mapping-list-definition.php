<?php
/**
 * The definition of the «Сопоставления» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the mappings list for the records engine: the v1 columns (the marketplace category, the shop categories, the profile, the
 * attributes the mapping fills, the products it covers) and the sentences of the table.
 *
 * @since 2.1.13
 */
final class Mapping_List_Definition {
	/**
	 * The table id.
	 */
	public const TABLE = 'mappings';

	/**
	 * The sort keys, the first the default.
	 */
	public const SORT_KEYS = [ 'name', 'category', 'shop', 'profile', 'attributes', 'products' ];

	/**
	 * Builds the definition.
	 *
	 * @since 2.1.13
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		return List_Definition::build( self::columns(), self::SORT_KEYS, self::messages() );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns(): array {
		return [
			List_Definition::column( 'name', __( 'Название', 'woodev-plugin-framework' ), 'link', [ 'required' => true ] ),
			List_Definition::column( 'category', __( 'Категория маркетплейса', 'woodev-plugin-framework' ), 'text' ),
			List_Definition::column( 'shop', __( 'Категории магазина', 'woodev-plugin-framework' ), 'text' ),
			List_Definition::column( 'profile', __( 'Профиль', 'woodev-plugin-framework' ), 'link' ),
			List_Definition::column(
				'attributes',
				__( 'Атрибуты', 'woodev-plugin-framework' ),
				'badge',
				[ 'help' => __( 'Товары, у которых не заполнены обязательные атрибуты категории, выгрузить на маркетплейс нельзя.', 'woodev-plugin-framework' ) ]
			),
			List_Definition::column( 'products', __( 'Товаров', 'woodev-plugin-framework' ), 'number', [ 'align' => 'right' ] ),
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return [
			'description' => __( 'На этой странице настраивается соответствие категорий вашего магазина категориям Ozon и указывается, откуда брать значения характеристик товаров.', 'woodev-plugin-framework' ),
			'empty'       => __( 'Сопоставлений пока нет. Сопоставление связывает категории вашего магазина с категориями маркетплейса: так товары попадают в нужный раздел и получают нужные характеристики.', 'woodev-plugin-framework' ),
			'error'       => __( 'Не удалось загрузить сопоставления. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'per_page'    => __( 'Сопоставлений на странице', 'woodev-plugin-framework' ),
		];
	}
}

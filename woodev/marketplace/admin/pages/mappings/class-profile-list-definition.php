<?php
/**
 * The definition of the «Профили» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the profiles list for the records engine: the profile's name, what it sets (with the values, as v1 showed them) and the
 * mappings that choose it.
 *
 * @since 2.1.13
 */
final class Profile_List_Definition {
	/**
	 * The table id.
	 */
	public const TABLE = 'profiles';

	/**
	 * The sort keys, the first the default.
	 */
	public const SORT_KEYS = [ 'name', 'properties', 'used_in' ];

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
			List_Definition::column( 'properties', __( 'Свойства профиля', 'woodev-plugin-framework' ), 'text' ),
			List_Definition::column( 'used_in', __( 'Используется в', 'woodev-plugin-framework' ), 'text' ),
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function messages(): array {
		return [
			'description' => __( 'На этой странице хранятся профили выгрузки — общие правила для габаритов, веса, НДС и цены. Профиль настраивается один раз и выбирается в нужных сопоставлениях.', 'woodev-plugin-framework' ),
			'empty'       => __( 'Профилей пока нет. Профиль — это набор общих настроек для группы товаров, например габариты и вес по умолчанию, ставка НДС, правило цены. Создайте его один раз и выбирайте в сопоставлениях.', 'woodev-plugin-framework' ),
			'error'       => __( 'Не удалось загрузить профили. Повторите чуть позже.', 'woodev-plugin-framework' ),
			'per_page'    => __( 'Профилей на странице', 'woodev-plugin-framework' ),
		];
	}
}

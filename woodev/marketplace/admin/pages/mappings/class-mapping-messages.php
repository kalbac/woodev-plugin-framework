<?php
/**
 * Merchant-facing sentences of the mapping and profile editors.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * Every error and notice the editors raise, in one place, so the input classes, the editors and the controller word a
 * case the same way and the view never has to compose a sentence (Rule 9: PHP owns the strings).
 *
 * @since 2.0.30
 */
final class Mapping_Messages {
	/**
	 * A request body that does not have the declared shape.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function malformed(): string {
		return __( 'Форма отправлена в неверном виде. Обновите страницу и попробуйте снова.', 'woodev-plugin-framework' );
	}

	/**
	 * The mapping label is empty.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function mapping_name_required(): string {
		return __( 'Введите название сопоставления — по нему вы найдёте его в списке.', 'woodev-plugin-framework' );
	}

	/**
	 * The profile label is empty.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function profile_name_required(): string {
		return __( 'Введите название профиля.', 'woodev-plugin-framework' );
	}

	/**
	 * A label longer than the limit.
	 *
	 * @since 2.0.30
	 *
	 * @param int $limit Longest label.
	 * @return string
	 */
	public static function name_too_long( int $limit ): string {
		/* translators: %d: the longest label. */
		return sprintf( __( 'Название слишком длинное — не больше %d символов.', 'woodev-plugin-framework' ), $limit );
	}

	/**
	 * The chosen marketplace category is not one the marketplace offers.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function unknown_category(): string {
		return __( 'Такой категории нет в каталоге маркетплейса. Выберите её из списка.', 'woodev-plugin-framework' );
	}

	/**
	 * The category list has not been loaded, so no category can be saved.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function categories_not_loaded(): string {
		return __( 'Список категорий маркетплейса ещё не загружен — сохранить категорию пока нельзя.', 'woodev-plugin-framework' );
	}

	/**
	 * The attribute table of a newly chosen category did not come with the form.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function attributes_not_loaded(): string {
		return __( 'Характеристики выбранной категории маркетплейса ещё не загрузились. Подождите, пока появится таблица, и сохраните снова.', 'woodev-plugin-framework' );
	}

	/**
	 * A source row names no usable origin.
	 *
	 * @since 2.0.30
	 *
	 * @param string $attribute Attribute name.
	 * @return string
	 */
	public static function attribute_source_missing( string $attribute ): string {
		/* translators: %s: attribute name. */
		return sprintf( __( 'Атрибут «%s»: выберите, откуда брать значение.', 'woodev-plugin-framework' ), $attribute );
	}

	/**
	 * A dictionary attribute has a fixed source without a chosen value.
	 *
	 * @since 2.0.30
	 *
	 * @param string $attribute Attribute name.
	 * @return string
	 */
	public static function dictionary_value_missing( string $attribute ): string {
		/* translators: %s: attribute name. */
		return sprintf( __( 'Атрибут «%s»: найдите значение в списке маркетплейса и выберите его.', 'woodev-plugin-framework' ), $attribute );
	}

	/**
	 * A free-text attribute has a fixed source without a value.
	 *
	 * @since 2.0.30
	 *
	 * @param string $attribute Attribute name.
	 * @return string
	 */
	public static function fixed_value_missing( string $attribute ): string {
		/* translators: %s: attribute name. */
		return sprintf( __( 'Атрибут «%s»: введите значение.', 'woodev-plugin-framework' ), $attribute );
	}

	/**
	 * The mapping to change or delete does not exist.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function mapping_not_found(): string {
		return __( 'Сопоставление не найдено. Обновите страницу.', 'woodev-plugin-framework' );
	}

	/**
	 * The profile to change or delete does not exist.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function profile_not_found(): string {
		return __( 'Профиль не найден. Обновите страницу.', 'woodev-plugin-framework' );
	}

	/**
	 * The profile chosen in a mapping does not exist.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function chosen_profile_missing(): string {
		return __( 'Выбранный профиль не найден. Выберите другой.', 'woodev-plugin-framework' );
	}

	/**
	 * A shop category already belongs to another mapping.
	 *
	 * @since 2.0.30
	 *
	 * @param string $category Shop category name.
	 * @param string $mapping  Name of the mapping that holds it.
	 * @return string
	 */
	public static function category_taken( string $category, string $mapping ): string {
		return sprintf(
			/* translators: 1: category name, 2: mapping name. */
			__( 'Категория «%1$s» уже входит в сопоставление «%2$s» — уберите её там или здесь.', 'woodev-plugin-framework' ),
			$category,
			$mapping
		);
	}

	/**
	 * The mapping was saved but another save took some of its categories first.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function categories_partly_taken(): string {
		return __( 'Сопоставление сохранено, но часть категорий уже занята другим сопоставлением и не добавлена. Проверьте список категорий.', 'woodev-plugin-framework' );
	}

	/**
	 * The database refused a write.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function save_failed(): string {
		return __( 'Не удалось сохранить. Повторите позже.', 'woodev-plugin-framework' );
	}

	/**
	 * A mapping cannot be deleted while products name it.
	 *
	 * @since 2.0.30
	 *
	 * @param int $products Products that chose the mapping by hand.
	 * @return string
	 */
	public static function mapping_in_use( int $products ): string {
		/* translators: %d: number of products. */
		return sprintf( __( 'Нельзя удалить: сопоставление выбрано вручную у товаров (%d).', 'woodev-plugin-framework' ), $products );
	}

	/**
	 * A profile cannot be deleted while mappings use it.
	 *
	 * @since 2.0.30
	 *
	 * @param int $mappings Mappings that name the profile.
	 * @return string
	 */
	public static function profile_in_use( int $mappings ): string {
		/* translators: %d: number of mappings. */
		return sprintf( __( 'Нельзя удалить: профиль выбран в сопоставлениях (%d). Сначала выберите другой профиль там.', 'woodev-plugin-framework' ), $mappings );
	}

	/**
	 * The marketplace could not be asked.
	 *
	 * @since 2.0.30
	 *
	 * @param string $reason What the adapter reported.
	 * @return string
	 */
	public static function marketplace_failure( string $reason ): string {
		/* translators: %s: the reason the marketplace or the plugin gave. */
		return sprintf( __( 'Не удалось получить данные маркетплейса: %s', 'woodev-plugin-framework' ), $reason );
	}

	/**
	 * The attribute has no dictionary to search.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function no_dictionary(): string {
		return __( 'У этой характеристики нет словаря маркетплейса.', 'woodev-plugin-framework' );
	}

	/**
	 * Suffix of the name given to a copied mapping.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function copy_suffix(): string {
		return __( '(копия)', 'woodev-plugin-framework' );
	}

	/**
	 * One line of the product check: how many products fail for the same first reason.
	 *
	 * @since 2.0.30
	 *
	 * @param int    $count   Products.
	 * @param string $message The reason.
	 * @return string
	 */
	public static function check_group( int $count, string $message ): string {
		/* translators: 1: number of products, 2: the reason they cannot be sent. */
		return sprintf( __( 'Товаров: %1$d — %2$s', 'woodev-plugin-framework' ), $count, $message );
	}

	/**
	 * The progress line while a long check runs.
	 *
	 * @since 2.0.30
	 *
	 * @param int $checked Products checked so far.
	 * @param int $total   Products the mapping covers.
	 * @return string
	 */
	public static function check_progress( int $checked, int $total ): string {
		/* translators: 1: products checked, 2: products in the mapping. */
		return sprintf( __( 'Проверено %1$d из %2$d…', 'woodev-plugin-framework' ), $checked, $total );
	}

	/**
	 * The result line of a finished check.
	 *
	 * @since 2.0.30
	 *
	 * @param int $checked Products checked.
	 * @param int $ok      Products with no problem.
	 * @return string
	 */
	public static function check_summary( int $checked, int $ok ): string {
		if ( 0 === $checked ) {
			return __( 'В этом сопоставлении нет товаров: выберите для него категории магазина.', 'woodev-plugin-framework' );
		}

		if ( $ok === $checked ) {
			return __( 'Замечаний нет: все товары готовы к выгрузке.', 'woodev-plugin-framework' );
		}

		/* translators: 1: products checked, 2: products with no problem. */
		return sprintf( __( 'Проверено товаров: %1$d, без замечаний: %2$d.', 'woodev-plugin-framework' ), $checked, $ok );
	}

	/**
	 * A profile fallback is not a positive number.
	 *
	 * @since 2.0.30
	 *
	 * @param string $field Field label (width, height, depth, weight).
	 * @return string
	 */
	public static function fallback_invalid( string $field ): string {
		/* translators: %s: field label (width, height, depth, weight). */
		return sprintf( __( 'Запасное значение «%s»: введите число больше нуля или оставьте поле пустым.', 'woodev-plugin-framework' ), $field );
	}

	/**
	 * A source set to a custom field names no field.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function custom_field_missing(): string {
		return __( 'Укажите название произвольного поля там, где выбран источник «Произвольное поле».', 'woodev-plugin-framework' );
	}

	/**
	 * The VAT rate is not on the list.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function vat_invalid(): string {
		return __( 'Выберите ставку НДС из списка.', 'woodev-plugin-framework' );
	}

	/**
	 * The exchange rate is not a positive number.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function rate_invalid(): string {
		return __( 'Курс: введите число больше нуля, например 12.5.', 'woodev-plugin-framework' );
	}

	/**
	 * The price-from-a-field rule names no field, or one the marketplace cannot read.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function price_field_invalid(): string {
		return __( 'Укажите название произвольного поля товара, из которого брать цену для маркетплейса.', 'woodev-plugin-framework' );
	}

	/**
	 * The profile price rule does not parse.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function price_rule_invalid(): string {
		return __( 'Правило цены заполнено неверно: наценка и минимальная цена — числа (минимальная цена — от 1 до 100 процентов).', 'woodev-plugin-framework' );
	}
}

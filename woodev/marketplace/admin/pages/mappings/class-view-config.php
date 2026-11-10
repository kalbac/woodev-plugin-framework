<?php
/**
 * Everything the mappings view shows that is not a record: its sentences and the options of its selects.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Source;

defined( 'ABSPATH' ) || exit;

/**
 * The view reads every word and every option from here (Rule 9): it holds no copy and no domain list of its own, so a missing
 * key renders blank instead of falling back to a hidden default.
 *
 * @since 2.0.30
 */
final class View_Config {
	/**
	 * The sentences of the view, by key.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return array_merge( self::list_messages(), self::mapping_messages(), self::profile_messages() );
	}

	/**
	 * The options of the profile form.
	 *
	 * @since 2.0.30
	 *
	 * @param Profile_Choices   $choices      What the form offers.
	 * @param Price_Source|null $price_source Price source of the marketplace, whose own currency rule the form may offer.
	 * @return array<string,mixed>
	 */
	public static function profile_form( Profile_Choices $choices, ?Price_Source $price_source ): array {
		$sources = [];
		foreach ( $choices->sources() as $setting => $kinds ) {
			$sources[] = [
				'setting' => $setting,
				'title'   => $choices->titles()[ $setting ] ?? $setting,
				'kinds'   => self::options( $kinds ),
				'default' => $choices->default_for( $setting ),
				'custom'  => Profile_Choices::KIND_META,
			];
		}

		return [
			'sources'        => $sources,
			'vat_rates'      => self::options( $choices->vat_rates() ),
			'weight_units'   => self::options(
				[
					'g'  => __( 'граммы', 'woodev-plugin-framework' ),
					'kg' => __( 'килограммы', 'woodev-plugin-framework' ),
				]
			),
			'dimension_units' => self::options(
				[
					'mm' => __( 'миллиметры', 'woodev-plugin-framework' ),
					'cm' => __( 'сантиметры', 'woodev-plugin-framework' ),
					'm'  => __( 'метры', 'woodev-plugin-framework' ),
				]
			),
			'currency_field' => null === $price_source ? null : $price_source->label(),
			'price'          => self::price_options(),
		];
	}

	/**
	 * Turns a `value => label` map into the list the selects take.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,string> $map Map.
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function options( array $map ): array {
		$options = [];

		foreach ( $map as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => (string) $label,
			];
		}

		return $options;
	}

	/**
	 * The choices of the price-rule fields.
	 *
	 * @return array<string,array<int,array{value:string,label:string}>>
	 */
	private static function price_options(): array {
		return [
			'base'           => self::options(
				[
					Price_Rule::BASE_ACTIVE  => __( 'Текущая цена (с учётом скидки)', 'woodev-plugin-framework' ),
					Price_Rule::BASE_REGULAR => __( 'Обычная цена', 'woodev-plugin-framework' ),
				]
			),
			'markup_kind'    => self::options(
				[
					Price_Rule::MARKUP_NONE    => __( 'Без наценки', 'woodev-plugin-framework' ),
					Price_Rule::MARKUP_FIXED   => __( 'Фиксированная сумма', 'woodev-plugin-framework' ),
					Price_Rule::MARKUP_PERCENT => __( 'Процент', 'woodev-plugin-framework' ),
				]
			),
			'rounding'       => self::options(
				[
					Price_Rule::ROUND_NONE    => __( 'Не округлять', 'woodev-plugin-framework' ),
					Price_Rule::ROUND_TO_1    => __( 'До рубля', 'woodev-plugin-framework' ),
					Price_Rule::ROUND_TO_10   => __( 'До десяти рублей', 'woodev-plugin-framework' ),
					Price_Rule::ROUND_ENDS_99 => __( 'До цены на 99', 'woodev-plugin-framework' ),
				]
			),
			'old_price_rule' => self::options(
				[
					Price_Rule::OLD_PRICE_ON_SALE => __( 'Показывать старую цену при скидке', 'woodev-plugin-framework' ),
					Price_Rule::OLD_PRICE_OFF     => __( 'Не показывать старую цену', 'woodev-plugin-framework' ),
				]
			),
		];
	}

	/**
	 * Sentences shared by both tabs and the lists.
	 *
	 * @return array<string,string>
	 */
	private static function list_messages(): array {
		return [
			'tabMappings'     => __( 'Сопоставления', 'woodev-plugin-framework' ),
			'tabProfiles'     => __( 'Профили', 'woodev-plugin-framework' ),
			'loading'         => __( 'Загрузка…', 'woodev-plugin-framework' ),
			'requestFailed'   => __( 'Не удалось выполнить запрос. Обновите страницу и повторите.', 'woodev-plugin-framework' ),
			'addMapping'      => __( 'Добавить сопоставление', 'woodev-plugin-framework' ),
			'addProfile'      => __( 'Добавить профиль', 'woodev-plugin-framework' ),
			'emptyMappings'   => __( 'Сопоставлений пока нет. Добавьте первое — оно задаёт, какой категории маркетплейса соответствуют категории вашего магазина.', 'woodev-plugin-framework' ),
			'emptyProfiles'   => __( 'Профилей пока нет. Профиль задаёт общие правила выгрузки: откуда брать данные, запасные размеры, цену.', 'woodev-plugin-framework' ),
			'chooseMapping'   => __( 'Выберите сопоставление слева или добавьте новое.', 'woodev-plugin-framework' ),
			'chooseProfile'   => __( 'Выберите профиль слева или добавьте новый.', 'woodev-plugin-framework' ),
			'columnName'      => __( 'Название', 'woodev-plugin-framework' ),
			'columnCategory'  => __( 'Категория маркетплейса', 'woodev-plugin-framework' ),
			'columnShop'      => __( 'Категорий магазина', 'woodev-plugin-framework' ),
			'columnProducts'  => __( 'Товаров', 'woodev-plugin-framework' ),
			'columnMappings'  => __( 'Сопоставлений', 'woodev-plugin-framework' ),
			'noCategory'      => __( 'Не выбрана', 'woodev-plugin-framework' ),
			'save'            => __( 'Сохранить', 'woodev-plugin-framework' ),
			'saving'          => __( 'Сохраняем…', 'woodev-plugin-framework' ),
			'delete'          => __( 'Удалить', 'woodev-plugin-framework' ),
			'confirmDelete'   => __( 'Да, удалить', 'woodev-plugin-framework' ),
			'cancel'          => __( 'Отмена', 'woodev-plugin-framework' ),
			'savedMapping'    => __( 'Сопоставление сохранено.', 'woodev-plugin-framework' ),
			'savedProfile'    => __( 'Профиль сохранён.', 'woodev-plugin-framework' ),
			'deletedMapping'  => __( 'Сопоставление удалено.', 'woodev-plugin-framework' ),
			'deletedProfile'  => __( 'Профиль удалён.', 'woodev-plugin-framework' ),
			'copiedMapping'   => __( 'Копия создана. Выберите для неё категории магазина — одна категория не может быть в двух сопоставлениях.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Sentences of the mapping editor.
	 *
	 * @return array<string,string>
	 */
	private static function mapping_messages(): array {
		return [
			'mappingName'         => __( 'Название сопоставления', 'woodev-plugin-framework' ),
			'shopCategories'      => __( 'Категории вашего магазина', 'woodev-plugin-framework' ),
			'shopCategoriesHelp'  => __( 'Сопоставление действует и на вложенные категории, если у них нет своего. Одна категория может входить только в одно сопоставление.', 'woodev-plugin-framework' ),
			'shopCategoryTaken'   => __( 'Уже входит в сопоставление', 'woodev-plugin-framework' ),
			'marketplaceCategory' => __( 'Категория маркетплейса', 'woodev-plugin-framework' ),
			'categorySearch'      => __( 'Начните вводить название категории', 'woodev-plugin-framework' ),
			'categoryChange'      => __( 'Выбрать другую', 'woodev-plugin-framework' ),
			'categoryUnavailable' => __( 'Этой категории больше нет в каталоге маркетплейса. Выберите другую.', 'woodev-plugin-framework' ),
			'noResults'           => __( 'Ничего не найдено', 'woodev-plugin-framework' ),
			'searching'           => __( 'Поиск…', 'woodev-plugin-framework' ),
			'profile'             => __( 'Профиль выгрузки', 'woodev-plugin-framework' ),
			'profileNone'         => __( '— не выбран —', 'woodev-plugin-framework' ),
			'attributes'          => __( 'Характеристики', 'woodev-plugin-framework' ),
			'attributesLoading'   => __( 'Загружаем характеристики…', 'woodev-plugin-framework' ),
			'attributesEmpty'     => __( 'Выберите категорию маркетплейса — здесь появятся её характеристики.', 'woodev-plugin-framework' ),
			'columnAttribute'     => __( 'Характеристика', 'woodev-plugin-framework' ),
			'columnSource'        => __( 'Откуда брать', 'woodev-plugin-framework' ),
			'columnValue'         => __( 'Значение', 'woodev-plugin-framework' ),
			'required'            => __( 'обязательная', 'woodev-plugin-framework' ),
			'sourceNone'          => __( 'Не заполнять', 'woodev-plugin-framework' ),
			'sourceFixed'         => __( 'Одно значение для всех', 'woodev-plugin-framework' ),
			'sourceAttribute'     => __( 'Атрибут товара', 'woodev-plugin-framework' ),
			'sourceMeta'          => __( 'Произвольное поле товара', 'woodev-plugin-framework' ),
			'sourceField'         => __( 'Поле карточки товара', 'woodev-plugin-framework' ),
			'attributeKey'        => __( 'Код атрибута, например pa_color', 'woodev-plugin-framework' ),
			'metaKey'             => __( 'Название поля', 'woodev-plugin-framework' ),
			'fixedValue'          => __( 'Значение', 'woodev-plugin-framework' ),
			'dictionarySearch'    => __( 'Введите не меньше двух букв и выберите значение', 'woodev-plugin-framework' ),
			'copy'                => __( 'Сделать копию', 'woodev-plugin-framework' ),
			'check'               => __( 'Проверить товары этой категории', 'woodev-plugin-framework' ),
			'checking'            => __( 'Проверяем товары…', 'woodev-plugin-framework' ),
			'checkHelp'           => __( 'Проверка сохранённого сопоставления: что помешает выгрузке его товаров.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Sentences of the profile editor.
	 *
	 * @return array<string,string>
	 */
	private static function profile_messages(): array {
		return [
			'profileName'      => __( 'Название профиля', 'woodev-plugin-framework' ),
			'sourcesHeading'   => __( 'Откуда брать данные', 'woodev-plugin-framework' ),
			'customFieldKey'   => __( 'Название поля', 'woodev-plugin-framework' ),
			'fallbacksHeading' => __( 'Запасные размеры и вес', 'woodev-plugin-framework' ),
			'fallbacksHelp'    => __( 'Применяются к товарам, у которых размеры или вес не заполнены. Оставьте пустым, если запасного значения не нужно.', 'woodev-plugin-framework' ),
			'fallbackWidth'    => __( 'Ширина', 'woodev-plugin-framework' ),
			'fallbackHeight'   => __( 'Высота', 'woodev-plugin-framework' ),
			'fallbackDepth'    => __( 'Глубина', 'woodev-plugin-framework' ),
			'fallbackWeight'   => __( 'Вес', 'woodev-plugin-framework' ),
			'unitWeight'       => __( 'Единица веса', 'woodev-plugin-framework' ),
			'unitDimension'    => __( 'Единица размеров', 'woodev-plugin-framework' ),
			'unitDefault'      => __( '— как в магазине —', 'woodev-plugin-framework' ),
			'vatRate'          => __( 'Ставка НДС', 'woodev-plugin-framework' ),
			'currencyHeading'  => __( 'Валюта цены', 'woodev-plugin-framework' ),
			'currencyAccount'  => __( 'Как в личном кабинете', 'woodev-plugin-framework' ),
			'currencyRate'     => __( 'Пересчитать по курсу', 'woodev-plugin-framework' ),
			'currencyRateInput' => __( 'Курс', 'woodev-plugin-framework' ),
			'currencyFixed'    => __( 'Фиксированная валюта (сохранена раньше, форма её не меняет)', 'woodev-plugin-framework' ),
			'currencyFieldKey' => __( 'Название поля товара', 'woodev-plugin-framework' ),
			'priceOverride'    => __( 'Своё правило цены для этого профиля', 'woodev-plugin-framework' ),
			'priceBase'        => __( 'Какую цену брать', 'woodev-plugin-framework' ),
			'priceMarkupKind'  => __( 'Наценка', 'woodev-plugin-framework' ),
			'priceMarkup'      => __( 'Размер наценки', 'woodev-plugin-framework' ),
			'priceRounding'    => __( 'Округление', 'woodev-plugin-framework' ),
			'priceOldPrice'    => __( 'Старая цена', 'woodev-plugin-framework' ),
			'priceMinPercent'  => __( 'Минимальная цена, % от обычной', 'woodev-plugin-framework' ),
		];
	}
}

<?php
/**
 * A plugin's own fields of the fake marketplace: one select on «Общие» and one on the product tab.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Settings\Extension_Field;
use Woodev\Framework\Marketplace\Settings\Extension_Group;
use Woodev\Framework\Marketplace\Settings\Settings_Extension;

/**
 * The shape of the first consumer (a «buy on the marketplace» button): the shop-wide select picks a mode, the product's select has an
 * extra «as in the shop settings» option with the empty value.
 */
final class Fake_Settings_Extension implements Settings_Extension {
	public const FIELD = 'buy_button';

	/**
	 * Groups for «Общие».
	 *
	 * @return Extension_Group[]
	 */
	public function default_groups(): array {
		return [
			new Extension_Group(
				'buy',
				'Кнопка «Купить»',
				[
					Extension_Field::select(
						self::FIELD,
						'Кнопка на карточке товара',
						[
							'none'   => 'Не показывать',
							'always' => 'Всегда',
							'stock'  => 'Только при наличии',
						],
						'none',
						'Где показывать кнопку.'
					),
				]
			),
		];
	}

	/**
	 * Groups for the product tab.
	 *
	 * @return Extension_Group[]
	 */
	public function product_groups(): array {
		return [
			new Extension_Group(
				'buy',
				'Кнопка «Купить»',
				[
					Extension_Field::select(
						self::FIELD,
						'Кнопка на карточке товара',
						[
							''       => 'Как в общих настройках',
							'none'   => 'Не показывать',
							'always' => 'Всегда',
						]
					),
					Extension_Field::toggle( 'ignored_on_the_tab', 'A switch the tab does not draw' ),
				]
			),
		];
	}
}

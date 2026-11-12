<?php
/**
 * Fields an account and the «Общие» defaults have in common: the price rule and the exchange switches.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Price\Price_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and validates the two groups of fields that exist twice on the screen: once per account and once as defaults.
 *
 * The caller decides the setting ids (`id_for( $field )`) and passes `$setting_extra` (a `show_if` that hides the group's fields
 * while an account follows the defaults), so the labels, options and tooltips are written once.
 *
 * @since 2.1.5
 */
final class Shared_Fields {
	/**
	 * Field name => `Price_Rule::from_array()` key.
	 *
	 * @var array<string,string>
	 */
	public const PRICE_RULE_FIELDS = [
		'price_base'           => 'base',
		'price_markup_kind'    => 'markup_kind',
		'price_markup'         => 'markup',
		'price_rounding'       => 'rounding',
		'price_old_price_rule' => 'old_price_rule',
		'price_min_percent'    => 'min_percent',
	];

	/**
	 * Names of the price-rule fields an adapter's screen shows: a rule with a price pusher has all six, one that only reads prices
	 * has the first three.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return string[] Field names, in screen order.
	 */
	public static function price_rule_field_names( Marketplace_Adapter $adapter ): array {
		$names = array_keys( self::PRICE_RULE_FIELDS );

		return null === $adapter->price_pusher() ? array_slice( $names, 0, 3 ) : $names;
	}

	/**
	 * Names of the exchange switches the adapter supports.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return string[] `orders_enabled` and/or `import_enabled`.
	 */
	public static function exchange_field_names( Marketplace_Adapter $adapter ): array {
		$names = [];

		if ( null !== $adapter->posting_handler() ) {
			$names[] = 'orders_enabled';
		}

		if ( $adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			$names[] = 'import_enabled';
		}

		return $names;
	}

	/**
	 * Registers the price-rule fields.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler    $handler       Handler.
	 * @param callable            $id_for        `function ( string $field ): string` — the setting id of a field name.
	 * @param Marketplace_Adapter $adapter       Adapter.
	 * @param array<string,mixed> $setting_extra Extra setting args (`show_if`).
	 * @return void
	 */
	public static function register_price_rule( Settings_Handler $handler, callable $id_for, Marketplace_Adapter $adapter, array $setting_extra = [] ): void {
		$marketplace = $adapter->settings_descriptor()->marketplace_name();

		Field_Kit::add_select(
			$handler,
			$id_for( 'price_base' ),
			__( 'От какой цены считать', 'woodev-plugin-framework' ),
			[
				Field_Kit::option( Price_Rule::BASE_ACTIVE, __( 'От цены на сайте (со скидкой, если она есть)', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::BASE_REGULAR, __( 'От обычной цены (скидки сайта не учитывать)', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Какую цену сайта брать за основу. Со скидкой — %s получит цену со скидкой и зачёркнутую обычную цену.', 'woodev-plugin-framework' ), $marketplace ),
			$setting_extra
		);

		Field_Kit::add_select(
			$handler,
			$id_for( 'price_markup_kind' ),
			__( 'Наценка', 'woodev-plugin-framework' ),
			[
				Field_Kit::option( Price_Rule::MARKUP_NONE, __( 'Без наценки', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::MARKUP_FIXED, __( 'Фиксированная сумма', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::MARKUP_PERCENT, __( 'Процент от цены', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Насколько цена на %s отличается от цены на сайте (например, на величину комиссии).', 'woodev-plugin-framework' ), $marketplace ),
			$setting_extra
		);

		Field_Kit::add_text(
			$handler,
			$id_for( 'price_markup' ),
			__( 'Размер наценки', 'woodev-plugin-framework' ),
			'0',
			__( 'Сумма в валюте кабинета или процент — как выбрано выше. Например, 150 или 15.', 'woodev-plugin-framework' ),
			'0',
			$setting_extra
		);

		if ( null === $adapter->price_pusher() ) {
			return;
		}

		Field_Kit::add_select(
			$handler,
			$id_for( 'price_rounding' ),
			__( 'Округление', 'woodev-plugin-framework' ),
			[
				Field_Kit::option( Price_Rule::ROUND_NONE, __( 'Не округлять', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::ROUND_TO_1, __( 'До целой единицы валюты', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::ROUND_TO_10, __( 'До десятков', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::ROUND_ENDS_99, __( 'Чтобы цена заканчивалась на 99', 'woodev-plugin-framework' ) ),
			],
			__( 'Как округлять цену после наценки.', 'woodev-plugin-framework' ),
			$setting_extra
		);

		Field_Kit::add_select(
			$handler,
			$id_for( 'price_old_price_rule' ),
			__( 'Старая цена', 'woodev-plugin-framework' ),
			[
				Field_Kit::option( Price_Rule::OLD_PRICE_ON_SALE, __( 'Показывать зачёркнутую цену, пока на сайте скидка', 'woodev-plugin-framework' ) ),
				Field_Kit::option( Price_Rule::OLD_PRICE_OFF, __( 'Не отправлять старую цену', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Нужна ли на %s зачёркнутая цена, когда на сайте действует скидка.', 'woodev-plugin-framework' ), $marketplace ),
			$setting_extra
		);

		Field_Kit::add_text(
			$handler,
			$id_for( 'price_min_percent' ),
			__( 'Минимальная цена, %', 'woodev-plugin-framework' ),
			'',
			/* translators: %s: marketplace name. */
			sprintf( __( 'Процент от цены, ниже которого %s не опустит цену в акциях. Пусто — минимальная цена не отправляется.', 'woodev-plugin-framework' ), $marketplace ),
			'80',
			$setting_extra
		);
	}

	/**
	 * Registers the orders and card-import switches the adapter supports.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler    $handler       Handler.
	 * @param callable            $id_for        `function ( string $field ): string` — the setting id of a field name.
	 * @param Marketplace_Adapter $adapter       Adapter.
	 * @param array<string,mixed> $setting_extra Extra setting args (`show_if`).
	 * @return void
	 */
	public static function register_exchange( Settings_Handler $handler, callable $id_for, Marketplace_Adapter $adapter, array $setting_extra = [] ): void {
		$marketplace = $adapter->settings_descriptor()->marketplace_name();
		$names       = self::exchange_field_names( $adapter );

		if ( in_array( 'orders_enabled', $names, true ) ) {
			Field_Kit::add_toggle(
				$handler,
				$id_for( 'orders_enabled' ),
				/* translators: %s: marketplace name. */
				sprintf( __( 'Заказы %s → WooCommerce', 'woodev-plugin-framework' ), $marketplace ),
				true,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Заказы с %s появляются в WooCommerce как обычные заказы.', 'woodev-plugin-framework' ), $marketplace ),
				$setting_extra
			);
		}

		if ( in_array( 'import_enabled', $names, true ) ) {
			Field_Kit::add_toggle(
				$handler,
				$id_for( 'import_enabled' ),
				__( 'Импортировать новые карточки автоматически', 'woodev-plugin-framework' ),
				false,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Когда в кабинете %s появляется новая карточка, плагин создаёт для неё товар на сайте. По умолчанию выключено.', 'woodev-plugin-framework' ), $marketplace ) . ' ' . __( 'In the other direction, add products manually on the Products screen.', 'woodev-plugin-framework' ),
				$setting_extra
			);
		}
	}

	/**
	 * Checks the price-rule fields together through `Price_Rule::from_array()`.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,mixed> $stored Rule stored so far (may be empty); missing keys come from the built-in defaults.
	 * @param array<string,mixed> $values Submitted `setting id => value` map.
	 * @param callable            $id_for `function ( string $field ): string`.
	 * @return array<string,string> `setting id => message`; empty when nothing was submitted or the rule is valid.
	 */
	public static function validate_price_rule( array $stored, array $values, callable $id_for ): array {
		$merged  = array_merge( Effective_Settings::builtin_defaults(), $stored );
		$touched = false;

		foreach ( self::PRICE_RULE_FIELDS as $field => $rule_key ) {
			$id = $id_for( $field );

			if ( array_key_exists( $id, $values ) ) {
				$touched             = true;
				$value               = trim( (string) $values[ $id ] );
				$merged[ $rule_key ] = '' === $value && 'min_percent' === $rule_key ? null : $value;
			}
		}

		if ( ! $touched ) {
			return [];
		}

		try {
			Price_Rule::from_array( self::rule_part( $merged ) );
		} catch ( \InvalidArgumentException $e ) {
			$min_id    = $id_for( 'price_min_percent' );
			$markup_id = $id_for( 'price_markup' );
			$target    = false !== stripos( $e->getMessage(), 'min' ) ? $min_id : $markup_id;

			return [
				$target => $target === $min_id
					? __( 'Укажите процент больше 0 и не больше 100 (например, 80) или оставьте поле пустым.', 'woodev-plugin-framework' )
					: __( 'Укажите корректное число для наценки (например, 150 или 15.5; скидка в процентах должна быть строго больше -100).', 'woodev-plugin-framework' ),
			];
		}

		return [];
	}

	/**
	 * The `Price_Rule` keys of a map that may also carry other defaults.
	 *
	 * @param array<string,mixed> $map Map.
	 * @return array<string,mixed>
	 */
	private static function rule_part( array $map ): array {
		return array_intersect_key( $map, array_flip( Effective_Settings::PRICE_KEYS ) );
	}
}

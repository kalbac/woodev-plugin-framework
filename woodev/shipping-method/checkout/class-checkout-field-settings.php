<?php
/**
 * Woodev Checkout Field Settings
 *
 * Store-level settings handler owning the «Поля» section of the «Доставка» tab
 * (design S1/S9). Registered with the `checkout_fields` option namespace
 * (`woodev_checkout_fields_*`) so it never collides with `Location_Settings`'s
 * `woodev_location_*` options.
 *
 * Owns five settings, each a single select naming genuinely different mechanisms
 * (design S4): `field_order_preset` (checkbox), `country_field`, `region_field`,
 * `address_field`, `postcode_field`. `remove` takes the field out of the DOM — its
 * value never reaches the order; `hide`/`hide_for_pickup` only visually hides it —
 * its value (e.g. an address written by the pickup point) still reaches the order.
 * The two mechanisms are never conflated; the framework never REMOVES the address
 * field, only hides it.
 *
 * Availability (design §3.2) is computed once at construction from an injected
 * {@see Checkout_Field_Environment}, so a control that cannot currently be used
 * renders disabled with a visible reason (D11) instead of hidden or silently dead.
 * The block checkout never reads `woocommerce_checkout_fields` — only
 * `woocommerce_get_country_locale` (measured on WC 11.0.1) — so `address_field`,
 * `country_field`, and the postcode `hide_for_pickup` VALUE only work on the
 * classic checkout; `field_order_preset` and `region_field` reach both checkouts
 * and are never disabled.
 *
 * {@see self::effective()} is this class's real contract with the rest of the
 * system (Task 6's `Checkout_Field_Policy` calls it for all five ids): the stored
 * value clamped to what is currently allowed (design §7 — clamp on READ, never
 * rewrite), mirroring
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_field_mode_region()}.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Field_Settings' ) ) :

	/**
	 * Settings handler for the checkout field policy («Поля» section).
	 *
	 * @since 2.0.2
	 */
	class Checkout_Field_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Environment facts the availability rules gate on, resolved once at
		 * construction.
		 *
		 * @var Checkout_Field_Environment
		 */
		private $env;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Checkout_Field_Environment|null $env environment facts the availability
		 *                                              rules gate on; defaults to
		 *                                              {@see Checkout_Field_Environment::from_wc()}
		 *                                              so production callers never have to
		 *                                              build one by hand.
		 */
		public function __construct( ?Checkout_Field_Environment $env = null ) {
			$this->env = $env ?? Checkout_Field_Environment::from_wc();

			parent::__construct( 'checkout_fields' );
		}

		public function get_owned_setting_ids(): array {
			return [
				'field_order_preset',
				'country_field',
				'region_field',
				'address_field',
				'postcode_field',
				'phone_field_format',
			];
		}

		/**
		 * Note shown under the «Поля» section heading, reporting Task 7's
		 * settlement-invariant restoration (design S8): when a third-party
		 * checkout-field-manager plugin removed the settlement (`*_city`) field or
		 * made it non-required, {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy}
		 * restores it and this note explains why — silently fixing someone else's
		 * plugin and saying nothing is exactly the failure this codebase avoids.
		 * Empty when nothing was overridden.
		 *
		 * Reads the RAW option ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::OPTION_LAST_OVERRIDES})
		 * with a plain `get_option()`, deliberately NOT through this class's own
		 * settings API: {@see \Woodev_Setting::get_value()} returns a cached property
		 * loaded once at construction, not a live option read (gotcha
		 * `woodev-setting-get-value-is-cached-not-a-live-option-read`) — the override
		 * report is written by a DIFFERENT (frontend checkout) request than the one
		 * that renders this note (wp-admin), so a cached value would never see it.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Reports Task 7's settlement-invariant override (issue #362, design S8).
		 *
		 * @return string
		 */
		public function get_section_note(): string {
			$overrides = get_option( Checkout_Field_Policy::OPTION_LAST_OVERRIDES, [] );

			if ( empty( $overrides ) ) {
				return '';
			}

			return __( 'Поле «Город» было изменено сторонним кодом (снята обязательность / удалено); фреймворк восстановил его — оформление заказа зависит от этого поля.', 'woodev-plugin-framework' );
		}

		/**
		 * The value the checkout must ACT on: the stored value clamped to what is
		 * currently allowed (design §7 — clamp on READ, never rewrite). The stored
		 * option itself is left untouched, so a condition change (e.g. the merchant
		 * adding a second shipping country, or switching checkout experience) restores
		 * the merchant's original choice the moment it becomes valid again — same shape
		 * as {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_field_mode_region()}.
		 *
		 * Clamping rules:
		 *  - `field_order_preset` — never disabled, returned as stored (cast to bool).
		 *  - `country_field` — `hide` allowed only when the store ships to exactly one
		 *    country AND the block checkout is not in use; otherwise clamps to `show`.
		 *  - `region_field` — never disabled (the `remove` value reaches both checkouts
		 *    via the locale instrument), returned as stored when it names a known option.
		 *  - `address_field` — `hide_for_pickup` allowed only on the classic checkout;
		 *    clamps to `show` on the block checkout.
		 *  - `postcode_field` — `hide_for_pickup` allowed only on the classic checkout
		 *    (`remove` works on both); clamps to `show` when the stored value names an
		 *    option not currently offered.
		 *  - `phone_field_format` — never disabled here either, returned as stored.
		 *    {@see Checkout_Field_Policy} calls `effective()` for every owned id, so this
		 *    id needs SOME answer, but it has no `field_order_preset`/`region_field`-style
		 *    `'show'` fallback to clamp to — a country losing its pattern is a
		 *    {@see self::get_phone_mask_config()}/JS-side no-op, never a rewrite (see that
		 *    method's own docblock).
		 *
		 * @since 2.0.2
		 *
		 * @param string $id one of {@see self::get_owned_setting_ids()}.
		 *
		 * @return bool|string
		 *
		 * @throws \Woodev_Plugin_Exception when `$id` is not one of this handler's own settings.
		 */
		public function effective( string $id ) {

			if ( 'field_order_preset' === $id ) {
				return (bool) $this->get_value( $id );
			}

			if ( 'phone_field_format' === $id ) {
				return (string) $this->get_value( $id );
			}

			switch ( $id ) {

				case 'country_field':
					$offered = ( 1 === $this->env->shipping_country_count && ! $this->env->block_checkout )
						? [ 'show', 'hide' ]
						: [ 'show' ];
					break;

				case 'region_field':
					$offered = [ 'show', 'remove' ];
					break;

				case 'address_field':
					$offered = $this->env->block_checkout ? [ 'show' ] : [ 'show', 'hide_for_pickup' ];
					break;

				case 'postcode_field':
					$offered = $this->env->block_checkout
						? [ 'show', 'remove' ]
						: [ 'show', 'hide_for_pickup', 'remove' ];
					break;

				default:
					throw new \Woodev_Plugin_Exception( "Checkout_Field_Settings::effective(): unknown setting id \"{$id}\"" );
			}

			$stored = (string) $this->get_value( $id );

			return in_array( $stored, $offered, true ) ? $stored : 'show';
		}

		protected function register_settings() {

			$this->register_setting(
				'field_order_preset',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Единый порядок и формат полей адреса', 'woodev-plugin-framework' ),
					'default' => true,
				]
			);
			$this->register_control(
				'field_order_preset',
				\Woodev_Control::TYPE_CHECKBOX,
				[
					'tooltip' => __( 'Приводит порядок и формат полей адреса на чекауте к единому стандарту фреймворка вместо порядка, который задаёт тема или сторонний плагин.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'country_field',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Страна', 'woodev-plugin-framework' ),
					'options' => [
						'show' => __( 'Показывать', 'woodev-plugin-framework' ),
						'hide' => __( 'Скрывать', 'woodev-plugin-framework' ),
					],
					'default' => 'show',
				]
			);
			$this->register_control(
				'country_field',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'Показывать ли поле «Страна» на чекауте. «Скрывать» доступно только когда магазин доставляет ровно в одну страну — тогда она подставляется автоматически и покупателю нечего выбирать.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'region_field',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Регион', 'woodev-plugin-framework' ),
					'options' => [
						'show'   => __( 'Показывать', 'woodev-plugin-framework' ),
						'remove' => __( 'Удалять', 'woodev-plugin-framework' ),
					],
					'default' => 'show',
				]
			);
			$this->register_control(
				'region_field',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( '«Удалять» полностью убирает поле «Регион» из формы чекаута — его значение никогда не попадёт в заказ, а настройки «Выбор региона» ниже перестают действовать (поле нечем управлять). «Показывать» оставляет обычное поле региона.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'address_field',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Адрес', 'woodev-plugin-framework' ),
					'options' => [
						'show'            => __( 'Показывать', 'woodev-plugin-framework' ),
						'hide_for_pickup' => __( 'Скрывать для методов ПВЗ', 'woodev-plugin-framework' ),
					],
					'default' => 'show',
				]
			);
			$this->register_control(
				'address_field',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( '«Скрывать для методов ПВЗ» прячет поле «Адрес» на чекауте, пока выбран способ доставки до пункта выдачи, но его значение всё равно может уйти в заказ (например, адрес, записанный из выбранного пункта, если это включено в разделе «Карта»). Работает только на классическом чекауте.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'postcode_field',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Индекс', 'woodev-plugin-framework' ),
					'options' => [
						'show'            => __( 'Показывать', 'woodev-plugin-framework' ),
						'hide_for_pickup' => __( 'Скрывать для методов ПВЗ', 'woodev-plugin-framework' ),
						'remove'          => __( 'Удалять', 'woodev-plugin-framework' ),
					],
					'default' => 'show',
				]
			);
			$this->register_control(
				'postcode_field',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( '«Скрывать для методов ПВЗ» прячет поле «Индекс» при выборе пункта выдачи, но его значение всё ещё может уйти в заказ; «Удалять» убирает поле из формы совсем, и его значение никогда не попадёт в заказ. Первый вариант работает только на классическом чекауте, второй — на обоих.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				'phone_field_format',
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Формат поля телефон', 'woodev-plugin-framework' ),
					'options' => $this->phone_field_format_options(),
					'default' => 'off',
				]
			);
			$this->register_control(
				'phone_field_format',
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'Маска ввода телефона на чекауте — не проверка формата (за неё отвечает перевозчик), а только удобство: лишний символ ввести нельзя, недобранный номер отправить можно. «Автоматически» — маска следует за страной, которую выбрал покупатель. Конкретная страна жёстко закрепляет маску за собой, независимо от выбора покупателя. Работает только на классическом чекауте.', 'woodev-plugin-framework' ),
				]
			);

			$this->apply_availability();
		}

		/**
		 * Disables controls the current environment cannot serve, with a visible
		 * reason (design D11) — never hidden, never silently dead.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function apply_availability(): void {

			$block        = $this->env->block_checkout;
			$reason_block = __( 'Недоступно на блочном чекауте: эта опция работает через скрипт классической формы оформления.', 'woodev-plugin-framework' );

			if ( 1 !== $this->env->shipping_country_count ) {
				$this->get_setting( 'country_field' )->get_control()->set_disabled(
					true,
					__( 'Доступно, когда магазин доставляет ровно в одну страну (WooCommerce → Настройки → Общие → Доставка в).', 'woodev-plugin-framework' )
				);
			} elseif ( $block ) {
				$this->get_setting( 'country_field' )->get_control()->set_disabled( true, $reason_block );
			}

			if ( $block ) {
				$this->get_setting( 'address_field' )->get_control()->set_disabled( true, $reason_block );

				$postcode_setting = $this->get_setting( 'postcode_field' );
				$postcode_control = $postcode_setting->get_control();

				// Only the `hide_for_pickup` VALUE is inert on the block checkout — `remove`
				// still works there — so the option list is narrowed rather than the whole
				// control disabled. `array_keys()` because `Woodev_Control::set_options()`
				// checks membership of `$valid_options` by VALUE (`in_array( $key, $valid_options, true )`
				// — see `class-control.php`), so it needs a plain list of valid keys, not the
				// `key => label` map `Woodev_Setting::get_options()` itself returns; passing
				// that map straight through would silently empty the option list.
				$postcode_control->set_options(
					[
						'show'   => __( 'Показывать', 'woodev-plugin-framework' ),
						'remove' => __( 'Удалять', 'woodev-plugin-framework' ),
					],
					array_keys( $postcode_setting->get_options() )
				);
				$postcode_control->set_description(
					trim( $postcode_control->get_description() . ' ' . __( 'Значение «Скрывать для методов ПВЗ» недоступно на блочном чекауте.', 'woodev-plugin-framework' ) )
				);
			}
		}


		/**
		 * Builds the `phone_field_format` select's options: «Не использовать»,
		 * «Автоматически», then one entry per shipping country (design per card
		 * #503). A country with no known mask ({@see Phone_Mask_Patterns::get()})
		 * is NOT hidden and NOT dropped from the list — a merchant scanning the
		 * list must be able to see every country the store ships to — but its
		 * label says so, so picking it is never a silently dead choice.
		 *
		 * This is a label-embedded reason, not the control-level
		 * `disabled`/`disabled_reason` mechanism {@see \Woodev_Control} carries
		 * elsewhere in this class (`country_field`, `address_field`,
		 * `postcode_field`): that mechanism disables the WHOLE control with ONE
		 * reason, never a single option among many, and the React `SelectField`
		 * component ({@see \Woodev\Framework\Settings\Field_Schema}, `select-field.js`)
		 * has no per-option disabled affordance to plug into — confirmed by
		 * reading both before choosing this shape. Filing that gap as a follow-up
		 * card is more honest than pretending the mechanism already fits.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		private function phone_field_format_options(): array {
			$options = [
				'off'  => __( 'Не использовать', 'woodev-plugin-framework' ),
				'auto' => __( 'Автоматически', 'woodev-plugin-framework' ),
			];

			$patterns = Phone_Mask_Patterns::get();

			foreach ( $this->env->shipping_countries as $code => $name ) {
				$options[ $code ] = isset( $patterns[ $code ] )
					? $name
					// translators: %s: country name.
					: sprintf( __( '%s (маска не описана)', 'woodev-plugin-framework' ), $name );
			}

			return $options;
		}

		/**
		 * The `phone-mask.js` config block: which mode the merchant picked, and
		 * the country → template table it needs to act on that mode. Read
		 * directly from the STORED value (never {@see self::effective()}) —
		 * unlike this class's other four settings, `phone_field_format`'s
		 * availability does not depend on the checkout-experience/country-count
		 * facts {@see Checkout_Field_Environment} carries; a country losing its
		 * pattern (a plugin unhooking {@see Phone_Mask_Patterns::FILTER_PATTERNS})
		 * degrades to a no-op on the JS side (same as picking «Не использовать»),
		 * never to a clamp-and-rewrite.
		 *
		 * @since 2.0.2
		 *
		 * @return array{mode: string, patterns: array<string,string>}
		 */
		public function get_phone_mask_config(): array {
			return [
				'mode'     => (string) $this->get_value( 'phone_field_format' ),
				'patterns' => Phone_Mask_Patterns::get(),
			];
		}
	}

endif;

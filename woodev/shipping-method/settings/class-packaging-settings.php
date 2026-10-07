<?php
/**
 * Carrier packing defaults and declared preset controls.
 *
 * @since 2.0.2
 */
namespace Woodev\Framework\Shipping\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores merchant choices separately from immutable carrier declarations.
 *
 * @since 2.0.2
 */
class Packaging_Settings extends \Woodev_Abstract_Settings {

	/** @var array<string,array> validated carrier presets, keyed by stable id */
	private array $presets;
	/** @var string legacy integration namespace */
	private string $plugin_id;

	/**
	 * @since 2.0.2
	 * @param string $plugin_id carrier id.
	 * @param array  $presets preset declarations in centimetres and kilograms.
	 */
	public function __construct( string $plugin_id, array $presets ) {
		$this->plugin_id = $plugin_id;
		$this->presets = [];
		foreach ( $presets as $preset ) {
			if ( ! is_array( $preset ) || ! isset( $preset['id'], $preset['name'], $preset['length'], $preset['width'], $preset['height'], $preset['cost_mode'] ) ) {
				throw new \InvalidArgumentException( 'A carrier box needs id, name, dimensions and cost_mode.' );
			}
			if ( ! is_string( $preset['name'] ) || '' === trim( $preset['name'] ) ) {
				throw new \InvalidArgumentException( 'Carrier box name must be nonempty.' );
			}
			$id = $preset['id'];
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $id ) || isset( $this->presets[ $id ] ) || ! in_array( $preset['cost_mode'], [ 'carrier', 'fixed', 'merchant' ], true ) ) {
				throw new \InvalidArgumentException( 'Invalid or duplicate carrier box id/cost mode.' );
			}
			$preset += [
				'max_weight' => 0,
				'box_weight' => 0,
				'cost' => '',
			];
			foreach ( [ 'length', 'width', 'height', 'max_weight', 'box_weight' ] as $key ) {
				if ( ! is_numeric( $preset[ $key ] ) || ! is_finite( (float) $preset[ $key ] ) || (float) $preset[ $key ] < 0 || ( in_array( $key, [ 'length', 'width', 'height' ], true ) && (float) $preset[ $key ] <= 0 ) ) {
					throw new \InvalidArgumentException( 'Invalid carrier box dimensions/weight.' );
				}
			}
			if ( 'fixed' === $preset['cost_mode'] && ( ! is_numeric( $preset['cost'] ) || ! is_finite( (float) $preset['cost'] ) || (float) $preset['cost'] < 0 ) ) {
				throw new \InvalidArgumentException( 'Fixed carrier box cost must be a nonnegative amount.' );
			}
			if ( 'merchant' === $preset['cost_mode'] && ( ! is_scalar( $preset['cost'] ) || ! Boxes_Settings::is_valid_cost( (string) $preset['cost'] ) ) ) {
				throw new \InvalidArgumentException( 'Invalid merchant box cost.' );
			}
			$this->presets[ $id ] = $preset;
		}
		parent::__construct( $plugin_id . '_packaging' );
	}

	/**
	 * @since 2.0.2
	 * @return string[]
	 */
	public function get_owned_setting_ids(): array {
		return array_keys( $this->get_settings() );
	}

	/**
	 * Keeps an existing integration packing default until a merchant saves the new setting.
	 *
	 * @since 2.0.2
	 * @param string $key packing_algorithm or unpacked_algorithm.
	 * @return string
	 */
	public function get_default_algorithm( string $key ): string {
		$value = $this->get_value( $key );
		$allowed = 'packing_algorithm' === $key ? \Woodev_Packer_Dispatcher::get_algorithms() : self::leftover_options();
		return is_string( $value ) && isset( $allowed[ $value ] ) ? $value : \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY;
	}

	/**
	 * Reads the legacy integration default into the control as well as the packing path.
	 *
	 * @since 2.0.2
	 * @return void
	 */
	protected function load_settings(): void {
		parent::load_settings();
		foreach ( $this->presets as $id => $preset ) {
			if ( 'fixed' === $preset['cost_mode'] ) {
				$this->get_setting( 'box_' . $id . '_cost' )->set_value( (string) $preset['cost'] );
			}
		}
		$legacy = get_option( 'woocommerce_' . $this->plugin_id . '_settings', [] );
		if ( ! is_array( $legacy ) ) {
			$legacy = [];
		}
		foreach ( [ 'packing_algorithm', 'unpacked_algorithm' ] as $key ) {
			$allowed = 'packing_algorithm' === $key ? \Woodev_Packer_Dispatcher::get_algorithms() : self::leftover_options();
			if ( null === get_option( 'woodev_' . $this->plugin_id . '_packaging_' . $key, null ) && isset( $legacy[ $key ] ) && is_string( $legacy[ $key ] ) && isset( $allowed[ $legacy[ $key ] ] ) ) {
				$this->get_setting( $key )->set_value( $legacy[ $key ] );
			}
		}
		$options = self::packing_options();
		if ( 'virtual' === $this->get_value( 'packing_algorithm' ) ) {
			$options['virtual'] = __( 'Минимальная коробка', 'woodev-plugin-framework' );
		}
		$this->get_setting( 'packing_algorithm' )->set_options( $options );
	}

	/**
	 * Effective declarations including current merchant choices (disabled presets included for cache identity).
	 *
	 * @since 2.0.2
	 * @return array<int,array>
	 */
	public function get_boxes(): array {
		$boxes = [];
		foreach ( $this->presets as $id => $preset ) {
			$preset['origin'] = 'carrier';
			$preset['enabled'] = true === $this->get_value( 'box_' . $id . '_enabled' );
			$preset['charge_carrier'] = 'carrier' === $preset['cost_mode'] && true === $this->get_value( 'box_' . $id . '_charge' );
			if ( 'merchant' === $preset['cost_mode'] ) {
				$preset['cost'] = (string) $this->get_value( 'box_' . $id . '_cost' );
			}
			$boxes[] = $preset;
		}
		return $boxes;
	}

	/**
	 * Merchant packing choices; virtual remains readable but is not offered for new choices.
	 *
	 * @since 2.0.2
	 * @return array<string,string>
	 */
	public static function packing_options(): array {
		return self::leftover_options() + [ 'boxes' => __( 'Упаковывать в коробки', 'woodev-plugin-framework' ) ];
	}

	/**
	 * @since 2.0.2
	 * @return array<string,string>
	 */
	public static function leftover_options(): array {
		return [
			'separately' => __( 'Каждый товар отдельно', 'woodev-plugin-framework' ),
			'single' => __( 'Всё в одну коробку', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * @since 2.0.2
	 * @return void
	 */
	protected function register_settings(): void {
		$this->register_setting(
			'packing_algorithm',
			\Woodev_Setting::TYPE_STRING,
			[
				'name' => __( 'Способ упаковки', 'woodev-plugin-framework' ),
				'default' => 'separately',
				'options' => self::packing_options() + [ 'virtual' => __( 'Минимальная коробка', 'woodev-plugin-framework' ) ],
			]
		);
		$this->register_control(
			'packing_algorithm',
			\Woodev_Control::TYPE_SELECT,
			[
				'options' => self::packing_options(),
				'tooltip' => __( 'Как объединять товары в посылки. Настройки способа доставки могут переопределить этот выбор.', 'woodev-plugin-framework' ),
			]
		);
		$this->register_setting(
			'unpacked_algorithm',
			\Woodev_Setting::TYPE_STRING,
			[
				'name' => __( 'Непоместившиеся товары', 'woodev-plugin-framework' ),
				'default' => 'separately',
				'options' => self::leftover_options(),
				'show_if' => [
					'setting' => 'packing_algorithm',
					'value' => 'boxes',
				],
			]
		);
		$this->register_control( 'unpacked_algorithm', \Woodev_Control::TYPE_SELECT, [ 'tooltip' => __( 'Как упаковывать товары, которые не поместились в коробки?', 'woodev-plugin-framework' ) ] );
		foreach ( $this->presets as $id => $preset ) {
			$prefix = 'box_' . $id;
			$this->register_setting(
				$prefix . '_enabled',
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name' => $preset['name'],
					'default' => false,
				]
			);
			$this->register_control(
				$prefix . '_enabled',
				\Woodev_Control::TYPE_TOGGLE,
				[
					'box_preset' => $preset + [ 'field' => 'enabled' ],
					'tooltip' => sprintf(
					/* translators: 1: length, 2: width, 3: height in cm */
						__( 'Размеры: %1$s × %2$s × %3$s см. Использовать, если подходящей упаковки магазина нет.', 'woodev-plugin-framework' ),
						$preset['length'],
						$preset['width'],
						$preset['height']
					),
				]
			);
			if ( 'carrier' === $preset['cost_mode'] ) {
				$this->register_setting(
					$prefix . '_charge',
					\Woodev_Setting::TYPE_BOOLEAN,
					[
						'name' => __( 'Учитывать стоимость', 'woodev-plugin-framework' ),
						'default' => true,
					]
				);
				$this->register_control(
					$prefix . '_charge',
					\Woodev_Control::TYPE_TOGGLE,
					[
						'box_preset' => $preset + [ 'field' => 'charge' ],
						'tooltip' => __( 'Перевозчик включит стоимость этой упаковки в расчёт доставки.', 'woodev-plugin-framework' ),
					]
				);
			} else {
				$this->register_setting(
					$prefix . '_cost',
					\Woodev_Setting::TYPE_STRING,
					[
						'name' => __( 'Стоимость упаковки', 'woodev-plugin-framework' ),
						'default' => (string) $preset['cost'],
						'validate' => [ Boxes_Settings::class, 'is_valid_cost' ],
					]
				);
				$this->register_control(
					$prefix . '_cost',
					\Woodev_Control::TYPE_TEXT,
					[
						'box_preset' => $preset + [ 'field' => 'cost' ],
						'disabled' => 'fixed' === $preset['cost_mode'],
						'tooltip' => __( 'Сумма за одну коробку или процент от стоимости товаров в ней, например 2%.', 'woodev-plugin-framework' ),
					]
				);
			}
		}
	}
}

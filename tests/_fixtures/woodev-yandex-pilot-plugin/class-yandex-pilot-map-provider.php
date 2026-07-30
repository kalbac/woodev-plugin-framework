<?php
/**
 * Yandex-shaped pilot fixture map provider.
 *
 * Proves the {@see Map_Provider} seam fits an own-rendered, API-key-holding
 * provider: it contributes an API-key settings field and self-describes its own
 * script handle. Per the SP-5 Task 9 re-point, the real provider boundary owns
 * everything inside its own container; this PHP side is the descriptor that gets
 * the right script and the right config to the browser. Mirrors
 * {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider} in shape, kept as its
 * own fixture class so this file stays independent of the framework's concrete
 * provider.
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Map\Map_Provider;

/**
 * Yandex.Maps map provider fixture.
 */
final class Woodev_Yandex_Pilot_Map_Provider implements Map_Provider {

	/** @var string provider identifier */
	const PROVIDER_ID = 'yandex';

	/**
	 * Gets the provider id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Gets the provider's human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Яндекс.Карты';
	}

	/**
	 * Gets the registered handle of the script that implements this provider.
	 *
	 * @return string
	 */
	public function get_script_handle(): string {
		return 'woodev-pickup-map-provider-' . self::PROVIDER_ID;
	}

	/**
	 * Gets the yandex provider settings fields.
	 *
	 * A keyed provider: it contributes an API-key credential field, in the Woodev
	 * settings-API `register_setting()` args shape — see
	 * {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider::get_settings_fields()},
	 * which this fixture mirrors.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings_fields(): array {
		return [
			'yandex_map_api_key' => [
				'name'      => 'Yandex.Maps API key',
				'type'      => 'string',
				'default'   => '',
				'required'  => false,
				'sensitive' => false,
			],
		];
	}

	/**
	 * Gets the config handed to the browser for this provider.
	 *
	 * @param array<string, mixed> $context request-scoped context (unused by this fixture).
	 *
	 * @return array<string,mixed>
	 */
	public function get_js_config( array $context ): array {
		return [
			'center' => [ 55.751244, 37.618423 ],
			'zoom'   => 10,
		];
	}
}

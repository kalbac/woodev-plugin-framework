<?php
/**
 * Yandex-shaped pilot fixture map provider.
 *
 * Proves the {@see Map_Provider} seam fits an API-key provider: unlike the
 * framework-default Leaflet provider, the yandex provider ships an API-key
 * settings field and self-describes its JS adapter. Per decision §6a the real
 * provider boundary is the JS adapter contract; this PHP side is a thin descriptor.
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

	/** @var string registered handle of the yandex JS adapter */
	private const ADAPTER_HANDLE = 'woodev-pickup-map-adapter-yandex';

	/**
	 * Gets the provider id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Enqueues the yandex map library and adapter.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script(
			self::ADAPTER_HANDLE,
			'https://api-maps.yandex.ru/2.1/?lang=ru_RU',
			[ 'jquery' ],
			'2.1',
			true
		);
	}

	/**
	 * Gets the yandex provider settings fields.
	 *
	 * A keyed provider: it contributes an API-key credential field.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings_fields(): array {
		return [
			'yandex_map_api_key' => [
				'title' => 'Yandex.Maps API key',
				'type'  => 'text',
			],
		];
	}

	/**
	 * Gets the yandex JS adapter handle.
	 *
	 * @return string
	 */
	public function get_js_adapter_handle(): string {
		return self::ADAPTER_HANDLE;
	}

	/**
	 * Gets the default yandex map configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function get_localized_config(): array {
		return [
			'center' => [ 55.751244, 37.618423 ],
			'zoom'   => 10,
		];
	}
}

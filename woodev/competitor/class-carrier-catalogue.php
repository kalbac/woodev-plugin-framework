<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

/**
 * Shared catalogue of Woodev carrier products and their competitors.
 *
 * @since 2.0.2
 */
final class Carrier_Catalogue {

	/**
	 * Returns the carrier product catalogue.
	 *
	 * @since 2.0.2
	 * @return array<string,array<string,mixed>>
	 */
	public static function products(): array {
		return [
			'cdek' => [
				'id'          => 'cdek',
				'plugin_file' => 'woocommerce-edostavka/woocommerce-edostavka.php',
				'name'        => 'СДЭК для WooCommerce',
				'url'         => 'https://woodev.ru/downloads/wc-edostavka-integration',
				'download_id' => 216,
				'competitors' => [
					[
						'file' => 'cdekdelivery/cdek.php',
						'name' => 'CDEKDelivery',
					],
					[
						'file' => 'cdek-for-woocommerce/cdek-for-woocommerce.php',
						'name' => 'CDEK для WooCommerce',
					],
					[
						'file' => 'woo-cdek/woo-cdek.php',
						'name' => 'Woo CDEK',
					],
				],
				'carrier_name' => 'СДЭК',
				'service_name' => 'СДЭК',
			],
			'yandex' => [
				'id'          => 'yandex',
				'plugin_file' => 'woocommerce-yandex-delivery/woocommerce-yandex-delivery.php',
				'name'        => 'Яндекс доставка для Woocommerce',
				'url'         => 'https://woodev.ru/downloads/yandex-dostavka-woocommerce',
				'download_id' => 821,
				'competitors' => [
					[
						'file' => 'yandex-go-delivery/yandex-go-delivery.php',
						'name' => 'Яндекс Доставка',
					],
				],
				'carrier_name' => 'Яндекс Доставки',
				'service_name' => 'Яндекс Доставку',
			],
			'russian-post' => [
				'id'          => 'russian-post',
				'plugin_file' => 'woodev-russian-post/woodev-russian-post.php',
				'name'        => 'Russian Post EMS integration with Woocommerce',
				'url'         => 'https://woodev.ru/downloads/pochta-rossii-woocommerce',
				'download_id' => 8772,
				'competitors' => [
					[
						'file' => 'russian-post-and-ems-for-woocommerce/russian-post-and-ems-for-woocommerce.php',
						'name' => 'Russian Post and EMS for WooCommerce',
					],
				],
				'carrier_name' => 'Почты России',
				'service_name' => 'Почту России',
			],
		];
	}
}

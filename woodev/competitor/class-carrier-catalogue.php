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
				'name'        => 'Почта России EMS для Woocommerce',
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

	/**
	 * Returns the shared recommendation copy templates.
	 *
	 * @since 2.0.2
	 * @return string[]
	 */
	public static function recommendation_templates(): array {
		return [
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». У Woodev тоже есть плагин для %2$s, и мы рекомендуем попробовать его: в нём больше полезных и удобных функций, он работает стабильнее, регулярно получает обновления, а на ваши вопросы ответит наша техническая поддержка.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Обратите внимание на наш плагин для %2$s: он даёт вашему магазину больше возможностей, стабильно работает и постоянно развивается, а если понадобится помощь, наша техническая поддержка всегда рядом.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Знаете ли вы, что у нас тоже есть плагин для %2$s? Перейдите на него, и ваш магазин получит больше удобных функций, стабильную работу, свежие обновления и техническую поддержку от команды, которая его создаёт.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Мы рекомендуем наш плагин для %2$s: с ним вы получаете больше возможностей для работы с отправлениями, стабильную работу, постоянные обновления и техническую поддержку, которая всегда на связи.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». У Woodev есть свой плагин для %2$s. Попробуйте его, и вы получите расширенный набор функций, надёжную работу, регулярные обновления и поддержку, которая поможет с любым вопросом.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Мы рекомендуем наш плагин для %2$s: больше функций для ваших отправлений, надёжная работа изо дня в день, регулярные обновления и техническая поддержка, которая не оставит вас с вопросом один на один.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». У нас есть свой плагин для %2$s, и при переходе на него вы получаете больше функций для работы с заказами, надёжную работу без сбоев, регулярные обновления и помощь нашей технической поддержки.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Рекомендуем попробовать наш плагин для %2$s: с ним вашему магазину доступно больше функций, работа без сбоев, регулярные обновления и техническая поддержка, готовая помочь в любой момент.', 'woodev-plugin-framework' ),
			/* translators: 1: competitor plugin name, 2: carrier name in the genitive */
			__( 'На этом сайте работает плагин стороннего разработчика «%1$s». Попробуйте наш плагин для %2$s: в нём больше возможностей для вашего магазина, он стабильно работает, регулярно обновляется, а наша техническая поддержка всегда готова помочь с настройкой и любыми вопросами.', 'woodev-plugin-framework' ),
		];
	}
}

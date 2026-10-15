<?php

/**
 * Excerpt of woocommerce-yandex-delivery/includes/class-shipping-method-other-day.php (v1, frozen) — only the literals the
 * contract tests pin; not runnable.
 */

class WC_Yandex_Delivery_Shipping_Method_Other_Day extends Abstract_WC_Yandex_Delivery_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id = 'yandex_delivery_other_day';
	}

	public function init_form_fields() {
		$this->instance_form_fields = Woodev_Helper::array_insert_after( $fields, 'title', [
			'tariff'        => [
				'default' => 'time_interval',
				'options' => [
					'time_interval' => '',
					'self_pickup'   => '',
				],
			],
			'shipment_type' => [],
		] );
	}
}

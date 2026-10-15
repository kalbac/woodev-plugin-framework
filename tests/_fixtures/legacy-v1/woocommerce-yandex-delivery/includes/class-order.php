<?php

/**
 * Excerpt of woocommerce-yandex-delivery/includes/class-order.php (v1, frozen) — only the literals the
 * contract tests pin; not runnable.
 */

class WC_Yandex_Delivery_Order extends WC_Order {

	public function get_request_id() {
		return $this->request_id ??= $this->get_meta( '_yandex_delivery_request_id' );
	}

	public function set_request_id( string $request_id = null ) {
		$this->update_meta_data( '_yandex_delivery_request_id', $request_id );
	}
}

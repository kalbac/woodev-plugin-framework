<?php

/**
 * Signature skeleton of woocommerce-edostavka/includes/class-wc-edostavka-shipping-method.php (v1, frozen) — class and method signatures only,
 * for the signature probe; not runnable.
 */

class WD_Edostavka_Shipping extends WC_Shipping_Method {
	private $locations_to;
	public function __construct( $instance_id = 0 ) {}

	public function admin_options() {}

	public function get_method_title() {}

	public function get_method_description() {}

	public function init_form_fields() {}

	public function generate_multiselect_html( $key, $data ) {}

	public function generate_select_html( $key, $data = [] ): string {}

	public function validate_fee_payments_field( $key, $value ) {}

	public function validate_cities_field( $key, $value ) {}

	public function update_instance_option( string $key, $value = '' ): bool {}

	public function is_available( $package ): bool {}

	private function has_only_selected_shipping_class( $package ): bool {}

	public function calculate_shipping( $package = array() ) {}

	private function dispatch( $package = [] ) {}

	public function get_rate_cost( $cost, $package = [] ) {}

	public function should_send_cart_api_request(): bool {}

	private function has_rate_error( $rate_data ): bool {}

	public function get_items_count( array $package ) {}

	public function get_packages( $package ) {}

	private function get_tariffs_list() {}

	private function get_shipping_classes_options() {}

	public function zone_shipping_methods( $methods ) {}

	private function is_accessing_settings() {}

}

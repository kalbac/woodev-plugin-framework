<?php

/**
 * Signature skeleton of woocommerce-edostavka/woocommerce-edostavka.php (v1, frozen) — class and method signatures only,
 * for the signature probe; not runnable.
 */

class WC_Edostavka_Shipping extends Woodev_Plugin {
	private $api;
	protected $admin;
	protected static $instance;
	protected $method_id;
	private $checkout;
	protected $webhook_handler;
	protected $cron;
	protected $customer;
	public function __construct() {}

	public function add_documentation_header( array $headers ): array {}

	public static function instance() {}

	public function init_plugin() {}

	public function includes() {}

	private function frontend_includes() {}

	private function ajax_includes() {}

	private function admin_includes() {}

	public function add_integration( $integrations ) {}

	public function load_shipping_classes() {}

	public function include_methods( $methods ) {}

	public function email_classes( array $email_classes ): array {}

	public function get_integration_handler(): ?WC_Edostavka_Integration {}

	public function get_checkout_handler() {}

	public function get_admin_instance() {}

	public function get_cron_instance() {}

	public function get_customer_handler() {}

	public function register_data_stores( $stores ) {}

	public function delete_shipping_method( $instance_id, $method_id ) {}

	public function order_items_updated( $order_id, $items ) {}

	public function order_items_removed( $item_id, $item ) {}

	private function delete_shipping_meta_data( WC_Order $order ) {}

	public function get_webhook_handler() {}

	public function get_method_id() {}

	public function get_plugin_name() {}

	public function get_download_id() {}

	protected function get_file() {}

	public function get_settings_url( $plugin_id = null ) {}

	public function is_plugin_settings() {}

	public function get_documentation_url() {}

	public function get_sales_page_url() {}

	public function get_support_url() {}

	public function auto_export_order( $order_id ) {}

	public function add_admin_notices() {}

	public function log( $message, $log_id = null ) {}

	public function get_api() {}

	protected function init_lifecycle_handler() {}

	protected function get_deprecated_hooks() {}

}

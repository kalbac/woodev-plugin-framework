<?php
/**
 * Names of the embedded classic screens of one marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Derives every hook name, nonce action and form key of the product tab and of the order metabox from the adapter id, so two
 * marketplace plugins active on one shop never share an AJAX action or a posted field. The merchant-facing marketplace name comes
 * from the adapter's settings descriptor and is already translated.
 *
 * @since 2.1.2
 */
final class Screen_Names {
	/**
	 * Adapter id.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Merchant-facing marketplace name.
	 *
	 * @var string
	 */
	private $marketplace_name;

	/**
	 * Creates the names.
	 *
	 * @since 2.1.2
	 *
	 * @param string $marketplace_id   Adapter id (`ozon`).
	 * @param string $marketplace_name Merchant-facing name, translated.
	 */
	public function __construct( string $marketplace_id, string $marketplace_name ) {
		$this->marketplace_id   = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $marketplace_id ) );
		$this->marketplace_name = $marketplace_name;
	}

	/**
	 * Returns the adapter id.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->marketplace_id;
	}

	/**
	 * Returns the merchant-facing marketplace name.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->marketplace_name;
	}

	/**
	 * Returns the `wp_ajax_` action of the product tab buttons.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function product_action(): string {
		return 'woodev_' . $this->marketplace_id . '_product';
	}

	/**
	 * Returns the nonce action of the product tab saves (a button appends `_{op}`).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function save_nonce(): string {
		return $this->product_action() . '_save';
	}

	/**
	 * Returns the name of the hidden field that carries the save nonce (the form's own `_wpnonce` belongs to WordPress).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function nonce_field(): string {
		return 'woodev_' . $this->marketplace_id . '_nonce';
	}

	/**
	 * Returns the key of the product data tab.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function tab_key(): string {
		return 'woodev_' . $this->marketplace_id;
	}

	/**
	 * Returns the posted array of the product tab (`ACCOUNT => fields`).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function post_key(): string {
		return 'woodev_' . $this->marketplace_id;
	}

	/**
	 * Returns the posted array of the variation panel (`LOOP => ACCOUNT => fields`).
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function variation_key(): string {
		return 'woodev_' . $this->marketplace_id . '_variation';
	}

	/**
	 * Returns the `wp_ajax_` action that lists the reasons an order can be cancelled with.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function cancel_reasons_action(): string {
		return 'woodev_' . $this->marketplace_id . '_cancel_reasons';
	}

	/**
	 * Returns the `wp_ajax_` action that cancels an order's posting.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function cancel_action(): string {
		return 'woodev_' . $this->marketplace_id . '_cancel_posting';
	}

	/**
	 * Returns the nonce action of one order's cancel buttons.
	 *
	 * @since 2.1.2
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public function cancel_nonce( int $order_id ): string {
		return 'woodev_' . $this->marketplace_id . '_cancel_' . $order_id;
	}
}

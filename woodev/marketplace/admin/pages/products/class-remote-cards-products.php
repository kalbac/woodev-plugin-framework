<?php
/**
 * The shop's products, as the «Связать с…» field and the «На сайте» column need them.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the WooCommerce catalogue, checks a product the merchant picked, and names a product for a cell. Reads WooCommerce only; it
 * never touches a link or the marketplace.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Products {
	/**
	 * Shortest search text the field acts on, and most products it lists: the catalogue can be large.
	 */
	public const MIN_SEARCH = 2;
	public const MAX_RESULTS = 30;

	/**
	 * Finds product ids by a search text: `function ( string $term, int $limit ): int[]`. WooCommerce's own product search in production.
	 *
	 * @var callable
	 */
	private $search;

	/**
	 * Creates the helper.
	 *
	 * @since 2.1.5
	 *
	 * @param callable $search `function ( string $term, int $limit ): int[]`: ids of products and variations that match the text.
	 */
	public function __construct( callable $search ) {
		$this->search = $search;
	}

	/**
	 * WooCommerce's product search (the one behind its own product pickers): name, article and the like, variations included.
	 *
	 * @since 2.1.5
	 *
	 * @return callable `function ( string $term, int $limit ): int[]`.
	 */
	public static function woocommerce_search(): callable {
		return static function ( string $term, int $limit ): array {
			/** @var \WC_Product_Data_Store_CPT $store */
			$store = \WC_Data_Store::load( 'product' );

			return array_map( 'intval', (array) $store->search_products( $term, '', true, false, $limit ) );
		};
	}

	/**
	 * The options of the product field for a search text: products and variations the user may open, a variable product left out
	 * because the card goes to one variation.
	 *
	 * @since 2.1.5
	 *
	 * @param string $term What the merchant typed.
	 * @return array<int,array{value:string,label:string}> Empty for a text shorter than {@see self::MIN_SEARCH}.
	 */
	public function options( string $term ): array {
		$term = trim( $term );

		if ( mb_strlen( $term ) < self::MIN_SEARCH ) {
			return [];
		}

		$options = [];

		foreach ( call_user_func( $this->search, $term, self::MAX_RESULTS ) as $id ) {
			$product = wc_get_product( (int) $id );

			if ( ! $product || $product->is_type( 'variable' ) || ! current_user_can( 'read_product', (int) $id ) ) {
				continue;
			}

			$options[] = [
				'value' => (string) (int) $id,
				'label' => rawurldecode( wp_strip_all_tags( $product->get_formatted_name() ) ),
			];
		}

		return array_slice( $options, 0, self::MAX_RESULTS );
	}

	/**
	 * Checks the product the merchant picked.
	 *
	 * @since 2.1.5
	 *
	 * @param mixed  $value The posted value of the field.
	 * @param string $field Field id, for the refusal.
	 * @return array{id:int,parent_id:int,title:string} The product to link: a simple product, or one variation.
	 * @throws Action_Input_Exception When it is not a product the user may edit, or is a variable product.
	 */
	public function pick( $value, string $field ): array {
		$id      = is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,11}$/D', $value ) ? (int) $value : 0;
		$product = $id > 0 ? wc_get_product( $id ) : null;

		if ( ! $product ) {
			throw new Action_Input_Exception( [ $field => __( 'Выберите товар из списка.', 'woodev-plugin-framework' ) ] );
		}

		if ( $product->is_type( 'variable' ) ) {
			throw new Action_Input_Exception( [ $field => __( 'К вариативному товару нельзя привязать карточку. Выберите один из его вариантов.', 'woodev-plugin-framework' ) ] );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new Action_Input_Exception( [ $field => __( 'У вас нет прав на изменение этого товара.', 'woodev-plugin-framework' ) ] );
		}

		return [
			'id'        => $id,
			'parent_id' => (int) $product->get_parent_id(),
			'title'     => $this->title( $product ),
		];
	}

	/**
	 * Names a product for the «На сайте» cell.
	 *
	 * @since 2.1.5
	 *
	 * @param int $product_id Product or variation id.
	 * @return array{title:string,url:string} The address is empty when the product is gone or the user may not edit it.
	 */
	public function describe( int $product_id ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return [
				/* translators: %d: product id. */
				'title' => sprintf( __( 'Товар №%d (удалён)', 'woodev-plugin-framework' ), $product_id ),
				'url'   => '',
			];
		}

		$edit_id = (int) $product->get_parent_id() > 0 ? (int) $product->get_parent_id() : $product_id;

		return [
			'title' => $this->title( $product ),
			'url'   => current_user_can( 'edit_post', $edit_id ) ? (string) get_edit_post_link( $edit_id, 'raw' ) : '',
		];
	}

	/**
	 * @param \WC_Product $product Product or variation.
	 * @return string
	 */
	private function title( $product ): string {
		return html_entity_decode( (string) $product->get_name(), ENT_QUOTES, 'UTF-8' );
	}
}

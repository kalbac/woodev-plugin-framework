<?php
/**
 * Builds the rows of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Row_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the reader's products and offers into rows of the records engine. A simple product is one row. A variable product is a
 * parent row that sums its variations and expands; each variation is a child row with its own state, stock, price, error and
 * activity (B4).
 *
 * Every text is clipped to what the engine accepts, so one long product title or a long marketplace error cannot turn the whole
 * page into a server error.
 *
 * @since 2.1.4
 */
final class Site_Products_Rows {
	/**
	 * Longest text of a cell, in characters.
	 */
	private const CLIP = 200;

	/**
	 * Most state badges in one cell: the engine's stack limit.
	 */
	private const MAX_BADGES = Row_Normalizer::MAX_STACK_ITEMS;

	/**
	 * Marketplace name for the «Маркетплейс» side of stock and price.
	 *
	 * @var string
	 */
	private $marketplace;

	/**
	 * Warehouse id => name, for the account.
	 *
	 * @var array<string,string>
	 */
	private $warehouses;

	/**
	 * Address of a product's edit screen: `function ( int $product_id ): string`.
	 *
	 * @var callable
	 */
	private $edit_url;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.4
	 *
	 * @param string               $marketplace Merchant-facing name of the marketplace.
	 * @param array<string,string> $warehouses  Warehouse id => name of the account.
	 * @param callable             $edit_url    `function ( int $product_id ): string`, '' when there is none.
	 */
	public function __construct( string $marketplace, array $warehouses, callable $edit_url ) {
		$this->marketplace = $marketplace;
		$this->warehouses  = $warehouses;
		$this->edit_url    = $edit_url;
	}

	/**
	 * Builds the row of a listed product.
	 *
	 * @since 2.1.4
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $product    Row of {@see Site_Products_Reader::rows()}.
	 * @param array<string,mixed> $activity   Activity of the row.
	 * @return array<string,mixed>
	 */
	public function parent( int $account_id, array $product, array $activity ): array {
		$offers = $product['offers'];

		if ( ! $product['is_variable'] ) {
			return $this->row( $account_id, (int) $product['id'], $product['title'], $this->offer_cells( $product['title'], $offers[0] ), $offers, $activity, false );
		}

		return $this->row( $account_id, (int) $product['id'], $product['title'], $this->parent_cells( $product, $offers ), $offers, $activity, true );
	}

	/**
	 * Builds the row of a variation.
	 *
	 * @since 2.1.4
	 *
	 * @param int                 $account_id Account.
	 * @param int                 $parent_id  Variable product.
	 * @param array<string,mixed> $offer      Offer of {@see Site_Products_Reader::variation_page()}.
	 * @param array<string,mixed> $activity   Activity of the row.
	 * @return array<string,mixed>
	 */
	public function child( int $account_id, int $parent_id, array $offer, array $activity ): array {
		$name = '' !== $offer['label'] ? $offer['label'] : '#' . $offer['product_id'];

		return $this->row( $account_id, (int) $offer['product_id'], $name, $this->offer_cells( $name, $offer ), [ $offer ], $activity, false, $parent_id );
	}

	/**
	 * Wraps cells into a row.
	 *
	 * @param int                            $account_id  Account.
	 * @param int                            $product_id  Row's product.
	 * @param string                         $name        Name, for the edit link and the revision.
	 * @param array<string,mixed>            $cells       Cells.
	 * @param array<int,array<string,mixed>> $offers      Offers the row stands for.
	 * @param array<string,mixed>            $activity    Activity.
	 * @param bool                           $has_children Whether the row expands.
	 * @param int                            $edit_id     Product whose edit screen opens; the row's own when 0.
	 * @return array<string,mixed>
	 */
	private function row( int $account_id, int $product_id, string $name, array $cells, array $offers, array $activity, bool $has_children, int $edit_id = 0 ): array {
		$edit = (string) call_user_func( $this->edit_url, $edit_id > 0 ? $edit_id : $product_id );
		$link = '' === $edit ? [] : [ 'url' => $edit ];

		return [
			'id'           => Site_Products_Ids::row_id( $account_id, $product_id ),
			'revision'     => substr( md5( (string) wp_json_encode( [ $name, $offers ] ) ), 0, 16 ),
			'cells'        => $cells,
			'actions'      => [
				[
					'id'      => 'edit',
					'enabled' => '' !== $edit,
					'reason'  => '' !== $edit ? '' : __( 'Нет права изменять этот товар.', 'woodev-plugin-framework' ),
				] + $link,
			],
			'selectable'   => true,
			'has_children' => $has_children,
			'activity'     => $activity,
		];
	}

	/**
	 * Cells of a single offer: a simple product or a variation.
	 *
	 * @param string              $name  Name.
	 * @param array<string,mixed> $offer Offer.
	 * @return array<string,mixed>
	 */
	private function offer_cells( string $name, array $offer ): array {
		$linked = null !== $offer['link_id'];

		return [
			'name'      => $this->clip( $name ),
			'sku'       => $this->sku_cell( $offer ),
			'state'     => [
				[
					'type' => 'badge',
					'value' => State_Groups::badge( $offer['group'] ),
				],
			],
			'stock'     => $this->pair_cell( $this->count( $offer['site_stock'] ), null === $offer['remote_stock'] ? null : (string) $offer['remote_stock'] ),
			'price'     => $this->pair_cell( $this->price( $offer['site_price'] ), $this->price( $offer['remote_price'] ) ),
			'warehouse' => $linked ? $this->warehouse_name( $offer['warehouse_id'] ) : null,
			'sync'      => $linked ? $this->sync_text( $offer ) : null,
			'error'     => '' === $offer['error'] ? null : $this->clip( $offer['error'] ),
			'actions'   => null,
		];
	}

	/**
	 * Cells of a variable product: the title, its own SKU, the badges of its variations and their stock and price summed.
	 *
	 * @param array<string,mixed>            $product Product row.
	 * @param array<int,array<string,mixed>> $offers  Its variations.
	 * @return array<string,mixed>
	 */
	private function parent_cells( array $product, array $offers ): array {
		$errors = array_filter( array_column( $offers, 'error' ) );

		return [
			'name'      => $this->clip( $product['title'] ),
			'sku'       => '' === $product['sku'] ? null : [
				[
					'type' => 'text',
					'value' => $this->clip( $product['sku'] ),
				],
			],
			'state'     => $this->state_badges( $offers ),
			'stock'     => $this->pair_cell( $this->site_stock_sum( $offers ), $this->remote_stock_sum( $offers ) ),
			'price'     => $this->pair_cell( $this->price_range( array_column( $offers, 'site_price' ) ), null ),
			'warehouse' => null,
			'sync'      => null,
			'error'     => [] === $errors ? null : $this->clip( sprintf( /* translators: %d: number of variations. */ _n( 'Ошибка у %d варианта', 'Ошибка у %d вариантов', count( $errors ), 'woodev-plugin-framework' ), count( $errors ) ) ),
			'actions'   => null,
		];
	}

	/**
	 * One badge per state group present among the variations, the biggest group first.
	 *
	 * @param array<int,array<string,mixed>> $offers Offers.
	 * @return array<int,array<string,mixed>>
	 */
	private function state_badges( array $offers ): array {
		$counts = array_count_values( array_column( $offers, 'group' ) );
		arsort( $counts );
		$badges = [];

		foreach ( array_slice( $counts, 0, self::MAX_BADGES, true ) as $group => $count ) {
			$badges[] = [
				'type'  => 'badge',
				'value' => State_Groups::badge( (string) $group, $count ),
			];
		}

		return $badges;
	}

	/**
	 * @param array<string,mixed> $offer Offer.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function sku_cell( array $offer ): ?array {
		$items = [];

		foreach ( [ $offer['sku'], $offer['offer_id'] ] as $text ) {
			if ( '' !== $text ) {
				$items[] = [
					'type' => 'text',
					'value' => $this->clip( $text ),
				];
			}
		}

		return [] === $items ? null : $items;
	}

	/**
	 * «На сайте: 5» above «Маркетплейс: 4».
	 *
	 * @param string|null $site   Site value, null for none.
	 * @param string|null $remote Marketplace value, null for none.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function pair_cell( ?string $site, ?string $remote ): ?array {
		$items = [];

		if ( null !== $site ) {
			$items[] = [
				'type' => 'text',
				'value' => sprintf( /* translators: %s: value. */ __( 'На сайте: %s', 'woodev-plugin-framework' ), $site ),
			];
		}

		if ( null !== $remote ) {
			$items[] = [
				'type' => 'text',
				'value' => $this->clip( $this->marketplace . ': ' . $remote ),
			];
		}

		return [] === $items ? null : $items;
	}

	/**
	 * @param array<string,mixed> $offer Offer.
	 * @return string
	 */
	private function sync_text( array $offer ): string {
		return sprintf(
			/* translators: 1: «да» or «нет» for stock, 2: for price. */
			__( 'Остатки: %1$s · Цены: %2$s', 'woodev-plugin-framework' ),
			$offer['sync_stock'] ? __( 'да', 'woodev-plugin-framework' ) : __( 'нет', 'woodev-plugin-framework' ),
			$offer['sync_price'] ? __( 'да', 'woodev-plugin-framework' ) : __( 'нет', 'woodev-plugin-framework' )
		);
	}

	/**
	 * @param int|null $warehouse_id Override, null for the account's default.
	 * @return string
	 */
	private function warehouse_name( ?int $warehouse_id ): string {
		if ( null === $warehouse_id || $warehouse_id <= 0 ) {
			return __( 'По умолчанию', 'woodev-plugin-framework' );
		}

		return $this->clip( $this->warehouses[ (string) $warehouse_id ] ?? '#' . $warehouse_id );
	}

	/**
	 * The site stock of a variable product: the sum of its variations, a shared pool counted once. Null when nothing manages stock.
	 *
	 * @param array<int,array<string,mixed>> $offers Variations.
	 * @return string|null
	 */
	private function site_stock_sum( array $offers ): ?string {
		$sum   = 0;
		$known = false;
		$pools = [];

		foreach ( $offers as $offer ) {
			if ( null === $offer['site_stock'] ) {
				continue;
			}

			$known = true;
			$pool  = $offer['stock_pool'];

			if ( null !== $pool && isset( $pools[ $pool ] ) ) {
				continue;
			}

			$pools[ (int) $pool ] = true;
			$sum                 += (int) $offer['site_stock'];
		}

		return $known ? (string) $sum : null;
	}

	/**
	 * @param array<int,array<string,mixed>> $offers Variations.
	 * @return string|null
	 */
	private function remote_stock_sum( array $offers ): ?string {
		$values = array_filter(
			array_column( $offers, 'remote_stock' ),
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		return [] === $values ? null : (string) array_sum( $values );
	}

	/**
	 * «100 – 250» over the prices that exist.
	 *
	 * @param array<int,string|null> $prices Prices.
	 * @return string|null
	 */
	private function price_range( array $prices ): ?string {
		$prices = array_values(
			array_filter(
				$prices,
				static function ( $price ): bool {
					return null !== $price;
				}
			)
		);

		if ( [] === $prices ) {
			return null;
		}

		$low  = min( array_map( 'floatval', $prices ) );
		$high = max( array_map( 'floatval', $prices ) );

		return $low === $high ? $this->price( (string) $low ) : $this->price( (string) $low ) . ' – ' . $this->price( (string) $high );
	}

	/**
	 * @param string|null $price Price as stored.
	 * @return string|null
	 */
	private function price( $price ): ?string {
		return null === $price || '' === $price ? null : (string) (float) $price;
	}

	/**
	 * @param int|null $count Stock.
	 * @return string|null
	 */
	private function count( $count ): ?string {
		return null === $count ? null : (string) $count;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function clip( string $text ): string {
		return mb_strlen( $text ) > self::CLIP ? mb_substr( $text, 0, self::CLIP - 1 ) . '…' : $text;
	}
}

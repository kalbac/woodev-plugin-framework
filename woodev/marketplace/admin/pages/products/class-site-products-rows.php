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
	 * Address of a product's picture: `function ( int $product_id ): string`; the WooCommerce placeholder's when the product has none.
	 *
	 * @var callable|null
	 */
	private $image_url;

	/**
	 * Turns a link's article into the text of the «Артикул» cell: `function ( string $offer_id ): string`.
	 *
	 * @var callable|null
	 */
	private $shown_article;

	/**
	 * What else there is to say about a product's article: `function ( int $product_id ): string`.
	 *
	 * @var callable|null
	 */
	private $article_notice;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.4
	 * @since 2.1.13 `$image_url` adds the photo column.
	 * @since 2.1.22 `$shown_article` and `$article_notice`.
	 *
	 * @param string               $marketplace Merchant-facing name of the marketplace.
	 * @param array<string,string> $warehouses  Warehouse id => name of the account.
	 * @param callable             $edit_url    `function ( int $product_id ): string`, '' when there is none.
	 * @param callable|null        $image_url   `function ( int $product_id ): string`, '' when there is none; null leaves the photo cell empty.
	 * @param callable|null        $shown_article `function ( string $offer_id ): string`, how a link's article is shown; as it is when null.
	 * @param callable|null        $article_notice `function ( int $product_id ): string`, a sentence for the product's state badge about its article.
	 */
	public function __construct( string $marketplace, array $warehouses, callable $edit_url, ?callable $image_url = null, ?callable $shown_article = null, ?callable $article_notice = null ) {
		$this->shown_article  = $shown_article;
		$this->article_notice = $article_notice;
		$this->marketplace    = $marketplace;
		$this->warehouses  = $warehouses;
		$this->edit_url    = $edit_url;
		$this->image_url   = $image_url;
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
			'cells'        => [ 'image' => $this->image_cell( $product_id, $name ) ] + $cells,
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
	 * The photo cell: the product's picture, or the WooCommerce placeholder, in the size of a table thumbnail.
	 *
	 * @param int    $product_id Product or variation.
	 * @param string $name       Name, for the picture's alternative text.
	 * @return array<int,array{type:string,value:array{url:string,alt:string}}>|null Null when there is no address.
	 */
	private function image_cell( int $product_id, string $name ): ?array {
		$url = null === $this->image_url ? '' : (string) call_user_func( $this->image_url, $product_id );

		if ( '' === $url ) {
			return null;
		}

		return [
			[
				'type'  => 'image',
				'value' => [
					'url' => $url,
					'alt' => $this->clip( $name ),
				],
			],
		];
	}

	/**
	 * Cells of a single offer: a simple product or a variation. The «Состояние» cell is one badge like the delivery page's; what is
	 * synchronised and the error are its tooltip, not lines under it, so every row is the same height.
	 *
	 * @param string              $name  Name.
	 * @param array<string,mixed> $offer Offer.
	 * @return array<string,mixed>
	 */
	private function offer_cells( string $name, array $offer ): array {
		$badge = State_Groups::badge( $offer['group'] );
		$tip   = $this->state_tooltip( $offer, null !== $offer['link_id'] );

		return [
			'name'      => $this->clip( $name ),
			'sku'       => $this->sku_cell( $offer ),
			'state'     => [
				[
					'type'  => 'badge',
					'value' => '' === $tip ? $badge : $badge + [ 'tooltip' => $tip ],
				],
			],
			'stock'     => $this->pair_text( $this->count( $offer['site_stock'] ), null === $offer['remote_stock'] ? null : (string) $offer['remote_stock'] ),
			'price'     => $this->pair_text( $this->price( $offer['site_price'] ), $this->price( $offer['remote_price'] ) ),
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
		$badges = $this->state_badges( $offers, Row_Normalizer::MAX_STACK_ITEMS );

		// The errors of the variations are said once, in the tooltip of the first (biggest) group's badge.
		if ( [] !== $errors && [] !== $badges ) {
			$badges[0]['value']['tooltip'] = sprintf( /* translators: %d: number of variations. */ _n( 'Ошибка у %d варианта', 'Ошибка у %d вариантов', count( $errors ), 'woodev-plugin-framework' ), count( $errors ) );
		}

		return [
			'name'      => $this->clip( $product['title'] ),
			'sku'       => '' === $product['sku'] ? null : [
				[
					'type' => 'text',
					'value' => $this->clip( $product['sku'] ),
				],
			],
			'state'     => $badges,
			'stock'     => $this->pair_text( $this->site_stock_sum( $offers ), $this->remote_stock_sum( $offers ) ),
			'price'     => $this->pair_text( $this->price_range( array_column( $offers, 'site_price' ) ), null ),
			'actions'   => null,
		];
	}

	/**
	 * One badge per state group present among the variations, the biggest group first.
	 *
	 * @param array<int,array<string,mixed>> $offers Offers.
	 * @param int                            $room   Most badges the cell has room for.
	 * @return array<int,array<string,mixed>>
	 */
	private function state_badges( array $offers, int $room ): array {
		$counts = array_count_values( array_column( $offers, 'group' ) );
		arsort( $counts );
		$badges = [];

		foreach ( array_slice( $counts, 0, $room, true ) as $group => $count ) {
			$badges[] = [
				'type'  => 'badge',
				'value' => State_Groups::badge( (string) $group, $count ),
			];
		}

		return $badges;
	}

	/**
	 * The site's article and, only when the marketplace's offer id is another one, the offer id under it.
	 *
	 * @param array<string,mixed> $offer Offer.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function sku_cell( array $offer ): ?array {
		$items = [];

		$offer_id = (string) $offer['offer_id'];
		$shown    = null === $this->shown_article || '' === $offer_id ? $offer_id : (string) call_user_func( $this->shown_article, $offer_id );

		foreach ( array_unique( array_filter( [ $offer['sku'], $shown ], 'strlen' ) ) as $text ) {
			$items[] = [
				'type'  => 'text',
				'value' => $this->clip( $text ),
			];
		}

		return [] === $items ? null : $items;
	}

	/**
	 * «5 / 4»: the site's value, a slash, the marketplace's. «—» stands for a side that has none, so «500 / —» reads as «not on the
	 * marketplace yet». The column's «?» says which side is which.
	 *
	 * @param string|null $site   Site value, null for none.
	 * @param string|null $remote Marketplace value, null for none.
	 * @return string
	 */
	private function pair_text( ?string $site, ?string $remote ): string {
		return $this->clip( ( $site ?? '—' ) . ' / ' . ( $remote ?? '—' ) );
	}

	/**
	 * What the badge explains on hover: what is synchronised, the warehouse when the product has one of its own, and the error the
	 * marketplace gave. One sentence a line; empty when there is nothing to add.
	 *
	 * @param array<string,mixed> $offer  Offer.
	 * @param bool                $linked Whether the offer has a card link (an unlinked product synchronises nothing).
	 * @return string
	 */
	private function state_tooltip( array $offer, bool $linked ): string {
		$lines = [];

		if ( $linked ) {
			$on = [];

			if ( $offer['sync_stock'] ) {
				$on[] = __( 'остатки', 'woodev-plugin-framework' );
			}

			if ( $offer['sync_price'] ) {
				$on[] = __( 'цены', 'woodev-plugin-framework' );
			}

			$lines[] = [] === $on
				? __( 'Синхронизация выключена', 'woodev-plugin-framework' )
				/* translators: %s: what is synchronised, for example «остатки, цены». */
				: sprintf( __( 'Синхронизируются: %s', 'woodev-plugin-framework' ), implode( ', ', $on ) );

			if ( null !== $offer['warehouse_id'] && $offer['warehouse_id'] > 0 ) {
				/* translators: %s: warehouse name. */
				$lines[] = sprintf( __( 'Склад: %s', 'woodev-plugin-framework' ), $this->warehouse_name( $offer['warehouse_id'] ) );
			}
		}

		if ( '' !== $offer['error'] ) {
			/* translators: %s: the marketplace's error text. */
			$lines[] = sprintf( __( 'Ошибка: %s', 'woodev-plugin-framework' ), $offer['error'] );
		}

		$notice = null === $this->article_notice ? '' : (string) call_user_func( $this->article_notice, (int) $offer['product_id'] );

		if ( '' !== $notice ) {
			$lines[] = $notice;
		}

		return mb_substr( implode( "\n", $lines ), 0, Row_Normalizer::MAX_TOOLTIP_LENGTH );
	}

	/**
	 * @param int $warehouse_id The product's own warehouse.
	 * @return string
	 */
	private function warehouse_name( int $warehouse_id ): string {
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

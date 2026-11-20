<?php
/**
 * Cells of the «Карточки {marketplace}» table that come straight from a card's fields.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Row_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot card into the cells of its photo, name, price and stock, and the lines of its identifiers.
 *
 * Every field is read defensively: a card that does not carry one (an older snapshot, a marketplace that does not report it) simply
 * shows «—» or nothing, so a column lights up the moment the store starts providing the field. The keys read are `image_url`,
 * `price` and `stock`.
 *
 * @since 2.1.13
 */
final class Remote_Cards_Cells {
	/**
	 * Longest name or address, in characters.
	 */
	private const CLIP = 200;

	/**
	 * `function ( array $remote_ids ): string`, or null when the adapter gives no cabinet addresses.
	 *
	 * @var callable|null
	 */
	private $card_url;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.13
	 *
	 * @param callable|null $card_url {@see Remote_Card_Address::remote_card_url()}.
	 */
	public function __construct( ?callable $card_url = null ) {
		$this->card_url = $card_url;
	}

	/**
	 * The card's photo, a small square; the WooCommerce placeholder when the card has none, so the column is never an empty cell.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array<int,array{type:string,value:mixed}>|null Null outside WooCommerce, where there is no placeholder either.
	 */
	public function image( array $card ): ?array {
		$own = $this->address( (string) ( $card['image_url'] ?? '' ) );
		$url = '' === $own ? $this->placeholder() : $own;

		if ( '' === $url ) {
			return null;
		}

		return [
			[
				'type'  => 'image',
				'value' => [
					'url' => $url,
					'alt' => $this->clip( (string) ( $card['name'] ?? '' ) ),
				],
			],
		];
	}

	/**
	 * The WooCommerce placeholder picture, shown for a card that has no photo of its own so the column is never an empty cell.
	 *
	 * @since 2.1.13
	 *
	 * @return string Empty outside WooCommerce, where there is no placeholder to show.
	 */
	private function placeholder(): string {
		return function_exists( 'wc_placeholder_img_src' ) ? $this->address( (string) wc_placeholder_img_src( 'thumbnail' ) ) : '';
	}

	/**
	 * The card's name, a link to the card in the seller's cabinet when the adapter knows the address.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array<int,array{type:string,value:mixed}>|null Null for a card with no name.
	 */
	public function name( array $card ): ?array {
		$name = $this->clip( trim( (string) ( $card['name'] ?? '' ) ) );

		if ( '' === $name ) {
			return null;
		}

		$url = $this->card_address( $card );

		return [
			'' === $url
				? [
					'type'  => 'text',
					'value' => $name,
				]
				: [
					'type'  => 'link',
					'value' => [
						'label' => $name,
						'url'   => $url,
					],
				],
		];
	}

	/**
	 * Where the card is in the seller's cabinet.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $card Card.
	 * @return string Empty when the adapter gives no address.
	 */
	public function card_address( array $card ): string {
		return null === $this->card_url ? '' : $this->address( (string) call_user_func( $this->card_url, (array) ( $card['remote_ids'] ?? [] ) ) );
	}

	/**
	 * The card's price on the marketplace, «—» when it is not known.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $card Card.
	 * @return string
	 */
	public function price( array $card ): string {
		$price = $card['price'] ?? null;

		return is_numeric( $price ) ? (string) (float) $price : '—';
	}

	/**
	 * The card's stock on the marketplace, «—» when it is not known.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $card Card.
	 * @return string
	 */
	public function stock( array $card ): string {
		$stock = $card['stock'] ?? null;

		return is_numeric( $stock ) ? (string) (int) $stock : '—';
	}

	/**
	 * What a merchant calls the marketplace's identifiers (the product tab says «ID товара · SKU · модель»). An identifier that is
	 * not listed keeps the adapter's own name.
	 *
	 * @return array<string,string>
	 */
	private function id_names(): array {
		return [
			'product_id' => __( 'ID товара', 'woodev-plugin-framework' ),
			'sku'        => __( 'SKU', 'woodev-plugin-framework' ),
			'model_id'   => __( 'Модель', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The marketplace's numbers of the card, as «ID товара: 1» lines (for the state badge's tooltip).
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $remote_ids Identifier name => value.
	 * @return string[]
	 */
	public function id_lines( array $remote_ids ): array {
		$lines = [];
		$names = $this->id_names();

		foreach ( $remote_ids as $name => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value || '0' === (string) $value ) {
				continue;
			}

			$lines[] = ( $names[ $name ] ?? $name ) . ': ' . $value;
		}

		return array_slice( $lines, 0, 5 );
	}

	/**
	 * An address the table may show: one the engine accepts, else none. A card with a stray address loses its photo or its link, not the whole list.
	 *
	 * @param string $url Address.
	 * @return string Empty when it is not usable.
	 */
	private function address( string $url ): string {
		try {
			return Row_Normalizer::safe_url( trim( $url ) );
		} catch ( \UnexpectedValueException $e ) {
			return '';
		}
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function clip( string $text ): string {
		return mb_strlen( $text ) > self::CLIP ? mb_substr( $text, 0, self::CLIP - 1 ) . '…' : $text;
	}
}

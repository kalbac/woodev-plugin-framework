<?php
/**
 * One marketplace card, read whole, in neutral units.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Price\Price_Pair;

defined( 'ABSPATH' ) || exit;

/**
 * What an adapter reads of one card when the merchant imports it: the link card the linker takes, and the content the product is
 * written from. The adapter converts the marketplace's units; this value holds metres as millimetres and grams, and money as
 * {@see Price_Pair} in the cabinet's currency.
 *
 * Constructed from two arrays so that an adapter names only the members it can fill:
 *
 * - `$card`: the link card of {@see \Woodev\Framework\Marketplace\Flows\Link_Resolver} (`offer_id`, `remote_ids`, `state`,
 *   `is_created`, `archived`, `failed`, `status_text`, `oversize`), handed to `Card_Linker::link()` as it is;
 * - `$fields`: `name`, `description` (raw, the writer sanitises it), `prices` (Price_Pair|null), `weight_g`, `length_mm`,
 *   `width_mm`, `height_mm` (int|null), `primary_image` (string), `images` (string[]), `remote_category` (the descriptor
 *   `Mapping::remote_category()` stores), `category_path` (string[], root first) and `group` (reserved for groups: `key`, `size`,
 *   `aspects`). A missing member reads as unknown: '' for text, null for a number or price, [] for a list.
 *
 * @since 2.1.6
 */
final class Card_Content {
	/**
	 * Link card of the marketplace.
	 *
	 * @var array<string,mixed>
	 */
	private $card;

	/**
	 * Content fields, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $fields;

	/**
	 * Creates the value.
	 *
	 * @since 2.1.6
	 *
	 * @param array<string,mixed> $card   Link card.
	 * @param array<string,mixed> $fields Content fields.
	 */
	public function __construct( array $card, array $fields = [] ) {
		$this->card   = $card;
		$this->fields = $fields;
	}

	/**
	 * Returns the link card.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,mixed>
	 */
	public function card(): array {
		return $this->card;
	}

	/**
	 * Returns the name of the card.
	 *
	 * @since 2.1.6
	 *
	 * @return string
	 */
	public function name(): string {
		return (string) ( $this->fields['name'] ?? '' );
	}

	/**
	 * Returns the description, raw.
	 *
	 * @since 2.1.6
	 *
	 * @return string
	 */
	public function description(): string {
		return (string) ( $this->fields['description'] ?? '' );
	}

	/**
	 * Returns the selling price and the old price, or null when the card has none.
	 *
	 * @since 2.1.6
	 *
	 * @return Price_Pair|null
	 */
	public function prices(): ?Price_Pair {
		$prices = $this->fields['prices'] ?? null;

		return $prices instanceof Price_Pair ? $prices : null;
	}

	/**
	 * Returns the weight in grams, or null when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return int|null
	 */
	public function weight_g(): ?int {
		return $this->number( 'weight_g' );
	}

	/**
	 * Returns the length in millimetres, or null when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return int|null
	 */
	public function length_mm(): ?int {
		return $this->number( 'length_mm' );
	}

	/**
	 * Returns the width in millimetres, or null when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return int|null
	 */
	public function width_mm(): ?int {
		return $this->number( 'width_mm' );
	}

	/**
	 * Returns the height in millimetres, or null when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return int|null
	 */
	public function height_mm(): ?int {
		return $this->number( 'height_mm' );
	}

	/**
	 * Returns the absolute URL of the primary image, or '' when there is none.
	 *
	 * @since 2.1.6
	 *
	 * @return string
	 */
	public function primary_image(): string {
		return (string) ( $this->fields['primary_image'] ?? '' );
	}

	/**
	 * Returns the absolute URLs of the other images, in the marketplace's order.
	 *
	 * @since 2.1.6
	 *
	 * @return string[]
	 */
	public function images(): array {
		return $this->strings( 'images' );
	}

	/**
	 * Returns the category descriptor the mapping matches on, or [] when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,mixed>
	 */
	public function remote_category(): array {
		return (array) ( $this->fields['remote_category'] ?? [] );
	}

	/**
	 * Returns the category names from the root down, or [] when unknown.
	 *
	 * @since 2.1.6
	 *
	 * @return string[]
	 */
	public function category_path(): array {
		return $this->strings( 'category_path' );
	}

	/**
	 * Returns the group the card belongs to. Reserved for groups (card I7); the importer ignores it until then.
	 *
	 * @since 2.1.6
	 *
	 * @return array{key:string,size:int,aspects:array<string,mixed>}
	 */
	public function group(): array {
		$group = (array) ( $this->fields['group'] ?? [] );

		return [
			'key'     => (string) ( $group['key'] ?? '' ),
			'size'    => (int) ( $group['size'] ?? 0 ),
			'aspects' => (array) ( $group['aspects'] ?? [] ),
		];
	}

	/**
	 * Reads an optional whole number.
	 *
	 * @param string $name Field name.
	 * @return int|null
	 */
	private function number( string $name ): ?int {
		$value = $this->fields[ $name ] ?? null;

		return null === $value ? null : (int) $value;
	}

	/**
	 * Reads a list of strings, dropping anything else.
	 *
	 * @param string $name Field name.
	 * @return string[]
	 */
	private function strings( string $name ): array {
		return array_values( array_filter( (array) ( $this->fields[ $name ] ?? [] ), 'is_string' ) );
	}
}

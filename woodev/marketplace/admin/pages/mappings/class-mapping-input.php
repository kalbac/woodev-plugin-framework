<?php
/**
 * A validated mapping form.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the JSON body of a mapping save into checked values: a request body is validated against a declared shape before use.
 * Pure apart from the WordPress sanitizers; the checks that need the shop (does the product category exist, is the category in
 * the marketplace tree) are passed in or done by the caller.
 *
 * Body shape: `name`, `terms[]` (shop category ids), `category` (resolved by the caller, see {@see Category_Finder}),
 * `profile_id`, and `attributes{attribute id: {source, value, dictionary_value_id, key}}` where `source` is the kind and `key`
 * is the attribute taxonomy, meta key or product field for every kind but a fixed value.
 *
 * @since 2.0.30
 */
final class Mapping_Input {
	public const MAX_NAME = 200;

	/**
	 * Source kinds the form may send. An empty kind means «no source».
	 *
	 * @var string[]
	 */
	private const KINDS = [ Source::FIXED, Source::WC_ATTRIBUTE, Source::META, Source::FIELD ];

	/**
	 * Keys a mapping body may hold.
	 *
	 * @var string[]
	 */
	public const BODY_KEYS = [ 'name', 'terms', 'category', 'profile_id', 'attributes' ];

	/**
	 * Merchant label.
	 *
	 * @var string
	 */
	private $name = '';

	/**
	 * Shop category term ids.
	 *
	 * @var int[]
	 */
	private $term_ids = [];

	/**
	 * Identity of the marketplace category, empty when none is chosen.
	 *
	 * @var array<string,mixed>
	 */
	private $category = [];

	/**
	 * Profile id.
	 *
	 * @var int|null
	 */
	private $profile_id;

	/**
	 * Attribute sources, as stored.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $attributes = [];

	/**
	 * Error lines.
	 *
	 * @var string[]
	 */
	private $errors = [];

	/**
	 * Reads a body.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,mixed>                        $body           Decoded request body.
	 * @param array<string,mixed>|null                   $category       Identity of the category the caller resolved from `category`, null for none.
	 * @param array<string,array{name:string,dict:bool}> $fields        Fields of that category by id; a sent id outside it is dropped.
	 * @param callable                                   $term_exists    `function ( int $term_id ): bool`, true for a product category.
	 * @param array<string,string>                       $product_fields Built-in product fields a `field` source may name (key => label).
	 * @param array<string,mixed>|null                   $stored         The mapping as stored (`category`, `attributes`); null for a new one.
	 * @return self
	 */
	public static function from_request(
		array $body,
		?array $category,
		array $fields,
		callable $term_exists,
		array $product_fields,
		?array $stored = null
	): self {
		$input             = new self();
		$input->name       = $input->read_name( $body );
		$input->term_ids   = $input->read_terms( $body, $term_exists );
		$input->category   = $category ?? [];
		$input->profile_id = self::positive_int( $body, 'profile_id' );
		$input->read_table( $body, $fields, $product_fields, $stored );

		return $input;
	}

	/**
	 * Tells whether the form can be saved.
	 *
	 * @since 2.0.30
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return [] === $this->errors;
	}

	/**
	 * Returns the error lines.
	 *
	 * @since 2.0.30
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns the label.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the shop category term ids.
	 *
	 * @since 2.0.30
	 *
	 * @return int[]
	 */
	public function term_ids(): array {
		return $this->term_ids;
	}

	/**
	 * Returns the marketplace category identity, empty when none is chosen.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,mixed>
	 */
	public function category(): array {
		return $this->category;
	}

	/**
	 * Returns the profile id.
	 *
	 * @since 2.0.30
	 *
	 * @return int|null
	 */
	public function profile_id(): ?int {
		return $this->profile_id;
	}

	/**
	 * Returns the attribute sources in the stored shape.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,array<string,string>>
	 */
	public function attributes(): array {
		return $this->attributes;
	}

	/**
	 * Reads the attribute table, or keeps the stored sources when no table came with a save that left the category as it was.
	 *
	 * An absent table is not an instruction to clear the sources: the view sends `attributes` only with a table it has loaded, so
	 * a save made before the table arrived (a slow connection, a failed load) cannot erase what the mapping holds. Clearing a
	 * source stays possible through its row, set to «no source».
	 *
	 * @param array<string,mixed>                        $body           Request body.
	 * @param array<string,array{name:string,dict:bool}> $fields         Fields of the category.
	 * @param array<string,string>                       $product_fields Product fields a `field` source may name.
	 * @param array<string,mixed>|null                   $stored         The stored mapping.
	 * @return void
	 */
	private function read_table( array $body, array $fields, array $product_fields, ?array $stored ): void {
		$stored_sources = null === $stored ? [] : (array) ( $stored['attributes'] ?? [] );

		if ( [] === $this->category || array_key_exists( 'attributes', $body ) ) {
			$this->attributes = $this->read_attributes( $body, $fields, $product_fields, $stored_sources );

			return;
		}

		if ( null !== $stored && $this->is_same_category( (array) ( $stored['category'] ?? [] ) ) ) {
			$this->attributes = $stored_sources;

			return;
		}

		$this->errors[] = Mapping_Messages::attributes_not_loaded();
	}

	/**
	 * Tells whether a stored category is the one chosen now.
	 *
	 * @param array<string,mixed> $stored Stored category descriptor.
	 * @return bool
	 */
	private function is_same_category( array $stored ): bool {
		return [] !== $stored && Category_Finder::key_of( $stored ) === Category_Finder::key_of( $this->category );
	}

	/**
	 * Reads the label.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return string
	 */
	private function read_name( array $body ): string {
		$name = isset( $body['name'] ) && is_scalar( $body['name'] ) ? trim( sanitize_text_field( (string) $body['name'] ) ) : '';

		if ( '' === $name ) {
			$this->errors[] = Mapping_Messages::mapping_name_required();
		}

		if ( mb_strlen( $name ) > self::MAX_NAME ) {
			$this->errors[] = Mapping_Messages::name_too_long( self::MAX_NAME );
		}

		return $name;
	}

	/**
	 * Reads the shop categories.
	 *
	 * @param array<string,mixed> $body        Request body.
	 * @param callable            $term_exists Category check.
	 * @return int[]
	 */
	private function read_terms( array $body, callable $term_exists ): array {
		if ( ! isset( $body['terms'] ) ) {
			return [];
		}

		if ( ! is_array( $body['terms'] ) ) {
			$this->errors[] = Mapping_Messages::malformed();

			return [];
		}

		$ids = [];
		foreach ( $body['terms'] as $raw ) {
			$term_id = is_scalar( $raw ) ? (int) $raw : 0;

			if ( $term_id > 0 && call_user_func( $term_exists, $term_id ) ) {
				$ids[] = $term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Reads the attribute table.
	 *
	 * @param array<string,mixed>                        $body           Request body.
	 * @param array<string,array{name:string,dict:bool}> $fields         Fields of the category.
	 * @param array<string,string>                       $product_fields Product fields a `field` source may name.
	 * @param array<string,mixed>                        $stored         Stored sources, by attribute id.
	 * @return array<string,array<string,mixed>>
	 */
	private function read_attributes( array $body, array $fields, array $product_fields, array $stored ): array {
		if ( ! isset( $body['attributes'] ) ) {
			return [];
		}

		if ( ! is_array( $body['attributes'] ) ) {
			$this->errors[] = Mapping_Messages::malformed();

			return [];
		}

		$result = [];
		foreach ( $body['attributes'] as $attribute_id => $entry ) {
			$attribute_id = (string) $attribute_id;

			if ( ! isset( $fields[ $attribute_id ] ) ) {
				continue;
			}

			if ( ! is_array( $entry ) ) {
				$this->errors[] = Mapping_Messages::malformed();
				continue;
			}

			$previous = isset( $stored[ $attribute_id ] ) && is_array( $stored[ $attribute_id ] ) ? $stored[ $attribute_id ] : null;
			$clean    = $this->read_entry( $entry, $fields[ $attribute_id ], $product_fields, $previous );

			if ( null !== $clean ) {
				$result[ $attribute_id ] = $clean;
			}
		}

		return $result;
	}

	/**
	 * Reads one attribute row.
	 *
	 * @param array<string,mixed>          $entry          Sent row.
	 * @param array{name:string,dict:bool} $field          The attribute.
	 * @param array<string,string>         $product_fields Product fields a `field` source may name.
	 * @param array<string,mixed>|null     $stored         The stored source of the attribute.
	 * @return array<string,mixed>|null Null when the row has no source.
	 */
	private function read_entry( array $entry, array $field, array $product_fields, ?array $stored ): ?array {
		$kind = isset( $entry['source'] ) && is_scalar( $entry['source'] ) ? (string) $entry['source'] : '';

		if ( '' === $kind ) {
			return null;
		}

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$this->errors[] = Mapping_Messages::malformed();

			return null;
		}

		$value = self::text( $entry, 'value' );
		$key   = self::text( $entry, 'key' );
		$dict  = isset( $entry['dictionary_value_id'] ) && is_scalar( $entry['dictionary_value_id'] ) ? (int) $entry['dictionary_value_id'] : 0;

		if ( null !== $stored && self::is_unchanged( $stored, $kind, $value, $key, $dict ) ) {
			return $stored; // What the form cannot show (a fallback value, further dictionary values from v1) stays with it.
		}

		if ( Source::FIXED === $kind ) {
			return $this->fixed( $field, $value, $dict );
		}

		if ( '' === $key || ( Source::FIELD === $kind && ! isset( $product_fields[ $key ] ) ) ) {
			$this->errors[] = Mapping_Messages::attribute_source_missing( $field['name'] );

			return null;
		}

		return [
			'source' => $kind,
			'wc_key' => $key,
		];
	}

	/**
	 * Reads a positive integer body value.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @param string              $key  Field name.
	 * @return int|null Null when absent, not a number or not above zero.
	 */
	private static function positive_int( array $body, string $key ): ?int {
		$value = isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ? (int) $body[ $key ] : 0;

		return $value > 0 ? $value : null;
	}

	/**
	 * Reads a trimmed text value of a row.
	 *
	 * @param array<string,mixed> $entry Sent row.
	 * @param string              $name  Key.
	 * @return string
	 */
	private static function text( array $entry, string $name ): string {
		return isset( $entry[ $name ] ) && is_scalar( $entry[ $name ] ) ? trim( sanitize_text_field( (string) $entry[ $name ] ) ) : '';
	}

	/**
	 * Tells whether a sent row selects what is already stored.
	 *
	 * @param array<string,mixed> $stored Stored source.
	 * @param string              $kind   Sent kind.
	 * @param string              $value  Sent value.
	 * @param string              $key    Sent key.
	 * @param int                 $dict   Sent dictionary value id.
	 * @return bool
	 */
	private static function is_unchanged( array $stored, string $kind, string $value, string $key, int $dict ): bool {
		if ( (string) ( $stored['source'] ?? '' ) !== $kind ) {
			return false;
		}

		if ( Source::FIXED !== $kind ) {
			return (string) ( $stored['wc_key'] ?? '' ) === $key;
		}

		return (string) ( $stored['value'] ?? '' ) === $value && (int) ( $stored['dictionary_value_id'] ?? 0 ) === $dict;
	}

	/**
	 * Builds a fixed-value row.
	 *
	 * @param array{name:string,dict:bool} $field The attribute.
	 * @param string                       $value Typed value or dictionary label.
	 * @param int                          $dict  Chosen dictionary value id.
	 * @return array<string,string>|null
	 */
	private function fixed( array $field, string $value, int $dict ): ?array {
		if ( $field['dict'] && $dict <= 0 ) {
			$this->errors[] = Mapping_Messages::dictionary_value_missing( $field['name'] );

			return null;
		}

		if ( ! $field['dict'] && '' === $value ) {
			$this->errors[] = Mapping_Messages::fixed_value_missing( $field['name'] );

			return null;
		}

		$row = [
			'source' => Source::FIXED,
			'value'  => $value,
		];

		if ( $field['dict'] ) {
			$row['dictionary_value_id'] = (string) $dict;
		}

		return $row;
	}
}

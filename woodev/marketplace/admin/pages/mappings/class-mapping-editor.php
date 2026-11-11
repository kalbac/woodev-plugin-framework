<?php
/**
 * Reads and writes mappings for the editor view.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the mapping editor does to the data, with no HTTP in it: the summaries of the list, one mapping with its
 * attribute table, the dictionary search, and the guarded writes (save, copy, delete).
 *
 * The rules a write keeps: a shop category belongs to at most one mapping (checked before the write, and again by the
 * store when two saves race), the chosen marketplace category and profile must exist, and a sent attribute outside
 * the category's fields is dropped. A write result is an array `{status, id, errors, notices}`; the controller maps
 * the status to HTTP.
 *
 * @since 2.1.0
 */
final class Mapping_Editor {
	/**
	 * Fewest characters a dictionary search takes.
	 */
	public const MIN_SEARCH = 2;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * Marketplace categories.
	 *
	 * @var Category_Finder
	 */
	private $categories;

	/**
	 * The products a mapping covers.
	 *
	 * @var Mapping_Products
	 */
	private $products;

	/**
	 * Built-in product fields a `field` source may name (key => label).
	 *
	 * @var array<string,string>
	 */
	private $product_fields;

	/**
	 * Full name of the links table, for the «still chosen by a product» check.
	 *
	 * @var string
	 */
	private $links_table;

	/**
	 * Creates the editor.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Mapping_Store   $mappings       Mappings.
	 * @param Wpdb_Profile_Store   $profiles       Profiles.
	 * @param Category_Finder      $categories     Marketplace categories.
	 * @param Mapping_Products     $products       The products a mapping covers.
	 * @param array<string,string> $product_fields Product fields a `field` source may name.
	 * @param string               $links_table    Full name of the links table.
	 */
	public function __construct(
		Wpdb_Mapping_Store $mappings,
		Wpdb_Profile_Store $profiles,
		Category_Finder $categories,
		Mapping_Products $products,
		array $product_fields,
		string $links_table
	) {
		$this->mappings       = $mappings;
		$this->profiles       = $profiles;
		$this->categories     = $categories;
		$this->products       = $products;
		$this->product_fields = $product_fields;
		$this->links_table    = $links_table;
	}

	/**
	 * Lists the term-bound mappings for the list.
	 *
	 * @since 2.1.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function summaries(): array {
		$rows       = [];
		$by_mapping = $this->mappings->terms_by_mapping();

		foreach ( $this->mappings->all_term_bound() as $mapping ) {
			$id     = $mapping['mapping_id'];
			$entry  = $this->categories->find_by_identity( $mapping['category'] );
			$rows[] = [
				'id'             => $id,
				'name'           => $mapping['name'],
				'terms'          => $by_mapping[ $id ] ?? [],
				'category_label' => $this->category_label( $mapping['category'], $entry ),
				'profile_id'     => $mapping['profile_id'],
				'products'       => $this->products->count( $id ),
			];
		}

		return $rows;
	}

	/**
	 * Returns a term-bound mapping as stored, without asking the marketplace anything.
	 *
	 * @since 2.1.0
	 *
	 * @param int $mapping_id Mapping id.
	 * @return array<string,mixed>|null The stored row (`mapping_id`, `name`, `wc_product_id`, `category`, `profile_id`,
	 *                                  `attributes`).
	 */
	public function stored( int $mapping_id ): ?array {
		$stored = $this->mappings->find( $mapping_id );

		return null === $stored || null !== $stored['wc_product_id'] ? null : $stored;
	}

	/**
	 * Returns one mapping with the attribute table of its category.
	 *
	 * @since 2.1.0
	 *
	 * @param int $mapping_id Mapping id.
	 * @return array<string,mixed>|null Null when it is not a term-bound mapping.
	 * @throws \Throwable When the adapter cannot give the fields of the category.
	 */
	public function detail( int $mapping_id ): ?array {
		$stored = $this->mappings->find( $mapping_id );

		if ( null === $stored || null !== $stored['wc_product_id'] ) {
			return null;
		}

		$entry = $this->categories->find_by_identity( $stored['category'] );

		return [
			'id'         => $mapping_id,
			'name'       => $stored['name'],
			'terms'      => $this->mappings->terms_by_mapping()[ $mapping_id ] ?? [],
			'profile_id' => $stored['profile_id'],
			'category'   => $this->category_view( $stored['category'], $entry ),
			'attributes' => null === $entry ? [] : $this->attribute_rows( $entry['identity'], $stored['attributes'] ),
		];
	}

	/**
	 * Builds the attribute table of a category, filled from stored sources.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $identity Category identity.
	 * @param array<string,mixed> $stored   Stored sources by attribute id; empty for a mapping that has none for this
	 *   category.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable When the adapter cannot give the fields.
	 */
	public function attribute_rows( array $identity, array $stored = [] ): array {
		$rows = [];

		foreach ( $this->categories->fields( $identity ) as $field ) {
			$known  = $stored[ $field->id() ] ?? null;
			$source = is_array( $known ) ? $known : null;
			$rows[] = [
				'id'         => $field->id(),
				'name'       => $field->name(),
				'required'   => $field->is_required(),
				'type'       => $field->type(),
				'dictionary' => Field_Definition::TYPE_DICTIONARY === $field->type(),
				'multi'      => $field->is_multi(),
				'unit'       => $field->unit(),
				'source'     => self::source_view( $source ),
			];
		}

		return $rows;
	}

	/**
	 * Searches the values of a dictionary field of a category.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $identity Category identity.
	 * @param string              $field_id Attribute id.
	 * @param string              $term     Search text.
	 * @return array<int,array{id:int,text:string,name:string}>|null Null when the attribute has no dictionary.
	 * @throws \Throwable When the adapter cannot search.
	 */
	public function dictionary_values( array $identity, string $field_id, string $term ): ?array {
		$field = $this->dictionary_field( $identity, $field_id );

		if ( null === $field ) {
			return null;
		}

		if ( mb_strlen( $term ) < self::MIN_SEARCH ) {
			return [];
		}

		$results = [];
		foreach ( $this->categories->search_dictionary( $field, $term ) as $value ) {
			$info      = (string) ( $value['info'] ?? '' );
			$name      = (string) ( $value['value'] ?? '' );
			$results[] = [
				'id'   => (int) ( $value['id'] ?? 0 ),
				'text' => $name . ( '' !== $info ? ' — ' . $info : '' ),
				'name' => $name,
			];
		}

		return $results;
	}

	/**
	 * Saves a mapping: creates it when `$mapping_id` is null, else changes it.
	 *
	 * @since 2.1.0
	 *
	 * @param int|null            $mapping_id Mapping id, null for a new one.
	 * @param array<string,mixed> $body       Decoded request body.
	 * @return array{status:string,id:int,errors:string[],notices:string[]} Status `saved`, `invalid`, `missing`,
	 *   `unavailable` or `failed`.
	 */
	public function save( ?int $mapping_id, array $body ): array {
		$stored = null === $mapping_id ? null : $this->mappings->find( $mapping_id );

		if ( null !== $mapping_id && ( null === $stored || null !== $stored['wc_product_id'] ) ) {
			return self::result( 'missing', 0, [ Mapping_Messages::mapping_not_found() ] );
		}

		try {
			$chosen = $this->chosen_category( $body );
			$fields = null === $chosen['entry'] ? [] : $this->field_map( $chosen['entry']['identity'] );
		} catch ( \Throwable $e ) {
			return self::result( 'unavailable', 0, [ Mapping_Messages::marketplace_failure( $e->getMessage() ) ] );
		}

		if ( null !== $chosen['error'] ) {
			return self::result( 'invalid', 0, [ $chosen['error'] ] );
		}

		$identity = null === $chosen['entry'] ? null : $chosen['entry']['identity'];
		$exists   = [ Shop_Categories::class, 'exists' ];
		$input    = Mapping_Input::from_request( $body, $identity, $fields, $exists, $this->product_fields, $stored );
		$errors   = array_merge( $input->errors(), $this->reference_errors( $input, $mapping_id ) );

		return [] === $errors ? $this->persist( $mapping_id, $input ) : self::result( 'invalid', 0, $errors );
	}

	/**
	 * Copies a mapping without its shop categories (a category cannot be in two mappings).
	 *
	 * @since 2.1.0
	 *
	 * @param int $mapping_id Mapping to copy.
	 * @return array{status:string,id:int,errors:string[],notices:string[]}
	 */
	public function copy( int $mapping_id ): array {
		$source = $this->mappings->find( $mapping_id );

		if ( null === $source || null !== $source['wc_product_id'] ) {
			return self::result( 'missing', 0, [ Mapping_Messages::mapping_not_found() ] );
		}

		$name = mb_substr( $source['name'], 0, Mapping_Input::MAX_NAME - 10 ) . ' ' . Mapping_Messages::copy_suffix();

		try {
			$id = $this->mappings->create(
				$name,
				null,
				$source['category'],
				$source['profile_id'],
				$source['attributes']
			);
		} catch ( \RuntimeException $e ) {
			return self::result( 'failed', 0, [ Mapping_Messages::save_failed() ] );
		}

		return self::result( 'saved', $id );
	}

	/**
	 * Deletes a mapping unless a product still names it.
	 *
	 * @since 2.1.0
	 *
	 * @param int $mapping_id Mapping id.
	 * @return array{status:string,id:int,errors:string[],notices:string[]} Status `saved` (deleted), `missing` or
	 *   `in_use`.
	 */
	public function delete( int $mapping_id ): array {
		$linked = $this->mappings->count_linked( $this->links_table, $mapping_id );

		if ( $linked > 0 ) {
			return self::result( 'in_use', $mapping_id, [ Mapping_Messages::mapping_in_use( $linked ) ] );
		}

		if ( ! $this->mappings->delete( $mapping_id ) ) {
			return self::result( 'missing', 0, [ Mapping_Messages::mapping_not_found() ] );
		}

		return self::result( 'saved', $mapping_id );
	}

	/**
	 * Resolves the category named in a body.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return array{entry:array{key:string,label:string,identity:array<string,mixed>}|null,error:string|null}
	 *         A null entry with no error means «no category».
	 */
	private function chosen_category( array $body ): array {
		$key = $body['category'] ?? null;

		if ( null === $key || '' === $key ) {
			return [
				'entry' => null,
				'error' => array_key_exists( 'category', $body ) ? null : Mapping_Messages::malformed(),
			];
		}

		$entry = is_string( $key ) ? $this->categories->find( $key ) : null;

		return [
			'entry' => $entry,
			'error' => null === $entry ? Mapping_Messages::unknown_category() : null,
		];
	}

	/**
	 * Lists the fields of a category as the input reads them: `id => {name, dict}`.
	 *
	 * @param array<string,mixed> $identity Category identity.
	 * @return array<string,array{name:string,dict:bool}>
	 * @throws \Throwable When the adapter cannot give the fields.
	 */
	private function field_map( array $identity ): array {
		$map = [];

		foreach ( $this->categories->fields( $identity ) as $field ) {
			$map[ $field->id() ] = [
				'name' => $field->name(),
				'dict' => Field_Definition::TYPE_DICTIONARY === $field->type(),
			];
		}

		return $map;
	}

	/**
	 * Finds the dictionary field of a category.
	 *
	 * @param array<string,mixed> $identity Category identity.
	 * @param string              $field_id Attribute id.
	 * @return Field_Definition|null
	 */
	private function dictionary_field( array $identity, string $field_id ): ?Field_Definition {
		foreach ( $this->categories->fields( $identity ) as $field ) {
			if ( $field->id() === $field_id && Field_Definition::TYPE_DICTIONARY === $field->type() ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Checks what a valid form still refers to: the profile and the shop categories other mappings hold.
	 *
	 * @param Mapping_Input $input      Parsed form.
	 * @param int|null      $mapping_id Mapping being saved, null for a new one.
	 * @return string[]
	 */
	private function reference_errors( Mapping_Input $input, ?int $mapping_id ): array {
		$errors = [];

		if ( null !== $input->profile_id() && null === $this->profiles->get( $input->profile_id() ) ) {
			$errors[] = Mapping_Messages::chosen_profile_missing();
		}

		$taken = $this->taken_terms( $mapping_id );
		foreach ( $input->term_ids() as $term_id ) {
			if ( isset( $taken[ $term_id ] ) ) {
				$category = Shop_Categories::name_of( $term_id );
				$errors[] = Mapping_Messages::category_taken( $category, $taken[ $term_id ]['name'] );
			}
		}

		return $errors;
	}

	/**
	 * Lists the shop categories with the mapping that holds each, so the editor can show the one-mapping-per-category
	 * rule.
	 *
	 * @since 2.1.0
	 *
	 * @return array<int,array{id:int,label:string,mapping_id:int|null,mapping_name:string}>
	 */
	public function shop_categories(): array {
		$taken = $this->taken_terms( null );
		$list  = [];

		foreach ( Shop_Categories::all() as $category ) {
			$holder = $taken[ $category['id'] ] ?? null;
			$list[] = [
				'id'           => $category['id'],
				'label'        => $category['label'],
				'mapping_id'   => null === $holder ? null : $holder['id'],
				'mapping_name' => null === $holder ? '' : $holder['name'],
			];
		}

		return $list;
	}

	/**
	 * Lists the shop categories other mappings hold: `term id => {id, name}` of the holding mapping.
	 *
	 * @param int|null $own Mapping being saved, whose categories are not "taken".
	 * @return array<int,array{id:int,name:string}>
	 */
	private function taken_terms( ?int $own ): array {
		$by_mapping = $this->mappings->terms_by_mapping();
		$taken      = [];

		foreach ( $this->mappings->all_term_bound() as $mapping ) {
			if ( $mapping['mapping_id'] === $own ) {
				continue;
			}

			foreach ( $by_mapping[ $mapping['mapping_id'] ] ?? [] as $term_id ) {
				$taken[ $term_id ] = [
					'id'   => $mapping['mapping_id'],
					'name' => $mapping['name'],
				];
			}
		}

		return $taken;
	}

	/**
	 * Writes a checked form.
	 *
	 * @param int|null      $mapping_id Mapping id, null for a new one.
	 * @param Mapping_Input $input      Parsed form.
	 * @return array{status:string,id:int,errors:string[],notices:string[]}
	 */
	private function persist( ?int $mapping_id, Mapping_Input $input ): array {
		try {
			if ( null === $mapping_id ) {
				$id = $this->mappings->create(
					$input->name(),
					null,
					$input->category(),
					$input->profile_id(),
					$input->attributes()
				);
			} else {
				$id = $mapping_id;

				$updated = $this->mappings->update(
					$id,
					$input->name(),
					$input->category(),
					$input->profile_id(),
					$input->attributes()
				);

				if ( ! $updated ) {
					return self::result( 'failed', 0, [ Mapping_Messages::save_failed() ] );
				}
			}

			$refused = $this->mappings->replace_terms( $id, $input->term_ids() );
		} catch ( \RuntimeException | Persistence_Exception $e ) {
			return self::result( 'failed', 0, [ Mapping_Messages::save_failed() ] );
		}

		$notices = [] === $refused ? [] : [ Mapping_Messages::categories_partly_taken() ];

		return self::result( 'saved', $id, [], $notices );
	}

	/**
	 * The category as the editor shows it.
	 *
	 * @param array<string,mixed>                                              $stored Stored category descriptor.
	 * @param array{key:string,label:string,identity:array<string,mixed>}|null $entry  The entry the schema offers for
	 *   it.
	 * @return array{key:string,label:string,available:bool}|null Null when the mapping has no category.
	 */
	private function category_view( array $stored, ?array $entry ): ?array {
		if ( [] === $stored ) {
			return null;
		}

		return [
			'key'       => null === $entry ? Category_Finder::key_of( $stored ) : $entry['key'],
			'label'     => $this->category_label( $stored, $entry ),
			'available' => null !== $entry,
		];
	}

	/**
	 * The category name for a list row.
	 *
	 * @param array<string,mixed>                                              $stored Stored category descriptor.
	 * @param array{key:string,label:string,identity:array<string,mixed>}|null $entry  The entry the schema offers for
	 *   it.
	 * @return string Empty when the mapping has no category; the stored path when the schema no longer offers it.
	 */
	private function category_label( array $stored, ?array $entry ): string {
		if ( null !== $entry ) {
			return $entry['label'];
		}

		$path = isset( $stored['path'] ) && is_array( $stored['path'] ) ? $stored['path'] : [];

		return implode( ' › ', array_map( 'strval', $path ) );
	}

	/**
	 * The stored source of an attribute as the row shows it.
	 *
	 * @param array<string,mixed>|null $source Stored source.
	 * @return array{source:string,value:string,key:string,dictionary_value_id:string}
	 */
	private static function source_view( ?array $source ): array {
		return [
			'source'              => (string) ( $source['source'] ?? '' ),
			'value'               => (string) ( $source['value'] ?? '' ),
			'key'                 => (string) ( $source['wc_key'] ?? '' ),
			'dictionary_value_id' => (string) ( $source['dictionary_value_id'] ?? '' ),
		];
	}

	/**
	 * Builds a write result.
	 *
	 * @param string   $status  Status.
	 * @param int      $id      Id of the written or deleted mapping, 0 when none.
	 * @param string[] $errors  Error lines.
	 * @param string[] $notices Notices that do not make the write fail.
	 * @return array{status:string,id:int,errors:string[],notices:string[]}
	 */
	private static function result( string $status, int $id, array $errors = [], array $notices = [] ): array {
		return [
			'status'  => $status,
			'id'      => $id,
			'errors'  => $errors,
			'notices' => $notices,
		];
	}
}

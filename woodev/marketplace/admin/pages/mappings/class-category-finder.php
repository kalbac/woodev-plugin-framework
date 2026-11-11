<?php
/**
 * Category search over the adapter's catalogue schema.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Searches {@see Catalog_Schema::categories()} for the mapping editor.
 *
 * The schema returns **adapter-defined descriptors**; the finder needs to tell two things apart in them. A descriptor
 * may carry two reserved display keys, and everything else is the category's *identity* (Ozon: category and type ids;
 * Wildberries: the subject id) which is what a mapping stores and what {@see Catalog_Schema::fields()} receives:
 *
 * - `path` — `string[]`, the names from the root to the category; the last one is the category itself. It is what the
 *   merchant searches and sees. A descriptor without a path is shown by its key.
 * - `disabled` — `true` for a node that exists in the tree but cannot hold a card; it is never offered.
 *
 * The browser never builds an identity: it gets an opaque `key` per category and sends the key back, and the server
 * resolves it here. A key (or a stored identity) the schema no longer offers resolves to nothing, so a forged or
 * stale category cannot be saved. The finder reads the schema once per request and never asks the marketplace itself;
 * whether `categories()` reads a cache is the adapter's business.
 *
 * @since 2.1.0
 */
final class Category_Finder {
	/**
	 * Most results one search returns.
	 */
	public const LIMIT = 40;

	/**
	 * Separator of the path shown to the merchant.
	 */
	private const SEPARATOR = ' › ';

	/**
	 * Descriptor keys that are display data, not identity.
	 */
	private const DISPLAY_KEYS = [ 'path', 'disabled' ];

	/**
	 * Schema.
	 *
	 * @var Catalog_Schema
	 */
	private $schema;

	/**
	 * Selectable categories by key, read once; null before the first read.
	 *
	 * @var array<string,array{key:string,label:string,identity:array<string,mixed>}>|null
	 */
	private $entries;

	/**
	 * Creates the finder.
	 *
	 * @since 2.1.0
	 *
	 * @param Catalog_Schema $schema Catalogue schema.
	 */
	public function __construct( Catalog_Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Returns the key of a category identity: stable for the same identity whatever the order of its keys.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $identity Category identity.
	 * @return string
	 */
	public static function key_of( array $identity ): string {
		ksort( $identity );

		return substr( sha1( (string) wp_json_encode( $identity ) ), 0, 16 );
	}

	/**
	 * Tells whether the schema offers any category.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_loaded(): bool {
		return [] !== $this->entries();
	}

	/**
	 * Finds categories whose path contains every word of the search text.
	 *
	 * @since 2.1.0
	 *
	 * @param string $term Search text.
	 * @return array<int,array{key:string,label:string}>
	 */
	public function search( string $term ): array {
		$words = preg_split( '/\s+/u', trim( $term ), -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? [] : $words;
		$found = [];

		foreach ( $this->entries() as $entry ) {
			if ( ! self::contains_all( $entry['label'], $words ) ) {
				continue;
			}

			$found[] = [
				'key'   => $entry['key'],
				'label' => $entry['label'],
			];

			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Looks a category up by the key the browser sent.
	 *
	 * @since 2.1.0
	 *
	 * @param string $key Category key.
	 * @return array{key:string,label:string,identity:array<string,mixed>}|null Null when the schema does not offer it.
	 */
	public function find( string $key ): ?array {
		return $this->entries()[ $key ] ?? null;
	}

	/**
	 * Looks a category up by a stored identity.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $identity Stored category descriptor.
	 * @return array{key:string,label:string,identity:array<string,mixed>}|null Null when the schema no longer offers
	 *   it.
	 */
	public function find_by_identity( array $identity ): ?array {
		return [] === $identity ? null : $this->find( self::key_of( $identity ) );
	}

	/**
	 * Lists the fields of a category.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $identity Category identity from {@see self::find()}.
	 * @return Field_Definition[]
	 */
	public function fields( array $identity ): array {
		return $this->schema->fields( $identity );
	}

	/**
	 * Searches the values of a dictionary field.
	 *
	 * @since 2.1.0
	 *
	 * @param Field_Definition $field Dictionary field.
	 * @param string           $term  Search text.
	 * @return array<int,array<string,mixed>> Values with at least `id` and `value`.
	 */
	public function search_dictionary( Field_Definition $field, string $term ): array {
		return $this->schema->search_dictionary( $field, $term );
	}

	/**
	 * Lists the selectable categories, keyed by category key, sorted by label.
	 *
	 * @return array<string,array{key:string,label:string,identity:array<string,mixed>}>
	 */
	private function entries(): array {
		if ( null !== $this->entries ) {
			return $this->entries;
		}

		$this->entries = [];

		foreach ( $this->schema->categories() as $descriptor ) {
			$entry = is_array( $descriptor ) ? self::entry( $descriptor ) : null;

			if ( null !== $entry ) {
				$this->entries[ $entry['key'] ] = $entry;
			}
		}

		uasort(
			$this->entries,
			static function ( array $a, array $b ): int {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return $this->entries;
	}

	/**
	 * Turns one descriptor into an entry.
	 *
	 * @param array<string,mixed> $descriptor Descriptor from the schema.
	 * @return array{key:string,label:string,identity:array<string,mixed>}|null Null for a disabled node or one with
	 *   no identity.
	 */
	private static function entry( array $descriptor ): ?array {
		if ( ! empty( $descriptor['disabled'] ) ) {
			return null;
		}

		$identity = array_diff_key( $descriptor, array_flip( self::DISPLAY_KEYS ) );

		if ( [] === $identity ) {
			return null;
		}

		ksort( $identity );
		$key = self::key_of( $identity );

		return [
			'key'      => $key,
			'label'    => self::label( $descriptor, $key ),
			'identity' => $identity,
		];
	}

	/**
	 * Builds the label the merchant sees.
	 *
	 * @param array<string,mixed> $descriptor Descriptor.
	 * @param string              $fallback   Shown when the descriptor has no path.
	 * @return string
	 */
	private static function label( array $descriptor, string $fallback ): string {
		$path  = isset( $descriptor['path'] ) && is_array( $descriptor['path'] ) ? $descriptor['path'] : [];
		$names = array_filter( array_map( 'strval', $path ) );

		return [] === $names ? $fallback : implode( self::SEPARATOR, $names );
	}

	/**
	 * Tells whether a label contains every word, ignoring case.
	 *
	 * @param string   $label Category path.
	 * @param string[] $words Search words.
	 * @return bool
	 */
	private static function contains_all( string $label, array $words ): bool {
		foreach ( $words as $word ) {
			if ( false === mb_stripos( $label, $word ) ) {
				return false;
			}
		}

		return true;
	}
}

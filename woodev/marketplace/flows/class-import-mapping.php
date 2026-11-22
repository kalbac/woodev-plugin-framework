<?php
/**
 * The mapping an imported card's shop category gets when it has none.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Input;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the «shop category ↔ marketplace category and type» mapping that the merchant would otherwise make by hand on the
 * «Сопоставление» page, for the category an imported card was put in ({@see Import_Category}). It adds no request to the marketplace:
 * the pair comes from the card ({@see Card_Content::remote_category()}), the attribute names from the schema's own cache. It runs
 * **before** the product is filled ({@see Card_Import}), so the profile that fills the product and prices it is the one the export
 * will read later.
 *
 * What it does, for a card whose pair has **no** mapping:
 *
 * - the shop category of the card's path has no mapping either → one is created for it, with no profile (a new mapping made by hand
 *   starts the same), and its attribute table links each characteristic the import wrote as a product attribute (the aspects of a
 *   card in a group of several, {@see Group_Assembler::place()}) to that attribute, by the characteristic's name;
 * - the shop category already belongs to another mapping → nothing is changed, and the card's note says so: the cards of one shop
 *   category are mapped by the type of the first of them.
 *
 * A mapping that exists is the merchant's and is never changed — with one exception. A mapping this class created carries a mark (a
 * fingerprint of what it wrote, kept beside the attribute sources), so a later card of the same pair **adds the links it brings that
 * the table lacks**: the first card may have had no aspects, or fewer. A row that is there is never overwritten, and a mapping whose
 * name, category, profile or table the merchant changed no longer matches its mark and is not touched again.
 *
 * A dictionary characteristic is linked only when the marketplace can resolve a label to a dictionary value id locally
 * ({@see \Woodev\Framework\Marketplace\Catalog\Dictionary_Label_Lookup}); without that the link would fail the export's validation.
 *
 * @since 2.1.15
 */
final class Import_Mapping {
	/**
	 * Key in the attribute table that marks a mapping this class created. It is no field id, and the readers of the table skip it.
	 */
	private const MARK = '_import_created';

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * The shop categories of an imported card.
	 *
	 * @var Import_Category
	 */
	private $categories;

	/**
	 * The marketplace's categories and their fields.
	 *
	 * @var Category_Finder
	 */
	private $finder;

	/**
	 * The marketplace's name in the merchant's sentences («Ozon»); '' for the neutral «маркетплейс».
	 *
	 * @var string
	 */
	private $marketplace_name;

	/**
	 * Whether the marketplace resolves a dictionary label to a value id from local data.
	 *
	 * @var bool
	 */
	private $resolves_dictionary_labels;

	/**
	 * Creates the writer.
	 *
	 * @since 2.1.15
	 *
	 * @param Wpdb_Mapping_Store $mappings                   Mappings.
	 * @param Import_Category    $categories                 The shop categories of an imported card.
	 * @param Category_Finder    $finder                     The marketplace's categories and their fields.
	 * @param string             $marketplace_name           The marketplace's name for merchant messages.
	 * @param bool               $resolves_dictionary_labels Whether the marketplace's export facts are a {@see \Woodev\Framework\Marketplace\Catalog\Dictionary_Label_Lookup}.
	 */
	public function __construct( Wpdb_Mapping_Store $mappings, Import_Category $categories, Category_Finder $finder, string $marketplace_name = '', bool $resolves_dictionary_labels = false ) {
		$this->mappings                   = $mappings;
		$this->categories                 = $categories;
		$this->finder                     = $finder;
		$this->marketplace_name           = trim( $marketplace_name );
		$this->resolves_dictionary_labels = $resolves_dictionary_labels;
	}

	/**
	 * Gives the shop category of an imported card its mapping when it has none, or completes the attribute table of the mapping an
	 * earlier import made. Call it only when the import creates categories, after the card's product was started and before it is
	 * filled: the category path it names exists by then, so it is found, not created twice.
	 *
	 * @since 2.1.15
	 *
	 * @param Card_Content $content The imported card.
	 * @return string What the merchant should read in the card's note, '' for nothing.
	 */
	public function ensure( Card_Content $content ): string {
		$remote = $content->remote_category();

		if ( [] === $remote ) {
			return '';
		}

		$found = $this->mappings->for_category( $remote );

		if ( null !== $found ) {
			$this->add_missing_links( $found['mapping'], $content );

			return '';
		}

		$term_id = $this->categories->term_ids( $content, true )[0] ?? 0;

		if ( $term_id <= 0 ) {
			return '';
		}

		$holder = $this->mappings->mapping_id_for_term( $term_id );

		return null === $holder ? $this->create( $content, $term_id ) : $this->another_type_note( $term_id, $holder );
	}

	/**
	 * Creates the mapping of a shop category and takes the category for it.
	 *
	 * @param Card_Content $content The card whose pair is mapped.
	 * @param int          $term_id The shop category, held by no mapping.
	 * @return string The note: '' when the mapping was made, or lost the category to a mapping saved at the same moment.
	 */
	private function create( Card_Content $content, int $term_id ): string {
		try {
			$name       = mb_substr( $this->term_name( $term_id ), 0, Mapping_Input::MAX_NAME );
			$category   = $content->remote_category();
			$attributes = self::marked( $name, $category, null, $this->attribute_links( $content ) );
			$id         = $this->mappings->create( $name, null, $category, null, $attributes );

			if ( ! $this->mappings->link_term_if_absent( $term_id, $id ) ) {
				$this->mappings->delete( $id ); // A category is in one mapping: do not leave this one without a category.
			}
		} catch ( \RuntimeException $exception ) { // Includes the store's Persistence_Exception.
			return __( 'Сопоставление категории не создано: не удалось записать его в базу данных.', 'woodev-plugin-framework' );
		}

		return '';
	}

	/**
	 * Adds the links a card brings to a mapping this class created and nobody has changed since. A mapping that is the merchant's, or a
	 * link that is there already, is left as it is.
	 *
	 * @param array{mapping_id:int,name:string,category:array<string,mixed>,profile_id:int|null,attributes:array<string,mixed>} $mapping The stored mapping of the card's pair.
	 * @param Card_Content                                                                                                      $content The card.
	 * @return void
	 */
	private function add_missing_links( array $mapping, Card_Content $content ): void {
		$stored = $mapping['attributes'];

		if ( ! self::is_untouched( $mapping ) ) {
			return;
		}

		$missing = array_diff_key( $this->attribute_links( $content ), $stored );

		if ( [] === $missing ) {
			return;
		}

		$attributes = self::marked( $mapping['name'], $mapping['category'], $mapping['profile_id'], array_diff_key( $stored, [ self::MARK => true ] ) + $missing );

		$this->mappings->update( $mapping['mapping_id'], $mapping['name'], $mapping['category'], $mapping['profile_id'], $attributes );
	}

	/**
	 * Tells whether a mapping is still as this class wrote it.
	 *
	 * @param array{name:string,category:array<string,mixed>,profile_id:int|null,attributes:array<string,mixed>} $mapping The stored mapping.
	 * @return bool
	 */
	private static function is_untouched( array $mapping ): bool {
		$mark = $mapping['attributes'][ self::MARK ] ?? null;

		return is_string( $mark ) && hash_equals( $mark, self::fingerprint( $mapping['name'], $mapping['category'], $mapping['profile_id'], $mapping['attributes'] ) );
	}

	/**
	 * Puts the mark on an attribute table.
	 *
	 * @param string                             $name       The mapping's name.
	 * @param array<string,mixed>                $category   Its marketplace category.
	 * @param int|null                           $profile_id Its profile.
	 * @param array<string,array<string,string>> $links      The attribute sources, without the mark.
	 * @return array<string,mixed>
	 */
	private static function marked( string $name, array $category, ?int $profile_id, array $links ): array {
		return $links + [ self::MARK => self::fingerprint( $name, $category, $profile_id, $links ) ];
	}

	/**
	 * Fingerprints what a mapping holds apart from its mark. The merchant changing the name, the category, the profile or a source —
	 * or copying the mapping, which renames it — makes it differ.
	 *
	 * @param string              $name       The mapping's name.
	 * @param array<string,mixed> $category   Its marketplace category.
	 * @param int|null            $profile_id Its profile.
	 * @param array<string,mixed> $attributes Its attribute table, the mark included or not.
	 * @return string
	 */
	private static function fingerprint( string $name, array $category, ?int $profile_id, array $attributes ): string {
		unset( $attributes[ self::MARK ] );

		return sha1( (string) wp_json_encode( [ $name, $category, $profile_id, $attributes ] ) );
	}

	/**
	 * Links each characteristic the import wrote as a product attribute to that attribute. The import writes only the aspects of a
	 * card in a group of several, and names the attribute as the marketplace names the characteristic, so the link is made by name
	 * against the fields the schema holds for the pair — none when the schema has not cached them.
	 *
	 * @param Card_Content $content The card.
	 * @return array<string,array<string,string>> Attribute sources by field id, in the stored shape.
	 */
	private function attribute_links( Card_Content $content ): array {
		$group = $content->group();

		if ( '' === Group_Assembler::key_of( $group ) || $group['size'] < 2 ) {
			return [];
		}

		$fields = $this->fields_by_name( $content->remote_category() );
		$links  = [];

		foreach ( $group['aspects'] as $name => $value ) {
			$name  = trim( (string) $name );
			$field = $fields[ mb_strtolower( $name ) ] ?? null;

			if ( '' !== $name && is_scalar( $value ) && '' !== trim( (string) $value ) && null !== $field ) {
				$links[ $field->id() ] = [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => $name,
				];
			}
		}

		return $links;
	}

	/**
	 * Returns the fields of a pair a link may name, by lower-cased name; the first of two fields with one name wins. A dictionary
	 * field is left out unless its labels can be resolved locally.
	 *
	 * @param array<string,mixed> $remote The pair.
	 * @return array<string,Field_Definition>
	 */
	private function fields_by_name( array $remote ): array {
		$fields = [];

		foreach ( $this->finder->cached_fields( $remote ) ?? [] as $field ) {
			$key = mb_strtolower( trim( $field->name() ) );

			if ( Field_Definition::TYPE_DICTIONARY === $field->type() && ! $this->resolves_dictionary_labels ) {
				continue;
			}

			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Tells the merchant that a shop category was mapped by the type of an earlier card, when a card of another type landed in it.
	 *
	 * @param int $term_id The shop category.
	 * @param int $holder  The mapping that holds it.
	 * @return string The sentence, '' when the mapping has no marketplace category to name.
	 */
	private function another_type_note( int $term_id, int $holder ): string {
		$mapping = $this->mappings->find( $holder );
		$entry   = null === $mapping ? null : $this->finder->find_by_identity( $mapping['category'] );

		if ( null === $entry ) {
			return '';
		}

		$where = __( 'на маркетплейсе', 'woodev-plugin-framework' );

		if ( '' !== $this->marketplace_name ) {
			/* translators: %s: marketplace name */
			$where = sprintf( __( 'в %s', 'woodev-plugin-framework' ), $this->marketplace_name );
		}

		/* translators: 1: shop category name, 2: where the cards are («в Ozon»), 3: marketplace category and type of the mapping */
		return sprintf( __( 'Категория «%1$s»: %2$s карточки разных типов, сопоставление создано по типу «%3$s».', 'woodev-plugin-framework' ), $this->term_name( $term_id ), $where, $entry['label'] );
	}

	/**
	 * Returns the name of a shop category.
	 *
	 * @param int $term_id Product category id.
	 * @return string The id when the category cannot be read.
	 */
	private function term_name( int $term_id ): string {
		$name = get_term_field( 'name', $term_id, 'product_cat' );

		return is_string( $name ) && '' !== $name ? $name : (string) $term_id;
	}
}

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
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the «shop category ↔ marketplace category and type» mapping that the merchant would otherwise make by hand on the
 * «Сопоставление» page, for the category an imported card was put in ({@see Import_Category}). It adds no request to the marketplace:
 * the pair comes from the card ({@see Card_Content::remote_category()}), the attribute names from the schema's own cache.
 *
 * What it does, for a card whose pair has **no** mapping:
 *
 * - the shop category of the card's path has no mapping either → one is created for it, with no profile (a new mapping made by hand
 *   starts the same), and its attribute table links each characteristic the import wrote as a product attribute (the aspects of a
 *   card in a group of several, {@see Group_Assembler::place()}) to that attribute, by the characteristic's name;
 * - the shop category already belongs to another mapping → nothing is changed, and when that mapping is for another marketplace
 *   type, the card's note says so: the cards of one shop category are mapped by the type of the first of them.
 *
 * A mapping that already exists, the merchant's or an earlier import's, is never read for change here: a card whose pair is
 * mapped returns at once. So the attribute links are made once, from the card that creates the mapping.
 *
 * @since 2.1.15
 */
final class Import_Mapping {
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
	 * Creates the writer.
	 *
	 * @since 2.1.15
	 *
	 * @param Wpdb_Mapping_Store $mappings   Mappings.
	 * @param Import_Category    $categories The shop categories of an imported card.
	 * @param Category_Finder    $finder     The marketplace's categories and their fields.
	 */
	public function __construct( Wpdb_Mapping_Store $mappings, Import_Category $categories, Category_Finder $finder ) {
		$this->mappings   = $mappings;
		$this->categories = $categories;
		$this->finder     = $finder;
	}

	/**
	 * Gives the shop category of an imported card its mapping when it has none. Call it only when the import creates categories,
	 * and after the card's product was made: the category path it names exists by then, so it is found, not created twice.
	 *
	 * @since 2.1.15
	 *
	 * @param Card_Content $content The imported card.
	 * @return string What the merchant should read in the card's note, '' for nothing.
	 */
	public function ensure( Card_Content $content ): string {
		$remote = $content->remote_category();

		if ( [] === $remote || null !== $this->mappings->for_category( $remote ) ) {
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
			$name = mb_substr( $this->term_name( $term_id ), 0, Mapping_Input::MAX_NAME );
			$id   = $this->mappings->create( $name, null, $content->remote_category(), null, $this->attribute_links( $content ) );

			if ( ! $this->mappings->link_term_if_absent( $term_id, $id ) ) {
				$this->mappings->delete( $id ); // A category is in one mapping: do not leave this one without a category.
			}
		} catch ( \RuntimeException $exception ) { // Includes the store's Persistence_Exception.
			return __( 'Сопоставление категории не создано: не удалось записать его в базу данных.', 'woodev-plugin-framework' );
		}

		return '';
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

		$ids = $this->field_ids_by_name( $content->remote_category() );

		if ( [] === $ids ) {
			return [];
		}

		$links = [];

		foreach ( $group['aspects'] as $name => $value ) {
			$name = trim( (string) $name );
			$key  = mb_strtolower( $name );

			if ( '' !== $name && is_scalar( $value ) && '' !== trim( (string) $value ) && isset( $ids[ $key ] ) ) {
				$links[ $ids[ $key ] ] = [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => $name,
				];
			}
		}

		return $links;
	}

	/**
	 * Returns the ids of a pair's fields by lower-cased name; the first of two fields with one name wins.
	 *
	 * @param array<string,mixed> $remote The pair.
	 * @return array<string,string>
	 */
	private function field_ids_by_name( array $remote ): array {
		$ids = [];

		foreach ( $this->finder->cached_fields( $remote ) ?? [] as $field ) {
			$key = mb_strtolower( trim( $field->name() ) );

			if ( ! isset( $ids[ $key ] ) ) {
				$ids[ $key ] = $field->id();
			}
		}

		return $ids;
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

		/* translators: 1: shop category name, 2: marketplace category and type of the mapping */
		return sprintf( __( 'Категория «%1$s»: на маркетплейсе карточки разных типов, сопоставление создано по типу «%2$s».', 'woodev-plugin-framework' ), $this->term_name( $term_id ), $entry['label'] );
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

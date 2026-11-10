<?php
/**
 * Wildberries-shaped catalogue schema.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;

/**
 * Parent category, then subject, then characteristics. Dictionaries are fixed directories (here: colours), not a
 * searchable dictionary per attribute as on Ozon, so the provider ignores the search term's reach and just filters.
 */
final class Fake_Catalog_Schema implements Catalog_Schema {
	private const COLORS = array(
		array(
			'id'    => '1',
			'value' => 'красный',
		),
		array(
			'id'    => '2',
			'value' => 'синий',
		),
		array(
			'id'    => '3',
			'value' => 'тёмно-синий',
		),
	);

	/**
	 * Lists subjects with their parent category.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function categories(): array {
		return array(
			array(
				'parent_id'  => 1,
				'subject_id' => 105,
				'name'       => 'Платья',
			),
		);
	}

	/**
	 * Lists the characteristics of a subject.
	 *
	 * @param array<string,mixed> $remote_category Subject descriptor.
	 * @return Field_Definition[]
	 */
	public function fields( array $remote_category ): array {
		return array(
			new Field_Definition( '1001', 'Бренд', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '1002', 'Цвет', true, Field_Definition::TYPE_DICTIONARY, true, null, array( $this, 'colors' ) ),
			new Field_Definition( '1003', 'Вес', false, Field_Definition::TYPE_DECIMAL, false, 'kg' ),
		);
	}

	/**
	 * Searches a dictionary field through its provider.
	 *
	 * @param Field_Definition $field Field.
	 * @param string           $term  Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public function search_dictionary( Field_Definition $field, string $term ): array {
		$provider = $field->dictionary_provider();

		return null === $provider ? array() : (array) call_user_func( $provider, $term );
	}

	/**
	 * Returns the colour directory filtered by a term.
	 *
	 * @param string $term Search text; empty returns the whole directory.
	 * @return array<int,array<string,mixed>>
	 */
	public function colors( string $term ): array {
		return array_values(
			array_filter(
				self::COLORS,
				static function ( array $color ) use ( $term ): bool {
					return '' === $term || false !== mb_stripos( $color['value'], $term );
				}
			)
		);
	}
}

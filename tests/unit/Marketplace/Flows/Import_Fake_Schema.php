<?php
/**
 * A two-type catalogue for the tests of the mapping an import creates.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Cached_Catalog_Fields;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;

/**
 * Two selectable types of one category («Смартфоны» and «Планшеты»). Both have the fields «Бренд», «Цвет» and «Размер»; the fields are
 * answered from the cache only while {@see self::$warm} is set.
 */
final class Import_Fake_Schema implements Catalog_Schema, Cached_Catalog_Fields {
	public const PHONES  = [
		'description_category_id' => 10,
		'type_id'                 => 1,
	];
	public const TABLETS = [
		'description_category_id' => 10,
		'type_id'                 => 2,
	];

	/**
	 * Whether the fields of a type are in the schema's cache.
	 *
	 * @var bool
	 */
	public $warm = true;

	/**
	 * Lists the two types, each with the path the merchant sees.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function categories(): array {
		return [
			self::PHONES + [ 'path' => [ 'Электроника', 'Смартфоны' ] ],
			self::TABLETS + [ 'path' => [ 'Электроника', 'Планшеты' ] ],
		];
	}

	/**
	 * Lists the fields of a type.
	 *
	 * @param array<string,mixed> $remote_category Type.
	 * @return Field_Definition[]
	 */
	public function fields( array $remote_category ): array {
		return [
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '9533', 'Размер', false, Field_Definition::TYPE_STRING ),
		];
	}

	/**
	 * Lists the fields of a type from the cache only.
	 *
	 * @param array<string,mixed> $remote_category Type.
	 * @return Field_Definition[]|null
	 */
	public function cached_fields( array $remote_category ): ?array {
		return $this->warm ? $this->fields( $remote_category ) : null;
	}

	/**
	 * Searches dictionary values: the tests never do.
	 *
	 * @param Field_Definition $field Field.
	 * @param string           $term  Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public function search_dictionary( Field_Definition $field, string $term ): array {
		return [];
	}
}

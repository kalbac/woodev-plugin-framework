<?php
/**
 * Shared set-up of the mappings view tests: a fake catalogue, SQLite stores and the wired editors.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Check;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Editor;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Products;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Choices;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Editor;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

require_once __DIR__ . '/wp-term-stub.php';

/**
 * A catalogue of two selectable categories and one disabled node, with a dictionary attribute. It records what it was asked.
 */
final class Fake_Tree_Schema implements Catalog_Schema {
	/**
	 * When set, every call throws it (the adapter cannot reach the marketplace).
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Calls made, by method.
	 *
	 * @var array<string,int>
	 */
	public $calls = [];

	/**
	 * Lists the categories: two selectable and one disabled.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function categories(): array {
		$this->enter( 'categories' );

		return [
			[
				'category_id' => 10,
				'type_id'     => 1,
				'path'        => [ 'Одежда', 'Платья' ],
			],
			[
				'type_id'     => 2,
				'category_id' => 11,
				'path'        => [ 'Одежда', 'Обувь', 'Ботинки' ],
			],
			[
				'category_id' => 12,
				'type_id'     => 3,
				'path'        => [ 'Закрытая' ],
				'disabled'    => true,
			],
		];
	}

	/**
	 * Lists three fields: a required string, a dictionary and a decimal.
	 *
	 * @param array<string,mixed> $remote_category Category identity.
	 * @return Field_Definition[]
	 */
	public function fields( array $remote_category ): array {
		$this->enter( 'fields' );

		return [
			new Field_Definition( '1', 'Бренд', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '2', 'Цвет', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '3', 'Вес', false, Field_Definition::TYPE_DECIMAL, false, 'kg' ),
		];
	}

	/**
	 * Searches two colours.
	 *
	 * @param Field_Definition $field Field.
	 * @param string           $term  Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public function search_dictionary( Field_Definition $field, string $term ): array {
		$this->enter( 'search_dictionary' );

		return [
			[
				'id'    => 7,
				'value' => 'красный',
				'info'  => 'тёплый',
			],
			[
				'id'    => 8,
				'value' => 'синий',
			],
		];
	}

	/**
	 * Counts a call and throws the configured failure.
	 *
	 * @param string $method Method.
	 * @return void
	 */
	private function enter( string $method ): void {
		$this->calls[ $method ] = ( $this->calls[ $method ] ?? 0 ) + 1;

		if ( null !== $this->failure ) {
			throw $this->failure;
		}
	}
}

/**
 * Wired objects over an in-memory database.
 */
final class Mappings_Harness {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	public $db;

	/**
	 * Catalogue.
	 *
	 * @var Fake_Tree_Schema
	 */
	public $schema;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	public $mappings;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	public $profiles;

	/**
	 * Mapping editor.
	 *
	 * @var Mapping_Editor
	 */
	public $editor;

	/**
	 * Profile editor.
	 *
	 * @var Profile_Editor
	 */
	public $profile_editor;

	/**
	 * Controller.
	 *
	 * @var Mappings_Controller
	 */
	public $controller;

	/**
	 * Category finder.
	 *
	 * @var Category_Finder
	 */
	public $finder;

	/**
	 * Product check.
	 *
	 * @var Mapping_Check
	 */
	public $check;

	/**
	 * Builds the objects.
	 */
	public function __construct() {
		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->schema   = new Fake_Tree_Schema();
		$this->mappings = new Wpdb_Mapping_Store( $this->db, Sqlite_Tables::table( 'mappings' ), Sqlite_Tables::table( 'mapping_terms' ) );
		$this->profiles = new Wpdb_Profile_Store( $this->db, Sqlite_Tables::table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( 'fake' ) );
		$this->finder   = new Category_Finder( $this->schema );

		$products = new Mapping_Products( [ $this->mappings, 'terms_by_mapping' ] );
		$fields   = [
			'name' => 'Название',
			'sku'  => 'Артикул',
		];
		$choices  = Profile_Choices::standard();

		$this->editor         = new Mapping_Editor( $this->mappings, $this->profiles, $this->finder, $products, $fields, Sqlite_Tables::table( 'links' ) );
		$this->profile_editor = new Profile_Editor( $this->profiles, $this->mappings, $choices, null );
		$this->check          = new Mapping_Check(
			$this->mappings,
			$this->profiles,
			new Export_Validator( [ new Category_Mapped_Rule() ] ),
			$this->finder,
			$products,
			static function ( \WC_Product $product, $mapping, $profile, array $base ): array {
				return $base;
			}
		);
		$this->controller     = new Mappings_Controller( 'fake-plugin', 'manage_woocommerce', $this->editor, $this->profile_editor, $this->check, $this->finder, $choices, null, $fields );
	}

	/**
	 * The key the finder gives the first selectable category.
	 *
	 * @return string
	 */
	public function dress_key(): string {
		return Category_Finder::key_of(
			[
				'category_id' => 10,
				'type_id'     => 1,
			]
		);
	}
}

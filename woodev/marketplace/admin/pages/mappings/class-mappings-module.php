<?php
/**
 * Wires the mappings view's REST routes for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Marketplace_Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the stores and editors of the mappings page from the plugin's adapter and registers the controller. Nothing
 * here names a marketplace: every marketplace fact comes from the {@see Marketplace_Adapter}, and the two lists the
 * adapter contract has no method for yet are filters named for the marketplace id:
 *
 * - `woodev_{id}_profile_choices` — the {@see Profile_Choices} the profile form offers;
 * - `woodev_{id}_mapping_check_context` — the validator context of one product in the mapping check.
 *
 * The page is registered by the base class, which calls {@see self::register()} once; a plugin that dropped the
 * `mappings` page from `get_marketplace_pages()` gets no routes.
 *
 * @since 2.1.0
 */
final class Mappings_Module {
	/**
	 * Slug of the page this module serves.
	 */
	public const PAGE = 'mappings';

	/**
	 * Hooks the route registration for a plugin.
	 *
	 * @since 2.1.0
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin ): void {
		add_action(
			'rest_api_init',
			static function () use ( $plugin ): void {
				$controller = self::controller( $plugin );

				if ( null !== $controller ) {
					$controller->register_routes();
				}
			}
		);
	}

	/**
	 * Builds the controller of a plugin.
	 *
	 * @since 2.1.0
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @return Mappings_Controller|null Null when the plugin has no mappings page or its id cannot be a route segment.
	 */
	public static function controller( Marketplace_Plugin $plugin ): ?Mappings_Controller {
		global $wpdb;

		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		$owner_ok = 1 === preg_match( Mappings_Controller::OWNER_PATTERN, $plugin->get_id() );

		if ( null === $menu || null === $page || ! $owner_ok ) {
			return null;
		}

		$adapter  = $plugin->get_marketplace_adapter();
		$schema   = new Schema( $adapter->id() );
		$prefix   = $wpdb->prefix;
		$mappings = new Wpdb_Mapping_Store(
			$wpdb,
			$schema->table_name( 'mappings', $prefix ),
			$schema->table_name( 'mapping_terms', $prefix )
		);
		$profiles = new Wpdb_Profile_Store(
			$wpdb,
			$schema->table_name( 'profiles', $prefix ),
			Wpdb_Profile_Store::price_changed_action( $adapter->id() )
		);
		$choices  = apply_filters( 'woodev_' . $adapter->id() . '_profile_choices', Profile_Choices::standard() );
		$choices  = $choices instanceof Profile_Choices ? $choices : Profile_Choices::standard();

		$categories = new Category_Finder( $adapter->catalog_schema() );
		$products   = new Mapping_Products( [ $mappings, 'terms_by_mapping' ] );
		$fields     = self::product_fields( $adapter );
		$links      = $schema->table_name( 'links', $prefix );
		$editor     = new Mapping_Editor( $mappings, $profiles, $categories, $products, $fields, $links );
		$validator  = new Export_Validator( $adapter->export_rules() );
		$context    = self::context_builder( $adapter );
		$check      = new Mapping_Check( $mappings, $profiles, $validator, $categories, $products, $context );

		return new Mappings_Controller(
			$plugin->get_id(),
			$page->get_capability( $menu->get_capability() ),
			$editor,
			new Profile_Editor( $profiles, $mappings, $choices, $adapter->price_source() ),
			$check,
			$categories,
			$choices,
			$adapter->price_source(),
			$fields
		);
	}

	/**
	 * Built-in product fields a `field` source may name. A subset of what the source resolver reads, with the
	 * merchant's names.
	 *
	 * @param Marketplace_Adapter $adapter The adapter.
	 * @return array<string,string>
	 */
	private static function product_fields( Marketplace_Adapter $adapter ): array {
		$fields = apply_filters(
			'woodev_' . $adapter->id() . '_mapping_product_fields',
			[
				'name'              => __( 'Название товара', 'woodev-plugin-framework' ),
				'sku'               => __( 'Артикул (SKU)', 'woodev-plugin-framework' ),
				'short_description' => __( 'Краткое описание', 'woodev-plugin-framework' ),
				'description'       => __( 'Полное описание', 'woodev-plugin-framework' ),
				'global_unique_id'  => __( 'GTIN / EAN / штрихкод', 'woodev-plugin-framework' ),
				'weight'            => __( 'Вес', 'woodev-plugin-framework' ),
				'length'            => __( 'Длина', 'woodev-plugin-framework' ),
				'width'             => __( 'Ширина', 'woodev-plugin-framework' ),
				'height'            => __( 'Высота', 'woodev-plugin-framework' ),
			]
		);

		return is_array( $fields ) ? $fields : [];
	}

	/**
	 * The validator context of one product: what the adapter knows, plus a filter for the facts only the plugin has.
	 *
	 * @param Marketplace_Adapter $adapter The adapter.
	 * @return callable
	 */
	private static function context_builder( Marketplace_Adapter $adapter ): callable {
		$filter = 'woodev_' . $adapter->id() . '_mapping_check_context';

		return static function (
			\WC_Product $product,
			Mapping $mapping,
			Profile $profile,
			array $base
		) use (
			$adapter,
			$filter
): array {
			$parent  = $product->get_parent_id() > 0 ? wc_get_product( $product->get_parent_id() ) : null;
			$context = array_merge(
				$base,
				[
					'article_policy' => $adapter->article_policy(),
					'price_source'   => $adapter->price_source(),
					'shop_currency'  => get_woocommerce_currency(),
					'parent_product' => $parent instanceof \WC_Product ? $parent : null,
				]
			);

			$filtered = apply_filters( $filter, $context, $product, $mapping, $profile );

			return is_array( $filtered ) ? $filtered : $context;
		};
	}
}

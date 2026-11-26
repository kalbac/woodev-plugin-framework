<?php
/**
 * Wires the mappings view's REST routes for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
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
	 * @param Marketplace_Plugin $plugin        The marketplace plugin.
	 * @param callable|null      $account_store Gives the plugin's account store: `function (): Account_Store`. Without it the
	 *                                          form has no article preview, which must know the accounts.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, ?callable $account_store = null ): void {
		add_action(
			'rest_api_init',
			static function () use ( $plugin, $account_store ): void {
				$controller = self::controller( $plugin, $account_store );

				if ( null !== $controller ) {
					$controller->register_routes();
				}
			}
		);
		add_action(
			'rest_api_init',
			static function () use ( $plugin ): void {
				self::register_lists( $plugin );
			},
			5 // Before the REST registrar walks its controllers (priority 10), so the routes of a table registered now exist.
		);
	}

	/**
	 * Registers the two lists of the page, «Сопоставления» and «Профили», with the records engine; does nothing when the plugin has no
	 * `mappings` page or its id cannot be a route segment.
	 *
	 * @since 2.1.13
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @return bool Whether the lists were registered.
	 */
	public static function register_lists( Marketplace_Plugin $plugin ): bool {
		$parts = self::parts( $plugin );

		if ( null === $parts || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return false;
		}

		$page_url = admin_url( 'admin.php?page=wc-admin&path=' . rawurlencode( $parts['path'] ) );
		$registry = Records_Registry::instance();
		$mappings = new Mapping_List_Provider( $page_url, $parts['mappings'], $parts['profiles'], $plugin->get_marketplace_adapter()->settings_descriptor()->marketplace_name() );
		$profiles = new Profile_List_Provider( $page_url, $parts['profiles'] );

		return $registry->register( $plugin->get_id(), $mappings, $parts['capability'] )
			&& $registry->register( $plugin->get_id(), $profiles, $parts['capability'] );
	}

	/**
	 * Builds the controller of a plugin.
	 *
	 * @since 2.1.0
	 *
	 * @param Marketplace_Plugin $plugin        The marketplace plugin.
	 * @param callable|null      $account_store Gives the plugin's account store: `function (): Account_Store`.
	 * @return Mappings_Controller|null Null when the plugin has no mappings page or its id cannot be a route segment.
	 */
	public static function controller( Marketplace_Plugin $plugin, ?callable $account_store = null ): ?Mappings_Controller {
		$parts = self::parts( $plugin );

		if ( null === $parts || 1 !== preg_match( Mappings_Controller::OWNER_PATTERN, $plugin->get_id() ) ) {
			return null;
		}

		$adapter = $plugin->get_marketplace_adapter();

		return new Mappings_Controller(
			$plugin->get_id(),
			$parts['capability'],
			$parts['mappings'],
			$parts['profiles'],
			$parts['check'],
			$parts['categories'],
			$parts['choices'],
			$adapter->price_source(),
			$parts['fields'],
			null === $account_store ? null : self::article_preview( $adapter, $account_store )
		);
	}

	/**
	 * The services both the controller and the two lists are made of: one set of stores and editors, so the lists show exactly what the
	 * forms save.
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @return array{path:string,capability:string,mappings:Mapping_Editor,profiles:Profile_Editor,check:Mapping_Check,categories:Category_Finder,choices:Profile_Choices,fields:array<string,string>}|null
	 *   Null when the plugin has no mappings page.
	 */
	private static function parts( Marketplace_Plugin $plugin ): ?array {
		global $wpdb;

		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		if ( null === $menu || null === $page ) {
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
		$validator  = new Export_Validator( $adapter->export_rules() );

		return [
			'path'       => $menu->get_page_path( $page ),
			'capability' => $page->get_capability( $menu->get_capability() ),
			'mappings'   => new Mapping_Editor( $mappings, $profiles, $categories, $products, $fields, $links ),
			'profiles'   => new Profile_Editor( $profiles, $mappings, $choices, $adapter->price_source() ),
			'check'      => new Mapping_Check( $mappings, $profiles, $validator, $categories, $products, self::context_builder( $adapter ) ),
			'categories' => $categories,
			'choices'    => $choices,
			'fields'     => $fields,
		];
	}

	/**
	 * Builds the article preview of the mapping form, which resolves a product the way the export does.
	 *
	 * @param Marketplace_Adapter $adapter       The adapter.
	 * @param callable            $account_store Gives the plugin's account store: `function (): Account_Store`.
	 * @return Mapping_Article_Preview|null Null for an adapter that does not implement {@see Article_Preview}.
	 */
	private static function article_preview( Marketplace_Adapter $adapter, callable $account_store ): ?Mapping_Article_Preview {
		global $wpdb;

		if ( ! $adapter instanceof Article_Preview ) {
			return null;
		}

		$schema = new Schema( $adapter->id() );
		$table  = static function ( string $name ) use ( $schema, $wpdb ): string {
			return $schema->table_name( $name, $wpdb->prefix );
		};

		$mappings = new Wpdb_Mapping_Store( $wpdb, $table( 'mappings' ), $table( 'mapping_terms' ) );

		return new Mapping_Article_Preview(
			$adapter,
			$adapter->export_facts(),
			$mappings,
			new Wpdb_Profile_Store( $wpdb, $table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( $adapter->id() ) ),
			new Mapping_Products( [ $mappings, 'terms_by_mapping' ] ),
			new Wpdb_Link_Store( $wpdb, $table( 'links' ), $table( 'link_ids' ) ),
			new Wpdb_Task_Ledger( $wpdb, $table( 'tasks' ), $table( 'task_items' ) ),
			new Pinned_Offer_Id( $adapter->id(), $adapter->article_policy() ),
			static function () use ( $account_store, $adapter ): array {
				return $account_store()->all( $adapter->id(), true );
			},
			$adapter instanceof Sellable_Units ? $adapter : null
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

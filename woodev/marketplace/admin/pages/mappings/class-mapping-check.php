<?php
/**
 * «Check the products of this category»: the validator over a mapping's products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the export validator over the products of one mapping and groups the first error of each product («12 products: no weight;
 * 3 products: no category mapped»). It only calls the validator; the rules live there and the rule list is the adapter's.
 *
 * The validator needs facts it cannot read from the product; the caller supplies a context builder, which keeps this class free
 * of any marketplace. The check is about the mapping, not about an account, so it carries no account-specific facts (the cabinet
 * currency, the article already held by another product): those are checked where a product is sent.
 *
 * @since 2.0.30
 */
final class Mapping_Check {
	/**
	 * Most products one request checks; the view asks again for the next batch until the mapping is done.
	 */
	public const LIMIT = 200;

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
	 * Validator.
	 *
	 * @var Export_Validator
	 */
	private $validator;

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
	 * Builds the validator context of a product: `function ( \WC_Product $product, Mapping $mapping, Profile $profile, array $base ): array`.
	 *
	 * @var callable
	 */
	private $context;

	/**
	 * Creates the check.
	 *
	 * @since 2.0.30
	 *
	 * @param Wpdb_Mapping_Store $mappings   Mappings.
	 * @param Wpdb_Profile_Store $profiles   Profiles.
	 * @param Export_Validator   $validator  Validator with the marketplace's rule list.
	 * @param Category_Finder    $categories Marketplace categories.
	 * @param Mapping_Products   $products   The products a mapping covers.
	 * @param callable           $context    Builds the validator context: `function ( \WC_Product, Mapping, Profile, array $base ): array`.
	 */
	public function __construct(
		Wpdb_Mapping_Store $mappings,
		Wpdb_Profile_Store $profiles,
		Export_Validator $validator,
		Category_Finder $categories,
		Mapping_Products $products,
		callable $context
	) {
		$this->mappings   = $mappings;
		$this->profiles   = $profiles;
		$this->validator  = $validator;
		$this->categories = $categories;
		$this->products   = $products;
		$this->context    = $context;
	}

	/**
	 * Checks one batch of the products of a mapping and adds it to what earlier batches found.
	 *
	 * @since 2.0.30
	 *
	 * @param int                                                                              $mapping_id Mapping id.
	 * @param int                                                                              $offset     Products already visited, in id order.
	 * @param array{checked:int,ok:int,groups:array<int,array{message:string,count:int}>}|null $carry      The result of the earlier batches.
	 * @return array{checked:int,ok:int,groups:array<int,array{message:string,count:int}>,total:int,next_offset:int}|null
	 *         Null when the mapping does not exist; `next_offset` is 0 once every product has been visited.
	 * @throws \Throwable When the adapter cannot give the fields of the category.
	 */
	public function run( int $mapping_id, int $offset = 0, ?array $carry = null ): ?array {
		$mapping = $this->mappings->get( $mapping_id );

		if ( null === $mapping ) {
			return null;
		}

		$profile = null !== $mapping->profile_id() ? $this->profiles->get( $mapping->profile_id() ) : null;
		$profile = $profile ?? new Profile( null, '', [] );
		$base    = $this->base_context( $mapping );
		$ids     = $this->products->ids( $mapping_id, self::LIMIT, $offset );
		$checked = null === $carry ? 0 : $carry['checked'];
		$ok      = null === $carry ? 0 : $carry['ok'];
		$groups  = self::carried_groups( $carry );

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			++$checked;
			$message = $this->first_error( $product, $mapping, $profile, $base );

			if ( null === $message ) {
				++$ok;
				continue;
			}

			$groups[ $message ] = ( $groups[ $message ] ?? 0 ) + 1;
		}

		$total = $this->products->count( $mapping_id );
		$next  = $offset + count( $ids );

		return [
			'checked'     => $checked,
			'ok'          => $ok,
			'groups'      => self::sorted_groups( $groups ),
			'total'       => $total,
			'next_offset' => count( $ids ) >= self::LIMIT && $next < $total ? $next : 0,
		];
	}

	/**
	 * The facts every product of the mapping shares: its category's fields and whether that category is still offered.
	 *
	 * @param Mapping $mapping The mapping.
	 * @return array<string,mixed>
	 */
	private function base_context( Mapping $mapping ): array {
		if ( ! $mapping->has_category() ) {
			return [ 'scope' => Export_Validator::SCOPE_FULL ];
		}

		return [
			'scope'                  => Export_Validator::SCOPE_FULL,
			'fields'                 => $this->categories->fields( $mapping->remote_category() ),
			'remote_category_exists' => null !== $this->categories->find_by_identity( $mapping->remote_category() ),
		];
	}

	/**
	 * Returns the first error message of a product, or null when it passes.
	 *
	 * @param \WC_Product         $product Product.
	 * @param Mapping             $mapping Mapping.
	 * @param Profile             $profile Profile.
	 * @param array<string,mixed> $base    Facts shared by the mapping's products.
	 * @return string|null
	 */
	private function first_error( \WC_Product $product, Mapping $mapping, Profile $profile, array $base ): ?string {
		$context = (array) call_user_func( $this->context, $product, $mapping, $profile, $base );
		$errors  = $this->validator->validate( $product, $mapping, $profile, $context )->errors();

		return [] === $errors ? null : (string) $errors[0]['message'];
	}

	/**
	 * Turns the groups of the earlier batches back into `message => count`.
	 *
	 * @param array{groups:array<int,array{message:string,count:int}>}|null $carry Earlier result.
	 * @return array<string,int>
	 */
	private static function carried_groups( ?array $carry ): array {
		$groups = [];

		foreach ( null === $carry ? [] : $carry['groups'] as $group ) {
			$groups[ $group['message'] ] = $group['count'];
		}

		return $groups;
	}

	/**
	 * Lists the groups, the most frequent first.
	 *
	 * @param array<string,int> $groups Count per message.
	 * @return array<int,array{message:string,count:int}>
	 */
	private static function sorted_groups( array $groups ): array {
		arsort( $groups );

		$sorted = [];
		foreach ( $groups as $message => $count ) {
			$sorted[] = [
				'message' => (string) $message,
				'count'   => $count,
			];
		}

		return $sorted;
	}
}

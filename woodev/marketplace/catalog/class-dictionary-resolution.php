<?php
/**
 * Dictionary value ids a source gives for a product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The single reading of «which dictionary values will be sent for this field»: a chosen id on the source (any source kind) wins;
 * otherwise each label the source gives goes through the context's local lookup. The dictionary rule reports the labels no id
 * was found for; the variation rule compares the ids, so both always agree.
 *
 * @since 2.0.29
 */
final class Dictionary_Resolution {
	/**
	 * Resolves a dictionary field for a product.
	 *
	 * @since 2.0.29
	 *
	 * @param Source             $source  Source of the field.
	 * @param Field_Definition   $field   The dictionary field.
	 * @param \WC_Product        $product Product or variation.
	 * @param Validation_Context $context Context (lookup and parent).
	 * @return array{ids:string[],unresolved:string[]} Sorted unique ids, and the labels with no id.
	 */
	public static function resolve( Source $source, Field_Definition $field, \WC_Product $product, Validation_Context $context ): array {
		if ( null !== $source->dictionary_value_id() ) {
			return [
				'ids'        => [ $source->dictionary_value_id() ],
				'unresolved' => [],
			];
		}

		$lookup     = $context->dictionary_lookup();
		$ids        = [];
		$unresolved = [];

		foreach ( Source_Resolver::values( $source, $product, $context->parent_product() ) as $label ) {
			$id = null === $lookup ? null : call_user_func( $lookup, $field, $label );

			if ( null === $id || '' === (string) $id ) {
				$unresolved[] = $label;
			} else {
				$ids[] = (string) $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return [
			'ids'        => $ids,
			'unresolved' => $unresolved,
		];
	}
}

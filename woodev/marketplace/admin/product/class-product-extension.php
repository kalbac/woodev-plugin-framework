<?php
/**
 * The product-tab side of a {@see Settings_Extension}.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Extension_Field;
use Woodev\Framework\Marketplace\Settings\Extension_Group;
use Woodev\Framework\Marketplace\Settings\Settings_Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the adapter's product fields to {@see Panel_View} with the values a product holds, and saves what the form posted.
 *
 * - **Product meta, not the link row.** The values belong to the product: a product linked to two accounts has two link rows, and a
 *   setting the merchant reads as «for this product» must not have two answers. The meta name is
 *   {@see Extension_Field::product_meta_key()}; a product with no meta follows «Общие» ({@see Effective_Settings::product_extra()}).
 * - **Selects only.** The empty option stores nothing (the meta is removed), which is how a product goes back to «Общие».
 * - **A variation has the same fields.** Its empty option follows the parent product, then «Общие»
 *   ({@see Effective_Settings::product_extra()}); the posted name carries the loop, `woodev_{id}_ext_variation[LOOP][field id]`.
 * - **The same nonce and capability as the rest of the tab**: the caller ({@see Product_Tab}) checks them before calling
 *   {@see self::save()}. Nothing here calls the marketplace.
 *
 * Posted names are `woodev_{id}_ext[{field id}]` ({@see Screen_Names::extension_key()}), a key of its own, so the per-account
 * blocks of `woodev_{id}[ACCOUNT][...]` never see them.
 *
 * @since 2.1.7
 */
final class Product_Extension {
	/**
	 * The adapter's extension.
	 *
	 * @var Settings_Extension
	 */
	private $extension;

	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * Creates the helper.
	 *
	 * @since 2.1.7
	 *
	 * @param Settings_Extension $extension The adapter's extension.
	 * @param Screen_Names       $names     Names.
	 */
	public function __construct( Settings_Extension $extension, Screen_Names $names ) {
		$this->extension = $extension;
		$this->names     = $names;
	}

	/**
	 * What {@see Panel_View::render_tab()} needs under its `extension` key.
	 *
	 * @since 2.1.7
	 *
	 * @param \WC_Product $product Product being edited, or a variation.
	 * @param int|null    $loop    Position of the variation in the form; null for the product tab.
	 * @return array{groups:Extension_Group[],values:array<string,string>,base:string,id:string}
	 */
	public function screen( \WC_Product $product, ?int $loop = null ): array {
		$values = [];

		foreach ( $this->fields() as $field ) {
			$stored                  = $product->get_meta( Extension_Field::product_meta_key( $this->names->id(), $field->id() ), true );
			$values[ $field->id() ] = is_string( $stored ) && $field->accepts( $stored ) ? $stored : '';
		}

		return [
			'groups' => $this->groups(),
			'values' => $values,
			'base'   => null === $loop ? $this->names->extension_key() : $this->names->extension_variation_key() . '[' . $loop . ']',
			'id'     => $this->names->tab_key() . '_ext' . ( null === $loop ? '' : '_v' . $loop ),
		];
	}

	/**
	 * The groups of the adapter that have a select to draw.
	 *
	 * @since 2.1.7
	 *
	 * @return Extension_Group[]
	 */
	public function groups(): array {
		$groups = [];

		foreach ( $this->extension->product_groups() as $group ) {
			$selects = $this->selects( $group->fields() );

			if ( [] !== $selects ) {
				$groups[] = new Extension_Group( $group->id(), $group->title(), $selects );
			}
		}

		return $groups;
	}

	/**
	 * Saves the posted values; nothing is written when one of them is not an option of its select.
	 *
	 * A field the form did not post keeps its value (a form of another version, a partial post).
	 *
	 * @since 2.1.7
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $posted  The posted `woodev_{id}_ext` array.
	 * @return string[] Sentences for what could not be saved; empty when everything was.
	 */
	public function save( \WC_Product $product, array $posted ): array {
		$accepted = [];
		$errors   = [];

		foreach ( $this->fields() as $field ) {
			if ( ! array_key_exists( $field->id(), $posted ) ) {
				continue;
			}

			if ( $field->accepts( $posted[ $field->id() ] ) ) {
				$accepted[ $field->id() ] = (string) $posted[ $field->id() ];
			} else {
				$errors[] = sprintf( /* translators: %s: name of the field. */ __( '«%s»: выберите значение из списка.', 'woodev-plugin-framework' ), $field->label() );
			}
		}

		if ( [] !== $errors || [] === $accepted ) {
			return $errors;
		}

		foreach ( $accepted as $id => $value ) {
			$key = Extension_Field::product_meta_key( $this->names->id(), $id );

			if ( '' === $value ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $value );
			}
		}

		$product->save_meta_data();

		return [];
	}

	/**
	 * Every select the adapter draws, by id.
	 *
	 * @return array<string,Extension_Field>
	 */
	private function fields(): array {
		$fields = [];

		foreach ( $this->groups() as $group ) {
			foreach ( $group->fields() as $field ) {
				$fields[ $field->id() ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Keeps the selects of a list.
	 *
	 * @param Extension_Field[] $fields Fields.
	 * @return Extension_Field[]
	 */
	private function selects( array $fields ): array {
		return array_values(
			array_filter(
				$fields,
				static function ( Extension_Field $field ): bool {
					return Extension_Field::TYPE_SELECT === $field->type();
				}
			)
		);
	}
}

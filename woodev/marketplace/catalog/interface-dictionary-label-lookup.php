<?php
/**
 * Local dictionary lookup contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: an adapter's {@see \Woodev\Framework\Marketplace\Flows\Export_Facts} that implements it can turn the text of a product
 * attribute («красный») into the dictionary value id the marketplace wants for a dictionary field, from data the plugin already
 * holds (the values of the cards it imported, its catalogue cache) — never with a request.
 *
 * The export context builder hands it to the validator and the card builder as `dictionary_lookup` (unless the adapter's own facts
 * already carry one), and the card import links a dictionary characteristic to its product attribute **only** when this contract is
 * there: a linked dictionary field whose text nobody can resolve is an export error ({@see Rules\Dictionary_Values_Rule}), worse than
 * the field being left unlinked.
 *
 * @since 2.1.15
 */
interface Dictionary_Label_Lookup {
	/**
	 * Returns the dictionary value id of a label.
	 *
	 * @since 2.1.15
	 *
	 * @param Field_Definition $field The dictionary field.
	 * @param string           $label The text a product gives for it.
	 * @return string|null Null when the label is not known locally.
	 */
	public function dictionary_value_id( Field_Definition $field, string $label ): ?string;
}

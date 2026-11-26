<?php
/**
 * Whether a label can be asked for a posting.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule behind the «Этикетка» button of an order and the `label` action of the «Заказы» table: a posting the marketplace has
 * finished with (delivered or cancelled, `is_terminal`) needs no label, and one the adapter says is not ready ({@see Label_Readiness})
 * cannot have it made yet. Both are decided from what the postings registry stores, so neither costs a call to the marketplace.
 *
 * @since 2.1.13
 */
final class Label_Availability {
	/**
	 * The adapter's readiness, when it has one.
	 *
	 * @var Label_Readiness|null
	 */
	private $readiness;

	/**
	 * The marketplace's word for a posting.
	 *
	 * @var Posting_Noun
	 */
	private $noun;

	/**
	 * Creates the rule.
	 *
	 * @since 2.1.13
	 * @since 2.1.23 Takes the posting noun.
	 *
	 * @param Label_Readiness|null $readiness The adapter's readiness; null for an adapter that has none.
	 * @param Posting_Noun|null    $noun      The marketplace's word for a posting; null for «отправление».
	 */
	public function __construct( ?Label_Readiness $readiness, ?Posting_Noun $noun = null ) {
		$this->readiness = $readiness;
		$this->noun      = $noun ?? Posting_Noun::shipment();
	}

	/**
	 * Tells why a label cannot be asked for a posting.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status      The marketplace's status of the posting, as stored.
	 * @param bool   $is_terminal Whether the marketplace has finished with the posting.
	 * @return string The sentence for the merchant; empty when the label can be asked for.
	 */
	public function reason( string $status, bool $is_terminal ): string {
		if ( $is_terminal ) {
			/* translators: %s: the marketplace's word for a posting, capitalised: «Отправление». */
			return sprintf( __( '%s завершено — этикетка не нужна.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->noun->one() ) );
		}

		return null === $this->readiness ? '' : trim( (string) $this->readiness->label_readiness( $status ) );
	}
}

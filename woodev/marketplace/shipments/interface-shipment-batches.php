<?php
/**
 * Marketplace domain — contract of the seller's shipments.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter: the seller gathers goods into shipments and hands each shipment over to the
 * marketplace. A marketplace that reports {@see \Woodev\Framework\Marketplace\Capabilities\Capability::CONFIRM_SHIPMENT} and
 * implements this gets the page of shipments and «add to a shipment» on its orders; any other gets neither. The words the
 * screens use come from the settings descriptor ({@see \Woodev\Framework\Marketplace\Settings\Shipment_Wording}).
 *
 * The screens never read the marketplace: a job keeps a copy of the shipments in the shop
 * ({@see self::list_shipments()}, {@see self::read_shipment()}, {@see self::units_to_reship()}), and an action reads the one
 * shipment it touched. The methods grouped under «From stored data» are asked while a screen is drawn, so they must not call
 * the marketplace.
 *
 * A refusal the marketplace gives for a whole request is thrown as {@see Shipment_Refused} with the sentence for the merchant;
 * any other failure is a technical one, which the adapter's error classifier reads.
 *
 * @since 2.1.28
 */
interface Shipment_Batches {

	// Reading the marketplace.

	/**
	 * Reads one page of the account's shipments, open and handed over.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $cursor  Cursor of the previous page; empty for the first page.
	 * @return Shipment_Listing
	 */
	public function list_shipments( Account $account, string $cursor ): Shipment_Listing;

	/**
	 * Reads one shipment with its units, what is missing on each, and its cargo places.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account  Account.
	 * @param Shipment $shipment The shipment as the shop knows it.
	 * @return Shipment_Contents The shipment as it is now.
	 */
	public function read_shipment( Account $account, Shipment $shipment ): Shipment_Contents;

	/**
	 * Lists the units the marketplace says must be shipped again.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return array<string,string[]> Shipment id => ids of its units; empty when there are none or the marketplace has no such list.
	 */
	public function units_to_reship( Account $account ): array;

	// From stored data.

	/**
	 * Lists the units of orders that a shipment could take, each with the reason when it cannot.
	 *
	 * A rule about the whole selection — one shipment holds one kind of cargo — is answered here: refuse the units that break
	 * it, so that nothing is sent for a selection the marketplace would turn down.
	 *
	 * @since 2.1.28
	 *
	 * @param Account       $account Account of the orders.
	 * @param \WC_Order[]   $orders  Orders of the account.
	 * @param Shipment|null $target  The open shipment they would join; null for a new one.
	 * @return Shipment_Candidate[] Empty when the orders have no unit of the marketplace's.
	 */
	public function shipment_candidates( Account $account, array $orders, ?Shipment $target ): array;

	/**
	 * Tells why a unit cannot be moved from one shipment into another.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment      $from Shipment the unit is in.
	 * @param Shipment      $to   Open shipment it would join.
	 * @param Shipment_Unit $unit A unit the marketplace lets be moved ({@see Shipment_Unit::is_movable()}).
	 * @return string The sentence for the merchant, in Russian; empty when it can be moved.
	 */
	public function move_refusal( Shipment $from, Shipment $to, Shipment_Unit $unit ): string;

	/**
	 * Declares the fields of the dialog that chooses where and when a shipment is handed over (a pickup point, a date). A list
	 * field with an options source is filled by {@see self::shipping_options()}.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return Seller_Action_Field[] Empty when there is nothing to choose.
	 */
	public function shipping_fields( Account $account ): array;

	/**
	 * Tells why the marketplace would not take an open shipment now, beyond what the screens already check (it is open, it has
	 * units, nothing required is missing): for example no pickup point has been chosen.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment          $shipment The shipment.
	 * @param Shipment_Contents $contents Its units and cargo places as the shop knows them.
	 * @return string The sentence for the merchant, in Russian; empty when it can be handed over.
	 */
	public function hand_over_refusal( Shipment $shipment, Shipment_Contents $contents ): string;

	/**
	 * Tells how many cargo places one request may add.
	 *
	 * @since 2.1.28
	 *
	 * @return int 0 for a marketplace without cargo places.
	 */
	public function max_cargo_places(): int;

	/**
	 * Tells why no cargo place can be added to a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment          $shipment The shipment.
	 * @param Shipment_Contents $contents Its units and cargo places as the shop knows them.
	 * @return string The sentence for the merchant, in Russian; empty when places can be added.
	 */
	public function cargo_place_refusal( Shipment $shipment, Shipment_Contents $contents ): string;

	/**
	 * Declares the documents a shipment may have (its QR code, the stickers of its cargo places).
	 *
	 * @since 2.1.28
	 *
	 * @return Shipment_Document_Type[] At most one per id.
	 */
	public function shipment_documents(): array;

	/**
	 * Tells why a document of a shipment cannot be had now.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment          $shipment    The shipment.
	 * @param Shipment_Contents $contents    Its units and cargo places as the shop knows them.
	 * @param string            $document_id Id of a declared document.
	 * @return string The sentence for the merchant, in Russian; empty when it can be downloaded.
	 */
	public function document_refusal( Shipment $shipment, Shipment_Contents $contents, string $document_id ): string;

	// Changing the marketplace.

	/**
	 * Creates an empty shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $name    Name the merchant gave it.
	 * @return Shipment The shipment the marketplace made.
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function create_shipment( Account $account, string $name ): Shipment;

	/**
	 * Adds units to an open shipment. One call carries every unit of the request, so the adapter batches them within the
	 * marketplace's limits. A unit the answer does not mention is reported as unanswered, never as added.
	 *
	 * @since 2.1.28
	 *
	 * @param Account              $account    Account.
	 * @param Shipment             $shipment   The shipment.
	 * @param Shipment_Candidate[] $candidates Units to add, none of them refused.
	 * @return Shipment_Result[]
	 * @throws Shipment_Refused When nothing at all was done.
	 */
	public function add_units( Account $account, Shipment $shipment, array $candidates ): array;

	/**
	 * Moves units out of one shipment into an open one. A unit the answer does not mention is reported as unanswered.
	 *
	 * @since 2.1.28
	 *
	 * @param Account         $account Account.
	 * @param Shipment        $from    Shipment they are in.
	 * @param Shipment        $to      Open shipment they join.
	 * @param Shipment_Unit[] $units   Units to move.
	 * @return Shipment_Result[]
	 * @throws Shipment_Refused When nothing at all was done.
	 */
	public function move_units( Account $account, Shipment $from, Shipment $to, array $units ): array;

	/**
	 * Reads the choices of one list field of {@see self::shipping_fields()} for a shipment. Called when the merchant opens the
	 * list, never while a table is read.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account  Account.
	 * @param Shipment $shipment The shipment.
	 * @param string   $field_id Field id.
	 * @param string   $search   What the merchant typed; empty for everything.
	 * @return array<string,string> Value => label. A value holds letters, digits, `_`, `.`, `:` and `-` only.
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function shipping_options( Account $account, Shipment $shipment, string $field_id, string $search ): array;

	/**
	 * Sets where and when a shipment is handed over.
	 *
	 * @since 2.1.28
	 *
	 * @param Account              $account  Account.
	 * @param Shipment             $shipment The open shipment.
	 * @param array<string,string> $values   Values by field id.
	 * @return void
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function choose_shipping( Account $account, Shipment $shipment, array $values ): void;

	/**
	 * Adds cargo places to an open shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account  Account.
	 * @param Shipment $shipment The shipment.
	 * @param int      $count    Between 1 and {@see self::max_cargo_places()}.
	 * @return void
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function add_cargo_places( Account $account, Shipment $shipment, int $count ): void;

	/**
	 * Hands an open shipment over to the marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account  Account.
	 * @param Shipment $shipment The shipment.
	 * @return void
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function hand_over( Account $account, Shipment $shipment ): void;

	/**
	 * Reads a document of a shipment. Called when the merchant downloads it.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account     Account.
	 * @param Shipment $shipment    The shipment.
	 * @param string   $document_id Id of a declared document.
	 * @return Shipment_File
	 * @throws Shipment_Refused When the marketplace refuses.
	 */
	public function shipment_document( Account $account, Shipment $shipment, string $document_id ): Shipment_File;
}

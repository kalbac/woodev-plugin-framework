<?php
/**
 * Writing the link of an existing marketplace card.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Store the identifiers and the state the marketplace reports; never overwrite a link; never touch a row an export holds; the
 * synchronisation switches stay off.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Linker
 */
final class CardLinkerTest extends Sync_Test_Case {
	/**
	 * Linker.
	 *
	 * @var Card_Linker
	 */
	private $linker;

	/**
	 * Builds the linker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->linker = new Card_Linker( $this->links() );
	}

	/**
	 * Builds a card as a resolver returns it.
	 *
	 * @param array<string,mixed> $overrides Replaced values.
	 * @return array<string,mixed>
	 */
	private function card( array $overrides = [] ): array {
		return array_merge(
			[
				'offer_id'    => 'NB-TEST-3',
				'remote_ids'  => [
					'card_ref'  => 6499406292,
					'model_ref' => 6510847990,
				],
				'state'       => Link_Record::STATE_CREATED,
				'is_created'  => true,
				'failed'      => false,
				'status_text' => '',
			],
			$overrides
		);
	}

	/**
	 * A fence the account does not hold.
	 *
	 * @return Claim_Fence
	 */
	private function stale_fence(): Claim_Fence {
		return new Claim_Fence( Sqlite_Tables::table( 'accounts' ), 'a-token-another-run-replaced' );
	}

	/**
	 * Counts the writes that went to the database.
	 *
	 * @return string[]
	 */
	private function writes(): array {
		return array_values(
			array_filter(
				$this->db->statements,
				static function ( string $sql ): bool {
					return 1 === preg_match( '/^(INSERT|UPDATE)/', $sql );
				}
			)
		);
	}

	/**
	 * A product with no row gets one with the marketplace's identifiers and state, and with both synchronisation switches off.
	 *
	 * @return void
	 */
	public function test_a_new_row_carries_the_identifiers_and_state(): void {
		$this->assertSame( Card_Linker::LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card() ) );

		$row = $this->link_of( 101 );

		$this->assertSame( 'NB-TEST-3', $row->offer_id() );
		$this->assertSame( 6499406292, $row->remote_id( 'card_ref' ) );
		$this->assertSame( 6510847990, $row->remote_id( 'model_ref' ) );
		$this->assertSame( Link_Record::STATE_CREATED, $row->card_state() );
		$this->assertFalse( $row->sync_stock() );
		$this->assertFalse( $row->sync_price() );
	}

	/**
	 * A variation keeps its parent id on the row.
	 *
	 * @return void
	 */
	public function test_a_variation_keeps_its_parent(): void {
		$this->linker->link( $this->account_id, 202, 200, $this->card() );

		$this->assertSame( 200, $this->link_of( 202 )->wc_parent_id() );
	}

	/**
	 * A declined card is linked in state `failed` and keeps the marketplace's text.
	 *
	 * @return void
	 */
	public function test_a_declined_card_keeps_the_marketplaces_text(): void {
		$this->linker->link(
			$this->account_id,
			101,
			0,
			$this->card(
				[
					'state'       => Link_Record::STATE_FAILED,
					'is_created'  => false,
					'failed'      => true,
					'status_text' => 'Не продаётся / declined',
				]
			)
		);

		$row = $this->link_of( 101 );

		$this->assertSame( Link_Record::STATE_FAILED, $row->card_state() );
		$this->assertSame( 'Не продаётся / declined', $this->links()->last_error( (int) $row->link_id() )['text'] );
	}

	/**
	 * An idle row (reset or never exported) is filled in, not duplicated.
	 *
	 * @return void
	 */
	public function test_an_idle_row_is_adopted(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );
		$id = (int) $this->link_of( 101 )->link_id();

		$this->assertSame( Card_Linker::LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card() ) );

		$row = $this->link_of( 101 );

		$this->assertSame( $id, $row->link_id() );
		$this->assertSame( 6499406292, $row->remote_id( 'card_ref' ) );
		$this->assertSame( 'NB-TEST-3', $row->offer_id() );
		$this->assertSame( 1, $this->links()->count( $this->account_id ) );
	}

	/**
	 * A card's oversize flag (Ozon «КГТ») reaches the new row.
	 *
	 * @return void
	 */
	public function test_the_oversize_flag_of_a_new_link_is_written(): void {
		$this->linker->link( $this->account_id, 101, 0, $this->card( [ 'oversize' => true ] ) );

		$this->assertTrue( $this->link_of( 101 )->is_oversize() );
	}

	/**
	 * An idle row adopts the card's oversize flag too.
	 *
	 * @return void
	 */
	public function test_the_oversize_flag_of_an_adopted_row_is_written(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );

		$this->linker->link( $this->account_id, 101, 0, $this->card( [ 'oversize' => true ] ) );

		$this->assertTrue( $this->link_of( 101 )->is_oversize() );
	}

	/**
	 * A card without the `oversize` key leaves the stored flag alone: it is never reset to false.
	 *
	 * @return void
	 */
	public function test_an_absent_oversize_key_keeps_the_stored_flag(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '', [ 'is_oversize' => 1 ] ) );

		$this->linker->link( $this->account_id, 101, 0, $this->card() );

		$this->assertTrue( $this->link_of( 101 )->is_oversize() );
	}

	/**
	 * A product that already has an article keeps its link, whatever the new card is.
	 *
	 * @return void
	 */
	public function test_an_existing_link_is_never_rewritten(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, 'OTHER-1', [ 'card_state' => Link_Record::STATE_QUEUED ] ) );

		$this->assertSame( Card_Linker::PRODUCT_LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card() ) );

		$row = $this->link_of( 101 );

		$this->assertSame( 'OTHER-1', $row->offer_id() );
		$this->assertSame( Link_Record::STATE_QUEUED, $row->card_state() );
		$this->assertSame( [], $row->remote_ids() );
	}

	/**
	 * A product that has a marketplace identifier but lost its article keeps its link too.
	 *
	 * @return void
	 */
	public function test_a_product_with_a_marketplace_identifier_is_not_relinked(): void {
		$this->linker->link( $this->account_id, 101, 0, $this->card() );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . ' SET offer_id = NULL WHERE wc_product_id = 101' );

		$this->assertSame( Card_Linker::PRODUCT_LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card( [ 'offer_id' => 'NB-OTHER' ] ) ) );
	}

	/**
	 * A card whose article another product holds is not given to a second product.
	 *
	 * @return void
	 */
	public function test_a_card_is_never_given_to_a_second_product(): void {
		$this->linker->link( $this->account_id, 101, 0, $this->card() );

		$this->assertSame( Card_Linker::CARD_LINKED, $this->linker->link( $this->account_id, 102, 0, $this->card() ) );
		$this->assertNull( $this->links()->find( $this->account_id, 102 ) );
		$this->assertSame( Card_Linker::PRODUCT_LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card() ) );
	}

	/**
	 * A row an export holds (a task id on it) is left alone and the caller is told.
	 *
	 * @return void
	 */
	public function test_a_row_an_export_holds_is_busy(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . ' SET last_task_id = 5 WHERE wc_product_id = 101' );

		$this->assertSame( Card_Linker::BUSY, $this->linker->link( $this->account_id, 101, 0, $this->card() ) );
		$this->assertSame( [], $this->link_of( 101 )->remote_ids() );
	}

	/**
	 * The configuration row of a variable product is not a card.
	 *
	 * @return void
	 */
	public function test_a_parent_row_is_not_a_card(): void {
		$this->links()->insert_if_absent( Link_Record::parent_row( $this->account_id, 200 ) );

		$this->assertSame( Card_Linker::NOT_A_CARD, $this->linker->link( $this->account_id, 200, 0, $this->card() ) );
	}

	/**
	 * An empty article or a card without a marketplace identifier is refused before the database is read.
	 *
	 * @return void
	 */
	public function test_a_card_without_identity_is_refused(): void {
		$this->assertSame( Card_Linker::NOT_A_CARD, $this->linker->link( $this->account_id, 101, 0, $this->card( [ 'offer_id' => '' ] ) ) );
		$this->assertSame( Card_Linker::NOT_A_CARD, $this->linker->link( $this->account_id, 101, 0, $this->card( [ 'remote_ids' => [] ] ) ) );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
	}

	/**
	 * The identity map lists what each offer row says, for the matcher.
	 *
	 * @return void
	 */
	public function test_the_identity_map_lists_articles_and_identifiers(): void {
		$this->linker->link( $this->account_id, 101, 0, $this->card() );
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 102, 0, '' ) );
		$this->links()->insert_if_absent( Link_Record::parent_row( $this->account_id, 200 ) );

		$this->assertSame(
			[
				101 => [
					'offer_id'       => 'NB-TEST-3',
					'has_remote_ids' => true,
					'card_state'     => Link_Record::STATE_CREATED,
				],
				102 => [
					'offer_id'       => null,
					'has_remote_ids' => false,
					'card_state'     => Link_Record::STATE_UNLINKED,
				],
			],
			$this->links()->identity_map( $this->account_id )
		);
		$this->assertSame( [], $this->links()->identity_map( 99 ) );
	}

	/**
	 * A run whose account claim is not held inserts no row — the claim is a condition of the INSERT itself.
	 *
	 * @return void
	 */
	public function test_a_run_without_the_claim_inserts_nothing(): void {
		$this->assertSame( Card_Linker::CLAIM_LOST, $this->linker->link( $this->account_id, 101, 0, $this->card(), $this->stale_fence() ) );
		$this->assertNull( $this->links()->find( $this->account_id, 101 ) );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
	}

	/**
	 * A run that holds the claim writes.
	 *
	 * @return void
	 */
	public function test_a_run_with_the_claim_links(): void {
		$fence = $this->job_gate()->claim( $this->account_id );

		$this->assertNotNull( $fence );
		$this->assertSame( Card_Linker::LINKED, $this->linker->link( $this->account_id, 101, 0, $this->card(), $fence ) );
	}

	/**
	 * A lost claim at the adoption of an idle row is reported as such, not as a busy row.
	 *
	 * @return void
	 */
	public function test_an_idle_row_is_not_adopted_without_the_claim(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );

		$this->assertSame( Card_Linker::CLAIM_LOST, $this->linker->link( $this->account_id, 101, 0, $this->card(), $this->stale_fence() ) );
		$this->assertSame( [], $this->link_of( 101 )->remote_ids() );
	}

	/**
	 * The same for a declined card: nothing is inserted and no state or text is written by a second statement.
	 *
	 * @return void
	 */
	public function test_a_declined_card_without_the_claim_writes_nothing(): void {
		$card = $this->card(
			[
				'state'       => Link_Record::STATE_FAILED,
				'is_created'  => false,
				'failed'      => true,
				'status_text' => 'declined',
			]
		);
		$before = count( $this->writes() );

		$this->assertSame( Card_Linker::CLAIM_LOST, $this->linker->link( $this->account_id, 101, 0, $card, $this->stale_fence() ) );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertCount( $before + 1, $this->writes(), 'one fenced statement, no unfenced follow-up' );
	}

	/**
	 * The marketplace's text of a declined card is written by the insert itself, not by a second statement.
	 *
	 * @return void
	 */
	public function test_a_declined_card_text_is_part_of_the_insert(): void {
		$before = count( $this->writes() );

		$this->linker->link(
			$this->account_id,
			101,
			0,
			$this->card(
				[
					'state'       => Link_Record::STATE_FAILED,
					'is_created'  => false,
					'failed'      => true,
					'status_text' => 'declined',
				]
			)
		);

		$writes = array_slice( $this->writes(), $before );

		$this->assertGreaterThanOrEqual( 1, count( $writes ) );
		$this->assertStringContainsString( 'declined', $writes[0] );
		$this->assertSame( 'declined', $this->links()->last_error( (int) $this->link_of( 101 )->link_id() )['text'] );
	}

	/**
	 * Every outcome has a sentence for the merchant, and none names a marketplace.
	 *
	 * @return void
	 */
	public function test_every_outcome_has_a_message(): void {
		$outcomes = [ Card_Linker::LINKED, Card_Linker::PRODUCT_LINKED, Card_Linker::CARD_LINKED, Card_Linker::NOT_A_CARD, Card_Linker::BUSY ];
		$texts    = array_map( [ Card_Linker::class, 'message' ], $outcomes );

		$this->assertSame( $texts, array_unique( $texts ) );
		$this->assertSame( Card_Linker::message( Card_Linker::BUSY ), Card_Linker::message( Card_Linker::CLAIM_LOST ) );
	}
	// Synchronisation on link (#1793) -------------------------------------------------------------------------------------

	/**
	 * Builds a linker that follows a «sync on link» decision and records its first sends into the two lists.
	 *
	 * @param bool                      $on     What the decision answers.
	 * @param array<int,Link_Record>    $stock  Collects the links of the first stock sends.
	 * @param array<int,Link_Record>    $price  Collects the links of the first price sends.
	 * @return Card_Linker
	 */
	private function syncing_linker( bool $on, array &$stock, array &$price ): Card_Linker {
		return new Card_Linker(
			$this->links(),
			static function () use ( $on ): bool {
				return $on;
			},
			static function ( Link_Record $link ) use ( &$stock ): void {
				$stock[] = $link;
			},
			static function ( int $account_id, Link_Record $link ) use ( &$price ): void {
				$price[] = $link;
			}
		);
	}

	/**
	 * With the setting on a new row is written with both switches on and the first send is recorded for its fresh row.
	 *
	 * @return void
	 */
	public function test_a_new_row_is_linked_with_sync_on_and_the_first_send_is_recorded(): void {
		$stock = [];
		$price = [];

		$this->assertSame( Card_Linker::LINKED, $this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card() ) );

		$row = $this->link_of( 101 );

		$this->assertTrue( $row->sync_stock() );
		$this->assertTrue( $row->sync_price() );
		$this->assertCount( 1, $stock );
		$this->assertCount( 1, $price );
		$this->assertSame( $row->link_id(), $stock[0]->link_id(), 'the first send reads the row the link wrote' );
		$this->assertTrue( $stock[0]->sync_stock(), 'the recorder sees the switch already on' );
	}

	/**
	 * With the setting off both switches stay off and nothing is recorded, as before.
	 *
	 * @return void
	 */
	public function test_with_the_setting_off_a_new_row_keeps_both_switches_off_and_records_nothing(): void {
		$stock = [];
		$price = [];

		$this->assertSame( Card_Linker::LINKED, $this->syncing_linker( false, $stock, $price )->link( $this->account_id, 101, 0, $this->card() ) );

		$this->assertFalse( $this->link_of( 101 )->sync_stock() );
		$this->assertFalse( $this->link_of( 101 )->sync_price() );
		$this->assertSame( [], $stock );
		$this->assertSame( [], $price );
	}

	/**
	 * Adopting an identity-less row writes the switches with the card; with the setting off the row's own switches stay.
	 *
	 * @return void
	 */
	public function test_adoption_writes_the_switches_only_with_the_setting_on(): void {
		$stock = [];
		$price = [];
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 102, 0, '', [ 'sync_stock' => 1 ] ) );

		$this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card() );
		$this->syncing_linker( false, $stock, $price )->link( $this->account_id, 102, 0, $this->card( [ 'offer_id' => 'NB-TEST-4', 'remote_ids' => [ 'card_ref' => 77 ] ] ) );

		$this->assertTrue( $this->link_of( 101 )->sync_stock() );
		$this->assertTrue( $this->link_of( 101 )->sync_price() );
		$this->assertTrue( $this->link_of( 102 )->sync_stock(), 'off leaves what the row had' );
		$this->assertFalse( $this->link_of( 102 )->sync_price() );
		$this->assertCount( 1, $stock, 'only the adoption with the setting on recorded a first send' );
		$this->assertSame( $this->link_of( 101 )->link_id(), $stock[0]->link_id() );
	}

	/**
	 * A product the importer made from the card gets only the first stock send: its price came from the card.
	 *
	 * @return void
	 */
	public function test_the_price_can_be_kept_out_of_the_first_send(): void {
		$stock = [];
		$price = [];

		$this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card(), null, false );

		$this->assertCount( 1, $stock );
		$this->assertSame( [], $price );
		$this->assertTrue( $this->link_of( 101 )->sync_price(), 'the switch itself is still on' );
	}

	/**
	 * A card that is not created yet is linked with the switches on but sends nothing: its creation seeds the first values.
	 *
	 * @return void
	 */
	public function test_a_card_that_is_not_created_records_no_first_send(): void {
		$stock = [];
		$price = [];

		$this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card( [ 'is_created' => false, 'state' => Link_Record::STATE_QUEUED ] ) );

		$this->assertTrue( $this->link_of( 101 )->sync_stock() );
		$this->assertSame( [], $stock );
		$this->assertSame( [], $price );
	}

	/**
	 * A link that was refused (product or card already linked) records nothing.
	 *
	 * @return void
	 */
	public function test_a_refused_link_records_no_first_send(): void {
		$stock = [];
		$price = [];
		$this->linker->link( $this->account_id, 101, 0, $this->card() );

		$this->assertSame( Card_Linker::PRODUCT_LINKED, $this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card() ) );
		$this->assertSame( Card_Linker::CARD_LINKED, $this->syncing_linker( true, $stock, $price )->link( $this->account_id, 102, 0, $this->card() ) );

		$this->assertSame( [], $stock );
		$this->assertSame( [], $price );
	}

	/**
	 * A claim that was taken over writes nothing and records nothing.
	 *
	 * @return void
	 */
	public function test_a_lost_claim_records_no_first_send(): void {
		$stock = [];
		$price = [];

		$this->assertSame( Card_Linker::CLAIM_LOST, $this->syncing_linker( true, $stock, $price )->link( $this->account_id, 101, 0, $this->card(), $this->stale_fence() ) );
		$this->assertSame( [], $stock );
	}

	/**
	 * The plugin's linker follows the account's own «sync on link» value; an account that never saved it reads on.
	 *
	 * @return void
	 */
	public function test_the_plugin_linker_follows_the_accounts_setting(): void {
		$stock_intents = $this->hook_stock();
		$linker        = Card_Linker::syncing( $this->links(), Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID ), [ $this->accounts(), 'get' ], $stock_intents, null );
		$managed       = $this->product( 12 );
		$other         = $this->product( 7 );

		$this->assertSame( Card_Linker::LINKED, $linker->link( $this->account_id, $managed, 0, $this->card() ) );
		$this->assertTrue( $this->link_of( $managed )->sync_stock(), 'an account without the key reads the default: on' );

		$this->set_account( [ Effective_Settings::KEY_SYNC_ON_LINK => false ] );

		$this->assertSame( Card_Linker::LINKED, $linker->link( $this->account_id, $other, 0, $this->card( [ 'offer_id' => 'NB-TEST-5', 'remote_ids' => [ 'card_ref' => 5 ] ] ) ) );
		$this->assertFalse( $this->link_of( $other )->sync_stock() );
		$this->assertNull( $this->stock_row( $other ), 'nothing recorded with the setting off' );
	}

	/**
	 * The first stock send is the product tab's: recorded on stock mode «сайт» for a product with managed stock, and only then.
	 *
	 * @return void
	 */
	public function test_the_first_stock_send_is_recorded_only_for_a_site_stock_and_a_managed_quantity(): void {
		$linker = Card_Linker::syncing( $this->links(), Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID ), [ $this->accounts(), 'get' ], $this->hook_stock(), null );

		$managed = $this->product( 12 );
		$linker->link( $this->account_id, $managed, 0, $this->card() );
		$this->assertNotNull( $this->stock_row( $managed ), 'stock mode «сайт» and a managed quantity: the intent is recorded' );

		$unmanaged = $this->product( null, [ 'manage_stock' => false ] );
		$linker->link( $this->account_id, $unmanaged, 0, $this->card( [ 'offer_id' => 'NB-TEST-6', 'remote_ids' => [ 'card_ref' => 6 ] ] ) );
		$this->assertNull( $this->stock_row( $unmanaged ), 'a product without managed stock records nothing' );

		foreach ( [ Wpdb_Account_Store::STOCK_MARKETPLACE, Wpdb_Account_Store::STOCK_OFF ] as $n => $mode ) {
			$this->set_account( [ 'stock_mode' => $mode ] );
			$product = $this->product( 9 );
			$linker->link( $this->account_id, $product, 0, $this->card( [ 'offer_id' => 'NB-MODE-' . $n, 'remote_ids' => [ 'card_ref' => 100 + $n ] ] ) );

			$this->assertTrue( $this->link_of( $product )->sync_stock(), 'the switch is on whatever the stock mode is' );
			$this->assertNull( $this->stock_row( $product ), 'stock mode «' . $mode . '» records nothing' );
		}
	}
	/**
	 * What a confirmation window says about the marketplace, for the setting and each direction the account sends (#1793).
	 *
	 * @return void
	 */
	public function test_the_confirmation_sentence_says_what_will_be_sent_only_when_something_will(): void {
		$nothing = 'На маркетплейсе ничего не меняется.';
		$stock   = [];
		$price   = [];
		$on      = $this->syncing_linker( true, $stock, $price );
		$off     = $this->syncing_linker( false, $stock, $price );
		$no_pusher = new Card_Linker( $this->links(), static function (): bool {
			return true;
		}, static function (): void {} );

		$cases = [
			'site stock, prices out'      => [ [ 'stock_mode' => 'site', 'price_direction' => 'to_marketplace' ], 'остатки и цены с сайта' ],
			'site stock, prices off'      => [ [ 'stock_mode' => 'site', 'price_direction' => 'off' ], 'остатки с сайта' ],
			'marketplace stock, prices out' => [ [ 'stock_mode' => 'marketplace', 'price_direction' => 'to_marketplace' ], 'цены с сайта' ],
			'prices read from the marketplace' => [ [ 'stock_mode' => 'off', 'price_direction' => 'from_marketplace' ], null ],
			'nothing sent'                => [ [ 'stock_mode' => 'off', 'price_direction' => 'off' ], null ],
		];

		foreach ( $cases as $name => [ $settings, $what ] ) {
			$this->set_account( $settings );
			$account = $this->account_now();

			if ( null === $what ) {
				$this->assertSame( $nothing, $on->remote_effect( $account, $nothing ), $name );
			} else {
				$this->assertSame( 'После связывания на маркетплейс будут отправлены ' . $what . ' (так включено в настройках аккаунта).', $on->remote_effect( $account, $nothing ), $name );
			}

			$this->assertSame( $nothing, $off->remote_effect( $account, $nothing ), $name . ': the setting is off' );
		}

		$this->set_account( [ 'stock_mode' => 'site', 'price_direction' => 'to_marketplace' ] );

		$this->assertSame( $nothing, $this->linker->remote_effect( $this->account_now(), $nothing ), 'a plain linker never switches anything on' );
		$this->assertStringContainsString( 'остатки с сайта', $no_pusher->remote_effect( $this->account_now(), $nothing ), 'a marketplace without a price pusher sends no prices' );
		$this->assertStringNotContainsString( 'цены', $no_pusher->remote_effect( $this->account_now(), $nothing ) );
	}
}

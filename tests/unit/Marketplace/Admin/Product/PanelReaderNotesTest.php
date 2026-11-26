<?php
/**
 * What the product tab reads of the notes a plugin keeps about its links.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Product
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Product\Panel_Reader;
use Woodev\Framework\Marketplace\Admin\Product\Product_Tab_Facts;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Note;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Notes;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Tests\Unit\Marketplace\Admin\Products\Products_Test_Case;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Link_Notes;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Product\Panel_Reader
 */
final class PanelReaderNotesTest extends Products_Test_Case {
	public function test_a_product_block_carries_the_note_about_its_link(): void {
		$product = $this->add_product( [ 'id' => 301 ] );
		$link    = $this->add_link( $product, Link_Record::STATE_CREATED );
		$note    = new Link_Note( 'Добавьте документы.' );
		$notes   = new Fake_Link_Notes();
		$notes->notes[ (int) $link->link_id() ] = $note;

		$block = $this->panel_reader( $notes )->offer_block( $this->account(), $product );

		$this->assertSame( $note, $block['note'] );
		$this->assertSame( [ [ $this->account_id, [ (int) $link->link_id() ] ] ], $notes->asked );
	}

	public function test_a_product_without_a_link_is_not_asked_about(): void {
		$notes = new Fake_Link_Notes();

		$block = $this->panel_reader( $notes )->offer_block( $this->account(), $this->add_product( [ 'id' => 302 ] ) );

		$this->assertNull( $block['note'] );
		$this->assertSame( [], $notes->asked );
	}

	public function test_the_variants_table_asks_once_and_gives_each_row_its_note(): void {
		$parent = $this->add_product(
			[
				'id'       => 400,
				'type'     => 'variable',
				'children' => [ 401, 402, 403 ],
			]
		);
		$links  = [];

		foreach ( [ 401, 402, 403 ] as $id ) {
			$child = $this->add_product(
				[
					'id'        => $id,
					'parent_id' => 400,
					'type'      => 'variation',
				]
			);

			$child->shouldReceive( 'get_image_id' )->andReturn( 0 );
			$child->shouldReceive( 'managing_stock' )->andReturn( false );

			if ( 403 !== $id ) {
				$links[ $id ] = (int) $this->add_link( $child, Link_Record::STATE_CREATED )->link_id();
			}
		}

		$notes                          = new Fake_Link_Notes();
		$notes->notes[ $links[402] ]    = new Link_Note( 'Отклонена.', Link_Note::TONE_ERROR );
		$rows                           = array_column( $this->panel_reader( $notes )->parent_block( $this->account(), $parent )['rows'], null, 'id' );

		$this->assertSame( [ [ $this->account_id, array_values( $links ) ] ], $notes->asked );
		$this->assertNull( $rows[401]['note'] );
		$this->assertSame( 'Отклонена.', $rows[402]['note']->text() );
		$this->assertNull( $rows[403]['note'] );
	}

	public function test_an_adapter_without_notes_gives_a_block_without_one(): void {
		$product = $this->add_product( [ 'id' => 303 ] );
		$this->add_link( $product, Link_Record::STATE_CREATED );

		$this->assertNull( $this->panel_reader( null )->offer_block( $this->account(), $product )['note'] );
	}

	/**
	 * The harness' account.
	 *
	 * @return Account
	 */
	private function account(): Account {
		return $this->accounts()->get( $this->account_id );
	}

	/**
	 * The reader over the harness' stores.
	 *
	 * @param Link_Notes|null $notes What the adapter says about links.
	 * @return Panel_Reader
	 */
	private function panel_reader( ?Link_Notes $notes ): Panel_Reader {
		$facts = new class() implements Product_Tab_Facts {
			public function card_url( Link_Record $link ): string {
				return '';
			}

			public function identifier_summary( Link_Record $link ): string {
				return '';
			}

			public function supports_oversize(): bool {
				return false;
			}
		};

		return new Panel_Reader(
			$this->links(),
			$this->contexts(),
			$this->facts,
			$this->validator(),
			$this->mappings(),
			new Warehouse_Options( new Wpdb_Catalog_Cache( $this->db, Sqlite_Tables::table( 'catalog_cache' ) ) ),
			$this->intents(),
			$this->accounts(),
			new License_Gate(
				static function (): bool {
					return true;
				}
			),
			new Logging(
				'woodev_test_advanced_logging',
				static function ( string $name, $fallback = false ) {
					return $fallback;
				}
			),
			new Pinned_Offer_Id( Sqlite_Tables::MARKETPLACE_ID, $this->adapter->article_policy() ),
			$this->adapter->article_policy(),
			$facts,
			null,
			false,
			null,
			$notes
		);
	}
}

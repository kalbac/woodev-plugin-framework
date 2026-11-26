<?php
/**
 * Unit tests for what the product tab reads about a card's article.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Flows\Article_Display;
use Mockery;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Product\Panel_Reader;
use Woodev\Framework\Marketplace\Admin\Product\Product_Tab_Facts;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;

/**
 * An adapter that changes articles shows a sent card's article as a line with «изменить» and the state of a change; one that does not keeps it frozen.
 */
final class PanelReaderArticleTest extends TestCase {
	public function test_a_sent_card_is_renamable_only_for_an_adapter_that_can_change_articles(): void {
		$row = Link_Record::offer( 3, 41, 0, 'ART-1', [ 'card_state' => Link_Record::STATE_CREATED ] );

		$this->assertFalse( $this->ids( $row, false )['renamable'] );
		$this->assertTrue( $this->ids( $row, true )['renamable'] );
	}

	public function test_a_card_the_marketplace_does_not_know_is_not_renamable(): void {
		foreach ( [ Link_Record::STATE_SUBMITTED, Link_Record::STATE_FAILED, Link_Record::STATE_IMPORTED_WITH_ERRORS, Link_Record::STATE_QUEUED ] as $state ) {
			$row = Link_Record::offer( 3, 41, 0, 'ART-1', [ 'card_state' => $state ] );

			$this->assertFalse( $this->ids( $row, true )['renamable'], $state );
		}
	}

	public function test_a_waiting_and_a_refused_change_are_read_from_the_row(): void {
		$waiting = Link_Record::offer( 3, 41, 0, 'ART-1', [ 'card_state' => Link_Record::STATE_CREATED, 'pending_offer_id' => 'ART-NEW' ] );
		$refused = $waiting->with( [ 'rename_error' => 'OFFER_ID_ALREADY_EXISTS' ] );

		$this->assertSame( 'ART-NEW', $this->ids( $waiting, true )['pending'] );
		$this->assertSame( '', $this->ids( $waiting, true )['rename_error'] );
		$this->assertSame( 'ART-1', $this->ids( $waiting, true )['offer_id'], 'the marketplace still knows the old article' );
		$this->assertSame( 'OFFER_ID_ALREADY_EXISTS', $this->ids( $refused, true )['rename_error'] );
		$this->assertSame( '', $this->ids( $waiting, false )['pending'], 'an adapter without the capability shows no change' );
	}

	public function test_a_waiting_change_stays_visible_while_the_card_is_being_exported(): void {
		$row = Link_Record::offer( 3, 41, 0, 'ART-1', [ 'card_state' => Link_Record::STATE_QUEUED, 'pending_offer_id' => 'ART-NEW' ] );
		$ids = $this->ids( $row, true );

		$this->assertFalse( $ids['renamable'], 'the field is not editable while the card is exported' );
		$this->assertSame( 'ART-NEW', $ids['pending'], 'but the change is still shown' );
	}

	public function test_a_variable_product_has_an_unsent_variation_only_when_one_has_no_card(): void {
		$created = Link_Record::offer( 3, 5, 41, 'ART-S', [ 'card_state' => Link_Record::STATE_CREATED ] );

		$this->assertFalse( $this->has_unsent( [ 5 => $created, 6 => $created ] ), 'imported or exported: every card exists' );
		$this->assertTrue( $this->has_unsent( [ 5 => $created, 6 => null ] ), 'a variation without a row' );
		$this->assertTrue( $this->has_unsent( [ 5 => $created, 6 => $created->with( [ 'card_state' => Link_Record::STATE_UNLINKED ] ) ] ), 'a variation not linked' );
		$this->assertTrue( $this->has_unsent( [ 5 => $created->with( [ 'card_state' => Link_Record::STATE_FAILED ] ) ] ), 'a card that failed was never created' );
		$this->assertFalse( $this->has_unsent( [ 5 => $created->with( [ 'card_state' => Link_Record::STATE_ARCHIVED ] ), 6 => $created->with( [ 'card_state' => Link_Record::STATE_QUEUED ] ) ] ), 'an archived and a queued card both exist' );
		$this->assertFalse( $this->has_unsent( [] ), 'no variation sold on the marketplace: nothing to export' );
	}

	/**
	 * Asks the reader whether any variation of a product has no card.
	 *
	 * @param array<int,Link_Record|null> $records Rows of the variations.
	 * @return bool
	 */
	private function has_unsent( array $records ): bool {
		$reader = ( new \ReflectionClass( Panel_Reader::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( Panel_Reader::class, 'any_variation_without_card' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // Needed before PHP 8.1; deprecated from 8.5.
		}

		return $method->invoke( $reader, $records );
	}

	/**
	 * Reads the article section the way the reader does.
	 *
	 * @param Link_Record $row    The product's row.
	 * @param bool        $renames Whether the adapter changes articles.
	 * @return array<string,mixed>
	 */
	private function ids( Link_Record $row, bool $renames ): array {
		$reader = ( new \ReflectionClass( Panel_Reader::class ) )->newInstanceWithoutConstructor();
		$set    = static function ( string $name, $value ) use ( $reader ): void {
			$property = new \ReflectionProperty( Panel_Reader::class, $name );

			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true ); // Needed before PHP 8.1; deprecated from 8.5.
			}

			$property->setValue( $reader, $value );
		};
		$policy = new Fake_Article_Policy();

		$set( 'renames_articles', $renames );
		$set( 'articles', new Article_Display() );
		$set( 'pinned', new Pinned_Offer_Id( 'fake', $policy ) );
		$set( 'article_policy', $policy );
		$set(
			'tab_facts',
			new class() implements Product_Tab_Facts {
				public function card_url( Link_Record $link ): string {
					return '';
				}

				public function identifier_summary( Link_Record $link ): string {
					return '';
				}

				public function supports_oversize(): bool {
					return false;
				}
			}
		);

		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_sku' )->andReturn( 'SKU-1' );
		$product->shouldReceive( 'get_id' )->andReturn( 41 );
		$product->shouldReceive( 'get_meta' )->andReturn( '' );

		$method = new \ReflectionMethod( Panel_Reader::class, 'identifiers' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // Needed before PHP 8.1; deprecated from 8.5.
		}

		return $method->invoke( $reader, new Account( 3, 'fake', 'Основной', true ), $product, $row, $row );
	}
}

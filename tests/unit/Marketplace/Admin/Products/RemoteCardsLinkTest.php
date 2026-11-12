<?php
/**
 * «Связать с…»: the one card of a row goes to the product the merchant picked. The card is read from the account's snapshot, the
 * write is one row of our own link table, and nothing reaches the marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Products
 */
final class RemoteCardsLinkTest extends Remote_Cards_Test_Case {

	protected function setUp(): void {
		parent::setUp();

		$this->put_snapshot( [ $this->card( 'MUG-1', 'Кружка синяя' ), $this->card( 'TEE-2', 'Футболка' ) ] );
	}

	/**
	 * The link row of a product in the harness' account, or null when it has none.
	 *
	 * @param int $product_id Product.
	 * @return Link_Record|null
	 */
	private function link_row( int $product_id ): ?Link_Record {
		return $this->links()->find( $this->account_id, $product_id );
	}

	/**
	 * @param string $offer_id Article of the card.
	 * @param mixed  $product  The posted product.
	 * @return array<string,mixed> The provider's preview, as the engine returns it to the browser.
	 */
	private function preview( string $offer_id, $product ): array {
		$result = $this->cards_provider()->preview( $this->context(), 'link_to', [ $this->card_row_id( $offer_id ) ], [ 'product' => $product ] );
		$plan   = Action_Response::preview( $this->cards_definition()->get_action( 'link_to' ), $result );

		$this->assertIsArray( $plan, 'The engine accepts the plan.' );

		return $plan;
	}

	/**
	 * @param string $offer_id Article of the card.
	 * @param mixed  $product  The posted product.
	 * @return array<string,mixed> The apply, as the engine returns it to the browser.
	 */
	private function apply( string $offer_id, $product ): array {
		$result = $this->cards_provider()->apply( $this->context(), 'link_to', [ $this->card_row_id( $offer_id ) ], [ 'product' => $product ] );
		$body   = Action_Response::apply( $this->cards_definition(), $this->cards_definition()->get_action( 'link_to' ), $result );

		$this->assertIsArray( $body, 'The engine accepts the answer.' );

		return $body;
	}

	public function test_the_preview_names_the_card_and_the_product_and_changes_nothing(): void {
		$this->add_product( [ 'id' => 41, 'sku' => 'MUG-1' ] );

		$plan = $this->preview( 'MUG-1', '41' );

		$this->assertSame( [ 1, 1, 0 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertTrue( $plan['can_apply'] );
		$this->assertStringContainsString( 'MUG-1', $plan['scope']['sentence'] );
		$this->assertStringContainsString( 'Кружка синяя', $plan['scope']['sentence'] );
		$this->assertStringContainsString( 'Товар 41', $plan['scope']['sentence'] );
		$this->assertNull( $this->link_row( 41 ), 'A preview writes no link.' );
		$this->assertSame( [], $this->adapter->link_resolver()->reads );
	}

	public function test_the_apply_links_the_card_to_the_product_and_returns_the_row_as_it_is_now(): void {
		$this->add_product( [ 'id' => 41, 'sku' => '' ] );

		$body = $this->apply( 'MUG-1', '41' );

		$this->assertSame( 'done', $body['status'] );
		$this->assertSame( [ 1, 0, 0 ], [ $body['accepted'], $body['skipped'], $body['failed'] ] );

		$link = $this->link_row( 41 );
		$this->assertNotNull( $link );
		$this->assertSame( 'MUG-1', $link->offer_id() );
		$this->assertSame( [ 'product_id' => 5001, 'sku' => 7001 ], $link->remote_ids() );
		$this->assertFalse( $link->sync_stock(), 'Linking a card does not make the shop push stock by itself.' );
		$this->assertFalse( $link->sync_price() );

		$row = $body['rows'][0];
		$this->assertSame( $this->card_row_id( 'MUG-1' ), $row['id'] );
		$this->assertSame( 'link', $row['cells']['on_site'][0]['type'] );
		$this->assertFalse( $row['actions'][0]['enabled'], 'The card is on the site now.' );
		$this->assertSame( [], $this->adapter->link_resolver()->reads, 'Linking writes our table; the marketplace is not asked.' );
		$this->assertSame( [], $this->scheduler->actions );
	}

	public function test_a_variation_is_linked_under_its_parent(): void {
		$this->add_product( [ 'id' => 50, 'type' => 'variable' ] );
		$this->add_product( [ 'id' => 51, 'parent_id' => 50, 'type' => 'variation' ] );

		$this->apply( 'MUG-1', '51' );

		$this->assertSame( 50, $this->link_of( 51 )->wc_parent_id() );
	}

	public function test_a_card_already_linked_to_another_product_is_not_given_to_a_second_one(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->add_product( [ 'id' => 42 ] );
		$this->apply( 'MUG-1', '41' );

		$body = $this->apply( 'MUG-1', '42' );

		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( [ 0, 1 ], [ $body['accepted'], $body['skipped'] ] );
		$this->assertStringContainsString( 'уже связана с другим товаром', $body['groups'][0]['reason'] );
		$this->assertNull( $this->link_row( 42 ) );
	}

	public function test_a_product_that_already_has_a_card_keeps_it(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->apply( 'MUG-1', '41' );

		$body = $this->apply( 'TEE-2', '41' );

		$this->assertSame( 1, $body['skipped'] );
		$this->assertStringContainsString( 'Сначала снимите старую связь', $body['groups'][0]['reason'] );
		$this->assertSame( 'MUG-1', $this->link_of( 41 )->offer_id() );
	}

	public function test_a_card_that_left_the_list_is_a_stale_list_with_its_reason(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->transients = [];

		$plan = $this->preview( 'MUG-1', '41' );
		$body = $this->apply( 'MUG-1', '41' );

		$this->assertFalse( $plan['can_apply'] );
		$this->assertStringContainsString( 'мог устареть', $plan['groups'][0]['reason'] );
		$this->assertSame( 1, $body['skipped'] );
		$this->assertNull( $this->link_row( 41 ) );
	}

	public function test_a_card_whose_details_did_not_agree_is_not_linkable(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->put_snapshot( [ $this->card( 'MUG-1', '', [ 'details_ok' => false ] ) ] );

		$body = $this->apply( 'MUG-1', '41' );

		$this->assertSame( 1, $body['skipped'] );
		$this->assertStringContainsString( 'нет полных данных', $body['groups'][0]['reason'] );
		$this->assertNull( $this->link_row( 41 ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function provide_products_that_cannot_be_picked(): array {
		return [
			'nothing picked'          => [ '', 'Выберите товар из списка' ],
			'not a number'            => [ 'abc', 'Выберите товар из списка' ],
			'a number with a suffix'  => [ '41; DROP', 'Выберите товар из списка' ],
			'an array'                => [ [ '41' ], 'Выберите товар из списка' ],
			'zero'                    => [ '0', 'Выберите товар из списка' ],
			'a product that is gone'  => [ '999', 'Выберите товар из списка' ],
			'a variable product'      => [ '60', 'вариативному товару' ],
		];
	}

	/**
	 * @dataProvider provide_products_that_cannot_be_picked
	 *
	 * @param mixed  $posted   What the browser sent.
	 * @param string $sentence Part of the refusal.
	 */
	public function test_a_product_that_cannot_take_a_card_is_a_refusal_on_the_field_in_the_preview_and_in_the_apply( $posted, string $sentence ): void {
		$this->add_product( [ 'id' => 60, 'type' => 'variable' ] );

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$this->$method( 'MUG-1', $posted );
				$this->fail( 'The picked product must be refused.' );
			} catch ( Action_Input_Exception $e ) {
				$this->assertSame( [ 'product' ], array_keys( $e->get_errors() ) );
				$this->assertStringContainsString( $sentence, $e->get_errors()['product'] );
			}
		}

		$this->assertNull( $this->link_row( 60 ) );
	}

	public function test_a_product_the_user_may_not_edit_is_refused(): void {
		$this->add_product( [ 'id' => 41 ] );
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'edit_post' !== $capability;
			}
		);

		$this->expectException( Action_Input_Exception::class );

		$this->apply( 'MUG-1', '41' );
	}

	public function test_a_row_of_another_account_links_nothing(): void {
		$this->add_product( [ 'id' => 41 ] );
		$foreign = '99999:' . substr( $this->card_row_id( 'MUG-1' ), strpos( $this->card_row_id( 'MUG-1' ), ':' ) + 1 );

		$result = $this->cards_provider()->apply( $this->context(), 'link_to', [ $foreign ], [ 'product' => '41' ] );

		$this->assertSame( 1, $result['skipped'], 'The key names no card of this account\'s list.' );
		$this->assertNull( $this->link_row( 41 ) );
	}

	public function test_the_card_comes_from_the_snapshot_never_from_the_request(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->put_snapshot( [ $this->card( 'MUG-1', 'Кружка', [ 'remote_ids' => [ 'product_id' => 111, 'sku' => 222 ] ] ) ] );

		$this->cards_provider()->apply( $this->context(), 'link_to', [ $this->card_row_id( 'MUG-1' ) ], [ 'product' => '41', 'remote_ids' => [ 'product_id' => 1 ], 'offer_id' => 'OTHER' ] );

		$link = $this->link_of( 41 );
		$this->assertSame( 'MUG-1', $link->offer_id() );
		$this->assertSame( [ 'product_id' => 111, 'sku' => 222 ], $link->remote_ids() );
	}

	public function test_a_declined_card_keeps_the_marketplaces_text_when_linked(): void {
		$this->add_product( [ 'id' => 41 ] );
		$this->put_snapshot( [ $this->card( 'MUG-1', '', [ 'state' => Link_Record::STATE_FAILED, 'failed' => true, 'status_text' => 'Нет категории' ] ) ] );

		$this->apply( 'MUG-1', '41' );

		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( 41 )->card_state() );
		$this->assertSame( 'Нет категории', $this->db->get_var( 'SELECT last_error_text FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE wc_product_id = 41' ) );
	}
}

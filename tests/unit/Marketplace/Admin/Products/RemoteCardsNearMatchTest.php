<?php
/**
 * The near-match report and the last «Связать по артикулу» run on the «Карточки на маркетплейсе» table: the pairs the run found
 * whose articles only look alike, the two answers the merchant gives (link the pair, or say they are different), the line about the
 * last run, and the manual link that settles a pair. Everything is local: a read and an answer never reach the marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids;
use Woodev\Framework\Marketplace\Data\Link_Record;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Query
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Summary
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids
 */
final class RemoteCardsNearMatchTest extends Remote_Cards_Test_Case {

	protected function setUp(): void {
		parent::setUp();

		$this->add_product( [ 'id' => 41, 'sku' => 'ABC-001' ] );
		$this->add_product( [ 'id' => 42, 'sku' => 'XYZ-9' ] );
		$this->put_snapshot( [ $this->card( 'abc-001', 'Кружка' ), $this->card( 'xyz-9', 'Тарелка' ) ] );
		$this->reports()->replace_near_matches(
			$this->account_id,
			[
				$this->near_entry( 41, 'ABC-001', 'abc-001' ),
				$this->near_entry( 42, 'XYZ-9', 'xyz-9', 'look_alike' ),
			]
		);
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed> The list body, passed through the engine's normaliser.
	 */
	private function list_body( array $filters = [] ): array {
		$result = $this->cards_provider()->query(
			$this->context(),
			[
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'filters'  => $filters,
			]
		);
		$body   = Row_Normalizer::page( $this->cards_definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * @param string $offer_id Article of the card.
	 * @param int    $product  The shop product.
	 * @return array<string,mixed> The engine's answer to the apply of «Это тот же товар».
	 */
	private function confirm( string $offer_id, int $product ): array {
		$action = $this->cards_definition()->get_action( 'near_confirm' );
		$result = $this->cards_provider()->apply( $this->context(), 'near_confirm', [ $this->near_row_id( $offer_id, $product ) ], [] );
		$body   = Action_Response::apply( $this->cards_definition(), $action, $result );

		$this->assertIsArray( $body, 'The engine accepts the answer.' );

		return $body;
	}

	/**
	 * @param string $offer_id Article of the card.
	 * @param int    $product  The shop product.
	 * @return array<string,mixed> The engine's answer to the apply of «Нет, это разные».
	 */
	private function dismiss( string $offer_id, int $product ): array {
		$action = $this->cards_definition()->get_action( 'near_dismiss' );
		$result = $this->cards_provider()->apply( $this->context(), 'near_dismiss', [ $this->near_row_id( $offer_id, $product ) ], [] );
		$body   = Action_Response::apply( $this->cards_definition(), $action, $result );

		$this->assertIsArray( $body, 'The engine accepts the answer.' );

		return $body;
	}

	public function test_the_definition_declares_both_answers_and_the_view_that_shows_the_report(): void {
		$definition = $this->cards_definition();

		$this->assertSame( 'Это тот же товар', $definition->get_action( 'near_confirm' )['label'] );
		$this->assertSame( 'Нет, это разные', $definition->get_action( 'near_dismiss' )['label'] );
		$this->assertSame( 'edit_products', $definition->get_action( 'near_confirm' )['capability'] );
		$this->assertContains( 'show', array_column( $definition->to_array()['filters'], 'id' ) );
	}

	public function test_the_report_view_lists_each_pair_with_the_two_answers_and_how_the_articles_differ(): void {
		$body = $this->list_body( [ 'show' => 'near' ] );
		$rows = array_column( $body['rows'], null, 'id' );

		$this->assertSame( 2, $body['total'] );
		$this->assertSame( [ $this->near_row_id( 'abc-001', 41 ), $this->near_row_id( 'xyz-9', 42 ) ], array_keys( $rows ) );

		$row = $rows[ $this->near_row_id( 'abc-001', 41 ) ];
		$this->assertSame( 'abc-001', $row['cells']['offer_id'] );
		$this->assertSame( [ 'near_confirm', 'near_dismiss' ], array_column( $row['actions'], 'id' ) );
		$this->assertSame( [ true, true ], array_column( $row['actions'], 'enabled' ) );
		$on_site = array_column( $row['cells']['on_site'], 'value' );
		$this->assertContains( 'Артикул на сайте: ABC-001', $on_site );
		$this->assertContains( 'Артикулы отличаются регистром букв', $on_site );
		$this->assertContains( 'Артикулы отличаются похожими буквами или цифрами', array_column( $rows[ $this->near_row_id( 'xyz-9', 42 ) ]['cells']['on_site'], 'value' ) );
		$this->assertStringContainsString( 'отличаются только регистром', $body['messages']['summary'] );
	}

	public function test_the_report_view_can_be_searched_by_the_cards_name_or_either_article(): void {
		$result = $this->cards_provider()->query( $this->context(), [ 'page' => 1, 'per_page' => 20, 'search' => 'карточка xyz', 'filters' => [ 'show' => 'near' ] ] );
		$this->assertSame( 1, $result['total'] );

		$result = $this->cards_provider()->query( $this->context(), [ 'page' => 1, 'per_page' => 20, 'search' => 'abc-001', 'filters' => [ 'show' => 'near' ] ] );
		$this->assertSame( 1, $result['total'], 'The product\'s article is searched too.' );
	}

	public function test_the_cards_table_says_what_the_last_run_did_and_how_many_pairs_wait(): void {
		$this->reports()->save_run(
			$this->account_id,
			[
				'finished_at'    => 1700000000,
				'cards'          => 40,
				'linked'         => 30,
				'already_linked' => 6,
				'ambiguous'      => 1,
				'error'          => '',
			]
		);

		$summary = $this->list_body()['messages']['summary'];

		$this->assertStringContainsString( 'Последний поиск по артикулу (14.11.2023 22:13)', $summary );
		$this->assertStringContainsString( 'карточек просмотрено — 40, связано — 30, уже были связаны — 6, неоднозначных — 1', $summary );
		$this->assertStringContainsString( 'которые ждут вашего решения: 2', $summary );
	}

	public function test_pairs_dropped_for_the_limit_are_mentioned_and_none_dropped_says_nothing(): void {
		$run = [ 'finished_at' => 1700000000, 'cards' => 1, 'linked' => 0, 'already_linked' => 0, 'ambiguous' => 0, 'error' => '' ];

		$this->reports()->save_run( $this->account_id, $run + [ 'near_dropped' => 7 ] );
		$this->assertStringContainsString( 'показаны первые 500 пар, ещё 7 не поместились', $this->list_body()['messages']['summary'] );

		$this->reports()->save_run( $this->account_id, $run + [ 'near_dropped' => 0 ] );
		$this->assertStringNotContainsString( 'не поместились', $this->list_body()['messages']['summary'] );
	}

	public function test_a_failed_run_says_why_and_a_table_without_a_run_or_pairs_says_nothing_about_them(): void {
		$this->reports()->save_run( $this->account_id, [ 'finished_at' => 1700000000, 'error' => 'Маркетплейс не ответил' ] );
		$this->assertStringContainsString( 'не удался: Маркетплейс не ответил', $this->list_body()['messages']['summary'] );

		$this->options = [];
		$summary       = $this->list_body()['messages']['summary'];
		$this->assertStringNotContainsString( 'Последний поиск', $summary );
		$this->assertStringNotContainsString( 'похожими артикулами', $summary );
	}

	public function test_the_cards_rows_do_not_offer_the_report_answers(): void {
		$rows = $this->list_body()['rows'];

		$this->assertSame( [ 'link_to' ], array_column( $rows[0]['actions'], 'id' ) );
	}

	public function test_reading_the_report_or_planning_an_answer_never_calls_the_marketplace(): void {
		$this->list_body( [ 'show' => 'near' ] );
		$this->cards_provider()->preview( $this->context(), 'near_confirm', [ $this->near_row_id( 'abc-001', 41 ) ], [] );
		$this->cards_provider()->preview( $this->context(), 'near_dismiss', [ $this->near_row_id( 'abc-001', 41 ) ], [] );

		$this->assertSame( [], $this->adapter->link_resolver()->reads );
		$this->assertSame( [], $this->scheduler->actions );
		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ), 'A preview settles nothing.' );
	}

	public function test_the_preview_of_the_link_names_the_card_and_the_product(): void {
		$result = $this->cards_provider()->preview( $this->context(), 'near_confirm', [ $this->near_row_id( 'abc-001', 41 ) ], [] );
		$plan   = Action_Response::preview( $this->cards_definition()->get_action( 'near_confirm' ), $result );

		$this->assertTrue( $plan['can_apply'] );
		$this->assertStringContainsString( 'abc-001', $plan['scope']['sentence'] );
		$this->assertStringContainsString( 'Товар 41', $plan['scope']['sentence'] );
	}

	public function test_this_is_the_same_product_links_the_pair_and_the_pair_leaves_the_report(): void {
		$body = $this->confirm( 'abc-001', 41 );

		$this->assertSame( 1, $body['accepted'] );
		$this->assertSame( [ $this->near_row_id( 'abc-001', 41 ) ], $body['removed_ids'] );

		$link = $this->link_of( 41 );
		$this->assertSame( 'abc-001', $link->offer_id() );
		$this->assertSame( [ 'product_id' => 5001, 'sku' => 7001 ], $link->remote_ids(), 'The report\'s own copy of the card is used.' );
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
		$this->assertSame( [], $this->adapter->link_resolver()->reads, 'Nothing reached the marketplace.' );
	}

	public function test_a_linked_card_shows_its_product_in_the_cards_table_after_the_answer(): void {
		$this->confirm( 'abc-001', 41 );

		$on_site = array_column( $this->list_body( [ 'on_site' => 'yes' ] )['rows'], 'cells', 'id' );

		$this->assertArrayHasKey( $this->card_row_id( 'abc-001' ), $on_site );
	}

	public function test_this_is_the_same_product_also_settles_the_other_look_alikes_of_that_product_and_card(): void {
		$this->add_product( [ 'id' => 43, 'sku' => 'ABC-001 ' ] );
		$this->reports()->replace_near_matches(
			$this->account_id,
			[
				$this->near_entry( 41, 'ABC-001', 'abc-001' ),
				$this->near_entry( 43, 'ABC-001 ', 'abc-001' ),
				$this->near_entry( 41, 'ABC-001', 'Abc-001' ),
				$this->near_entry( 42, 'XYZ-9', 'xyz-9' ),
			]
		);

		$this->confirm( 'abc-001', 41 );

		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ), 'A product has one card and a card one product.' );
	}

	public function test_no_these_are_different_removes_the_pair_only_and_links_nothing(): void {
		$body = $this->dismiss( 'abc-001', 41 );

		$this->assertSame( 1, $body['accepted'] );
		$this->assertSame( [ $this->near_row_id( 'abc-001', 41 ) ], $body['removed_ids'] );
		$this->assertNull( $this->links()->find( $this->account_id, 41 ), 'No link is written.' );
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
	}

	public function test_a_pair_answered_no_does_not_come_back_with_the_next_run_until_an_article_changes(): void {
		$this->dismiss( 'abc-001', 41 );

		// The next run finds the same two pairs again.
		$this->reports()->replace_near_matches(
			$this->account_id,
			[
				$this->near_entry( 41, 'ABC-001', 'abc-001' ),
				$this->near_entry( 42, 'XYZ-9', 'xyz-9', 'look_alike' ),
			]
		);
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ), 'The answered pair stays out.' );

		// The merchant changes the product's article: it is a new question.
		$this->reports()->replace_near_matches(
			$this->account_id,
			[
				$this->near_entry( 41, 'ABC-001.', 'abc-001' ),
				$this->near_entry( 42, 'XYZ-9', 'xyz-9', 'look_alike' ),
			]
		);
		$this->assertSame( [ 41, 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
	}

	public function test_a_manual_link_settles_the_pair_in_the_report(): void {
		$result = $this->cards_provider()->apply( $this->context(), 'link_to', [ $this->card_row_id( 'abc-001' ) ], [ 'product' => '41' ] );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ), 'After «Связать с…» the pair is not asked about again.' );
	}

	public function test_a_manual_link_that_is_refused_leaves_the_report_alone(): void {
		$this->add_link( $this->products[41], Link_Record::STATE_CREATED, [ 'offer_id' => 'OTHER' ] );

		$this->cards_provider()->apply( $this->context(), 'link_to', [ $this->card_row_id( 'abc-001' ) ], [ 'product' => '41' ] );

		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ) );
	}

	public function test_a_pair_that_is_already_settled_is_a_refusal_with_its_reason_and_the_row_goes(): void {
		$this->dismiss( 'abc-001', 41 );

		foreach ( [ 'near_confirm', 'near_dismiss' ] as $action ) {
			$result = $this->cards_provider()->apply( $this->context(), $action, [ $this->near_row_id( 'abc-001', 41 ) ], [] );

			$this->assertSame( 1, $result['skipped'], $action );
			$this->assertSame( [ $this->near_row_id( 'abc-001', 41 ) ], $result['removed_ids'], $action );
			$this->assertStringContainsString( 'уже решена', $result['groups'][0]['reason'], $action );
		}

		$this->assertNull( $this->links()->find( $this->account_id, 41 ) );
	}

	public function test_a_product_that_took_another_card_meanwhile_settles_the_pair_without_a_link(): void {
		$this->add_link( $this->products[41], Link_Record::STATE_CREATED, [ 'offer_id' => 'OTHER' ] );

		$result = $this->cards_provider()->apply( $this->context(), 'near_confirm', [ $this->near_row_id( 'abc-001', 41 ) ], [] );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( 'уже связан', $result['groups'][0]['reason'] );
		$this->assertSame( [ $this->near_row_id( 'abc-001', 41 ) ], $result['removed_ids'] );
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
	}

	public function test_the_ids_of_another_account_or_a_card_row_are_not_report_rows(): void {
		$foreign = '99999:' . substr( $this->near_row_id( 'abc-001', 41 ), strpos( $this->near_row_id( 'abc-001', 41 ), ':' ) + 1 );

		foreach ( [ $foreign, $this->card_row_id( 'abc-001' ) ] as $id ) {
			$result = $this->cards_provider()->apply( $this->context(), 'near_confirm', [ $id ], [] );

			$this->assertSame( 1, $result['skipped'], $id );
		}

		$this->assertNull( $this->links()->find( $this->account_id, 41 ) );
		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ) );
	}

	public function test_a_report_row_is_authorised_for_its_own_account_only(): void {
		$provider = $this->cards_provider();

		$this->assertTrue( $provider->authorize( 'near_confirm', $this->context(), [ $this->near_row_id( 'abc-001', 41 ) ] ) );
		$this->assertFalse( $provider->authorize( 'near_confirm', $this->context(), [ '99999:' . str_repeat( 'a', 32 ) . ':41' ] ) );
		$this->assertFalse( $provider->authorize( 'near_confirm', $this->context(), [ $this->card_row_id( 'abc-001' ) . ':0' ] ) );
	}

	public function test_a_user_who_may_not_edit_the_product_decides_nothing(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'edit_post' !== $capability;
			}
		);

		foreach ( [ 'near_confirm', 'near_dismiss' ] as $action ) {
			foreach ( [ 'preview', 'apply' ] as $method ) {
				try {
					$this->cards_provider()->$method( $this->context(), $action, [ $this->near_row_id( 'abc-001', 41 ) ], [] );
					$this->fail( "$action $method must be refused" );
				} catch ( Action_State_Exception $e ) {
					$this->assertStringContainsString( 'нет прав', $e->getMessage() );
				}
			}
		}

		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ) );
		$this->assertNull( $this->links()->find( $this->account_id, 41 ) );
	}

	public function test_a_product_that_is_gone_cannot_be_linked_but_its_pair_can_be_removed(): void {
		unset( $this->products[41] );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->products[ (int) $id ] ?? false;
			}
		);

		try {
			$this->cards_provider()->apply( $this->context(), 'near_confirm', [ $this->near_row_id( 'abc-001', 41 ) ], [] );
			$this->fail( 'A product that is gone cannot take a card.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'нет на сайте', $e->getMessage() );
		}

		$this->dismiss( 'abc-001', 41 );
		$this->assertSame( [ 42 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
	}

	public function test_the_ids_of_report_rows_round_trip(): void {
		$id = Remote_Cards_Ids::near_row_id( 7, 'ABC-001', 41 );

		$this->assertSame(
			[
				'key'        => Remote_Cards_Ids::key( 'ABC-001' ),
				'product_id' => 41,
			],
			Remote_Cards_Ids::near_of( $id, 7 )
		);
		$this->assertNull( Remote_Cards_Ids::near_of( $id, 8 ) );
		$this->assertNull( Remote_Cards_Ids::near_of( Remote_Cards_Ids::row_id( 7, 'ABC-001' ), 7 ), 'A card row id names no product.' );
		$this->assertNull( Remote_Cards_Ids::key_of( $id, 7 ), 'A report row id is not a card row id.' );
		$this->assertNull( Remote_Cards_Ids::near_of( '7:' . Remote_Cards_Ids::key( 'A' ) . ':0', 7 ) );
	}
}

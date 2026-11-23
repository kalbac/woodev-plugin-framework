<?php
/**
 * The «Карточки на маркетплейсе» provider against the records engine: what it declares, the rows it builds from the snapshot, what a
 * read does not do (it never calls the marketplace), and the answers it gives to a preview and an apply, run through the engine's own
 * normalisers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Query;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';
require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows
 */
final class RemoteCardsProviderTest extends Remote_Cards_Test_Case {

	/**
	 * @param array<string,mixed> $filters Filters as the engine validates them.
	 * @param string              $search  Search text.
	 * @return array<string,mixed> The list body, passed through the engine's normaliser.
	 */
	private function list_body( array $filters = [], string $search = '' ): array {
		$query  = [
			'page'     => 1,
			'per_page' => 20,
			'search'   => $search,
			'filters'  => $filters,
		];
		$result = $this->cards_provider()->query( $this->context(), $query );
		$body   = Row_Normalizer::page( $this->cards_definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * @param array<string,mixed> $body List body.
	 * @return array<string,array<string,mixed>> The card rows by row id.
	 */
	private function rows_by_offer( array $body ): array {
		return array_column( $body['rows'], null, 'id' );
	}

	public function test_the_definition_is_accepted_by_the_engine_and_declares_a_searchable_product_field(): void {
		$definition = $this->cards_definition()->to_array();

		$this->assertSame( [ 'refresh_list', 'link_by_article' ], array_column( $definition['bulk_actions'], 'id' ) );
		$this->assertTrue( array_column( $definition['bulk_actions'], null, 'id' )['refresh_list']['skip_preview'], '«Обновить список» starts on the click: a preview of an account-wide read counts nothing.' );
		$this->assertFalse( array_column( $definition['bulk_actions'], null, 'id' )['link_by_article']['skip_preview'] );
		$this->assertSame( [ 'open_remote', 'open_product', 'unlink', 'link_to', 'near_confirm', 'near_dismiss' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [], $definition['sorts']['keys'], 'The list is the marketplace\'s own order.' );
		$this->assertNull( $definition['analytics'] );
		$this->assertNull( $definition['children'] );

		$field = array_column( $definition['row_actions'], null, 'id' )['link_to']['fields'][0];
		$this->assertSame( 'product', $field['id'] );
		$this->assertTrue( $field['required'] );
		$this->assertTrue( $field['options_source'] );
		$this->assertTrue( $field['searchable'], 'The product is looked for by what the merchant types.' );
	}

	public function test_both_account_wide_actions_state_that_they_reach_the_whole_account(): void {
		$actions = array_column( $this->cards_definition()->to_array()['bulk_actions'], null, 'id' );

		foreach ( [ 'refresh_list', 'link_by_article' ] as $id ) {
			$this->assertSame( 'account', $actions[ $id ]['scope'], $id );
			$this->assertNotSame( '', $actions[ $id ]['scope_sentence'], $id );
		}

		$this->assertStringContainsString( 'Тестмаркет', $actions['refresh_list']['scope_sentence'] );
		$this->assertStringContainsString( 'по всему магазину или по одной категории', $actions['link_by_article']['scope_sentence'] );
		$this->assertStringNotContainsString( 'отмеченным', $actions['link_by_article']['scope_sentence'], 'no card row can be marked on this table' );
	}

	public function test_the_table_opens_on_all_cards_so_a_cabinet_whose_cards_are_all_on_the_site_is_not_called_empty(): void {
		$filters = array_column( $this->cards_definition()->to_array()['filters'], null, 'id' );

		$this->assertSame( 'search', $filters['q']['type'] );
		$this->assertArrayNotHasKey( 'default', $filters['on_site'] );
		$this->assertSame( [ 'no', 'yes' ], array_column( $filters['on_site']['options'], 'value' ) );
		$this->assertTrue( $filters['on_site']['views'], '«На сайте» is the counted row above the table.' );
		$this->assertArrayNotHasKey( 'views', $filters['show'] );
	}

	public function test_the_account_selector_offers_the_enabled_accounts(): void {
		$context = $this->cards_definition()->to_array()['context'];

		$this->assertSame( [ [ 'value' => (string) $this->account_id, 'label' => 'Основной' ] ], $context['options'] );
	}

	public function test_a_card_no_product_carries_is_a_row_that_can_be_linked(): void {
		$this->put_cards( [ $this->card( 'A-1', 'Кружка синяя', [ 'status_text' => '' ] ) ] );

		$body = $this->list_body( [ 'on_site' => 'no' ] );
		$row  = $this->rows_by_offer( $body )[ $this->card_row_id( 'A-1' ) ];

		$this->assertSame( 1, $body['total'] );
		$this->assertSame( 'A-1', $row['cells']['offer_id'] );
		$this->assertSame( [ [ 'type' => 'text', 'value' => 'Кружка синяя' ] ], $row['cells']['name'], 'No cabinet address from the adapter: the name is plain text.' );
		$this->assertArrayNotHasKey( 'ids', $row['cells'], 'The marketplace numbers are not a column.' );
		$this->assertCount( 1, $row['cells']['state'], 'One badge; the numbers are its tooltip.' );
		$this->assertSame( 'ID товара: 5001' . "\n" . 'SKU: 7001', $row['cells']['state'][0]['value']['tooltip'] );
		$this->assertSame( [ '—', '—' ], [ $row['cells']['price'], $row['cells']['stock'] ], 'The store gives no price or stock yet: a dash, not an empty cell.' );
		$this->assertSame( 'https://example.test/placeholder.png', $row['cells']['image'][0]['value']['url'], 'A card without a photo shows the WooCommerce placeholder.' );
		$this->assertSame( 'Создан', $row['cells']['state'][0]['value']['label'] );
		$this->assertSame( '—', $row['cells']['on_site'][0]['value'] );
		$this->assertSame( [ [ 'id' => 'link_to', 'enabled' => true, 'reason' => '' ] ], $row['actions'] );
		$this->assertFalse( $row['selectable'] );
		$this->assertFalse( $row['has_children'] );
	}

	public function test_a_card_a_product_carries_shows_the_product_and_cannot_be_linked_again(): void {
		$product = $this->add_product( [ 'id' => 41, 'sku' => 'B-2' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED, [ 'offer_id' => 'B-2' ] );
		$this->put_cards( [ $this->card( 'B-2', 'Футболка' ) ] );

		$row = $this->rows_by_offer( $this->list_body( [ 'on_site' => 'yes' ] ) )[ $this->card_row_id( 'B-2' ) ];

		$this->assertSame( 'link', $row['cells']['on_site'][0]['type'] );
		$this->assertSame( 'Товар 41', $row['cells']['on_site'][0]['value']['label'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=41&action=edit', $row['cells']['on_site'][0]['value']['url'] );
		// A card on the site needs nothing done to it: it is opened or unlinked, no disabled «Связать с…» and no sentence.
		$this->assertSame( [ 'open_product', 'unlink' ], array_column( $row['actions'], 'id' ) );
		$this->assertTrue( $row['actions'][0]['enabled'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=41&action=edit', $row['actions'][0]['url'] );
	}

	public function test_the_default_filter_hides_the_cards_already_on_the_site_and_no_filter_shows_all(): void {
		$product = $this->add_product( [ 'id' => 41, 'sku' => 'B-2' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED, [ 'offer_id' => 'B-2' ] );
		$this->put_cards( [ $this->card( 'A-1' ), $this->card( 'B-2' ), $this->card( 'C-3' ) ] );

		$this->assertSame( 2, $this->list_body( [ 'on_site' => 'no' ] )['total'] );
		$this->assertSame( 1, $this->list_body( [ 'on_site' => 'yes' ] )['total'] );
		$this->assertSame( 3, $this->list_body()['total'], 'Cleared: every card.' );
		$this->assertSame( 1, $this->list_body( [], 'c-3' )['total'] );
	}

	public function test_the_on_site_facets_count_the_cards_whatever_the_view_and_follow_the_search(): void {
		$product = $this->add_product( [ 'id' => 41, 'sku' => 'B-2' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED, [ 'offer_id' => 'B-2' ] );
		$this->put_cards( [ $this->card( 'A-1' ), $this->card( 'B-2' ), $this->card( 'C-3' ) ] );

		$counts = function ( array $filters, string $search = '' ): array {
			return array_column( $this->list_body( $filters, $search )['facets']['on_site'], 'count', 'value' );
		};

		$this->assertSame( [ 'no' => 2, 'yes' => 1 ], $counts( [ 'on_site' => 'no' ] ) );
		$this->assertSame( [ 'no' => 2, 'yes' => 1 ], $counts( [ 'on_site' => 'yes' ] ), 'The chosen view does not change the numbers.' );
		$this->assertSame( [ 'no' => 1, 'yes' => 0 ], $counts( [ 'on_site' => 'no' ], 'c-3' ), 'The search does.' );
	}

	public function test_a_table_without_a_snapshot_counts_zero_cards(): void {
		$this->assertSame( [ 'no' => 0, 'yes' => 0 ], array_column( $this->list_body( [ 'on_site' => 'no' ] )['facets']['on_site'], 'count', 'value' ) );
	}

	public function test_a_card_whose_details_did_not_come_has_no_state_and_cannot_be_linked(): void {
		$this->put_cards( [ $this->card( 'A-1', '', [ 'details_ok' => false, 'state' => 'unlinked', 'name' => '' ] ) ] );

		$row = $this->list_body()['rows'][0];

		$this->assertSame( 'Неизвестно', $row['cells']['state'][0]['value']['label'] );
		$this->assertFalse( $row['actions'][0]['enabled'] );
		$this->assertStringContainsString( 'Обновите список', $row['actions'][0]['reason'] );
	}

	public function test_a_declined_card_shows_the_marketplaces_reason_and_an_archived_one_says_so(): void {
		$this->put_cards(
			[
				$this->card( 'A-1', '', [ 'state' => 'failed', 'failed' => true, 'status_text' => 'Нет категории' ] ),
				$this->card( 'B-2', '', [ 'archived' => true ] ),
			]
		);

		$rows = $this->rows_by_offer( $this->list_body() );
		$a    = $rows[ $this->card_row_id( 'A-1' ) ]['cells']['state'];
		$b    = $rows[ $this->card_row_id( 'B-2' ) ]['cells']['state'];

		$this->assertSame( [ 'Ошибка', 'danger' ], [ $a[0]['value']['label'], $a[0]['value']['tone'] ] );
		$this->assertSame( 'Нет категории', explode( "\n", $a[0]['value']['tooltip'] )[0], 'The reason is the badge\'s tooltip.' );
		$this->assertSame( 'В архиве', $b[0]['value']['label'] );
	}

	public function test_the_summary_says_when_the_list_was_loaded_or_what_to_do(): void {
		$body = $this->list_body();
		$this->assertStringNotContainsString( 'Обновить список', $body['messages']['summary'], 'No list yet: the empty table says so once, the line above it stays quiet.' );
		$this->assertArrayNotHasKey( 'empty', $body['messages'], 'The definition\'s own empty sentence («Список карточек ещё не загружен…») stands.' );

		$this->put_cards( [ $this->card( 'A-1' ), $this->card( 'B-2' ) ], 1700000000 );
		$loaded = $this->list_body()['messages'];
		$this->assertStringContainsString( 'Список обновлён 14.11.2023 в 22:13.', $loaded['summary'] );
		$this->assertStringContainsString( 'В списке карточек: 2.', $loaded['summary'] );
		$this->assertSame( 'Список обновлён 14.11.2023 в 22:13', $loaded['refreshed_at'], 'The time alone, for the screen\'s summary area.' );

		$this->card_store()->mark_refreshing( $this->account_id );
		$body = $this->list_body();
		$this->assertTrue( $body['account_activity']['active'], 'The account line says the list is being loaded.' );
		$this->assertSame( [ 'refresh_list' ], $body['account_activity']['blocked_actions'] );
		$this->assertStringNotContainsString( 'обновите страницу', $body['account_activity']['text'], 'The table refreshes itself when the load ends (#1873).' );

		$this->card_store()->fail( $this->account_id, 'Маркетплейс не ответил' );
		$summary = $this->list_body()['messages']['summary'];
		$this->assertStringContainsString( 'Маркетплейс не ответил', $summary );
		$this->assertFalse( $this->list_body()['account_activity']['active'], 'A finished load, failed or not, ends the account line: that is what makes the screen read the list again.' );
	}

	public function test_the_summary_counts_the_archived_cards_apart_so_the_number_can_be_compared_with_the_cabinet_s(): void {
		$this->put_cards( [ $this->card( 'A-1' ), $this->card( 'B-2', '', [ 'archived' => true ] ), $this->card( 'C-3', '', [ 'archived' => true ] ) ] );

		$this->assertStringContainsString( 'В списке карточек: 3, из них в архиве — 2.', $this->list_body()['messages']['summary'] );

		$this->put_cards( [ $this->card( 'A-1' ), $this->card( 'B-2', '', [ 'archived' => true ] ) ] );
		$this->assertStringContainsString( 'В списке карточек: 2, из них в архиве — 1.', $this->list_body()['messages']['summary'], 'The count is _n()\'s plural, same for one archived card.' );

		$this->put_cards( [ $this->card( 'A-1' ) ] );
		$this->assertStringNotContainsString( 'в архиве', $this->list_body()['messages']['summary'], 'No archived card: the old sentence stands.' );
	}

	public function test_a_price_shows_in_the_currency_the_connection_check_read_and_in_the_shop_s_when_it_never_ran(): void {
		$seen = [];
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, array $args = [] ) use ( &$seen ): string {
				$seen[] = $args['currency'] ?? '';

				return $amount . ' ' . ( $args['currency'] ?? 'shop' );
			}
		);
		$this->put_cards( [ $this->card( 'A-1', '', [ 'price' => '2120.00' ] ) ] );
		$row = $this->rows_by_offer( $this->list_body() )[ $this->card_row_id( 'A-1' ) ];

		$this->assertSame( '2120.00 shop', $row['cells']['price'], 'An account that was never checked has no currency of its own.' );

		$this->accounts()->set_health( $this->account_id, new Health_Result( Health_Result::OK, 'ok', null, [ 'currency' => 'RUB' ] ) );
		$row = $this->rows_by_offer( $this->list_body() )[ $this->card_row_id( 'A-1' ) ];

		$this->assertSame( '2120.00 RUB', $row['cells']['price'] );
		$this->assertSame( [ '', 'RUB' ], $seen );
	}

	public function test_reading_the_table_never_calls_the_marketplace_nor_queues_anything(): void {
		$this->put_cards( [ $this->card( 'A-1' ) ] );
		$provider = $this->cards_provider();

		$provider->get_definition( $this->context() );
		$provider->query( $this->context(), [ 'page' => 1, 'per_page' => 20, 'search' => '', 'filters' => [] ] );
		$provider->get_activity( $this->context(), [ $this->card_row_id( 'A-1' ) ] );
		$provider->get_field_options( $this->context(), 'link_to', 'product', [ 'row_id' => $this->card_row_id( 'A-1' ), 'search' => 'кружка', 'cursor' => '' ] );
		$provider->preview( $this->context(), 'refresh_list', [], [] );

		$this->assertSame( [], $this->adapter->link_resolver()->reads, 'Not one read of the cabinet.' );
		$this->assertSame( [], $this->scheduler->actions, 'Not one job queued by a read or a preview.' );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ), 'A preview marks nothing.' );
	}

	public function test_a_table_for_an_account_that_is_gone_is_empty_not_an_error(): void {
		$result = $this->cards_provider()->query( [ 'account_id' => '99999' ], [ 'page' => 1, 'per_page' => 20, 'filters' => [] ] );

		$this->assertSame( [], $result['rows'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_the_ids_of_another_account_or_a_malformed_one_are_refused(): void {
		$provider = $this->cards_provider();
		$foreign  = '99999:' . substr( $this->card_row_id( 'A-1' ), strpos( $this->card_row_id( 'A-1' ), ':' ) + 1 );

		$this->assertTrue( $provider->authorize( 'list', $this->context(), [] ) );
		$this->assertTrue( $provider->authorize( 'link_to', $this->context(), [ $this->card_row_id( 'A-1' ) ] ) );
		$this->assertFalse( $provider->authorize( 'link_to', $this->context(), [ $foreign ] ), 'A row shown under one account is not usable under another.' );
		$this->assertFalse( $provider->authorize( 'link_to', $this->context(), [ '1:not-a-key' ] ) );
		$this->assertTrue( $provider->authorize( 'link_to', $this->context(), [ $this->card_row_id( 'never-listed' ) ] ), 'A card that has left the list is a stale list, with its reason, not an unknown id.' );
	}

	public function test_the_activity_is_the_account_line_only(): void {
		$this->card_store()->mark_refreshing( $this->account_id );

		$activity = $this->cards_provider()->get_activity( $this->context(), [] );

		$this->assertSame( [], $activity['rows'] );
		$this->assertSame( [], $activity['removed_ids'] );
		$this->assertTrue( $activity['account_activity']['active'] );
		$this->assertSame( Activity_Normalizer::account( $this->cards_definition(), $activity['account_activity'] )['blocked_actions'], [ 'refresh_list' ] );
	}

	public function test_refreshing_the_list_marks_it_and_queues_one_job_and_calls_nobody(): void {
		$provider = $this->cards_provider();

		$plan = Action_Response::preview( $this->cards_definition()->get_action( 'refresh_list' ), $provider->preview( $this->context(), 'refresh_list', [], [] ) );
		$this->assertTrue( $plan['can_apply'] );
		$this->assertSame( 'account', $plan['scope']['type'] );
		$this->assertSame( [], $this->scheduler->actions );

		$result = $provider->apply( $this->context(), 'refresh_list', [], [] );
		$body   = Action_Response::apply( $this->cards_definition(), $this->cards_definition()->get_action( 'refresh_list' ), $result );

		$this->assertSame( 'queued', $body['status'] );
		$this->assertTrue( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertCount( 1, $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->refresh_hook() ) );
		$this->assertSame( [], $this->adapter->link_resolver()->reads, 'The job reads the cabinet later; this request does not.' );
	}

	public function test_two_clicks_do_not_queue_two_reads(): void {
		$provider = $this->cards_provider();
		$provider->apply( $this->context(), 'refresh_list', [], [] );

		$plan   = $provider->preview( $this->context(), 'refresh_list', [], [] );
		$second = $provider->apply( $this->context(), 'refresh_list', [], [] );

		$this->assertSame( 0, $plan['eligible'] );
		$this->assertSame( 1, $second['skipped'] );
		$this->assertStringContainsString( 'уже загружается', $second['groups'][0]['reason'] );
		$this->assertStringNotContainsString( 'обновите страницу', $second['groups'][0]['reason'] );
		$this->assertCount( 1, $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->refresh_hook() ) );
	}

	public function test_a_refused_queue_reports_it_and_leaves_no_refreshing_mark(): void {
		$this->scheduler->zero_for[ ( new Link_Scheduler( $this->names() ) )->refresh_hook() ] = true;

		try {
			$this->cards_provider()->apply( $this->context(), 'refresh_list', [], [] );
			$this->fail( 'The refused queue must reach the merchant.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'Action Scheduler', $e->getMessage() );
		}

		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertStringContainsString( 'Action Scheduler', $this->card_store()->last_error( $this->account_id ) );
	}

	public function test_refreshing_and_linking_by_article_need_a_licence_in_force(): void {
		$this->licensed = false;
		$provider       = $this->cards_provider();

		foreach ( [ 'refresh_list', 'link_by_article' ] as $action ) {
			foreach ( [ 'preview', 'apply' ] as $method ) {
				try {
					$provider->$method( $this->context(), $action, [], [] );
					$this->fail( "$action $method must be refused" );
				} catch ( Action_State_Exception $e ) {
					$this->assertStringContainsString( 'Лицензия', $e->getMessage() );
				}
			}
		}

		$this->assertSame( [], $this->scheduler->actions );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
	}

	public function test_linking_by_article_is_the_same_account_wide_job_as_on_the_products_table(): void {
		$provider = $this->cards_provider();

		$plan = Action_Response::preview( $this->cards_definition()->get_action( 'link_by_article' ), $provider->preview( $this->context(), 'link_by_article', [], [ 'whole_shop' => true ] ) );
		$this->assertSame( 7, $plan['requested'] );
		$this->assertStringContainsString( '7 товаров', $plan['scope']['sentence'] );

		$done = $provider->apply( $this->context(), 'link_by_article', [], [ 'whole_shop' => true ] );

		$this->assertTrue( $done['queued'] );
		$this->assertCount( 1, $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() ) );
	}

	public function test_linking_by_article_asks_the_same_two_fields_as_on_the_products_table(): void {
		$actions = array_column( $this->cards_definition()->to_array()['bulk_actions'], null, 'id' );
		$fields  = array_column( $actions['link_by_article']['fields'], null, 'id' );

		$this->assertSame( [ 'whole_shop', 'category' ], array_keys( $fields ) );
		$this->assertTrue( $fields['whole_shop']['toggle'] );
		$this->assertSame( 'whole_shop', $fields['category']['hidden_when'] );
		$this->assertTrue( $fields['category']['options_source'] );
		$this->assertTrue( $fields['category']['searchable'] );
	}

	public function test_one_category_reaches_the_job_from_this_table_and_the_whole_shop_stays_the_default(): void {
		Functions\when( 'get_term' )->alias(
			static function ( int $term_id ) {
				if ( 7 !== $term_id ) {
					return null;
				}

				$term          = new \WP_Term();
				$term->term_id = 7;
				$term->name    = 'Обувь';

				return $term;
			}
		);
		$provider = $this->cards_provider();

		$plan = Action_Response::preview( $this->cards_definition()->get_action( 'link_by_article' ), $provider->preview( $this->context(), 'link_by_article', [], [ 'whole_shop' => false, 'category' => '7' ] ) );
		$this->assertSame( 3, $plan['requested'] );
		$this->assertStringContainsString( 'Категория «Обувь»', $plan['scope']['sentence'] );

		$provider->apply( $this->context(), 'link_by_article', [], [ 'whole_shop' => false, 'category' => '7' ] );
		$provider->apply( $this->context(), 'link_by_article', [], [ 'whole_shop' => true, 'category' => '7' ] );
		$provider->apply( $this->context(), 'link_by_article', [], [ 'whole_shop' => true ] );

		$this->assertSame( [ 7, 7, null, null ], $this->candidates_asked );
		$queued = $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() );
		$this->assertSame( [ $this->account_id, 7, 0 ], $queued[0]['args'] );
		$this->assertSame( [ $this->account_id, 0, 0 ], $queued[1]['args'] );
	}

	public function test_a_category_that_is_not_a_product_category_is_refused_on_the_category_field_here_too(): void {
		Functions\when( 'get_term' )->justReturn( null );

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$this->cards_provider()->$method( $this->context(), 'link_by_article', [], [ 'whole_shop' => false, 'category' => '99' ] );
				$this->fail( "$method accepted the category." );
			} catch ( Action_Input_Exception $e ) {
				$this->assertSame( [ 'category' ], array_keys( $e->get_errors() ) );
			}
		}

		$this->assertSame( [], $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() ) );
	}

	public function test_the_category_options_are_the_categories_not_the_products(): void {
		Functions\when( 'get_ancestors' )->justReturn( [] );
		Functions\when( 'get_terms' )->alias(
			static function (): array {
				$term          = new \WP_Term();
				$term->term_id = 7;
				$term->name    = 'Обувь';

				return [ $term ];
			}
		);

		$page = $this->cards_provider()->get_field_options( $this->context(), 'link_by_article', 'category', [ 'row_id' => '', 'search' => 'об', 'cursor' => '' ] );

		$this->assertSame( [ [ 'value' => '7', 'label' => 'Обувь' ] ], $page['options'] );
		$this->assertNull( $page['next_cursor'] );
	}

	public function test_an_action_for_a_switched_off_account_is_refused(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->expectException( Action_State_Exception::class );

		$this->cards_provider()->apply( $this->context(), 'refresh_list', [], [] );
	}

	public function test_the_product_options_are_the_shops_products_that_match_and_never_a_variable_parent(): void {
		$simple    = $this->add_product( [ 'id' => 41, 'sku' => 'MUG-1' ] );
		$variation = $this->add_product( [ 'id' => 43, 'parent_id' => 42, 'type' => 'variation' ] );
		$this->add_product( [ 'id' => 42, 'type' => 'variable' ] );
		$simple->shouldReceive( 'get_formatted_name' )->andReturn( 'Кружка (#41 – MUG-1)' );
		$variation->shouldReceive( 'get_formatted_name' )->andReturn( 'Кроссовки – 42 (#43 – SH-42)' );
		$this->found = [ 41, 42, 43, 999 ];

		$options = $this->cards_provider()->get_field_options( $this->context(), 'link_to', 'product', [ 'row_id' => '', 'search' => ' MUG ', 'cursor' => '' ] );

		$this->assertSame(
			[
				[ 'value' => '41', 'label' => 'Кружка (#41 – MUG-1)' ],
				[ 'value' => '43', 'label' => 'Кроссовки – 42 (#43 – SH-42)' ],
			],
			$options['options']
		);
		$this->assertNull( $options['next_cursor'] );
		$this->assertSame( [ [ 'MUG', 30 ] ], $this->searches, 'The text is trimmed and the answer bounded.' );
	}

	public function test_a_search_text_that_is_too_short_lists_nothing_and_asks_nobody(): void {
		$this->found = [ 41 ];

		foreach ( [ '', ' ', 'м' ] as $text ) {
			$options = $this->cards_provider()->get_field_options( $this->context(), 'link_to', 'product', [ 'row_id' => '', 'search' => $text, 'cursor' => '' ] );

			$this->assertSame( [], $options['options'], "'$text'" );
		}

		$this->assertSame( [], $this->searches );
	}

	public function test_a_product_the_user_may_not_read_is_not_offered(): void {
		$product = $this->add_product( [ 'id' => 41 ] );
		$product->shouldReceive( 'get_formatted_name' )->andReturn( 'Секрет (#41)' );
		$this->found = [ 41 ];
		\Brain\Monkey\Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'read_product' !== $capability;
			}
		);

		$options = $this->cards_provider()->get_field_options( $this->context(), 'link_to', 'product', [ 'row_id' => '', 'search' => 'секрет', 'cursor' => '' ] );

		$this->assertSame( [], $options['options'] );
	}
}

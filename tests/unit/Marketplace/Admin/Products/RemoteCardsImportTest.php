<?php
/**
 * «Импортировать» on the «Карточки на маркетплейсе» table (card I6): what the table declares, what the preview and the apply do, the
 * locked rows, the import note and the one-card actions that refuse several rows. Everything runs through the engine's own normalisers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Import_Plan;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Import_Plan
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows
 */
final class RemoteCardsImportTest extends Remote_Cards_Test_Case {

	protected function setUp(): void {
		parent::setUp();

		$this->importable = true;
	}

	/**
	 * The cards of the scenario: two new ones, one whose article a simple product carries, one whose article a variable parent
	 * carries, an archived one, one with no details, and one a link already holds.
	 *
	 * @return void
	 */
	private function put_scenario(): void {
		$this->add_link( $this->add_product( [ 'id' => 41, 'sku' => 'HAVE-1' ] ), Link_Record::STATE_CREATED, [ 'offer_id' => 'HAVE-1' ] );
		$this->sku_kinds = [
			'LINK-1' => Remote_Cards_Import_Plan::SKU_PLAIN,
			'VAR-1'  => Remote_Cards_Import_Plan::SKU_VARIABLE,
			'CUT-1'  => Remote_Cards_Import_Plan::SKU_MARKED,
		];

		$this->put_snapshot(
			[
				$this->card( 'NEW-1' ),
				$this->card( 'NEW-2' ),
				$this->card( 'CUT-1' ),
				$this->card( 'LINK-1' ),
				$this->card( 'VAR-1' ),
				$this->card( 'OLD-1', '', [ 'archived' => true, 'state' => 'archived' ] ),
				$this->card( 'BAD-1', '', [ 'details_ok' => false ] ),
				$this->card( 'HAVE-1' ),
			]
		);
	}

	/**
	 * @param string[] $offer_ids Articles.
	 * @return string[]
	 */
	private function ids( array $offer_ids ): array {
		return array_map( [ $this, 'card_row_id' ], $offer_ids );
	}

	/**
	 * @param string   $action    Action id.
	 * @param string[] $offer_ids Articles.
	 * @return array<string,mixed> The preview as the engine returns it.
	 */
	private function preview( string $action, array $offer_ids ): array {
		$result = $this->cards_provider()->preview( $this->context(), $action, $this->ids( $offer_ids ), [] );
		$plan   = Action_Response::preview( $this->cards_definition()->get_action( $action ), $result );

		$this->assertIsArray( $plan, 'The engine accepts the plan.' );

		return $plan;
	}

	/**
	 * @param string   $action    Action id.
	 * @param string[] $offer_ids Articles.
	 * @return array<string,mixed> The apply as the engine returns it.
	 */
	private function apply( string $action, array $offer_ids ): array {
		$result = $this->cards_provider()->apply( $this->context(), $action, $this->ids( $offer_ids ), [] );
		$body   = Action_Response::apply( $this->cards_definition(), $this->cards_definition()->get_action( $action ), $result );

		$this->assertIsArray( $body, 'The engine accepts the answer.' );

		return $body;
	}

	/**
	 * @return array<string,array<string,mixed>> The listed rows by row id, every filter cleared.
	 */
	private function rows(): array {
		$query  = [
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'filters'  => [],
		];
		$result = $this->cards_provider()->query( $this->context(), $query );
		$body   = Row_Normalizer::page( $this->cards_definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return array_column( $body['rows'], null, 'id' );
	}

	/**
	 * @param array<string,mixed> $plan A preview.
	 * @return array<string,int> Skipped count by reason text.
	 */
	private function groups( array $plan ): array {
		return array_column( $plan['groups'], 'count', 'reason' );
	}

	/**
	 * @return string[] Articles of the queued import actions, in order.
	 */
	private function queued_offers(): array {
		$offers = [];

		foreach ( $this->scheduler->of( $this->import_scheduler()->hook() ) as $action ) {
			$offers = array_merge( $offers, $action['args'][1] );
		}

		return $offers;
	}

	public function test_a_table_whose_adapter_cannot_import_has_no_import_action_and_no_selection(): void {
		$this->importable = false;
		$this->put_snapshot( [ $this->card( 'NEW-1' ) ] );

		$definition = $this->cards_definition()->to_array();

		$this->assertNotContains( 'import', array_column( $definition['row_actions'], 'id' ) );
		$this->assertNotContains( 'import_selected', array_column( $definition['bulk_actions'], 'id' ) );
		$this->assertSame( 1, $definition['pagination']['max_selection'] );
		$this->assertFalse( array_values( $this->rows() )[0]['selectable'] );
	}

	public function test_an_importing_table_declares_the_action_as_a_row_and_a_bulk_action_and_selects_a_page(): void {
		$this->put_snapshot( [ $this->card( 'NEW-1' ) ] );

		$definition = $this->cards_definition();
		$row        = $definition->get_action( Remote_Cards_Definition::IMPORT );
		$bulk       = $definition->get_action( Remote_Cards_Definition::IMPORT_SELECTED );

		foreach ( [ $row, $bulk ] as $action ) {
			$this->assertSame( 'Импортировать', $action['label'] );
			$this->assertSame( 'mutate', $action['effect'] );
			$this->assertSame( 'dialog', $action['interaction'] );
			$this->assertSame( 'selection', $action['scope'] );
			$this->assertSame( 'manage_woocommerce', $action['capability'] );
		}

		$this->assertContains( 'import', $definition->get_row_action_ids() );
		$this->assertSame( 100, $definition->get_pagination()['max_selection'] );

		$listed = array_values( $this->rows() )[0];

		$this->assertTrue( $listed['selectable'] );
		$this->assertSame( [ 'import', 'link_to' ], array_column( $listed['actions'], 'id' ) );
		$this->assertTrue( $listed['actions'][0]['enabled'] );
	}

	public function test_the_preview_groups_the_selection_and_writes_and_calls_nothing(): void {
		$this->put_scenario();

		$plan = $this->preview( 'import_selected', [ 'NEW-1', 'NEW-2', 'CUT-1', 'LINK-1', 'VAR-1', 'OLD-1', 'BAD-1', 'HAVE-1' ] );

		$this->assertSame( [ 8, 4, 4 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertTrue( $plan['can_apply'] );
		$this->assertStringContainsString( 'Будет создано товаров: 3.', $plan['scope']['sentence'] );
		$this->assertStringContainsString( 'свяжем: 1', $plan['scope']['sentence'] );
		$this->assertSame(
			[
				'Уже на сайте'                                                                    => 1,
				'Товар с таким артикулом — вариативный: выберите вариант через «Связать с…»' => 1,
				'Карточка в архиве — не импортируем'                                              => 1,
				'Нет полных данных — обновите список'                                             => 1,
			],
			$this->groups( $plan )
		);
		$this->assertSame( [], $this->scheduler->actions, 'A preview queues nothing.' );
		$this->assertSame( [], $this->adapter->link_resolver()->reads, 'A preview calls no marketplace.' );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'NEW-1' ), 'A preview writes no link.' );
	}

	/**
	 * A snapshot card of the colour group.
	 *
	 * @param string              $offer_id Article.
	 * @param array<string,mixed> $more     Keys to override.
	 * @return array<string,mixed>
	 */
	private function colour( string $offer_id, array $more = [] ): array {
		$group = [
			'key'  => 'model-7',
			'size' => 3,
		];

		return $this->card( $offer_id, '', array_merge( [ 'group' => $group ], $more ) );
	}

	public function test_the_preview_counts_the_cards_of_one_group_as_one_product(): void {
		$this->put_snapshot( [ $this->colour( 'RED' ), $this->colour( 'BLACK' ), $this->colour( 'GREEN' ), $this->card( 'SOLO' ) ] );

		$plan = $this->preview( 'import_selected', [ 'RED', 'BLACK', 'GREEN' ] );

		$this->assertSame( [ 3, 3, 0 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ], 'three cards are imported' );
		$this->assertStringContainsString( 'Будет создано товаров: 1.', $plan['scope']['sentence'], 'as one product' );
		$this->assertStringContainsString( 'Карточки одной группы станут вариантами одного товара.', $plan['scope']['sentence'] );

		$mixed = $this->preview( 'import_selected', [ 'RED', 'SOLO', 'BLACK', 'GREEN' ] );

		$this->assertStringContainsString( 'Будет создано товаров: 2.', $mixed['scope']['sentence'], 'a card with no group is a product of its own' );

		$one = $this->preview( 'import', [ 'RED' ] );

		$this->assertStringContainsString( 'Будет создано товаров: 1.', $one['scope']['sentence'] );
		$this->assertStringNotContainsString( 'вариантами', $one['scope']['sentence'], 'one card of a group is simply one product' );

		$body = $this->apply( 'import_selected', [ 'RED', 'BLACK', 'GREEN' ] );

		$this->assertSame( 3, $body['accepted'] );
		$this->assertSame( [ 'RED', 'BLACK', 'GREEN' ], $this->queued_offers(), 'every card of the group is queued: the job assembles them' );
	}

	public function test_a_card_whose_group_has_a_product_on_the_site_adds_no_product(): void {
		$this->group_states[ $this->account_id . ':model-7' ] = Group_Assembler::READY;
		$this->put_snapshot( [ $this->colour( 'BLACK' ), $this->colour( 'GREEN' ), $this->card( 'SOLO' ) ] );

		$plan = $this->preview( 'import_selected', [ 'BLACK', 'GREEN', 'SOLO' ] );

		$this->assertSame( 3, $plan['eligible'] );
		$this->assertStringContainsString( 'Будет создано товаров: 1.', $plan['scope']['sentence'], 'only the card with no group makes a product' );
		$this->assertStringContainsString( 'Станут вариантами уже импортированных товаров: 2.', $plan['scope']['sentence'] );

		$joining = $this->preview( 'import', [ 'BLACK' ] );

		$this->assertTrue( $joining['can_apply'] );
		$this->assertStringNotContainsString( 'Будет создано', $joining['scope']['sentence'] );
		$this->assertStringContainsString( 'Станут вариантами уже импортированных товаров: 1.', $joining['scope']['sentence'] );
	}

	public function test_a_card_whose_group_is_half_made_is_left_out_unless_the_half_made_card_is_selected_too(): void {
		$this->group_states[ $this->account_id . ':model-7' ] = Group_Assembler::BUILDING;
		$this->sku_kinds['RED']                               = Remote_Cards_Import_Plan::SKU_MARKED;
		$this->put_snapshot( [ $this->colour( 'RED' ), $this->colour( 'BLACK' ), $this->colour( 'GREEN' ) ] );

		$alone = $this->preview( 'import_selected', [ 'BLACK', 'GREEN' ] );

		$this->assertSame( [ 2, 0, 2 ], [ $alone['requested'], $alone['eligible'], $alone['skipped'] ], 'the job would skip both' );
		$this->assertFalse( $alone['can_apply'] );
		$this->assertSame( [ 'Импорт другой карточки этой группы не завершён — сначала импортируйте её' => 2 ], $this->groups( $alone ) );

		$together = $this->preview( 'import_selected', [ 'BLACK', 'GREEN', 'RED' ] );

		$this->assertSame( [ 3, 3, 0 ], [ $together['requested'], $together['eligible'], $together['skipped'] ] );
		$this->assertStringContainsString( 'Будет создано товаров: 1.', $together['scope']['sentence'] );
		$this->assertStringContainsString( 'Карточки одной группы станут вариантами одного товара.', $together['scope']['sentence'] );

		$this->apply( 'import_selected', [ 'BLACK', 'GREEN', 'RED' ] );

		$this->assertSame( [ 'RED', 'BLACK', 'GREEN' ], $this->queued_offers(), 'the half-made card is queued first, so the job finishes it before the others join' );
	}

	public function test_only_the_cards_the_import_takes_are_counted_in_a_group(): void {
		$this->add_link( $this->add_product( [ 'id' => 41, 'sku' => 'RED' ] ), Link_Record::STATE_CREATED, [ 'offer_id' => 'RED' ] );
		$this->put_snapshot(
			[
				$this->colour( 'RED' ),
				$this->colour( 'BLACK' ),
				$this->colour( 'OLD', [ 'archived' => true, 'state' => 'archived' ] ),
				$this->colour( 'CUP', [ 'group' => [ 'key' => 'model-8', 'size' => 2 ] ] ),
			]
		);

		$plan = $this->preview( 'import_selected', [ 'RED', 'BLACK', 'OLD', 'CUP' ] );

		$this->assertSame( [ 4, 2, 2 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertStringContainsString( 'Будет создано товаров: 2.', $plan['scope']['sentence'], 'the black card and the card of another group: one product each' );
		$this->assertStringNotContainsString( 'вариантами', $plan['scope']['sentence'] );
		$this->assertSame(
			[
				'Уже на сайте'                       => 1,
				'Карточка в архиве — не импортируем' => 1,
			],
			$this->groups( $plan )
		);
	}

	public function test_a_card_the_list_no_longer_holds_is_left_out_with_its_reason(): void {
		$this->put_snapshot( [ $this->card( 'NEW-1' ) ] );

		$plan = $this->preview( 'import', [ 'GONE-9' ] );

		$this->assertSame( [ 1, 0, 1 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertFalse( $plan['can_apply'] );
		$this->assertSame( [ 'Нет в загруженном списке — обновите список' => 1 ], $this->groups( $plan ) );
	}

	public function test_apply_queues_the_eligible_cards_replans_and_answers_queued(): void {
		$this->put_scenario();

		$preview = $this->preview( 'import_selected', [ 'NEW-1', 'NEW-2', 'LINK-1' ] );
		$this->assertSame( 3, $preview['eligible'] );

		// Between the preview and the apply a product of the shop takes the article of NEW-2: a variable parent.
		$this->sku_kinds['NEW-2'] = Remote_Cards_Import_Plan::SKU_VARIABLE;

		$body = $this->apply( 'import_selected', [ 'NEW-1', 'NEW-2', 'LINK-1', 'VAR-1', 'HAVE-1' ] );

		$this->assertSame( 'queued', $body['status'] );
		$this->assertSame( [ 5, 2, 3, 0 ], [ $body['requested'], $body['accepted'], $body['skipped'], $body['failed'] ] );
		$this->assertSame( [ 'NEW-1', 'LINK-1' ], $this->queued_offers() );
		$this->assertStringContainsString( 'в очередь', $body['messages']['action_status_queued'] );
		$this->assertSame( [ true, true ], array_column( array_column( $body['rows'], 'activity' ), 'locked' ), 'The queued rows come back locked.' );
	}

	public function test_a_second_apply_does_not_queue_the_cards_that_are_already_being_imported(): void {
		$this->put_scenario();

		$this->apply( 'import_selected', [ 'NEW-1', 'NEW-2' ] );
		$again = $this->apply( 'import_selected', [ 'NEW-1', 'NEW-2' ] );

		$this->assertSame( [ 0, 2 ], [ $again['accepted'], $again['skipped'] ] );
		$this->assertSame( [ 'Уже импортируется — дождитесь результата' => 2 ], $this->groups( $again ) );
		$this->assertCount( 1, $this->scheduler->of( $this->import_scheduler()->hook() ) );
	}

	public function test_an_apply_with_nothing_eligible_queues_nothing(): void {
		$this->put_scenario();

		$body = $this->apply( 'import', [ 'OLD-1' ] );

		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( [], $this->scheduler->actions );
	}

	public function test_a_refused_queue_reaches_the_merchant(): void {
		$this->put_scenario();
		$this->scheduler->zero_for[ $this->import_scheduler()->hook() ] = true;

		$this->expectException( Action_State_Exception::class );
		$this->expectExceptionMessage( 'Action Scheduler' );

		$this->cards_provider()->apply( $this->context(), 'import', $this->ids( [ 'NEW-1' ] ), [] );
	}

	public function test_importing_needs_a_licence_in_force_at_preview_and_at_apply(): void {
		$this->put_scenario();
		$this->licensed = false;

		foreach ( [ 'import', 'import_selected' ] as $action ) {
			foreach ( [ 'preview', 'apply' ] as $method ) {
				try {
					$this->cards_provider()->$method( $this->context(), $action, $this->ids( [ 'NEW-1' ] ), [] );
					$this->fail( "$action $method must be refused" );
				} catch ( Action_State_Exception $e ) {
					$this->assertStringContainsString( 'Лицензия', $e->getMessage() );
					$this->assertStringContainsString( 'импортировать карточки', $e->getMessage() );
				}
			}
		}

		$this->assertSame( [], $this->scheduler->actions );
	}

	public function test_a_row_being_imported_is_locked_and_blocks_its_import_and_link_to_but_not_the_bulk_action(): void {
		$this->put_scenario();
		$this->import_scheduler()->import( $this->account_id, [ 'NEW-1' ] );

		$rows   = $this->rows();
		$locked = $rows[ $this->card_row_id( 'NEW-1' ) ];
		$other  = $rows[ $this->card_row_id( 'NEW-2' ) ];

		$this->assertTrue( $locked['activity']['locked'] );
		$this->assertSame( [ 'import', 'link_to' ], $locked['activity']['blocked_actions'] );
		$this->assertSame( 'Импортируется', $locked['activity']['processes'][0]['label'] );
		$this->assertSame( [ false, false ], array_column( $locked['actions'], 'enabled' ) );
		$this->assertFalse( $other['activity']['locked'] );
		$this->assertSame( [ true, true ], array_column( $other['actions'], 'enabled' ) );
	}

	public function test_the_account_line_says_the_cards_are_being_imported(): void {
		$this->put_scenario();

		$this->assertFalse( $this->cards_provider()->get_activity( $this->context(), [] )['account_activity']['active'] );

		$this->import_scheduler()->import( $this->account_id, [ 'NEW-1' ] );
		$line = $this->cards_provider()->get_activity( $this->context(), [] )['account_activity'];

		$this->assertTrue( $line['active'] );
		$this->assertStringContainsString( 'Импортируем карточки', $line['text'] );
	}

	public function test_the_activity_returns_the_current_rows_and_the_card_moves_to_on_the_site_by_itself(): void {
		$this->put_scenario();
		$this->import_scheduler()->import( $this->account_id, [ 'NEW-1' ] );

		$during = $this->cards_provider()->get_activity( $this->context(), $this->ids( [ 'NEW-1' ] ) )['rows'][0];
		$this->assertTrue( $during['activity']['locked'] );

		// The job finished: the link exists, the action is gone from the queue.
		$this->scheduler->actions = [];
		$this->add_link( $this->add_product( [ 'id' => 77, 'sku' => 'NEW-1' ] ), Link_Record::STATE_CREATED, [ 'offer_id' => 'NEW-1' ] );

		$after = $this->cards_provider()->get_activity( $this->context(), $this->ids( [ 'NEW-1' ] ) )['rows'][0];

		$this->assertFalse( $after['activity']['locked'] );
		$this->assertSame( 'link', $after['cells']['on_site'][0]['type'] );
		$this->assertSame( 'Товар 77', $after['cells']['on_site'][0]['value']['label'] );
		$this->assertFalse( $after['actions'][0]['enabled'], 'A card on the site is not imported again.' );
	}

	public function test_the_note_detail_follows_the_logging_switch_in_both_directions_for_the_same_stored_note(): void {
		$this->put_scenario();
		$this->reports()->set_import_note( $this->account_id, 'NEW-1', 'Не удалось сохранить товар.', 'WC_Data_Exception: duplicate sku' );
		$id = $this->card_row_id( 'NEW-1' );

		$text = static function ( array $row ): array {
			return array_column( $row['cells']['state'], 'value' );
		};

		$off = $text( $this->rows()[ $id ] );
		$this->assertContains( 'Не удалось сохранить товар.', $off );
		$this->assertNotContains( 'подсказка: WC_Data_Exception: duplicate sku', $off );

		$this->options['woodev_test_advanced_logging'] = 'yes';
		$on                                            = $text( $this->rows()[ $id ] );
		$this->assertContains( 'Не удалось сохранить товар.', $on );
		$this->assertContains( 'подсказка: WC_Data_Exception: duplicate sku', $on );

		$this->options['woodev_test_advanced_logging'] = 'no';
		$this->assertSame( $off, $text( $this->rows()[ $id ] ), 'Turned off again, the same note loses its detail.' );
		$this->assertSame( 'WC_Data_Exception: duplicate sku', $this->reports()->import_notes( $this->account_id )[0]['detail'], 'The stored note never changed.' );
	}

	public function test_listing_and_polling_never_call_the_marketplace_or_queue_anything(): void {
		$this->put_scenario();

		$this->rows();
		$this->cards_provider()->get_activity( $this->context(), $this->ids( [ 'NEW-1', 'LINK-1' ] ) );
		$this->preview( 'import_selected', [ 'NEW-1' ] );

		$this->assertSame( [], $this->adapter->link_resolver()->reads );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function one_card_actions(): array {
		return [
			'link_to'      => [ 'link_to', [ 'product' => '41' ] ],
			'near_confirm' => [ 'near_confirm', [] ],
			'near_dismiss' => [ 'near_dismiss', [] ],
		];
	}

	/**
	 * @dataProvider one_card_actions
	 *
	 * @param string              $action  Action id.
	 * @param array<string,mixed> $payload Fields.
	 * @return void
	 */
	public function test_the_one_card_actions_refuse_several_rows_at_preview_and_at_apply( string $action, array $payload ): void {
		$this->put_scenario();
		$this->add_product( [ 'id' => 42, 'sku' => 'abc-001' ] );
		$this->reports()->replace_near_matches( $this->account_id, [ $this->near_entry( 42, 'ABC-001', 'abc-001' ) ] );
		$ids = [ $this->card_row_id( 'NEW-1' ), $this->card_row_id( 'NEW-2' ) ];

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$this->cards_provider()->$method( $this->context(), $action, $ids, $payload );
				$this->fail( "$action $method must refuse several rows" );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsString( 'одной карточки', $e->getMessage() );
			}
		}

		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'NEW-1' ), 'Not the first row either.' );
		$this->assertCount( 1, $this->reports()->near_matches( $this->account_id ), 'The report is untouched.' );
	}

	public function test_link_to_refuses_a_card_that_is_being_imported(): void {
		$this->put_scenario();
		$this->add_product( [ 'id' => 43, 'sku' => 'X-43' ] );
		$this->import_scheduler()->import( $this->account_id, [ 'NEW-1' ] );

		$result = $this->cards_provider()->preview( $this->context(), 'link_to', $this->ids( [ 'NEW-1' ] ), [ 'product' => '43' ] );

		$this->assertSame( 0, $result['eligible'] );
		$this->assertStringContainsString( 'импортируется', $result['groups'][0]['reason'] );
	}

	public function test_linking_a_card_by_hand_clears_its_stale_import_note(): void {
		$this->put_scenario();
		$this->add_product( [ 'id' => 44, 'sku' => 'X-44' ] );
		$this->reports()->set_import_note( $this->account_id, 'NEW-1', 'Не удалось сохранить товар.' );

		$this->cards_provider()->apply( $this->context(), 'link_to', $this->ids( [ 'NEW-1' ] ), [ 'product' => '44' ] );

		$this->assertSame( [], $this->reports()->import_notes( $this->account_id ) );
	}
}

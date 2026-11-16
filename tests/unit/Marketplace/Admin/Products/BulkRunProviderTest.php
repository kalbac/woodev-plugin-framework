<?php
/**
 * «Все по фильтру» on the «Товары сайта» table: the matching preview and apply, the account line of a run, «Остановить» and «Подробнее».
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Line;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Worker;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Scripted_Bulk_Run_Lock;
use Woodev\Tests\Unit\Marketplace\Support\Scripted_Bulk_Run_Worker;

require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Line
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Activity
 */
final class BulkRunProviderTest extends Products_Test_Case {
	private const HOOK = 'woodev_fake_seller_bulk_run';

	/**
	 * @var Bulk_Run_Store
	 */
	private $runs;

	/**
	 * @var Scripted_Bulk_Run_Lock
	 */
	private $lock;

	/**
	 * @var Bulk_Run_Runner
	 */
	private $runner;

	/**
	 * @var Site_Products_Provider
	 */
	private $matching;

	/**
	 * @var int
	 */
	private $tokens = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->tokens = 0;
		$this->runs   = new Bulk_Run_Store( $this->db, Sqlite_Tables::table( 'bulk_runs' ), $this->clock() );
		$this->lock   = new Scripted_Bulk_Run_Lock();
		$this->runner = new Bulk_Run_Runner(
			$this->runs,
			$this->lock,
			new Bulk_Run_Scheduler( $this->names() ),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			new Scripted_Bulk_Run_Worker(),
			null,
			null,
			function (): string {
				return str_pad( (string) ++$this->tokens, 32, '0', STR_PAD_LEFT );
			}
		);

		Functions\when( 'get_terms' )->justReturn( [ (object) [ 'term_id' => 7, 'name' => 'Обувь' ] ] );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.test/wp-admin/post.php' );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_set_current_user' )->justReturn( null );
		Functions\when( 'admin_url' )->returnArg( 1 );

		$this->matching = $this->matching_provider();
	}

	/**
	 * The provider with the bulk run wired in.
	 *
	 * @return Site_Products_Provider
	 */
	private function matching_provider(): Site_Products_Provider {
		$reader = $this->reader();

		return new Site_Products_Provider(
			$this->accounts(),
			$reader,
			new Site_Products_Selection( $reader ),
			new \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Activity( $this->ledger(), $this->db, Sqlite_Tables::table( 'sync_state' ), Sqlite_Tables::table( 'accounts' ), $this->clock(), $this->runs, [ $this->runner, 'recover' ] ),
			$this->bulk(),
			new Warehouse_Options( new Wpdb_Catalog_Cache( $this->db, Sqlite_Tables::table( 'catalog_cache' ) ) ),
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			[
				'adapter_id'  => Sqlite_Tables::MARKETPLACE_ID,
				'marketplace' => 'Тестмаркет',
				'withheld'    => [],
			],
			$this->runner,
			$this->runs
		);
	}

	/**
	 * The query the engine hands over.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array{search:string,filters:array<string,mixed>}
	 */
	private function query( array $filters = [] ): array {
		return [
			'search'  => '',
			'filters' => $filters,
		];
	}

	/**
	 * @return Bulk_Run
	 */
	private function current(): Bulk_Run {
		$run = $this->runs->find( $this->account_id );
		$this->assertNotNull( $run );

		return $run;
	}

	/**
	 * Gives the engine the provider's answer, as the REST handler does, and checks it accepts it.
	 *
	 * @param string              $action Action id.
	 * @param array<string,mixed> $result Preview answer.
	 * @return array<string,mixed>
	 */
	private function through_preview( string $action, array $result ): array {
		$definition = Records_Definition::from_array( $this->matching->get_definition( $this->context() ) );
		$body       = Action_Response::preview( $definition->get_action( $action ), $result );

		$this->assertIsArray( $body, 'The engine accepts the preview of ' . $action . '.' );

		return $body;
	}

	public function test_the_provider_serves_the_matching_target_and_every_selection_action_declares_it(): void {
		$definition = Records_Definition::from_array( $this->matching->get_definition( $this->context() ) );

		$this->assertInstanceOf( \Woodev\Framework\Admin\Records\Applies_To_Matching::class, $this->matching );

		foreach ( Site_Products_Bulk::ids() as $action ) {
			$declared = $definition->get_action( $action );

			$this->assertSame( Site_Products_Bulk::LINK_BY_ARTICLE !== $action, $declared['all_matching'], $action . ' — «Связать по артикулу» is an account action, not part of A.' );
		}

		foreach ( [ Site_Products_Bulk::RUN_STOP, Site_Products_Bulk::RUN_REPORT ] as $button ) {
			$this->assertSame( 'account', $definition->get_action( $button )['scope'] );
			$this->assertSame( [ 'hidden' ], array_column( $definition->get_action( $button )['fields'], 'type' ) );
		}
	}

	public function test_the_matching_preview_is_one_count_and_one_sentence_and_writes_nothing(): void {
		$before = count( $this->db->statements );

		$result = $this->matching->preview_matching( $this->context(), Site_Products_Bulk::SYNC_STOCK_ON, $this->query(), [] );
		$body   = $this->through_preview( Site_Products_Bulk::SYNC_STOCK_ON, $result );

		$this->assertSame( 3, $body['requested'], 'The three listed products of the shop: the table\'s own unit.' );
		$this->assertSame( 3, $body['eligible'] );
		$this->assertStringStartsWith( 'Будет затронуто 3 товара по фильтру.', $body['scope']['sentence'] );
		$this->assertStringContainsString( 'Ход работы — в строке аккаунта над таблицей.', $body['scope']['sentence'] );
		$this->assertNull( $this->runs->find( $this->account_id ), 'No run is stored by a preview.' );

		$writes = array_filter(
			array_slice( $this->db->statements, $before ),
			static function ( string $sql ): bool {
				return 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $sql );
			}
		);
		$this->assertSame( [], array_values( $writes ) );
	}

	public function test_the_matching_preview_counts_the_products_under_the_filter_only(): void {
		$result = $this->matching->preview_matching( $this->context(), Site_Products_Bulk::RESET, $this->query( [ 'category' => 7 ] ), [] );

		$this->assertSame( 1, $result['requested'], 'Only the variable product is in the category.' );
	}

	public function test_every_matching_sentence_fits_the_engines_limit_and_names_the_marketplace_where_it_must(): void {
		foreach ( Site_Products_Bulk::ids() as $action ) {
			if ( Site_Products_Bulk::LINK_BY_ARTICLE === $action ) {
				continue;
			}

			$result = $this->matching->preview_matching( $this->context(), $action, $this->query(), $action === Site_Products_Bulk::SET_WAREHOUSE ? [ 'warehouse' => '0' ] : [] );

			$this->assertLessThanOrEqual( Records_Definition::MAX_STRING_LENGTH, mb_strlen( $result['scope_sentence'] ), $action );
			$this->through_preview( $action, $result );
		}

		$archive = $this->matching->preview_matching( $this->context(), Site_Products_Bulk::ARCHIVE, $this->query(), [] );
		$detach  = $this->matching->preview_matching( $this->context(), Site_Products_Bulk::DETACH, $this->query(), [] );

		$this->assertStringContainsString( 'Тестмаркет', $archive['scope_sentence'] );
		$this->assertSame( 'Архивировать 3 товара', $archive['confirm_label'] );
		$this->assertSame( 'Снять связь у 3 товара', $detach['confirm_label'] );
	}

	public function test_the_apply_stores_a_run_with_the_filter_and_no_ids_and_queues_the_first_tick(): void {
		$answer = $this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query( [ 'state' => 'unlinked', 'q' => 'Сумк' ] ), [] );

		$run = $this->current();
		$this->assertTrue( $run->is_running() );
		$this->assertSame( Site_Products_Bulk::SEND_STOCK, $run->action() );
		$this->assertSame( 3, $run->user_id() );
		$this->assertSame( [ 'search' => '', 'filters' => [ 'state' => 'unlinked', 'q' => 'Сумк' ] ], $run->filter() );
		$this->assertSame( 1, $run->counts()['products_total'], 'Only the bag is unlinked and matches the search.' );
		$this->assertStringNotContainsString( 'ids', (string) $this->db->get_var( 'SELECT filter || payload FROM ' . Sqlite_Tables::table( 'bulk_runs' ) ), 'A list of ids is never stored.' );

		$this->assertSame( [ [ $this->account_id, $run->run_id() ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ) );
		$this->assertSame( [ 'id' => $run->run_id(), 'products_total' => 1 ], $answer['run'] );
		$this->assertTrue( $answer['queued'] );
	}

	public function test_the_apply_answer_keeps_the_run_block_through_the_engine(): void {
		$definition = Records_Definition::from_array( $this->matching->get_definition( $this->context() ) );
		$answer     = $this->matching->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );

		$body = Action_Response::apply( $definition, $definition->get_action( Site_Products_Bulk::RESET ), $answer );

		$this->assertIsArray( $body );
		$this->assertSame( 'queued', $body['status'] );
		$this->assertSame( $this->current()->run_id(), $body['run']['id'] );
		$this->assertSame( 3, $body['run']['products_total'] );
		$this->assertSame( 3, $body['accepted'] );
	}

	public function test_a_second_start_while_a_run_is_going_is_refused_with_the_name_of_the_running_action(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );

		foreach ( [ 'preview_matching', 'apply_matching' ] as $method ) {
			try {
				$this->matching->$method( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );
				$this->fail( $method . ' must refuse.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertSame( 'Уже выполняется «Отправить остатки» по фильтру — дождитесь окончания или остановите его.', $e->getMessage() );
			}
		}

		$this->assertSame( Site_Products_Bulk::SEND_STOCK, $this->current()->action(), 'The running one is untouched.' );
	}

	public function test_a_run_of_the_other_table_is_named_by_its_label_not_by_its_id(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'bulk_runs' ) . " SET action = 'import_selected'" );

		foreach ( [ 'preview_matching', 'apply_matching' ] as $method ) {
			try {
				$this->matching->$method( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );
				$this->fail( $method . ' must refuse.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertSame( 'Уже выполняется «Импортировать» по фильтру — дождитесь окончания или остановите его.', $e->getMessage() );
			}
		}
	}

	public function test_a_start_right_after_a_finished_run_is_accepted(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$this->runner->stop( $this->account_id, $this->current()->run_id() );

		$answer = $this->matching->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );

		$this->assertSame( Site_Products_Bulk::RESET, $this->current()->action() );
		$this->assertSame( $this->current()->run_id(), $answer['run']['id'] );
	}

	public function test_a_filter_that_matches_nothing_starts_nothing(): void {
		$this->expectException( Action_State_Exception::class );

		try {
			$this->matching->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query( [ 'q' => 'такого нет' ] ), [] );
		} finally {
			$this->assertNull( $this->runs->find( $this->account_id ) );
		}
	}

	public function test_a_lapsed_licence_refuses_the_actions_that_write_to_the_marketplace_and_not_the_others(): void {
		$this->licensed = false;

		foreach ( [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::SEND_STOCK, Site_Products_Bulk::SEND_PRICE, Site_Products_Bulk::ARCHIVE ] as $action ) {
			try {
				$this->matching->apply_matching( $this->context(), $action, $this->query(), [] );
				$this->fail( $action . ' must be refused.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$this->assertNull( $this->runs->find( $this->account_id ) );
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SYNC_STOCK_OFF, $this->query(), [] );
		$this->assertTrue( $this->current()->is_running() );
	}

	public function test_a_warehouse_that_is_not_the_accounts_is_refused_and_the_default_is_stored_as_zero(): void {
		try {
			$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SET_WAREHOUSE, $this->query(), [ 'warehouse' => '999' ] );
			$this->fail( 'A foreign warehouse must be refused.' );
		} catch ( Action_Input_Exception $e ) {
			$this->assertNull( $this->runs->find( $this->account_id ) );
		}

		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SET_WAREHOUSE, $this->query(), [ 'warehouse' => '0' ] );
		$this->assertSame( [ 'warehouse' => 0 ], $this->current()->payload() );
	}

	public function test_a_provider_without_the_run_services_refuses_the_mode(): void {
		$reader = $this->reader();
		$plain  = $this->provider();

		$this->assertInstanceOf( \Woodev\Framework\Admin\Records\Applies_To_Matching::class, $plain );
		$this->expectException( Action_State_Exception::class );

		$plain->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );
		unset( $reader );
	}

	public function test_the_account_line_of_a_running_run_names_the_action_both_units_and_offers_stop_and_details(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$run_id = $this->current()->run_id();

		$this->runs->take_tick( $this->account_id, $run_id, 'tick-a' );
		$this->runs->save( $this->account_id, $run_id, 'tick-a', [ 'parent' => 10, 'child' => 0 ], [ 'products_total' => 3, 'products_done' => 2, 'offers_accepted' => 5, 'offers_skipped' => 1, 'offers_failed' => 1 ], [] );

		$definition = Records_Definition::from_array( $this->matching->get_definition( $this->context() ) );
		$line       = Activity_Normalizer::account( $definition, $this->matching->query( $this->context(), [ 'page' => 1, 'per_page' => 20 ] )['account_activity'] );

		$this->assertTrue( $line['active'] );
		$this->assertSame( 'Выполняется «Отправить остатки» по фильтру: товаров 2 из ~3 (обработано вариантов и простых товаров: 7).', $line['text'] );
		$this->assertSame( [ Site_Products_Bulk::RUN_STOP, Site_Products_Bulk::RUN_REPORT ], array_column( $line['actions'], 'action' ) );
		$this->assertSame( [ 'run_id' => $run_id ], $line['actions'][0]['payload'], 'The stop carries the run on the line.' );
	}

	public function test_a_run_that_is_being_stopped_offers_no_second_stop(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$this->runs->request_cancel( $this->account_id, $this->current()->run_id() );

		$line = $this->matching->get_activity( $this->context(), [] )['account_activity'];

		$this->assertSame( [ Site_Products_Bulk::RUN_REPORT ], array_column( $line['actions'], 'action' ) );
		$this->assertStringContainsString( 'Останавливаем', $line['text'] );
	}

	public function test_the_line_of_a_finished_run_shows_its_result_with_details_for_a_day_and_then_nothing(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SYNC_STOCK_ON, $this->query(), [] );
		$run_id = $this->current()->run_id();
		$this->runs->take_tick( $this->account_id, $run_id, 'tick-a' );
		$this->runs->finish( $this->account_id, $run_id, 'tick-a', Bulk_Run::DONE, [], [ 'products_total' => 3, 'products_done' => 3, 'offers_accepted' => 5, 'offers_skipped' => 1, 'offers_failed' => 0 ], [] );

		$line = $this->matching->get_activity( $this->context(), [] )['account_activity'];

		$this->assertFalse( $line['active'] );
		$this->assertSame( 'Готово: товаров 3; изменено 5, пропущено 1, не удалось 0.', $line['text'] );
		$this->assertSame( [ Site_Products_Bulk::RUN_REPORT ], array_column( $line['actions'], 'action' ) );

		$this->now += Site_Products_Run_Line::SHOW_FINISHED_SECONDS + 5;

		$this->assertFalse( $this->matching->get_activity( $this->context(), [] )['account_activity']['active'] );
		$this->assertArrayNotHasKey( 'actions', $this->matching->get_activity( $this->context(), [] )['account_activity'] );
	}

	public function test_a_run_that_only_queues_remote_work_reports_what_it_queued_never_a_remote_outcome(): void {
		$counts = [ 'products_total' => 10, 'products_done' => 10, 'offers_accepted' => 17500, 'offers_skipped' => 300, 'offers_failed' => 0 ];
		$run    = $this->finished_run( Site_Products_Bulk::SEND_PRICE, Bulk_Run::DONE, $counts );

		$this->assertStringStartsWith( 'Подготовлено и поставлено в очередь: 17' . "\u{00A0}" . '500; пропущено 300', Site_Products_Run_Line::finished( $run ) );
		$this->assertStringContainsString( 'Отправка идёт в фоне', Site_Products_Run_Line::finished( $run ) );
	}

	public function test_a_cancelled_and_a_stopped_run_say_so(): void {
		$counts    = [ 'products_total' => 100, 'products_done' => 40, 'offers_accepted' => 40, 'offers_skipped' => 0, 'offers_failed' => 0 ];
		$cancelled = $this->finished_run( Site_Products_Bulk::RESET, Bulk_Run::CANCELLED, $counts );
		$stopped   = $this->finished_run( Site_Products_Bulk::SEND_STOCK, Bulk_Run::STOPPED, $counts, [ 'stopped' => 'Лицензия не действует.' ] );

		$this->assertStringContainsString( 'остановлено: обработано товаров 40 из ~100', Site_Products_Run_Line::finished( $cancelled ) );
		$this->assertSame( '«Отправить остатки» по фильтру остановлено: Лицензия не действует.', Site_Products_Run_Line::finished( $stopped ) );
	}

	/**
	 * @param string              $action Action.
	 * @param string              $state  State.
	 * @param array<string,int>   $counts Counters.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return Bulk_Run
	 */
	private function finished_run( string $action, string $state, array $counts, array $cursor = [] ): Bulk_Run {
		return new Bulk_Run(
			[
				'account_id'  => $this->account_id,
				'run_id'      => 'r9',
				'state'       => $state,
				'cancel'      => 0,
				'action'      => $action,
				'counts'      => json_encode( $counts ),
				'cursor'      => json_encode( $cursor ),
				'started_at'  => '2026-11-16 10:00:00',
				'finished_at' => '2026-11-16 10:30:00',
			]
		);
	}

	public function test_a_lost_enqueue_is_recovered_by_the_line_read_that_runs_every_few_seconds(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );
		$this->scheduler->actions = []; // A crash between a save and the enqueue of the next tick.

		$this->matching->query( $this->context(), [ 'page' => 1, 'per_page' => 20 ] );

		$this->assertSame( [ [ $this->account_id, $this->current()->run_id() ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ) );
	}

	public function test_stop_ends_the_run_and_a_stale_stop_changes_nothing(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::RESET, $this->query(), [] );
		$first = $this->current()->run_id();

		$stopped = $this->matching->apply( $this->context(), Site_Products_Bulk::RUN_STOP, [], [ Site_Products_Bulk::RUN_FIELD => $first ] );

		$this->assertSame( 1, $stopped['accepted'] );
		$this->assertSame( Bulk_Run::CANCELLED, $this->current()->state() );

		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$second = $this->current()->run_id();

		// The second admin confirms «Остановить» for the run R1 that finished while the dialog was open.
		$late = $this->matching->apply( $this->context(), Site_Products_Bulk::RUN_STOP, [], [ Site_Products_Bulk::RUN_FIELD => $first ] );

		$this->assertSame( 0, $late['accepted'] );
		$this->assertSame( 1, $late['skipped'] );
		$this->assertSame( 'Это действие уже закончилось — новое действие не остановлено.', $late['messages']['action_status_failed'] );
		$this->assertSame( $second, $this->current()->run_id() );
		$this->assertTrue( $this->current()->is_running(), 'R2 keeps running.' );
	}

	public function test_the_stop_dialog_for_a_run_that_is_gone_says_so(): void {
		$this->expectException( Action_State_Exception::class );

		$this->matching->preview( $this->context(), Site_Products_Bulk::RUN_STOP, [], [ Site_Products_Bulk::RUN_FIELD => 'gone' ] );
	}

	public function test_details_show_the_stored_report_through_the_preview_contract(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$run_id = $this->current()->run_id();
		$group  = [ 'kind' => 'skipped', 'reason' => 'не отправлялся на маркетплейс', 'count' => 12, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'Платье' ] ];

		$this->runs->take_tick( $this->account_id, $run_id, 'tick-a' );
		$this->runs->finish( $this->account_id, $run_id, 'tick-a', Bulk_Run::DONE, [], [ 'products_total' => 3, 'products_done' => 3, 'offers_accepted' => 5, 'offers_skipped' => 12, 'offers_failed' => 0 ], [ $group ] );

		$result = $this->matching->preview( $this->context(), Site_Products_Bulk::RUN_REPORT, [], [ Site_Products_Bulk::RUN_FIELD => $run_id ] );
		$body   = $this->through_preview( Site_Products_Bulk::RUN_REPORT, $result );

		$this->assertSame( 'не отправлялся на маркетплейс', $body['groups'][0]['reason'] );
		$this->assertSame( 12, $body['groups'][0]['count'] );
		$this->assertSame( [ 'Платье' ], $body['groups'][0]['examples'] );
		$this->assertSame( 17, $body['requested'] );
		$this->assertFalse( $body['can_apply'], 'It is a read.' );
	}

	public function test_a_full_report_of_fifty_groups_still_fits_the_engines_answer(): void {
		$this->matching->apply_matching( $this->context(), Site_Products_Bulk::SEND_STOCK, $this->query(), [] );
		$run_id = $this->current()->run_id();
		$groups = [];

		for ( $i = 0; $i < 50; $i++ ) {
			$groups[] = [ 'kind' => 'failed', 'reason' => 'причина ' . $i, 'count' => 2, 'fix_label' => '', 'fix_url' => '', 'examples' => [ 'A', 'B', 'C', 'D', 'E' ] ];
		}

		$this->runs->take_tick( $this->account_id, $run_id, 'tick-a' );
		$this->runs->finish( $this->account_id, $run_id, 'tick-a', Bulk_Run::DONE, [], [ 'offers_failed' => 100 ], $groups );

		$body = $this->through_preview( Site_Products_Bulk::RUN_REPORT, $this->matching->preview( $this->context(), Site_Products_Bulk::RUN_REPORT, [], [ Site_Products_Bulk::RUN_FIELD => $run_id ] ) );

		$this->assertCount( 50, $body['groups'] );
	}

	public function test_the_two_run_buttons_need_no_ids_to_be_authorised(): void {
		$this->assertTrue( $this->matching->authorize( Site_Products_Bulk::RUN_STOP, $this->context(), [] ) );
		$this->assertTrue( $this->matching->authorize( Site_Products_Bulk::RUN_REPORT, $this->context(), [] ) );
	}

	public function test_the_labels_of_the_toolbar_and_of_the_line_are_the_same_words(): void {
		$definition = Records_Definition::from_array( $this->matching->get_definition( $this->context() ) );

		foreach ( Site_Products_Bulk::ids() as $action ) {
			if ( Site_Products_Bulk::LINK_BY_ARTICLE === $action ) {
				continue;
			}

			$this->assertSame( Site_Products_Definition::label_of( $action ), $definition->get_action( $action )['label'], $action );
		}

		$this->assertSame( 500, Site_Products_Run_Worker::DEFAULT_SIZE );
	}
}

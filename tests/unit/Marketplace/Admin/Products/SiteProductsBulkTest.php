<?php
/**
 * The twelve bulk actions of the «Товары сайта» table: the preview writes nothing, the apply replans and counts what really landed.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk
 */
final class SiteProductsBulkTest extends Products_Test_Case {
	/**
	 * @param string          $action    Action id.
	 * @param int[]           $offers    Offers.
	 * @param int|null        $warehouse Warehouse.
	 * @return array<string,mixed>
	 */
	private function preview( string $action, array $offers, ?int $warehouse = null ): array {
		return $this->bulk()->preview( $this->account_now(), $action, $offers, array_combine( $offers, $offers ) ?: [], $warehouse );
	}

	/**
	 * @param string          $action    Action id.
	 * @param int[]           $offers    Offers.
	 * @param int|null        $warehouse Warehouse.
	 * @return array<string,mixed>
	 */
	private function apply( string $action, array $offers, ?int $warehouse = null ): array {
		return $this->bulk()->apply( $this->account_now(), $action, $offers, array_combine( $offers, $offers ) ?: [], $warehouse );
	}

	/**
	 * The engine rebuilds an answer from a whitelist and throws away what it does not know; running the answer through it proves
	 * the counts add up and the groups have the shape the browser reads.
	 *
	 * @param array<string,mixed> $result Provider answer of a preview.
	 * @return array<string,mixed>
	 */
	private function through_engine_preview( array $result ): array {
		$action = [
			'id'             => 'x',
			'scope'          => 'selection',
			'scope_sentence' => '',
			'fields'         => [],
			'confirm_label'  => '',
		];

		$body = Action_Response::preview( $action, $result );
		$this->assertIsArray( $body, 'The engine accepts the preview.' );

		return $body;
	}

	public function test_there_are_twelve_distinct_actions(): void {
		$ids = Site_Products_Bulk::ids();

		$this->assertCount( 12, $ids );
		$this->assertCount( 12, array_unique( $ids ) );
	}

	public function test_a_sync_switch_changes_the_link_and_a_repeat_is_skipped_with_its_reason(): void {
		$product = $this->add_product();
		$link    = $this->add_link( $product, Link_Record::STATE_CREATED );
		$id      = (int) $product->get_id();

		$plan = $this->preview( Site_Products_Bulk::SYNC_STOCK_OFF, [ $id ] );
		$this->through_engine_preview( $plan );

		$this->assertSame( 1, $plan['eligible'] );
		$this->assertTrue( $this->link_of( $id )->sync_stock(), 'A preview writes nothing.' );

		$done = $this->apply( Site_Products_Bulk::SYNC_STOCK_OFF, [ $id ] );

		$this->assertSame( 1, $done['accepted'] );
		$this->assertFalse( $this->link_of( $id )->sync_stock() );
		$this->assertFalse( $done['queued'] );

		$again = $this->apply( Site_Products_Bulk::SYNC_STOCK_OFF, [ $id ] );

		$this->assertSame( 0, $again['accepted'] );
		$this->assertSame( 1, $again['skipped'] );
		$this->assertSame( 'уже настроено так', $again['groups'][0]['reason'] );
		$this->assertSame( 'skipped', $again['groups'][0]['kind'] );
		$this->assertSame( [ 'Товар ' . $id ], $again['groups'][0]['examples'] );
		$this->assertNotNull( $link->link_id() );
	}

	public function test_a_product_that_was_never_sent_is_left_out_of_a_link_action_with_the_export_hint(): void {
		$product = $this->add_product();

		$plan = $this->preview( Site_Products_Bulk::SYNC_PRICE_ON, [ (int) $product->get_id() ] );

		$this->assertSame( 0, $plan['eligible'] );
		$this->assertSame( 1, $plan['skipped'] );
		$this->assertStringContainsString( 'сначала экспортируйте', $plan['groups'][0]['reason'] );
	}

	public function test_choosing_a_warehouse_stores_it_and_zero_means_the_account_default(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED );
		$id = (int) $product->get_id();

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::SET_WAREHOUSE, [ $id ], 33 )['accepted'] );
		$this->assertSame( 33, $this->link_of( $id )->warehouse_id() );

		$same = $this->apply( Site_Products_Bulk::SET_WAREHOUSE, [ $id ], 33 );
		$this->assertSame( 'этот склад уже выбран', $same['groups'][0]['reason'] );

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::SET_WAREHOUSE, [ $id ], 0 )['accepted'] );
		$this->assertNull( $this->link_of( $id )->warehouse_id() );
	}

	public function test_reset_returns_a_card_to_not_linked_and_skips_one_that_already_is(): void {
		$sent = $this->add_product();
		$this->add_link( $sent, Link_Record::STATE_CREATED );
		$clean = $this->add_product();
		$this->add_link( $clean, Link_Record::STATE_UNLINKED );

		$result = $this->apply( Site_Products_Bulk::RESET, [ (int) $sent->get_id(), (int) $clean->get_id() ] );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( (int) $sent->get_id() )->card_state() );
		$this->assertStringContainsString( 'и так «Не связан»', $result['groups'][0]['reason'] );
	}

	public function test_detach_forgets_a_card_but_not_one_that_is_being_submitted(): void {
		$created = $this->add_product();
		$this->add_link( $created, Link_Record::STATE_CREATED, [ 'offer_id' => 'A-1' ] );
		$submitted = $this->add_product();
		$this->add_link( $submitted, Link_Record::STATE_SUBMITTED );
		$none = $this->add_product();
		$this->add_link( $none, Link_Record::STATE_UNLINKED );
		$ids = [ (int) $created->get_id(), (int) $submitted->get_id(), (int) $none->get_id() ];

		$result = $this->apply( Site_Products_Bulk::DETACH, $ids );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $ids[0] )->card_state() );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $ids[1] )->card_state() );
		$this->assertContains( 'связи нет — снимать нечего', array_column( $result['groups'], 'reason' ) );
	}

	public function test_detach_changes_nothing_while_the_sender_holds_the_account(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED );
		$id = (int) $product->get_id();

		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 60 ) );

		$result = $this->apply( Site_Products_Bulk::DETACH, [ $id ] );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'failed', $result['groups'][0]['kind'] );
		$this->assertStringContainsString( 'повторите через минуту', $result['groups'][0]['reason'] );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $id )->card_state() );
	}

	public function test_archive_records_the_intent_for_created_cards_only(): void {
		$created = $this->add_product();
		$this->add_link( $created, Link_Record::STATE_CREATED );
		$queued = $this->add_product();
		$this->add_link( $queued, Link_Record::STATE_QUEUED );

		$result = $this->apply( Site_Products_Bulk::ARCHIVE, [ (int) $created->get_id(), (int) $queued->get_id() ] );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertTrue( $result['queued'], 'The sender archives later: the answer says queued.' );
		$this->assertSame( 'на маркетплейсе нет созданной карточки этого товара', $result['groups'][0]['reason'] );

		$row = $this->db->get_row( 'SELECT pending_payload FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE link_id = ' . (int) $this->link_of( (int) $created->get_id() )->link_id() . ' AND kind = \'' . Sync_State_Store::KIND_STOCK . '\'', 'ARRAY_A' );
		$this->assertStringContainsString( '"archive":true', (string) $row['pending_payload'] );
	}

	public function test_sending_stock_marks_the_intent_and_names_what_blocks_the_rest(): void {
		$ready   = $this->stock_product( 'S-1', 9001, 5 );
		$switch  = $this->stock_product( 'S-2', 9002, 5, [ 'sync_stock' => false ] );
		$managed = $this->stock_product( 'S-3', 9003, 5, [ 'manage_stock' => false ] );
		$pending = $this->stock_product( 'S-4', 9004, 5, [ 'state' => Link_Record::STATE_QUEUED ] );

		$result = $this->apply( Site_Products_Bulk::SEND_STOCK, [ $ready, $switch, $managed, $pending ] );
		$reasons = array_column( $result['groups'], 'reason' );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 3, $result['skipped'] );
		$this->assertContains( 'синхронизация остатков выключена', $reasons );
		$this->assertContains( 'для товара не ведётся учёт остатков', $reasons );
		$this->assertContains( 'карточка ещё не создана на маркетплейсе', $reasons );
		$this->assertNotNull( $this->stock_row( $ready ) );
		$this->assertNull( $this->stock_row( $managed ) );
	}

	public function test_stock_is_not_sent_when_the_account_does_not_push_it(): void {
		$product = $this->stock_product( 'S-1', 9001, 5 );
		$this->set_account( [ 'stock_mode' => 'marketplace' ] );

		$plan = $this->preview( Site_Products_Bulk::SEND_STOCK, [ $product ] );

		$this->assertSame( 0, $plan['eligible'] );
		$this->assertSame( 'в настройках аккаунта остатки не отправляются на маркетплейс', $plan['groups'][0]['reason'] );
	}

	public function test_sending_a_price_needs_a_created_card_a_switch_and_a_price(): void {
		$ok = $this->add_product( [ 'price' => '1500' ] );
		$this->add_link( $ok, Link_Record::STATE_CREATED );
		$off = $this->add_product();
		$this->add_link( $off, Link_Record::STATE_CREATED, [ 'sync_price' => 0 ] );
		$free = $this->add_product( [ 'price' => '' ] );
		$this->add_link( $free, Link_Record::STATE_CREATED );
		$ids = [ (int) $ok->get_id(), (int) $off->get_id(), (int) $free->get_id() ];

		$plan = $this->preview( Site_Products_Bulk::SEND_PRICE, $ids );
		$this->assertSame( 1, $plan['eligible'] );

		$result  = $this->apply( Site_Products_Bulk::SEND_PRICE, $ids );
		$reasons = array_column( $result['groups'], 'reason' );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertContains( 'синхронизация цен выключена', $reasons );
		$this->assertContains( 'у товара нет цены, которую можно отправить', $reasons );
		$this->assertTrue( $result['queued'] );
	}

	public function test_prices_are_not_sent_when_the_account_does_not_push_them(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED );
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );

		$plan = $this->preview( Site_Products_Bulk::SEND_PRICE, [ (int) $product->get_id() ] );

		$this->assertSame( 'в настройках аккаунта цены на маркетплейс не отправляются', $plan['groups'][0]['reason'] );
	}

	public function test_export_queues_what_validates_and_reports_the_rest_by_reason(): void {
		$good = $this->add_product( [ 'sku' => 'G-1' ] );
		$bad  = $this->add_product( [ 'sku' => 'B-1' ] );
		$this->rule->blocked = [ (int) $bad->get_id() ];
		$digital             = $this->add_product( [ 'virtual' => true ] );
		$busy                = $this->add_product();
		$this->add_link( $busy, Link_Record::STATE_SUBMITTED );
		$ids = [ (int) $good->get_id(), (int) $bad->get_id(), (int) $digital->get_id(), (int) $busy->get_id() ];

		$plan = $this->preview( Site_Products_Bulk::EXPORT, $ids );
		$this->through_engine_preview( $plan );

		$this->assertSame( 4, $plan['requested'] );
		$this->assertSame( 1, $plan['eligible'] );
		$this->assertSame( 3, $plan['skipped'] );
		$this->assertSame( [], $this->scheduler->of( 'woodev_fake_seller_export_batch' ), 'A preview queues nothing.' );

		$result = $this->apply( Site_Products_Bulk::EXPORT, $ids );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertTrue( $result['queued'] );
		$this->assertCount( 1, $this->scheduler->of( 'woodev_fake_seller_export_batch' ) );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( (int) $good->get_id() )->card_state() );

		$reasons = array_column( $plan['groups'], 'reason' );
		$this->assertContains( 'карточка сейчас отправляется на маркетплейс — дождитесь результата', $reasons );
		$this->assertContains( 'этот товар не продаётся на маркетплейсе (например, виртуальный или скачиваемый)', $reasons );
	}

	public function test_export_does_not_reach_a_product_the_user_may_not_edit(): void {
		$product          = $this->add_product();
		$this->forbidden = [ (int) $product->get_id() ];

		$result = $this->apply( Site_Products_Bulk::EXPORT, [ (int) $product->get_id() ] );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'у вас нет прав менять этот товар', $result['groups'][0]['reason'] );
		$this->assertNull( $this->links()->find( $this->account_id, (int) $product->get_id() ) );
	}

	public function test_a_variation_is_judged_by_its_parents_edit_right(): void {
		$variation = $this->add_product( [ 'parent_id' => 300 ] );
		$this->add_link( $variation, Link_Record::STATE_CREATED );
		$id = (int) $variation->get_id();
		$this->forbidden = [ 300 ];

		$result = $this->bulk()->apply( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_OFF, [ $id ], [ $id => 300 ] );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 'у вас нет прав менять этот товар', $result['groups'][0]['reason'] );
		$this->assertTrue( $this->link_of( $id )->sync_stock() );
	}

	public function test_linking_by_article_covers_the_whole_account_and_says_so(): void {
		$plan = $this->preview( Site_Products_Bulk::LINK_BY_ARTICLE, [] );

		$this->assertSame( 7, $plan['requested'] );
		$this->assertSame( 7, $plan['eligible'] );
		$this->assertSame( 0, $plan['skipped'] );
		$this->assertStringContainsString( '7 товаров', $plan['scope_sentence'] );

		$done = $this->apply( Site_Products_Bulk::LINK_BY_ARTICLE, [] );

		$this->assertSame( 7, $done['accepted'] );
		$this->assertTrue( $done['queued'] );
		$this->assertCount( 1, $this->scheduler->of( ( new Link_Scheduler( $this->names() ) )->link_hook() ) );
	}

	public function test_the_apply_never_trusts_the_preview(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED );
		$id = (int) $product->get_id();

		$this->assertSame( 1, $this->preview( Site_Products_Bulk::DETACH, [ $id ] )['eligible'] );

		$this->links()->set_state( (int) $this->link_of( $id )->link_id(), Link_Record::STATE_SUBMITTED );

		$result = $this->apply( Site_Products_Bulk::DETACH, [ $id ] );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Keeps the unused import honest: the account is the one the harness created.
	 *
	 * @return void
	 */
	public function test_the_harness_account_is_the_one_the_actions_run_for(): void {
		$this->assertInstanceOf( Account::class, $this->account_now() );
	}
}

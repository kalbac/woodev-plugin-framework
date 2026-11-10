<?php
/**
 * Tests for the layer's value objects and pure services.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Diagnostics\Support_Report;
use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;
use Woodev\Framework\Marketplace\Diagnostics\Why_Not_Syncing;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Ledger\Task_Status;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Push_Receipt;
use Woodev\Framework\Marketplace\Stock\Stock_Line;

/**
 * Small contracts that the wider tests lean on: validation at construction, immutability and the Russian merchant text.
 */
final class LayerValueObjectsTest extends TestCase {
	/**
	 * A bucket needs positive numbers; burst defaults to the limit.
	 *
	 * @return void
	 */
	public function test_bucket_spec_validates_and_defaults_burst(): void {
		$spec = new Bucket_Spec( 'default', 50, 1 );
		$this->assertSame( 50, $spec->burst() );
		$this->assertSame( Bucket_Spec::COST_REQUEST, $spec->cost_mode() );
		$this->assertSame( 1, $spec->cost_4xx() );

		$items = new Bucket_Spec( 'product_ops', 30000, 60, 0, Bucket_Spec::COST_ITEMS );
		$this->assertSame( Bucket_Spec::COST_ITEMS, $items->cost_mode() );

		foreach ( array(
			array( '', 1, 1 ),
			array( 'x', 0, 1 ),
			array( 'x', 1, 0 ),
		) as $args ) {
			try {
				new Bucket_Spec( ...$args );
				$this->fail( 'Accepted an invalid bucket.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );
		new Bucket_Spec( 'x', 1, 1, 0, 'tokens' );
	}

	/**
	 * Health statuses are a closed set.
	 *
	 * @return void
	 */
	public function test_health_result_status_is_a_closed_set(): void {
		$this->assertTrue( ( new Health_Result( Health_Result::OK ) )->is_ok() );
		$this->assertFalse( ( new Health_Result( Health_Result::ROLE_MISSING, 'нет прав' ) )->is_ok() );

		$this->expectException( \InvalidArgumentException::class );
		new Health_Result( 'disabled' );
	}

	/**
	 * Account settings are read with a fallback.
	 *
	 * @return void
	 */
	public function test_account_settings_have_fallbacks(): void {
		$account = new Account( 3, 'x', 'Shop', true, array(), null, array( 'warehouse' => 5 ) );

		$this->assertSame( 5, $account->setting( 'warehouse', 0 ) );
		$this->assertSame( 'site', $account->setting( 'stock_mode', 'site' ) );
		$this->assertNull( $account->setting( 'missing' ) );
	}

	/**
	 * Tasks: closed kinds and statuses, final detection, copy-on-change.
	 *
	 * @return void
	 */
	public function test_async_task_is_immutable_and_validated(): void {
		$task = new Async_Task( 1, Async_Task::KIND_PRICE, null, Async_Task::STATUS_POLLING );
		$done = $task->with_status( Async_Task::STATUS_DONE, array( 'summary' => 'ok' ) );

		$this->assertFalse( $task->is_final() );
		$this->assertTrue( $done->is_final() );
		$this->assertSame( Async_Task::STATUS_POLLING, $task->status() );
		$this->assertSame( array( 'summary' => 'ok' ), $done->result() );
		$this->assertNull( $done->remote_task_id() );

		$this->assertTrue( ( new Task_Status( Async_Task::STATUS_EXPIRED ) )->is_final() );
		$this->assertFalse( ( new Task_Status( Async_Task::STATUS_POLLING ) )->is_final() );

		try {
			new Async_Task( 1, 'export' );
			$this->fail( 'Unknown kind accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'kind', $e->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		new Task_Status( 'maybe' );
	}

	/**
	 * Item results carry the marketplace's words and the wait it asked for.
	 *
	 * @return void
	 */
	public function test_item_result_carries_marketplace_words(): void {
		$ok     = Item_Result::success( 4, array( 'sku' => 123 ) );
		$failed = Item_Result::failure( 5, 'TOO_MANY_REQUESTS', 'slow down', 31 );

		$this->assertTrue( $ok->is_ok() );
		$this->assertSame( array( 'sku' => 123 ), $ok->remote_ids() );
		$this->assertFalse( $failed->is_ok() );
		$this->assertSame( 31, $failed->retry_after_seconds() );
		$this->assertSame( 'slow down', $failed->message() );
	}

	/**
	 * A receipt is immediate, pending or a duplicate.
	 *
	 * @return void
	 */
	public function test_push_receipt_shapes(): void {
		$task = new Async_Task( 1, Async_Task::KIND_PRICE, 'abc', Async_Task::STATUS_POLLING );

		$this->assertFalse( Push_Receipt::immediate( array( Item_Result::success( 1 ) ) )->is_pending() );
		$this->assertTrue( Push_Receipt::pending( $task )->is_pending() );

		$duplicate = Push_Receipt::duplicate( 'abc', $task );
		$this->assertSame( 'abc', $duplicate->duplicate_of() );
		$this->assertSame( $task, $duplicate->pending_task() );
	}

	/**
	 * Links change by copy; remote ids merge; flags and ids read with fallbacks.
	 *
	 * @return void
	 */
	public function test_product_link_is_immutable(): void {
		$link    = new Product_Link( 1, 10, 0, 'SKU', array( 'product_id' => 5 ), Product_Link::STATE_UNLINKED, array( 'sync_price' => true ) );
		$created = $link->with_remote_ids( array( 'sku' => 77 ) )->with_state( Product_Link::STATE_CREATED )->with_link_id( 9 );

		$this->assertFalse( $link->is_created() );
		$this->assertNull( $link->remote_id( 'sku' ) );
		$this->assertTrue( $created->is_created() );
		$this->assertSame( 5, $created->remote_id( 'product_id' ) );
		$this->assertSame( 77, $created->remote_id( 'sku' ) );
		$this->assertSame( 9, $created->link_id() );
		$this->assertTrue( $created->flag( 'sync_price' ) );
		$this->assertFalse( $created->flag( 'sync_stock' ) );
		$this->assertTrue( $created->flag( 'sync_stock', true ) );
	}

	/**
	 * Stock quantities cannot be negative; order lines keep exact totals.
	 *
	 * @return void
	 */
	public function test_stock_and_order_lines_refuse_negative_quantities(): void {
		$link = new Product_Link( 1, 10, 0, 'SKU' );
		$this->assertSame( 0, ( new Stock_Line( $link, 0 ) )->quantity() );

		$line = new Order_Line( 'SKU', array(), 3, Money::from_string( '33.33', 'RUB' ), 'Товар' );
		$this->assertSame( '99.99', $line->line_total()->to_string() );
		$this->assertSame( '0.00', $line->with_quantity( 0 )->line_total()->to_string() );

		try {
			new Stock_Line( $link, -1 );
			$this->fail( 'Negative stock accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		new Order_Line( 'SKU', array(), -1, Money::zero( 'RUB' ) );
	}

	/**
	 * An order needs an id and a canonical status; the group key defaults to the id (Ozon: one posting, one order).
	 *
	 * @return void
	 */
	public function test_order_dto_defaults_group_key_and_validates(): void {
		$dto = new Order_DTO( 1, '0123-456-1', '', 'awaiting_packaging', 'awaiting_shipment', array() );
		$this->assertSame( '0123-456-1', $dto->group_key() );
		$this->assertNull( $dto->updated_at() );
		$this->assertFalse( $dto->is_cancelled() );

		try {
			new Order_DTO( 1, '', '', 'x', 'new', array() );
			$this->fail( 'Empty id accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'id', $e->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		new Order_DTO( 1, '1', '', 'x', 'shipping_soon', array() );
	}

	/**
	 * Field types are a closed set; a source is complete only with its value or key.
	 *
	 * @return void
	 */
	public function test_field_definition_and_source_validation(): void {
		$this->assertSame( 'Бренд', ( new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_STRING ) )->name() );

		$this->assertTrue( ( new Source( Source::FIXED, 'Nike' ) )->is_complete() );
		$this->assertTrue( ( new Source( Source::FIXED, '', '', '970' ) )->is_complete() );
		$this->assertFalse( ( new Source( Source::FIXED ) )->is_complete() );
		$this->assertTrue( ( new Source( Source::WC_ATTRIBUTE, '', 'pa_color' ) )->is_complete() );
		$this->assertFalse( ( new Source( Source::META ) )->is_complete() );

		$this->expectException( \InvalidArgumentException::class );
		new Field_Definition( '1', 'x', false, 'blob' );
	}

	/**
	 * The neutral validator: an unmapped category and required fields without a usable source are errors, with Russian
	 * reasons the merchant can act on; a complete mapping passes.
	 *
	 * @return void
	 */
	public function test_export_validator_neutral_checks(): void {
		$product   = \Mockery::mock( 'WC_Product' );
		$profile   = new Profile( null, 'Default' );
		$validator = new Export_Validator( array() );
		$fields    = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '4180', 'Название', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '9999', 'Заметка', false, Field_Definition::TYPE_STRING ),
		);

		$unmapped = $validator->validate(
			$product,
			new Mapping( null, array(), null ),
			$profile,
			array(
				'fields' => $fields,
				'scope'  => Export_Validator::SCOPE_MAPPING,
			)
		);
		$this->assertFalse( $unmapped->is_valid() );
		$this->assertSame( Export_Validator::CODE_NO_CATEGORY, $unmapped->errors()[0]['code'] );
		$this->assertStringContainsString( 'Категория', $unmapped->errors()[0]['message'] );

		$mapping = new Mapping( 5, array( 'type_id' => 94091 ), null, array( '85' => new Source( Source::FIXED, 'Nike' ) ) );
		$partial = $validator->validate(
			$product,
			$mapping,
			$profile,
			array(
				'fields' => $fields,
				'scope'  => Export_Validator::SCOPE_MAPPING,
			)
		);
		$this->assertCount( 1, $partial->errors() );
		$this->assertSame( '4180', $partial->errors()[0]['field_id'] );
		$this->assertStringContainsString( 'Название', $partial->errors()[0]['message'] );

		$complete = new Mapping(
			5,
			array( 'type_id' => 94091 ),
			null,
			array(
				'85'   => new Source( Source::FIXED, 'Nike' ),
				'4180' => new Source( Source::FIELD, '', 'name' ),
			)
		);
		$this->assertTrue(
			$validator->validate(
				$product,
				$complete,
				$profile,
				array(
					'fields' => $fields,
					'scope'  => Export_Validator::SCOPE_MAPPING,
				)
			)->is_valid()
		);
	}

	/**
	 * Warnings do not block the export; errors do.
	 *
	 * @return void
	 */
	public function test_validation_report_separates_warnings_from_errors(): void {
		$report = ( new Validation_Report() )->with_issue( Validation_Report::WARNING, 'w', 'Мало фото' );
		$this->assertTrue( $report->is_valid() );
		$this->assertCount( 1, $report->warnings() );

		$blocked = $report->with_issue( Validation_Report::ERROR, 'e', 'Нет веса', '4497' );
		$this->assertFalse( $blocked->is_valid() );
		$this->assertTrue( $report->is_valid(), 'adding an issue returns a copy' );
		$this->assertCount( 2, $blocked->issues() );

		$this->expectException( \InvalidArgumentException::class );
		$report->with_issue( 'fatal', 'x', 'y' );
	}

	/**
	 * The report keeps the section order, hides secrets whatever the label language, and masks identifiers.
	 *
	 * @return void
	 */
	public function test_support_report_masks_secrets_and_identifiers(): void {
		$report = Support_Report::build(
			array(
				new Report_Section(
					'Аккаунт',
					array(
						'Client-Id' => Support_Report::mask_identifier( '1234567' ),
						'Api-Key'   => 'abcd-secret-key',
						'api_key'   => 'abcd-secret-key',
						'Токен'     => 'jwt.value.here',
						'Статус'    => 'ok',
					)
				),
				new Report_Section( 'Сайт', array( 'PHP' => '7.4.33' ) ),
			)
		);

		$this->assertStringContainsString( "== Аккаунт ==\nClient-Id: ***4567", $report );
		$this->assertStringContainsString( 'Статус: ok', $report );
		$this->assertStringNotContainsString( 'abcd-secret-key', $report );
		$this->assertStringNotContainsString( 'jwt.value.here', $report );
		$this->assertLessThan( strpos( $report, '== Сайт ==' ), strpos( $report, '== Аккаунт ==' ) );
		$this->assertSame( '***4567', Support_Report::mask_identifier( '1234567' ) );
	}

	/**
	 * The reasons come out as codes with parameters, in the fixed order of §10.2, and are empty for a healthy link. The layer writes no
	 * sentence: a shared admin mapper does.
	 *
	 * @return void
	 */
	public function test_why_not_syncing_lists_reasons_in_the_fixed_order(): void {
		$account = new Account( 1, 'x', 'Shop', false );
		$link    = new Product_Link( 1, 10, 0, 'SKU', array(), Product_Link::STATE_UNLINKED );

		$reasons = Why_Not_Syncing::reasons(
			$link,
			$account,
			array(
				'license_ok'          => false,
				'health'              => Health_Result::KEY_EXPIRED,
				'retry_after_seconds' => 31,
				'last_error'          => 'TOO_MANY_REQUESTS',
			)
		);

		$this->assertSame(
			array(
				Sync_Reason::LICENSE_INVALID,
				Sync_Reason::ACCOUNT_DISABLED,
				Sync_Reason::KEY_INVALID,
				Sync_Reason::NOT_LINKED,
				Sync_Reason::STOCK_SYNC_OFF,
				Sync_Reason::NO_WAREHOUSE,
				Sync_Reason::RATE_LIMITED,
				Sync_Reason::LAST_ERROR,
			),
			array_map( array( $this, 'reason_code' ), $reasons )
		);
		$this->assertSame( array( 'seconds' => 31 ), $reasons[6]->params() );
		$this->assertSame( array( 'message' => 'TOO_MANY_REQUESTS' ), $reasons[7]->params() );

		$healthy = new Product_Link( 1, 10, 0, 'SKU', array(), Product_Link::STATE_CREATED, array( 'sync_stock' => true ), 5 );
		$this->assertSame( array(), Why_Not_Syncing::reasons( $healthy, new Account( 1, 'x', 'Shop', true ), array() ) );

		$creating = new Product_Link( 1, 10, 0, 'SKU', array(), Product_Link::STATE_PENDING_REMOTE_IDS, array( 'sync_stock' => true ), 5 );
		$pending = Why_Not_Syncing::reasons( $creating, new Account( 1, 'x', 'Shop', true ), array() );
		$this->assertSame( array( Sync_Reason::CARD_PENDING ), array_map( array( $this, 'reason_code' ), $pending ) );
		$this->assertSame( array( 'state' => Product_Link::STATE_PENDING_REMOTE_IDS ), $pending[0]->params() );
	}

	/**
	 * Returns the code of a reason.
	 *
	 * @param Sync_Reason $reason Reason.
	 * @return string
	 */
	private function reason_code( Sync_Reason $reason ): string {
		return $reason->code();
	}
}

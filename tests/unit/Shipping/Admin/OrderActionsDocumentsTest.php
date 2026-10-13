<?php
/**
 * Unit: the «Накладная» / «Штрихкод» row actions (card #1134).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Order_Actions, Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Order\{Abstract_Shipment_Handler, Bulk_Document_Source, Document_Result, Document_Source, Document_Type_Declarations};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-document-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-document-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-bulk-document-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-document-type-declarations.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_row
 */
final class OrderActionsDocumentsTest extends TestCase {

	/** @var array<string,mixed> post meta of order 123. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [
			'_cdek_marker'           => '1',
			'_cdek_carrier_order_id' => 'CARRIER-1',
		];
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string { return ucfirst( $status ); } );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( bool $flag = true ): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek' ],
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'status_meta_key'           => '_cdek_status',
				'status_map'                => [ 'NEW' => 'created' ],
				'supports_label_printing'   => $flag,
			]
		);
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		return $order;
	}

	private function source( array $types ): Document_Source {
		return new class( $types ) implements Document_Source {
			private $types;

			public function __construct( array $types ) {
				$this->types = $types;
			}

			public function get_document_types( \WC_Order $order ): array {
				return $this->types;
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}
		};
	}

	private function actions( ?Document_Source $source = null ): Order_Actions {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_reconcile' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'export_unknown_since' )->andReturn( 0 )->byDefault();
		$handler->shouldReceive( 'supports_update' )->andReturn( true );
		// #1204: a carrier that declares nothing keeps today's behaviour.
		$handler->shouldReceive( 'supports_refusal' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'get_handed_over_statuses' )->andReturn( [] )->byDefault();
		$handler->shouldReceive( 'get_refusable_statuses' )->andReturn( [] )->byDefault();
		$handler->shouldReceive( 'is_handed_over' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'is_refusable' )->andReturn( false )->byDefault();
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		if ( null !== $source ) {
			Orders_Registry::instance()->register_document_source( 'cdek', $source );
		}

		return new Order_Actions( Orders_Registry::instance() );
	}

	/** @return string[] */
	private function ids( Order_Actions $actions, Orders_Provider $provider ): array {
		return array_column( $actions->for_row( $this->order(), $provider ), 'action' );
	}

	public function test_an_exported_order_of_a_printing_carrier_gets_the_document_actions_after_the_carrier_ones(): void {
		$actions = $this->actions( $this->source( [ 'waybill', 'barcode' ] ) );
		$row     = $actions->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL, 'waybill', 'barcode' ], array_column( $row, 'action' ) );
		$this->assertSame( 'Накладная', $row[2]['label'] );
		$this->assertSame( 'Штрихкод', $row[3]['label'] );
		$this->assertFalse( $row[2]['destructive'], 'a download needs no «Да / Нет»' );
	}

	/** #1192: only a bulk-capable source marks the documents it can print for several orders. */
	public function test_the_bulk_flag_is_set_only_for_types_a_bulk_source_prints_in_bulk(): void {
		$source = new class() implements Bulk_Document_Source {
			public function get_document_types( \WC_Order $order ): array {
				return [ 'waybill', 'barcode' ];
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}

			public function get_bulk_document_types(): array {
				return [ 'waybill' ];
			}

			public function get_bulk_document_limit(): int {
				return 100;
			}

			public function get_bulk_document( array $orders, string $type ): Document_Result {
				return Document_Result::pending();
			}
		};

		$row = array_column( $this->actions( $source )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertTrue( $row['waybill']['bulk'] );
		$this->assertArrayNotHasKey( 'bulk', $row['barcode'] );
	}

	/**
	 * A source that declares its own types (#1257) — the shape of a carrier with a label and a handover act.
	 *
	 * @param array<string,array<string,mixed>> $declarations type => declaration.
	 * @param string[]                          $bulk         the types it prints in bulk.
	 */
	private function declaring_source( array $declarations, array $bulk ): Document_Source {
		return new class( $declarations, $bulk ) implements Bulk_Document_Source, Document_Type_Declarations {
			private $declarations;
			private $bulk;

			public function __construct( array $declarations, array $bulk ) {
				$this->declarations = $declarations;
				$this->bulk         = $bulk;
			}

			public function get_document_types( \WC_Order $order ): array {
				return array_merge( array_keys( $this->declarations ), [ 'undeclared' ] );
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}

			public function get_document_type_declarations(): array {
				return $this->declarations;
			}

			public function get_bulk_document_types(): array {
				return $this->bulk;
			}

			public function get_bulk_document_limit(): int {
				return 100;
			}

			public function get_bulk_document( array $orders, string $type ): Document_Result {
				return Document_Result::pending();
			}
		};
	}

	/** #1257: the carrier's own types become row actions with its own labels; an undeclared type stays hidden. */
	public function test_declared_types_become_document_actions_with_the_carriers_labels(): void {
		$source = $this->declaring_source(
			[
				'label'        => [ 'label' => 'Этикетка' ],
				'handover_act' => [ 'label' => 'Акт приёма-передачи', 'bulk_label' => 'Печать актов', 'icon' => 'clipboard' ],
			],
			[ 'handover_act' ]
		);

		$row = array_column( $this->actions( $source )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL, 'label', 'handover_act' ], array_keys( $row ) );
		$this->assertSame( 'Этикетка', $row['label']['label'] );
		$this->assertSame( 'Акт приёма-передачи', $row['handover_act']['label'] );
		$this->assertSame( 'clipboard', $row['handover_act']['icon'] );
		$this->assertTrue( $row['label']['document'], 'the page downloads it instead of posting it to an action route' );
		$this->assertTrue( $row['handover_act']['document'] );
		$this->assertArrayNotHasKey( 'undeclared', $row );
	}

	/** #1257: bulk only for types the source prints in bulk, worded by the declaration (or the single label). */
	public function test_bulk_is_offered_only_for_declared_bulk_types_and_worded_by_the_carrier(): void {
		$source = $this->declaring_source(
			[
				'label'        => [ 'label' => 'Этикетка' ],
				'handover_act' => [ 'label' => 'Акт приёма-передачи', 'bulk_label' => 'Печать актов' ],
				'manifest'     => [ 'label' => 'Реестр' ],
			],
			[ 'handover_act', 'manifest' ]
		);

		$row = array_column( $this->actions( $source )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertArrayNotHasKey( 'bulk', $row['label'] );
		$this->assertArrayNotHasKey( 'bulk_label', $row['label'] );
		$this->assertTrue( $row['handover_act']['bulk'] );
		$this->assertSame( 'Печать актов', $row['handover_act']['bulk_label'] );
		$this->assertSame( 'Реестр', $row['manifest']['bulk_label'], 'no bulk_label => the single-order label' );
	}

	/** #1257: a source that declares nothing keeps today's waybill / barcode — the CDEK plugin is unchanged. */
	public function test_a_source_that_declares_nothing_keeps_the_built_in_labels_and_bulk_wording(): void {
		$source = new class() implements Bulk_Document_Source {
			public function get_document_types( \WC_Order $order ): array {
				return [ 'waybill', 'barcode' ];
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}

			public function get_bulk_document_types(): array {
				return [ 'waybill', 'barcode' ];
			}

			public function get_bulk_document_limit(): int {
				return 100;
			}

			public function get_bulk_document( array $orders, string $type ): Document_Result {
				return Document_Result::pending();
			}
		};

		$row = array_column( $this->actions( $source )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertSame( 'Накладная', $row['waybill']['label'] );
		$this->assertSame( 'Печать накладных', $row['waybill']['bulk_label'] );
		$this->assertSame( 'Штрихкод', $row['barcode']['label'] );
		$this->assertSame( 'Печать штрихкодов', $row['barcode']['bulk_label'] );
		$this->assertTrue( $row['waybill']['document'] );
	}

	/** #1257: a declaration for a built-in key overrides its label; one without a usable label is ignored. */
	public function test_a_declaration_overrides_a_default_label_and_one_without_a_label_is_ignored(): void {
		$source = $this->declaring_source(
			[
				'waybill' => [ 'label' => 'Сопроводительный лист' ],
				'barcode' => [ 'label' => '  ' ],
			],
			[]
		);

		$row = array_column( $this->actions( $source )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertSame( 'Сопроводительный лист', $row['waybill']['label'] );
		$this->assertSame( 'media-document', $row['waybill']['icon'], 'the built-in glyph stays unless one is declared' );
		$this->assertSame( 'Штрихкод', $row['barcode']['label'], 'an empty label falls back to the framework default' );
	}

	public function test_a_plain_source_never_carries_the_bulk_flag(): void {
		$row = array_column( $this->actions( $this->source( [ 'waybill', 'barcode' ] ) )->for_row( $this->order(), $this->provider() ), null, 'action' );

		$this->assertArrayNotHasKey( 'bulk', $row['waybill'] );
		$this->assertArrayNotHasKey( 'bulk', $row['barcode'] );
	}

	public function test_they_are_never_part_of_the_executable_set(): void {
		$actions  = $this->actions( $this->source( [ 'waybill' ] ) );
		$provider = $this->provider();

		$this->assertNotContains( 'waybill', array_column( $actions->for_order( $this->order(), $provider ), 'action' ) );
		$this->assertFalse( $actions->is_offered( $this->order(), $provider, 'waybill' ), 'the REST action route refuses a document id like any unknown action' );
	}

	public function test_only_the_types_the_source_offers_and_the_framework_labels_appear(): void {
		$actions = $this->actions( $this->source( [ 'barcode', 'invoice' ] ) );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL, 'barcode' ], $this->ids( $actions, $this->provider() ) );
	}

	public function test_no_documents_without_the_support_flag_a_source_or_a_carrier_order(): void {
		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], $this->ids( $this->actions( $this->source( [ 'waybill' ] ) ), $this->provider( false ) ), 'flag off' );

		Orders_Registry::instance()->reset_for_tests();
		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], $this->ids( $this->actions( null ), $this->provider() ), 'no source' );

		Orders_Registry::instance()->reset_for_tests();
		$this->meta['_cdek_carrier_order_id'] = '';
		$this->assertNotContains( 'waybill', $this->ids( $this->actions( $this->source( [ 'waybill' ] ) ), $this->provider() ), 'not exported' );
	}

	public function test_another_managers_edit_lock_greys_out_the_carrier_actions_but_not_a_read_only_download(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [ 'time' => time(), 'user_id' => 7 ];
		$user               = new \stdClass();
		$user->ID           = 7;
		$user->display_name = 'Мария';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$row = $this->actions( $this->source( [ 'waybill' ] ) )->for_row( $this->order(), $this->provider() );

		foreach ( $row as $action ) {
			if ( 'waybill' === $action['action'] ) {
				$this->assertArrayNotHasKey( 'disabled', $action, 'downloading never edits the order' );
			} else {
				$this->assertTrue( $action['disabled'], $action['action'] );
			}
		}
	}

	public function test_the_two_documents_declare_different_icons(): void {
		$row   = $this->actions( $this->source( [ 'waybill', 'barcode' ] ) )->for_row( $this->order(), $this->provider() );
		$icons = array_column( $row, 'icon', 'action' );

		$this->assertSame( 'media-document', $icons['waybill'] );
		$this->assertSame( 'tag', $icons['barcode'] );
		$this->assertNotSame( $icons['waybill'], $icons['barcode'] );
	}

	public function test_the_built_in_carrier_actions_declare_their_icons_and_cancel_is_destructive(): void {
		$row = $this->actions( null )->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ 'update', 'remove' ], array_column( $row, 'icon' ) );
		$this->assertSame( [ false, true ], array_column( $row, 'destructive' ) );
	}

	public function test_is_document_knows_the_two_client_side_downloads_only(): void {
		$this->assertTrue( Order_Actions::is_document( 'waybill' ) );
		$this->assertTrue( Order_Actions::is_document( 'barcode' ) );
		$this->assertFalse( Order_Actions::is_document( Order_Actions::CANCEL ) );
		$this->assertFalse( Order_Actions::is_document( 'call_courier' ) );
	}

	public function test_a_declared_icon_survives_the_filter_and_an_unusable_one_is_dropped(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'woodev_shipping_order_actions' !== $hook ) {
					return $value;
				}

				$value[] = [ 'action' => 'a', 'label' => 'A', 'icon' => 'calendar-alt' ];
				$value[] = [ 'action' => 'b', 'label' => 'B' ];
				$value[] = [ 'action' => 'c', 'label' => 'C', 'icon' => 'x" onload="y' ];
				$value[] = [ 'action' => 'd', 'label' => 'D', 'icon' => [ 'svg' ] ];

				return $value;
			}
		);

		$row = $this->actions( null )->for_order( $this->order(), $this->provider() );
		$by  = array_column( $row, null, 'action' );

		$this->assertSame( 'calendar-alt', $by['a']['icon'] );
		$this->assertArrayNotHasKey( 'icon', $by['b'], 'none declared => none sent; the client draws the neutral glyph' );
		$this->assertArrayNotHasKey( 'icon', $by['c'] );
		$this->assertArrayNotHasKey( 'icon', $by['d'] );
	}
}

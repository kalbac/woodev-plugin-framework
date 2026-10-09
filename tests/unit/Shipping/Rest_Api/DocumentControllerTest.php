<?php
/**
 * Unit: `GET /shipping/orders/{id}/documents/{type}` — carrier document downloads (card #1134).
 *
 * Covers the permission gate (header nonce, query nonce, no nonce, wrong nonce, no capability, unknown order),
 * every state of a {@see Document_Result} (binary, link, link as JSON, pending, failed, thrown, nonsense, empty),
 * the availability gate (support flag, registered source, carrier order id, offered type) and the "downloaded" meta.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Bulk_Document_Source;
use Woodev\Framework\Shipping\Order\Document_Result;
use Woodev\Framework\Shipping\Order\Document_Source;
use Woodev\Framework\Shipping\Rest_Api\Document_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-document-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-document-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-bulk-document-source.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-document-controller.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Document_Controller
 */
final class DocumentControllerTest extends TestCase {

	private const NONCE = 'good-nonce';

	/** @var array<string,mixed> post meta of order 123. */
	private $meta = [];

	/** @var array<int,array<string,mixed>> per-order meta overriding {@see self::$meta} (bulk tests). */
	private $meta_by_order = [];

	/** @var array<string,mixed> meta written during the test. */
	private $written = [];

	/** @var bool */
	private $can = true;

	/** @var string[] log lines. */
	private $logged = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = [
			'_cdek_marker'   => '1',
			'_cdek_order_id' => 'uuid-1',
		];
		$this->written       = [];
		$this->meta_by_order = [];
		$this->can           = true;
		$this->logged  = [];

		Functions\stubs( [ 'add_action', 'add_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static function ( $value ) { return abs( (int) $value ); } );
		Functions\when( 'sanitize_key' )->alias( static function ( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); } );
		Functions\when( 'sanitize_file_name' )->alias( static function ( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name ); } );
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				$scheme = (string) parse_url( (string) $url, PHP_URL_SCHEME );

				return in_array( $scheme, (array) $protocols, true ) ? (string) $url : '';
			}
		);
		Functions\when( 'current_user_can' )->alias( function ( $cap ) { return $this->can && 'edit_shop_orders' === $cap; } );
		Functions\when( 'wp_verify_nonce' )->alias( static function ( $nonce, $action ) { return self::NONCE === $nonce && 'wp_rest' === $action ? 1 : false; } );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta_by_order[ $id ][ $key ] ?? $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ) {
				$this->written[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wc_get_order' )->alias( function ( $id ) { return in_array( (int) $id, [ 123, 124, 125 ], true ) ? $this->order( (int) $id ) : false; } );
		Functions\when( 'wc_get_logger' )->alias(
			function () {
				$logger = Mockery::mock();
				$logger->shouldReceive( 'error' )->andReturnUsing(
					function ( $message ) {
						$this->logged[] = $message;
					}
				);

				return $logger;
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function order( int $id = 123 ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );

		return $order;
	}

	private function controller( ?Document_Source $source, bool $flag = true, bool $with_id_key = true ): Document_Controller {
		$args = [ 'supports_label_printing' => $flag ];

		if ( $with_id_key ) {
			$args['carrier_order_id_meta_key'] = '_cdek_order_id';
		}

		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], $args ) );

		if ( null !== $source ) {
			Orders_Registry::instance()->register_document_source( 'cdek', $source );
		}

		return new Document_Controller( Orders_Registry::instance() );
	}

	/** A source offering the given types and answering every request with `$result` (or throwing it). */
	private function source( $result, array $types = [ 'waybill', 'barcode' ] ): Document_Source {
		return new class( $result, $types ) implements Document_Source {
			private $result;
			private $types;

			public function __construct( $result, array $types ) {
				$this->result = $result;
				$this->types  = $types;
			}

			public function get_document_types( \WC_Order $order ): array {
				return $this->types;
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				if ( $this->result instanceof \Throwable ) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}

	private function request( array $params = [], array $headers = [ 'X-WP-Nonce' => self::NONCE ] ): \WP_REST_Request {
		return new \WP_REST_Request( array_merge( [ 'id' => 123, 'type' => 'waybill' ], $params ), $headers );
	}

	// ----- permission gate -----

	public function test_the_nonce_header_passes_the_permission_check(): void {
		$this->assertTrue( $this->controller( null )->permissions_check( $this->request() ) );
	}

	/**
	 * The orders page used to send the nonce only as `?_wpnonce=`; `get_header()` returns NULL for an absent header,
	 * so the fallback never ran and every download was a 403.
	 */
	public function test_the_query_nonce_passes_when_no_header_is_sent(): void {
		$this->assertTrue( $this->controller( null )->permissions_check( $this->request( [ '_wpnonce' => self::NONCE ], [] ) ) );
	}

	public function test_no_nonce_a_wrong_nonce_or_no_capability_is_forbidden(): void {
		$controller = $this->controller( null );

		foreach ( [ $this->request( [], [] ), $this->request( [], [ 'X-WP-Nonce' => 'stale' ] ), $this->request( [ '_wpnonce' => 'stale' ], [] ) ] as $request ) {
			$result = $controller->permissions_check( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 403, $result->data['status'] );
		}

		$this->can = false;
		$result    = $controller->permissions_check( $this->request() );

		$this->assertSame( 403, $result->data['status'] );
	}

	public function test_an_unknown_order_is_a_404_after_the_nonce_passed(): void {
		$result = $this->controller( null )->permissions_check( $this->request( [ 'id' => 999 ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->data['status'] );
	}

	// ----- availability gate -----

	public function test_the_support_flag_source_carrier_order_id_and_offered_type_are_all_required(): void {
		$ready = Document_Result::binary( '%PDF-' );

		$cases = [
			'flag off'          => $this->controller( $this->source( $ready ), false ),
			'no source'         => $this->controller( null ),
			'no carrier id key' => $this->controller( $this->source( $ready ), true, false ),
		];

		foreach ( $cases as $name => $controller ) {
			$result = $controller->download( $this->request() );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 404, $result->data['status'], $name );
			Orders_Registry::instance()->reset_for_tests();
		}

		// Carrier id present as a key but the order has none yet.
		$this->meta['_cdek_order_id'] = '';
		$this->assertSame( 404, $this->controller( $this->source( $ready ) )->download( $this->request() )->data['status'] );
		Orders_Registry::instance()->reset_for_tests();

		// A type the source does not offer.
		$this->meta['_cdek_order_id'] = 'uuid-1';
		$this->assertSame( 404, $this->controller( $this->source( $ready, [ 'barcode' ] ) )->download( $this->request() )->data['status'] );
	}

	// ----- results -----

	public function test_a_binary_result_is_a_pdf_attachment_with_a_safe_filename_and_marks_the_download(): void {
		$response = $this->controller( $this->source( Document_Result::binary( "%PDF-1.4\nbody" ) ) )->download( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( "%PDF-1.4\nbody", $response->get_data() );
		$this->assertSame( 'application/pdf', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'attachment; filename="order-123-waybill.pdf"', $response->get_headers()['Content-Disposition'] );
		$this->assertArrayHasKey( '_woodev_shipping_document_downloaded_cdek_waybill', $this->written );
		$this->assertIsInt( $this->written['_woodev_shipping_document_downloaded_cdek_waybill'] );
	}

	public function test_the_type_never_reaches_the_filename_unsanitised(): void {
		$source   = $this->source( Document_Result::binary( '%PDF-' ), [ 'way"bill' ] );
		$response = $this->controller( $source )->download( $this->request( [ 'type' => 'way"bill' ] ) );

		// sanitize_key() strips the quote, so the requested type no longer matches an offered one.
		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	public function test_a_link_result_redirects_with_a_302_and_marks_the_download(): void {
		$response = $this->controller( $this->source( Document_Result::url( 'https://carrier.example/doc.pdf' ) ) )->download( $this->request() );

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( 'https://carrier.example/doc.pdf', $response->get_headers()['Location'] );
		$this->assertArrayHasKey( '_woodev_shipping_document_downloaded_cdek_waybill', $this->written );
	}

	public function test_a_link_result_is_json_for_the_admin_ui(): void {
		$response = $this->controller( $this->source( Document_Result::url( 'https://carrier.example/doc.pdf' ) ) )->download( $this->request( [ 'format' => 'json' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'status' => 'url',
				'url'    => 'https://carrier.example/doc.pdf',
			],
			$response->get_data()
		);
	}

	public function test_only_https_links_are_followed(): void {
		foreach ( [ 'http://carrier.example/doc.pdf', 'javascript:alert(1)', '' ] as $bad ) {
			$result = $this->controller( $this->source( Document_Result::url( $bad ) ) )->download( $this->request() );

			$this->assertInstanceOf( \WP_Error::class, $result, $bad );
			$this->assertSame( 502, $result->data['status'], $bad );
			$this->assertSame( [], $this->written, $bad . ' is not marked as downloaded' );
			Orders_Registry::instance()->reset_for_tests();
		}
	}

	public function test_a_pending_result_is_202_with_the_retry_hint_and_is_not_marked(): void {
		$response = $this->controller( $this->source( Document_Result::pending( 7 ) ) )->download( $this->request() );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( '7', $response->get_headers()['Retry-After'] );
		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertSame( 7, $response->get_data()['retry_after'] );
		$this->assertNotSame( '', $response->get_data()['message'] );
		$this->assertSame( [], $this->written );
	}

	public function test_failures_are_a_502_with_a_generic_message_and_the_reason_goes_to_the_log(): void {
		$cases = [
			'failed'    => [ Document_Result::failed( 'order not found at the carrier' ), 'order not found at the carrier' ],
			'thrown'    => [ new \RuntimeException( 'carrier timed out' ), 'carrier timed out' ],
			'nonsense'  => [ null, 'Document_Result' ],
			'empty pdf' => [ Document_Result::binary( '' ), null ],
		];

		foreach ( $cases as $name => [ $result, $logged ] ) {
			$this->logged = [];
			$source       = new class( $result ) implements Document_Source {
				private $result;

				public function __construct( $result ) {
					$this->result = $result;
				}

				public function get_document_types( \WC_Order $order ): array {
					return [ 'waybill' ];
				}

				// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
				public function get_document( \WC_Order $order, string $type ): Document_Result {
					if ( $this->result instanceof \Throwable ) {
						throw $this->result;
					}

					return $this->result;
				}
			};

			$response = $this->controller( $source )->download( $this->request() );

			$this->assertInstanceOf( \WP_Error::class, $response, $name );
			$this->assertSame( 502, $response->data['status'], $name );
			$this->assertStringNotContainsString( 'carrier timed out', $response->message, 'the carrier text never reaches the merchant: ' . $name );

			if ( null !== $logged ) {
				$this->assertStringContainsString( $logged, implode( "\n", $this->logged ), $name . ' is logged' );
			}

			$this->assertSame( [], $this->written, $name );
			Orders_Registry::instance()->reset_for_tests();
		}
	}

	// ----- serving the bytes -----

	public function test_the_pdf_body_is_echoed_verbatim_only_for_this_route(): void {
		$controller = $this->controller( null );
		$pdf        = new \WP_REST_Response( "%PDF-1.4\n\"quoted\"", 200, [ 'Content-Type' => 'application/pdf' ] );
		$route      = new class() extends \WP_REST_Request {
			public function get_route() {
				return '/woodev/v1/shipping/orders/123/documents/waybill';
			}
		};

		ob_start();
		$served = $controller->serve_binary_response( false, $pdf, $route, null );
		$out    = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( "%PDF-1.4\n\"quoted\"", $out, 'not JSON-encoded' );

		$other = new class() extends \WP_REST_Request {
			public function get_route() {
				return '/woodev/v1/shipping/orders/123/actions/export';
			}
		};

		ob_start();
		$served = $controller->serve_binary_response( false, $pdf, $other, null );
		$out    = ob_get_clean();

		$this->assertFalse( $served, 'another route is left to the default encoder' );
		$this->assertSame( '', $out );

		ob_start();
		$served = $controller->serve_binary_response( false, new \WP_REST_Response( [ 'status' => 'pending' ], 202 ), $route, null );
		ob_end_clean();

		$this->assertFalse( $served, 'a JSON answer on this route is left alone' );
	}

	// ----- bulk: one document for several orders (#1192) -----

	/** A bulk-capable source answering `$result`, recording what it was asked. */
	private function bulk_source( $result, int $limit = 100, array $types = [ 'waybill', 'barcode' ] ): Bulk_Document_Source {
		return new class( $result, $limit, $types ) implements Bulk_Document_Source {
			public $asked = [];
			private $result;
			private $limit;
			private $types;

			public function __construct( $result, int $limit, array $types ) {
				$this->result = $result;
				$this->limit  = $limit;
				$this->types  = $types;
			}

			public function get_document_types( \WC_Order $order ): array {
				return [ 'waybill', 'barcode' ];
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}

			public function get_bulk_document_types(): array {
				return $this->types;
			}

			public function get_bulk_document_limit(): int {
				return $this->limit;
			}

			public function get_bulk_document( array $orders, string $type ): Document_Result {
				$this->asked[] = [ array_map( static function ( $order ) { return $order->get_id(); }, $orders ), $type ];

				if ( $this->result instanceof \Throwable ) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}

	private function bulk_request( $ids, array $params = [], string $type = 'waybill' ): \WP_REST_Request {
		return new \WP_REST_Request( array_merge( [ 'type' => $type, 'ids' => $ids ], $params ), [ 'X-WP-Nonce' => self::NONCE ] );
	}

	public function test_the_bulk_route_has_the_same_permission_gate_without_needing_an_order(): void {
		$controller = $this->controller( null );

		$this->assertTrue( $controller->bulk_permissions_check( $this->bulk_request( '1,2' ) ) );

		$this->can = false;
		$result    = $controller->bulk_permissions_check( $this->bulk_request( '1,2' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->data['status'] );

		$this->can = true;
		$this->assertSame( 403, $controller->bulk_permissions_check( new \WP_REST_Request( [ 'ids' => '1' ], [ 'X-WP-Nonce' => 'stale' ] ) )->data['status'] );
	}

	public function test_a_bulk_request_hands_the_carrier_one_set_in_the_order_given_and_serves_one_pdf(): void {
		$source   = $this->bulk_source( Document_Result::binary( '%PDF-bulk' ) );
		$response = $this->controller( $source )->download_bulk( $this->bulk_request( '125,123,124' ) );

		$this->assertSame( [ [ [ 125, 123, 124 ], 'waybill' ] ], $source->asked, 'ONE carrier call for the whole set' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '%PDF-bulk', $response->get_data() );
		$this->assertSame( 'application/pdf', $response->get_headers()['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^attachment; filename="orders-waybill-\d{8}\.pdf"$/', $response->get_headers()['Content-Disposition'] );
		$this->assertArrayNotHasKey( 'X-Woodev-Skipped', $response->get_headers(), 'nobody left out => no header' );
		$this->assertArrayHasKey( '_woodev_shipping_document_downloaded_cdek_waybill', $this->written );
	}

	public function test_ids_may_be_an_array_or_a_comma_list_and_duplicates_collapse(): void {
		$source = $this->bulk_source( Document_Result::binary( '%PDF-' ) );
		$this->controller( $source )->download_bulk( $this->bulk_request( [ 124, '123', 124, 0, 'x' ] ) );

		$this->assertSame( [ 124, 123 ], $source->asked[0][0] );
	}

	/** The carrier must be told who it left out — CDEK skips an unknown order and only warns. */
	public function test_orders_the_carrier_left_out_are_named_in_a_header(): void {
		$source   = $this->bulk_source( Document_Result::binary( '%PDF-' )->with_skipped( [ 124, 999 ] ) );
		$response = $this->controller( $source )->download_bulk( $this->bulk_request( '123,124' ) );

		$this->assertSame( '124:carrier_skipped', $response->get_headers()['X-Woodev-Skipped'], '999 was never sent, so it cannot be "skipped"' );
		$this->assertArrayNotHasKey( '_woodev_shipping_document_downloaded_cdek_waybill_124', $this->written );
	}

	public function test_orders_the_framework_cannot_print_are_left_out_with_their_reason_and_never_reach_the_carrier(): void {
		$this->meta_by_order[124] = [ '_cdek_order_id' => '' ];                    // not exported yet.
		$source                   = $this->bulk_source( Document_Result::binary( '%PDF-' ) );
		$response                 = $this->controller( $source )->download_bulk( $this->bulk_request( '123,124,999' ) );

		$this->assertSame( [ [ [ 123 ], 'waybill' ] ], $source->asked );
		$this->assertSame( '124:not_exported,999:not_found', $response->get_headers()['X-Woodev-Skipped'] );
	}

	public function test_an_order_of_another_carrier_or_without_the_document_type_is_left_out(): void {
		$other = Mockery::mock( '\WC_Order' );
		$other->shouldReceive( 'get_id' )->andReturn( 125 );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $other ) { return 125 === (int) $id ? $other : ( 123 === (int) $id ? $this->order() : false ); } );
		// 125 carries no marker meta => no provider matches it.
		$this->meta_by_order[125] = [ '_cdek_marker' => '' ];

		$source   = $this->bulk_source( Document_Result::binary( '%PDF-' ) );
		$response = $this->controller( $source )->download_bulk( $this->bulk_request( '123,125' ) );

		$this->assertSame( '125:not_shipping', $response->get_headers()['X-Woodev-Skipped'] );
	}

	public function test_nothing_printable_is_a_422_not_an_empty_pdf(): void {
		$this->meta_by_order[123] = [ '_cdek_order_id' => '' ];
		$result                   = $this->controller( $this->bulk_source( Document_Result::binary( '%PDF-' ) ) )->download_bulk( $this->bulk_request( '123,999' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->data['status'] );
		$this->assertSame( 'woodev_document_nothing_to_print', $result->get_error_code() );
	}

	public function test_a_selection_of_several_carriers_is_refused_with_a_clear_message(): void {
		$controller = $this->controller( $this->bulk_source( Document_Result::binary( '%PDF-' ) ) );
		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'yandex', 'Яндекс', '_ya_marker', [ 'ya' ], [ 'supports_label_printing' => true, 'carrier_order_id_meta_key' => '_ya_order_id' ] ) );
		Orders_Registry::instance()->register_document_source( 'yandex', $this->bulk_source( Document_Result::binary( '%PDF-' ) ) );
		$this->meta_by_order[124] = [ '_cdek_marker' => '', '_ya_marker' => '1', '_ya_order_id' => 'Y-1' ];

		$result = $controller->download_bulk( $this->bulk_request( '123,124' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->data['status'] );
		$this->assertSame( 'woodev_document_mixed_carriers', $result->get_error_code() );
	}

	public function test_more_orders_than_the_carrier_takes_are_refused_not_split(): void {
		$source = $this->bulk_source( Document_Result::binary( '%PDF-' ), 2 );
		$result = $this->controller( $source )->download_bulk( $this->bulk_request( '123,124,125' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->data['status'] );
		$this->assertSame( 'woodev_document_bulk_too_many', $result->get_error_code() );
		$this->assertStringContainsString( '2', $result->get_error_message() );
		$this->assertSame( [], $source->asked, 'the carrier is not called' );
	}

	public function test_a_carrier_without_the_bulk_capability_or_for_this_type_is_not_offered_it(): void {
		$plain = $this->controller( $this->source( Document_Result::binary( '%PDF-' ) ) )->download_bulk( $this->bulk_request( '123' ) );
		$this->assertSame( 404, $plain->data['status'] );
		Orders_Registry::instance()->reset_for_tests();

		$barcode_only = $this->controller( $this->bulk_source( Document_Result::binary( '%PDF-' ), 100, [ 'barcode' ] ) )->download_bulk( $this->bulk_request( '123' ) );
		$this->assertSame( 404, $barcode_only->data['status'] );
	}

	public function test_a_bulk_pending_result_is_the_same_202_contract_and_marks_nothing(): void {
		$response = $this->controller( $this->bulk_source( Document_Result::pending( 4 ) ) )->download_bulk( $this->bulk_request( '123,124' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( '4', $response->get_headers()['Retry-After'] );
		$this->assertSame( 4, $response->get_data()['retry_after'] );
		$this->assertSame( [], $this->written );
	}

	public function test_a_bulk_link_is_json_with_the_skipped_list(): void {
		$source   = $this->bulk_source( Document_Result::url( 'https://carrier.example/all.pdf' )->with_skipped( [ 124 ] ) );
		$response = $this->controller( $source )->download_bulk( $this->bulk_request( '123,124', [ 'format' => 'json' ] ) );

		$this->assertSame(
			[
				'status'  => 'url',
				'url'     => 'https://carrier.example/all.pdf',
				'skipped' => [ [ 'id' => 124, 'code' => 'carrier_skipped' ] ],
			],
			$response->get_data()
		);
	}

	public function test_bulk_failures_are_a_generic_502_and_the_reason_is_logged(): void {
		foreach ( [ Document_Result::failed( 'quota' ), new \RuntimeException( 'carrier timed out' ), null ] as $result ) {
			$this->logged = [];
			$response     = $this->controller( $this->bulk_source( $result ) )->download_bulk( $this->bulk_request( '123' ) );

			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 502, $response->data['status'] );
			$this->assertNotSame( [], $this->logged );
			$this->assertStringNotContainsString( 'carrier timed out', $response->get_error_message() );
			Orders_Registry::instance()->reset_for_tests();
		}
	}

	public function test_the_ids_argument_must_name_orders_and_stays_below_the_ceiling(): void {
		$this->assertTrue( Document_Controller::validate_bulk_ids( '1,2,3' ) );
		$this->assertTrue( Document_Controller::validate_bulk_ids( [ 1, 2 ] ) );

		foreach ( [ '', [], 'x,0' ] as $empty ) {
			$this->assertInstanceOf( \WP_Error::class, Document_Controller::validate_bulk_ids( $empty ) );
		}

		$too_many = Document_Controller::validate_bulk_ids( range( 1, 501 ) );
		$this->assertInstanceOf( \WP_Error::class, $too_many );
		$this->assertSame( 400, $too_many->data['status'] );
		$this->assertTrue( Document_Controller::validate_bulk_ids( range( 1, 500 ) ) );
	}

	public function test_the_bulk_route_is_served_as_raw_pdf_bytes_too(): void {
		$controller = $this->controller( null );
		$pdf        = new \WP_REST_Response( '%PDF-bulk', 200, [ 'Content-Type' => 'application/pdf' ] );
		$route      = new class() extends \WP_REST_Request {
			public function get_route() {
				return '/woodev/v1/shipping/orders/documents/waybill';
			}
		};

		ob_start();
		$served = $controller->serve_binary_response( false, $pdf, $route, null );
		$out    = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( '%PDF-bulk', $out );
	}
}

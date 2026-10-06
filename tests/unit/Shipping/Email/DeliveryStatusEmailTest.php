<?php
/**
 * Unit tests for shipment status emails.
 *
 * @package Woodev\Tests\Unit\Shipping\Email
 */

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( __NAMESPACE__ . '\\OrderUtil', false ) ) {
		class OrderUtil {
			public static bool $hpos_enabled = false;
			public static function custom_orders_table_usage_is_enabled(): bool { return self::$hpos_enabled; }
		}
	}
}

namespace {
	if ( ! class_exists( 'WC_Email' ) ) {
		/** Minimal WooCommerce email surface for unit tests. */
		class WC_Email {
			public static array $sent = [];
			public string $id = '';
			public string $title = '';
			public string $description = '';
			public string $enabled = 'yes';
			public string $recipient = '';
			public bool $customer_email = false;
			public string $template_html = '';
			public string $template_plain = '';
			public string $template_base = '';
			public $object;
			public array $form_fields = [];
			public array $placeholders = [];

			public function __construct() {
				$this->init_form_fields();
				$this->enabled = $this->form_fields['enabled']['default'];
			}

			public function init_form_fields() {}
			public function get_option( string $key, $default = '' ) { return $this->form_fields[ $key ]['default'] ?? $default; }
			public function is_enabled(): string { return $this->enabled; }
			public function is_valid(): bool { return true; }
			public function get_recipient(): string { return $this->recipient; }
			public function get_subject(): string { return $this->format_string( $this->get_option( 'subject', $this->get_default_subject() ) ); }
			public function get_heading(): string { return $this->format_string( $this->get_option( 'heading', $this->get_default_heading() ) ); }
			public function get_content(): string { return $this->format_string( $this->get_option( 'body', '' ) ); }
			public function get_headers(): string { return ''; }
			public function get_attachments(): array { return []; }
			public function get_email_type_options(): array { return [ 'html' => 'HTML' ]; }
			public function format_string( string $text ): string { return strtr( $text, $this->placeholders ); }
			public function send( $to, $subject, $message, $headers, $attachments ): bool { self::$sent[] = [ $to, $subject, $message ]; return true; }
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Email {

use Automattic\WooCommerce\Utilities\OrderUtil;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Email\Delivery_Status_Email;
use Woodev\Framework\Shipping\Email\Delivery_Status_Emails;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/email/class-delivery-status-email.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/email/class-delivery-status-emails.php';

/** @covers \Woodev\Framework\Shipping\Email\Delivery_Status_Email */
final class DeliveryStatusEmailTest extends TestCase {
	/** @var array<string,mixed> Order meta fake. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->meta = [];
		OrderUtil::$hpos_enabled = false;
		\WC_Email::$sent = [];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ) { $this->meta[ $key ] = $value; return true; } );
		Functions\when( 'sanitize_key' )->alias( static function ( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $key ) ); } );
		Functions\when( 'is_email' )->alias( static function ( string $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; } );
	}

	public function test_status_triggers_once_and_resolves_available_placeholders(): void {
		$order = $this->order( 'buyer@example.test' );
		$this->meta['_tracking'] = 'TRK 42';
		$provider = Orders_Provider::create( 'test', 'Перевозчик', '_marker', [ 'test_shipping' ], [ 'tracking_meta_key' => '_tracking', 'tracking_url_template' => 'https://track.test/{tracking}' ] );
		$email = $this->email( [ Delivery_Status::CREATED ], true, 'Заказ {order_number}: {tracking_number}' );

		$email->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );
		$email->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );

		$this->assertCount( 1, \WC_Email::$sent );
		$this->assertSame( 'Заказ 123: TRK 42', \WC_Email::$sent[0][1] );
		$this->assertSame( 'yes', $this->meta['_woodev_delivery_email_customer_shipment_created_created'] );
	}

	public function test_missing_values_become_blank_and_no_buyer_email_sends_nothing(): void {
		$provider = Orders_Provider::create( 'test', 'Перевозчик', '_marker', [ 'test_shipping' ] );
		$email = $this->email( [ Delivery_Status::DELIVERED ], true, 'Заказ {order_number} — {tracking_number}' );
		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::DELIVERED, $provider );
		$this->assertSame( 'Заказ 123 — ', \WC_Email::$sent[0][1] );

		$email->maybe_trigger( $this->order( '' ), null, Delivery_Status::DELIVERED, $provider );
		$this->assertCount( 1, \WC_Email::$sent );
	}

	public function test_exception_email_is_disabled_by_default(): void {
		$email = $this->email( [ Delivery_Status::RETURNED, Delivery_Status::FAILED ], false, 'Issue' );
		$this->assertSame( 'no', $email->is_enabled() );
		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::FAILED, Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] ) );
		$this->assertCount( 0, \WC_Email::$sent );
	}

	public function test_hpos_meta_path_is_used_for_deduplication(): void {
		OrderUtil::$hpos_enabled = true;
		$email = $this->email( [ Delivery_Status::CREATED ], true, 'Order {order_number}' );
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$order = $this->order( 'buyer@example.test' );
		$email->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );
		$email->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );
		$this->assertCount( 1, \WC_Email::$sent );
		$this->assertSame( 'yes', $this->meta['_woodev_delivery_email_customer_shipment_created_created'] );
	}

	public function test_registered_emails_cover_each_ready_made_status_and_default_toggle(): void {
		$emails = Delivery_Status_Emails::instance()->register_emails( [] );
		$this->assertCount( 4, $emails );
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$order    = $this->order( 'buyer@example.test' );
		$events   = [
			[ 'customer_shipment_created', Delivery_Status::CREATED ],
			[ 'customer_shipment_created', Delivery_Status::IN_TRANSIT ],
			[ 'customer_shipment_pickup', Delivery_Status::READY_FOR_PICKUP ],
			[ 'customer_shipment_delivered', Delivery_Status::DELIVERED ],
			[ 'customer_shipment_exception', Delivery_Status::RETURNED ],
			[ 'customer_shipment_exception', Delivery_Status::FAILED ],
		];
		foreach ( $events as [ $email_id, $status ] ) {
			$emails[ $email_id ]->maybe_trigger( $order, null, $status, $provider );
		}
		$this->assertCount( 4, \WC_Email::$sent, 'The four ON states send; returned and failed remain OFF by default.' );
	}

	private function email( array $statuses, bool $enabled, string $subject ): Delivery_Status_Email {
		return new Delivery_Status_Email( 'customer_shipment_created', 'Status', 'Test', $statuses, $subject, 'Heading', 'Body', $enabled );
	}

	private function order( string $email ): \WC_Order {
		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( string $key ) { return $this->meta[ $key ] ?? ''; } );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( string $key, $value ) { $this->meta[ $key ] = $value; } );
		$order->shouldReceive( 'save_meta_data' )->zeroOrMoreTimes();
		$order->shouldReceive( 'get_order_number' )->andReturn( '123' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'save' )->zeroOrMoreTimes();
		return $order;
	}
}
}

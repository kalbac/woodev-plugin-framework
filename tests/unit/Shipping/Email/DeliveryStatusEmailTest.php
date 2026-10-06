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
				$this->enabled      = $this->form_fields['enabled']['default'];
				// What WC_Email's own constructor merges in, and what a status email must not wipe.
				$this->placeholders = [ '{site_title}' => 'Мой магазин' ];
			}

			public function init_form_fields() {}
			public function get_option( string $key, $default = '' ) { return $this->form_fields[ $key ]['default'] ?? $default; }
			/** Like WooCommerce (class-wc-email.php): a BOOLEAN, `'yes' === $this->enabled`. A stub that answered 'yes'/'no' hid the bug of comparing it with 'yes'. */
			public function is_enabled() { return 'yes' === $this->enabled; }
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
		$property = new \ReflectionProperty( Delivery_Status_Emails::class, 'emails' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( Delivery_Status_Emails::instance(), [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ) { $this->meta[ $key ] = $value; return true; } );
		Functions\when( 'sanitize_key' )->alias( static function ( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $key ) ); } );
		Functions\when( 'is_email' )->alias( static function ( string $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; } );
	}

	public function test_russian_catalogue_supplies_short_titles_and_the_sent_body_uses_the_carrier_seam(): void {
		$catalogue = file_get_contents( dirname( __DIR__, 4 ) . '/woodev/languages/woodev-plugin-framework-ru_RU.po' );
		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"/m', $catalogue, $matches, PREG_SET_ORDER );
		$translations = [];
		foreach ( $matches as $match ) {
			$translations[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
		}
		Functions\when( '__' )->alias( static fn( $msgid, $domain = '' ) => $translations[ $msgid ] ?? $msgid );
		$emails = Delivery_Status_Emails::instance()->register_emails( [] );
		$this->assertSame( [ 'Доставка: Передан в доставку', 'Доставка: Ожидает в ПВЗ', 'Доставка: Доставлено', 'Доставка: Возврат' ], array_map( static fn( $email ) => $email->title, array_values( $emails ) ) );
		$provider = Orders_Provider::create( 'test', 'CDEK WooCommerce Shipping Method', '_marker', [ 'test_shipping' ] );
		$plugin = Mockery::mock( \Woodev\Framework\Shipping\Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_carrier_name' )->once()->andReturn( 'СДЭК' );
		$registry = \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance();
		$registry->register_provider( $provider, $plugin );
		try {
			$emails['customer_shipment_created']->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
			$this->assertStringContainsString( 'Ваше отправление передано в СДЭК.', \WC_Email::$sent[0][2] );
			$this->assertStringNotContainsString( '{carrier_name}', \WC_Email::$sent[0][2] );
		} finally {
			$registry->reset_for_tests();
		}
	}

	public function test_placeholder_hints_use_wc_tooltips_on_subject_and_body(): void {
		$email = $this->email( [ Delivery_Status::CREATED ], true, 'Subject' );
		$this->assertTrue( $email->form_fields['subject']['desc_tip'] );
		$this->assertTrue( $email->form_fields['body']['desc_tip'] );
		$this->assertStringContainsString( '{carrier_name}', $email->form_fields['subject']['description'] );
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
		$this->assertSame( Delivery_Status::CREATED, $this->meta['_woodev_delivery_email_customer_shipment_created'], 'one flag per email; the value names the status that sent it' );
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
		$this->assertFalse( $email->is_enabled() );
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
		$this->assertSame( Delivery_Status::CREATED, $this->meta['_woodev_delivery_email_customer_shipment_created'] );
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
		$this->assertCount( 3, \WC_Email::$sent, 'created and in_transit are ONE email (sent once), pickup and delivered send; returned and failed stay OFF by default.' );
	}

	public function test_an_enabled_email_sends_and_a_disabled_one_does_not_with_wc_boolean_semantics(): void {
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );

		$on = $this->email( [ Delivery_Status::CREATED ], true, 'On {order_number}' );
		$this->assertTrue( $on->is_enabled(), 'the stub answers like WooCommerce: a bool' );
		$on->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
		$this->assertCount( 1, \WC_Email::$sent );

		\WC_Email::$sent = [];
		$this->meta       = [];
		$off              = $this->email( [ Delivery_Status::CREATED ], false, 'Off' );
		$off->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
		$this->assertCount( 0, \WC_Email::$sent );
	}

	public function test_the_handed_over_email_is_sent_once_across_created_and_in_transit(): void {
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$email    = $this->email( [ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT ], true, 'Order {order_number}' );
		$order    = $this->order( 'buyer@example.test' );

		$email->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );
		$email->maybe_trigger( $order, Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, $provider );
		$email->maybe_trigger( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::IN_TRANSIT, $provider );

		$this->assertCount( 1, \WC_Email::$sent, 'a shipment that passes through both statuses is told once' );
	}

	public function test_returned_then_failed_is_one_exception_email(): void {
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$email    = $this->email( [ Delivery_Status::RETURNED, Delivery_Status::FAILED ], true, 'Problem' );
		$order    = $this->order( 'buyer@example.test' );

		$email->maybe_trigger( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::FAILED, $provider );
		$email->maybe_trigger( $order, Delivery_Status::FAILED, Delivery_Status::RETURNED, $provider );

		$this->assertCount( 1, \WC_Email::$sent );
	}

	public function test_two_orders_and_two_emails_are_deduplicated_independently(): void {
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$created  = new Delivery_Status_Email( 'email_a', 'A', 'A', [ Delivery_Status::CREATED ], 'A', 'A', 'A', true );
		$done     = new Delivery_Status_Email( 'email_b', 'B', 'B', [ Delivery_Status::DELIVERED ], 'B', 'B', 'B', true );
		$order    = $this->order( 'buyer@example.test' );

		$created->maybe_trigger( $order, null, Delivery_Status::CREATED, $provider );
		$done->maybe_trigger( $order, null, Delivery_Status::DELIVERED, $provider );

		$this->assertCount( 2, \WC_Email::$sent );
		$this->assertArrayHasKey( '_woodev_delivery_email_email_a', $this->meta );
		$this->assertArrayHasKey( '_woodev_delivery_email_email_b', $this->meta );
	}

	public function test_woocommerce_placeholders_survive_beside_ours(): void {
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );
		$email    = $this->email( [ Delivery_Status::CREATED ], true, '{site_title}: заказ {order_number}' );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		$this->assertSame( 'Мой магазин: заказ 123', \WC_Email::$sent[0][1] );
	}

	public function test_html_body_escapes_placeholder_values_and_links_the_tracking_url(): void {
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$this->meta['_tracking'] = 'TRK<script>alert(1)</script>';
		$this->meta['_point']    = [ 'address' => 'ул. <b>Ленина</b>, 1' ];
		$provider = Orders_Provider::create(
			'test',
			'Carrier <i>X</i>',
			'_marker',
			[ 'test_shipping' ],
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_template' => 'https://track.test/?n={tracking}',
				'pickup_point_meta_key' => '_point',
			]
		);
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', '{carrier_name} {tracking_number} {pickup_point} {tracking_url}', true );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
		$html = $email->get_content_html();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '<a href="https://track.test/?n=TRK%3Cscript%3Ealert%281%29%3C%2Fscript%3E">', $html, 'the tracking URL is a link' );
	}

	public function test_one_listener_hands_a_published_status_to_every_email_without_the_mailer_being_built_first(): void {
		$emails   = Delivery_Status_Emails::instance();
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );

		$emails->register_emails( [] );
		$emails->dispatch( $this->order( 'buyer@example.test' ), null, Delivery_Status::READY_FOR_PICKUP, $provider );

		$this->assertCount( 1, \WC_Email::$sent );
		$this->assertSame( 'Order 123 is waiting at the pickup point', \WC_Email::$sent[0][1], 'the pickup email, in its English msgid (the catalogue translates it)' );
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

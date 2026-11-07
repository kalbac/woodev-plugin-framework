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
			/** @var array<string,string> Settings the merchant saved (WooCommerce keeps them in the email's option). */
			public array $saved = [];
			/** The locale in force: the administrator's until setup_locale() switches to the shop's. */
			public static string $locale = 'en_US';
			/** @var string[] setup_locale() / restore_locale() calls, in order. */
			public static array $locale_log = [];

			public function __construct() {
				$this->init_form_fields();
				$this->enabled      = $this->form_fields['enabled']['default'];
				// What WC_Email's own constructor merges in, and what a status email must not wipe.
				$this->placeholders = [ '{site_title}' => 'Мой магазин' ];
			}

			public function init_form_fields() {}
			/** Like WC_Settings_API::get_option(): the saved value, else the field default; an empty one gives way to $default. */
			public function get_option( string $key, $default = '' ) {
				$value = $this->saved[ $key ] ?? ( $this->form_fields[ $key ]['default'] ?? '' );
				return '' === $value ? $default : $value;
			}
			public function setup_locale() { self::$locale_log[] = 'setup'; self::$locale = 'ru_RU'; }
			public function restore_locale() { self::$locale_log[] = 'restore'; self::$locale = 'en_US'; }
			/** Like WooCommerce (class-wc-email.php): a BOOLEAN, `'yes' === $this->enabled`. A stub that answered 'yes'/'no' hid the bug of comparing it with 'yes'. */
			public function is_enabled() { return 'yes' === $this->enabled; }
			public function is_valid(): bool { return true; }
			public function get_recipient(): string { return $this->recipient; }
			public function get_subject(): string { return $this->format_string( $this->get_option( 'subject', $this->get_default_subject() ) ); }
			public function get_heading(): string { return $this->format_string( $this->get_option( 'heading', $this->get_default_heading() ) ); }
			public function get_content(): string { return $this->format_string( $this->get_option( 'body', $this->get_default_body() ) ); }
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
use Woodev\Framework\Shipping\Email\{Delivery_Status_Email, Delivery_Status_Emails};
use Woodev\Framework\Shipping\Order\{Delivery_Status, Tracking_Shipment};
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
		\WC_Email::$sent       = [];
		\WC_Email::$locale     = 'en_US';
		\WC_Email::$locale_log = [];
		$property = new \ReflectionProperty( Delivery_Status_Emails::class, 'emails' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( Delivery_Status_Emails::instance(), [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Tracking_Shipment validates every link it is given, the template-built ones included.
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
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

	public function test_default_texts_are_translated_in_the_locale_at_send_time_not_the_one_at_construction(): void {
		$russian = [
			'Order {order_number} has been handed over for delivery' => 'Заказ {order_number} передан в доставку',
			'Your order has been handed over for delivery'           => 'Ваш заказ передан в доставку',
			'Your shipment has been handed over to {carrier_name}. Tracking number: {tracking_number}. {tracking_url}' => 'Ваше отправление передано в {carrier_name}. Номер: {tracking_number}. {tracking_url}',
		];
		// The language of whoever is on the site: English until setup_locale() switches to the shop's.
		Functions\when( '__' )->alias( static fn( $msgid, $domain = '' ) => 'ru_RU' === \WC_Email::$locale ? ( $russian[ $msgid ] ?? $msgid ) : $msgid );

		$email = Delivery_Status_Emails::instance()->register_emails( [] )['customer_shipment_created'];

		$this->assertSame( 'Order {order_number} has been handed over for delivery', $email->get_default_subject(), 'read in the administrator\'s locale' );
		$this->assertSame( '', $email->form_fields['subject']['default'], 'an untouched field is empty, not frozen in the constructor locale' );
		$this->assertSame( 'Order {order_number} has been handed over for delivery', $email->form_fields['subject']['placeholder'] );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] ) );

		$this->assertSame( [ 'setup', 'restore' ], \WC_Email::$locale_log );
		$this->assertSame( 'Заказ 123 передан в доставку', \WC_Email::$sent[0][1] );
		$this->assertStringStartsWith( 'Ваше отправление передано в', \WC_Email::$sent[0][2] );
		$this->assertSame( 'Order {order_number} has been handed over for delivery', $email->get_default_subject(), 'the administrator\'s locale is back' );
	}

	public function test_a_text_the_merchant_saved_is_never_replaced_by_a_default(): void {
		Functions\when( '__' )->alias( static fn( $msgid, $domain = '' ) => 'ru_RU' === \WC_Email::$locale ? 'ПЕРЕВОД' : $msgid );
		$email                   = Delivery_Status_Emails::instance()->register_emails( [] )['customer_shipment_created'];
		$email->saved['subject'] = 'My own subject {order_number}';
		$email->saved['body']    = 'My own body';

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] ) );

		$this->assertSame( 'My own subject 123', \WC_Email::$sent[0][1] );
		$this->assertSame( 'My own body', \WC_Email::$sent[0][2] );
	}

	public function test_a_plain_string_default_still_works_and_the_locale_is_restored_when_sending_throws(): void {
		$email = $this->email( [ Delivery_Status::CREATED ], true, 'Plain subject' );
		$this->assertSame( 'Plain subject', $email->get_default_subject() );

		Functions\when( 'apply_filters' )->alias( static function () { throw new \RuntimeException( 'filter failed' ); } );
		try {
			$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] ) );
			$this->fail( 'The filter exception was expected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( [ 'setup', 'restore' ], \WC_Email::$locale_log );
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

	public function test_email_uses_the_declared_absolute_tracking_url(): void {
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$this->meta['_tracking_url'] = 'https://tracking.example.test/request/abc?share=one&source=carrier';
		$this->meta['_tracking'] = 'ABC 123';
		$provider = Orders_Provider::create(
			'test',
			'Carrier',
			'_marker',
			[ 'test_shipping' ],
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_meta_key' => '_tracking_url',
				'tracking_url_template' => 'https://track.test/{tracking}',
			]
		);
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', '{tracking_url}', true );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		$this->assertStringContainsString( '<a href="https://tracking.example.test/request/abc?share=one&source=carrier">', $email->get_content_html() );
	}

	public function test_email_omits_the_absolute_tracking_url_when_the_order_has_no_tracking_number(): void {
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$this->meta['_tracking_url'] = 'https://tracking.example.test/request/abc';
		$provider = Orders_Provider::create(
			'test',
			'Carrier',
			'_marker',
			[ 'test_shipping' ],
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_meta_key' => '_tracking_url',
				'tracking_url_template' => 'https://track.test/{tracking}',
			]
		);
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', '{tracking_url}', true );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		$this->assertSame( '', $email->get_content_html() );
	}

	public function test_email_falls_back_to_template_for_empty_or_invalid_absolute_tracking_url(): void {
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$this->meta['_tracking'] = 'ABC 123';
		$provider = Orders_Provider::create(
			'test',
			'Carrier',
			'_marker',
			[ 'test_shipping' ],
			[
				'tracking_meta_key'      => '_tracking',
				'tracking_url_meta_key'  => '_tracking_url',
				'tracking_url_template'  => 'https://track.test/{tracking}',
			]
		);
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', '{tracking_url}', true );

		$cases = [
			''                                  => '<a href="https://track.test/ABC%20123">',
			'javascript:alert(1)'               => '<a href="https://track.test/ABC%20123">',
			'javascript://example.com/%0Aalert(1)' => '<a href="https://track.test/ABC%20123">',
			'ftp://h.test/x'                    => '<a href="https://track.test/ABC%20123">',
			'data://text/html,x'                => '<a href="https://track.test/ABC%20123">',
			'not a URL'                          => '<a href="https://track.test/ABC%20123">',
			'HTTPS://tracking.example.test/ABC' => '<a href="HTTPS://tracking.example.test/ABC">',
		];
		foreach ( $cases as $absolute_url => $expected_link ) {
			$this->meta['_tracking_url'] = $absolute_url;
			unset( $this->meta['_woodev_delivery_email_customer_shipment_created'] );
			$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
			$this->assertStringContainsString( $expected_link, $email->get_content_html() );
		}
	}

	public function test_one_listener_hands_a_published_status_to_every_email_without_the_mailer_being_built_first(): void {
		$emails   = Delivery_Status_Emails::instance();
		$provider = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ] );

		$emails->register_emails( [] );
		$emails->dispatch( $this->order( 'buyer@example.test' ), null, Delivery_Status::READY_FOR_PICKUP, $provider );

		$this->assertCount( 1, \WC_Email::$sent );
		$this->assertSame( 'Order 123 is waiting at the pickup point', \WC_Email::$sent[0][1], 'the pickup email, in its English msgid (the catalogue translates it)' );
	}

	/**
	 * A carrier that reports the given parcels through its resolver (Ozon-like; Yandex-like when link-only).
	 *
	 * @param Tracking_Shipment[] $shipments parcels.
	 */
	private function carrier_with_shipments( array $shipments ): Orders_Provider {
		return Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ], [ 'shipments_resolver' => static function () use ( $shipments ) { return $shipments; } ] );
	}

	/** Sends the body through the email and returns what HTML mail would carry (the template stub echoes the body). */
	private function html_body_for( Orders_Provider $provider, string $body ): string {
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', $body, true );
		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		return $email->get_content_html();
	}

	/**
	 * Sends the REGISTERED handed-over email (its real default body) for an order whose carrier reports the given
	 * parcels, and returns [ HTML body, plain-text body ] as the mail would carry them.
	 *
	 * @param Tracking_Shipment[] $shipments parcels.
	 * @param array<string,string> $translations msgid => msgstr applied by __() (the ru_RU catalogue, or none).
	 * @param string|null $saved_body a merchant's saved body, or null for the untouched default.
	 * @return array{0:string,1:string}
	 */
	private function registered_created_email_bodies( array $shipments, array $translations = [], ?string $saved_body = null ): array {
		Functions\when( '__' )->alias( static fn( $msgid, $domain = '' ) => $translations[ $msgid ] ?? $msgid );
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		Functions\when( 'esc_url' )->alias( static function ( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; } );
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		// One send per order and email: forget an earlier call's flag.
		unset( $this->meta['_woodev_delivery_email_customer_shipment_created'] );
		// The registry keeps the emails it built, with the default text of the language then in force: start fresh.
		$property = new \ReflectionProperty( Delivery_Status_Emails::class, 'emails' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( Delivery_Status_Emails::instance(), [] );
		$email = Delivery_Status_Emails::instance()->register_emails( [] )['customer_shipment_created'];
		if ( null !== $saved_body ) {
			$email->saved['body'] = $saved_body;
		}
		$provider = $this->carrier_with_shipments( $shipments );
		$plugin   = Mockery::mock( \Woodev\Framework\Shipping\Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_carrier_name' )->andReturn( 'СДЭК' );
		$registry = \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance();
		$registry->register_provider( $provider, $plugin );
		try {
			$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );
		} finally {
			$registry->reset_for_tests();
		}

		return [ $email->get_content_html(), $email->get_content_plain() ];
	}

	/** @return array<string,string> the committed ru_RU catalogue, single-line entries. */
	private function russian_catalogue(): array {
		$catalogue = file_get_contents( dirname( __DIR__, 4 ) . '/woodev/languages/woodev-plugin-framework-ru_RU.po' );
		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"/m', $catalogue, $matches, PREG_SET_ORDER );
		$translations = [];
		foreach ( $matches as $match ) {
			$translations[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
		}

		return $translations;
	}

	/**
	 * Baseline: the default handed-over email for an order with no or one parcel reads exactly as it did in 2.0.24
	 * (critic of PR #1584, §4 of the #1575 spec: M=0 and M=1 are byte-identical).
	 */
	public function test_the_default_handed_over_email_is_unchanged_since_2_0_24_for_no_parcel(): void {
		[ $html, $plain ] = $this->registered_created_email_bodies( [] );
		$this->assertSame( 'Your shipment has been handed over to СДЭК. Tracking number: . ', $html );
		$this->assertSame( 'Your shipment has been handed over to СДЭК. Tracking number: . ', $plain );

		[ $ru_html, $ru_plain ] = $this->registered_created_email_bodies( [], $this->russian_catalogue() );
		$this->assertSame( 'Ваше отправление передано в СДЭК. Номер отслеживания: . ', $ru_html );
		$this->assertSame( 'Ваше отправление передано в СДЭК. Номер отслеживания: . ', $ru_plain );
	}

	public function test_the_default_handed_over_email_is_unchanged_since_2_0_24_for_one_parcel(): void {
		$parcel = [ Tracking_Shipment::create( 'TRK 42', 'https://track.test/TRK%2042' ) ];

		[ $html, $plain ] = $this->registered_created_email_bodies( $parcel );
		$this->assertSame( 'Your shipment has been handed over to СДЭК. Tracking number: TRK 42. <a href="https://track.test/TRK%2042">https://track.test/TRK%2042</a>', $html );
		$this->assertSame( 'Your shipment has been handed over to СДЭК. Tracking number: TRK 42. https://track.test/TRK%2042', $plain );

		[ $ru_html, $ru_plain ] = $this->registered_created_email_bodies( $parcel, $this->russian_catalogue() );
		$this->assertSame( 'Ваше отправление передано в СДЭК. Номер отслеживания: TRK 42. <a href="https://track.test/TRK%2042">https://track.test/TRK%2042</a>', $ru_html );
		$this->assertSame( 'Ваше отправление передано в СДЭК. Номер отслеживания: TRK 42. https://track.test/TRK%2042', $ru_plain );
	}

	/**
	 * Rig defect D3 (#1575): a single parcel that is only a link (Yandex) must not print «Tracking number: .».
	 */
	public function test_one_link_only_parcel_gets_the_link_line_in_both_languages_and_formats(): void {
		$parcel = [ Tracking_Shipment::create( '', 'https://yandex.test/share/1' ) ];

		[ $html, $plain ] = $this->registered_created_email_bodies( $parcel );
		$this->assertSame( "Your shipment has been handed over to СДЭК.\nTrack your shipment: <a href=\"https://yandex.test/share/1\">https://yandex.test/share/1</a>", $html );
		$this->assertSame( "Your shipment has been handed over to СДЭК.\nTrack your shipment: https://yandex.test/share/1", $plain );

		[ $ru_html, $ru_plain ] = $this->registered_created_email_bodies( $parcel, $this->russian_catalogue() );
		$this->assertSame( "Ваше отправление передано в СДЭК.\nСсылка для отслеживания: <a href=\"https://yandex.test/share/1\">https://yandex.test/share/1</a>", $ru_html );
		$this->assertStringNotContainsString( 'Номер отслеживания', $ru_plain );
		$this->assertStringNotContainsString( ': .', $ru_plain );
	}

	public function test_a_body_the_merchant_wrote_is_never_replaced_for_a_link_only_parcel(): void {
		$parcel = [ Tracking_Shipment::create( '', 'https://yandex.test/share/1' ) ];

		[ , $plain ] = $this->registered_created_email_bodies( $parcel, [], 'Our own text: {tracking_number}|{tracking_url}' );

		$this->assertSame( 'Our own text: |https://yandex.test/share/1', $plain );
	}

	public function test_several_parcels_get_the_list_layout_of_the_default_body_in_both_languages(): void {
		$parcels = [ Tracking_Shipment::create( 'A1', 'https://track.test/A1' ), Tracking_Shipment::create( 'B2' ) ];

		[ $html, $plain ] = $this->registered_created_email_bodies( $parcels );
		$this->assertSame( "Your shipment has been handed over to СДЭК.\nTracking number: Shipment 1 of 2: <a href=\"https://track.test/A1\">A1</a><br>Shipment 2 of 2: B2\n", $html );
		$this->assertSame( "Your shipment has been handed over to СДЭК.\nTracking number: Shipment 1 of 2: A1 — https://track.test/A1\nShipment 2 of 2: B2\n", $plain );

		[ $ru_html ] = $this->registered_created_email_bodies( $parcels, $this->russian_catalogue() );
		$this->assertStringStartsWith( "Ваше отправление передано в СДЭК.\nНомер отслеживания: ", $ru_html );
	}

	public function test_a_body_the_merchant_wrote_is_never_replaced_even_for_several_parcels(): void {
		$parcels = [ Tracking_Shipment::create( 'A1' ), Tracking_Shipment::create( 'B2' ) ];

		[ , $plain ] = $this->registered_created_email_bodies( $parcels, [], 'Our own text: {tracking_number}' );

		$this->assertSame( "Our own text: Shipment 1 of 2: A1\nShipment 2 of 2: B2", $plain );
	}

	public function test_no_shipments_leave_both_tracking_placeholders_empty(): void {
		$email = $this->email( [ Delivery_Status::CREATED ], true, '[{tracking_number}][{tracking_url}]' );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $this->carrier_with_shipments( [] ) );

		$this->assertSame( '[][]', \WC_Email::$sent[0][1] );
	}

	public function test_one_shipment_keeps_the_number_and_the_url_exactly_as_before_the_seam(): void {
		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) { return parse_url( $url ); } );
		$this->meta['_tracking'] = 'TRK 42';
		$provider                = Orders_Provider::create( 'test', 'Carrier', '_marker', [ 'test_shipping' ], [ 'tracking_meta_key' => '_tracking', 'tracking_url_template' => 'https://track.test/{tracking}' ] );
		$email                   = $this->email( [ Delivery_Status::CREATED ], true, '{tracking_number}|{tracking_url}' );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		$this->assertSame( 'TRK 42|https://track.test/TRK%2042', \WC_Email::$sent[0][1], 'M=1 reads the same as the inline computation it replaced' );
	}

	public function test_a_link_only_shipment_gives_an_empty_number_and_the_link(): void {
		$email = $this->email( [ Delivery_Status::CREATED ], true, '[{tracking_number}][{tracking_url}]' );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $this->carrier_with_shipments( [ Tracking_Shipment::create( '', 'https://yandex.test/share/1' ) ] ) );

		$this->assertSame( '[][https://yandex.test/share/1]', \WC_Email::$sent[0][1], 'the internal request id never reaches the mail' );
	}

	public function test_several_shipments_are_one_captioned_line_each_in_plain_text(): void {
		Functions\when( 'wc_get_template_html' )->alias( static function ( $template, $args ) { return $args['body']; } );
		$provider = $this->carrier_with_shipments(
			[
				Tracking_Shipment::create( 'A1', 'https://track.test/A1' ),
				Tracking_Shipment::create( 'B2' ),
				Tracking_Shipment::create( '', 'https://track.test/C3' ),
			]
		);
		$email = new Delivery_Status_Email( 'customer_shipment_created', 'T', 'D', [ Delivery_Status::CREATED ], 'S', 'H', '{tracking_number}|{tracking_url}', true );
		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $provider );

		$this->assertSame(
			"Shipment 1 of 3: A1 — https://track.test/A1\nShipment 2 of 3: B2\nShipment 3 of 3: Track shipment — https://track.test/C3|",
			$email->get_content_plain(),
			'{tracking_url} is empty: every link is inside its own line'
		);
	}

	public function test_several_shipments_are_one_linked_line_each_in_html(): void {
		$html = $this->html_body_for(
			$this->carrier_with_shipments(
				[
					Tracking_Shipment::create( 'A1', 'https://track.test/A1' ),
					Tracking_Shipment::create( 'TRK<script>alert(1)</script>' ),
				]
			),
			'{tracking_number}|{tracking_url}'
		);

		$this->assertSame(
			'Shipment 1 of 2: <a href="https://track.test/A1">A1</a><br>Shipment 2 of 2: TRK&lt;script&gt;alert(1)&lt;/script&gt;|',
			$html
		);
	}

	public function test_the_subject_carries_the_numbers_alone_not_the_captioned_lines(): void {
		$email = $this->email( [ Delivery_Status::CREATED ], true, 'Заказ {order_number}: {tracking_number}' );

		$email->maybe_trigger( $this->order( 'buyer@example.test' ), null, Delivery_Status::CREATED, $this->carrier_with_shipments( [ Tracking_Shipment::create( 'A1' ), Tracking_Shipment::create( '', 'https://track.test/x' ), Tracking_Shipment::create( 'B2' ) ] ) );

		$this->assertSame( 'Заказ 123: A1, B2', \WC_Email::$sent[0][1] );
	}

	public function test_the_placeholder_filter_sees_the_readable_text_and_a_replaced_value_is_escaped_not_linked(): void {
		$seen = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) use ( &$seen ) {
				if ( 'woodev_shipping_delivery_email_placeholders' === $tag ) {
					$seen                       = $value['{tracking_number}'];
					$value['{tracking_number}'] = '<b>Custom</b>';
				}

				return $value;
			}
		);
		$provider = $this->carrier_with_shipments( [ Tracking_Shipment::create( 'A1', 'https://track.test/A1' ), Tracking_Shipment::create( 'B2' ) ] );

		$html = $this->html_body_for( $provider, '{tracking_number}' );

		$this->assertSame( "Shipment 1 of 2: A1 — https://track.test/A1\nShipment 2 of 2: B2", $seen );
		$this->assertSame( '&lt;b&gt;Custom&lt;/b&gt;', $html, 'a filter result is escaped like any other value; no markup from the filter' );
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

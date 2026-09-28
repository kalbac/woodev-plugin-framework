<?php
/**
 * Unit: the admin order wizard's payload validation (#710 spec D4, card #968).
 *
 * The admin path validates its OWN input and returns the problems as DATA — a list of
 * `{ field, code, message }` — never a notice. Pinned: every rule, the O12 «only our carriers»
 * rule, the pickup rule (required for a pickup tariff, dropped for any other), the «ship to
 * billing» fallback, and that nothing is read from `$_POST` or a session.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace {

	if ( ! class_exists( 'WC_Product' ) ) {
		/**
		 * Minimal global \WC_Product stand-in.
		 */
		class WC_Product {
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator
	 */
	final class OrderPayloadValidatorTest extends TestCase {

		/** @var array<int,\WC_Product|null> products the fake `wc_get_product()` knows, by id. */
		private $products = [];

		protected function setUp(): void {
			parent::setUp();

			$this->products = [];

			Functions\when( 'wc_clean' )->alias(
				static function ( $value ) {
					return is_string( $value ) ? trim( $value ) : $value;
				}
			);
			Functions\when( 'wc_sanitize_phone_number' )->alias(
				static function ( string $phone ): string {
					return preg_replace( '/[^\d+]/', '', $phone );
				}
			);
			Functions\when( 'wc_format_postcode' )->returnArg( 1 );
			Functions\when( 'wc_format_decimal' )->alias(
				static function ( $number ): string {
					return (string) $number;
				}
			);
			Functions\when( 'sanitize_email' )->alias(
				static function ( string $email ): string {
					return trim( $email );
				}
			);
			Functions\when( 'is_email' )->alias(
				static function ( string $email ): bool {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
				}
			);
			Functions\when( 'email_exists' )->alias(
				static function ( string $email ) {
					return 'taken@example.test' === $email ? 7 : false;
				}
			);
			Functions\when( 'get_userdata' )->alias(
				static function ( int $id ) {
					return 5 === $id ? (object) [ 'ID' => 5 ] : false;
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				function ( int $id ) {
					return $this->products[ $id ] ?? false;
				}
			);
			Functions\when( 'wc_get_order_statuses' )->justReturn(
				[
					'wc-pending'    => 'Ожидает оплаты',
					'wc-processing' => 'Обработка',
					'wc-on-hold'    => 'На удержании',
					'wc-completed'  => 'Выполнен',
					'wc-cancelled'  => 'Отменён',
				]
			);
			Functions\when( 'wc_get_order_status_name' )->alias(
				static function ( string $status ): string {
					return ucfirst( $status );
				}
			);

			Orders_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * @param bool $with_writer whether the carrier declared a marker writer.
		 * @return Orders_Registry
		 */
		private function registry( bool $with_writer = true ): Orders_Registry {
			$args = $with_writer
				? [
					'marker_writer' => static function ( \WC_Order $order, array $context ): void {
					},
				]
				: [];

			$registry = Orders_Registry::instance();
			$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek_courier', 'cdek_pickup' ], $args ) );

			return $registry;
		}

		private function validator( bool $with_writer = true ): Order_Payload_Validator {
			return new Order_Payload_Validator(
				$this->registry( $with_writer ),
				[
					'pickup_method_ids' => static function (): array {
						return [ 'cdek_pickup' ];
					},
					'gateway_ids'       => static function (): array {
						return [ 'cod', 'bacs' ];
					},
					'countries'         => static function (): array {
						return [
							'RU' => 'Россия',
							'KZ' => 'Казахстан',
						];
					},
					'states'            => static function ( string $country ): array {
						return 'RU' === $country ? [ 'МОСКВА' => 'Москва', 'САНКТ-ПЕТЕРБУРГ' => 'Санкт-Петербург' ] : [];
					},
				]
			);
		}

		private function product( int $id, string $type = 'simple', int $parent = 0 ): \WC_Product {
			$product = Mockery::mock( \WC_Product::class );
			$product->shouldReceive( 'is_type' )->andReturnUsing(
				static function ( $asked ) use ( $type ): bool {
					return $asked === $type;
				}
			);
			$product->shouldReceive( 'get_parent_id' )->andReturn( $parent );

			$this->products[ $id ] = $product;

			return $product;
		}

		/**
		 * A payload that validates, to be bent one field at a time.
		 *
		 * @param array<string,mixed> $override top-level keys to replace.
		 * @return array<string,mixed>
		 */
		private function payload( array $override = [] ): array {
			$this->product( 10 );

			return array_replace(
				[
					'customer'       => [ 'id' => 0 ],
					'billing'        => [
						'first_name' => 'Иван',
						'last_name'  => 'Иванов',
						'country'    => 'RU',
						'state'      => 'МОСКВА',
						'city'       => 'Москва',
						'address_1'  => 'ул. Тверская, 1',
						'phone'      => '+7 (999) 123-45-67',
						'email'      => 'ivan@example.test',
					],
					'items'          => [
						[
							'product_id' => 10,
							'quantity'   => 2,
							'price'      => '150',
						],
					],
					'shipping_line'  => [
						'method_id'   => 'cdek_courier',
						'instance_id' => 3,
						'label'       => 'Курьер',
						'cost'        => '350',
						'meta'        => [ 'delivery_time' => '2-3 дня' ],
					],
					'payment_method' => 'cod',
				],
				$override
			);
		}

		/**
		 * @param array<string,mixed> $result a validate() result.
		 * @return string[] `field:code` of every error.
		 */
		private function codes( array $result ): array {
			return array_map(
				static function ( array $error ): string {
					return $error['field'] . ':' . $error['code'];
				},
				$result['errors']
			);
		}

		public function test_a_complete_payload_has_no_errors_and_is_normalised(): void {
			$result = $this->validator()->validate( $this->payload(), false );

			$this->assertSame( [], $result['errors'] );

			$data = $result['data'];

			$this->assertSame( 0, $data['customer']['id'] );
			$this->assertFalse( $data['customer']['create_account'] );
			$this->assertSame( '+79991234567', $data['billing']['phone'] );
			$this->assertSame( 'cdek_courier', $data['shipping_line']['method_id'] );
			$this->assertSame( 'cdek_courier:3', $data['shipping_line']['rate_id'] );
			$this->assertSame( '350', $data['shipping_line']['cost'] );
			$this->assertSame( [ 'delivery_time' => '2-3 дня' ], $data['shipping_line']['meta'] );
			$this->assertSame( 'cdek', $data['shipping_line']['provider']->get_id() );
			$this->assertSame( 'cod', $data['payment_method'] );
			$this->assertSame( 'pending', $data['status'], 'a create defaults to pending' );
			$this->assertNull( $data['pickup_point'] );
			$this->assertCount( 1, $data['items'] );
			$this->assertSame( '150', $data['items'][0]['price'] );
		}

		public function test_an_empty_shipping_address_means_ship_to_billing(): void {
			$data = $this->validator()->validate( $this->payload(), false )['data'];

			$this->assertSame( 'RU', $data['shipping']['country'] );
			$this->assertSame( 'Москва', $data['shipping']['city'] );
			$this->assertSame( 'ул. Тверская, 1', $data['shipping']['address_1'] );
		}

		public function test_a_filled_shipping_address_is_kept_as_is(): void {
			$data = $this->validator()->validate(
				$this->payload( [ 'shipping' => [ 'country' => 'KZ', 'city' => 'Алматы' ] ] ),
				false
			)['data'];

			$this->assertSame( 'KZ', $data['shipping']['country'] );
			$this->assertSame( 'Алматы', $data['shipping']['city'] );
		}

		public function test_a_partly_filled_shipping_address_is_not_silently_replaced_by_billing(): void {
			$result = $this->validator()->validate( $this->payload( [ 'shipping' => [ 'first_name' => 'Пётр' ] ] ), false );

			$this->assertContains( 'shipping.country:country_required', $this->codes( $result ), 'the manager started a second address: it needs its own country' );
		}

		public function test_no_destination_country_is_an_error(): void {
			$payload = $this->payload();
			unset( $payload['billing']['country'], $payload['billing']['state'] );

			$this->assertContains( 'billing.country:country_required', $this->codes( $this->validator()->validate( $payload, false ) ) );
		}

		public function test_an_unknown_country_and_a_foreign_state_are_errors_with_their_field_path(): void {
			$bad_country = $this->payload();
			$bad_country['billing']['country'] = 'ZZ';

			$this->assertContains( 'billing.country:invalid_country', $this->codes( $this->validator()->validate( $bad_country, false ) ) );

			$bad_state = $this->payload();
			$bad_state['billing']['state'] = 'MOW';

			$this->assertContains( 'billing.state:invalid_state', $this->codes( $this->validator()->validate( $bad_state, false ) ) );
		}

		public function test_a_state_is_not_checked_for_a_country_that_has_no_states(): void {
			$payload = $this->payload();
			$payload['billing']['country'] = 'KZ';
			$payload['billing']['state']   = 'whatever';

			$this->assertSame( [], $this->validator()->validate( $payload, false )['errors'] );
		}

		public function test_a_malformed_email_is_an_error(): void {
			$payload = $this->payload();
			$payload['billing']['email'] = 'not-an-email';

			$this->assertContains( 'billing.email:invalid_email', $this->codes( $this->validator()->validate( $payload, false ) ) );
		}

		public function test_an_empty_items_list_and_a_bad_line_are_errors(): void {
			$this->assertContains( 'items:items_required', $this->codes( $this->validator()->validate( $this->payload( [ 'items' => [] ] ), false ) ) );

			$codes = $this->codes(
				$this->validator()->validate(
					$this->payload(
						[
							'items' => [
								[
									'product_id' => 999,
									'quantity'   => 0,
									'price'      => '-5',
								],
							],
						]
					),
					false
				)
			);

			$this->assertContains( 'items.0.quantity:invalid_quantity', $codes );
			$this->assertContains( 'items.0.price:invalid_price', $codes );
			$this->assertContains( 'items.0.product_id:unknown_product', $codes );
		}

		public function test_a_variable_parent_without_a_variation_is_not_orderable(): void {
			$this->product( 20, 'variable' );

			$codes = $this->codes(
				$this->validator()->validate( $this->payload( [ 'items' => [ [ 'product_id' => 20, 'quantity' => 1 ] ] ] ), false )
			);

			$this->assertContains( 'items.0.product_id:unknown_product', $codes );
		}

		public function test_a_variation_must_belong_to_the_product_it_was_sent_with(): void {
			$this->product( 31, 'variation', 20 );

			$ok = $this->validator()->validate(
				$this->payload( [ 'items' => [ [ 'product_id' => 20, 'variation_id' => 31, 'quantity' => 1 ] ] ] ),
				false
			);

			$this->assertSame( [], $ok['errors'] );
			$this->assertNull( $ok['data']['items'][0]['price'], 'an absent price keeps the product own' );

			$other = $this->validator()->validate(
				$this->payload( [ 'items' => [ [ 'product_id' => 21, 'variation_id' => 31, 'quantity' => 1 ] ] ] ),
				false
			);

			$this->assertContains( 'items.0.product_id:unknown_product', $this->codes( $other ) );
		}

		public function test_item_id_is_read_on_an_update_only(): void {
			$items = [ [ 'item_id' => 55, 'product_id' => 10, 'quantity' => 1 ] ];

			$this->assertSame( 55, $this->validator()->validate( $this->payload( [ 'items' => $items ] ), true )['data']['items'][0]['item_id'] );
			$this->assertSame( 0, $this->validator()->validate( $this->payload( [ 'items' => $items ] ), false )['data']['items'][0]['item_id'] );
		}

		public function test_a_foreign_shipping_method_is_refused_o12(): void {
			$payload = $this->payload();
			$payload['shipping_line']['method_id'] = 'flat_rate';

			$this->assertContains( 'shipping_line.method_id:foreign_method', $this->codes( $this->validator()->validate( $payload, false ) ) );
		}

		public function test_a_carrier_without_a_marker_writer_is_refused_and_logged(): void {
			// The validator logs this plugin bug; keep the line out of the test run's output.
			$previous = ini_set( 'error_log', '/dev/null' );

			$codes = $this->codes( $this->validator( false )->validate( $this->payload(), false ) );

			ini_set( 'error_log', false === $previous ? '' : $previous );

			$this->assertContains( 'shipping_line.method_id:carrier_unsupported', $codes );
		}

		public function test_a_missing_shipping_line_and_a_bad_cost_are_errors(): void {
			$payload = $this->payload();
			unset( $payload['shipping_line'] );

			$this->assertContains( 'shipping_line:shipping_required', $this->codes( $this->validator()->validate( $payload, false ) ) );

			$bad_cost = $this->payload();
			$bad_cost['shipping_line']['cost'] = 'free';

			$this->assertContains( 'shipping_line.cost:invalid_cost', $this->codes( $this->validator()->validate( $bad_cost, false ) ) );
		}

		public function test_a_zero_cost_is_a_valid_price(): void {
			$payload = $this->payload();
			$payload['shipping_line']['cost'] = 0;

			$result = $this->validator()->validate( $payload, false );

			$this->assertSame( [], $result['errors'] );
			$this->assertSame( '0', $result['data']['shipping_line']['cost'] );
		}

		public function test_a_pickup_tariff_needs_a_point(): void {
			$payload = $this->payload();
			$payload['shipping_line']['method_id'] = 'cdek_pickup';

			$this->assertContains( 'pickup_point.id:pickup_required', $this->codes( $this->validator()->validate( $payload, false ) ) );

			$payload['pickup_point'] = [ 'id' => 'PVZ-1', 'name' => 'ignored' ];
			$result                  = $this->validator()->validate( $payload, false );

			$this->assertSame( [], $result['errors'] );
			$this->assertSame( [ 'id' => 'PVZ-1' ], $result['data']['pickup_point'] );
		}

		public function test_a_stale_point_is_dropped_for_a_non_pickup_tariff(): void {
			$payload                 = $this->payload();
			$payload['pickup_point'] = [ 'id' => 'PVZ-1' ];

			$result = $this->validator()->validate( $payload, false );

			$this->assertSame( [], $result['errors'] );
			$this->assertNull( $result['data']['pickup_point'] );
		}

		public function test_the_instance_suffix_does_not_hide_a_pickup_method(): void {
			$payload = $this->payload();
			$payload['shipping_line']['method_id']   = 'cdek_pickup';
			$payload['shipping_line']['instance_id'] = 9;

			$this->assertContains( 'pickup_point.id:pickup_required', $this->codes( $this->validator()->validate( $payload, false ) ) );
		}

		public function test_an_unknown_payment_method_is_an_error_and_none_is_allowed(): void {
			$this->assertContains( 'payment_method:unknown_payment_method', $this->codes( $this->validator()->validate( $this->payload( [ 'payment_method' => 'paypal' ] ), false ) ) );

			$none = $this->validator()->validate( $this->payload( [ 'payment_method' => '' ] ), false );

			$this->assertSame( [], $none['errors'] );
			$this->assertSame( '', $none['data']['payment_method'] );
		}

		public function test_statuses_are_normalised_and_checked(): void {
			$this->assertSame( 'processing', $this->validator()->validate( $this->payload( [ 'status' => 'wc-processing' ] ), false )['data']['status'] );
			$this->assertContains( 'status:unknown_status', $this->codes( $this->validator()->validate( $this->payload( [ 'status' => 'shipped-ish' ] ), false ) ) );
		}

		public function test_a_final_status_is_refused_as_a_target_on_update_only(): void {
			$this->assertContains( 'status:final_status', $this->codes( $this->validator()->validate( $this->payload( [ 'status' => 'cancelled' ] ), true ) ) );

			// A create may place an order straight into one (a gift, a channel import).
			$this->assertSame( [], $this->validator()->validate( $this->payload( [ 'status' => 'completed' ] ), false )['errors'] );
		}

		public function test_an_update_without_a_status_keeps_the_current_one(): void {
			$this->assertNull( $this->validator()->validate( $this->payload(), true )['data']['status'] );
		}

		public function test_an_unknown_customer_is_an_error(): void {
			$this->assertContains( 'customer.id:unknown_customer', $this->codes( $this->validator()->validate( $this->payload( [ 'customer' => [ 'id' => 999 ] ] ), false ) ) );
			$this->assertSame( [], $this->validator()->validate( $this->payload( [ 'customer' => [ 'id' => 5 ] ] ), false )['errors'] );
		}

		public function test_create_account_needs_a_free_email(): void {
			$ok = $this->validator()->validate( $this->payload( [ 'customer' => [ 'create_account' => true ] ] ), false );

			$this->assertSame( [], $ok['errors'] );
			$this->assertTrue( $ok['data']['customer']['create_account'] );

			$no_email = $this->payload( [ 'customer' => [ 'create_account' => true ] ] );
			$no_email['billing']['email'] = '';

			$this->assertContains( 'billing.email:account_needs_email', $this->codes( $this->validator()->validate( $no_email, false ) ) );

			$taken = $this->payload( [ 'customer' => [ 'create_account' => true ] ] );
			$taken['billing']['email'] = 'taken@example.test';

			$this->assertContains( 'billing.email:email_exists', $this->codes( $this->validator()->validate( $taken, false ) ) );
		}

		public function test_create_account_is_ignored_for_an_existing_customer(): void {
			$result = $this->validator()->validate( $this->payload( [ 'customer' => [ 'id' => 5, 'create_account' => true ] ] ), false );

			$this->assertFalse( $result['data']['customer']['create_account'] );
		}

		public function test_fields_and_carrier_fields_keep_scalars_only(): void {
			$result = $this->validator()->validate(
				$this->payload(
					[
						'fields'         => [ 'carrier_comment' => ' позвонить ', 'nested' => [ 'x' ] ],
						'carrier_fields' => [ 'declared_value' => 100, 'obj' => new \stdClass() ],
					]
				),
				false
			);

			$this->assertSame( [ 'carrier_comment' => 'позвонить' ], $result['data']['fields'] );
			$this->assertSame( [ 'declared_value' => 100 ], $result['data']['carrier_fields'] );
		}

		public function test_every_problem_is_reported_at_once(): void {
			$result = $this->validator()->validate( [], false );

			$this->assertContains( 'items:items_required', $this->codes( $result ) );
			$this->assertContains( 'shipping_line:shipping_required', $this->codes( $result ) );
			$this->assertContains( 'billing.country:country_required', $this->codes( $result ) );
		}

		public function test_every_error_carries_a_field_a_code_and_a_sentence(): void {
			$result = $this->validator()->validate( [], false );

			foreach ( $result['errors'] as $error ) {
				$this->assertNotSame( '', $error['field'] );
				$this->assertNotSame( '', $error['code'] );
				$this->assertNotSame( '', $error['message'] );
			}
		}
	}
}

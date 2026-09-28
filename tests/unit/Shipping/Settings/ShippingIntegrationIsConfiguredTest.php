<?php
/**
 * Unit tests for the DEFAULT Shipping_Integration::is_configured() (#953).
 *
 * The default used to return `true` unconditionally, so a plugin that forgot to override
 * it reported itself configured while holding no credentials. It now derives the answer
 * from the credentials the integration declares.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace {

	if ( ! class_exists( 'WC_Integration', false ) ) {
		/**
		 * Minimal WooCommerce integration base — same shape as the stub in
		 * ShippingMethodFilterReturnGuardsTest, whichever file loads first wins.
		 */
		class WC_Integration {

			/** @var string */
			public $id;

			/** @var array */
			public $form_fields = [];

			/** @var string */
			public $method_title = '';

			/** @var string */
			public $method_description = '';

			/** @var array */
			public $settings = [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Settings {

	use Woodev\Framework\Shipping\Settings\Shipping_Integration;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Integration double: form fields and stored values are injected, and it does NOT
	 * override is_configured() — that is the method under test.
	 */
	class Woodev_Test_Is_Configured_Integration extends Shipping_Integration {

		/** @var string */
		public $id = 'is_configured_test';

		/** @var array<string, mixed> stored option values */
		public array $values = [];

		/**
		 * @param array<string, array> $fields form fields.
		 * @param array<string, mixed> $values stored values.
		 */
		public function __construct( array $fields = [], array $values = [] ) {
			$this->form_fields = $fields;
			$this->values      = $values;
		}

		/**
		 * @param string $key           option key.
		 * @param mixed  $empty_value   fallback.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			return $this->values[ $key ] ?? $empty_value;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function init_plugin(): Shipping_Plugin {
			throw new \LogicException( 'not needed' );
		}
	}

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Settings\Shipping_Integration
	 */
	final class ShippingIntegrationIsConfiguredTest extends TestCase {

		/**
		 * @param array<string, array> $fields form fields.
		 * @param array<string, mixed> $values stored values.
		 * @return Woodev_Test_Is_Configured_Integration
		 */
		private function integration( array $fields, array $values = [] ): Woodev_Test_Is_Configured_Integration {
			return new Woodev_Test_Is_Configured_Integration( $fields, $values );
		}

		public function test_no_declared_credentials_stays_configured(): void {
			$integration = $this->integration(
				[
					'enable_debug'    => [ 'type' => 'checkbox' ],
					'default_weight'  => [ 'type' => 'number' ],
					'origin_postcode' => [ 'type' => 'text' ],
				]
			);

			$this->assertTrue( $integration->is_configured() );
		}

		public function test_no_fields_at_all_stays_configured(): void {
			$this->assertTrue( $this->integration( [] )->is_configured() );
		}

		public function test_an_empty_password_field_is_not_configured(): void {
			$integration = $this->integration( [ 'api_secret' => [ 'type' => 'password' ] ], [ 'api_secret' => '' ] );

			$this->assertFalse( $integration->is_configured() );
		}

		public function test_a_missing_password_value_is_not_configured(): void {
			$integration = $this->integration( [ 'api_secret' => [ 'type' => 'password' ] ] );

			$this->assertFalse( $integration->is_configured() );
		}

		public function test_a_whitespace_only_value_is_not_configured(): void {
			$integration = $this->integration( [ 'api_secret' => [ 'type' => 'password' ] ], [ 'api_secret' => "  \t" ] );

			$this->assertFalse( $integration->is_configured() );
		}

		public function test_a_filled_password_field_is_configured(): void {
			$integration = $this->integration( [ 'api_secret' => [ 'type' => 'password' ] ], [ 'api_secret' => 's3cret' ] );

			$this->assertTrue( $integration->is_configured() );
		}

		public function test_every_declared_secret_must_be_filled(): void {
			$fields = [
				'api_key'    => [
					'type'     => 'text',
					'required' => true,
				],
				'api_secret' => [ 'type' => 'password' ],
			];

			$this->assertFalse( $this->integration( $fields, [ 'api_key' => 'key' ] )->is_configured() );
			$this->assertFalse( $this->integration( $fields, [ 'api_secret' => 'secret' ] )->is_configured() );
			$this->assertTrue( $this->integration( $fields, [ 'api_key' => 'key', 'api_secret' => 'secret' ] )->is_configured() );
		}

		public function test_an_explicit_required_true_makes_a_text_field_a_credential(): void {
			$fields = [ 'client_id' => [ 'type' => 'text', 'required' => true ] ];

			$this->assertFalse( $this->integration( $fields )->is_configured() );
			$this->assertTrue( $this->integration( $fields, [ 'client_id' => 'abc' ] )->is_configured() );
		}

		public function test_a_plain_text_field_without_required_is_not_a_credential(): void {
			$integration = $this->integration( [ 'account' => [ 'type' => 'text' ] ] );

			$this->assertTrue( $integration->is_configured() );
		}

		public function test_required_false_opts_a_password_field_out(): void {
			$integration = $this->integration(
				[
					'webhook_secret' => [
						'type'     => 'password',
						'required' => false,
					],
				]
			);

			$this->assertTrue( $integration->is_configured() );
		}

		public function test_a_secret_scoped_to_another_environment_is_ignored(): void {
			$fields = [
				'prod_secret' => [
					'type'  => 'password',
					'class' => 'environment-field production-field',
				],
				'test_secret' => [
					'type'  => 'password',
					'class' => 'environment-field test-field',
				],
			];

			// Production is the default environment: only prod_secret counts.
			$this->assertFalse( $this->integration( $fields, [ 'test_secret' => 'x' ] )->is_configured() );
			$this->assertTrue( $this->integration( $fields, [ 'prod_secret' => 'x' ] )->is_configured() );

			// Switch to the test environment: only test_secret counts.
			$this->assertFalse( $this->integration( $fields, [ 'environment' => 'test', 'prod_secret' => 'x' ] )->is_configured() );
			$this->assertTrue( $this->integration( $fields, [ 'environment' => 'test', 'test_secret' => 'x' ] )->is_configured() );
		}

		public function test_an_overriding_subclass_still_wins(): void {
			$integration = new class( [ 'api_secret' => [ 'type' => 'password' ] ] ) extends Woodev_Test_Is_Configured_Integration {

				public function is_configured(): bool {
					return true;
				}
			};

			$this->assertTrue( $integration->is_configured() );
		}
	}
}

<?php
/**
 * Secret masking in the per-plugin settings REST controller (#1406).
 *
 * Assertions on secret values are written as boolean comparisons so a failure
 * never prints a secret into the test output.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WP_REST_Controller', false ) ) {
		class WP_REST_Controller {

			/** @var string */
			protected $namespace;

			/** @var string */
			protected $rest_base;

			public function get_public_item_schema() {
				return [];
			}

			public function add_additional_fields_schema( $schema ) {
				return $schema;
			}

			public function get_endpoint_args_for_item_schema( $method = null ) {
				return [];
			}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-plugin-rest-api-settings.php';

	/**
	 * Class PluginRestApiSettingsSecretMaskTest.
	 */
	class PluginRestApiSettingsSecretMaskTest extends TestCase {

		private const STORED = 'dummy-stored-value';

		private const NEW_VALUE = 'dummy-new-value';

		private const CONSTANT_VALUE = 'dummy-constant-value';

		/** @var string declared default of the 'api_key' fixture (must match the literal in register_settings) */
		private const DEFAULT_SECRET = 'dummy-declared-default';

		/** @var array<string,mixed> in-memory options table */
		private $options = [];

		/**
		 * Defines the constants once per process (constants cannot be undefined).
		 *
		 * @return void
		 */
		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();

			if ( ! defined( 'WOODEV_1406_DEFINED' ) ) {
				define( 'WOODEV_1406_DEFINED', self::CONSTANT_VALUE );
			}
		}

		/**
		 * Builds a controller over an in-memory handler with a secret, a constant-backed
		 * (defined), a constant-backed (undefined) and a plain field.
		 *
		 * @param array<string,mixed> $stored stored options, keyed by setting id.
		 * @return \Woodev_REST_API_Settings
		 */
		private function make_controller( array $stored ): \Woodev_REST_API_Settings {

			foreach ( $stored as $id => $value ) {
				$this->options[ 'woodev_test-plugin_' . $id ] = $value;
			}

			$options = &$this->options;

			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = false ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'update_option' )->alias(
				static function ( $name, $value ) use ( &$options ) {
					$options[ $name ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_option' )->alias(
				static function ( $name ) use ( &$options ) {
					unset( $options[ $name ] );
					return true;
				}
			);
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'rest_ensure_response' )->returnArg();

			$handler = new class( 'test-plugin' ) extends \Woodev_Abstract_Settings {

				/**
				 * Registers the fixture settings.
				 *
				 * @return void
				 */
				protected function register_settings() {
					$this->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Token', 'sensitive' => true ] );
					$this->register_setting( 'api_key', \Woodev_Setting::TYPE_STRING, [ 'name' => 'API key', 'sensitive' => true, 'default' => 'dummy-declared-default' ] );
					$this->register_setting( 'const_defined', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Const', 'constant_name' => 'WOODEV_1406_DEFINED' ] );
					$this->register_setting( 'const_undefined', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Const', 'constant_name' => 'WOODEV_1406_UNDEFINED' ] );
					$this->register_setting( 'title', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Title' ] );
				}
			};

			return new \Woodev_REST_API_Settings( $handler );
		}

		/**
		 * Builds a request carrying the given params.
		 *
		 * @param array<string,mixed> $params request params.
		 * @return \WP_REST_Request
		 */
		private function request( array $params ) {
			$request = Mockery::mock( 'WP_REST_Request' );
			$request->shouldReceive( 'get_param' )->andReturnUsing(
				static function ( $key ) use ( $params ) {
					return $params[ $key ] ?? null;
				}
			);

			return $request;
		}

		/**
		 * Asserts that no value in the response contains the secret.
		 *
		 * @param mixed  $response response data.
		 * @param string $secret   secret to look for.
		 * @return void
		 */
		private function assert_response_lacks( $response, string $secret ): void {
			$this->assertFalse( str_contains( (string) json_encode( $response ), $secret ), 'a secret leaked into the response' );
		}

		/**
		 * GET of a sensitive field returns the mask and no stored value.
		 *
		 * @return void
		 */
		public function test_get_masks_a_sensitive_field(): void {
			$controller = $this->make_controller( [ 'token' => self::STORED ] );

			$item = $controller->get_item( $this->request( [ 'id' => 'token' ] ) );

			$this->assertSame( '', $item['value'] );
			$this->assertTrue( $item['is_set'] );
			$this->assert_response_lacks( $item, self::STORED );
		}

		/**
		 * An unset sensitive field reports is_set = false.
		 *
		 * @return void
		 */
		public function test_get_reports_an_unset_secret_as_not_set(): void {
			$controller = $this->make_controller( [] );

			$item = $controller->get_item( $this->request( [ 'id' => 'token' ] ) );

			$this->assertSame( '', $item['value'] );
			$this->assertFalse( $item['is_set'] );
		}

		/**
		 * A constant-backed field is masked whether or not the constant is defined.
		 *
		 * @return void
		 */
		public function test_get_masks_constant_backed_fields_defined_or_not(): void {
			$controller = $this->make_controller( [ 'const_undefined' => self::STORED ] );

			$defined   = $controller->get_item( $this->request( [ 'id' => 'const_defined' ] ) );
			$undefined = $controller->get_item( $this->request( [ 'id' => 'const_undefined' ] ) );

			$this->assertSame( '', $defined['value'] );
			$this->assertTrue( $defined['is_set'] );
			$this->assert_response_lacks( $defined, self::CONSTANT_VALUE );

			$this->assertSame( '', $undefined['value'] );
			$this->assertTrue( $undefined['is_set'] );
			$this->assert_response_lacks( $undefined, self::STORED );
		}

		/**
		 * The list endpoint masks the same way, and leaves a plain field alone.
		 *
		 * @return void
		 */
		public function test_get_items_masks_secrets_and_keeps_plain_values(): void {
			$controller = $this->make_controller(
				[
					'token' => self::STORED,
					'title' => 'Shop',
				]
			);

			$items = $controller->get_items( $this->request( [] ) );

			$by_id = array_column( $items, null, 'id' );

			$this->assertSame( '', $by_id['token']['value'] );
			$this->assertSame( '', $by_id['const_defined']['value'] );
			$this->assertSame( 'Shop', $by_id['title']['value'] );
			$this->assertArrayNotHasKey( 'is_set', $by_id['title'] );
			$this->assert_response_lacks( $items, self::STORED );
			$this->assert_response_lacks( $items, self::CONSTANT_VALUE );
		}

		/**
		 * Posting the mask back leaves the stored secret unchanged.
		 *
		 * @return void
		 */
		public function test_update_with_the_mask_keeps_the_stored_secret(): void {
			$controller = $this->make_controller( [ 'token' => self::STORED ] );

			$item = $controller->update_item( $this->request( [ 'id' => 'token', 'value' => '' ] ) );

			$this->assertTrue( self::STORED === $this->options['woodev_test-plugin_token'], 'the stored secret was changed by a masked update' );
			$this->assertSame( '', $item['value'] );
			$this->assertTrue( $item['is_set'] );
			$this->assert_response_lacks( $item, self::STORED );
		}

		/**
		 * Posting a null value is treated as the mask too.
		 *
		 * @return void
		 */
		public function test_update_with_null_keeps_the_stored_secret(): void {
			$controller = $this->make_controller( [ 'token' => self::STORED ] );

			$controller->update_item( $this->request( [ 'id' => 'token' ] ) );

			$this->assertTrue( self::STORED === $this->options['woodev_test-plugin_token'] );
		}

		/**
		 * Posting a new value changes the secret and the response stays masked.
		 *
		 * @return void
		 */
		public function test_update_with_a_new_value_changes_the_secret_and_masks_the_response(): void {
			$controller = $this->make_controller( [ 'token' => self::STORED ] );

			$item = $controller->update_item( $this->request( [ 'id' => 'token', 'value' => self::NEW_VALUE ] ) );

			$this->assertTrue( self::NEW_VALUE === $this->options['woodev_test-plugin_token'], 'the secret was not updated' );
			$this->assertSame( '', $item['value'] );
			$this->assertTrue( $item['is_set'] );
			$this->assert_response_lacks( $item, self::NEW_VALUE );
			$this->assert_response_lacks( $item, self::STORED );
		}

		/**
		 * A defined constant wins: an update never reaches the DB and never echoes the constant.
		 *
		 * @return void
		 */
		public function test_update_of_a_defined_constant_field_is_ignored_and_masked(): void {
			$controller = $this->make_controller( [] );

			$item = $controller->update_item( $this->request( [ 'id' => 'const_defined', 'value' => self::NEW_VALUE ] ) );

			$this->assertArrayNotHasKey( 'woodev_test-plugin_const_defined', $this->options );
			$this->assertSame( '', $item['value'] );
			$this->assertTrue( $item['is_set'] );
			$this->assert_response_lacks( $item, self::CONSTANT_VALUE );
			$this->assert_response_lacks( $item, self::NEW_VALUE );
		}

		/**
		 * An undefined-constant field falls back to the DB: the mask keeps it, a new value changes it.
		 *
		 * @return void
		 */
		public function test_update_of_an_undefined_constant_field_follows_the_mask_semantics(): void {
			$controller = $this->make_controller( [ 'const_undefined' => self::STORED ] );

			$controller->update_item( $this->request( [ 'id' => 'const_undefined', 'value' => '' ] ) );
			$this->assertTrue( self::STORED === $this->options['woodev_test-plugin_const_undefined'] );

			$item = $controller->update_item( $this->request( [ 'id' => 'const_undefined', 'value' => self::NEW_VALUE ] ) );
			$this->assertTrue( self::NEW_VALUE === $this->options['woodev_test-plugin_const_undefined'] );
			$this->assertSame( '', $item['value'] );
			$this->assert_response_lacks( $item, self::NEW_VALUE );
		}

		/**
		 * A secret's declared default is masked too: GET single and list never emit it.
		 *
		 * @return void
		 */
		public function test_get_masks_the_declared_default_of_a_secret(): void {
			$controller = $this->make_controller( [ 'api_key' => self::STORED ] );

			$item = $controller->get_item( $this->request( [ 'id' => 'api_key' ] ) );

			$this->assertSame( '', $item['default'] );
			$this->assertSame( '', $item['value'] );
			$this->assertTrue( $item['is_set'] );
			$this->assert_response_lacks( $item, self::DEFAULT_SECRET );
			$this->assert_response_lacks( $item, self::STORED );

			$items = $controller->get_items( $this->request( [] ) );
			$by_id = array_column( $items, null, 'id' );

			$this->assertSame( '', $by_id['api_key']['default'] );
			$this->assert_response_lacks( $items, self::DEFAULT_SECRET );
		}

		/**
		 * The declared default of a secret stays masked in the update response, for the mask and for a new value.
		 *
		 * @return void
		 */
		public function test_update_masks_the_declared_default_of_a_secret(): void {
			$controller = $this->make_controller( [ 'api_key' => self::STORED ] );

			$masked = $controller->update_item( $this->request( [ 'id' => 'api_key', 'value' => '' ] ) );

			$this->assertTrue( self::STORED === $this->options['woodev_test-plugin_api_key'], 'the stored secret was changed by a masked update' );
			$this->assertSame( '', $masked['default'] );
			$this->assert_response_lacks( $masked, self::DEFAULT_SECRET );

			$changed = $controller->update_item( $this->request( [ 'id' => 'api_key', 'value' => self::NEW_VALUE ] ) );

			$this->assertTrue( self::NEW_VALUE === $this->options['woodev_test-plugin_api_key'], 'the secret was not updated' );
			$this->assertSame( '', $changed['default'] );
			$this->assert_response_lacks( $changed, self::DEFAULT_SECRET );
			$this->assert_response_lacks( $changed, self::NEW_VALUE );
		}

		/**
		 * A plain field is unaffected: its value is returned and an empty update still applies.
		 *
		 * @return void
		 */
		public function test_a_plain_field_is_unchanged(): void {
			$controller = $this->make_controller( [ 'title' => 'Shop' ] );

			$item = $controller->update_item( $this->request( [ 'id' => 'title', 'value' => '' ] ) );

			$this->assertSame( '', $item['value'] );
			$this->assertArrayNotHasKey( 'is_set', $item );
			$this->assertSame( '', $this->options['woodev_test-plugin_title'] );
		}
	}
}

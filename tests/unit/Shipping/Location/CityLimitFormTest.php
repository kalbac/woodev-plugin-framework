<?php
/**
 * City_Limit_Form — the instance-form control of the city limit (#1176): the two fields, what saving keeps,
 * what the view tells the merchant (stale cities, a zone whose regions changed, a region field that cannot work
 * with a region zone) and the markup the zone modal's script mounts into.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace {

	if ( ! class_exists( 'WC_Settings_API', false ) ) {
		/** The slice of WooCommerce's settings API the cities control draws through. */
		class WC_Settings_API {

			/** @var int */
			public $instance_id = 262;

			/** @var array<string, mixed> */
			public $options = [];

			/** @param string $key option key. @return string */
			public function get_field_key( $key ) {
				return 'woocommerce_local_pickup_' . $key;
			}

			/** @param string $key option key. @param mixed $empty_value fallback. @return mixed */
			public function get_option( $key, $empty_value = null ) {
				return $this->options[ $key ] ?? $empty_value;
			}

			/** @param array $data field. @return string */
			public function get_tooltip_html( $data ) {
				return empty( $data['desc_tip'] ) ? '' : '<span class="woocommerce-help-tip" data-tip="x"></span>';
			}

			/** @param array $data field. @return string */
			public function get_custom_attribute_html( $data ) {
				$html = '';

				foreach ( (array) ( $data['custom_attributes'] ?? [] ) as $name => $value ) {
					$html .= $name . '="' . $value . '" ';
				}

				return $html;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\City_Limit;
	use Woodev\Framework\Shipping\Location\City_Limit_Form;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/CityLimitTest.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-city-limit-form.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Location\City_Limit_Form
	 */
	final class CityLimitFormTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			City_Limit_Form::reset_for_tests();
			unset( $_REQUEST['instance_id'] );

			Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
			Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
			Functions\when( 'esc_url_raw' )->returnArg( 1 );
			Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ) );
			Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-1' );
		}

		protected function tearDown(): void {
			City_Limit_Form::reset_for_tests();
			unset( $_REQUEST['instance_id'] );

			parent::tearDown();
		}

		private function city( string $key, string $label = 'Город' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => 'settlement',
					'country'     => 'RU',
					'region'      => [ 'name' => 'Санкт-Петербург', 'type' => '' ],
					'settlement'  => [ 'name' => $label, 'type' => '' ],
					'label'       => $label,
				]
			);
		}

		private function service(): City_Limit_Test_Service {
			return new City_Limit_Test_Service();
		}

		// ---- the fields -------------------------------------------------------------------------------------

		public function test_the_two_fields_are_a_mode_select_and_a_cities_control_shown_only_with_a_mode(): void {
			$fields = City_Limit_Form::fields();

			$this->assertSame( [ 'city_limit_mode', 'city_limit_cities' ], array_keys( $fields ) );

			$mode = $fields['city_limit_mode'];
			$this->assertSame( 'select', $mode['type'] );
			$this->assertSame( 'Ограничение по городам', $mode['title'] );
			$this->assertSame( '', $mode['default'] );
			$this->assertSame( [ '', 'include', 'exclude' ], array_keys( $mode['options'] ) );
			$this->assertNotSame( '', $mode['desc_tip'] );

			$cities = $fields['city_limit_cities'];
			$this->assertSame( 'woodev_city_limit', $cities['type'] );
			$this->assertSame( 'Города', $cities['title'] );
			$this->assertSame( [ 'setting' => 'city_limit_mode', 'operator' => '!=', 'value' => '' ], $cities['show_if'] );

			foreach ( $fields as $field ) {
				$this->assertIsCallable( $field['sanitize_callback'] );
			}
		}

		public function test_the_merchant_facing_copy_has_no_jargon(): void {
			$text = json_encode( City_Limit_Form::fields(), JSON_UNESCAPED_UNICODE );

			foreach ( [ 'чекаут', 'фреймворк', 'провайдер' ] as $word ) {
				$this->assertStringNotContainsStringIgnoringCase( $word, $text, 'AGENT-RULES Rule 10c' );
			}
		}

		public function test_register_adds_the_renderer_once(): void {
			Filters\expectAdded( 'woocommerce_generate_woodev_city_limit_html' )->once();

			City_Limit_Form::register();
			City_Limit_Form::register();

			$this->assertTrue( true );
		}

		// ---- saving ---------------------------------------------------------------------------------------

		public function test_the_posted_mode_is_clamped(): void {
			$this->assertSame( 'include', City_Limit_Form::sanitize_mode( 'include' ) );
			$this->assertSame( '', City_Limit_Form::sanitize_mode( 'something else' ) );
			$this->assertSame( '', City_Limit_Form::sanitize_mode( null ) );
		}

		public function test_saving_keeps_valid_cities_compact_and_drops_the_rest(): void {
			$posted = json_encode(
				[
					array_merge( $this->city( 'test-cdek:394', 'Пушкин' )->to_array(), [ 'raw' => [ 'payload' => 'big' ] ] ),
					[ 'key' => 'broken' ],
					$this->city( 'test-cdek:394', 'Пушкин' )->to_array(),
				],
				JSON_UNESCAPED_UNICODE
			);

			$saved = City_Limit_Form::sanitize_cities( $posted );

			$this->assertStringNotContainsString( 'payload', $saved );
			$this->assertSame( [ 'test-cdek:394' ], array_map( static fn( $c ) => $c->key(), City_Limit::decode( $saved ) ) );
		}

		public function test_a_classic_page_hands_the_value_over_slashed_and_it_still_saves(): void {
			$posted = addslashes( json_encode( [ $this->city( 'test-cdek:394', 'Пушкин' )->to_array() ], JSON_UNESCAPED_UNICODE ) );

			$this->assertCount( 1, City_Limit::decode( City_Limit_Form::sanitize_cities( $posted ) ) );
		}

		public function test_saving_nothing_stores_an_empty_list(): void {
			$this->assertSame( '[]', City_Limit_Form::sanitize_cities( '' ) );
			$this->assertSame( '[]', City_Limit_Form::sanitize_cities( null ) );
			$this->assertSame( '[]', City_Limit_Form::sanitize_cities( 'garbage' ) );
		}

		// ---- the view ---------------------------------------------------------------------------------------

		public function test_a_clean_list_has_no_notes_and_keeps_its_cities(): void {
			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [] ],
				$this->service(),
				true
			);

			$this->assertSame( [], $view['notes'] );
			$this->assertSame( 'ok', $view['items'][0]['state'] );
			$this->assertSame( 'test-cdek:394', $view['items'][0]['record']['key'] );
			$this->assertNull( $view['items'][0]['record']['raw'] );
			$this->assertSame( 'RU', $view['country'] );
			$this->assertTrue( $view['active'] );
			$this->assertSame( [ 'test-cdek:394' ], array_map( static fn( $c ) => $c->key(), City_Limit::decode( $view['value'] ) ) );
		}

		public function test_a_stale_city_is_re_resolved_by_name_and_the_merchant_is_told_to_save(): void {
			$service              = $this->service();
			$service->owner       = 'test-cdek';
			$service->replacement = $this->city( 'test-cdek:394', 'Пушкин' );

			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'dadata:abc', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [] ],
				$service,
				true
			);

			$this->assertSame( 'ok', $view['items'][0]['state'] );
			$this->assertSame( 'test-cdek:394', $view['items'][0]['record']['key'] );
			$this->assertStringContainsString( 'test-cdek:394', $view['value'], 'the replacement is what saving the form keeps' );
			$this->assertCount( 1, $view['notes'] );
			$this->assertStringContainsString( 'Сохраните', $view['notes'][0] );
		}

		public function test_a_stale_city_that_cannot_be_re_resolved_stays_listed_and_marked(): void {
			$service        = $this->service();
			$service->owner = 'test-cdek';

			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'dadata:abc', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [] ],
				$service,
				true
			);

			$this->assertSame( 'stale', $view['items'][0]['state'] );
			$this->assertCount( 1, $view['notes'] );
			$this->assertStringContainsString( 'не учитываются', $view['notes'][0] );
			$this->assertStringContainsString( 'dadata:abc', $view['value'], 'nothing is dropped silently' );
		}

		public function test_re_resolving_is_skipped_when_the_layer_is_not_usable(): void {
			$service              = $this->service();
			$service->owner       = 'test-cdek';
			$service->replacement = $this->city( 'test-cdek:394', 'Пушкин' );

			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'dadata:abc', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [] ],
				$service,
				false
			);

			$this->assertSame( 'stale', $view['items'][0]['state'] );
		}

		public function test_two_stale_cities_that_resolve_to_one_are_listed_once(): void {
			$service              = $this->service();
			$service->owner       = 'test-cdek';
			$service->replacement = $this->city( 'test-cdek:394', 'Пушкин' );

			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'dadata:a', 'Пушкин' ), $this->city( 'dadata:b', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [] ],
				$service,
				true
			);

			$this->assertCount( 1, $view['items'] );
		}

		public function test_a_city_outside_the_zones_regions_is_marked_and_explained(): void {
			$service             = $this->service();
			$service->state_code = 'САНКТ-ПЕТЕРБУРГ';

			$view = City_Limit_Form::build_view(
				City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
				[ 'country' => 'RU', 'states' => [ 'RU' => [ 'ОМСКАЯ ОБЛАСТЬ' ] ] ],
				$service,
				true
			);

			$this->assertSame( 'outside', $view['items'][0]['state'] );
			$this->assertStringContainsString( 'Регионы зоны доставки изменились', implode( ' ', $view['notes'] ) );
		}

		public function test_a_region_zone_with_the_region_field_removed_is_warned_about_in_one_line(): void {
			$service                 = $this->service();
			$service->region_removed = true;
			$zone                    = [ 'country' => 'RU', 'states' => [ 'RU' => [ 'ОМСКАЯ ОБЛАСТЬ' ] ] ];

			$warned = City_Limit_Form::build_view( '[]', $zone, $service, true );

			$this->assertCount( 1, $warned['notes'] );
			$this->assertStringContainsString( 'поле региона убрано', $warned['notes'][0] );

			$whole_country = City_Limit_Form::build_view( '[]', [ 'country' => 'RU', 'states' => [] ], $service, true );
			$this->assertSame( [], $whole_country['notes'], 'a zone with no regions does not care' );

			$service->region_removed = false;
			$this->assertSame( [], City_Limit_Form::build_view( '[]', $zone, $service, true )['notes'] );
		}

		public function test_an_inactive_location_layer_says_why_the_search_is_silent(): void {
			$service         = $this->service();
			$service->active = false;

			$view = City_Limit_Form::build_view( '[]', [ 'country' => '', 'states' => [] ], $service, false );

			$this->assertFalse( $view['active'] );
			$this->assertStringContainsString( 'Поиск городов недоступен', $view['notes'][0] );
			$this->assertSame( 'RU', $view['country'], 'no zone country: the store default' );
		}

		// ---- the markup -------------------------------------------------------------------------------------

		public function test_the_row_carries_the_hidden_input_the_mount_point_and_the_notes(): void {
			$settings          = new \WC_Settings_API();
			$settings->options = [ 'city_limit_cities' => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ) ];

			$html = $this->render_with( $settings, $this->service() );

			$this->assertStringContainsString( '<tr valign="top">', $html );
			$this->assertStringContainsString( 'name="woocommerce_local_pickup_city_limit_cities"', $html );
			$this->assertStringContainsString( 'type="hidden"', $html );
			$this->assertStringContainsString( 'class="woodev-city-limit"', $html );
			$this->assertStringContainsString( '<li>Пушкин</li>', $html, 'the no-script list' );

			preg_match( '/data-config="([^"]*)"/', $html, $matches );
			$config = json_decode( html_entity_decode( $matches[1] ), true );

			$this->assertSame( 'woocommerce_local_pickup_city_limit_cities', $config['inputId'] );
			$this->assertSame( 262, $config['instanceId'] );
			$this->assertSame( 'https://example.test/wp-json/woodev/v1', $config['restRoot'] );
			$this->assertSame( 'nonce-1', $config['nonce'] );
			$this->assertSame( 'test-cdek:394', $config['items'][0]['record']['key'] );
		}

		public function test_the_row_prints_each_note_as_a_description_paragraph(): void {
			$service         = $this->service();
			$service->active = false;

			$html = $this->render_with( new \WC_Settings_API(), $service );

			$this->assertSame( 1, substr_count( $html, 'woodev-city-limit__note' ) );
			$this->assertStringContainsString( 'Поиск городов недоступен', $html );
		}

		public function test_anything_but_a_settings_object_is_left_alone(): void {
			$this->assertSame( 'kept', City_Limit_Form::render( 'kept', 'city_limit_cities', [], null ) );
			$this->assertSame( '', City_Limit_Form::render( null, 'city_limit_cities', [], new \stdClass() ) );
		}

		/**
		 * Draws the row with the given service standing in for the real location layer.
		 *
		 * @param \WC_Settings_API        $settings The settings object.
		 * @param City_Limit_Test_Service $service  The location layer.
		 *
		 * @return string
		 */
		private function render_with( \WC_Settings_API $settings, City_Limit_Test_Service $service ): string {
			City_Limit_Form::use_service_for_tests( $service );

			return City_Limit_Form::render( '', 'city_limit_cities', City_Limit_Form::fields()['city_limit_cities'], $settings );
		}
	}
}

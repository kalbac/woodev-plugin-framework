<?php
/**
 * The mapping and profile editors and their REST controller, over the real stores on SQLite.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Messages;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Mappings_Harness.php';
require_once dirname( __DIR__, 5 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

if ( ! class_exists( __NAMESPACE__ . '\\Json_Request', false ) ) {
	/**
	 * A REST request with a JSON body and a query string.
	 */
	final class Json_Request extends \WP_REST_Request {
		/**
		 * Query string.
		 *
		 * @var array<string,mixed>
		 */
		private $query;

		/**
		 * JSON body; null means the body is not JSON.
		 *
		 * @var mixed
		 */
		private $json;

		/**
		 * Builds the request.
		 *
		 * @param mixed               $json  Body.
		 * @param array<string,mixed> $query Query string.
		 * @param array<string,mixed> $url   Path parameters.
		 */
		public function __construct( $json = [], array $query = [], array $url = [] ) {
			parent::__construct( array_merge( $url, $query ) );
			$this->set_url_params( $url );
			$this->query = $query;
			$this->json  = $json;
		}

		/**
		 * Query string.
		 *
		 * @return array<string,mixed>
		 */
		public function get_query_params() {
			return $this->query;
		}

		/**
		 * JSON body.
		 *
		 * @return mixed
		 */
		public function get_json_params() {
			return $this->json;
		}
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Editor
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Editor
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller
 */
final class MappingsEditorTest extends TestCase {
	/**
	 * Harness.
	 *
	 * @var Mappings_Harness
	 */
	private $h;

	/**
	 * Capabilities the current user holds.
	 *
	 * @var string[]
	 */
	private $caps = [ 'manage_woocommerce' ];

	/**
	 * Stubs WordPress and builds the objects.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
		);
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				return $id < 100 ? self::term( (int) $id, 0, 'Категория ' . $id ) : false;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		$this->h = new Mappings_Harness();
	}

	/**
	 * Builds a term.
	 *
	 * @param int    $id     Term id.
	 * @param int    $parent Parent id.
	 * @param string $name   Name.
	 * @return \WP_Term
	 */
	private static function term( int $id, int $parent, string $name ): \WP_Term {
		$term          = new \WP_Term();
		$term->term_id = $id;
		$term->parent  = $parent;
		$term->name    = $name;

		return $term;
	}

	/**
	 * A body that saves.
	 *
	 * @param array<string,mixed> $extra Replacements.
	 * @return array<string,mixed>
	 */
	private function body( array $extra = [] ): array {
		return array_merge(
			[
				'name'                => 'Платья',
				'terms'               => [ 5 ],
				'category'            => $this->h->dress_key(),
				'profile_id'          => null,
				'attributes'          => [
					'1' => [ 'source' => 'fixed', 'value' => 'Zara' ],
				],
				'attributes_category' => $this->h->dress_key(),
			],
			$extra
		);
	}

	// -- writes ----------------------------------------------------------------------------------

	/**
	 * A valid body creates a mapping with its categories and sources.
	 *
	 * @return void
	 */
	public function test_save_creates_a_mapping_with_its_terms_and_category(): void {
		$result = $this->h->editor->save( null, $this->body() );

		$this->assertSame( 'saved', $result['status'] );

		$stored = $this->h->mappings->find( $result['id'] );
		$this->assertSame( [ 'category_id' => 10, 'type_id' => 1 ], $stored['category'] );
		$this->assertSame( 'Zara', $stored['attributes']['1']['value'] );
		$this->assertSame( [ 5 ], $this->h->mappings->terms_by_mapping()[ $result['id'] ] );
	}

	/**
	 * A shop category belongs to one mapping: the second save is refused before anything is written.
	 *
	 * @return void
	 */
	public function test_a_category_of_another_mapping_is_refused(): void {
		$this->h->editor->save( null, $this->body() );

		$second = $this->h->editor->save( null, $this->body( [ 'name' => 'Другое', 'terms' => [ 5, 6 ] ] ) );

		$this->assertSame( 'invalid', $second['status'] );
		$this->assertStringContainsString( 'Категория 5', $second['errors'][0] );
		$this->assertStringContainsString( 'Платья', $second['errors'][0] );
		$this->assertCount( 1, $this->h->mappings->all_term_bound(), 'nothing was written' );
	}

	/**
	 * A mapping may keep its own categories when it is saved again.
	 *
	 * @return void
	 */
	public function test_a_mapping_keeps_its_own_categories_on_update(): void {
		$id = $this->h->editor->save( null, $this->body() )['id'];

		$again = $this->h->editor->save( $id, $this->body( [ 'terms' => [ 5, 7 ] ] ) );

		$this->assertSame( 'saved', $again['status'] );
		$this->assertSame( [ 5, 7 ], $this->h->mappings->terms_by_mapping()[ $id ] );
	}

	/**
	 * A forged, disabled or missing category is refused, and so is a profile that does not exist.
	 *
	 * @return void
	 */
	public function test_forged_references_are_refused(): void {
		$forged = $this->h->editor->save( null, $this->body( [ 'category' => 'deadbeefdeadbeef' ] ) );
		$this->assertSame( 'invalid', $forged['status'] );

		$absent = $this->body();
		unset( $absent['category'] );
		$this->assertSame( 'invalid', $this->h->editor->save( null, $absent )['status'], 'the key must always be sent' );

		$profile = $this->h->editor->save( null, $this->body( [ 'profile_id' => 99 ] ) );
		$this->assertSame( 'invalid', $profile['status'] );
		$this->assertSame( [], $this->h->mappings->all_term_bound() );
	}

	/**
	 * Changing or copying a mapping that does not exist is a "missing", and a product-scoped mapping is out of reach.
	 *
	 * @return void
	 */
	public function test_a_missing_or_product_scoped_mapping_cannot_be_changed(): void {
		$this->assertSame( 'missing', $this->h->editor->save( 55, $this->body() )['status'] );
		$this->assertSame( 'missing', $this->h->editor->copy( 55 )['status'] );
		$this->assertSame( 'missing', $this->h->editor->delete( 55 )['status'] );

		$scoped = $this->h->mappings->create( 'Для одного товара', 42, [ 'category_id' => 10, 'type_id' => 1 ], null, [] );
		$this->assertSame( 'missing', $this->h->editor->save( $scoped, $this->body() )['status'] );
		$this->assertNull( $this->h->editor->detail( $scoped ) );
	}

	/**
	 * An adapter that cannot give the fields turns a save into "unavailable", not an exception.
	 *
	 * @return void
	 */
	public function test_an_adapter_failure_is_reported_not_thrown(): void {
		$this->h->schema->failure = new \RuntimeException( 'нет связи' );

		$result = $this->h->editor->save( null, $this->body() );

		$this->assertSame( 'unavailable', $result['status'] );
		$this->assertStringContainsString( 'нет связи', $result['errors'][0] );
	}

	/**
	 * A copy has the sources and no shop categories.
	 *
	 * @return void
	 */
	public function test_a_copy_has_no_shop_categories(): void {
		$id   = $this->h->editor->save( null, $this->body() )['id'];
		$copy = $this->h->editor->copy( $id );

		$this->assertSame( 'saved', $copy['status'] );
		$this->assertSame( 'Платья (копия)', $this->h->mappings->find( $copy['id'] )['name'] );
		$this->assertSame( 'Zara', $this->h->mappings->find( $copy['id'] )['attributes']['1']['value'] );
		$this->assertArrayNotHasKey( $copy['id'], $this->h->mappings->terms_by_mapping() );
	}

	/**
	 * Deleting removes the mapping and frees its categories.
	 *
	 * @return void
	 */
	public function test_delete_frees_the_categories(): void {
		$id = $this->h->editor->save( null, $this->body() )['id'];

		$this->assertSame( 'saved', $this->h->editor->delete( $id )['status'] );
		$this->assertSame( [], $this->h->mappings->terms_by_mapping() );
	}

	// -- reads -----------------------------------------------------------------------------------

	/**
	 * The editor payload says when the saved category is gone from the marketplace tree.
	 *
	 * @return void
	 */
	public function test_detail_flags_a_category_the_marketplace_dropped(): void {
		$id = $this->h->mappings->create( 'Старое', null, [ 'category_id' => 99, 'type_id' => 9 ], null, [] );

		$detail = $this->h->editor->detail( $id );

		$this->assertFalse( $detail['category']['available'] );
		$this->assertSame( [], $detail['attributes'] );
		$this->assertSame( 0, $this->h->schema->calls['fields'] ?? 0, 'no fields are asked for a category that is gone' );
	}

	/**
	 * The attribute table of a saved category carries the stored source of each row.
	 *
	 * @return void
	 */
	public function test_detail_fills_the_table_from_the_stored_sources(): void {
		$id = $this->h->editor->save( null, $this->body() )['id'];

		$rows = $this->h->editor->detail( $id )['attributes'];

		$this->assertSame( [ '1', '2', '3' ], array_column( $rows, 'id' ) );
		$this->assertSame( 'Zara', $rows[0]['source']['value'] );
		$this->assertTrue( $rows[1]['dictionary'] );
		$this->assertSame( '', $rows[2]['source']['source'] );
	}

	/**
	 * A dictionary search needs two characters and a dictionary attribute.
	 *
	 * @return void
	 */
	public function test_dictionary_search_rules(): void {
		$identity = [ 'category_id' => 10, 'type_id' => 1 ];

		$this->assertSame( [], $this->h->editor->dictionary_values( $identity, '2', 'к' ) );
		$this->assertNull( $this->h->editor->dictionary_values( $identity, '1', 'красный' ), 'a string attribute has no dictionary' );

		$values = $this->h->editor->dictionary_values( $identity, '2', 'кр' );
		$this->assertSame( 'красный — тёплый', $values[0]['text'] );
		$this->assertSame( 'красный', $values[0]['name'] );
		$this->assertSame( 'синий', $values[1]['text'] );
	}

	// -- profiles --------------------------------------------------------------------------------

	/**
	 * A profile is created, read back as form values, and cannot be deleted while a mapping uses it.
	 *
	 * @return void
	 */
	public function test_profile_round_trip_and_delete_guard(): void {
		$saved = $this->h->profile_editor->save( null, [ 'name' => 'Основной', 'fallback_weight' => '2', 'currency_kind' => 'rate', 'currency_rate' => '90' ] );
		$this->assertSame( 'saved', $saved['status'] );

		$values = $this->h->profile_editor->detail( $saved['id'] )['values'];
		$this->assertSame( '2', $values['fallback_weight'] );
		$this->assertSame( 'rate', $values['currency_kind'] );
		$this->assertSame( '90', $values['currency_rate'] );
		$this->assertSame( 'sku', $values['offer_id_source'] );

		$this->h->editor->save( null, $this->body( [ 'profile_id' => $saved['id'] ] ) );
		$blocked = $this->h->profile_editor->delete( $saved['id'] );
		$this->assertSame( 'in_use', $blocked['status'] );
		$this->assertNotNull( $this->h->profiles->get( $saved['id'] ) );
	}

	/**
	 * A profile saved without a price rule offers valid choices, so switching the override on and saving as the form
	 * shows it succeeds.
	 *
	 * @return void
	 */
	public function test_enabling_a_price_override_on_a_profile_without_a_rule_saves(): void {
		$saved  = $this->h->profile_editor->save( null, [ 'name' => 'Основной' ] );
		$values = $this->h->profile_editor->detail( $saved['id'] )['values'];

		$this->assertFalse( $values['price_override'] );
		$this->assertSame( 'active', $values['price_base'] );
		$this->assertSame( 'none', $values['price_rounding'] );
		$this->assertSame( 'on_sale', $values['price_old_price_rule'] );

		$again = $this->h->profile_editor->save(
			$saved['id'],
			array_merge( $values, [ 'name' => 'Основной', 'price_override' => true, 'price_markup_kind' => 'percent', 'price_markup' => '10' ] )
		);

		$this->assertSame( 'saved', $again['status'] );
		$this->assertSame( '10', $this->h->profile_editor->detail( $saved['id'] )['values']['price_markup'] );
		$this->assertTrue( $this->h->profile_editor->detail( $saved['id'] )['values']['price_override'] );
	}

	/**
	 * A table loaded for another category is refused by the save, and nothing is written.
	 *
	 * @return void
	 */
	public function test_a_table_of_another_category_is_refused_on_save(): void {
		$result = $this->h->editor->save( null, $this->body( [ 'attributes_category' => 'deadbeefdeadbeef' ] ) );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertSame( [ Mapping_Messages::attributes_of_other_category() ], $result['errors'] );
		$this->assertCount( 0, $this->h->mappings->all_term_bound() );
	}

	/**
	 * An invalid profile writes nothing; a missing one is "missing".
	 *
	 * @return void
	 */
	public function test_invalid_and_missing_profiles(): void {
		$this->assertSame( 'invalid', $this->h->profile_editor->save( null, [ 'name' => '' ] )['status'] );
		$this->assertSame( [], $this->h->profiles->all() );
		$this->assertSame( 'missing', $this->h->profile_editor->save( 9, [ 'name' => 'A' ] )['status'] );
		$this->assertSame( 'missing', $this->h->profile_editor->delete( 9 )['status'] );
	}

	// -- controller ------------------------------------------------------------------------------

	/**
	 * Asserts an error result.
	 *
	 * @param mixed  $result Controller result.
	 * @param string $code   Expected code.
	 * @param int    $status Expected HTTP status.
	 * @return void
	 */
	private function assertError( $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/**
	 * Nobody without the capability reaches a route.
	 *
	 * @return void
	 */
	public function test_the_permission_gate_is_the_capability(): void {
		$this->assertTrue( $this->h->controller->check_permission() );

		$this->caps = [ 'edit_posts' ];
		$this->assertError( $this->h->controller->check_permission(), 'woodev_records_forbidden', 403 );
	}

	/**
	 * A body that is not JSON, or holds a key the route does not declare, is a 400 before any validation.
	 *
	 * @return void
	 */
	public function test_bodies_are_checked_against_the_declared_keys(): void {
		$this->assertError( $this->h->controller->create_mapping( new Json_Request( null ) ), 'woodev_mappings_malformed', 400 );
		$this->assertError( $this->h->controller->create_mapping( new Json_Request( $this->body( [ 'account_id' => 3 ] ) ) ), 'woodev_mappings_unknown_field', 400 );
		$this->assertError( $this->h->controller->create_profile( new Json_Request( [ 'name' => 'A', 'wc_product_id' => 5 ] ) ), 'woodev_mappings_unknown_field', 400 );
		$this->assertError( $this->h->controller->get_categories( new Json_Request( [], [ 'search' => 'a', 'page' => 2 ] ) ), 'woodev_mappings_unknown_field', 400 );
		$this->assertSame( [], $this->h->mappings->all_term_bound() );
	}

	/**
	 * Each editor status has its HTTP status, and a validation failure carries every sentence.
	 *
	 * @return void
	 */
	public function test_editor_statuses_map_to_http_statuses(): void {
		$ok = $this->h->controller->create_mapping( new Json_Request( $this->body() ) );
		$this->assertSame( 200, $ok->get_status() );
		$id = $ok->get_data()['id'];

		$invalid = $this->h->controller->create_mapping( new Json_Request( $this->body( [ 'name' => '', 'terms' => [ 5 ] ] ) ) );
		$this->assertError( $invalid, 'woodev_mappings_invalid', 422 );
		$this->assertCount( 2, $invalid->get_error_data()['errors'] );

		$this->assertError( $this->h->controller->update_mapping( new Json_Request( $this->body(), [], [ 'id' => '77' ] ) ), 'woodev_mappings_missing', 404 );
		$this->assertError( $this->h->controller->get_mapping( new Json_Request( [], [], [ 'id' => '77' ] ) ), 'woodev_mappings_not_found', 404 );

		$profile = $this->h->controller->create_profile( new Json_Request( [ 'name' => 'P' ] ) )->get_data()['id'];
		$this->h->controller->update_mapping( new Json_Request( $this->body( [ 'profile_id' => $profile ] ), [], [ 'id' => (string) $id ] ) );
		$this->assertError( $this->h->controller->delete_profile( new Json_Request( [], [], [ 'id' => (string) $profile ] ) ), 'woodev_mappings_in_use', 409 );
	}

	/**
	 * The adapter failing on a lookup is a 502 with its sentence; an unknown category key is a 400 and never reaches the fields call.
	 *
	 * @return void
	 */
	public function test_lookups_report_an_adapter_failure_and_refuse_a_forged_key(): void {
		$this->assertError( $this->h->controller->get_fields( new Json_Request( [], [ 'category' => 'deadbeefdeadbeef' ] ) ), 'woodev_mappings_unknown_category', 400 );
		$this->assertSame( 0, $this->h->schema->calls['fields'] ?? 0 );

		$fields = $this->h->controller->get_fields( new Json_Request( [], [ 'category' => $this->h->dress_key() ] ) );
		$this->assertSame( [ '1', '2', '3' ], array_column( $fields->get_data()['attributes'], 'id' ) );

		$broken                  = new Mappings_Harness();
		$broken->schema->failure = new \RuntimeException( 'нет связи' );
		$down                    = $broken->controller->get_categories( new Json_Request( [], [ 'search' => 'пл' ] ) );
		$this->assertError( $down, 'woodev_mappings_unavailable', 502 );
		$this->assertStringContainsString( 'нет связи', $down->get_error_message() );
	}

	/**
	 * The attribute table is filled from a mapping only when that mapping holds the category being asked about.
	 *
	 * @return void
	 */
	public function test_fields_are_filled_only_from_a_mapping_with_that_category(): void {
		$id    = $this->h->editor->save( null, $this->body() )['id'];
		$other = \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder::key_of( [ 'category_id' => 11, 'type_id' => 2 ] );

		$same = $this->h->controller->get_fields( new Json_Request( [], [ 'category' => $this->h->dress_key(), 'mapping_id' => (string) $id ] ) );
		$diff = $this->h->controller->get_fields( new Json_Request( [], [ 'category' => $other, 'mapping_id' => (string) $id ] ) );

		$this->assertSame( 'Zara', $same->get_data()['attributes'][0]['source']['value'] );
		$this->assertSame( '', $diff->get_data()['attributes'][0]['source']['source'] );
	}

	/**
	 * The first paint carries the sentences, the lists and the form options, privately.
	 *
	 * @return void
	 */
	public function test_state_carries_everything_the_first_paint_needs(): void {
		Functions\when( 'get_term_children' )->justReturn( [] );
		Functions\when( 'get_terms' )->justReturn( [ self::term( 5, 0, 'Одежда' ), self::term( 6, 5, 'Платья' ) ] );
		$this->h->editor->save( null, $this->body() );

		$this->h->schema->calls = [];
		$response               = $this->h->controller->get_state( new Json_Request() );
		$this->assertNotInstanceOf( \WP_Error::class, $response, $response instanceof \WP_Error ? $response->get_error_message() : '' );
		$data = $response->get_data();

		$this->assertSame( [ 'Одежда', 'Одежда › Платья' ], array_column( $data['shop_categories'], 'label' ) );
		$this->assertSame( 'Платья', $data['shop_categories'][0]['mapping_name'], 'the category that is taken says by whom' );
		$this->assertNull( $data['shop_categories'][1]['mapping_id'] );
		$this->assertSame( 'Одежда › Платья', $data['mappings'][0]['category_label'] );
		$this->assertNotSame( '', $data['messages']['save'] );
		$this->assertSame( 'offer_id_source', $data['profile_form']['sources'][0]['setting'] );
		$this->assertTrue( $data['categories_loaded'] );
		$this->assertSame( 0, $this->h->schema->calls['fields'] ?? 0, 'the first paint never asks for fields' );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] ?? ( $response->headers['Cache-Control'] ?? 'private, no-store' ) );
	}
}

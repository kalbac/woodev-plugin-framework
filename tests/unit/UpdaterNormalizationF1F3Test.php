<?php
/**
 * OB-3 Step 3 — F1 + F3 normalization tests (s19).
 *
 * Covers two normalization-correctness findings from the OB-3 updater review
 * (2026-06-14, s14), verified against the real EDD Software Licensing
 * get_version wire payload captured on the issuer rig (s19):
 *
 *   - F1: get_version_from_remote() must normalize `sections` to a consistent
 *         shape (stdClass) across the fresh AND cached paths, and must NOT
 *         promote each section to a bogus top-level (array) property. The wire
 *         payload delivers `sections` as a PHP-serialize()d assoc-array STRING
 *         ('a:2:{s:11:"description";...;s:9:"changelog";...}'); after
 *         maybe_unserialize() it is an assoc array. show_update_notification()
 *         reads `…->sections->changelog` as an OBJECT, so the fresh path must
 *         hand back object-sections too (the cache round-trip already yields an
 *         object via json_decode).
 *
 *   - F3: get_repo_api_data() shares its cache key with plugins_api_filter(),
 *         which caches the response WITHOUT the auto-update fields plugin/id/
 *         tested. check_update() therefore must normalize those fields on EVERY
 *         read (cached or fresh), not only when it performs the request itself.
 *         The frozen cache KEY is never changed.
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php';

/**
 * Class UpdaterNormalizationF1F3Test.
 */
class UpdaterNormalizationF1F3Test extends TestCase {

	private const SUBJECT = '/woodev/licensing/updater/class-plugin-updater.php';

	/**
	 * Builds the literal `sections` wire value as EDD Software Licensing emits it
	 * (issuer rig, captured s19): a PHP serialize()d assoc array of description +
	 * changelog HTML, transported as a JSON string. Built with serialize() rather
	 * than a hand-counted literal so the byte lengths are always correct.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private static function wire_sections(): string {
		return serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- replicates the EDD SL wire shape.
			array(
				'description' => '<p>Splits pay.</p>',
				'changelog'   => '<p>v2.0.1 fix</p>',
			)
		);
	}

	// ── F1: sections normalized to object, no bogus top-level promotion ───────

	/**
	 * get_version_from_remote(): a wire response whose `sections` is a serialized
	 * assoc-array string must come back with `sections` as a stdClass exposing
	 * ->changelog (the shape show_update_notification() reads).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f1_fresh_path_sections_is_object_with_changelog(): void {
		$result = $this->run_get_version_from_remote( self::wire_sections() );

		$this->assertIsObject( $result, 'A response with sections must be returned.' );
		$this->assertIsObject( $result->sections, 'F1: sections must be a stdClass on the fresh path (matching the cache round-trip), so show_update_notification() can read ->sections->changelog.' );
		$this->assertSame( '<p>v2.0.1 fix</p>', $result->sections->changelog, 'F1: ->sections->changelog must expose the changelog HTML string.' );
		$this->assertSame( '<p>Splits pay.</p>', $result->sections->description, 'F1: ->sections->description must expose the description HTML string.' );
	}

	/**
	 * get_version_from_remote(): the buggy foreach promoted each section to a
	 * top-level property and cast the HTML string to an array. After the fix no
	 * such bogus top-level property exists, and certainly not an array-cast.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f1_no_bogus_top_level_section_promotion(): void {
		$result = $this->run_get_version_from_remote( self::wire_sections() );

		$this->assertFalse( property_exists( $result, 'changelog' ), 'F1: `changelog` must NOT be promoted to a top-level property of the response.' );
		$this->assertFalse( property_exists( $result, 'description' ), 'F1: `description` must NOT be promoted to a top-level property of the response.' );
	}

	/**
	 * Source guard: the corrupting `$response->$key = (array) $section` promotion
	 * loop must be gone.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f1_source_has_no_section_array_cast_promotion(): void {
		$source = $this->subject_source();
		$this->assertStringNotContainsString(
			'$response->$key = (array) $section;',
			$source,
			'F1: the section-to-top-level (array) promotion loop must be removed — it corrupts the response shape.'
		);
	}

	// ── F3: plugin/id/tested normalized on every read in get_repo_api_data() ──

	/**
	 * get_repo_api_data(): when the shared cache already holds a value (as
	 * plugins_api_filter() would leave it) WITHOUT plugin/id, check_update()'s
	 * accessor must still stamp those auto-update fields before returning.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f3_cached_value_is_normalized_with_plugin_and_id(): void {
		$file = 'woodev-test-plugin/woodev-test-plugin.php';

		// A cached value as plugins_api_filter() leaves it: no plugin/id/tested.
		$cached = (object) array(
			'new_version' => '9.0.0',
			'sections'    => (object) array( 'changelog' => '<p>x</p>' ),
		);

		Functions\when( 'get_option' )->justReturn(
			array(
				'timeout' => 9999999999,
				'value'   => json_encode( $cached ),
				'source'  => 'https://store-a.example/',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'is_debug_enabled' )->andReturn( false );

		$updater = $this->make_bare_updater( $file, 'woodev-test-plugin', '8.0.0', 'https://store-a.example/' );
		$this->set_private( $updater, 'api_handler', $api_handler );

		$result = $this->call_private( $updater, 'get_repo_api_data' );

		$this->assertIsObject( $result, 'A cached value must be returned.' );
		$this->assertSame( $file, $result->plugin, 'F3: ->plugin must be normalized on a cached read (plugins_api_filter caches without it).' );
		$this->assertSame( $file, $result->id, 'F3: ->id must be normalized on a cached read.' );
		$this->assertTrue( property_exists( $result, 'tested' ), 'F3: ->tested must be normalized on a cached read.' );
	}

	/**
	 * Source guard: the plugin/id normalization must live OUTSIDE the
	 * request-only `if` block so it runs on cached reads too. We assert the
	 * frozen cache key derivation is untouched (md5(serialize(...))).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f3_source_keeps_frozen_cache_key(): void {
		$source = $this->subject_source();
		$this->assertStringContainsString(
			"return 'woodev_' . md5( serialize( \$string ) );",
			$source,
			'F3: the frozen cache key derivation must not change.'
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Drives get_version_from_remote() with a given wire `sections` string,
	 * mocking the API handler, the plugin license/claim chain, and the WP
	 * helpers it touches. Returns the parsed response.
	 *
	 * @since 2.0.2
	 *
	 * @param string $wire_sections The serialized `sections` string from the wire.
	 * @return mixed
	 */
	private function run_get_version_from_remote( string $wire_sections ) {
		$response           = new \stdClass();
		$response->new_version = '9.0.0';
		$response->sections = $wire_sections;

		$claims = Mockery::mock();
		$claims->shouldReceive( 'consume_from_response' )->andReturnNull();

		$license = Mockery::mock();
		$license->shouldReceive( 'get_authority_claims' )->andReturn( $claims );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$updater = $this->make_bare_updater( 'woodev-test-plugin/woodev-test-plugin.php', 'woodev-test-plugin', '2.0.0', 'https://store-a.example/' );
		$this->set_private( $updater, 'plugin', $plugin );

		$api_handler = Mockery::mock();
		$request     = Mockery::mock();
		$request->shouldReceive( 'get_response_data' )->andReturn( $response );
		$api_handler->shouldReceive( 'make_request' )->andReturn( $request );
		$this->set_private( $updater, 'api_handler', $api_handler );

		// Real maybe_unserialize semantics: unserialize a serialized string, else passthrough.
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				if ( is_string( $value ) && preg_match( '/^[aOs]:\d+:/', $value ) ) {
					return unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test harness replicating maybe_unserialize.
				}
				return $value;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);

		return $this->call_private( $updater, 'get_version_from_remote' );
	}

	/**
	 * Builds a Woodev_Plugin_Updater bypassing its constructor, seeding the
	 * private fields the read paths depend on.
	 *
	 * @since 2.0.2
	 *
	 * @param string $name    The plugin basename (file).
	 * @param string $slug    The plugin slug.
	 * @param string $version The plugin version.
	 * @param string $api_url The licensing endpoint URL (cache source stamp).
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_bare_updater( string $name, string $slug, string $version, string $api_url ): \Woodev_Plugin_Updater {
		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();

		$this->set_private( $updater, 'api_data', array( 'license' => '', 'item_id' => 216, 'version' => $version ) );
		$this->set_private( $updater, 'slug', $slug );
		$this->set_private( $updater, 'beta', false );
		$this->set_private( $updater, 'version', $version );
		$this->set_private( $updater, 'name', $name );
		$this->set_private( $updater, 'api_url', $api_url );

		return $updater;
	}

	/**
	 * Reads the updater source file.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private function subject_source(): string {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . self::SUBJECT );
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		return $source;
	}

	/**
	 * Calls a private method via reflection.
	 *
	 * @since 2.0.2
	 *
	 * @param object $object Target.
	 * @param string $method Method name.
	 * @return mixed
	 */
	private function call_private( $object, string $method ) {
		$reflection = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->invoke( $object );
	}

	/**
	 * Sets a private property via reflection.
	 *
	 * @since 2.0.2
	 *
	 * @param object $object   Target.
	 * @param string $property Property name.
	 * @param mixed  $value    Value.
	 * @return void
	 */
	private function set_private( $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( $object, $value );
	}
}

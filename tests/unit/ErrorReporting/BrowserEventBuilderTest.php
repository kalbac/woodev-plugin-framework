<?php
/**
 * Browser_Event_Builder — what a browser report becomes, and what it never carries (#1081, D7).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Woodev\Framework\Error_Reporting\Browser_Event_Builder;

/**
 * @covers \Woodev\Framework\Error_Reporting\Browser_Event_Builder
 */
final class BrowserEventBuilderTest extends ErrorReportingTestCase {

	private const SCRIPT = self::OUR_URL . '/assets/js/map.js';

	/**
	 * @param array<int,array<string,mixed>> $frames Innermost first.
	 * @param array<string,mixed>            $extra  Extra payload keys.
	 * @return array<string,mixed>
	 */
	private function error_payload( array $frames, array $extra = [] ): array {
		return array_merge(
			[
				'source' => 'error',
				'type'   => 'TypeError',
				'frames' => $frames,
			],
			$extra
		);
	}

	public function test_an_error_in_our_script_becomes_an_anonymised_javascript_event(): void {
		$event = $this->make_browser_builder()->from_payload(
			$this->error_payload(
				[
					[
						'url'  => self::SCRIPT . '?ver=3#frag',
						'line' => 10,
						'col'  => 20,
						'fn'   => 'draw',
					],
					[
						'url'  => self::OUR_URL . '/assets/js/mount.js',
						'line' => 5,
						'col'  => 6,
					],
				]
			)
		);

		$this->assertSame( 'javascript', $event['platform'] );
		$this->assertSame( 'acme-delivery@1.4.0', $event['release'] );
		$this->assertSame( 32, strlen( $event['event_id'] ) );
		$this->assertSame( 'browser', $event['tags']['source'] );
		$this->assertSame( 'acme-delivery', $event['tags']['plugin'] );
		$this->assertSame( 'abcdef0123456789', $event['server_name'] );

		$value = $event['exception']['values'][0];

		$this->assertSame( 'TypeError', $value['type'] );
		$this->assertArrayNotHasKey( 'value', $value, 'no message, ever' );
		$this->assertSame( 'onerror', $value['mechanism']['type'] );
		$this->assertFalse( $value['mechanism']['handled'] );

		// Oldest frame first (Sentry), paths relative to the plugins directory, no query/fragment/host.
		$this->assertSame(
			[
				[
					'filename' => 'plugins/acme-delivery/assets/js/mount.js',
					'lineno'   => 5,
					'colno'    => 6,
					'in_app'   => true,
				],
				[
					'filename' => 'plugins/acme-delivery/assets/js/map.js',
					'lineno'   => 10,
					'colno'    => 20,
					'in_app'   => true,
				],
			],
			$value['stacktrace']['frames']
		);

		$json = $this->encode( $event );
		$this->assertStringNotContainsString( 'shop.example.ru', $json );
		$this->assertStringNotContainsString( 'ver=3', $json );
		$this->assertStringNotContainsString( 'frag', $json );
	}

	public function test_a_foreign_frame_is_dropped_and_an_event_with_no_frame_of_ours_is_refused(): void {
		$builder = $this->make_browser_builder();

		$event = $builder->from_payload(
			$this->error_payload(
				[
					[ 'url' => 'https://shop.example.ru/wp-content/plugins/other/x.js' ],
					[ 'url' => self::SCRIPT ],
					[ 'url' => 'https://evil.example/wp-content/plugins/acme-delivery/a.js' ],
				]
			)
		);

		$this->assertCount( 1, $event['exception']['values'][0]['stacktrace']['frames'] );
		$this->assertNull( $builder->from_payload( $this->error_payload( [ [ 'url' => 'https://shop.example.ru/wp-content/plugins/other/x.js' ] ] ) ) );
		$this->assertNull( $builder->from_payload( $this->error_payload( [] ) ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function hostile_urls(): array {
		return [
			'dot-dot segment'      => [ self::OUR_URL . '/../other-plugin/x.js' ],
			'encoded dot segment'  => [ self::OUR_URL . '/%2e%2e/other-plugin/x.js' ],
			'sibling with prefix'  => [ self::OUR_URL . '-pro/x.js' ],
			'the base itself'      => [ self::OUR_URL ],
			'userinfo trick'       => [ 'https://shop.example.ru@evil.example/wp-content/plugins/acme-delivery/x.js' ],
			'backslash'            => [ 'https://shop.example.ru/wp-content/plugins/acme-delivery\\..\\x.js' ],
			'not a url'            => [ 'javascript:alert(1)' ],
		];
	}

	/**
	 * @dataProvider hostile_urls
	 */
	public function test_a_url_that_only_looks_like_ours_is_refused( string $url ): void {
		$this->assertNull( $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => $url ] ] ) ) );
	}

	public function test_scheme_and_host_case_do_not_matter_but_the_path_does(): void {
		$builder = $this->make_browser_builder();

		$this->assertNotNull( $builder->from_payload( $this->error_payload( [ [ 'url' => 'http://SHOP.example.ru/wp-content/plugins/acme-delivery/a.js' ] ] ) ) );
		$this->assertNull( $builder->from_payload( $this->error_payload( [ [ 'url' => 'https://shop.example.ru/wp-content/plugins/Acme-Delivery/a.js' ] ] ) ) );
	}

	public function test_a_mixed_case_registered_base_and_a_mixed_case_candidate_match_independently(): void {
		$scope = new \Woodev\Framework\Error_Reporting\Plugin_Scope(
			[
				[
					'id'      => 'acme-delivery',
					'version' => '1.4.0',
					'dir'     => $this->plugin_dir,
					'url'     => 'https://SHOP.Example.RU/wp-content/plugins/acme-delivery',
				],
			]
		);

		foreach ( [ 'https://shop.example.ru', 'https://SHOP.EXAMPLE.RU', 'http://Shop.example.Ru' ] as $host ) {
			$located = $scope->locate_url( $host . '/wp-content/plugins/acme-delivery/a.js' );

			$this->assertSame( 'plugins/acme-delivery/a.js', $located['path'] ?? null, $host );
		}

		// The lowercase base is told to the browser as registered — it lowercases the authority itself.
		$this->assertSame( [ 'https://SHOP.Example.RU/wp-content/plugins/acme-delivery' ], $scope->url_bases() );
	}

	public function test_no_message_in_the_payload_ever_reaches_the_event(): void {
		$event = $this->make_browser_builder()->from_payload(
			$this->error_payload(
				[ [ 'url' => self::SCRIPT ] ],
				[
					'message' => 'Иван Иванов, ул. Ленина 1',
					'stack'   => 'TypeError: secret',
					'value'   => 'secret',
				]
			)
		);

		$json = $this->encode( $event );

		$this->assertStringNotContainsString( 'Иван', $json );
		$this->assertStringNotContainsString( 'secret', $json );
		$this->assertStringNotContainsString( 'Ленина', $json );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function error_names(): array {
		return [
			'standard TypeError'    => [ 'TypeError', 'TypeError' ],
			'standard AggregateError' => [ 'AggregateError', 'AggregateError' ],
			'a name with a person in it' => [ 'John_Smith', 'Error' ],
			'a valid identifier that is not a standard name' => [ 'JohnSmith79001234567', 'Error' ],
			'syntactically bad'     => [ 'Bad: secret@example.com', 'Error' ],
			'case matters'          => [ 'typeerror', 'Error' ],
			'empty'                 => [ '', 'Error' ],
		];
	}

	/**
	 * @dataProvider error_names
	 */
	public function test_the_error_type_comes_from_the_closed_set_of_standard_names( string $sent, string $exported ): void {
		$event = $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => self::SCRIPT ] ], [ 'type' => $sent ] ) );

		$this->assertSame( $exported, $event['exception']['values'][0]['type'] );
		$this->assertStringNotContainsString( 'John', $this->encode( $event ) );
	}

	public function test_a_function_name_is_never_exported_and_positions_are_clamped(): void {
		$event = $this->make_browser_builder()->from_payload(
			$this->error_payload(
				[
					[
						'url'  => self::SCRIPT,
						'line' => 99999999999,
						'col'  => -4,
						'fn'   => 'John.Smith',
					],
				]
			)
		);

		$frame = $event['exception']['values'][0]['stacktrace']['frames'][0];

		$this->assertSame( 10000000, $frame['lineno'] );
		$this->assertSame( 0, $frame['colno'] );
		$this->assertArrayNotHasKey( 'function', $frame );
		$this->assertStringNotContainsString( 'John', $this->encode( $event ) );
	}

	public function test_at_most_thirty_frames_are_kept(): void {
		$frames = [];

		for ( $i = 1; $i <= 45; ++$i ) {
			$frames[] = [
				'url'  => self::SCRIPT,
				'line' => $i,
			];
		}

		$event = $this->make_browser_builder()->from_payload( $this->error_payload( $frames ) );

		$this->assertCount( 30, $event['exception']['values'][0]['stacktrace']['frames'] );
	}

	public function test_the_pickup_event_carries_plugin_field_and_code_only(): void {
		$event = $this->make_browser_builder()->from_payload(
			[
				'source'   => 'pickup',
				'pluginId' => 'acme-delivery',
				'fieldId'  => 'pickup_point',
				'code'     => 'map_script',
				'message'  => 'Иван Иванов, ул. Ленина 1',
			]
		);

		$value = $event['exception']['values'][0];

		$this->assertSame( 'woodev_pickup_error', $value['type'] );
		$this->assertSame( 'acme-delivery:pickup_point:map_script', $value['value'] );
		$this->assertSame( 'pickup_point', $event['tags']['field_id'] );
		$this->assertSame( 'acme-delivery@1.4.0', $event['release'] );
		$this->assertArrayNotHasKey( 'stacktrace', $value );
		$this->assertStringNotContainsString( 'Иван', $this->encode( $event ) );
	}

	public function test_every_code_the_pickup_providers_emit_is_exported_as_it_is(): void {
		foreach ( Browser_Event_Builder::PICKUP_CODES as $code ) {
			$event = $this->make_browser_builder()->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => $code,
				]
			);

			$this->assertSame( 'acme-delivery:pickup_point:' . $code, $event['exception']['values'][0]['value'] );
		}
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function unknown_pickup_codes(): array {
		return [
			'a phone number'      => [ '79001234567' ],
			'a person'            => [ 'John.Smith' ],
			'a made-up code'      => [ 'woodev_pickup_something_new' ],
			'a known code, wrong case' => [ 'MAP_SCRIPT' ],
		];
	}

	/**
	 * @dataProvider unknown_pickup_codes
	 */
	public function test_a_pickup_code_outside_the_closed_set_becomes_the_unknown_constant( string $code ): void {
		$event = $this->make_browser_builder()->from_payload(
			[
				'source'   => 'pickup',
				'pluginId' => 'acme-delivery',
				'fieldId'  => 'pickup_point',
				'code'     => $code,
			]
		);

		$this->assertSame( 'acme-delivery:pickup_point:unknown', $event['exception']['values'][0]['value'] );
		$this->assertStringNotContainsString( $code, $this->encode( $event ) );
	}

	/**
	 * The critic's payloads: syntactically valid tokens that are a person's name and a phone number.
	 */
	public function test_a_pickup_event_with_a_field_the_server_does_not_know_is_dropped(): void {
		$builder = $this->make_browser_builder();

		$this->assertNull(
			$builder->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'John.Smith',
					'code'     => '79001234567',
				]
			)
		);
		$this->assertNull(
			$builder->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point_2', // Known for NO plugin.
					'code'     => 'map_script',
				]
			)
		);
	}

	public function test_a_field_is_known_per_plugin_not_globally(): void {
		$builder = $this->make_browser_builder( [ 'someone-else' => [ 'pickup_point' ] ] );

		$this->assertNull(
			$builder->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);
	}

	public function test_without_any_declared_field_no_pickup_event_is_exported(): void {
		$builder = $this->make_browser_builder( [] );

		$this->assertNull(
			$builder->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);
	}

	public function test_junk_in_the_declared_fields_is_ignored(): void {
		$builder = $this->make_browser_builder( [ 'acme-delivery' => [ 'pickup_point', 7, null, [ 'x' ] ], 5 => [ 'pickup_point' ], 'broken' => 'pickup_point' ] );

		$this->assertNotNull(
			$builder->from_payload(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);
	}

	/**
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function refused_pickup_payloads(): array {
		$ok = [
			'source'   => 'pickup',
			'pluginId' => 'acme-delivery',
			'fieldId'  => 'pickup_point',
			'code'     => 'map_script',
		];

		return [
			'unregistered plugin' => [ array_merge( $ok, [ 'pluginId' => 'someone-else' ] ) ],
			'no plugin'           => [ array_diff_key( $ok, [ 'pluginId' => 1 ] ) ],
			'field with space'    => [ array_merge( $ok, [ 'fieldId' => 'pickup point' ] ) ],
			'code too long'       => [ array_merge( $ok, [ 'code' => str_repeat( 'x', 65 ) ] ) ],
			'code with newline'   => [ array_merge( $ok, [ 'code' => "map\n" ] ) ],
			'code not a string'   => [ array_merge( $ok, [ 'code' => [ 'x' ] ] ) ],
		];
	}

	/**
	 * @dataProvider refused_pickup_payloads
	 * @param array<string,mixed> $payload Payload.
	 */
	public function test_a_malformed_or_unattributable_pickup_event_is_refused( array $payload ): void {
		$this->assertNull( $this->make_browser_builder()->from_payload( $payload ) );
	}

	public function test_an_unknown_source_is_refused(): void {
		$this->assertNull( $this->make_browser_builder()->from_payload( [ 'source' => 'console' ] ) );
		$this->assertNull( $this->make_browser_builder()->from_payload( [] ) );
	}

	public function test_a_rejection_report_has_its_own_mechanism(): void {
		$event = $this->make_browser_builder()->from_payload(
			[
				'source' => 'unhandledrejection',
				'type'   => 'RangeError',
				'frames' => [ [ 'url' => self::SCRIPT ] ],
			]
		);

		$this->assertSame( 'onunhandledrejection', $event['exception']['values'][0]['mechanism']['type'] );
	}

	/**
	 * Free text in a frame URL: the prefix is ours, the rest is the customer's. None of it may be exported.
	 *
	 * @return array<string,array{string}>
	 */
	public function untrusted_asset_urls(): array {
		return [
			// The critic's repro: literal PII path segments, no such file.
			'literal PII path'            => [ self::OUR_URL . '/Иван/Ленина 1/john@example.com.js' ],
			'literal PII, critic repro'   => [ self::OUR_URL . '/Иван/Ленина%201/john@example.com.js' ],
			'percent-encoded PII path'    => [ self::OUR_URL . '/%D0%98%D0%B2%D0%B0%D0%BD/%D0%9B%D0%B5%D0%BD%D0%B8%D0%BD%D0%B0%201/john%40example.com.js' ],
			'an invented file name'       => [ self::OUR_URL . '/assets/js/John.Smith.js' ],
			'an invented file, real dir'  => [ self::OUR_URL . '/assets/js/79001234567.js' ],
			'a real file, wrong dir'      => [ self::OUR_URL . '/js/map.js' ],
			'encoded slash'               => [ self::OUR_URL . '/assets%2fjs%2fmap.js' ],
			'encoded slash, upper case'   => [ self::OUR_URL . '/assets%2Fjs/map.js' ],
			'encoded backslash'           => [ self::OUR_URL . '/assets%5cjs%5cmap.js' ],
			'encoded dot-dot'             => [ self::OUR_URL . '/assets/%2e%2e/assets/js/map.js' ],
			'encoded dot-dot, upper case' => [ self::OUR_URL . '/assets/js/%2E%2E/js/map.js' ],
			'dot-dot to a sibling plugin' => [ self::OUR_URL . '/../other-plugin/secret.js' ],
			'encoded dot-dot to sibling'  => [ self::OUR_URL . '/%2e%2e/other-plugin/secret.js' ],
			'double-encoded dot-dot'      => [ self::OUR_URL . '/%252e%252e/other-plugin/secret.js' ],
			'a NUL byte'                  => [ self::OUR_URL . '/assets/js/map.js%00.png' ],
			'a NUL before the extension'  => [ self::OUR_URL . '/assets/js/map%00.js' ],
			'a newline'                   => [ self::OUR_URL . '/assets/js/map.js%0a.js' ],
			'an empty segment'            => [ self::OUR_URL . '/assets//js/map.js' ],
			'not a script'                => [ self::OUR_URL . '/readme.txt' ],
			'a directory'                 => [ self::OUR_URL . '/assets/js.js' ],
			'a real file, wrong extension' => [ self::OUR_URL . '/assets/js/map.js.txt' ],
		];
	}

	/**
	 * @dataProvider untrusted_asset_urls
	 */
	public function test_a_frame_url_that_is_not_an_existing_script_of_the_plugin_is_dropped_and_the_report_with_it( string $url ): void {
		$event = $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => $url ] ] ) );

		$this->assertNull( $event, 'no frame survives → the report is dropped; nothing of the URL is exported' );
	}

	public function test_only_the_frames_that_resolve_to_a_real_script_survive_next_to_an_invented_one(): void {
		$event = $this->make_browser_builder()->from_payload(
			$this->error_payload(
				[
					[ 'url' => self::OUR_URL . '/Иван/Ленина%201/john@example.com.js' ],
					[ 'url' => self::SCRIPT ],
				]
			)
		);

		$frames = $event['exception']['values'][0]['stacktrace']['frames'];

		$this->assertCount( 1, $frames );
		$this->assertSame( 'plugins/acme-delivery/assets/js/map.js', $frames[0]['filename'] );

		$json = $this->encode( $event );

		foreach ( [ 'Иван', 'Ленина', 'john', 'example.com' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $json );
		}
	}

	public function test_a_real_asset_is_exported_under_the_path_the_filesystem_reports(): void {
		$builder = $this->make_browser_builder();

		foreach (
			[
				self::OUR_URL . '/assets/js/map.js'      => 'plugins/acme-delivery/assets/js/map.js',
				self::OUR_URL . '/a.js'                  => 'plugins/acme-delivery/a.js',
				// A percent-encoded space names the real file «my map.js» — decoded once, before the check.
				self::OUR_URL . '/assets/js/my%20map.js' => 'plugins/acme-delivery/assets/js/my map.js',
			] as $url => $expected
		) {
			$event = $builder->from_payload( $this->error_payload( [ [ 'url' => $url ] ] ) );

			$this->assertSame( $expected, $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'], $url );
		}
	}

	public function test_a_symlink_that_leaves_the_plugin_directory_is_not_followed(): void {
		$link = $this->plugin_dir . '/assets/js/escape.js';

		if ( ! @symlink( $this->fs_root . '/other-plugin/secret.js', $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filesystem without symlinks skips the test.
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}

		$this->assertNull( $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => self::OUR_URL . '/assets/js/escape.js' ] ] ) ) );
	}

	public function test_a_symlink_that_stays_inside_the_plugin_is_exported_under_its_target(): void {
		$link = $this->plugin_dir . '/assets/js/alias.js';

		if ( ! @symlink( $this->plugin_dir . '/assets/js/map.js', $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filesystem without symlinks skips the test.
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}

		$event = $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => self::OUR_URL . '/assets/js/alias.js' ] ] ) );

		$this->assertSame( 'plugins/acme-delivery/assets/js/map.js', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'] );
	}

	public function test_the_critics_payloads_export_nothing_the_customer_typed(): void {
		$builder = $this->make_browser_builder();

		$this->assertNull(
			$builder->from_payload(
				[
					'source' => 'error',
					'type'   => 'Error',
					'frames' => [ [ 'url' => self::OUR_URL . '/Иван/Ленина%201/john@example.com.js', 'line' => 1 ] ],
				]
			)
		);

		$event = $builder->from_payload(
			[
				'source' => 'error',
				'type'   => 'John_Smith',
				'frames' => [ [ 'url' => self::SCRIPT, 'line' => 1, 'fn' => 'John.Smith' ] ],
			]
		);

		$this->assertStringNotContainsString( 'John', $this->encode( $event ) );
	}
}

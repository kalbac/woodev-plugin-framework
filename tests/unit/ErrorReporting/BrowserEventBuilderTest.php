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
					'function' => 'draw',
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

	public function test_an_error_name_that_is_not_an_identifier_becomes_plain_error(): void {
		$event = $this->make_browser_builder()->from_payload( $this->error_payload( [ [ 'url' => self::SCRIPT ] ], [ 'type' => 'Bad: secret@example.com' ] ) );

		$this->assertSame( 'Error', $event['exception']['values'][0]['type'] );
	}

	public function test_a_function_name_must_be_an_identifier_and_positions_are_clamped(): void {
		$event = $this->make_browser_builder()->from_payload(
			$this->error_payload(
				[
					[
						'url'  => self::SCRIPT,
						'line' => 99999999999,
						'col'  => -4,
						'fn'   => 'has spaces and Иван',
					],
				]
			)
		);

		$frame = $event['exception']['values'][0]['stacktrace']['frames'][0];

		$this->assertSame( 10000000, $frame['lineno'] );
		$this->assertSame( 0, $frame['colno'] );
		$this->assertArrayNotHasKey( 'function', $frame );
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
}

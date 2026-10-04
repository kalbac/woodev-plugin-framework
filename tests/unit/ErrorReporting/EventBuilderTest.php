<?php
/**
 * Event_Builder — event shape, attribution and PII scrubbing (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

/**
 * @covers \Woodev\Framework\Error_Reporting\Event_Builder
 */
final class EventBuilderTest extends ErrorReportingTestCase {

	public function test_an_exception_thrown_in_our_plugin_becomes_an_attributed_event(): void {
		$e = $this->make_exception(
			self::OURS . '/includes/class-export.php',
			42,
			[
				[
					'file'     => self::OURS . '/includes/class-handler.php',
					'line'     => 10,
					'function' => 'run',
					'class'    => 'Acme\\Handler',
					'type'     => '->',
				],
			],
			'API said no'
		);

		$event = $this->make_builder()->from_throwable( $e, null, false );

		$this->assertSame( 'acme-delivery@1.4.0', $event['release'] );
		$this->assertSame( 'fatal', $event['level'] );
		$this->assertSame( 'abcdef0123456789', $event['server_name'] );
		$this->assertSame(
			[
				'plugin'            => 'acme-delivery',
				'plugin_version'    => '1.4.0',
				'framework_version' => '2.0.1',
				'wp_version'        => '6.8',
				'wc_version'        => '10.0.0',
				'php_version'       => '8.1.0',
				'site'              => 'abcdef0123456789',
			],
			$event['tags']
		);

		$value = $event['exception']['values'][0];
		$this->assertSame( 'RuntimeException', $value['type'] );
		$this->assertSame( 'API said no', $value['value'] );
		$this->assertFalse( $value['mechanism']['handled'] );

		// Oldest frame first: the caller, then the throw site carrying the called function.
		$frames = $value['stacktrace']['frames'];
		$this->assertCount( 2, $frames );
		$this->assertSame( 'plugins/acme-delivery/includes/class-handler.php', $frames[0]['filename'] );
		$this->assertSame( 10, $frames[0]['lineno'] );
		$this->assertSame( 'plugins/acme-delivery/includes/class-export.php', $frames[1]['filename'] );
		$this->assertSame( 42, $frames[1]['lineno'] );
		$this->assertSame( 'Acme\\Handler->run', $frames[1]['function'] );
		$this->assertTrue( $frames[1]['in_app'] );
	}

	public function test_a_foreign_exception_yields_no_event(): void {
		$e = $this->make_exception( self::OTHER . '/x.php', 3, [ [ 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 9, 'function' => 'do_action' ] ] );

		$this->assertNull( $this->make_builder()->from_throwable( $e ) );
	}

	public function test_a_foreign_throw_site_called_from_our_plugin_is_attributed_to_us(): void {
		$e = $this->make_exception( self::OTHER . '/x.php', 3, [ [ 'file' => self::OURS . '/hooks.php', 'line' => 20, 'function' => 'foreign_call' ] ] );

		$event = $this->make_builder()->from_throwable( $e );

		$this->assertSame( 'acme-delivery', $event['tags']['plugin'] );
		$frames = $event['exception']['values'][0]['stacktrace']['frames'];
		$this->assertTrue( $frames[0]['in_app'] );
		$this->assertFalse( $frames[1]['in_app'] );
		$this->assertSame( 'plugins/some-other-plugin/x.php', $frames[1]['filename'] );
	}

	public function test_an_explicit_plugin_id_attributes_a_manual_capture_even_with_a_foreign_stack(): void {
		$e = $this->make_exception( self::OTHER . '/x.php', 3 );

		$event = $this->make_builder()->from_throwable( $e, 'acme-delivery' );

		$this->assertSame( 'acme-delivery@1.4.0', $event['release'] );
		$this->assertSame( 'error', $event['level'] );
		$this->assertTrue( $event['exception']['values'][0]['mechanism']['handled'] );
	}

	public function test_an_unknown_plugin_id_does_not_make_a_foreign_error_ours(): void {
		$e = $this->make_exception( self::OTHER . '/x.php', 3 );

		$this->assertNull( $this->make_builder()->from_throwable( $e, 'not-registered' ) );
	}

	public function test_function_arguments_never_reach_the_event(): void {
		$e = $this->make_exception(
			self::OURS . '/a.php',
			1,
			[
				[
					'file'     => self::OURS . '/b.php',
					'line'     => 2,
					'function' => 'login',
					'args'     => [ 'hunter2-secret', [ 'card' => '4111111111111111' ] ],
				],
			]
		);

		$json = $this->encode( $this->make_builder()->from_throwable( $e ) );

		$this->assertStringNotContainsString( 'hunter2-secret', $json );
		$this->assertStringNotContainsString( '4111111111111111', $json );
		$this->assertStringNotContainsString( '"args"', $json );
	}

	public function test_the_event_carries_no_site_address_no_absolute_path_and_no_request_or_user_data(): void {
		$e = $this->make_exception(
			self::OURS . '/a.php',
			1,
			[ [ 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 5, 'function' => 'apply_filters' ] ],
			'cURL error: could not reach https://shop.example.ru/wp-json/x for buyer ivan@mail.ru in /srv/wp/wp-content/plugins/acme-delivery/a.php'
		);

		$event = $this->make_builder()->from_throwable( $e );
		$json  = $this->encode( $event );

		$this->assertStringNotContainsString( 'shop.example.ru', $json );
		$this->assertStringNotContainsString( 'ivan@mail.ru', $json );
		$this->assertStringNotContainsString( '/srv/wp/', $json );
		$this->assertArrayNotHasKey( 'request', $event );
		$this->assertArrayNotHasKey( 'user', $event );
		$this->assertArrayNotHasKey( 'contexts', $event );
		$this->assertStringContainsString( '[site]', $event['exception']['values'][0]['value'] );
		$this->assertStringContainsString( '[email]', $event['exception']['values'][0]['value'] );
		$this->assertStringContainsString( 'plugins/acme-delivery/a.php', $event['exception']['values'][0]['value'] );
		$this->assertSame( 'wp-includes/plugin.php', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'] );
	}

	public function test_namespace_backslashes_in_a_message_survive_scrubbing(): void {
		$e = $this->make_exception( self::OURS . '/a.php', 1, [], 'Class "Acme\\Missing" not found' );

		$this->assertSame( 'Class "Acme\\Missing" not found', $this->make_builder()->from_throwable( $e )['exception']['values'][0]['value'] );
	}

	public function test_a_path_outside_the_site_keeps_only_its_basename(): void {
		$this->assertSame( '[external]/secret.php', $this->make_builder()->relative_path( '/home/ivan/private/secret.php' ) );
		$this->assertSame( '[internal]', $this->make_builder()->relative_path( '' ) );
	}

	public function test_a_long_multibyte_message_is_cut_on_a_character_boundary(): void {
		$e = $this->make_exception( self::OURS . '/a.php', 1, [], str_repeat( 'ошибка ', 200 ) );

		$value = $this->make_builder()->from_throwable( $e )['exception']['values'][0]['value'];

		$this->assertLessThanOrEqual( 504, strlen( $value ) );
		$this->assertTrue( mb_check_encoding( $value, 'UTF-8' ) );
	}

	public function test_an_anonymous_class_type_does_not_leak_its_path(): void {
		$anonymous = new class( 'x' ) extends \RuntimeException {};
		$e         = $this->make_exception( self::OURS . '/a.php', 1 );
		$builder   = $this->make_builder();

		$this->assertStringNotContainsString( "\0", $builder->from_throwable( $anonymous, 'acme-delivery' )['exception']['values'][0]['type'] );
		$this->assertNotNull( $builder->from_throwable( $e ) );
	}

	public function test_a_fatal_in_our_file_is_reported_with_its_error_type(): void {
		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size exhausted in /srv/wp/wp-content/plugins/acme-delivery/a.php',
				'file'    => self::OURS . '/a.php',
				'line'    => 77,
			]
		);

		$this->assertSame( 'fatal', $event['level'] );
		$this->assertSame( 'E_ERROR', $event['exception']['values'][0]['type'] );
		$this->assertSame( 'plugins/acme-delivery/a.php', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'] );
	}

	public function test_a_fatal_in_a_foreign_file_is_dropped(): void {
		$this->assertNull(
			$this->make_builder()->from_fatal(
				[
					'type'    => E_ERROR,
					'message' => 'boom',
					'file'    => self::OTHER . '/a.php',
					'line'    => 1,
				]
			)
		);
	}

	public function test_an_uncaught_fatal_is_attributed_through_its_stack_text_and_loses_the_argument_text(): void {
		$message = "Uncaught Exception: no route in /srv/wp/wp-content/plugins/some-other-plugin/lib.php:5\n"
			. "Stack trace:\n"
			. "#0 /srv/wp/wp-content/plugins/acme-delivery/hooks.php(20): Foreign_Lib->go('hunter2-secret', Object(WC_Order))\n"
			. "#1 /srv/wp/wp-includes/class-wp-hook.php(324): handler()\n"
			. "#2 {main}\n  thrown in /srv/wp/wp-content/plugins/some-other-plugin/lib.php on line 5";

		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => $message,
				'file'    => self::OTHER . '/lib.php',
				'line'    => 5,
			]
		);

		$this->assertSame( 'acme-delivery', $event['tags']['plugin'] );
		$json = $this->encode( $event );
		$this->assertStringNotContainsString( 'hunter2-secret', $json );
		$this->assertStringNotContainsString( 'Stack trace', $json );

		$frames = $event['exception']['values'][0]['stacktrace']['frames'];
		$this->assertCount( 3, $frames );
		$this->assertSame( 'Foreign_Lib->go', $frames[1]['function'] );
	}
}

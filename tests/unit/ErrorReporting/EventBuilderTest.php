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
		$this->assertArrayNotHasKey( 'value', $value, 'an exception message never leaves the site' );
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

	/**
	 * The critic's probe strings (r1): none of them may appear anywhere in a payload built from
	 * an exception that carries them in its message.
	 *
	 * @return array<string,array{string}>
	 */
	public function probe_messages(): array {
		return [
			'name phone address' => [ 'Иван Иванов +7 (999) 123-45-67, Москва, ул. Ленина 10' ],
			'sql with address'   => [ "Duplicate entry 'Москва, ул. Ленина 10' for key 'addr' (INSERT INTO wp_x VALUES ('ivan@mail.ru'))" ],
			'external path'      => [ 'failed to open /home/ivan/private/key.pem' ],
			'url with token'     => [ 'GET https://SHOP.EXAMPLE.RU/?token=private-token-1 failed' ],
			'email'              => [ 'no account for ivan@mail.ru' ],
			'namespaced class'   => [ 'Class "Acme\\Missing" not found' ],
		];
	}

	/**
	 * @dataProvider probe_messages
	 */
	public function test_no_exception_message_text_reaches_the_event( string $message ): void {
		$e = $this->make_exception(
			self::OURS . '/a.php',
			1,
			[ [ 'file' => '/srv/wp/wp-includes/plugin.php', 'line' => 5, 'function' => 'apply_filters' ] ],
			$message
		);

		$event = $this->make_builder()->from_throwable( $e );
		// Without the random event id and the timestamp: a digit run such as «999» can turn up in them by chance.
		$json = $this->encode( array_diff_key( $event, [ 'event_id' => true, 'timestamp' => true ] ) );

		foreach ( [ 'Иван', '999', 'Ленина', 'Москва', 'INSERT', 'ivan', 'mail.ru', 'private', 'token', 'SHOP', 'EXAMPLE', 'not found', 'Missing', 'no account', 'failed' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, "«{$needle}» must not be sent" );
		}

		$this->assertStringNotContainsString( '/srv/wp/', $json );
		$this->assertArrayNotHasKey( 'request', $event );
		$this->assertArrayNotHasKey( 'user', $event );
		$this->assertArrayNotHasKey( 'contexts', $event );
		$this->assertArrayNotHasKey( 'extra', $event );
		$this->assertSame( 'wp-includes/plugin.php', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'] );
	}

	public function test_the_exception_code_is_sent_only_when_it_is_an_integer(): void {
		$builder = $this->make_builder();

		$with = $builder->from_throwable( new \RuntimeException( 'x', 502 ), 'acme-delivery' );
		$this->assertSame( [ 'code' => 502 ], $with['exception']['values'][0]['mechanism']['data'] );

		$none = $builder->from_throwable( new \RuntimeException( 'x' ), 'acme-delivery' );
		$this->assertArrayNotHasKey( 'data', $none['exception']['values'][0]['mechanism'] );

		$string_code = new \PDOException( 'x' );
		$prop        = new \ReflectionProperty( \Exception::class, 'code' );
		$prop->setValue( $string_code, '23000' );
		$this->assertArrayNotHasKey( 'data', $builder->from_throwable( $string_code, 'acme-delivery' )['exception']['values'][0]['mechanism'] );
	}

	public function test_a_path_outside_the_site_keeps_only_its_basename(): void {
		$this->assertSame( '[external]/secret.php', $this->make_builder()->relative_path( '/home/ivan/private/secret.php' ) );
		$this->assertSame( '[internal]', $this->make_builder()->relative_path( '' ) );
	}

	public function test_an_anonymous_class_name_does_not_leak_its_path_anywhere(): void {
		$anonymous = new class( 'x' ) extends \RuntimeException {};
		$builder   = $this->make_builder();

		$event = $builder->from_throwable(
			$anonymous,
			'acme-delivery'
		);
		$this->assertStringNotContainsString( "\0", $event['exception']['values'][0]['type'] );
		$this->assertStringContainsString( '@anonymous', $event['exception']['values'][0]['type'] );

		$trace_class = "class@anonymous\0" . self::OURS . '/a.php:3$0';
		$e           = $this->make_exception( self::OURS . '/a.php', 1, [ [ 'file' => self::OURS . '/b.php', 'line' => 2, 'function' => 'run', 'class' => $trace_class, 'type' => '->' ] ] );
		$json        = $this->encode( $builder->from_throwable( $e ) );

		$this->assertStringContainsString( 'class@anonymous->run', $json );
		$this->assertStringNotContainsString( "\\u0000", $json );
		$this->assertStringNotContainsString( '/srv/wp', $json );
	}

	public function test_a_closure_name_does_not_carry_its_file_path(): void {
		$e    = $this->make_exception( self::OURS . '/a.php', 1, [ [ 'file' => self::OURS . '/b.php', 'line' => 2, 'function' => '{closure:/home/ivan/private/x.php:12}' ] ] );
		$json = $this->encode( $this->make_builder()->from_throwable( $e ) );

		$this->assertStringContainsString( '{closure}', $json );
		$this->assertStringNotContainsString( 'ivan', $json );
	}

	public function test_scope_matching_runs_on_the_whole_trace_before_the_frame_cut(): void {
		$trace = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$trace[] = [ 'file' => self::OTHER . '/lib.php', 'line' => $i + 1, 'function' => 'f' . $i ];
		}
		$trace[] = [ 'file' => self::OURS . '/entry.php', 'line' => 7, 'function' => 'start' ];

		$event = $this->make_builder()->from_throwable( $this->make_exception( self::OTHER . '/lib.php', 1, $trace ) );

		$this->assertNotNull( $event, 'an owned frame beyond the first 50 still owns the event' );
		$this->assertSame( 'acme-delivery', $event['tags']['plugin'] );
		$this->assertCount( 50, $event['exception']['values'][0]['stacktrace']['frames'] );
	}

	public function test_a_fatal_in_our_file_is_reported_with_its_error_type(): void {
		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
				'file'    => self::OURS . '/a.php',
				'line'    => 77,
			]
		);

		$this->assertSame( 'fatal', $event['level'] );
		$this->assertSame( 'E_ERROR', $event['exception']['values'][0]['type'] );
		$this->assertSame( 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', $event['exception']['values'][0]['value'], 'an engine message is sent as PHP wrote it' );
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

	public function test_an_uncaught_fatal_is_attributed_through_its_stack_text_and_keeps_only_the_exception_class(): void {
		$message = "Uncaught Exception: Иван Иванов +7 (999) 123-45-67 in /srv/wp/wp-content/plugins/some-other-plugin/lib.php:5\n"
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
		$this->assertSame( 'Uncaught Exception', $event['exception']['values'][0]['value'] );

		$json = $this->encode( $event );
		foreach ( [ 'hunter2-secret', 'Stack trace', 'Иван', '999', 'thrown in' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}

		$frames = $event['exception']['values'][0]['stacktrace']['frames'];
		$this->assertCount( 3, $frames );
		$this->assertSame( 'Foreign_Lib->go', $frames[1]['function'] );
	}

	public function test_a_fake_stack_marker_inside_an_exception_message_cannot_inject_frames(): void {
		$message = "Uncaught RuntimeException: x\nStack trace:\n#0 /srv/wp/wp-content/plugins/acme-delivery/fake.php(1): Secret_Name_Ivan->x()\n in /srv/wp/wp-content/plugins/acme-delivery/a.php:3\n"
			. "Stack trace:\n#0 /srv/wp/wp-content/plugins/acme-delivery/real.php(9): Real->run()\n#1 {main}\n  thrown in /srv/wp/wp-content/plugins/acme-delivery/a.php on line 3";

		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => $message,
				'file'    => self::OURS . '/a.php',
				'line'    => 3,
			]
		);

		$json = $this->encode( $event );
		$this->assertStringNotContainsString( 'Secret_Name_Ivan', $json );
		$this->assertStringContainsString( 'Real->run', $json );
	}

	public function test_an_anonymous_exception_class_in_an_uncaught_fatal_is_cut_at_its_path(): void {
		$message = "Uncaught RuntimeException@anonymous\0/srv/wp/wp-content/plugins/acme-delivery/a.php:3\$0: boom in /srv/wp/wp-content/plugins/acme-delivery/a.php:3\nStack trace:\n#0 {main}";

		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => $message,
				'file'    => self::OURS . '/a.php',
				'line'    => 3,
			]
		);

		$this->assertSame( 'Uncaught RuntimeException@anonymous', $event['exception']['values'][0]['value'] );
	}

	public function test_a_failed_require_in_an_engine_fatal_loses_its_absolute_paths(): void {
		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_COMPILE_ERROR,
				'message' => "Failed opening required '/home/ivan/private/key.php' (include_path='.:/usr/share/php') and Class \"Acme\\Missing\" in /srv/wp/wp-content/plugins/acme-delivery/b.php",
				'file'    => self::OURS . '/a.php',
				'line'    => 3,
			]
		);

		$value = $event['exception']['values'][0]['value'];
		$this->assertStringNotContainsString( '/home/ivan', $value );
		$this->assertStringNotContainsString( '/usr/share', $value );
		$this->assertStringContainsString( '[external]/key.php', $value );
		$this->assertStringContainsString( 'plugins/acme-delivery/b.php', $value );
		$this->assertStringContainsString( 'Acme\\Missing', $value, 'namespace backslashes are not paths' );
	}

	public function test_a_long_multibyte_engine_message_is_cut_on_a_character_boundary(): void {
		$event = $this->make_builder()->from_fatal(
			[
				'type'    => E_ERROR,
				'message' => str_repeat( 'ошибка ', 200 ),
				'file'    => self::OURS . '/a.php',
				'line'    => 1,
			]
		);

		$value = $event['exception']['values'][0]['value'];

		$this->assertLessThanOrEqual( 504, strlen( $value ) );
		$this->assertTrue( mb_check_encoding( $value, 'UTF-8' ) );
	}
}

<?php
/**
 * Woodev_Script_Handler log-field sanitisation tests (issue #402).
 *
 * `ajax_log_event()` is registered for `wp_ajax_nopriv_wc_{id}_log_script_event` as well as the
 * authenticated action, and its only gate is a nonce the server prints into the front-end
 * script — so every guest who opens the checkout holds one. Whatever it accepts goes onto a
 * line of the plugin's log.
 *
 * A log file has no framing beyond the newline. A value carrying one is not logged
 * *containing* a line break; it APPENDS A LINE, and the forged line is indistinguishable from
 * anything the framework itself wrote. The old code ran `trim()`, which strips leading and
 * trailing whitespace and leaves every interior newline exactly where it is.
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/script-handler.php';

/**
 * Concrete handler so `ajax_log_event()` — not just its helper — can be driven.
 */
class Script_Handler_Log_Probe extends \Woodev_Script_Handler {

	/** @var string[] every message that reached log_event(), in order. */
	public $logged = [];

	/** @var bool */
	private $logging;

	public function __construct( bool $logging = true ) {
		$this->logging = $logging;
	}

	public function get_id() {
		return 'probe';
	}

	public function get_id_dasherized() {
		return 'probe';
	}

	protected function log_event( $message ) {
		$this->logged[] = $message;
	}

	protected function is_logging_enabled() {
		return $this->logging;
	}
}

/**
 * Class ScriptHandlerLogFieldTest.
 */
class ScriptHandlerLogFieldTest extends TestCase {

	/**
	 * Calls the protected static sanitiser.
	 *
	 * @param string $value raw value.
	 * @return string
	 */
	private function sanitize( string $value ): string {
		$method = new \ReflectionMethod( \Woodev_Script_Handler::class, 'sanitize_log_field' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( null, $value );
	}

	/**
	 * The defect itself: a payload that would have written a second, forged log line.
	 *
	 * @dataProvider provide_line_forging_payloads
	 *
	 * @param string $payload what a guest posts.
	 */
	public function test_no_payload_can_produce_a_second_line( string $payload ): void {
		$sanitized = $this->sanitize( $payload );

		$this->assertStringNotContainsString( "\n", $sanitized );
		$this->assertStringNotContainsString( "\r", $sanitized );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_line_forging_payloads(): array {
		return [
			'unix newline'      => [ "harmless\n2026-08-27 FORGED: license valid" ],
			'windows newline'   => [ "harmless\r\n2026-08-27 FORGED" ],
			'bare carriage ret' => [ "harmless\rFORGED" ],
			'many newlines'     => [ "a\n\n\n\nb" ],
			'leading newline'   => [ "\nFORGED" ],
			'form feed'         => [ "a\x0cb" ],
			'vertical tab'      => [ "a\x0bb" ],
			'null byte'         => [ "a\x00b" ],
			'ansi escape'       => [ "a\x1b[31mRED\x1b[0m" ],
			'c1 control'        => [ "a\u{0085}b" ],
		];
	}

	/**
	 * The control the test above needs: ordinary text survives intact, including the Cyrillic
	 * this framework's own messages are written in. Without it, `assertStringNotContainsString`
	 * would pass for a sanitiser that returned the empty string for everything.
	 *
	 * @dataProvider provide_legitimate_messages
	 *
	 * @param string $message a real script event.
	 */
	public function test_legitimate_text_is_unchanged( string $message ): void {
		$this->assertSame( $message, $this->sanitize( $message ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function provide_legitimate_messages(): array {
		return [
			'ascii'          => [ 'Payment form failed to initialise' ],
			'cyrillic'       => [ 'Не удалось загрузить карту' ],
			'punctuation'    => [ 'error: "code" (42) — see https://example.test/a?b=1&c=2' ],
			'emoji'          => [ 'boom 💥' ],
		];
	}

	/**
	 * A TAB is a C0 control (`	`) and is normalised like the rest, deliberately.
	 *
	 * It cannot forge a line, so this is not a security requirement — it is the rule staying
	 * ONE rule. "Every C0/C1 control becomes a space" is checkable at a glance; "every C0/C1
	 * control except tab" invites the next reader to wonder which other exceptions exist. The
	 * log has no columnar structure for a tab to align, so nothing is lost.
	 */
	public function test_a_tab_is_normalised_like_any_other_control_character(): void {
		$this->assertSame( 'a b', $this->sanitize( "a	b" ) );
	}

	/**
	 * A control character becomes a SPACE, not nothing — otherwise `A\nB` would silently
	 * become the single token `AB`, which reads as a different message rather than a
	 * sanitised one.
	 */
	public function test_a_control_character_becomes_a_space_not_nothing(): void {
		$this->assertSame( 'A B', $this->sanitize( "A\nB" ) );
	}

	/**
	 * A run of control characters collapses to ONE space, so a payload cannot pad a line to
	 * the cap with invisible bytes.
	 */
	public function test_a_run_of_control_characters_collapses_to_one_space(): void {
		$this->assertSame( 'A B', $this->sanitize( "A\r\n\r\n\r\nB" ) );
	}

	/**
	 * The second half of the same defect: one unauthenticated request must not be able to put
	 * an unbounded amount of text on a line.
	 */
	public function test_a_long_message_is_capped(): void {
		$this->assertSame( 500, mb_strlen( $this->sanitize( str_repeat( 'x', 10000 ) ) ) );
	}

	/**
	 * The cap counts CHARACTERS, not bytes — a Cyrillic message is two bytes per character in
	 * UTF-8, and capping by bytes would both halve it and risk cutting mid-character.
	 */
	public function test_the_cap_is_multibyte_aware(): void {
		$capped = $this->sanitize( str_repeat( 'я', 10000 ) );

		$this->assertSame( 500, mb_strlen( $capped ) );
		$this->assertSame( str_repeat( 'я', 500 ), $capped );
	}

	/**
	 * The control for the cap: a message shorter than it is returned whole. Without this,
	 * `assertSame( 500, … )` would pass for a sanitiser that padded everything to 500.
	 */
	public function test_control_a_short_message_is_not_padded(): void {
		$this->assertSame( 'short', $this->sanitize( 'short' ) );
	}

	/**
	 * Malformed UTF-8 makes the `/u` pass return NULL, and a caller who can post one is
	 * exactly the caller this function exists for. The byte-wise fallback must still strip the
	 * newline rather than returning the empty string — an empty return would hand the attacker
	 * a way to make the sanitiser do nothing, and would also silently destroy a legitimate
	 * mis-encoded message.
	 */
	public function test_malformed_utf8_still_has_its_newlines_stripped(): void {
		$sanitized = $this->sanitize( "\xC3\x28bad\nFORGED" );

		$this->assertStringNotContainsString( "\n", $sanitized );
		$this->assertStringContainsString( 'bad', $sanitized );
		$this->assertStringContainsString( 'FORGED', $sanitized );
	}
	/**
	 * The MAJOR a critic pass found in the merged fix: the malformed-UTF-8 fallback used the
	 * pattern `/[\x00-\x1f\x7f]+/`, stopping short of C1 (`\x80-\x9f`) that the `/u`
	 * pattern covers, so on that branch a raw `\x9b` — the single-byte ANSI CSI — was never
	 * replaced.
	 *
	 * The report this came from said the byte reached the log. Driving the whole function
	 * says otherwise: with mbstring present, `mb_substr()` rewrites invalid UTF-8, and
	 * `c328419b42` came out `3f28413f42`. The gap was real and MASKED by an optional
	 * extension. Without mbstring the old byte-wise cap did no such rewriting and the byte
	 * survived. Hence this test asserts the byte is gone — true either way after the fix, and
	 * true for the right reason.
	 *
	 * The existing C1 case above uses U+0085, which is VALID UTF-8 (`c2 85`), so it exercises
	 * the Unicode branch and could never reach this one. That is why this test names the raw
	 * byte explicitly.
	 *
	 * Asserted on THE INJECTED BYTE, not on the whole 0x80-0x9f range: continuation bytes of
	 * legitimate multi-byte characters live in 0x80-0xbf, so a range assertion would fail on
	 * any Cyrillic message. (It did, on the first draft of this test — «Ã» is `c3 83`.)
	 *
	 * @dataProvider provide_raw_c1_in_malformed_input
	 *
	 * @param string $payload  malformed UTF-8 carrying a raw C1 byte.
	 * @param string $injected the raw byte it carries.
	 */
	public function test_a_raw_c1_byte_in_malformed_utf8_is_still_stripped( string $payload, string $injected ): void {
		$this->assertStringContainsString( $injected, $payload, 'sanity: the payload really carries the byte' );
		$this->assertStringNotContainsString( $injected, $this->sanitize( $payload ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function provide_raw_c1_in_malformed_input(): array {
		return [
			// Built with chr() rather than string escapes: these are RAW bytes, and a source
			// file that stores them as escapes is one editor away from re-encoding them as
			// valid UTF-8 (which is exactly what happened to the first draft of this
			// provider, silently turning every case into the branch it does not test).
			'ansi CSI'                 => [ chr( 0xc3 ) . chr( 0x28 ) . 'A' . chr( 0x9b ) . 'B', chr( 0x9b ) ],
			'C1 lower edge'            => [ chr( 0xc3 ) . chr( 0x28 ) . 'A' . chr( 0x80 ) . 'B', chr( 0x80 ) ],
			'C1 upper edge'            => [ chr( 0xc3 ) . chr( 0x28 ) . 'A' . chr( 0x9f ) . 'B', chr( 0x9f ) ],
			'C1 after a bad lead byte' => [ chr( 0xc3 ) . chr( 0x28 ) . 'bad' . chr( 0x9b ) . 'FORGED', chr( 0x9b ) ],
		];
	}

	/**
	 * The control: the payloads above really are malformed, so they really do take the
	 * fallback branch. Without this the test would also pass for input the `/u` pattern
	 * handled, proving nothing about the branch it names.
	 *
	 * `preg_match( '//u', $s )` returns FALSE — not 0 — on an invalid subject. Asserting `0`
	 * is how the first draft of this control passed while checking nothing.
	 */
	public function test_control_the_raw_c1_payloads_really_are_malformed_utf8(): void {
		foreach ( $this->provide_raw_c1_in_malformed_input() as $name => $case ) {
			$this->assertFalse( preg_match( '//u', $case[0] ), $name . ' must be invalid UTF-8' );
		}
	}

	/**
	 * The other MINOR from the same pass: mbstring is NOT a declared requirement of this
	 * package, and the fallback was a byte-wise `substr()` that cuts mid-character. 499 ASCII
	 * characters followed by «я» came back as 500 bytes ending in a lone `0xd1` — invalid
	 * UTF-8, written into the log.
	 *
	 * The cap cannot be driven without mbstring on this machine, so what is asserted is the
	 * INVARIANT that holds either way: whatever comes back is valid UTF-8 and no longer than
	 * the cap.
	 */
	public function test_a_cap_landing_on_a_multibyte_boundary_never_returns_invalid_utf8(): void {
		$capped = $this->sanitize( str_repeat( 'a', 499 ) . 'я' . str_repeat( 'b', 50 ) );

		$this->assertSame( 1, preg_match( '//u', $capped ), 'the capped value must still be valid UTF-8' );
		$this->assertLessThanOrEqual( 500, mb_strlen( $capped ) );
	}
	/**
	 * THE ENDPOINT WIRING, and the gap a critic pass named: every test above calls
	 * `sanitize_log_field()` directly, so they all stay green if `ajax_log_event()` regresses
	 * to `trim()` or swaps the `name`/`message` fields. The sanitiser is only worth anything
	 * because that handler uses it, and nothing pinned that.
	 *
	 * Asserted on what reaches `log_event()`, which is the last thing before the log file.
	 */
	public function test_the_nopriv_endpoint_sanitises_what_it_logs(): void {
		\Brain\Monkey\Functions\when( 'wp_verify_nonce' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_send_json_success' )->justReturn( null );
		\Brain\Monkey\Functions\when( 'wp_send_json_error' )->justReturn( null );
		// The rate-limit gate (issue #577) reads/writes its counter through the transient
		// API; a single request never approaches LOG_RATE_LIMIT_MAX, so a bare "not yet
		// seen" stand-in is all this test needs. wp_using_ext_object_cache() must be
		// stubbed too, not left to function_exists()'s "false" default: once ANY test in
		// the same PHPUnit process stubs it first, the function stays permanently defined
		// for the rest of the run and this test would hit it un-mocked.
		\Brain\Monkey\Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'get_transient' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'set_transient' )->justReturn( true );

		$_POST = [
			'security' => 'nonce',
			'name'     => "widget
FORGED-NAME",
			'message'  => "boom
2026-08-27 FORGED: license valid",
		];

		$handler = new Script_Handler_Log_Probe();

		$handler->ajax_log_event();

		$this->assertCount( 1, $handler->logged );
		$this->assertStringNotContainsString( "
", $handler->logged[0] );
		// Both posted fields go through the sanitiser, not just the message.
		$this->assertStringContainsString( 'FORGED-NAME', $handler->logged[0] );
		$this->assertStringContainsString( 'boom', $handler->logged[0] );

		$_POST = [];
	}

	/**
	 * The control: with logging disabled the endpoint writes nothing at all, so the assertion
	 * above cannot pass for a handler that logs unconditionally.
	 */
	public function test_control_the_endpoint_logs_nothing_when_logging_is_disabled(): void {
		\Brain\Monkey\Functions\when( 'wp_verify_nonce' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();

		$_POST = [ 'security' => 'nonce', 'message' => 'boom' ];

		$handler = new Script_Handler_Log_Probe( false );

		$handler->ajax_log_event();

		$this->assertSame( [], $handler->logged );

		$_POST = [];
	}
}

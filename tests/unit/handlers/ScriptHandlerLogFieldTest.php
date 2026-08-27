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
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/script-handler.php';

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
}

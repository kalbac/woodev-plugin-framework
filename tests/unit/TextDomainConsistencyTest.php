<?php
/**
 * Text-domain consistency test.
 *
 * Asserts every i18n call in the framework declares the framework's own text domain.
 * Guards a silent failure class: WordPress looks a string up in the domain it was given,
 * finds nothing, and returns the original untranslated — so a wrong domain shows the
 * shopper an English fragment inside a Russian sentence and reports no error anywhere.
 *
 * The domain this repo actually leaked was `woocommerce-plugin-framework`, inherited from
 * the donor project (#421). The assertion below is deliberately wider than that one string:
 * any domain other than the framework's is a defect, whatever its provenance.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

/**
 * @coversNothing
 */
final class TextDomainConsistencyTest extends TestCase {

	/**
	 * The one text domain every framework string belongs to.
	 */
	private const TEXT_DOMAIN = 'woodev-plugin-framework';

	/**
	 * Gettext wrappers mapped to the argument count at which their LAST argument is the text
	 * domain. The position varies by wrapper — `__()` takes two arguments, `_nx()` takes five
	 * — so the arity has to be known per function: a call with FEWER arguments than this has
	 * omitted its domain entirely (WordPress then falls back to its own `default` domain),
	 * which is a different defect and is not what this test asserts.
	 *
	 * `_n_noop()`/`_nx_noop()` are included: their domain argument is also last, and a wrong
	 * one there fails at `translate_nooped_plural()` time rather than at the call site.
	 */
	private const TRANSLATION_FUNCTIONS = [
		'__'          => 2,
		'_e'          => 2,
		'esc_html__'  => 2,
		'esc_html_e'  => 2,
		'esc_attr__'  => 2,
		'esc_attr_e'  => 2,
		'esc_xml__'   => 2,
		'esc_xml_e'   => 2,
		'_x'          => 3,
		'_ex'         => 3,
		'esc_html_x'  => 3,
		'esc_attr_x'  => 3,
		'esc_xml_x'   => 3,
		'_n_noop'     => 3,
		'_n'          => 4,
		'_nx_noop'    => 4,
		'_nx'         => 5,
	];

	/**
	 * Domains other than the framework's that a framework string may legitimately declare.
	 *
	 * Borrowing a host plugin's domain reuses ITS translation of a string we did not author
	 * and do not ship — correct when the string is theirs verbatim, and the only way to keep
	 * a shared phrase consistent with the surrounding WooCommerce UI. It is an exception, so
	 * every entry has to be listed here deliberately.
	 */
	private const BORROWED_DOMAINS = [
		'woocommerce',
	];

	public function test_every_translation_call_uses_the_framework_text_domain(): void {
		$root     = dirname( __DIR__, 2 );
		$offences = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/woodev', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

			foreach ( $this->extract_wrong_domains( (string) file_get_contents( $file->getPathname() ) ) as $line => $domain ) {
				$offences[] = sprintf( '%s:%d declares text domain %s', $relative, $line, $domain );
			}
		}

		sort( $offences );

		$this->assertSame(
			[],
			$offences,
			"Every framework string must be registered under '" . self::TEXT_DOMAIN . "'. A string in another domain is never found and silently renders untranslated:\n" . implode( "\n", $offences )
		);
	}

	/**
	 * Returns `line number => declared domain` for every translation call in $source whose
	 * domain is a string literal other than the framework's.
	 *
	 * Tokenising rather than pattern-matching: the domain is the LAST argument, and its
	 * position varies by function (`__()` takes two arguments, `_nx()` takes four), so a
	 * regex would have to encode the arity of every wrapper. Walking the token stream to the
	 * call's closing parenthesis reads the last argument directly, whatever the arity — and
	 * it will not match the function names where they appear inside a comment or a string.
	 *
	 * A call whose last argument is not a plain string literal (a constant, a variable, a
	 * concatenation) is skipped rather than reported: this test cannot know what it resolves
	 * to, and guessing would make it fail on code that is fine.
	 *
	 * @param string $source
	 * @return array<int, string>
	 */
	private function extract_wrong_domains( string $source ): array {
		$tokens = token_get_all( $source );
		$found  = [];

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || \T_STRING !== $token[0] || ! array_key_exists( $token[1], self::TRANSLATION_FUNCTIONS ) ) {
				continue;
			}

			// A method or property of the same name is not a gettext call.
			$preceding = $this->previous_significant( $tokens, $index );
			if ( is_array( $preceding ) && in_array( $preceding[0], [ \T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION ], true ) ) {
				continue;
			}

			$opening = $this->next_significant_index( $tokens, $index );
			if ( null === $opening || '(' !== $tokens[ $opening ] ) {
				continue;
			}

			$call = $this->read_call( $tokens, $opening );

			// Fewer arguments than the wrapper's arity means no domain was passed at all —
			// a real defect, but a different one, and not this test's assertion.
			if ( null === $call || $call['arguments'] !== self::TRANSLATION_FUNCTIONS[ $token[1] ] ) {
				continue;
			}

			$last = $call['last'];
			if ( ! is_array( $last ) || \T_CONSTANT_ENCAPSED_STRING !== $last[0] ) {
				continue;
			}

			$domain = trim( $last[1], "'\"" );
			if ( self::TEXT_DOMAIN !== $domain && ! in_array( $domain, self::BORROWED_DOMAINS, true ) ) {
				$found[ $token[2] ] = $domain;
			}
		}

		return $found;
	}

	/**
	 * Reads the call whose parenthesis opens at $opening, returning how many top-level
	 * arguments it passes and the final token of the last one — or null when the call is
	 * unbalanced or takes no arguments.
	 *
	 * Nesting is tracked for `()`, `[]` and `{}` alike, so a comma inside an inner call or
	 * an inline array does not inflate the argument count, and the returned token belongs to
	 * the outer call's own last argument.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int                                            $opening
	 * @return array{arguments:int, last:array{0:int,1:string,2:int}|string|null}|null
	 */
	private function read_call( array $tokens, int $opening ): ?array {
		$depth     = 0;
		$last      = null;
		$arguments = 0;

		for ( $i = $opening, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( in_array( $token, [ '(', '[', '{' ], true ) ) {
				++$depth;
				continue;
			}

			if ( in_array( $token, [ ')', ']', '}' ], true ) ) {
				--$depth;

				if ( 0 === $depth ) {
					return [
						'arguments' => null === $last && 0 === $arguments ? 0 : $arguments + 1,
						'last'      => $last,
					];
				}

				continue;
			}

			if ( 1 !== $depth ) {
				continue;
			}

			if ( ',' === $token ) {
				++$arguments;
				$last = null;
				continue;
			}

			if ( is_array( $token ) && ! in_array( $token[0], [ \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ], true ) ) {
				$last = $token;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int                                            $index
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private function previous_significant( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], [ \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ], true ) ) {
				continue;
			}

			return $tokens[ $i ];
		}

		return null;
	}

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int                                            $index
	 * @return int|null
	 */
	private function next_significant_index( array $tokens, int $index ): ?int {
		for ( $i = $index + 1, $count = count( $tokens ); $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], [ \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ], true ) ) {
				continue;
			}

			return $i;
		}

		return null;
	}
}

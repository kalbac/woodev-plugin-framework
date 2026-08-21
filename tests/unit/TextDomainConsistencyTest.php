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
	 * Guards the scanner itself.
	 *
	 * The scan above is only worth its runtime if it cannot be walked past, and the failure
	 * that matters is the FALSE GREEN — a wrong domain the scanner does not report. The
	 * trailing-comma case below is not hypothetical: an independent critic review found and
	 * reproduced it against the first version of this file, where a legal
	 * `__( 'text', 'wrong-domain', )` read as three arguments, missed the arity check for
	 * `__()`, and was skipped as though it had declared no domain at all.
	 *
	 * @dataProvider provide_scanner_cases
	 *
	 * @param string             $source   PHP source to scan.
	 * @param array<int, string> $expected Domains the scanner must report, in order.
	 */
	public function test_the_scanner_reports_exactly_these_domains( string $source, array $expected ): void {
		$this->assertSame( $expected, array_values( $this->extract_wrong_domains( "<?php\n" . $source ) ) );
	}

	/**
	 * @return array<string, array{0:string, 1:array<int, string>}>
	 */
	public function provide_scanner_cases(): array {
		return [
			'plain wrong domain'              => [ "__( 'text', 'wrong-domain' );", [ 'wrong-domain' ] ],
			'wrong domain, trailing comma'    => [ "__( 'text', 'wrong-domain', );", [ 'wrong-domain' ] ],
			'correct domain'                  => [ "__( 'text', 'woodev-plugin-framework' );", [] ],
			'correct domain, trailing comma'  => [ "__( 'text', 'woodev-plugin-framework', );", [] ],
			'borrowed domain is allowed'      => [ "__( 'WooCommerce', 'woocommerce' );", [] ],
			'no domain is a different defect' => [ "__( 'text' );", [] ],
			'context wrapper'                 => [ "_x( 'text', 'context', 'wrong-domain' );", [ 'wrong-domain' ] ],
			'plural wrapper'                  => [ "_n( 'one', 'many', \$n, 'wrong-domain' );", [ 'wrong-domain' ] ],
			'nested call in first argument'   => [ "__( sprintf( '%s, %s', \$a, \$b ), 'wrong-domain' );", [ 'wrong-domain' ] ],
			'inline array in first argument'  => [ "__( \$map[ 'a', ], 'wrong-domain' );", [ 'wrong-domain' ] ],
			'closure in first argument'       => [ "__( \$f( function ( \$v ) { return [ \$v, 1 ]; } ), 'wrong-domain' );", [ 'wrong-domain' ] ],
			'brace interpolation in string'   => [ "__( \"text {\$var} more\", 'wrong-domain' );", [ 'wrong-domain' ] ],
			'method of the same name'         => [ "\$obj->__( 'text', 'wrong-domain' );", [] ],
			'static of the same name'         => [ "Klass::__( 'text', 'wrong-domain' );", [] ],
			'non-literal domain is reported'  => [
				"__( 'text', SOME_CONSTANT );",
				[ 'SOME_CONSTANT (not a string literal, so its domain cannot be verified)' ],
			],
		];
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

			// A domain that is not a plain string literal cannot be checked here, and
			// skipping it silently is the same false green the trailing comma produced: a
			// constant or variable is free to resolve to any domain at all. There are none
			// in woodev/ today, so the honest assertion is that there continue to be none —
			// if one ever appears, this fails and someone decides deliberately how to verify
			// it, rather than the guarantee quietly narrowing.
			if ( ! is_array( $last ) || \T_CONSTANT_ENCAPSED_STRING !== $last[0] ) {
				$found[ $token[2] ] = sprintf(
					'%s (not a string literal, so its domain cannot be verified)',
					is_array( $last ) ? $last[1] : (string) $last
				);
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
	 * arguments it passes and the final significant token of the last one — or null when the
	 * call is unbalanced.
	 *
	 * Arguments are accumulated rather than counted, because a PHP trailing comma
	 * (`__( 'text', 'domain', )`, legal since 7.3) otherwise reads as one argument more than
	 * the call really passes. That inflated count missed the wrapper's arity, the caller
	 * skipped the call as "no domain given", and a genuinely wrong domain written that way
	 * was accepted silently — a FALSE GREEN in the one direction that matters, found by an
	 * independent critic review and reproduced before this fix. Collecting each argument's
	 * final token and taking the last non-empty one gets both the count and the domain right,
	 * with or without the trailing comma.
	 *
	 * Nesting is tracked for `()`, `[]` and `{}`, so a comma inside an inner call, an inline
	 * array or a closure body does not split an argument. `{` also arrives as the ARRAY
	 * tokens `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` when a double-quoted string
	 * interpolates (`"text {$var}"`) while its `}` arrives as the plain string — counting
	 * only the plain `{` would leave the depth permanently short and make every later comma
	 * in the file read at the wrong level.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int                                            $opening
	 * @return array{arguments:int, last:array{0:int,1:string,2:int}|string|null}|null
	 */
	private function read_call( array $tokens, int $opening ): ?array {
		$depth     = 0;
		$current   = null;
		$arguments = [];

		for ( $i = $opening, $count = count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( in_array( $token, [ '(', '[', '{' ], true )
				|| ( is_array( $token ) && in_array( $token[0], [ \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
				++$depth;
				continue;
			}

			if ( in_array( $token, [ ')', ']', '}' ], true ) ) {
				--$depth;

				if ( 0 === $depth ) {
					if ( null !== $current ) {
						$arguments[] = $current;
					}

					return [
						'arguments' => count( $arguments ),
						'last'      => [] === $arguments ? null : end( $arguments ),
					];
				}

				continue;
			}

			if ( 1 !== $depth ) {
				continue;
			}

			if ( ',' === $token ) {
				$arguments[] = $current;
				$current     = null;
				continue;
			}

			if ( is_array( $token ) && ! in_array( $token[0], [ \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ], true ) ) {
				$current = $token;
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

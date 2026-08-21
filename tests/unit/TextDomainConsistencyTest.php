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
	 * The name of the domain parameter, identical across every wrapper below — which is what
	 * makes a named argument resolvable without a per-function table of parameter names.
	 */
	private const DOMAIN_PARAMETER = 'domain';

	/**
	 * Gettext wrappers mapped to their POSITIONAL domain argument's 1-based position. It
	 * varies by wrapper — `__( $text, $domain )` puts it second, `_nx( $single, $plural,
	 * $number, $context, $domain )` fifth — so the position has to be known per function.
	 *
	 * Read as a POSITION, not as an argument count. An earlier version of this file treated it
	 * as "the call must pass exactly this many arguments, and the domain is the last one", and
	 * an independent critic review broke that three separate ways, each a FALSE GREEN:
	 *
	 * - `__( domain: 'wrong', text: 'x' )` — named arguments are order-independent, so the
	 *   domain is not last and the call does not even have a positional domain;
	 * - `__( 'x', 'wrong', 'extra' )` — PHP passes surplus arguments to a userland function
	 *   without complaint, so the domain is still second while the last token is not it;
	 * - `__( ...$args )` — nothing about the domain is knowable statically.
	 *
	 * Position plus a named lookup answers the first two exactly; the third is reported as
	 * unverifiable rather than skipped.
	 *
	 * A call passing FEWER positional arguments than this and naming no domain has omitted the
	 * domain entirely — WordPress then falls back to its own `default` domain. That is a real
	 * defect, but a different one, and not what this test asserts.
	 *
	 * `_n_noop()`/`_nx_noop()` are included: a wrong domain there fails at
	 * `translate_nooped_plural()` time rather than at the call site.
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

			// The three shapes a second critic pass broke the position-as-arity version with.
			// Each is legal PHP that reaches WordPress with the wrong domain, and each was
			// silently accepted.
			'named argument, in order'        => [ "__( text: 'x', domain: 'wrong-domain' );", [ 'wrong-domain' ] ],
			'named argument, reversed'        => [ "__( domain: 'wrong-domain', text: 'x' );", [ 'wrong-domain' ] ],
			'named argument, correct domain'  => [ "__( domain: 'woodev-plugin-framework', text: 'x' );", [] ],
			'named context wrapper reversed'  => [ "_x( domain: 'wrong-domain', text: 'x', context: 'c' );", [ 'wrong-domain' ] ],
			'surplus positional argument'     => [ "__( 'text', 'wrong-domain', 'extra' );", [ 'wrong-domain' ] ],
			'spread cannot be verified'       => [
				"__( ...\$args );",
				[ 'a spread argument list, so its domain cannot be verified' ],
			],
			'ternary first argument'          => [ "__( \$flag ? 'a' : 'b', 'wrong-domain' );", [ 'wrong-domain' ] ],
			'first-class callable is not one' => [ "\$fn = __( ... );", [] ],
		];
	}

	/**
	 * Returns `line number => declared domain` for every translation call in $source whose
	 * domain is a string literal other than the framework's, plus every call whose domain
	 * cannot be read statically at all.
	 *
	 * Tokenising rather than pattern-matching, for two reasons: the domain's position varies
	 * by wrapper, so a regex would have to encode every signature; and a regex matches the
	 * function names inside comments and strings, which the token stream does not.
	 *
	 * The domain is located by PARAMETER, not by position in the source text — a named
	 * `domain:` argument wherever it sits, otherwise the positional argument at the wrapper's
	 * recorded position. Reading "the last argument" instead is what let three false greens
	 * through; see {@see self::TRANSLATION_FUNCTIONS}.
	 *
	 * A domain that cannot be read as a plain string literal — a constant, a variable, a
	 * concatenation, or an argument list spread with `...` — is REPORTED as unverifiable
	 * rather than skipped. Skipping it silently would be the same false green in a quieter
	 * costume: the value is free to be anything, and this test would still claim to have
	 * checked it.
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

			if ( null === $call ) {
				continue;
			}

			// `__( ... )` with nothing else is PHP 8.1's first-class callable syntax: it
			// creates a Closure and translates nothing, so it declares no domain and is not
			// this test's business. It is told apart from a real spread by carrying no
			// argument at all — `__( ...$args )` carries one.
			if ( $call['spread'] && [] === $call['arguments'] ) {
				continue;
			}

			// `__( ...$args )` — the argument list is assembled at runtime, so nothing about
			// the domain is knowable here. Report it; do not pretend to have checked it.
			if ( $call['spread'] ) {
				$found[ $token[2] ] = 'a spread argument list, so its domain cannot be verified';
				continue;
			}

			$domain_token = $this->domain_argument( $call, self::TRANSLATION_FUNCTIONS[ $token[1] ] );

			// No domain argument at all: WordPress falls back to its own `default` domain.
			// A real defect, but a different one, and not this test's assertion.
			if ( null === $domain_token ) {
				continue;
			}

			// A domain that is not a plain string literal is free to resolve to anything, so
			// passing over it silently is a false green too. There are none in woodev/ today;
			// the assertion is that there continue to be none, and if one appears somebody
			// decides deliberately how to verify it rather than the guarantee quietly
			// narrowing.
			if ( ! is_array( $domain_token ) || \T_CONSTANT_ENCAPSED_STRING !== $domain_token[0] ) {
				$found[ $token[2] ] = sprintf(
					'%s (not a string literal, so its domain cannot be verified)',
					is_array( $domain_token ) ? $domain_token[1] : (string) $domain_token
				);
				continue;
			}

			$domain = trim( $domain_token[1], "'\"" );
			if ( self::TEXT_DOMAIN !== $domain && ! in_array( $domain, self::BORROWED_DOMAINS, true ) ) {
				$found[ $token[2] ] = $domain;
			}
		}

		return $found;
	}

	/**
	 * Reads the call whose parenthesis opens at $opening into its top-level arguments — or
	 * null when the call is unbalanced.
	 *
	 * Each argument is `{name, value}`: `name` is its parameter name when written as a named
	 * argument (`domain: 'x'`) and null otherwise, and `value` is the argument's FINAL
	 * significant token, which for the literal domains this test cares about is the whole of
	 * it. `spread` reports whether any argument was spread with `...`.
	 *
	 * Arguments are accumulated rather than counted. A PHP trailing comma
	 * (`__( 'text', 'domain', )`, legal since 7.3) otherwise reads as one argument more than
	 * the call passes — the miscount defeated the position check, the caller skipped the call
	 * as "no domain given", and a wrong domain written that way was accepted silently. An
	 * independent critic review found and reproduced it.
	 *
	 * Nesting is tracked for `()`, `[]` and `{}`, so a comma inside an inner call, an inline
	 * array or a closure body does not split an argument. `{` also arrives as the ARRAY
	 * tokens `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` when a double-quoted string
	 * interpolates (`"text {$var}"`) while its `}` arrives as the plain string — counting
	 * only the plain `{` would leave the depth permanently short and put every later comma in
	 * the file at the wrong level.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int                                            $opening
	 * @return array{arguments:array<int, array{name:?string, value:array{0:int,1:string,2:int}|string|null}>, spread:bool}|null
	 */
	private function read_call( array $tokens, int $opening ): ?array {
		$depth     = 0;
		$arguments = [];
		$spread    = false;
		$current   = null;
		$name      = null;
		$is_first  = true;

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
					if ( null !== $current || null !== $name ) {
						$arguments[] = [
							'name'  => $name,
							'value' => $current,
						];
					}

					return [
						'arguments' => $arguments,
						'spread'    => $spread,
					];
				}

				continue;
			}

			if ( 1 !== $depth ) {
				continue;
			}

			if ( ',' === $token ) {
				$arguments[] = [
					'name'  => $name,
					'value' => $current,
				];

				$current  = null;
				$name     = null;
				$is_first = true;
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], [ \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ], true ) ) {
				continue;
			}

			if ( is_array( $token ) && \T_ELLIPSIS === $token[0] ) {
				$spread = true;
				continue;
			}

			// A named argument opens the argument as `name:` — a bare label immediately
			// followed by a colon. The ternary's colon cannot be mistaken for it: there the
			// label is not the argument's FIRST token, and its `?` intervenes.
			if ( $is_first && is_array( $token ) && \T_STRING === $token[0] ) {
				$after = $this->next_significant_index( $tokens, $i );

				if ( null !== $after && ':' === $tokens[ $after ] ) {
					$name     = $token[1];
					$is_first = false;
					$i        = $after;
					continue;
				}
			}

			$is_first = false;
			$current  = $token;
		}

		return null;
	}

	/**
	 * Picks the token carrying the text domain out of a read call — the argument named
	 * `domain` wherever it sits, otherwise the positional argument at $position (1-based).
	 * Returns null when the call passes no domain at all.
	 *
	 * Named arguments are order-independent in PHP 8, and positional ones are counted among
	 * themselves so a named argument elsewhere in the list does not shift them. Surplus
	 * arguments — which PHP passes to a userland function without complaint — leave the
	 * domain exactly where its position says it is, which is why this reads a position rather
	 * than the last argument.
	 *
	 * @param array{arguments:array<int, array{name:?string, value:array{0:int,1:string,2:int}|string|null}>, spread:bool} $call
	 * @param int                                                                                                          $position
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private function domain_argument( array $call, int $position ) {
		$positional = [];

		foreach ( $call['arguments'] as $argument ) {
			if ( self::DOMAIN_PARAMETER === $argument['name'] ) {
				return $argument['value'];
			}

			if ( null === $argument['name'] ) {
				$positional[] = $argument['value'];
			}
		}

		return $positional[ $position - 1 ] ?? null;
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

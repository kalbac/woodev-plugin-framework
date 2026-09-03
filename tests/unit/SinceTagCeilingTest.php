<?php
/**
 * `@since` ceiling test.
 *
 * Asserts no `@since` tag in `woodev/**` names a version GREATER than the planned release
 * ceiling. Guards a silent failure class with real history: `@since` here means the PLANNED
 * release, not `Woodev_Plugin::VERSION` (the RELEASED one) — the two are deliberately different
 * concepts (#409, AGENT-RULES.md Rule 5). A model writing new code naturally reasons "current is
 * 2.0.2, so mine is 2.0.3", which is exactly backwards: 2.0.2 IS the number to write, because it
 * is the release the new member ships in. #116a swept seven members carrying `@since 3.0.0`
 * through `5.2.0` in s111 — numbers inherited from this repo's donor project, above the released
 * version, that made any version comparison conclude the API was unreleased. The sweep fixed the
 * symptom without a gate, and it regressed within one overnight session (#752): two independent
 * workers out of four wrote `@since 2.0.3` again, caught only by human review, which is exactly
 * the enforcement that does not scale.
 *
 * THE CEILING'S SOURCE OF TRUTH is `composer.json` → `extra.woodev.planned-release`, deliberately
 * NOT a constant in `Woodev_Plugin` (that ships to every site for a dev-time lint) and deliberately
 * NOT derived from `Woodev_Plugin::VERSION` (#409 says the two are separate on purpose — a planned
 * release can be a minor bump, not just the next patch). `composer.json` already exists, `extra` is
 * Composer's own slot for arbitrary project metadata, and the file is dev-time only.
 *
 * WHY THE INVARIANT NEEDS NO SPECIAL CASE FOR INHERITED CODE. `1.0.0` marking code present since
 * the initial import (151+ occurrences) is correct and must keep passing (#116a) — and it does,
 * for free: the assertion is a one-directional ceiling (`> planned release` fails), never an
 * equality check, so anything AT OR BELOW the ceiling — `1.0.0`, `1.4.0`, `1.4.1`, `1.5.0`,
 * `1.5.2`, `1.8.0`, `2.0.0`, `2.0.1`, all of it — passes without the test needing to know it
 * exists. Only a version ABOVE the ceiling is ever wrong.
 *
 * WHY A MALFORMED OR MISSING VERSION FAILS THE TEST, rather than being listed as merely
 * unverifiable. Skipping it silently would be the exact false-green shape this test exists to
 * prevent: a scanner that quietly stops looking is indistinguishable, from a green run, to one
 * that never found anything wrong. There are zero malformed tags in `woodev/` today (measured), so
 * this path is only exercised by the scanner's own dataProvider coverage below — a gate whose
 * reporting path has never been seen to fire is not yet proven to work.
 *
 * WHY `version_compare()`, never a string comparison: `'2.0.10' < '2.0.9'` lexically (the digit
 * '1' sorts below '9'), which would silently wave a real ceiling violation through. `version_compare()`
 * parses the numeric segments and gets this right.
 *
 * WHY THE TAG IS MATCHED BY DOCBLOCK FORM, not by any occurrence of the string `@since`: a tag is
 * recognised only at the start of a docblock line — `* @since <version>` (or `/** @since <version>`
 * for a single-line docblock) — via `T_DOC_COMMENT` tokens. The word `@since` appearing mid-prose
 * in a docblock, or inside a `@see` line's description, sits after other text on that line and
 * therefore never matches the line-start anchor — it is data, not a tag. Restricting the scan to
 * `T_DOC_COMMENT` tokens also means a `//` comment or a string literal containing the word `@since`
 * is never mistaken for a tag, the same defence `TextDomainConsistencyTest` uses for gettext calls.
 *
 * WHY THE TRAILING-PROSE FORM IS HANDLED: `@since 2.0.2 Native WC address fields are skipped.` is
 * legal and common. The version is read as the first whitespace-delimited token after `@since`
 * only — the trailing sentence is never part of the captured token, so it cannot corrupt the
 * comparison or the malformed-token check.
 *
 * WHY SCOPE IS `woodev/` ONLY: `tests/` carries 31 files with their own `@since` tags (this file's
 * own new members among them, at `2.0.2` per house convention) that are not framework API and were
 * never part of the #116a/#752 defect.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

/**
 * @coversNothing
 */
final class SinceTagCeilingTest extends TestCase {

	/**
	 * Matches an `@since` tag at the start of a docblock line — after an optional `/**` (a
	 * single-line docblock) or a continuation `*` (a multi-line one) — capturing the first
	 * whitespace-delimited token that follows, if any. A tag mentioned mid-sentence, or inside
	 * another tag's description (e.g. `@see Foo::bar() bumped @since 2.0.3`), never starts a line
	 * this way and is therefore never captured.
	 */
	private const SINCE_TAG_PATTERN = '/^[ \t]*(?:\/\*\*|\*)[ \t]*@since\b[ \t]*(\S*)/';

	/**
	 * A well-formed `@since` version: exactly three dot-separated numeric segments, matching every
	 * value actually in use in `woodev/` today (measured: 1.4.0, 1.4.1, 1.5.0, 1.5.2, 1.8.0,
	 * 2.0.0, 2.0.1, 2.0.2). Anything else — no token, `TBD`, a two-segment `2.0` — is malformed and
	 * reported rather than silently skipped.
	 */
	private const WELL_FORMED_VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

	public function test_no_since_tag_in_the_framework_exceeds_the_planned_release_ceiling(): void {
		$root     = dirname( __DIR__, 2 );
		$ceiling  = $this->planned_release_ceiling();
		$offences = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/woodev', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

			foreach ( $this->scan_since_tags( (string) file_get_contents( $file->getPathname() ), $ceiling ) as $line => $reason ) {
				$offences[] = sprintf( '%s:%d %s', $relative, $line, $reason );
			}
		}

		sort( $offences );

		$this->assertSame(
			[],
			$offences,
			"No @since tag in woodev/** may name a version above the planned release ceiling ({$ceiling}, " .
			"from composer.json's extra.woodev.planned-release). @since is the PLANNED release, not " .
			"Woodev_Plugin::VERSION — see AGENT-RULES.md Rule 5. A tag above the ceiling makes any version " .
			"comparison conclude the member is unreleased (#116a):\n" . implode( "\n", $offences )
		);
	}

	/**
	 * A ceiling below the released version would make this whole gate a no-op: nothing new could
	 * ever be written to exceed it, so `test_no_since_tag_in_the_framework_exceeds_the_planned_release_ceiling()`
	 * would pass on a `composer.json` that nobody had bumped for the current release cycle,
	 * silently. This assertion is the cheap guard against exactly that — it does not scan the
	 * framework at all, it only compares the two numbers.
	 */
	public function test_the_planned_release_ceiling_is_never_below_the_released_version(): void {
		$ceiling = $this->planned_release_ceiling();

		$this->assertTrue(
			version_compare( $ceiling, \Woodev_Plugin::VERSION, '>=' ),
			"composer.json's extra.woodev.planned-release ({$ceiling}) is below the released " .
			'Woodev_Plugin::VERSION (' . \Woodev_Plugin::VERSION . ') — a ceiling below what has ' .
			'already shipped would make the @since ceiling gate useless, silently.'
		);
	}

	/**
	 * @dataProvider provide_scanner_cases
	 *
	 * @param string             $source   PHP source to scan (wrapped in an opening tag by the caller).
	 * @param string             $ceiling  the planned release ceiling to scan against.
	 * @param array<int, string> $expected violation reasons the scanner must report, in order.
	 */
	public function test_the_scanner_reports_exactly_these_violations( string $source, string $ceiling, array $expected ): void {
		$this->assertSame(
			$expected,
			array_values( $this->scan_since_tags( "<?php\n" . $source, $ceiling ) )
		);
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:array<int, string>}>
	 */
	public function provide_scanner_cases(): array {
		return [
			'at the ceiling passes'                        => [
				"/**\n * @since 2.0.2\n */\nfunction f() {}",
				'2.0.2',
				[],
			],
			'inherited 1.0.0 passes even far below the ceiling' => [
				"/**\n * @since 1.0.0\n */\nfunction f() {}",
				'2.0.2',
				[],
			],
			'above the ceiling is reported'                => [
				"/**\n * @since 2.0.3\n */\nfunction f() {}",
				'2.0.2',
				[ 'declares @since 2.0.3, above the planned release ceiling 2.0.2' ],
			],
			'trailing prose does not stop a passing tag from passing' => [
				"/**\n * @since 2.0.2 Native WC address fields are skipped.\n */\nfunction f() {}",
				'2.0.2',
				[],
			],
			'trailing prose does not hide a real violation' => [
				"/**\n * @since 2.0.3 Native WC address fields are skipped.\n */\nfunction f() {}",
				'2.0.2',
				[ 'declares @since 2.0.3, above the planned release ceiling 2.0.2' ],
			],
			'a prose mention of @since is not a tag'        => [
				"/**\n * Mentions @since informally in prose here, not as a tag.\n */\nfunction f() {}",
				'2.0.2',
				[],
			],
			'@since inside a @see description is not a tag' => [
				"/**\n * @see Some_Class::method() bumped @since 2.0.3 there.\n */\nfunction f() {}",
				'2.0.2',
				[],
			],
			'a // comment is not a docblock and is not scanned' => [
				"// @since 2.0.3\nfunction f() {}",
				'2.0.2',
				[],
			],
			'a missing version token is reported'           => [
				"/**\n * @since\n */\nfunction f() {}",
				'2.0.2',
				[ 'has @since with no version token after it' ],
			],
			'a non-numeric version is reported'             => [
				"/**\n * @since TBD\n */\nfunction f() {}",
				'2.0.2',
				[ 'has @since TBD, which is not a well-formed version' ],
			],
			'a two-segment version is reported'             => [
				"/**\n * @since 2.0\n */\nfunction f() {}",
				'2.0.2',
				[ 'has @since 2.0, which is not a well-formed version' ],
			],
			'a single-line docblock is scanned too'         => [
				'/** @since 2.0.3 */' . "\nfunction f() {}",
				'2.0.2',
				[ 'declares @since 2.0.3, above the planned release ceiling 2.0.2' ],
			],
			'string comparison would miss this; version_compare does not' => [
				"/**\n * @since 2.0.10\n */\nfunction f() {}",
				'2.0.9',
				[ 'declares @since 2.0.10, above the planned release ceiling 2.0.9' ],
			],
			'two offending tags in one file are both reported independently' => [
				"/**\n * @since 2.0.3\n */\nfunction a() {}\n\n/**\n * @since 2.0.4\n */\nfunction b() {}",
				'2.0.2',
				[
					'declares @since 2.0.3, above the planned release ceiling 2.0.2',
					'declares @since 2.0.4, above the planned release ceiling 2.0.2',
				],
			],
		];
	}

	/**
	 * Reads the machine-readable ceiling from `composer.json`'s `extra.woodev.planned-release`
	 * (AGENT-RULES.md Rule 5) — the single place this repo decided the planned release lives,
	 * rather than duplicating it as a string literal in this test.
	 */
	private function planned_release_ceiling(): string {
		$path     = dirname( __DIR__, 2 ) . '/composer.json';
		$composer = json_decode( (string) file_get_contents( $path ), true );
		$ceiling  = $composer['extra']['woodev']['planned-release'] ?? null;

		if ( ! is_string( $ceiling ) || '' === $ceiling ) {
			$this->fail( "composer.json is missing extra.woodev.planned-release — the machine-readable @since ceiling this gate depends on (AGENT-RULES.md Rule 5)." );
		}

		return $ceiling;
	}

	/**
	 * Walks $source's `T_DOC_COMMENT` tokens and returns `line number => violation reason` for
	 * every `@since` tag whose version is missing, malformed, or exceeds $ceiling. A tag at or
	 * below the ceiling is not returned at all — this is a violations list, not a full inventory,
	 * matching the production assertion's `assertSame( [], $offences )` shape.
	 *
	 * Tokenising rather than pattern-matching the raw source, for the same reason
	 * {@see TextDomainConsistencyTest::scan()} does: a regex over raw text would also match inside
	 * `//` comments and string literals, and restricting to `T_DOC_COMMENT` tokens rules that out
	 * for free.
	 *
	 * @param string $source
	 * @param string $ceiling
	 * @return array<int, string>
	 */
	private function scan_since_tags( string $source, string $ceiling ): array {
		$violations = [];

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || \T_DOC_COMMENT !== $token[0] ) {
				continue;
			}

			$line = $token[2];

			foreach ( explode( "\n", $token[1] ) as $text ) {
				if ( 1 === preg_match( self::SINCE_TAG_PATTERN, $text, $matches ) ) {
					$version = $matches[1];

					if ( '' === $version ) {
						$violations[ $line ] = 'has @since with no version token after it';
					} elseif ( 1 !== preg_match( self::WELL_FORMED_VERSION_PATTERN, $version ) ) {
						$violations[ $line ] = sprintf( 'has @since %s, which is not a well-formed version', $version );
					} elseif ( version_compare( $version, $ceiling, '>' ) ) {
						$violations[ $line ] = sprintf( 'declares @since %s, above the planned release ceiling %s', $version, $ceiling );
					}
				}

				++$line;
			}
		}

		return $violations;
	}
}

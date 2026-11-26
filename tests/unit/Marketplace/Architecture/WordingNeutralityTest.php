<?php
/**
 * The merchant's words are the plugin's: the framework names no marketplace and calls a posting by the marketplace's noun.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Reads the strings of every PHP file of `woodev/marketplace/` with the tokenizer — each translatable msgid is one of them — so a
 * sentence that would say another marketplace's name, or «отправление» to a marketplace with its own word, fails here. Comments may
 * use a marketplace as an example.
 */
final class WordingNeutralityTest extends TestCase {
	private const STRINGS = [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ];

	/**
	 * No string names a marketplace.
	 *
	 * @return void
	 */
	public function test_no_string_of_the_marketplace_module_names_a_marketplace(): void {
		$this->assertSame( [], $this->strings_matching( '/ozon|wildberries|yandex|aliexpress|sbermegamarket|озон|вайлдберриз|яндекс\s*маркет|мегамаркет|алиэкспресс/iu' ) );
	}

	/**
	 * Only the default noun spells «отправление»: every other sentence takes the word from {@see Posting_Noun}.
	 *
	 * @return void
	 */
	public function test_only_the_default_noun_spells_the_word_for_a_posting(): void {
		$offences = array_filter(
			$this->strings_matching( '/отправлени/iu' ),
			static function ( string $offence ): bool {
				return 0 !== strpos( $offence, 'settings/class-posting-noun.php:' );
			}
		);

		$this->assertSame( [], array_values( $offences ), 'Take the word from the Posting_Noun of the marketplace.' );
	}

	/**
	 * @param string $pattern A regular expression.
	 * @return string[] `file:line string` of every string that matches.
	 */
	private function strings_matching( string $pattern ): array {
		$root    = dirname( __DIR__, 4 ) . '/woodev/marketplace/';
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$found   = [];
		$scanned = 0;

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			++$scanned;

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], self::STRINGS, true ) && 1 === preg_match( $pattern, $token[1] ) ) {
					$found[] = str_replace( $root, '', $file->getPathname() ) . ':' . $token[2] . ' ' . trim( $token[1] );
				}
			}
		}

		$this->assertGreaterThan( 100, $scanned, 'The module was not read.' );

		sort( $found );

		return $found;
	}
}

<?php
namespace Woodev\Tests\Unit\Help;

use Brain\Monkey\Filters;
use Woodev\Framework\Help\Help_Links;
use Woodev\Framework\Help\Help_Links_Checker;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Help\Help_Links
 * @covers \Woodev\Framework\Help\Help_Links_Checker
 */
class HelpLinksTest extends TestCase {

	private const TABLE = [
		'import'          => 'https://woodev.ru/docs/import/#how',
		'import-problems' => 'https://woodev.ru/docs/import/#chastye-problemy',
	];

	public function test_a_topic_string_resolves_to_the_table_url_with_the_default_text(): void {
		$this->assertSame(
			[
				'url'   => 'https://woodev.ru/docs/import/#how',
				'text'  => 'Подробнее как это работает',
				'label' => 'Подробнее как это работает (откроется в новой вкладке)',
			],
			Help_Links::resolve( 'import', self::TABLE )
		);
	}

	public function test_a_declaration_picks_one_of_the_fixed_link_texts(): void {
		$this->assertSame(
			'Подробнее как настроить',
			Help_Links::resolve( [ 'topic' => 'import', 'text' => Help_Links::TEXT_HOW_TO_SET_UP ], self::TABLE )['text']
		);
		$this->assertSame(
			'Подробнее для чего это нужно',
			Help_Links::resolve( [ 'topic' => 'import', 'text' => Help_Links::TEXT_WHY_NEEDED ], self::TABLE )['text']
		);
	}

	public function test_an_unknown_text_variant_falls_back_to_the_default_instead_of_hiding_the_link(): void {
		$this->assertSame(
			'Подробнее как это работает',
			Help_Links::resolve( [ 'topic' => 'import', 'text' => 'free text' ], self::TABLE )['text']
		);
	}

	public function test_an_unknown_topic_resolves_to_nothing(): void {
		$this->assertNull( Help_Links::resolve( 'no-such-topic', self::TABLE ) );
	}

	public function test_an_empty_or_missing_declaration_resolves_to_nothing(): void {
		foreach ( [ '', '  ', [], [ 'topic' => '' ], null, 5 ] as $declaration ) {
			$this->assertNull( Help_Links::resolve( $declaration, self::TABLE ) );
		}
	}

	public function test_missing_topics_lists_each_unknown_topic_once(): void {
		$this->assertSame(
			[ 'typo', 'other' ],
			Help_Links::missing_topics( [ 'import', 'typo', [ 'topic' => 'typo' ], [ 'topic' => 'other' ], '' ], self::TABLE )
		);
	}

	public function test_the_plugin_table_keeps_only_absolute_http_urls_under_named_topics(): void {
		Filters\expectApplied( 'woodev_help_topics' )->once()->andReturnUsing( static fn( array $topics ): array => $topics );

		$plugin = new class() {
			public function get_id(): string {
				return 'demo';
			}

			public function get_help_topics(): array {
				return [
					'good'      => 'https://woodev.ru/docs/a/#b',
					'plain'     => 'http://woodev.ru/docs/a/',
					'relative'  => '/docs/a/',
					'script'    => 'javascript:alert(1)',
					'not-a-url' => '',
					7           => 'https://woodev.ru/docs/seven/',
				];
			}
		};

		$this->assertSame(
			[
				'good'  => 'https://woodev.ru/docs/a/#b',
				'plain' => 'http://woodev.ru/docs/a/',
			],
			Help_Links::topics_of( $plugin )
		);
	}

	public function test_a_plugin_without_a_table_and_a_service_have_no_topics(): void {
		$this->assertSame( [], Help_Links::topics_of( null ) );
		$this->assertSame( [], Help_Links::topics_of( new \stdClass() ) );
	}

	public function test_the_checker_reports_every_address_that_is_not_2xx_or_3xx_without_the_network(): void {
		$calls   = [];
		$checker = new Help_Links_Checker(
			static function ( string $url ) use ( &$calls ): int {
				$calls[] = $url;

				return [
					'https://woodev.ru/docs/ok/'       => 200,
					'https://woodev.ru/docs/moved/'    => 301,
					'https://woodev.ru/docs/gone/'     => 404,
					'https://woodev.ru/docs/down/'     => 0,
					'https://woodev.ru/docs/boom/'     => 500,
				][ $url ];
			}
		);

		$broken = $checker->find_broken(
			[
				'ok'    => 'https://woodev.ru/docs/ok/',
				'moved' => 'https://woodev.ru/docs/moved/',
				'gone'  => 'https://woodev.ru/docs/gone/',
				'twin'  => 'https://woodev.ru/docs/gone/',
				'down'  => 'https://woodev.ru/docs/down/',
				'boom'  => 'https://woodev.ru/docs/boom/',
			]
		);

		$this->assertSame(
			[
				'gone' => 404,
				'twin' => 404,
				'down' => 0,
				'boom' => 500,
			],
			$broken
		);
		$this->assertCount( 5, $calls, 'an address shared by two topics is requested once' );
	}

	public function test_the_checker_passes_an_empty_table(): void {
		$this->assertSame( [], ( new Help_Links_Checker( static fn(): int => 404 ) )->find_broken( [] ) );
	}
}

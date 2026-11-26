<?php
/**
 * A note a plugin keeps about a link, and what it does to a state badge of a table.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Shared
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Note;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Note_Badge;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Link_Note
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Link_Note_Badge
 */
final class LinkNoteTest extends TestCase {
	public function test_a_note_is_a_warning_unless_it_says_otherwise(): void {
		$note = new Link_Note( '  Добавьте документы.  ' );

		$this->assertSame( 'Добавьте документы.', $note->text() );
		$this->assertSame( Link_Note::TONE_WARNING, $note->tone() );
		$this->assertTrue( $note->needs_attention() );
		$this->assertTrue( ( new Link_Note( 'Отклонена', Link_Note::TONE_ERROR ) )->needs_attention() );
		$this->assertFalse( ( new Link_Note( 'Проверяется', Link_Note::TONE_INFO ) )->needs_attention() );
	}

	public function test_a_note_without_a_text_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Link_Note( "  \n " );
	}

	public function test_an_unknown_tone_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Link_Note( 'Текст', 'success' );
	}

	public function test_no_note_leaves_the_badge_alone(): void {
		$badge = [
			'label' => 'Создан',
			'tone'  => 'success',
		];

		$this->assertSame( $badge, Link_Note_Badge::add( $badge, [] ) );
	}

	public function test_the_notes_open_the_tooltip_and_the_same_sentence_is_said_once(): void {
		$badge = Link_Note_Badge::add(
			[
				'label'   => 'Создан',
				'tone'    => 'success',
				'tooltip' => 'Синхронизируются: цены',
			],
			[ new Link_Note( 'Добавьте документы.' ), new Link_Note( 'Добавьте документы.' ) ]
		);

		$this->assertSame( "Добавьте документы.\nСинхронизируются: цены", $badge['tooltip'] );
		$this->assertSame( 'Создан', $badge['label'], 'the label stays the state of the card' );
	}

	/**
	 * @return array<string,array{0:string,1:string[],2:string}>
	 */
	public static function tones(): array {
		return [
			'info keeps the colour'          => [ 'success', [ Link_Note::TONE_INFO ], 'success' ],
			'a warning takes it over'        => [ 'success', [ Link_Note::TONE_WARNING ], 'warning' ],
			'an error takes it over'         => [ 'success', [ Link_Note::TONE_ERROR ], 'danger' ],
			'an error badge stays red'       => [ 'danger', [ Link_Note::TONE_WARNING ], 'danger' ],
			'the worst of several notes wins' => [ 'info', [ Link_Note::TONE_ERROR, Link_Note::TONE_WARNING, Link_Note::TONE_INFO ], 'danger' ],
		];
	}

	/**
	 * @dataProvider tones
	 *
	 * @param string   $tone     Tone of the badge.
	 * @param string[] $notes    Tones of the notes.
	 * @param string   $expected Tone of the result.
	 * @return void
	 */
	public function test_the_more_serious_tone_wins( string $tone, array $notes, string $expected ): void {
		$badge = Link_Note_Badge::add(
			[
				'label' => 'Создан',
				'tone'  => $tone,
			],
			array_map(
				static function ( string $one ): Link_Note {
					return new Link_Note( 'Текст', $one );
				},
				$notes
			)
		);

		$this->assertSame( $expected, $badge['tone'] );
	}

	public function test_a_long_note_is_clipped_to_what_a_tooltip_holds(): void {
		$badge = Link_Note_Badge::add(
			[
				'label' => 'Создан',
				'tone'  => 'success',
			],
			[ new Link_Note( str_repeat( 'а', 1000 ) ) ]
		);

		$this->assertSame( Row_Normalizer::MAX_TOOLTIP_LENGTH, mb_strlen( $badge['tooltip'] ) );
	}
}

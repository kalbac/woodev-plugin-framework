<?php
/**
 * Labels of several files and labels answered at once.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Labels;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Label_Bundle;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Flows\Label_File;
use Woodev\Framework\Marketplace\Flows\Label_Provider;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Flows\Label_Sheet;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Formatted_Task_Label_Provider;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Immediate_Formatted_Label_Provider;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Immediate_Label_Provider;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;

/**
 * Several files of one answer become one document: PNG stickers a page to print, ZPL one printer file, anything else an archive
 * of the files under their names; a single file keeps its name. A provider that answers at once leaves no task and no poll.
 */
final class LabelFilesTest extends Label_Test_Case {
	private const PNG_A = "\x89PNG\r\n\x1a\nsticker-a";
	private const PNG_B = "\x89PNG\r\n\x1a\nsticker-b";

	/**
	 * Stubs the file helpers of WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_attr' )->alias(
			static function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES );
			}
		);
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );
			}
		);
		Functions\when( 'wp_tempnam' )->alias(
			static function (): string {
				return (string) tempnam( sys_get_temp_dir(), 'labels' );
			}
		);
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $path ): void {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A test double.
			}
		);
	}

	public function test_files_keeps_label_files_only_and_an_empty_list_is_a_refusal(): void {
		$file   = new Label_File( 'a.png', 'image/png', self::PNG_A );
		$answer = Label_Answer::files( [ 'not a file', $file ] );

		$this->assertSame( Label_Answer::FILES, $answer->kind() );
		$this->assertSame( [ $file ], $answer->file_list() );
		$this->assertSame( Label_Answer::BROKEN, Label_Answer::files( [] )->kind() );
	}

	public function test_a_file_name_has_no_path_and_is_never_empty(): void {
		$this->assertSame( '..-..-x.zpl', ( new Label_File( '../../x.zpl', 'application/zpl', '' ) )->name() );
		$this->assertSame( 'label', ( new Label_File( '  ', 'image/png', '' ) )->name() );
		$this->assertSame( 'image/png', ( new Label_File( 'a.png', ' Image/PNG ', '' ) )->content_type() );
	}

	public function test_a_set_of_png_stickers_is_stored_as_a_page_and_reads_back_byte_for_byte(): void {
		$stored = new Label_Bundle(
			[
				new Label_File( 'a.png', 'image/png', self::PNG_A, 58, 40 ),
				new Label_File( 'b.png', 'image/png', self::PNG_B, 58, 40 ),
			]
		);

		$this->assertSame( Label_Bundle::SHEET_TYPE, $stored->content_type() );

		$read = Label_Bundle::from_body( $stored->encode() );

		$this->assertNotNull( $read );
		$this->assertSame( [ self::PNG_A, self::PNG_B ], array_map( static fn( Label_File $f ) => $f->body(), $read->files() ) );
		$this->assertSame( [ 58, 40 ], [ $read->files()[1]->width_mm(), $read->files()[1]->height_mm() ] );
		$this->assertNull( Label_Bundle::from_body( 'not json' ) );
	}

	public function test_a_set_with_any_other_file_is_stored_as_files(): void {
		$bundle = new Label_Bundle( [ new Label_File( 'a.png', 'image/png', self::PNG_A ), new Label_File( 'b.svg', 'image/svg+xml', '<svg/>' ) ] );

		$this->assertSame( Label_Bundle::FILES_TYPE, $bundle->content_type() );
	}

	public function test_one_file_is_downloaded_under_its_own_name(): void {
		$file = new Label_File( 'sticker-319267-6684.zpl', 'application/zpl', '^XA^XZ' );

		$this->assertSame( $file, ( new Label_Bundle( [ $file ] ) )->download( 'fake-labels-1' ) );
	}

	public function test_several_zpl_files_are_one_printer_file_label_after_label(): void {
		$download = ( new Label_Bundle(
			[
				new Label_File( 'a.zpl', 'application/zpl', "^XA^FD1^FS^XZ\n" ),
				new Label_File( 'b.zpl', 'application/zpl', '^XA^FD2^FS^XZ' ),
			]
		) )->download( 'fake-labels-1' );

		$this->assertSame( 'fake-labels-1.zpl', $download->name() );
		$this->assertSame( "^XA^FD1^FS^XZ\n^XA^FD2^FS^XZ\n", $download->body() );
	}

	public function test_several_svg_files_are_an_archive_that_keeps_their_names(): void {
		$download = ( new Label_Bundle(
			[
				new Label_File( 'sticker-1.svg', 'image/svg+xml', '<svg>1</svg>' ),
				new Label_File( 'sticker-2.svg', 'image/svg+xml', '<svg>2</svg>' ),
				new Label_File( 'sticker-1.svg', 'image/svg+xml', '<svg>3</svg>' ),
			]
		) )->download( 'fake-labels-1' );

		$this->assertSame( 'fake-labels-1.zip', $download->name() );
		$this->assertSame( 'application/zip', $download->content_type() );

		$path = (string) tempnam( sys_get_temp_dir(), 'zip' );
		file_put_contents( $path, $download->body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.

		$zip = new \ZipArchive();
		$zip->open( $path );
		$contents = [];

		for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name              = (string) $zip->getNameIndex( $index );
			$contents[ $name ] = $zip->getFromIndex( $index );
		}

		$zip->close();
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame(
			[
				'sticker-1.svg'   => '<svg>1</svg>',
				'sticker-2.svg'   => '<svg>2</svg>',
				'sticker-1-2.svg' => '<svg>3</svg>',
			],
			$contents
		);
	}

	/**
	 * @dataProvider unusable_temporary_files
	 *
	 * @param mixed $path What `wp_tempnam()` gives back.
	 * @return void
	 */
	public function test_an_archive_without_a_temporary_file_asks_for_the_hosting( $path ): void {
		Functions\when( 'wp_tempnam' )->justReturn( $path );

		$bundle = new Label_Bundle( [ new Label_File( 'a.svg', 'image/svg+xml', '<svg/>' ), new Label_File( 'b.svg', 'image/svg+xml', '<svg/>' ) ] );

		$this->expectException( Label_Exception::class );
		$this->expectExceptionMessage( 'Попросите хостинг включить расширение PHP «zip» и проверить папку временных файлов.' );

		$bundle->download( 'fake-labels-1' );
	}

	/** @return array<string,array{0:mixed}> */
	public function unusable_temporary_files(): array {
		return [
			'none was made'           => [ false ],
			'an empty path'           => [ '' ],
			'a path nobody can write' => [ '/nonexistent/woodev-labels.tmp' ],
		];
	}

	public function test_the_page_tells_a_label_printer_which_print_settings_to_choose(): void {
		$this->assertStringContainsString( 'поля «Нет» и масштаб 100 %', Label_Sheet::html( [ new Label_File( 'a.png', 'image/png', self::PNG_A ) ] ) );
	}

	public function test_the_page_shows_every_sticker_at_its_size_and_escapes_the_names(): void {
		$html = Label_Sheet::html(
			[
				new Label_File( 'a".png', 'image/png', self::PNG_A, 58, 40 ),
				new Label_File( 'b.png', 'image/png', self::PNG_B ),
			]
		);

		$this->assertStringContainsString( 'src="data:image/png;base64,' . base64_encode( self::PNG_A ) . '"', $html ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringContainsString( 'style="width:58mm;height:40mm"', $html );
		$this->assertSame( 2, substr_count( $html, '<div class="label">' ) );
		$this->assertSame( 1, substr_count( $html, 'style="width' ), 'A sticker that says no size is printed at its own.' );
		$this->assertStringNotContainsString( 'a".png', $html );
		$this->assertStringContainsString( 'window.print()', $html );
	}

	public function test_a_polled_answer_with_several_files_becomes_one_document(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Label_Answer::files( [ new Label_File( 'a.png', 'image/png', self::PNG_A ), new Label_File( 'b.png', 'image/png', self::PNG_B ) ] );

		$this->assertSame( Label_Service::COLLECTED, $this->service()->collect( $this->account(), $task_id ) );

		$document = $this->labels->document( (int) $this->labels->document_of_task( $task_id ) );

		$this->assertSame( Label_Bundle::SHEET_TYPE, $document['content_type'] );
		$this->assertCount( 2, Label_Bundle::from_body( $document['body'] )->files() );
	}

	public function test_labels_answered_at_once_are_stored_with_no_task_and_no_poll(): void {
		$provider = new Fake_Immediate_Label_Provider( Label_Answer::files( [ new Label_File( 'a.png', 'image/png', self::PNG_A ) ] ) );
		$service  = $this->immediate_service( $provider );
		$jobs     = new Label_Jobs( $this->job_gate(), $service, $this->labels, $this->adapter->error_classifier(), $this->names() );

		Actions\expectDone( 'woodev_fake_seller_label_ready' )->once()->with( 1, 0, $this->account_id );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->assertSame( 0, $jobs->request( $this->account(), [ ' 0001-1 ', '0001-1' ] ) );
		$this->assertSame( [ [ 'labels_now', [ '0001-1' ] ] ], $provider->calls );

		$document = $this->labels->document( 1 );

		$this->assertSame( Label_Bundle::SHEET_TYPE, $document['content_type'] );
		$this->assertSame( [ '0001-1' ], $document['external_keys'] );
		$this->assertSame( 0, $document['task_id'] );
		$this->assertNull( $this->labels->task( 1 ) );
	}

	/**
	 * @dataProvider answers_without_labels
	 *
	 * @param Label_Answer $answer  Answer.
	 * @param string       $message What the merchant reads.
	 * @return void
	 */
	public function test_an_immediate_answer_without_labels_is_a_refusal_and_stores_nothing( Label_Answer $answer, string $message ): void {
		$service = $this->immediate_service( new Fake_Immediate_Label_Provider( $answer ) );

		try {
			$service->create( $this->account(), [ '0001-1' ] );
			$this->fail( 'The request must be refused.' );
		} catch ( Label_Exception $e ) {
			$this->assertSame( $message, $e->getMessage() );
		}

		$this->assertNull( $this->labels->document( 1 ) );
	}

	/** @return array<string,array{0:Label_Answer,1:string}> */
	public function answers_without_labels(): array {
		return [
			'refused with a sentence' => [ Label_Answer::broken( 'Нужен номер декларации.' ), 'Нужен номер декларации.' ],
			'no files'                => [ Label_Answer::files( [] ), 'Маркетплейс не смог подготовить этикетки.' ],
			'still pending'           => [ Label_Answer::pending(), 'Маркетплейс не смог подготовить этикетки.' ],
		];
	}

	public function test_a_lapsed_licence_stops_an_immediate_request_before_the_marketplace(): void {
		$provider       = new Fake_Immediate_Label_Provider( Label_Answer::files( [ new Label_File( 'a.png', 'image/png', self::PNG_A ) ] ) );
		$this->licensed = false;

		try {
			$this->immediate_service( $provider )->create( $this->account(), [ '0001-1' ] );
			$this->fail( 'The request must be refused.' );
		} catch ( Label_Exception $e ) {
			$this->assertSame( [], $provider->calls );
		}
	}

	public function test_a_page_made_in_another_format_is_not_handed_over_again(): void {
		$provider = new Fake_Immediate_Formatted_Label_Provider( Label_Answer::files( [ new Label_File( 'a.png', 'image/png', self::PNG_A ) ] ) );
		$service  = $this->immediate_service( $provider );
		$stored   = $this->stored_labels( $provider );

		$service->create( $this->account(), [ '0001-1' ] );

		$first = $stored->covering( $this->account_id, [ '0001-1' ] );

		$this->assertSame( 1, $first );
		$this->assertTrue( $stored->is_page( $first ) );

		$provider->format = 'png-40x30';

		$this->assertSame( 0, $stored->covering( $this->account_id, [ '0001-1' ] ), 'Another size: the old page does not count.' );
		$this->assertSame( 0, $stored->states( $this->account_id, [ '0001-1' ] )['0001-1']['document_id'] );

		$service->create( $this->account(), [ '0001-1' ] );

		$this->assertSame( 2, $stored->covering( $this->account_id, [ '0001-1' ] ) );

		$provider->format = 'png-58x40';

		$this->assertSame( 1, $stored->covering( $this->account_id, [ '0001-1' ] ), 'Back to the first size: its page is the right one again.' );

		$page = $this->download()->response( $this->labels->document( 1 ) );

		$this->assertSame( 'text/html; charset=utf-8', $page['content_type'] );
	}

	public function test_a_polled_document_keeps_the_format_it_was_asked_in(): void {
		$provider = new Fake_Formatted_Task_Label_Provider( Label_Answer::file( self::PDF, 'application/pdf' ) );
		$service  = new Label_Service( $provider, $this->labels, $this->license(), $this->adapter->error_classifier(), $this->names() );
		$task_id  = $service->create( $this->account(), [ '0001-1' ] );

		$provider->format = 'png-40x30'; // Changed while the marketplace was making the label.

		$this->assertSame( Label_Service::COLLECTED, $service->collect( $this->account(), $task_id ) );
		$this->assertSame( 0, $this->stored_labels( $provider )->covering( $this->account_id, [ '0001-1' ] ) );

		$provider->format = 'png-58x40';

		$document_id = $this->stored_labels( $provider )->covering( $this->account_id, [ '0001-1' ] );

		$this->assertSame( (int) $this->labels->document_of_task( $task_id ), $document_id );
		$this->assertSame( 'application/pdf', $this->download()->response( $this->labels->document( $document_id ) )['content_type'], 'The format is never sent to the browser.' );
	}

	public function test_a_marketplace_with_one_format_stores_the_plain_content_type_and_asks_no_account(): void {
		$this->assertNull(
			Label_Service::format_lookup(
				$this->provider,
				function (): void {
					$this->fail( 'No account is read.' );
				}
			)
		);

		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();
		$this->service()->collect( $this->account(), $task_id );

		$this->assertSame( 'application/pdf', $this->labels->document( 1 )['content_type'] );
	}

	public function test_a_stored_pdf_is_answered_as_before(): void {
		$this->assertSame(
			[
				'content_type' => 'application/pdf',
				'disposition'  => 'inline',
				'filename'     => 'fake-labels-5.pdf',
				'body'         => self::PDF,
			],
			$this->download()->response( [ 'document_id' => 5, 'content_type' => 'application/pdf', 'body' => self::PDF ] )
		);
	}

	public function test_a_stored_page_is_shown_and_a_stored_file_is_saved_under_its_name(): void {
		$sheet = new Label_Bundle( [ new Label_File( 'a.png', 'image/png', self::PNG_A ) ] );
		$page  = $this->download()->response( [ 'document_id' => 5, 'content_type' => $sheet->content_type(), 'body' => $sheet->encode() ] );

		$this->assertSame( [ 'text/html; charset=utf-8', 'inline' ], [ $page['content_type'], $page['disposition'] ] );
		$this->assertStringContainsString( '<div class="label">', $page['body'] );

		$svg   = new Label_Bundle( [ new Label_File( 'sticker 7.svg', 'image/svg+xml', '<svg/>' ) ] );
		$saved = $this->download()->response( [ 'document_id' => 6, 'content_type' => $svg->content_type(), 'body' => $svg->encode() ] );

		$this->assertSame(
			[
				'content_type' => 'image/svg+xml',
				'disposition'  => 'attachment',
				'filename'     => 'sticker7.svg',
				'body'         => '<svg/>',
			],
			$saved
		);
	}

	public function test_a_stored_set_that_cannot_be_read_says_so(): void {
		$this->expectException( Label_Exception::class );

		$this->download()->response( [ 'document_id' => 5, 'content_type' => Label_Bundle::FILES_TYPE, 'body' => '[]' ] );
	}

	/**
	 * @param Label_Provider $provider Provider.
	 * @return Site_Orders_Labels The reader of stored documents, as the screens build it.
	 */
	private function stored_labels( Label_Provider $provider ): Site_Orders_Labels {
		return new Site_Orders_Labels(
			$this->db,
			Sqlite_Tables::table( 'tasks' ),
			Sqlite_Tables::table( 'documents' ),
			'__return_empty_string',
			'__return_zero',
			$this->clock(),
			Label_Service::format_lookup(
				$provider,
				function (): Account {
					return $this->account();
				}
			)
		);
	}

	/**
	 * @param Label_Provider $provider Provider.
	 * @return Label_Service
	 */
	private function immediate_service( Label_Provider $provider ): Label_Service {
		return new Label_Service( $provider, $this->labels, $this->license(), $this->adapter->error_classifier(), $this->names() );
	}

	/**
	 * @return Label_Download A handler for building responses; its checks are `LabelDownloadTest`'s subject.
	 */
	private function download(): Label_Download {
		return new Label_Download( $this->labels, $this->accounts(), '__return_null', new Fake_Order_Meta(), $this->license(), $this->names(), 'fake' );
	}
}

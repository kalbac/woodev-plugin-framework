<?php
/**
 * The grouped bulk report and the pieces it relies on.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Source_Resolver;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;

/**
 * Spec 5.1.1: «12 товаров: нет веса; 3 товара: не сопоставлена категория».
 */
final class BulkValidationReportTest extends TestCase {
	/**
	 * Closes Mockery.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * Builds a report with one error.
	 *
	 * @param string $code    Check code.
	 * @param string $summary Group label.
	 * @return Validation_Report
	 */
	private function failing( string $code, string $summary ): Validation_Report {
		return ( new Validation_Report() )->with_issue( Validation_Report::ERROR, $code, 'Подробный текст', null, Validation_Report::FIX_PRODUCT, $summary );
	}

	/**
	 * The summary line is the spec's example, largest group first.
	 *
	 * @return void
	 */
	public function test_groups_blocked_products_by_their_first_error(): void {
		$bulk = new Bulk_Validation_Report();

		for ( $id = 1; $id <= 3; $id++ ) {
			$bulk->add( $id, $this->failing( 'category_not_mapped', 'не сопоставлена категория' ) );
		}

		for ( $id = 4; $id <= 15; $id++ ) {
			$bulk->add( $id, $this->failing( 'weight_missing', 'нет веса' ) );
		}

		$this->assertSame( '12 товаров: нет веса; 3 товара: не сопоставлена категория', $bulk->summary() );
		$this->assertSame( 12, $bulk->groups()[0]['count'] );
		$this->assertSame( 'weight_missing', $bulk->groups()[0]['code'] );
		$this->assertSame( range( 4, 15 ), $bulk->groups()[0]['product_ids'] );
		$this->assertSame( Validation_Report::FIX_PRODUCT, $bulk->groups()[0]['fix'] );
	}

	/**
	 * Only the FIRST error places a product in a group; a later error does not add it to a second one.
	 *
	 * @return void
	 */
	public function test_a_product_is_counted_once_under_its_first_error(): void {
		$bulk   = new Bulk_Validation_Report();
		$report = $this->failing( 'weight_missing', 'нет веса' )->with_issue( Validation_Report::ERROR, 'image_missing', 'Нет фото', null, null, 'нет фото' );
		$bulk->add( 7, $report );

		$this->assertCount( 1, $bulk->groups() );
		$this->assertSame( 'нет веса', $bulk->groups()[0]['summary'] );
		$this->assertSame( $report, $bulk->report_for( 7 ), 'the detail view keeps the whole report' );
		$this->assertNull( $bulk->report_for( 8 ) );
	}

	/**
	 * Valid products go to the queue, blocked ones stay out, warnings do not block.
	 *
	 * @return void
	 */
	public function test_split_into_queue_blocked_and_warned(): void {
		$bulk = new Bulk_Validation_Report();
		$bulk->add( 1, new Validation_Report() );
		$bulk->add( 2, $this->failing( 'weight_missing', 'нет веса' ) );
		$bulk->add( 3, ( new Validation_Report() )->with_issue( Validation_Report::WARNING, 'few_images', 'Мало фото' ) );

		$this->assertSame( array( 1, 3 ), $bulk->valid_ids() );
		$this->assertSame( array( 2 ), $bulk->blocked_ids() );
		$this->assertSame( array( 3 ), $bulk->warned_ids() );
		$this->assertSame( '1 товар: нет веса', $bulk->summary() );
		$this->assertSame( '', ( new Bulk_Validation_Report() )->summary() );
	}

	/**
	 * Russian plural of «товар».
	 *
	 * @dataProvider plural_cases
	 * @param int    $count    Number.
	 * @param string $expected Word form.
	 * @return void
	 */
	public function test_russian_plural( int $count, string $expected ): void {
		$this->assertSame( $expected, Bulk_Validation_Report::products( $count ) );
	}

	/**
	 * Plural cases.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public function plural_cases(): array {
		return array(
			'1'   => array( 1, 'товар' ),
			'2'   => array( 2, 'товара' ),
			'4'   => array( 4, 'товара' ),
			'5'   => array( 5, 'товаров' ),
			'11'  => array( 11, 'товаров' ),
			'12'  => array( 12, 'товаров' ),
			'21'  => array( 21, 'товар' ),
			'22'  => array( 22, 'товара' ),
			'100' => array( 100, 'товаров' ),
			'111' => array( 111, 'товаров' ),
			'101' => array( 101, 'товар' ),
		);
	}

	/**
	 * A source gives its value by kind, trims, drops empties and falls back to the stored default.
	 *
	 * @return void
	 */
	public function test_source_resolver_reads_every_kind(): void {
		$product = \Mockery::mock( 'WC_Product' )->shouldIgnoreMissing();
		$product->shouldReceive( 'get_attributes' )->andReturn(
			array(
				'pa_color' => new Fake_Attribute( array( 'Красный', 'Синий, тёмный' ), true ),
				'Размер'   => new Fake_Attribute( array( 'S', 'M' ), false ),
			)
		);
		$product->shouldReceive( 'get_attribute' )->with( 'pa_size' )->andReturn( '' );
		$product->shouldReceive( 'get_meta' )->with( 'brand' )->andReturn( 'Nike' );
		$product->shouldReceive( 'get_meta' )->with( 'empty' )->andReturn( '' );
		$product->shouldReceive( 'get_sku' )->andReturn( 'A-1' );

		$this->assertSame( array( 'Nike' ), Source_Resolver::values( new Source( Source::FIXED, 'Nike' ), $product ) );
		$this->assertSame( array(), Source_Resolver::values( new Source( Source::FIXED, '  ' ), $product ) );
		$this->assertSame( array( 'Красный', 'Синий, тёмный' ), Source_Resolver::values( new Source( Source::WC_ATTRIBUTE, '', 'pa_color' ), $product ), 'a term name with a comma stays one value' );
		$this->assertSame( array( 'S', 'M' ), Source_Resolver::values( new Source( Source::WC_ATTRIBUTE, '', 'Размер' ), $product ), 'custom attribute options, not the «S | M» display string' );
		$this->assertSame( array( 'M' ), Source_Resolver::values( new Source( Source::WC_ATTRIBUTE, 'M', 'pa_size' ), $product ), 'the default when the product has none' );
		$this->assertSame( array( 'Nike' ), Source_Resolver::values( new Source( Source::META, '', 'brand' ), $product ) );
		$this->assertSame( array(), Source_Resolver::values( new Source( Source::META, '', 'empty' ), $product ) );
		$this->assertSame( array( 'A-1' ), Source_Resolver::values( new Source( Source::FIELD, '', 'sku' ), $product ) );
		$this->assertSame( array( 'x' ), Source_Resolver::values( new Source( Source::FIELD, 'x', 'unknown_field' ), $product ), 'an unknown built-in field falls back' );
		$this->assertTrue( Source_Resolver::has_value( new Source( Source::FIXED, '', '', '12' ), $product ), 'a chosen dictionary id is a value' );
		$this->assertFalse( Source_Resolver::has_value( new Source( Source::WC_ATTRIBUTE, '', 'pa_size' ), $product ) );
	}
}

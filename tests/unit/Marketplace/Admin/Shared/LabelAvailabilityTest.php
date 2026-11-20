<?php
/**
 * Unit tests for the rule that says whether a label can be asked for a posting.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Mockery;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Readiness;
use Woodev\Tests\Unit\TestCase;

/**
 * A finished posting needs no label whatever the adapter says; an open one is the adapter's call when it has an opinion, and free when
 * it has none.
 */
final class LabelAvailabilityTest extends TestCase {
	public function test_a_finished_posting_needs_no_label_and_the_adapter_is_not_asked(): void {
		$readiness = Mockery::mock( Label_Readiness::class );
		$readiness->shouldNotReceive( 'label_readiness' );

		$this->assertSame( 'Отправление завершено — этикетка не нужна.', ( new Label_Availability( $readiness ) )->reason( 'delivered', true ) );
		$this->assertSame( 'Отправление завершено — этикетка не нужна.', ( new Label_Availability( null ) )->reason( 'cancelled', true ) );
	}

	public function test_an_adapter_without_an_opinion_leaves_an_open_posting_free(): void {
		$this->assertSame( '', ( new Label_Availability( null ) )->reason( 'awaiting_packaging', false ) );
	}

	public function test_the_adapters_reason_is_returned_for_the_status_it_judges(): void {
		$readiness = Mockery::mock( Label_Readiness::class );
		$readiness->shouldReceive( 'label_readiness' )->with( 'awaiting_packaging' )->andReturn( 'Сначала соберите заказ.' );
		$readiness->shouldReceive( 'label_readiness' )->with( 'awaiting_deliver' )->andReturn( null );

		$availability = new Label_Availability( $readiness );

		$this->assertSame( 'Сначала соберите заказ.', $availability->reason( 'awaiting_packaging', false ) );
		$this->assertSame( '', $availability->reason( 'awaiting_deliver', false ) );
	}

	public function test_a_blank_reason_is_no_reason(): void {
		$readiness = Mockery::mock( Label_Readiness::class );
		$readiness->shouldReceive( 'label_readiness' )->andReturn( '  ' );

		$this->assertSame( '', ( new Label_Availability( $readiness ) )->reason( 'x', false ) );
	}
}

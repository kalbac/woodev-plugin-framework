<?php
/** Tests the carrier document result value object. */

use Woodev\Framework\Shipping\Order\Document_Result;

require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/order/class-document-result.php';

/** @covers \Woodev\Framework\Shipping\Order\Document_Result */
class DocumentResultTest extends \PHPUnit\Framework\TestCase {
	public function test_it_exposes_binary_content(): void {
		$result = Document_Result::binary( '%PDF' );
		$this->assertSame( Document_Result::READY_BINARY, $result->get_state() );
		$this->assertSame( '%PDF', $result->get_value() );
	}

	public function test_pending_retry_delay_cannot_be_negative(): void {
		$this->assertSame( 0, Document_Result::pending( -4 )->get_retry_after() );
	}

	public function test_it_exposes_remote_url_and_failure(): void {
		$this->assertSame( 'https://example.test/file.pdf', Document_Result::url( 'https://example.test/file.pdf' )->get_value() );
		$this->assertSame( 'unavailable', Document_Result::failed( 'unavailable' )->get_value() );
	}
}

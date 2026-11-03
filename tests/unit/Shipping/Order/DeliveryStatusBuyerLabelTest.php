<?php
/**
 * Unit tests for the buyer wording of a canonical delivery state and the catalogue behind the buyer tracking block (#1575).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status::buyer_label */
final class DeliveryStatusBuyerLabelTest extends TestCase {

	public function test_every_canonical_state_has_a_buyer_label_and_no_two_share_one(): void {
		$labels = [];
		foreach ( Delivery_Status::canonical_states() as $state ) {
			$label = Delivery_Status::buyer_label( $state );
			$this->assertIsString( $label, "state {$state} has a buyer label" );
			$this->assertNotSame( '', $label );
			$labels[ $state ] = $label;
		}

		$this->assertCount( count( $labels ), array_unique( $labels ), 'a buyer can tell two states apart' );
	}

	public function test_a_state_the_buyer_cannot_be_told_about_has_no_label(): void {
		$this->assertNull( Delivery_Status::buyer_label( Delivery_Status::UNKNOWN ) );
		$this->assertNull( Delivery_Status::buyer_label( 'no_such_state' ) );
		$this->assertNull( Delivery_Status::buyer_label( '' ) );
	}

	public function test_every_constant_of_the_enum_is_answered_or_deliberately_silent(): void {
		$constants = ( new \ReflectionClass( Delivery_Status::class ) )->getConstants();

		foreach ( $constants as $name => $state ) {
			if ( Delivery_Status::UNKNOWN === $state ) {
				continue;
			}
			$this->assertNotNull( Delivery_Status::buyer_label( $state ), "a new Delivery_Status::{$name} needs a buyer label" );
		}
	}

	/**
	 * The storefront msgids are English; the Russian arrives only from the catalogue, so each must be in the `.po`
	 * translated and in the `.pot` (gotcha rule-1-has-two-halves-an-english-msgid-alone-is-a-regression).
	 */
	public function test_every_buyer_facing_string_is_translated_in_the_russian_catalogue(): void {
		$root      = dirname( __DIR__, 4 ) . '/woodev/languages/';
		$po        = $this->catalogue( $root . 'woodev-plugin-framework-ru_RU.po' );
		$pot       = $this->catalogue( $root . 'woodev-plugin-framework.pot' );
		$msgids    = [
			'Delivery tracking',
			'Tracking number',
			'Shipment %1$d of %2$d',
			'Current status: %1$s, %2$s',
			'Current status: %s',
			'Track shipment',
		];
		foreach ( Delivery_Status::canonical_states() as $state ) {
			$msgids[] = Delivery_Status::buyer_label( $state );
		}

		foreach ( $msgids as $msgid ) {
			$this->assertArrayHasKey( $msgid, $po, "'{$msgid}' is in the ru_RU .po" );
			$this->assertNotSame( '', $po[ $msgid ], "'{$msgid}' is translated" );
			$this->assertNotSame( $msgid, $po[ $msgid ], "'{$msgid}' is not left in English" );
			$this->assertArrayHasKey( $msgid, $pot, "'{$msgid}' is in the .pot" );
		}
	}

	/** @return array<string,string> msgid => msgstr of the single-line entries. */
	private function catalogue( string $path ): array {
		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"/m', (string) file_get_contents( $path ), $matches, PREG_SET_ORDER );
		$entries = [];
		foreach ( $matches as $match ) {
			$entries[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
		}

		return $entries;
	}
}

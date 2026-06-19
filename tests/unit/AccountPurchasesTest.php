<?php
/**
 * Unit tests for Woodev_Account_Purchases — the pure normalizer that maps the
 * connector's /purchases response to the lean UI list, plus the badge-id collector.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-purchases.php';

/**
 * @covers \Woodev_Account_Purchases
 */
final class AccountPurchasesTest extends TestCase {

	public function test_normalize_maps_lean_shape(): void {
		$response = array(
			'purchases' => array(
				array(
					'download_id' => 127940,
					'slug'        => 'wb',
					'title'       => 'Интеграция WB',
					'icon'        => 'https://woodev.ru/i.jpg',
					'date'        => '2024-03-15 10:23:45',
				),
			),
		);

		$out = \Woodev_Account_Purchases::normalize( $response );

		$this->assertSame(
			array(
				array(
					'id'    => 127940,
					'title' => 'Интеграция WB',
					'icon'  => 'https://woodev.ru/i.jpg',
					'date'  => '2024-03-15 10:23:45',
				),
			),
			$out
		);
	}

	public function test_normalize_skips_nonpositive_ids_and_dedupes(): void {
		$response = array(
			'purchases' => array(
				array( 'download_id' => 0, 'title' => 'zero' ),
				array( 'download_id' => -3, 'title' => 'neg' ),
				array( 'download_id' => 21, 'title' => 'A' ),
				array( 'download_id' => 21, 'title' => 'dup' ),
				'not-an-array',
			),
		);

		$out = \Woodev_Account_Purchases::normalize( $response );

		$this->assertCount( 1, $out );
		$this->assertSame( 21, $out[0]['id'] );
		$this->assertSame( 'A', $out[0]['title'] );
	}

	public function test_normalize_defaults_missing_keys(): void {
		$out = \Woodev_Account_Purchases::normalize( array( 'purchases' => array( array( 'download_id' => 5 ) ) ) );

		$this->assertSame( array( array( 'id' => 5, 'title' => '', 'icon' => '', 'date' => '' ) ), $out );
	}

	public function test_normalize_returns_empty_for_missing_or_bad_response(): void {
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( array() ) );
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( array( 'purchases' => 'x' ) ) );
		$this->assertSame( array(), \Woodev_Account_Purchases::normalize( 'nope' ) );
	}

	public function test_download_ids_extracts_deduped_positive_ints(): void {
		$purchases = array(
			array( 'id' => 127940 ),
			array( 'id' => 21 ),
			array( 'id' => 127940 ),
			array( 'title' => 'no id' ),
		);

		$this->assertSame( array( 127940, 21 ), \Woodev_Account_Purchases::download_ids( $purchases ) );
	}

	public function test_download_ids_empty_input(): void {
		$this->assertSame( array(), \Woodev_Account_Purchases::download_ids( array() ) );
	}
}

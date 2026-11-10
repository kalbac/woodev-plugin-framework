<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

class RecordsRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Records_Registry::reset_for_tests();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		parent::tearDown();
	}

	public function test_two_owners_can_each_register_a_table_with_the_same_id(): void {
		$registry = Records_Registry::instance();
		$ozon     = new Fake_Provider( 'products' );
		$wb       = new Fake_Provider( 'products' );

		$this->assertTrue( $registry->register( 'ozon-seller', $ozon ) );
		$this->assertTrue( $registry->register( 'wildberries', $wb ) );

		$this->assertSame( $ozon, $registry->get( 'ozon-seller', 'products' )->get_provider() );
		$this->assertSame( $wb, $registry->get( 'wildberries', 'products' )->get_provider() );
		$this->assertSame( 'manage_woocommerce', $registry->get( 'wildberries', 'products' )->get_capability() );
	}

	public function test_a_duplicate_is_reported_and_never_replaces_the_first(): void {
		$registry = Records_Registry::instance();
		$first    = new Fake_Provider( 'products' );
		Functions\expect( '_doing_it_wrong' )->once();

		$registry->register( 'ozon-seller', $first, 'edit_products' );
		$taken = $registry->register( 'ozon-seller', new Fake_Provider( 'products' ), 'manage_options' );

		$this->assertFalse( $taken );
		$this->assertSame( $first, $registry->get( 'ozon-seller', 'products' )->get_provider() );
		$this->assertSame( 'edit_products', $registry->get( 'ozon-seller', 'products' )->get_capability() );
	}

	public function test_malformed_ids_are_refused(): void {
		$registry = Records_Registry::instance();
		Functions\expect( '_doing_it_wrong' )->times( 3 );

		$this->assertFalse( $registry->register( 'Ozon Seller', new Fake_Provider( 'products' ) ) );
		$this->assertFalse( $registry->register( 'ozon-seller', new Fake_Provider( '../products' ) ) );
		$this->assertFalse( $registry->register( 'ozon-seller', new Fake_Provider( 'products' ), '' ) );
		$this->assertNull( $registry->get( 'ozon-seller', 'products' ) );
	}

	public function test_an_unknown_owner_or_table_is_null(): void {
		$registry = Records_Registry::instance();
		$registry->register( 'ozon-seller', new Fake_Provider( 'products' ) );

		$this->assertNull( $registry->get( 'ozon-seller', 'orders' ) );
		$this->assertNull( $registry->get( 'wildberries', 'products' ) );
	}
}

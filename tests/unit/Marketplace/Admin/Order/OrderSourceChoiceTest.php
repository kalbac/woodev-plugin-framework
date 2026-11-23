<?php
/**
 * Unit tests for the «Источник» choice of the orders list.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Order\Order_Source_Choice;
use Woodev\Tests\Unit\TestCase;

/**
 * A user who never chose gets the shop's own orders; a choice is kept per user and only a known one is kept.
 */
final class OrderSourceChoiceTest extends TestCase {
	private const USER = 7;

	/**
	 * The user meta of the test, by user id.
	 *
	 * @var array<int,string>
	 */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) {
				return 'woodev_marketplace_orders_source' === $key ? ( $this->meta[ $user_id ] ?? '' ) : '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) {
				$this->meta[ $user_id ] = $value;

				return true;
			}
		);
	}

	private function choice(): Order_Source_Choice {
		return new Order_Source_Choice( [ 'ozon_api', 'wb_api' ] );
	}

	public function test_a_user_who_never_chose_gets_the_shop_only(): void {
		$this->assertSame( Order_Source_Choice::SHOP, $this->choice()->resolve( self::USER, '' ) );
		$this->assertSame( [], $this->meta, 'The default is not written down.' );
	}

	public function test_a_requested_choice_is_applied_and_remembered_per_user(): void {
		$this->assertSame( Order_Source_Choice::MARKETPLACES, $this->choice()->resolve( self::USER, 'marketplace' ) );
		$this->assertSame( 'marketplace', $this->meta[ self::USER ] );

		// The next request carries no choice: the list opens as the user left it.
		$this->assertSame( 'marketplace', $this->choice()->resolve( self::USER, '' ) );
		// Another user is not touched.
		$this->assertSame( Order_Source_Choice::SHOP, $this->choice()->resolve( 8, '' ) );
	}

	public function test_one_marketplace_is_a_choice_by_its_created_via(): void {
		$this->assertSame( 'ozon_api', $this->choice()->resolve( self::USER, 'ozon_api' ) );
		$this->assertSame( 'ozon_api', $this->meta[ self::USER ] );
	}

	public function test_a_choice_that_did_not_change_is_not_written_again(): void {
		$this->meta[ self::USER ] = 'all';

		Functions\expect( 'update_user_meta' )->never();

		$this->assertSame( 'all', $this->choice()->resolve( self::USER, 'all' ) );
	}

	public function test_an_unknown_request_is_ignored_and_the_remembered_choice_stays(): void {
		$this->meta[ self::USER ] = 'all';

		$this->assertSame( 'all', $this->choice()->resolve( self::USER, 'nonsense' ) );
		$this->assertSame( 'all', $this->meta[ self::USER ] );
	}

	public function test_a_remembered_marketplace_that_is_gone_falls_back_to_the_default(): void {
		$this->meta[ self::USER ] = 'yandex_market';

		$this->assertSame( Order_Source_Choice::SHOP, $this->choice()->resolve( self::USER, '' ) );
	}

	public function test_a_choice_of_one_account_is_known_only_when_it_is_on_offer_and_is_remembered(): void {
		$choice  = new Order_Source_Choice( [ 'ozon_api' ], [ 'ozon_api:12' ] );
		$account = Order_Source_Choice::account( 'ozon_api', 12 );

		$this->assertSame( 'ozon_api:12', $account );
		$this->assertSame( $account, $choice->resolve( self::USER, $account ) );
		$this->assertSame( $account, $this->meta[ self::USER ] );
		$this->assertSame( Order_Source_Choice::SHOP, ( new Order_Source_Choice( [ 'ozon_api' ] ) )->resolve( 0, 'ozon_api:12' ), 'A marketplace with one account offers no such choice.' );
	}

	public function test_a_choice_of_one_account_is_split_into_its_marketplace_and_account(): void {
		$this->assertSame( [ 'ozon_api', 12 ], Order_Source_Choice::parse_account( 'ozon_api:12' ) );
		$this->assertNull( Order_Source_Choice::parse_account( 'ozon_api' ) );
		$this->assertNull( Order_Source_Choice::parse_account( 'ozon_api:abc' ) );
	}

	public function test_a_visitor_without_an_account_gets_the_default_and_nothing_is_stored(): void {
		Functions\expect( 'update_user_meta' )->never();

		$this->assertSame( 'all', $this->choice()->resolve( 0, 'all' ) );
		$this->assertSame( Order_Source_Choice::SHOP, $this->choice()->resolve( 0, '' ) );
	}
}

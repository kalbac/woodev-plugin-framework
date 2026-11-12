<?php
/**
 * Unit tests for the buttons and the saves of the product tab: nonce, capability, account.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Product\Link_Form_Input;
use Woodev\Framework\Marketplace\Admin\Product\Link_Settings_Writer;
use Woodev\Framework\Marketplace\Admin\Product\Panel_Reader;
use Woodev\Framework\Marketplace\Admin\Product\Panel_View;
use Woodev\Framework\Marketplace\Admin\Product\Product_Tab;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Json_Answer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The tab's mutations all go through the guard: a button needs the nonce of its operation and `edit_product` on the product, the
 * ones that write remotely `manage_woocommerce` as well; a save needs the tab's own nonce field. A refused request changes nothing.
 */
final class ProductTabAjaxTest extends Sync_Test_Case {
	/**
	 * Capabilities the user holds (the second argument of `current_user_can()` is ignored).
	 *
	 * @var string[]
	 */
	private $caps = [ 'edit_product', 'manage_woocommerce' ];

	/**
	 * Boots the request stubs: valid nonces are `ok:` + the action.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Json_Answer::install();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'map_deep' )->alias(
			static function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'ok:' . $action === $nonce ? 1 : false;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);

		$this->caps = [ 'edit_product', 'manage_woocommerce' ];
		$_POST      = [];
		$_REQUEST   = [];
	}

	/**
	 * Clears the request.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST    = [];
		$_REQUEST = [];
		parent::tearDown();
	}

	public function test_a_button_with_a_forged_nonce_is_refused_and_changes_nothing(): void {
		$id = $this->tab_product( 'ART-1', 5001 );
		$this->request_button( 'archive', $id, 'forged' );

		$answer = $this->press();

		$this->assertFalse( $answer->success );
		$this->assertSame( 403, $answer->status );
		$this->assertNull( $this->stock_row( $id ) );
	}

	public function test_the_nonce_of_another_operation_does_not_authorise_a_button(): void {
		$id = $this->tab_product( 'ART-2', 5002 );
		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_detach' );

		$this->assertSame( 403, $this->press()->status );
		$this->assertNull( $this->stock_row( $id ) );
	}

	public function test_a_user_without_edit_product_is_refused(): void {
		$id         = $this->tab_product( 'ART-3', 5003 );
		$this->caps = [ 'manage_woocommerce' ];
		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_archive' );

		$this->assertSame( 403, $this->press()->status );
		$this->assertNull( $this->stock_row( $id ) );
	}

	public function test_a_remote_write_needs_the_shop_capability_as_well(): void {
		$id         = $this->tab_product( 'ART-4', 5004 );
		$this->caps = [ 'edit_product' ];

		foreach ( [ 'archive', 'export', 'link' ] as $op ) {
			$this->request_button( $op, $id, 'ok:woodev_fake_product_save_' . $op );

			$this->assertSame( 403, $this->press()->status, $op );
		}

		$this->assertNull( $this->stock_row( $id ) );
	}

	public function test_detach_needs_only_edit_product(): void {
		$id         = $this->tab_product( 'ART-5', 5005, false );
		$this->caps = [ 'edit_product' ];
		$this->request_button( 'detach', $id, 'ok:woodev_fake_product_save_detach' );

		$answer = $this->press();

		$this->assertSame( 409, $answer->status ); // Past the guard: the product has no link to detach.
	}

	public function test_operations_the_marketplace_cannot_serve_get_no_button_and_no_answer(): void {
		$id = $this->tab_product( 'ART-12', 5012 );

		foreach ( [ 'export', 'link' ] as $op ) {
			$this->request_button( $op, $id, 'ok:woodev_fake_product_save_' . $op );

			$this->assertSame( 400, $this->press( [ 'export', 'link' ] )->status, $op );
		}

		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_archive' );

		$this->assertTrue( $this->press( [ 'export', 'link' ] )->success ); // The others still work.
	}

	public function test_an_unknown_account_is_refused(): void {
		$id = $this->tab_product( 'ART-6', 5006 );
		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_archive' );
		$_POST['account_id'] = '9999';

		$this->assertSame( 403, $this->press()->status );
	}

	public function test_a_disabled_account_is_refused(): void {
		$id      = $this->tab_product( 'ART-7', 5007 );
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_archive' );

		$answer = $this->press();

		$this->assertSame( 400, $answer->status );
		$this->assertNull( $this->stock_row( $id ) );
	}

	public function test_a_malformed_request_is_a_400(): void {
		$_POST = [
			'op'         => 'archive',
			'product_id' => '12abc',
			'account_id' => (string) $this->account_id,
		];

		$this->assertSame( 400, $this->press()->status );
	}

	public function test_a_valid_archive_records_the_intent_and_answers_with_the_badge(): void {
		$id = $this->tab_product( 'ART-8', 5008 );
		$this->request_button( 'archive', $id, 'ok:woodev_fake_product_save_archive' );

		$answer = $this->press();

		$this->assertTrue( $answer->success );
		$this->assertSame( 'Создан', $answer->data['badge']['label'] );
		$this->assertNotNull( $this->stock_row( $id ) );
	}

	public function test_a_save_without_the_tab_nonce_writes_nothing(): void {
		$id                                           = $this->tab_product( 'ART-9', 5009, false );
		$_POST['woodev_fake']                         = [ (string) $this->account_id => [ 'sync_stock' => '0' ] ];
		$_REQUEST['woodev_fake_nonce']                = 'forged';

		$this->tab()->save_product( $id ); // The bare writer would fatal if the guard let the save through.

		$this->assertNull( $this->links()->find( $this->account_id, $id ) );
	}

	public function test_a_save_by_a_user_who_may_not_edit_the_product_writes_nothing(): void {
		$id                            = $this->tab_product( 'ART-10', 5010, false );
		$this->caps                    = [];
		$_POST['woodev_fake']          = [ (string) $this->account_id => [ 'sync_stock' => '0' ] ];
		$_REQUEST['woodev_fake_nonce'] = 'ok:woodev_fake_product_save';

		$this->tab()->save_product( $id );

		$this->assertNull( $this->links()->find( $this->account_id, $id ) );
	}

	public function test_a_variation_save_is_checked_against_its_parent_and_nonce(): void {
		$id                                   = $this->tab_product( 'ART-11', 5011, false, 7000 );
		$_POST['woodev_fake_variation']       = [ 3 => [ (string) $this->account_id => [ 'sync_stock' => '0' ] ] ];
		$_REQUEST['woodev_fake_nonce']        = 'forged';

		$this->tab()->save_variation( $id, 3 );

		$this->assertNull( $this->links()->find( $this->account_id, $id ) );
	}

	public function test_the_tab_and_the_hooks_are_named_after_the_marketplace(): void {
		$tabs = $this->tab()->add_tab( [ 'general' => [] ] );

		$this->assertArrayHasKey( 'woodev_fake', $tabs );
		$this->assertSame( 'Fake', $tabs['woodev_fake']['label'] );
		$this->assertSame( 'woodev_fake_product_data', $tabs['woodev_fake']['target'] );
		$this->assertContains( 'show_if_variable', $tabs['woodev_fake']['class'] );
	}

	/**
	 * Runs the AJAX handler and returns the answer.
	 *
	 * @param string[] $withheld Operations the marketplace cannot serve.
	 * @return Json_Answer
	 */
	private function press( array $withheld = [] ): Json_Answer {
		try {
			$this->tab( $withheld )->handle_ajax();
		} catch ( Json_Answer $answer ) {
			return $answer;
		}

		$this->fail( 'The handler sent no answer.' );
	}

	/**
	 * Prepares the request of a button.
	 *
	 * @param string $op         Operation.
	 * @param int    $product_id Product.
	 * @param string $nonce      Nonce sent.
	 * @return void
	 */
	private function request_button( string $op, int $product_id, string $nonce ): void {
		$_POST = [
			'op'         => $op,
			'product_id' => (string) $product_id,
			'account_id' => (string) $this->account_id,
		];

		$_REQUEST['_wpnonce'] = $nonce;
	}

	/**
	 * Creates a product the tab can act on.
	 *
	 * @param string   $offer     Article.
	 * @param int      $remote_id The marketplace's item id.
	 * @param bool     $linked    Whether it has a created link.
	 * @param int|null $parent_id Parent product, for a variation.
	 * @return int Product id.
	 */
	private function tab_product( string $offer, int $remote_id, bool $linked = true, ?int $parent_id = null ): int {
		$id = $linked ? $this->stock_product( $offer, $remote_id ) : $this->product( 10 );

		$this->products[ $id ]->shouldReceive( 'get_type' )->andReturn( 'simple' );
		$this->products[ $id ]->shouldReceive( 'is_type' )->andReturn( false );
		$this->products[ $id ]->shouldReceive( 'get_children' )->andReturn( [] );
		$this->products[ $id ]->shouldReceive( 'get_parent_id' )->andReturn( (int) $parent_id );

		return $id;
	}

	/**
	 * Builds the tab over the real stores, with the collaborators the refused requests never reach left bare.
	 *
	 * @param string[] $withheld Operations the marketplace cannot serve.
	 * @return Product_Tab
	 */
	private function tab( array $withheld = [] ): Product_Tab {
		$names  = new Screen_Names( 'fake', 'Fake' );
		$pinned = new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() );
		$gate   = new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);

		return new Product_Tab(
			Admin_Guard::for_store( $this->accounts(), 'fake' ),
			$this->accounts(),
			$this->links(),
			$this->bare( Panel_Reader::class ),
			new Panel_View( $names ),
			$this->bare( Link_Settings_Writer::class ),
			new Link_Form_Input( $pinned ),
			$this->bare( Export_Service::class ),
			new Link_Scheduler( $this->names() ),
			$gate,
			$this->bare( Link_Detacher::class ),
			$this->hook_stock(),
			$names,
			[
				'settings' => 'https://example.test/settings',
				'mappings' => 'https://example.test/mappings',
				'assets'   => 'https://example.test/assets/',
			],
			'1.0',
			$withheld
		);
	}

	/**
	 * Creates a final collaborator without running its constructor.
	 *
	 * @param string $class Class name.
	 * @return object
	 */
	private function bare( string $class ) {
		return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}
}

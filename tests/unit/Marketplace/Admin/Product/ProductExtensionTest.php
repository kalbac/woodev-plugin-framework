<?php
/**
 * Unit tests for the product-tab side of an adapter's {@see Settings_Extension}.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
use Woodev\Framework\Marketplace\Admin\Product\Panel_View;
use Woodev\Framework\Marketplace\Admin\Product\Product_Extension;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Extension;
use Woodev\Tests\Unit\TestCase;

/**
 * The adapter's selects on the product tab: printed once after the account blocks, saved as product meta, and read back through the
 * resolver, which falls back to the shop-wide value. A tab without an adapter extension prints what it printed before.
 */
final class ProductExtensionTest extends TestCase {
	private const META = '_woodev_fake_ext_buy_button';

	/**
	 * Meta of the product under test.
	 *
	 * @var \ArrayObject
	 */
	private $meta;

	/**
	 * Options (the defaults).
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Boots the option and escaping stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->meta    = new \ArrayObject();
		$this->options = [];

		Functions\when( 'selected' )->alias(
			static function ( $selected, $current, $echo = true ) {
				return (string) $selected === (string) $current ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
	}

	public function test_the_select_is_printed_once_with_its_options_and_the_stored_choice_selected(): void {
		$this->meta[ self::META ] = 'always';

		$html = $this->render_tab( true );

		$this->assertSame( 1, substr_count( $html, 'name="woodev_fake_ext[buy_button]"' ) );
		$this->assertStringContainsString( '<option value="">Как в общих настройках</option>', $html );
		$this->assertStringContainsString( '<option value="always" selected="selected">Всегда</option>', $html );
		$this->assertStringContainsString( 'Кнопка «Купить»', $html );
		$this->assertStringNotContainsString( 'ignored_on_the_tab', $html, 'a switch is not drawn on the tab' );
		$this->assertGreaterThan( strpos( $html, 'name="woodev_fake[3][sync_stock]"' ), strpos( $html, 'woodev_fake_ext[buy_button]' ), 'after the account blocks' );
	}

	public function test_a_product_with_no_choice_shows_the_as_in_the_shop_option(): void {
		$html = $this->render_tab( true );

		$this->assertStringContainsString( '<option value="" selected="selected">Как в общих настройках</option>', $html );
	}

	public function test_without_an_extension_the_tab_is_byte_identical(): void {
		$plain      = $this->render_tab( false );
		$empty_ext  = $this->render_tab( false, [ 'groups' => [], 'values' => [], 'base' => 'woodev_fake_ext' ] );

		$this->assertSame( $plain, $empty_ext );
		$this->assertStringNotContainsString( '_ext', $plain );
	}

	public function test_a_saved_choice_is_stored_as_product_meta_and_read_through_the_resolver(): void {
		$product = $this->product();

		$this->assertSame( [], $this->extension()->save( $product, [ 'buy_button' => 'none' ] ) );
		$this->assertSame( 'none', $this->meta[ self::META ] );
		$this->assertSame( 'none', Effective_Settings::for_adapter( 'fake' )->product_extra( $product, 'buy_button', 'always' ) );
		$this->assertSame( 'none', $this->extension()->screen( $product )['values']['buy_button'], 'it survives a reload of the tab' );
	}

	public function test_an_unknown_option_is_refused_and_nothing_is_written(): void {
		$product = $this->product();

		$errors = $this->extension()->save( $product, [ 'buy_button' => 'sometimes' ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Кнопка на карточке товара', $errors[0] );
		$this->assertSame( [], $this->meta->getArrayCopy() );
	}

	public function test_the_empty_choice_removes_the_meta_and_the_product_follows_the_shop(): void {
		$this->meta[ self::META ]                                    = 'none';
		$this->options['woodev_fake_account_defaults']               = [ Effective_Settings::EXTRA_KEY => [ 'buy_button' => 'stock' ] ];
		$product                                                     = $this->product();

		$this->assertSame( 'none', Effective_Settings::for_adapter( 'fake' )->product_extra( $product, 'buy_button' ) );
		$this->assertSame( [], $this->extension()->save( $product, [ 'buy_button' => '' ] ) );
		$this->assertArrayNotHasKey( self::META, $this->meta );
		$this->assertSame( 'stock', Effective_Settings::for_adapter( 'fake' )->product_extra( $product, 'buy_button' ) );
	}

	public function test_a_field_the_form_did_not_post_keeps_its_value(): void {
		$this->meta[ self::META ] = 'always';

		$this->assertSame( [], $this->extension()->save( $this->product(), [] ) );
		$this->assertSame( 'always', $this->meta[ self::META ] );
	}

	public function test_a_variation_panel_prints_the_same_select_under_its_loop_and_keeps_its_own_choice(): void {
		$this->meta[ self::META ] = 'none';
		$screen                   = $this->extension()->screen( $this->product(), 4 );

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'loop'         => 4,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
				'extension'    => $screen,
			]
		);
		$html = (string) ob_get_clean();

		$this->assertSame( 'woodev_fake_ext_variation[4]', $screen['base'] );
		$this->assertStringContainsString( 'name="woodev_fake_ext_variation[4][buy_button]"', $html );
		$this->assertStringContainsString( 'id="woodev_fake_ext_v4_buy_button"', $html );
		$this->assertStringContainsString( '<option value="none" selected="selected">Не показывать</option>', $html );
	}

	public function test_a_variation_follows_its_parent_and_then_the_shop(): void {
		$parent_meta = new \ArrayObject();
		$parent      = $this->product( $parent_meta );
		$variation   = $this->product( null, 70 );
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) use ( $parent ) {
				return 70 === (int) $id ? $parent : null;
			}
		);
		$resolver = Effective_Settings::for_adapter( 'fake' );

		$this->options['woodev_fake_account_defaults'] = [ Effective_Settings::EXTRA_KEY => [ 'buy_button' => 'stock' ] ];
		$this->assertSame( 'stock', $resolver->product_extra( $variation, 'buy_button' ), 'the shop' );

		$parent_meta[ self::META ] = 'always';
		$this->assertSame( 'always', $resolver->product_extra( $variation, 'buy_button' ), 'the parent beats the shop' );

		$this->meta[ self::META ] = 'none';
		$this->assertSame( 'none', $resolver->product_extra( $variation, 'buy_button' ), 'its own beats the parent' );
	}

	public function test_a_toggle_is_not_a_field_of_the_tab(): void {
		$this->assertSame( [ 'buy_button' ], array_map( static function ( $f ) { return $f->id(); }, $this->extension()->groups()[0]->fields() ) );
		$this->assertSame( [], $this->extension()->save( $this->product(), [ 'ignored_on_the_tab' => 'whatever' ] ) );
		$this->assertSame( [], $this->meta->getArrayCopy() );
	}

	/**
	 * Builds the helper over the fake extension.
	 *
	 * @return Product_Extension
	 */
	private function extension(): Product_Extension {
		return new Product_Extension( new Fake_Settings_Extension(), new Screen_Names( 'fake', 'Fake' ) );
	}

	/**
	 * Builds a product (or a variation) whose meta is `$store`.
	 *
	 * @param \ArrayObject|null $store     Meta store, {@see self::$meta} by default.
	 * @param int               $parent_id Parent id of a variation, 0 for a product.
	 * @return \WC_Product
	 */
	private function product( ?\ArrayObject $store = null, int $parent_id = 0 ): \WC_Product {
		$store   = $store ?? $this->meta;
		$product = \Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'get_parent_id' )->andReturn( $parent_id );
		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $store ) {
				return $store[ $key ] ?? '';
			}
		);
		$product->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( string $key, $value ) use ( $store ): void {
				$store[ $key ] = $value;
			}
		);
		$product->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			static function ( string $key ) use ( $store ): void {
				unset( $store[ $key ] );
			}
		);
		$product->shouldReceive( 'save_meta_data' )->andReturn( null );

		return $product;
	}

	/**
	 * Renders the tab of one account, with the extension of the fake adapter or the given one.
	 *
	 * @param bool                      $with_extension Whether to ask the helper for the extension.
	 * @param array<string,mixed>|null  $extension      An `extension` key to use as it is.
	 * @return string
	 */
	private function render_tab( bool $with_extension, ?array $extension = null ): string {
		$screen = [
			'blocks'       => [ $this->block() ],
			'nonce'        => 'save-nonce',
			'ops'          => [],
			'link_scope'   => '',
			'settings_url' => 'https://example.test/settings',
			'mappings_url' => 'https://example.test/mappings',
		];

		if ( $with_extension ) {
			$screen['extension'] = $this->extension()->screen( $this->product() );
		} elseif ( null !== $extension ) {
			$screen['extension'] = $extension;
		}

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_tab( $screen );

		return (string) ob_get_clean();
	}

	/**
	 * Builds the block of an offer of account 3.
	 *
	 * @return array<string,mixed>
	 */
	private function block(): array {
		return [
			'kind'         => 'offer',
			'product_id'   => 41,
			'account'      => new Account( 3, 'fake', 'Основной', true ),
			'link_id'      => 0,
			'state'        => Link_Record::STATE_CREATED,
			'badge'        => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'remote_error' => '',
			'ids'          => [
				'editable' => false,
				'offer_id' => 'ART-1',
				'auto'     => 'ART-1',
				'summary'  => '',
				'card_url' => '',
			],
			'validation'   => [
				'errors'   => [],
				'warnings' => [],
				'failed'   => false,
			],
			'sync_stock'   => true,
			'sync_price'   => true,
			'oversize'     => false,
			'warehouse'    => [
				'selected' => null,
				'options'  => [
					[
						'value' => '',
						'label' => '',
					],
				],
				'default'  => '',
			],
			'price'        => [
				'override' => [],
				'computed' => [
					'price'     => '',
					'old_price' => '',
					'min_price' => '',
				],
				'currency' => 'RUB',
				'problem'  => '',
			],
			'mapping'      => [
				'selected' => null,
				'options'  => [],
				'resolved' => '',
			],
			'reasons'      => [
				'visible' => false,
				'list'    => [],
			],
			'can_detach'   => true,
			'can_archive'  => true,
		];
	}
}

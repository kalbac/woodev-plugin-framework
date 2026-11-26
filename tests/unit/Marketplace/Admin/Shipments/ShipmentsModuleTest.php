<?php
/**
 * Who gets the page of shipments, and the download of its documents.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shipments;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipment_Document_Download;
use Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Module;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Settings\Settings_Descriptor;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Settings\Shipment_Wording;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

require_once dirname( __DIR__ ) . '/Test_Marketplace_Plugin.php';

/**
 * A marketplace plugin over the adapter a test gives it.
 */
final class Shipping_Test_Plugin extends Test_Marketplace_Plugin {
	/** @var Marketplace_Adapter */
	public $adapter;

	/** @return Marketplace_Adapter */
	public function get_marketplace_adapter(): Marketplace_Adapter {
		return $this->adapter;
	}

	/** @return array<int,array<string,mixed>> */
	public function pages_declared(): array {
		return $this->get_marketplace_pages();
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipments_Module
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Shipments\Shipment_Document_Download
 * @covers \Woodev\Framework\Marketplace\Settings\Shipment_Noun
 */
final class ShipmentsModuleTest extends Shipments_Test_Case {
	/**
	 * The page goes after «Заказы» for a marketplace that ships in shipments, named in its words.
	 *
	 * @return void
	 */
	public function test_the_page_is_offered_with_the_capability_and_the_contract(): void {
		$plugin = $this->plugin( $this->adapter( true, true, $this->noun() ) );
		$pages  = $plugin->pages_declared();

		$this->assertSame( [ Page::LANDING_SLUG, 'orders', 'shipments', 'mappings', 'tools', Page::DIAGNOSTICS_SLUG ], array_column( $pages, 'slug' ) );
		$this->assertSame( 'Поставки', $pages[2]['title'] );
		$this->assertSame( [ 'shipments' ], $pages[2]['tables'] );
		$this->assertInstanceOf( Page::class, Page::from_array( $pages[2] ) );
	}

	/**
	 * Without the capability, or without the contract, there is no page: the released marketplace plugins keep their menu.
	 *
	 * @return void
	 */
	public function test_no_capability_or_no_contract_means_no_page(): void {
		foreach ( [ $this->adapter( false, true ), $this->adapter( true, false ) ] as $adapter ) {
			$this->assertFalse( Shipments_Module::is_offered( $adapter ) );
			$this->assertNotContains( 'shipments', array_column( $this->plugin( $adapter )->pages_declared(), 'slug' ) );
		}
	}

	/**
	 * Without words of its own a marketplace's shipments are «отгрузки».
	 *
	 * @return void
	 */
	public function test_the_default_words(): void {
		$descriptor = Mockery::mock( Settings_Descriptor::class );

		$this->assertSame( 'Отгрузки', Shipment_Noun::for_descriptor( $descriptor )->title() );
		$this->expectException( \InvalidArgumentException::class );
		new Shipment_Noun( [ 'title' => 'Поставки' ] );
	}

	/**
	 * A document is read only for a person with the right and the nonce, an account of the marketplace, and a licence.
	 *
	 * @return void
	 */
	public function test_the_download_checks_everything_before_it_reads(): void {
		$this->marketplace->shipments['S-1'] = Fake_Shipment_Marketplace::remote_shipment( 'Первая', false );
		$this->refresh();
		$this->marketplace->remote = [];
		$key                       = md5( 'S-1' );
		$can                       = true;

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce ) => 'good' === $nonce ? 1 : false );
		Functions\when( 'current_user_can' )->alias(
			static function () use ( &$can ): bool {
				return $can;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$download         = new Shipment_Document_Download( $this->shipment_service(), $this->accounts(), $this->names(), Sqlite_Tables::MARKETPLACE_ID );
		$_GET['_wpnonce'] = 'good';

		$this->assertSame( 'S-1-qr.png', $download->authorise( $this->account_id, $key, 'qr' )->filename() );

		$refused = function () use ( $download, $key ): int {
			try {
				$download->authorise( $this->account_id, $key, 'qr' );
			} catch ( Shipment_Refused $e ) {
				return $e->getCode();
			}

			return 200;
		};

		$this->marketplace->remote = [];
		$_GET['_wpnonce']          = 'forged';
		$this->assertSame( 403, $refused() );

		$_GET['_wpnonce'] = 'good';
		$can              = false;
		$this->assertSame( 403, $refused() );

		$can            = true;
		$this->licensed = false;
		$this->assertSame( 0, $refused() );
		$this->assertSame( [], $this->marketplace->remote );

		unset( $_GET['_wpnonce'] );
	}

	/**
	 * @param bool               $capability Whether the adapter reports the capability.
	 * @param bool               $contract   Whether it implements the contract.
	 * @param Shipment_Noun|null $noun       Its words.
	 * @return Marketplace_Adapter
	 */
	private function adapter( bool $capability, bool $contract, ?Shipment_Noun $noun = null ): Marketplace_Adapter {
		$descriptor = null === $noun ? Mockery::mock( Settings_Descriptor::class ) : Mockery::mock( Settings_Descriptor::class, Shipment_Wording::class );

		if ( null !== $noun ) {
			$descriptor->shouldReceive( 'shipment_noun' )->andReturn( $noun );
		}

		$adapter = $contract ? Mockery::mock( Marketplace_Adapter::class, Shipment_Batches::class ) : Mockery::mock( Marketplace_Adapter::class );

		$adapter->shouldReceive( 'supports' )->andReturnUsing( static fn( string $name ): bool => $capability && Capability::CONFIRM_SHIPMENT === $name );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( $descriptor );

		return $adapter;
	}

	/**
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return Shipping_Test_Plugin
	 */
	private function plugin( Marketplace_Adapter $adapter ): Shipping_Test_Plugin {
		$plugin          = new Shipping_Test_Plugin( self::PLUGIN_ID, [] );
		$plugin->adapter = $adapter;

		return $plugin;
	}
}

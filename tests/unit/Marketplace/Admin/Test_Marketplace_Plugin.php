<?php
/**
 * Marketplace_Plugin double for the admin tests.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/class-marketplace-plugin.php';

/**
 * The constructor is bypassed (it would register the plugin with the collector and wire WooCommerce); the plugin's data comes
 * from the arguments instead.
 */
final class Test_Marketplace_Plugin extends Marketplace_Plugin {

	/** @var string */
	private $plugin_id;

	/** @var array<string,mixed> */
	private $menu_definition;

	/** @var array<int,array<string,mixed>>|null */
	private $pages;

	/** @var array<int,object> */
	private $providers;

	/** @var array<string,string> */
	private $views;

	/**
	 * @param string                              $plugin_id       Plugin id.
	 * @param array<string,mixed>                 $menu_definition The menu definition.
	 * @param array<int,array<string,mixed>>|null $pages           Page descriptors; null keeps the framework's default five.
	 * @param array<int,object>                   $providers       Settings providers.
	 * @param array<string,string>                $views           Plugin views.
	 */
	public function __construct( string $plugin_id, array $menu_definition, ?array $pages = null, array $providers = [], array $views = [] ) {
		$this->plugin_id       = $plugin_id;
		$this->menu_definition = $menu_definition;
		$this->pages           = $pages;
		$this->providers       = $providers;
		$this->views           = $views;
	}

	/** @return Marketplace_Adapter */
	public function get_marketplace_adapter(): Marketplace_Adapter {
		return new Fake_Marketplace_Adapter();
	}

	/** @return array<string,mixed> */
	protected function get_marketplace_menu(): array {
		return $this->menu_definition;
	}

	/** @return array<int,array<string,mixed>> */
	protected function get_marketplace_pages(): array {
		return $this->pages ?? parent::get_marketplace_pages();
	}

	/** @return array<string,string> */
	protected function get_marketplace_views(): array {
		return $this->views;
	}

	/** @return array<int,object> */
	public function get_settings_providers(): array {
		return $this->providers;
	}

	/** @return string */
	protected function get_file() {
		return __FILE__;
	}

	/** @return string */
	public function get_plugin_name() {
		return 'Test Marketplace';
	}

	/** @return string */
	public function get_id() {
		return $this->plugin_id;
	}

	/** @return int */
	public function get_download_id() {
		return 0;
	}
}

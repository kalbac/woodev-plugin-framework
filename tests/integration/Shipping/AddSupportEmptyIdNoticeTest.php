<?php
/**
 * `add_support()` called before `parent::__construct()` reports the wrong order — #815.
 *
 * `Shipping_Method::__construct()` assigns `$this->id` itself (`$this->id =
 * static::get_method_id();`), so a subclass calling `$this->add_support( ... )` from its OWN
 * constructor BEFORE chaining to `parent::__construct()` runs `add_support()` while
 * `get_id()` still answers `''`. Measured on the rig (#813/#815): the action `add_support()`
 * fires then reads `woodev_shipping_method__supports_<feature>` — no id segment, a name
 * shared by every shipping method that declares a feature in this order.
 *
 * The fix does not change WHEN or WHETHER that action fires — queuing it was rejected on the
 * card as a contract change nobody asked for. It only reports the call via
 * `_doing_it_wrong()` under `WP_DEBUG`, naming the correct order.
 *
 * WHY A PROBE SUBCLASS, unlike `AddSupportRebuildsFormFieldsTest.php`'s own choice not to
 * use one. That file could assert entirely on the EXISTING `Woodev_Test_Shipping_Method`
 * fixture because every case it measures is reachable by calling `add_support()` on an
 * already-constructed instance. This card's defect exists only in the ONE constructor
 * ordering that fixture does not use — it declares `FEATURE_BOX_PACKING` via
 * `$this->supports = [ ... ]`, never via `add_support()`, and always before
 * `parent::__construct()`. There is no way to reach "`add_support()` called before
 * `parent::__construct()`" without a subclass that does exactly that.
 *
 * WHY NOT THE SINGLETON `Woodev_Test_Shipping_Method_Plugin`. `Shipping_Method::__construct()`
 * unconditionally calls `$this->get_plugin()->set_shipping_method( $this->get_method_id(),
 * $this )`. Handing the probe an id the singleton's `add_shipping_method()` never declared
 * would register an entry in its `$methods` map with no `'class_name'` key — the exact
 * defect `AddSupportRebuildsFormFieldsTest.php`'s own docblock measured and reverted, since
 * the singleton is shared for the whole suite process and the pollution would outlive this
 * test. `Add_Support_Notice_Test_Plugin` below is a throwaway `Shipping_Plugin`, built fresh
 * per test and never registered anywhere else, so `set_shipping_method()` has somewhere
 * harmless to write and nothing survives the test.
 *
 * `AddSupportEmptyIdNoticeTest` is declared FIRST in this file, ahead of its own fixture
 * classes: `IntegrationSuiteBaseClassTest` (#561) greps every integration test file for its
 * FIRST class declaration and requires that one to extend the shared base — a fixture class
 * extending `Shipping_Plugin` or `Shipping_Method` earlier in the file would read as a false
 * offender.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Api\Shipping_API;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Integration\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Method::add_support
 */
class AddSupportEmptyIdNoticeTest extends TestCase {

	/**
	 * The defect: a subclass calling `add_support()` before `parent::__construct()` must be
	 * reported, naming the exact function string the implementation passes.
	 *
	 * @return void
	 */
	public function test_add_support_before_construction_reports_incorrect_usage(): void {

		$this->setExpectedIncorrectUsage( 'Woodev\Framework\Shipping\Shipping_Method::add_support' );

		$method = new Add_Support_Before_Construct_Probe( new Add_Support_Notice_Test_Plugin() );

		// The notice reports the ORDER, it does not change what add_support() declares —
		// the behaviour stays exactly what #813 already pinned.
		$this->assertTrue( $method->supports_cod() );
	}

	/**
	 * The documented order — `add_support()` called after `parent::__construct()`, with the
	 * id already set — must never trip the notice.
	 *
	 * @return void
	 */
	public function test_add_support_after_construction_does_not_report(): void {

		$method = new Add_Support_After_Construct_Probe( new Add_Support_Notice_Test_Plugin() );

		$method->add_support( Shipping_Method::FEATURE_COD );

		$this->assertTrue( $method->supports_cod() );
	}
}

/**
 * A throwaway `Shipping_Plugin` — see this file's top docblock for why the singleton test
 * plugin is deliberately not used here. `__construct()` is a no-op so none of
 * `Shipping_Plugin`'s real setup (`includes()`, `add_hooks()`, WooCommerce currency/country
 * config) runs; the five methods below are the abstract surface across
 * `Shipping_Plugin` / `Woocommerce_Plugin` / `Woodev_Plugin`, stubbed only because PHP
 * requires them to exist for the class to be instantiable — none of them is ever called.
 *
 * @since 2.0.2
 */
class Add_Support_Notice_Test_Plugin extends Shipping_Plugin {

	public function __construct() {}

	protected function get_shipping_method_classes(): array {
		return [];
	}

	public function get_api(): ?Shipping_API {
		return null;
	}

	protected function get_file() {
		return __FILE__;
	}

	public function get_plugin_name() {
		return 'Add Support Notice Test Plugin';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * The defect's precondition: declares a feature via `add_support()` BEFORE chaining to
 * `parent::__construct()` — the one ordering `AddSupportRebuildsFormFieldsTest.php`'s
 * fixture never exercises. See this file's own top docblock for why a probe is unavoidable
 * here and why it carries its own throwaway plugin.
 *
 * @since 2.0.2
 */
class Add_Support_Before_Construct_Probe extends Shipping_Method {

	private const METHOD_ID = 'add-support-before-construct-probe';

	/** @var Shipping_Plugin */
	private $plugin;

	/**
	 * @param Shipping_Plugin $plugin      the throwaway plugin double this instance registers into.
	 * @param int             $instance_id shipping method instance ID.
	 */
	public function __construct( Shipping_Plugin $plugin, int $instance_id = 0 ) {
		$this->plugin = $plugin;

		// The order the card exists to catch (#815): get_id() is still '' here, one full
		// line above the assignment that gives it a value.
		$this->add_support( self::FEATURE_COD );

		parent::__construct( $instance_id );
	}

	/** @inheritDoc */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/** @inheritDoc */
	public function get_delivery_type(): string {
		return self::TYPE_COURIER;
	}

	/** @inheritDoc */
	protected function get_method_form_fields(): array {
		return [];
	}

	/** @inheritDoc */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		return null;
	}

	/** @inheritDoc */
	protected function get_plugin(): Shipping_Plugin {
		return $this->plugin;
	}
}

/**
 * The documented order, on a SEPARATE probe class: `add_support()` called on an already
 * constructed instance, after `parent::__construct()` has already assigned `$this->id`. A
 * second, near-identical class rather than a constructor flag on the one above, so neither
 * case can accidentally share the other's already-declared `supports` state.
 *
 * @since 2.0.2
 */
class Add_Support_After_Construct_Probe extends Shipping_Method {

	private const METHOD_ID = 'add-support-after-construct-probe';

	/** @var Shipping_Plugin */
	private $plugin;

	/**
	 * @param Shipping_Plugin $plugin      the throwaway plugin double this instance registers into.
	 * @param int             $instance_id shipping method instance ID.
	 */
	public function __construct( Shipping_Plugin $plugin, int $instance_id = 0 ) {
		$this->plugin = $plugin;

		parent::__construct( $instance_id );
	}

	/** @inheritDoc */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/** @inheritDoc */
	public function get_delivery_type(): string {
		return self::TYPE_COURIER;
	}

	/** @inheritDoc */
	protected function get_method_form_fields(): array {
		return [];
	}

	/** @inheritDoc */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		return null;
	}

	/** @inheritDoc */
	protected function get_plugin(): Shipping_Plugin {
		return $this->plugin;
	}
}

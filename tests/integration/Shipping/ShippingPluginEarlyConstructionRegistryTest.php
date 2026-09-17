<?php
/**
 * A `Shipping_Method` constructed before its id is registered must not leave the plugin's
 * registry with a half-entry — #818, surfaced in s124 while working #813.
 *
 * `Shipping_Method::__construct()` calls
 * `$this->get_plugin()->set_shipping_method( $this->get_method_id(), $this )` UNCONDITIONALLY
 * (`class-shipping-method.php:104`) — before this fix, that write created
 * `$this->methods[ $id ] = [ 'shipping_method' => $this ]` for an id
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_shipping_method()} had never touched,
 * with no `class_name` key at all. `get_shipping_method_class_names()`
 * (`class-shipping-plugin.php:1071` at the time of the report) then read that key with no
 * guard — "Undefined array key" plus a `null` element in the result.
 *
 * INTEGRATION AND NOT UNIT, because the point is what the REAL `Shipping_Method` constructor
 * does — `WC_Shipping_Method`'s own construction, `init_form_fields()`, `init_settings()` via
 * the real WooCommerce Settings API — the same reason `ShippingIntegrationConstructionTest`
 * and `AddSupportRebuildsFormFieldsTest` (right next to this file) give for their own choice.
 * The unit suite's `WC_Shipping_Method` stand-in has no constructor of its own, so a unit test
 * building a `Shipping_Method` the normal way would prove nothing about this exact code path;
 * {@see \Woodev\Tests\Unit\Shipping\ShippingPluginRegistryHalfEntryTest} covers the same
 * registry contract directly, driving `set_shipping_method()` the way the constructor does,
 * without a real construction.
 *
 * A DEDICATED, THROWAWAY `Shipping_Plugin` — never the shared
 * `Woodev_Test_Shipping_Method_Plugin::instance()` singleton other integration tests share for
 * the rest of the process. `AddSupportRebuildsFormFieldsTest`'s own docblock is explicit about
 * why: its first draft left unregistered ids in that SAME singleton's private `$methods` map,
 * which nothing in the suite noticed until deliberately asserted against. Now that the
 * half-entry itself is fixed, a probe would leave a COMPLETE entry rather than a broken one —
 * but it would still be a bogus method permanently visible to
 * `get_shipping_methods()`/`get_shipping_method_ids()`/`get_shipping_method_class_names()`
 * for every other test that runs against the singleton afterwards, in the same class of leak.
 * A private plugin instance, constructed and discarded inside each test, cannot leak.
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
 * @since 2.0.2
 */
class ShippingPluginEarlyConstructionRegistryTest extends TestCase {

	/**
	 * The defect itself: constructing a method before `add_shipping_method()` has ever run
	 * for its id must not make `get_shipping_method_class_names()` warn or return `null`.
	 *
	 * RED without the fix: `Woodev_818_Early_Probe_Method`'s constructor calls
	 * `set_shipping_method()` on a plugin that has never heard of
	 * `woodev_818_early_probe`, which used to create an entry with no `class_name` — this
	 * assertion would then see a `null` in the list where the real class name belongs (and,
	 * depending on `zend.assertions`, PHP's own "Undefined array key" notice/warning on top).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_get_shipping_method_class_names_has_no_null_after_early_construction(): void {

		$plugin = new Woodev_818_Registry_Test_Plugin();

		// The defect's trigger, verbatim: constructing the method BEFORE anything ever
		// registers its id — Shipping_Method::__construct() does the rest.
		new Woodev_818_Early_Probe_Method( $plugin );

		$class_names = $plugin->get_shipping_method_class_names();

		$this->assertNotContains( null, $class_names, 'no entry may resolve to null' );
		$this->assertContains(
			Woodev_818_Early_Probe_Method::class,
			$class_names,
			'the early-constructed method must be reported with its real class name'
		);
	}

	/**
	 * The id itself must become a real, complete registry entry — not merely fail to warn.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_early_construction_produces_a_complete_registry_entry(): void {

		$plugin = new Woodev_818_Registry_Test_Plugin();
		$method = new Woodev_818_Early_Probe_Method( $plugin );

		$this->assertTrue( $plugin->has_shipping_method( Woodev_818_Early_Probe_Method::METHOD_ID ) );

		$this->assertContains(
			Woodev_818_Early_Probe_Method::METHOD_ID,
			$plugin->get_shipping_method_ids()
		);

		$this->assertSame(
			Woodev_818_Early_Probe_Method::class,
			$plugin->get_shipping_method_class_name( Woodev_818_Early_Probe_Method::METHOD_ID )
		);

		// The SAME instance the constructor call above already built — get_shipping_method()
		// must not construct a second, different one for an id whose only entry came from
		// early construction.
		$this->assertSame(
			$method,
			$plugin->get_shipping_method( Woodev_818_Early_Probe_Method::METHOD_ID )
		);
	}

	/**
	 * The positive control: the ORDINARY path — `add_shipping_method()` first, construction
	 * after — must keep working exactly as before. Without this, the two tests above could
	 * pass for a fix that broke normal registration instead of the early-construction case.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_normal_registration_then_construction_is_unaffected(): void {

		$plugin = new Woodev_818_Registry_Test_Plugin();

		$plugin->add_shipping_method( Woodev_818_Early_Probe_Method::METHOD_ID, Woodev_818_Early_Probe_Method::class );

		$method = new Woodev_818_Early_Probe_Method( $plugin );

		$this->assertSame(
			[ Woodev_818_Early_Probe_Method::class ],
			$plugin->get_shipping_method_class_names()
		);
		$this->assertSame( $method, $plugin->get_shipping_method( Woodev_818_Early_Probe_Method::METHOD_ID ) );
	}
}

/**
 * A throwaway `Shipping_Plugin`. The constructor is bypassed (same reasoning as
 * `Woodev_Test_Shipping_Plugin_For_Guards` in the unit suite's
 * `ShippingMethodFilterReturnGuardsTest`): this test needs none of
 * `Shipping_Plugin::__construct()`'s wiring (`includes()`/`add_hooks()`), only its registry —
 * `$methods` starts empty regardless of which constructor path built the object.
 *
 * @since 2.0.2
 */
final class Woodev_818_Registry_Test_Plugin extends Shipping_Plugin {

	public function __construct() {}

	/** @return array */
	protected function get_shipping_method_classes(): array {
		return [];
	}

	/** @return string */
	protected function get_file() {
		return __FILE__;
	}

	/** @return string */
	public function get_plugin_name() {
		return 'Card 818 Registry Test Plugin';
	}

	/** @return int */
	public function get_download_id() {
		return 0;
	}

	/** @return Shipping_API|null */
	public function get_api(): ?Shipping_API {
		return null;
	}
}

/**
 * A minimal, REAL shipping method — its constructor is NOT overridden, so
 * `parent::__construct( $instance_id )` runs the genuine `Shipping_Method::__construct()`,
 * which is what calls `set_shipping_method()` on whatever `get_plugin()` returns.
 *
 * @since 2.0.2
 */
final class Woodev_818_Early_Probe_Method extends Shipping_Method {

	/** @var string never registered on any plugin via add_shipping_method() before use. */
	public const METHOD_ID = 'woodev_818_early_probe';

	/** @var Shipping_Plugin the throwaway plugin this probe reports to. */
	private Shipping_Plugin $owning_plugin;

	/**
	 * @param Shipping_Plugin $owning_plugin the throwaway plugin instance for this test.
	 * @param int             $instance_id   shipping method instance id.
	 */
	public function __construct( Shipping_Plugin $owning_plugin, int $instance_id = 0 ) {

		$this->owning_plugin      = $owning_plugin;
		$this->method_title       = 'Card 818 Early Probe';
		$this->method_description = 'Constructed before registration on purpose — see #818.';

		parent::__construct( $instance_id );
	}

	/** @return string */
	public static function get_method_id(): string {
		return self::METHOD_ID;
	}

	/** @return string */
	public function get_delivery_type(): string {
		return self::TYPE_COURIER;
	}

	/** @return array */
	protected function get_method_form_fields(): array {
		return [];
	}

	/**
	 * @param array                      $package unused — this probe never calculates a rate.
	 * @param \Woodev_Packer_Result|null $packed  unused.
	 * @return Shipping_Rate|null
	 */
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		return null;
	}

	/** @return Shipping_Plugin */
	protected function get_plugin(): Shipping_Plugin {
		return $this->owning_plugin;
	}
}

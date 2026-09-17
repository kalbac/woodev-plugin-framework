<?php
/**
 * Unit tests for `Shipping_Plugin::get_declared_shipping_method_ids()` (card #842, round 2).
 *
 * `Orders_Registry::check_method_ids_contract()` (#842) needs a side-effect-free
 * source of "what shipping methods does this plugin ship". Round 1 answered that by
 * calling `WC()->shipping()->get_shipping_methods()` and reading
 * `Shipping_Plugin::get_shipping_method_ids()` — which forces WooCommerce to
 * CONSTRUCT every registered shipping method on every admin request under
 * `WP_DEBUG` (critic MAJOR). `get_declared_shipping_method_ids()` derives the same
 * ids statically instead, off the SAME class list
 * `Shipping_Plugin::register_shipping_methods()` uses — that method's own
 * filter/fallback behaviour is covered by `ShippingMethodFilterReturnGuardsTest`
 * (site 6) and is unaffected by this extraction.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base — same shape as the sibling stubs
		 * elsewhere in this suite (whichever file's guard runs first wins).
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/** @var array */
			public array $supports = [];

			/** @var array */
			public $instance_form_fields = [];

			/** @var array */
			public $settings = [];

			/** @var string */
			public $title = '';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Minimal Shipping_Plugin double whose declared class list is supplied by the
	 * test, so `get_declared_shipping_method_ids()` (and, through it,
	 * `register_shipping_methods()`'s shared class-list resolution) run against a
	 * list the test controls.
	 */
	class Woodev_Test_Shipping_Plugin_For_Declared_Ids extends Shipping_Plugin {

		/** @var class-string[] */
		private array $classes;

		/** @param class-string[] $classes shipping method classes to declare. */
		public function __construct( array $classes ) {
			$this->classes = $classes;
		}

		/** @return class-string[] */
		protected function get_shipping_method_classes(): array {
			return $this->classes;
		}

		/** @return string */
		protected function get_file() {
			return __FILE__;
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Declared Ids Shipping Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/** @return string */
		public function get_id() {
			return 'declared-ids-shipping';
		}

		/** @return string */
		public function get_id_underscored() {
			return 'declared_ids_shipping';
		}

		/** @return null */
		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}
	}

	/**
	 * A shipping method whose CONSTRUCTOR THROWS — proves
	 * `get_declared_shipping_method_ids()` never constructs a shipping method to
	 * read its id: `get_method_id()` is `abstract public static`, so it is read via
	 * `$class::get_method_id()` off the class name alone.
	 */
	class Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids extends Shipping_Method {

		public function __construct() {
			throw new \RuntimeException( 'must not be constructed — get_declared_shipping_method_ids() is static' );
		}

		/** @return string */
		public static function get_method_id(): string {
			return 'declared-courier';
		}

		/** @return string */
		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function get_plugin(): Shipping_Plugin {
			throw new \RuntimeException( 'not needed — this double is never constructed' );
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return \Woodev\Framework\Shipping\Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			throw new \RuntimeException( 'not needed — this double is never constructed' );
		}
	}

	/**
	 * A second throwing double with a distinct id — needed to prove a FILTERED list
	 * is honoured (a different class than the plugin's own).
	 */
	class Woodev_Test_Other_Throwing_Shipping_Method_For_Declared_Ids extends Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids {

		/** @return string */
		public static function get_method_id(): string {
			return 'declared-pickup';
		}
	}

	/**
	 * A class that is NOT a `Shipping_Method` at all — the "invalid class skipped"
	 * case `Shipping_Plugin::is_valid_shipping_method_class()` guards against.
	 */
	class Woodev_Test_Invalid_Shipping_Method_For_Declared_Ids {

		/** @return string */
		public static function get_method_id(): string {
			return 'not-a-real-shipping-method';
		}
	}

	/**
	 * @coversNothing
	 */
	final class ShippingPluginDeclaredMethodIdsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Default: no filter attached — apply_filters() passes the second
			// argument through unchanged, exactly like an unhooked filter does.
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					return $filtered;
				}
			);
		}

		/**
		 * The core proof for the whole extraction (critic MAJOR, round 2): both
		 * doubles' constructors THROW, so a passing assertion proves neither was
		 * constructed — each id is read via `$class::get_method_id()` statically,
		 * in class-list order.
		 */
		public function test_ids_are_derived_statically_from_the_plugins_own_class_list(): void {
			$plugin = new Woodev_Test_Shipping_Plugin_For_Declared_Ids(
				[
					Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids::class,
					Woodev_Test_Other_Throwing_Shipping_Method_For_Declared_Ids::class,
				]
			);

			$this->assertSame( [ 'declared-courier', 'declared-pickup' ], $plugin->get_declared_shipping_method_ids() );
		}

		/**
		 * The `woodev_shipping_plugin_method_classes` filter is honoured — the SAME
		 * filter `register_shipping_methods()` applies, via the shared resolution, so
		 * the two can never drift.
		 */
		public function test_honours_the_method_classes_filter(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					return 'woodev_shipping_plugin_method_classes' === $tag
						? [ Woodev_Test_Other_Throwing_Shipping_Method_For_Declared_Ids::class ]
						: $filtered;
				}
			);

			$plugin = new Woodev_Test_Shipping_Plugin_For_Declared_Ids( [ Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids::class ] );

			$this->assertSame( [ 'declared-pickup' ], $plugin->get_declared_shipping_method_ids() );
		}

		/**
		 * A non-array filter return is discarded — the same fallback
		 * `register_shipping_methods()` documents, exercised through the shared helper.
		 */
		public function test_a_non_array_filter_return_falls_back_to_the_plugins_own_list(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					return 'woodev_shipping_plugin_method_classes' === $tag ? 'not an array' : $filtered;
				}
			);

			$plugin = new Woodev_Test_Shipping_Plugin_For_Declared_Ids( [ Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids::class ] );

			$this->assertSame( [ 'declared-courier' ], $plugin->get_declared_shipping_method_ids() );
		}

		/**
		 * A class that is not a `Shipping_Method` subclass is skipped, exactly like
		 * `register_shipping_methods()`'s own `is_valid_shipping_method_class()` guard.
		 */
		public function test_an_invalid_class_is_skipped(): void {
			$plugin = new Woodev_Test_Shipping_Plugin_For_Declared_Ids(
				[
					Woodev_Test_Invalid_Shipping_Method_For_Declared_Ids::class,
					Woodev_Test_Throwing_Shipping_Method_For_Declared_Ids::class,
				]
			);

			$this->assertSame( [ 'declared-courier' ], $plugin->get_declared_shipping_method_ids() );
		}
	}
}

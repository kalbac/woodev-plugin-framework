<?php
/**
 * The merchant's own Title and Description reach WooCommerce — #768, #766.
 *
 * These live in the INTEGRATION suite deliberately. Both defects are about what a real
 * `WC_Shipping_Method` and a real `WC_Shipping_Rate` do with values the framework hands
 * them, and the unit suite's WooCommerce stand-ins are hand-written stubs — asserting
 * against those would prove the stub, not the contract (gotcha
 * `a-mocked-provider-proves-the-mock-not-the-contract`). Specifically:
 *
 * - `WC_Shipping_Method::get_option()` routes a key to the INSTANCE option when the
 *   method has an instance id and the key is one of its instance form fields. That
 *   routing is the whole mechanism behind the Title field, and no stub reproduces it.
 * - `WC_Shipping_Method::add_rate()` builds the `WC_Shipping_Rate` itself and sets seven
 *   things on it — description and delivery time are not among them, even though the
 *   object supports both. That omission is why the framework applies them afterwards.
 *
 * The fixture `Woodev_Test_Shipping_Method` is the right subject precisely because it
 * does NOT assign `$this->title` in its own constructor: that is the blind spot which
 * let #768 live — every plugin that ever reached a browser assigned the property itself,
 * so the base never had to.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class ShippingMethodTitleAndRateAttributesTest extends TestCase {

	/**
	 * Instance id used for the method under test. Any non-zero value works: it is what
	 * makes `get_option()` route to the instance settings.
	 *
	 * @var int
	 */
	private const INSTANCE_ID = 4242;

	/**
	 * The default title a pickup method gets from `get_default_title()`.
	 *
	 * @var string
	 */
	private const DEFAULT_PICKUP_TITLE = 'Pickup delivery';

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( 'woocommerce_woodev_test_shipping_' . self::INSTANCE_ID . '_settings' );
		parent::tearDown();
	}

	/**
	 * Writes the instance settings WooCommerce would have written from the zone screen.
	 *
	 * @param array $settings the instance settings to store.
	 *
	 * @return \Woodev_Test_Shipping_Method
	 */
	private function method_with_settings( array $settings ): \Woodev_Test_Shipping_Method {

		update_option( 'woocommerce_woodev_test_shipping_' . self::INSTANCE_ID . '_settings', $settings );

		return new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );
	}

	/* ------------------------------------------------------------------ *
	 * #768 — the Title field
	 * ------------------------------------------------------------------ */

	/**
	 * The title the merchant typed is the title WooCommerce shows.
	 *
	 * `WC_Shipping_Method::get_title()` is `apply_filters( ..., $this->title, ... )` with
	 * no fallback of any kind, so this passes only if the base actually assigns the
	 * property. Before the fix it returned an empty string and the method sat nameless in
	 * the shipping-zone table.
	 *
	 * @return void
	 */
	public function test_the_merchant_title_reaches_get_title(): void {

		$method = $this->method_with_settings( [ 'title' => 'Пункт выдачи СДЭК' ] );

		$this->assertSame( 'Пункт выдачи СДЭК', $method->get_title() );
	}

	/**
	 * With nothing saved, the method still has a name — the form field's own default.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_method_falls_back_to_the_default_title(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$this->assertSame( self::DEFAULT_PICKUP_TITLE, $method->get_title() );
	}

	/**
	 * A merchant who CLEARS the field gets the default back rather than a nameless
	 * method. This is the case the plain `get_option( 'title' )` of the v1 framework did
	 * not cover, and it is also what stops the rate from being dropped further down —
	 * `add_rate()` discards a rate whose label is empty.
	 *
	 * @return void
	 */
	public function test_a_cleared_title_falls_back_to_the_default(): void {

		$method = $this->method_with_settings( [ 'title' => '' ] );

		$this->assertSame( self::DEFAULT_PICKUP_TITLE, $method->get_title() );
	}

	/**
	 * A plugin that assigns `$this->title` itself keeps winning: its assignment runs
	 * after `parent::__construct()`.
	 *
	 * This is the compatibility question the card asked to confirm by measurement rather
	 * than assume — every shipped woodev shipping plugin sets the property in its own
	 * constructor, so the base must not overrule them.
	 *
	 * @return void
	 */
	public function test_a_subclass_that_sets_the_title_itself_still_wins(): void {

		update_option(
			'woocommerce_woodev_test_shipping_' . self::INSTANCE_ID . '_settings',
			[ 'title' => 'from the settings' ]
		);

		$method = new Woodev_Test_Shipping_Method_With_Own_Title( self::INSTANCE_ID );

		$this->assertSame( 'assigned by the subclass', $method->get_title() );
	}

	/* ------------------------------------------------------------------ *
	 * #768 / #766 — the Description field and the rate attributes
	 * ------------------------------------------------------------------ */

	/**
	 * The merchant's Description ends up on the `WC_Shipping_Rate`.
	 *
	 * That is the only slot WooCommerce has for it: `WC_Shipping_Method` has no
	 * `$description` property at all (only `$method_description`, which describes the
	 * method TYPE to the merchant), while `WC_Shipping_Rate::set_description()` exists
	 * since WooCommerce 9.2.0 and the Store API publishes it per rate. Before the fix
	 * nothing in the framework read the option, so the control was inert end to end.
	 *
	 * @return void
	 */
	public function test_the_merchant_description_reaches_the_rate(): void {

		if ( ! method_exists( \WC_Shipping_Rate::class, 'set_description' ) ) {
			$this->markTestSkipped( 'WC_Shipping_Rate::set_description() arrived in WooCommerce 9.2.0.' );
		}

		$method = $this->method_with_settings(
			[
				'title'       => 'Пункт выдачи СДЭК',
				'description' => 'Отправление со склада в Москве',
			]
		);

		$method->calculate_shipping( [] );

		$rate = $this->single_rate_of( $method );

		$this->assertSame( 'Отправление со склада в Москве', $rate->get_description() );
	}

	/**
	 * A description the carrier put on the rate outranks the merchant's option — the
	 * option is the default beneath it, not an override of it.
	 *
	 * @return void
	 */
	public function test_a_rate_description_outranks_the_option(): void {

		if ( ! method_exists( \WC_Shipping_Rate::class, 'set_description' ) ) {
			$this->markTestSkipped( 'WC_Shipping_Rate::set_description() arrived in WooCommerce 9.2.0.' );
		}

		$method = $this->method_with_settings(
			[
				'title'       => 'Пункт выдачи СДЭК',
				'description' => 'the option',
			]
		);

		$this->return_rate_with_args( $method, [ 'description' => 'from the carrier', 'delivery_time' => '2-4 days' ] );

		$method->calculate_shipping( [] );

		$rate = $this->single_rate_of( $method );

		$this->assertSame( 'from the carrier', $rate->get_description() );
		$this->assertSame( '2-4 days', $rate->get_delivery_time() );
	}

	/**
	 * `taxes` and `calc_tax` travel through the DTO and are honoured by `add_rate()`.
	 *
	 * This is the half of #766 that is about extension rather than crashes: while a
	 * plugin called `add_rate()` itself it could pass these; once the rate had to become
	 * a `Shipping_Rate`, everything past the five modelled keys was dropped in silence.
	 * Explicit taxes are the observable case — `add_rate()` skips its own calculation
	 * when an array is supplied and stores what it was given.
	 *
	 * @return void
	 */
	public function test_explicit_taxes_survive_the_dto(): void {

		$method = $this->method_with_settings( [ 'title' => 'Пункт выдачи СДЭК' ] );

		$this->return_rate_with_args( $method, [ 'taxes' => [ 1 => 12.5 ] ] );

		$method->calculate_shipping( [] );

		$this->assertSame( [ 1 => 12.5 ], $this->single_rate_of( $method )->get_taxes() );
	}

	/**
	 * A rate built with an empty label does not take the method off the checkout.
	 *
	 * `add_rate()` returns early on an empty label, with no fatal and no log line, so the
	 * method would simply vanish. The framework substitutes its own title instead — which
	 * is never empty since #768.
	 *
	 * @return void
	 */
	public function test_an_empty_rate_label_is_replaced_by_the_method_title(): void {

		// The DTO reports the empty label through `_doing_it_wrong()`, which WordPress's
		// own test case turns into a failure unless it is declared. Declaring it here is
		// the assertion that the report fires at all — a silent degradation would leave a
		// developer with no way to find out their builder produced a nameless rate.
		$this->setExpectedIncorrectUsage( 'Woodev\Framework\Shipping\Shipping_Rate::__construct' );

		$method = $this->method_with_settings( [ 'title' => 'Пункт выдачи СДЭК' ] );

		$this->return_rate_with_args( $method, [], '' );

		$method->calculate_shipping( [] );

		$rate = $this->single_rate_of( $method );

		$this->assertSame( 'Пункт выдачи СДЭК', $rate->get_label() );
	}

	/**
	 * The empty-label substitution must survive a third party returning garbage from
	 * `woocommerce_shipping_method_title`.
	 *
	 * `WC_Shipping_Method::get_title()` is a bare `apply_filters()` with no type guard of
	 * its own, and it is consulted here on the shipping calculation path — so an actor's
	 * return reaches our code with a customer waiting. Casting an object to string is a
	 * fatal `Error`, which would turn the guard against silent rate loss into a white
	 * screen. Found by the critic pass on this change; the rule it applies is the
	 * project's own: discard a bad filter return and keep the pre-filter value.
	 *
	 * @return void
	 */
	public function test_a_non_string_title_filter_return_does_not_fatal(): void {

		$this->setExpectedIncorrectUsage( 'Woodev\Framework\Shipping\Shipping_Rate::__construct' );

		$method = $this->method_with_settings( [ 'title' => 'Пункт выдачи СДЭК' ] );

		add_filter( 'woocommerce_shipping_method_title', static fn() => new \stdClass() );

		$this->return_rate_with_args( $method, [], '' );

		$method->calculate_shipping( [] );

		// WooCommerce drops a rate whose label is empty, so no rate is the honest
		// outcome here. What matters is that the request survived at all.
		$this->assertSame( [], $method->rates );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Asserts the method produced exactly one rate and returns it.
	 *
	 * @param \WC_Shipping_Method $method the method that just calculated.
	 *
	 * @return \WC_Shipping_Rate
	 */
	private function single_rate_of( \WC_Shipping_Method $method ): \WC_Shipping_Rate {

		$this->assertCount( 1, $method->rates, 'The method must have produced exactly one rate.' );

		return array_values( $method->rates )[0];
	}

	/**
	 * Makes the method return a rate carrying the given args (and label) on its next
	 * calculation, through the framework's own rate filter.
	 *
	 * The filter is used rather than a subclass so the assertion covers the path a real
	 * plugin takes; it is removed in tearDown by WordPress's own per-test hook reset.
	 *
	 * @param \Woodev_Test_Shipping_Method $method the method under test.
	 * @param array                        $args   further WooCommerce rate attributes.
	 * @param string|null                  $label  label to force, or null to keep the rate's own.
	 *
	 * @return void
	 */
	private function return_rate_with_args( \Woodev_Test_Shipping_Method $method, array $args, ?string $label = null ): void {

		add_filter(
			'woodev_shipping_method_calculated_rate',
			static function ( $rate ) use ( $method, $args, $label ) {

				if ( ! $rate instanceof Shipping_Rate ) {
					return $rate;
				}

				$rate = $rate->with_args( $args );

				return null === $label ? $rate : $rate->with_label( $label );
			}
		);
	}
}

/**
 * A method that assigns `$this->title` in its own constructor, the way every shipped
 * woodev shipping plugin does.
 *
 * @since 2.0.2
 */
class Woodev_Test_Shipping_Method_With_Own_Title extends \Woodev_Test_Shipping_Method {

	/**
	 * @param int $instance_id shipping method instance ID.
	 */
	public function __construct( int $instance_id = 0 ) {

		parent::__construct( $instance_id );

		$this->title = 'assigned by the subclass';
	}
}

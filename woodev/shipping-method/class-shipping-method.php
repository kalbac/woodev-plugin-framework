<?php
/**
 * Woodev Shipping Method
 *
 * Base shipping method class providing common functionality for all
 * shipping method types (courier, pickup, postal).
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Point_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method' ) ) :

	abstract class Shipping_Method extends \WC_Shipping_Method {

		/** Courier delivery type */
		const TYPE_COURIER = 'courier';

		/** Pickup point delivery type */
		const TYPE_PICKUP = 'pickup';

		/** Postal delivery type */
		const TYPE_POSTAL = 'postal';

		/**
		 * Method classes already reported by {@see self::ensure_method_title()}.
		 *
		 * @var array<string, true>
		 */
		private static $method_title_reported = [];

		const SHIPPING_CLASS_NONE = 'none';

		const SHIPPING_CLASS_ANY = 'any';

		/** Shipping zones feature */
		const FEATURE_SHIPPING_ZONES = 'shipping-zones';

		/** Instance settings feature */
		const FEATURE_INSTANCE_SETTINGS = 'instance-settings';

		const FEATURE_SHIPPING_CLASSES = 'shipping-classes';

		/** Box-packing feature: lets the method combine package contents into parcels. */
		const FEATURE_BOX_PACKING = 'box-packing';

		/** COD (cash-on-delivery) payment support feature. */
		const FEATURE_COD = 'cod';

		/** Insurance feature: adds the instance mode and the shared quote/order resolver. */
		const FEATURE_INSURANCE = 'insurance';

		/** Yandex's installed-site setting key and values, preserved for migration. */
		const OPTION_INSURANCE = 'include_insurance';
		const INSURANCE_NONE = 'none';
		const INSURANCE_ALWAYS = 'always';
		const INSURANCE_DELIVERY_PAYMENT = 'delivery_payment';

		/** Declared-value support feature. */
		const FEATURE_DECLARED_VALUE = 'declared-value';

		/**
		 * Rate-cache feature: successful rates of this method may be reused for a few minutes.
		 *
		 * OFF unless declared. Declaring it is a promise that {@see self::get_rate_cache_context()}
		 * names every input the carrier's price depends on (#958).
		 */
		const FEATURE_RATE_CACHE = 'rate-cache';

		/**
		 * Fee-by-payment-method feature: the instance gets a `fee_payments` control, and the carrier asks
		 * {@see self::fee_applies_for_package()} (or calls {@see self::apply_fee_for_package()}) before it
		 * adds its fee. OFF unless declared (#1144).
		 */
		const FEATURE_FEE_PAYMENTS = 'fee-payments';

		/**
		 * Cost-limits feature, for a carrier whose price is CALCULATED (by the carrier's API): the instance
		 * gets a `min_cost` and a `max_cost` control, and the framework raises or lowers the finished rate
		 * to them ({@see self::apply_cost_limits()}). Equal limits make the price effectively fixed, which
		 * is why there is no separate «fixed price» option. OFF unless declared; a carrier whose merchant
		 * types the price himself must not declare it.
		 */
		const FEATURE_COST_LIMITS = 'cost-limits';

		/** Instance setting holding the lowest cost the customer may pay — the Yandex plugin's key, so a migration carries it 1:1. */
		const OPTION_MIN_COST = 'min_cost';

		/** Instance setting holding the highest cost the customer may pay — the Yandex plugin's key. */
		const OPTION_MAX_COST = 'max_cost';

		/**
		 * The features whose declaration changes what {@see self::init_form_fields()} builds.
		 *
		 * Exactly these five gate a control there. The rest declare intent and shape no form, so
		 * {@see self::add_support()} must not pay for a rebuild on their account.
		 *
		 * @since 2.0.2
		 */
		private const FORM_SHAPING_FEATURES = [
			self::FEATURE_SHIPPING_CLASSES,
			self::FEATURE_BOX_PACKING,
			self::FEATURE_FEE_PAYMENTS,
			self::FEATURE_COST_LIMITS,
			self::FEATURE_INSURANCE,
		];

		/**
		 * Whether {@see self::init_form_fields()} is running right now.
		 *
		 * Read by {@see self::add_support()}, which must not rebuild the form underneath a pass
		 * that is about to assign over its result.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private bool $building_form_fields = false;

		/**
		 * Whether a form-shaping feature was declared while the form was being built.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private bool $pending_form_rebuild = false;

		/**
		 * Whether {@see self::get_admin_rates_for_package()} is running on this instance right now.
		 *
		 * The narrow context flag the REST/admin veto in {@see self::should_send_cart_api_request()}
		 * yields to (#965, spec D2 «Mine 1»): per instance and per call, never a global relaxation.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private bool $admin_rate_calculation = false;

		/**
		 * Gets the unique method identifier.
		 *
		 * Used for WC registration. Must be unique across all methods.
		 *
		 * @since 1.5.0
		 *
		 * @return string method ID
		 */
		abstract public static function get_method_id(): string;

		/**
		 * Gets the delivery type for this method.
		 *
		 * @since 1.5.0
		 *
		 * @return string one of TYPE_COURIER, TYPE_PICKUP, TYPE_POSTAL
		 */
		abstract public function get_delivery_type(): string;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Applies the merchant's `title` option to `$this->title` (#768).
		 *
		 * @param int $instance_id shipping method instance ID
		 */
		public function __construct( $instance_id = 0 ) {

			parent::__construct( $instance_id );

			$this->id = static::get_method_id();

			$this->get_plugin()->set_shipping_method( $this->get_method_id(), $this );

			/*
			 * MERGE, never overwrite — the subclass has already run at this point.
			 *
			 * A shipping method declares its features the way WooCommerce's own methods do:
			 * `$this->supports = [ … ]` in its constructor, BEFORE calling this one. An
			 * unconditional assignment here threw that away silently, and the loss was
			 * invisible because every fixture happened to declare exactly the two features
			 * this line re-added.
			 *
			 * Measured on the rig, #567, all three ways a plugin could try:
			 *
			 *   pre-set `$this->supports` with FEATURE_BOX_PACKING  -> supports_box_packing() FALSE
			 *   add_support() after construction                    -> TRUE, but NO control
			 *   add_support() + a manual init_form_fields() re-run   -> control appears
			 *
			 * So neither documented path produced the setting, and the `supports_box_packing()`
			 * and `supports_shipping_classes()` branches in `init_form_fields()` below were
			 * dead code for every plugin — which is why no fixture could reach «Алгоритм
			 * упаковки» on the rig at all.
			 *
			 * `WC_Shipping_Method::$supports` defaults to `[ 'settings' ]`, so a subclass that
			 * declares NOTHING arrives here carrying that. It is dropped, deliberately: keeping
			 * it would flip `WC_Shipping_Method::has_settings()` to true for a method with no
			 * instance id, which is a different change from this fix and one nothing asked for.
			 * A subclass that declares `'settings'` alongside anything else keeps it.
			 */
			$declared = is_array( $this->supports ) && [ 'settings' ] !== $this->supports ? $this->supports : [];

			$this->supports = array_values(
				array_unique(
					array_merge(
						[
							self::FEATURE_SHIPPING_ZONES,
							self::FEATURE_INSTANCE_SETTINGS,
						],
						$declared
					)
				)
			);

			// Load form fields
			$this->init_form_fields();

			// Initialize and load settings
			$this->init_settings();

			/*
			 * Apply the merchant's own title.
			 *
			 * `WC_Shipping_Method::get_title()` returns `$this->title` with no fallback of
			 * any kind, so leaving the property unset leaves the method NAMELESS in the
			 * shipping-zone table and on the checkout — while `init_form_fields()` above
			 * still shows the merchant a Title control. The v1 framework did assign it
			 * here; the v2 rewrite dropped the line, and no fixture noticed because every
			 * plugin that reached a browser assigned `$this->title` in its own constructor
			 * (#768).
			 *
			 * A subclass that still does so wins: its assignment runs after this one.
			 */
			$this->title = (string) $this->get_option( 'title', $this->get_title_fallback() );

			$this->ensure_method_title();

			// admin only
			if ( is_admin() ) {
				add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
			}
		}

		/**
		 * Guarantees a non-empty `$method_title`.
		 *
		 * WooCommerce shows `$method_title` (the admin-facing name) in the «Create shipping method» modal and
		 * in the zone's method list; empty, the card is blank and the merchant cannot tell what they are adding.
		 * A carrier must set it (and `$method_description`), like `$this->supports`, BEFORE calling the parent
		 * constructor. When it did not, this falls back to the method's default title for its delivery type
		 * ({@see self::get_default_title()}), then to the capitalised method id — and reports a
		 * `_doing_it_wrong()` (visible with WP_DEBUG) once per class so the carrier author notices.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function ensure_method_title(): void {

			if ( '' !== trim( (string) $this->method_title ) ) {
				return;
			}

			$fallback = $this->get_default_title();

			if ( '' === $fallback ) {
				$fallback = ucfirst( (string) $this->id );
			}

			$this->method_title = $fallback;

			if ( isset( self::$method_title_reported[ static::class ] ) ) {
				return;
			}

			self::$method_title_reported[ static::class ] = true;

			_doing_it_wrong(
				static::class . '::$method_title',
				sprintf(
					'The shipping method "%1$s" did not set $method_title, so WooCommerce would show it nameless. Set $this->method_title (and $this->method_description) before calling parent::__construct(); "%2$s" is used for now.',
					esc_html( (string) $this->id ),
					esc_html( $fallback )
				),
				'2.0.2'
			);
		}

		/**
		 * Gets the title to use when the merchant has left the Title field empty.
		 *
		 * An empty title is exactly the symptom being fixed, so the fallback must not be
		 * empty either. {@see self::get_default_title()} covers the three delivery types;
		 * `$method_title` — the name the plugin registered the method under — covers a
		 * method whose type is none of them.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_title_fallback(): string {

			$fallback = $this->get_default_title();

			return '' !== $fallback ? $fallback : (string) $this->method_title;
		}

		public function init_form_fields() {

			$this->instance_form_fields = [

				'title'       => [
					'title'    => esc_html__( 'Title', 'woodev-plugin-framework' ),
					'type'     => 'text',
					'desc_tip' => esc_html__(
						'Shipping method title that the customer will see during checkout.',
						'woodev-plugin-framework'
					),
					'default'  => $this->get_default_title(),
				],

				'description' => [
					'title'       => esc_html__( 'Description', 'woodev-plugin-framework' ),
					'type'        => 'textarea',
					'desc_tip'    => esc_html__( 'This text is shown to the customer under the method name in the order form. Leave empty to hide it.', 'woodev-plugin-framework' ),
					'default'     => $this->get_default_description(),
					'css'         => 'max-width:400px;',
					'placeholder' => esc_attr__( 'Enter description here', 'woodev-plugin-framework' ),
				],
			];

			$this->instance_form_fields = array_merge( $this->instance_form_fields, $this->get_method_form_fields() );

			if ( $this->supports_shipping_classes() ) {

				$this->instance_form_fields['shipping_class_id'] = [
					'title'    => esc_html__( 'Shipping class', 'woodev-plugin-framework' ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => self::SHIPPING_CLASS_ANY,
					'options'  => $this->get_shipping_classes_options(),
					'desc_tip' => esc_html__( 'Select the shipping class that this method applies to.', 'woodev-plugin-framework' ),
				];
			}

			if ( $this->supports_box_packing() ) {

				$inherits_packing = $this->get_plugin()->uses_boxes();
				$packing_default = $inherits_packing ? 'default' : 'separately';
				$inherited_option = $inherits_packing ? [ 'default' => __( 'Как в настройках плагина', 'woodev-plugin-framework' ) ] : [];
				$this->instance_form_fields['packing_algorithm'] = [
					'title'    => esc_html__( 'Способ упаковки', 'woodev-plugin-framework' ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => $packing_default,
					'options'  => $inherited_option + Settings\Packaging_Settings::packing_options(),
					'desc_tip' => esc_html__( 'How cart items are combined into parcels before rate calculation.', 'woodev-plugin-framework' ),
				];
				$this->instance_form_fields['unpacked_algorithm'] = [
					'title' => __( 'Непоместившиеся товары', 'woodev-plugin-framework' ),
					'type' => 'select',
					'default' => $packing_default,
					'options' => $inherited_option + Settings\Packaging_Settings::leftover_options(),
					'desc_tip' => __( 'Как упаковывать товары, которые не поместились в коробки?', 'woodev-plugin-framework' ),
					'show_if' => [
						'setting' => 'packing_algorithm',
						'value' => 'boxes',
					],
				];
			}

			if ( $this->supports_fee_payments() ) {

				// the options are filled in when the control is rendered — see generate_multiselect_html()
				$this->instance_form_fields[ Fee_Payments::OPTION_KEY ] = [
					'title'             => __( 'Оплата с наценкой', 'woodev-plugin-framework' ),
					'type'              => 'multiselect',
					'class'             => 'wc-enhanced-select',
					'css'               => 'width: 400px;',
					'default'           => [],
					'options'           => [],
					'desc_tip'          => __( 'Наценка на доставку применяется только если покупатель выбрал один из этих способов оплаты. Оставьте поле пустым, чтобы наценка применялась всегда.', 'woodev-plugin-framework' ),
					'custom_attributes' => [
						'data-placeholder' => __( 'Любой способ оплаты', 'woodev-plugin-framework' ),
					],
				];
			}

			if ( $this->supports_insurance() ) {

				$this->instance_form_fields[ self::OPTION_INSURANCE ] = [
					'title'    => __( 'Учитывать страховку', 'woodev-plugin-framework' ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => $this->get_default_insurance_mode(),
					'options'  => [
						self::INSURANCE_NONE             => __( 'Нет', 'woodev-plugin-framework' ),
						self::INSURANCE_ALWAYS           => __( 'Всегда', 'woodev-plugin-framework' ),
						self::INSURANCE_DELIVERY_PAYMENT => __( 'Только при оплате при получении', 'woodev-plugin-framework' ),
					],
					'desc_tip' => __( 'Включать страховку в стоимость доставки. Объявленная стоимость — стоимость товаров в этой посылке после скидок, без налогов и стоимости доставки.', 'woodev-plugin-framework' ),
				];
			}

			if ( $this->supports_cost_limits() ) {

				$this->instance_form_fields[ self::OPTION_MIN_COST ] = [
					'title'       => __( 'Минимальная стоимость доставки', 'woodev-plugin-framework' ),
					'type'        => 'price',
					'default'     => '',
					'placeholder' => __( 'Не ограничено', 'woodev-plugin-framework' ),
					'desc_tip'    => __( 'Если стоимость, рассчитанная перевозчиком, окажется ниже этого значения, покупатель заплатит указанную сумму. Если указать одинаковые минимум и максимум, стоимость доставки будет фиксированной. Бесплатная доставка остаётся бесплатной.', 'woodev-plugin-framework' ),
				];

				$this->instance_form_fields[ self::OPTION_MAX_COST ] = [
					'title'       => __( 'Максимальная стоимость доставки', 'woodev-plugin-framework' ),
					'type'        => 'price',
					'default'     => '',
					'placeholder' => __( 'Не ограничено', 'woodev-plugin-framework' ),
					'desc_tip'    => __( 'Если стоимость, рассчитанная перевозчиком, окажется выше этого значения, покупатель заплатит указанную сумму. Оставьте поле пустым, чтобы не ограничивать стоимость.', 'woodev-plugin-framework' ),
				];
			}

			/**
			 * Shipping Method Instance Form Fields Filter.
			 *
			 * Allow actors to modify the instance form fields shown on the admin
			 * settings screen.
			 *
			 * A return that is not an array is discarded and the fields built above
			 * are kept instead — WooCommerce hands `$instance_form_fields` to
			 * `array_map()` when rendering the settings screen, and a non-array
			 * value there is a fatal `TypeError`.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the pre-filter fields
			 *              are kept instead of trusting the return's type.
			 *
			 * @param array $instance_form_fields the instance form fields built above
			 * @param Shipping_Method $method Method instance
			 */
			$this->building_form_fields = true;

			try {
				$filtered_form_fields = apply_filters( 'woodev_shipping_method_' . $this->get_id() . '_form_fields', $this->instance_form_fields, $this );
			} finally {
				$this->building_form_fields = false;
			}

			if ( is_array( $filtered_form_fields ) ) {
				$this->instance_form_fields = $filtered_form_fields;
			}

			/*
			 * A filter callback is allowed to declare a feature, and that has to survive the
			 * assignment above.
			 *
			 * `add_support()` rebuilds the form when the feature shapes it. Called from INSIDE
			 * this filter it would re-enter here, build the control correctly — and then the
			 * outer pass would return from `apply_filters()` holding the array as it looked
			 * BEFORE the feature existed and assign that straight over the top. The feature
			 * would end up declared with no control: the #813 defect reached from the other
			 * side. So `add_support()` defers to this flag instead of rebuilding under us, and
			 * the pass that is actually in charge redoes itself once, here, after assigning.
			 *
			 * This terminates. The second pass runs with the feature already in `supports`, so
			 * a callback that declares it again is a no-op in `add_support()` and sets nothing
			 * pending; and only five features shape the form at all, which bounds even a
			 * pathological callback that declares a different one each time.
			 */
			if ( $this->pending_form_rebuild ) {

				$this->pending_form_rebuild = false;

				$this->init_form_fields();
			}
		}

		/**
		 * Returns an array of form fields specific for this method.
		 *
		 * @since 1.5.0
		 * @return array of form fields
		 */
		abstract protected function get_method_form_fields(): array;

		/**
		 * Get the default shipping method title, which is configurable within the
		 * admin and displayed on checkout
		 *
		 * @since 1.5.0
		 * @return string shipping method title to show on checkout
		 */
		protected function get_default_title(): string {

			if ( $this->is_courier_shipping() ) {
				return esc_html__( 'Courier delivery', 'woodev-plugin-framework' );
			} elseif ( $this->is_pickup_shipping() ) {
				return esc_html__( 'Pickup delivery', 'woodev-plugin-framework' );
			} elseif ( $this->is_postal_shipping() ) {
				return esc_html__( 'Postal delivery', 'woodev-plugin-framework' );
			}

			return '';
		}


		/**
		 * Get the default shipping method description, which is configurable
		 * within the admin and displayed on checkout
		 *
		 * @since 1.5.0
		 * @return string shipping method description to show on checkout
		 */
		protected function get_default_description(): string {

			if ( $this->is_courier_shipping() ) {
				return esc_html__( 'Delivery by courier to customer address.', 'woodev-plugin-framework' );
			} elseif ( $this->is_pickup_shipping() ) {
				return esc_html__( 'Delivery to pickup point.', 'woodev-plugin-framework' );
			} elseif ( $this->is_postal_shipping() ) {
				return esc_html__( 'Delivery to postal office.', 'woodev-plugin-framework' );
			}

			return '';
		}

		/**
		 * Final calculate_shipping method - delegates to abstract calculate_rate()
		 *
		 * When the carrier API fails during rate calculation (any `Woodev_Plugin_Exception`,
		 * which covers `Woodev_API_Exception` and `Woodev_Packer_Exception`), the method is
		 * hidden — no rate is added, the exception never reaches the cart — the failure is
		 * logged, and the `woodev_shipping_method_rate_calculation_failed` action fires so a
		 * plugin can implement its own fallback. The framework builds no tariff fallback itself.
		 *
		 * @param array $package Package data
		 *
		 * @since 1.4.0
		 * @since 2.0.2 A carrier exception hides the method instead of reaching the cart, and fires
		 *              {@see 'woodev_shipping_method_rate_calculation_failed'}.
		 * @since 2.0.2 A method that declared {@see self::FEATURE_RATE_CACHE} has its successful rate cached
		 *              for a few minutes ({@see Shipping_Rate_Cache}, #958); a failure or an empty result
		 *              never is, and a method that did not declare it is never cached.
		 */
		final public function calculate_shipping( $package = [] ): void {

			/**
			 * Shipping Method Before Calculate Shipping Action.
			 *
			 * Triggered before shipping calculation begins.
			 *
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			do_action( 'woodev_shipping_method_before_calculate_shipping', $package, $this );

			if ( ! $this->should_send_cart_api_request() ) {
				return;
			}

			if ( ! $this->is_available_for_package( $package ) ) {
				return;
			}

			$this->before_calculate( $package );

			/**
			 * Shipping Method Pre-Calculate Rate Filter.
			 *
			 * Lets a cache layer short-circuit rate calculation by returning a
			 * previously stored rate for this package. Returning a non-null value
			 * skips the (potentially expensive, API-backed) calculate_rate() call;
			 * the resulting rate still passes through the
			 * `woodev_shipping_method_calculated_rate` filter below, where a cache
			 * can persist freshly computed rates.
			 *
			 * A return that is neither `null` nor a {@see Shipping_Rate} instance is
			 * discarded and treated as `null` — rate calculation proceeds normally
			 * rather than handing a malformed value to the code below, which is a
			 * fatal `Error` on a truthy non-`Shipping_Rate` return.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A return that is neither `null` nor a {@see Shipping_Rate}
			 *              is discarded instead of being trusted by truthiness.
			 *
			 * @param Shipping_Rate|null $rate Cached rate to use, or null to calculate normally
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			$pre_calculated_rate = apply_filters( 'woodev_shipping_method_pre_calculate_rate', null, $package, $this );

			$rate = $pre_calculated_rate instanceof Shipping_Rate ? $pre_calculated_rate : null;

			// A rate a pre-filter supplied is its owner's business; the framework cache only fronts the carrier
			// call, and only for a method that opted in (FEATURE_RATE_CACHE) — a method that did not never
			// touches a transient.
			$rate_cache = null === $rate && $this->supports_rate_cache() ? new Shipping_Rate_Cache() : null;

			if ( null !== $rate_cache ) {
				$rate = $rate_cache->get( $this, $package );
			}

			if ( null === $rate ) {
				try {
					$rate = $this->calculate_rate( $package );

					// Only a calculation that returned a rate is kept — a failure (below) and a «no rate» answer never are.
					if ( null !== $rate_cache && null !== $rate ) {
						$rate_cache->put( $this, $package, $rate );
					}
				} catch ( \Woodev_Plugin_Exception $exception ) {
					$rate = null;

					$this->handle_rate_calculation_failure( $exception, $package );
				}
			}

			/**
			 * Shipping Method Rate Filter.
			 *
			 * Allow actors to modify the calculated rate before it's added.
			 *
			 * A return that is neither `null` nor a {@see Shipping_Rate} instance is
			 * discarded and the pre-filter rate is kept, so a misbehaving actor
			 * degrades to "no rate added" (if the pre-filter rate was also null)
			 * rather than fatalling while a customer is calculating shipping.
			 *
			 * @since 2.0.2 A return that is neither `null` nor a {@see Shipping_Rate}
			 *              is discarded; the pre-filter rate is kept instead of
			 *              trusting the return by truthiness.
			 *
			 * @param Shipping_Rate|null $rate Calculated rate or null
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			$filtered_rate = apply_filters( 'woodev_shipping_method_calculated_rate', $rate, $package, $this );

			if ( null === $filtered_rate || $filtered_rate instanceof Shipping_Rate ) {
				$rate = $filtered_rate;
			}

			if ( $rate ) {

				$rate = $this->guard_rate_label( $rate );

				/**
				 * Shipping Method Before Add Rate Action.
				 *
				 * Triggered before a rate is added.
				 *
				 * @param Shipping_Rate $rate Rate object
				 * @param array $package Package data
				 * @param Shipping_Method $method Method instance
				 */
				do_action( 'woodev_shipping_method_before_add_rate', $rate, $package, $this );

				// Convert Shipping_Rate object to array for WC compatibility
				$this->add_rate( $rate->to_array() );

				$this->apply_rate_attributes( $rate );

				/**
				 * Shipping Method After Add Rate Action.
				 *
				 * Triggered after a rate is added.
				 *
				 * @param Shipping_Rate $rate Rate object
				 * @param array $package Package data
				 * @param Shipping_Method $method Method instance
				 */
				do_action( 'woodev_shipping_method_after_add_rate', $rate, $package, $this );
			}

			$this->after_calculate( $package, $rate );

			/**
			 * Shipping Method After Calculate Shipping Action.
			 *
			 * Triggered after shipping calculation completes.
			 *
			 * @param array $package Package data
			 * @param Shipping_Rate|null $rate Calculated rate or null
			 * @param Shipping_Method $method Method instance
			 */
			do_action( 'woodev_shipping_method_after_calculate_shipping', $package, $rate, $this );
		}

		/**
		 * Prices `$package` on behalf of the admin order wizard and returns the rates this method
		 * offers for it (#965, spec `2026-09-27-710-create-edit-order-design.md` D2).
		 *
		 * The wizard's route runs in an admin REST request, where `WC()->session`, `WC()->cart` and
		 * `WC()->customer` are all null and {@see self::should_send_cart_api_request()} vetoes every
		 * carrier call — `calculate_shipping()` is `final` and that veto is private, so this is the
		 * one seam that lifts it. The lift is narrow: this instance, this call, restored in a
		 * `finally`. The method still goes through its own `calculate_shipping()` — availability,
		 * `woodev_shipping_method_*` hooks, the rate filters, label guard, rate attributes and the
		 * carrier-failure handling all run — so the rates, and their meta, are exactly the ones a
		 * checkout would get.
		 *
		 * `$record` is the destination location record. It is put in force on the plugin's
		 * {@see Location\Location_Service} for the call ({@see Location\Location_Service::with_explicit_record()}),
		 * so carrier rate code that reads the customer's location gets the destination instead of
		 * the ADMIN's own stored one (spec D2 «Mine 3»). `null` means «no destination record».
		 *
		 * Does not touch WooCommerce's per-package session rate cache: that lives in
		 * `WC_Shipping::calculate_shipping_for_package()`, which this never calls (it fatals without
		 * a session; spec D2 «Mine 2»).
		 *
		 * @since 2.0.2
		 *
		 * @param array                $package WooCommerce shipping package (`contents`, `destination`, …).
		 * @param Location_Record|null $record  Destination location record, or `null` for none.
		 *
		 * @return array<string, \WC_Shipping_Rate> Rates keyed by rate id; empty when the method is
		 *                                          unavailable for the package or the carrier failed.
		 */
		public function get_admin_rates_for_package( array $package, ?Location_Record $record = null ): array {

			$previous                     = $this->admin_rate_calculation;
			$this->admin_rate_calculation = true;

			try {
				return (array) $this->get_plugin()->get_location_service()->with_explicit_record(
					$record,
					function () use ( $package ): array {
						return (array) $this->get_rates_for_package( $package );
					}
				);
			} finally {
				$this->admin_rate_calculation = $previous;
			}
		}

		/**
		 * Substitutes the method's own title for an empty rate label.
		 *
		 * `WC_Shipping_Method::add_rate()` returns early — silently — when the label is
		 * empty, which takes the method off the checkout with no fatal and no log line.
		 * An empty label is not a programming error worth throwing over on the shipping
		 * calculation path (#766): it is what a merchant produces by clearing the Title
		 * field. `get_title()` is a real value here since #768 gave the title a fallback,
		 * so the customer sees a named rate instead of no rate at all.
		 *
		 * ⚠ `get_title()` is `apply_filters( 'woocommerce_shipping_method_title', ... )`
		 * with no type guard of its own, so its return is a THIRD PARTY'S value on the
		 * checkout path. A non-string return is discarded and the rate is left as it was:
		 * casting it would fatal on an object, which is the failure this guard exists to
		 * prevent in the first place.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate the calculated rate
		 *
		 * @return Shipping_Rate the same rate, or a copy carrying the method's title
		 */
		private function guard_rate_label( Shipping_Rate $rate ): Shipping_Rate {

			if ( '' !== trim( $rate->get_label() ) ) {
				return $rate;
			}

			$title = $this->get_title();

			if ( ! is_string( $title ) || '' === trim( $title ) ) {
				return $rate;
			}

			return $rate->with_label( $title );
		}

		/**
		 * Applies the rate attributes `add_rate()` does not accept.
		 *
		 * `add_rate()` sets id, method id, instance id, label, cost, taxes and tax status
		 * on the `WC_Shipping_Rate` it builds — and stops there. `description` and
		 * `delivery_time` exist on that object (WooCommerce 9.2.0+) and are published per
		 * rate by the Store API, so they can only be applied to the object afterwards.
		 *
		 * The rate's own value wins; the merchant's `description` option is the default
		 * beneath it, which is what finally gives that settings field an effect (#768).
		 *
		 * Both setters are probed with `method_exists()` rather than assumed: the
		 * framework supports WooCommerce from 7.0, where neither exists.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate the rate just handed to {@see \WC_Shipping_Method::add_rate()}
		 */
		private function apply_rate_attributes( Shipping_Rate $rate ): void {

			$wc_rate = $this->rates[ $rate->get_id() ] ?? null;

			if ( ! $wc_rate instanceof \WC_Shipping_Rate ) {
				return;
			}

			$attributes = $rate->get_post_add_rate_attributes();

			$description = (string) ( $attributes['description'] ?? '' );

			if ( '' === $description ) {
				$description = (string) $this->get_option( 'description', '' );
			}

			if ( '' !== $description && method_exists( $wc_rate, 'set_description' ) ) {
				$wc_rate->set_description( $description );
			}

			$delivery_time = (string) ( $attributes['delivery_time'] ?? '' );

			if ( '' !== $delivery_time && method_exists( $wc_rate, 'set_delivery_time' ) ) {
				$wc_rate->set_delivery_time( $delivery_time );
			}
		}

		/**
		 * Calculates the shipping rate for the package.
		 *
		 * Template method: when this method opts into {@see self::FEATURE_BOX_PACKING},
		 * the cart contents are packed into parcels via {@see self::pack_package()} and
		 * the (nullable) result is handed to {@see self::rate_package()}. Methods that do
		 * not support box-packing receive a null packed result. This method is final
		 * so the packing wiring cannot be bypassed — implement {@see self::rate_package()}
		 * for carrier-specific rating, or hook {@see 'woodev_shipping_method_pre_calculate_rate'}
		 * to short-circuit (e.g. a cache layer).
		 *
		 * @since 1.4.0
		 * @since 2.0.0 Now a final template; carrier logic moved to {@see self::rate_package()}.
		 * @since 2.0.2 Runs under the `rates` request purpose (#954): every API call the rating
		 *              makes gets the short rates timeout. A rate call is never retried.
		 *
		 * @param array $package Package data.
		 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
		 */
		final protected function calculate_rate( array $package ): ?Shipping_Rate {

			// A customer is waiting for this answer, so every API call the rating makes gets the short «rates» timeout
			// (#954). The scope is reset in a `finally`: a failed rate call must not leave it behind for the next call.
			return \Woodev_API_Request_Purpose::run(
				\Woodev_API_Request_Purpose::RATES,
				function () use ( $package ): ?Shipping_Rate {

					$packed = $this->supports_box_packing()
						? $this->pack_package( $package )
						: null;

					$rate = $this->rate_package( $package, $packed );

					// «free» is the carrier's decision (a free-from threshold, a coupon), so it is read from the rate
					// BEFORE the box cost is added: a 0 rate with a 25 box surcharge is still a free rate
					$carrier_free = null !== $rate && $this->supports_cost_limits() && self::rate_cost_total( $rate ) <= 0;

					if ( null !== $rate && null !== $packed ) {
						$extra = Packaging::get_cost( $packed, (array) ( $package['contents'] ?? [] ) );
						if ( $extra > 0 ) {
							$cost = $rate->get_cost();
							if ( is_array( $cost ) ) {
								$cost['woodev_packaging'] = ( $cost['woodev_packaging'] ?? 0 ) + $extra;
							} else {
								$cost = (float) $cost + $extra;
							}
							$rate = $rate->with_cost( $cost );
						}
					}

					// last: the limits bound what the customer pays, so they come after the fee (inside rate_package())
					// and the packaging cost; a rate the carrier made free is never raised to the minimum
					if ( null !== $rate && $this->supports_cost_limits() ) {
						$rate = $this->limit_rate_cost( $rate, $carrier_free );
					}

					return $rate;
				}
			);
		}

		/**
		 * Produces the shipping rate for a package.
		 *
		 * Implemented by concrete shipping methods. When this method supports
		 * {@see self::FEATURE_BOX_PACKING} and the cart has physical contents, $packed
		 * carries the parcels produced by the configured packing algorithm; the carrier
		 * decides how to quote them (typically one multi-place request, not a sum of
		 * per-parcel prices). $packed is null when this method does not support
		 * box-packing, there is nothing physical to pack (e.g. a virtual-only cart),
		 * or the WooCommerce-aware packer is unavailable; rate without dimensional
		 * data in that case.
		 *
		 * @since 2.0.0
		 *
		 * @param array                      $package Package data.
		 * @param \Woodev_Packer_Result|null $packed  Packed parcels, or null (see above).
		 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
		 */
		abstract protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate;

		/**
		 * Check if method is available for package.
		 *
		 * @param array $package Package data
		 *
		 * @return bool
		 * @since 1.4.0
		 */
		protected function is_available_for_package( array $package ): bool {

			$is_available = true;

			if ( $this->get_plugin()->get_accepted_countries() ) {

				$country_code = Shipping_Helper::get_package_country( $package );

				if ( ! empty( $country_code ) && ! in_array( $country_code, $this->get_plugin()->get_accepted_countries() ) ) {

					$is_available = false;

					$this->get_plugin()->log_debug(
						sprintf( 'The shipping method %s is not available for country %s', $this->get_title(), $country_code ),
						sprintf( '%s_%s', $this->get_plugin()->get_id(), $this->get_id() )
					);
				}
			}

			if ( $this->supports_shipping_classes() && ! $this->has_only_selected_shipping_class( $package ) ) {

				$is_available = false;

				$this->get_plugin()->log_debug(
					sprintf( 'Shipping cost calculation for the "%s" method was stopped because the cart contains items that do not match the selected shipping class.', $this->get_title() ),
					sprintf( '%s_%s', $this->get_plugin()->get_id(), $this->get_id() )
				);
			}

			/**
			 * Shipping Method Is Available Filter.
			 *
			 * Allow actors to modify method availability for a package.
			 *
			 * A return that is not a `bool` is discarded and the pre-filter
			 * availability is kept instead — this method's `bool` return type
			 * makes any other return a fatal `TypeError` while a customer is at
			 * checkout.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-`bool` return is discarded; the pre-filter
			 *              availability is kept instead of trusting the return's
			 *              type.
			 *
			 * @param bool $is_available Whether method is available
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			$filtered_is_available = apply_filters( 'woodev_shipping_' . $this->id . '_is_available', $is_available, $package, $this );

			return is_bool( $filtered_is_available ) ? $filtered_is_available : $is_available;
		}

		/**
		 * Hook called before calculate_rate().
		 *
		 * @param array $package Package data
		 *
		 * @since 1.4.0
		 */
		protected function before_calculate( array $package ): void {}

		/**
		 * Hook called after calculate_rate().
		 *
		 * @param array              $package Package data
		 * @param Shipping_Rate|null $rate Calculated rate or null
		 *
		 * @since 1.4.0
		 */
		protected function after_calculate( array $package, ?Shipping_Rate $rate ): void {}

		/**
		 * Packs the package contents into parcels using the configured algorithm.
		 *
		 * Converts the WooCommerce package's cart contents into packer input
		 * items via {@see \Woodev_WC_Packer_Dispatcher::from_cart_items()} and
		 * runs the algorithm selected in this method's settings (falling back to
		 * the virtual minimal box). Returns null when there is nothing physical
		 * to pack or the WooCommerce-aware dispatcher is unavailable, so callers
		 * can skip dimensional rate logic without catching exceptions.
		 *
		 * This is the seam a carrier overrides to customize parcels: both the rate and
		 * {@see self::pack_order()} go through it.
		 *
		 * @since 2.0.0
		 *
		 * @param array $package WooCommerce shipping package (expects a 'contents' array of cart items).
		 * @return \Woodev_Packer_Result|null packed result, or null when nothing is packable
		 */
		protected function pack_package( array $package ): ?\Woodev_Packer_Result {

			if ( ! class_exists( '\\Woodev_WC_Packer_Dispatcher' ) ) {
				return null;
			}

			$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : [];

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items( $contents );

			if ( [] === $items ) {
				return null;
			}

			$algorithm = $this->get_packing_algorithm();
			if ( \Woodev_Packer_Dispatcher::ALGORITHM_BOXES !== $algorithm ) {
				return \Woodev_WC_Packer_Dispatcher::pack( $algorithm, $items );
			}
			$boxes = array_merge( \Woodev_WC_Packer_Dispatcher::get_store_boxes(), Packaging::to_boxes( $this->get_plugin()->get_packaging_settings()->get_boxes() ) );
			return \Woodev_WC_Packer_Dispatcher::pack( $algorithm, $items, $boxes, $this->get_unpacked_algorithm() );
		}

		/**
		 * Packs an ORDER into parcels with the same packer the rate was calculated with.
		 *
		 * The packing of a carrier order is recomputed at export, not stored at checkout (#948): the
		 * order is turned into a WooCommerce-shaped package (see
		 * {@see \Woodev_WC_Packer_Dispatcher::order_to_cart_contents()} — lines that need no shipping,
		 * deleted products and fully refunded lines are left out as a cart leaves them out, refunded
		 * units are not counted) and handed to {@see self::pack_package()}, the very seam the rate
		 * packs through. The algorithm, the default dimensions (#955) and any carrier override of
		 * `pack_package()` therefore apply to the export exactly as to the rate. As at rate time, a
		 * method that has not opted into {@see self::FEATURE_BOX_PACKING} gets null.
		 *
		 * Call path from an export: find the order's shipping line with
		 * {@see Shipping_Helper::get_order_shipping_item()}, resolve the instance with
		 * `\WC_Shipping_Zones::get_shipping_method( $line->get_instance_id() )` (false when the zone no
		 * longer has it) and call this on the result. The handler cannot do that for a plugin — it does
		 * not know which of the order's lines is the plugin's — so the plugin's own export code does.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order about to be exported
		 * @return \Woodev_Packer_Result|null packed result, or null when nothing is packable or packing is not supported
		 */
		public function pack_order( \WC_Order $order ): ?\Woodev_Packer_Result {

			if ( ! $this->supports_box_packing() || ! class_exists( '\\Woodev_WC_Packer_Dispatcher' ) ) {
				return null;
			}

			return $this->pack_package( $this->build_order_package( $order ) );
		}

		/**
		 * Builds the WooCommerce-shaped shipping package of an order.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order about to be exported
		 * @return array<string, mixed> package: `contents` of the order's shippable lines, the order's shipping `destination`
		 */
		private function build_order_package( \WC_Order $order ): array {

			return [
				'contents'    => \Woodev_WC_Packer_Dispatcher::order_to_cart_contents( $order ),
				'destination' => [
					'country'   => (string) $order->get_shipping_country(),
					'state'     => (string) $order->get_shipping_state(),
					'postcode'  => (string) $order->get_shipping_postcode(),
					'city'      => (string) $order->get_shipping_city(),
					'address'   => (string) $order->get_shipping_address_1(),
					'address_1' => (string) $order->get_shipping_address_1(),
					'address_2' => (string) $order->get_shipping_address_2(),
				],
			];
		}

		/**
		 * Gets the packing algorithm configured for this method.
		 *
		 * Falls back to the virtual minimal box when the stored value is not a
		 * registered algorithm, so an out-of-range option can never reach the
		 * dispatcher (which would otherwise throw).
		 *
		 * @since 2.0.0
		 *
		 * @return string one of the \Woodev_Packer_Dispatcher::ALGORITHM_* constants
		 */
		protected function get_packing_algorithm(): string {

			$algorithm = (string) $this->get_option( 'packing_algorithm', 'default' );
			if ( '' === $algorithm || 'default' === $algorithm ) {
				$algorithm = $this->get_plugin()->get_packaging_settings()->get_default_algorithm( 'packing_algorithm' );
			}

			return array_key_exists( $algorithm, \Woodev_Packer_Dispatcher::get_algorithms() )
				? $algorithm
				: \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL;
		}

		/**
		 * Effective handling for items fitting no enabled box.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		protected function get_unpacked_algorithm(): string {
			$value = (string) $this->get_option( 'unpacked_algorithm', 'default' );
			if ( '' === $value || 'default' === $value ) {
				$value = $this->get_plugin()->get_packaging_settings()->get_default_algorithm( 'unpacked_algorithm' );
			}
			return 'single' === $value ? 'single' : 'separately';
		}

		/**
		 * Adds the effective carrier default to the conditional leftovers control in WC zone forms.
		 *
		 * @since 2.0.2
		 * @param string $key field key.
		 * @param array  $data field definition.
		 * @return string
		 */
		public function generate_select_html( $key, $data = [] ): string {
			if ( 'packing_algorithm' === $key && 'virtual' === $this->get_option( 'packing_algorithm', 'default' ) ) {
				$data['options']['virtual'] = __( 'Минимальная коробка', 'woodev-plugin-framework' );
			}
			if ( 'unpacked_algorithm' === $key ) {
				$data['custom_attributes']['data-woodev-packing-default'] = $this->get_plugin()->get_packaging_settings()->get_default_algorithm( 'packing_algorithm' );
			}
			return parent::generate_select_html( $key, $data );
		}

		/**
		 * Fills the `fee_payments` control with the store's enabled payment gateways when it is rendered.
		 *
		 * Done at render time, not when the form is built: the form is built on every request that
		 * constructs the method, and loading every gateway there would be paid by the storefront for
		 * a control only the settings screen shows. A gateway that is already chosen stays in the list
		 * even if it has since been switched off, so saving the screen does not drop it silently.
		 *
		 * @since 2.0.2
		 *
		 * Exactly WooCommerce's signature — `( $key, $data )`, no return type, no default: a carrier that
		 * overrides this method copying WooCommerce's signature must stay compatible. A default on `$data`
		 * made the CDEK override `( $key, $data ): string` a fatal (s159); a return type would do the same to
		 * the untyped v1 carrier overrides counted by the signature probe (#767).
		 *
		 * @param string $key  field key.
		 * @param array  $data field definition.
		 * @return string
		 */
		public function generate_multiselect_html( $key, $data ) {

			if ( Fee_Payments::OPTION_KEY === $key ) {
				$data['options'] = Fee_Payments::gateway_options( $this->get_fee_payments() );
			}

			return parent::generate_multiselect_html( $key, $data );
		}

		/**
		 * Gets the plugin instance that owns this shipping method.
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Plugin
		 */
		abstract protected function get_plugin(): Shipping_Plugin;

		/**
		 * Gets the order meta prefixed used for the *_order_meta() methods
		 *
		 * Defaults to `_wc_{gateway_id}_`
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_order_meta_prefix(): string {
			return sprintf( '_woodev_%s_', $this->get_id() );
		}

		/**
		 * Gets the method ID (for compatibility with WC_Shipping_Method).
		 *
		 * @return string
		 * @since 1.4.0
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Returns the shipping method id with dashes in place of underscores, and
		 * appropriate for use in frontend element names, classes and ids
		 *
		 * @since 1.5.0
		 * @return string shipping method id with dashes in place of underscores
		 */
		public function get_id_dasherized(): string {
			return str_replace( '_', '-', $this->get_id() );
		}

		public function get_id_underscored() {
			return str_replace( '-', '_', $this->get_id() );
		}

		public function is_courier_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_COURIER;
		}

		public function is_pickup_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_PICKUP;
		}

		public function is_postal_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_POSTAL;
		}

		/**
		 * Gets this method's pickup-point source, if it is a pickup method.
		 *
		 * Accessor seam: lets shared subsystems — the pickup REST controller (forthcoming) —
		 * reach a method's normalizing {@see Point_Source} without knowing the concrete
		 * method class. The base method has no source and returns null; a pickup method
		 * overrides this to expose its {@see Point_Source}.
		 *
		 * @since 1.5.0
		 *
		 * @return Point_Source|null the pickup-point source, or null for non-pickup methods
		 */
		public function get_pickup_point_source(): ?Point_Source {
			return null;
		}

		/**
		 * Determines whether a cart API request should be sent based on the current context.
		 *
		 * This method evaluates the execution environment and vetoes the carrier call in an admin
		 * context, during a REST API call, or via XML-RPC — with one exception: a Store API request
		 * (`/wc/store/…`, what the block cart and checkout use to price shipping) is the customer's
		 * own cart calculation and must get rates, exactly like a classic checkout.
		 *
		 * @since 2.0.2 A Store API request is no longer vetoed by the REST guard.
		 * @since 2.0.2 The admin order wizard's rate calculator ({@see self::get_admin_rates_for_package()})
		 *              is not vetoed either — for the duration of its own call on this instance only (#965).
		 *
		 * @return bool True if a cart API request should be sent, false otherwise.
		 */
		private function should_send_cart_api_request(): bool {

			if ( $this->admin_rate_calculation ) {
				return true;
			}

			if ( defined( 'XMLRPC_REQUEST' ) || ( is_admin() && did_action( 'woocommerce_cart_loaded_from_session' ) ) ) {
				return false;
			}

			if ( defined( 'REST_REQUEST' ) || defined( 'REST_API_REQUEST' ) ) {
				return $this->is_store_api_request();
			}

			return true;
		}

		/**
		 * Whether the current request is a WooCommerce Store API request.
		 *
		 * Prefers WooCommerce's own detection. `WC()->is_store_api_request()` only exists from
		 * WooCommerce 9.0, so on the older versions this framework still supports (7.0+) the fallback
		 * mirrors what WooCommerce itself does, for both permalink structures: the request path
		 * against `rest_get_url_prefix()` + `/wc/store/` (pretty permalinks, `/wp-json/wc/store/v1/…`)
		 * and the `rest_route` parameter (plain permalinks, `?rest_route=/wc/store/v1/…`, or the
		 * route WordPress itself dispatched).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		private function is_store_api_request(): bool {

			$woocommerce = function_exists( 'WC' ) ? WC() : null;

			if ( is_object( $woocommerce ) && method_exists( $woocommerce, 'is_store_api_request' ) ) {
				return (bool) $woocommerce->is_store_api_request();
			}

			// Pretty permalinks: match the path only (the leading slash anchors the prefix), so a query
			// argument that merely looks like a Store API URL is not mistaken for one.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$path        = '' !== $request_uri ? wp_parse_url( '/' . ltrim( $request_uri, '/' ), PHP_URL_PATH ) : null;

			if ( is_string( $path ) && false !== strpos( $path, '/' . trailingslashit( rest_get_url_prefix() ) . 'wc/store/' ) ) {
				return true;
			}

			// Plain permalinks: the route travels as a parameter. WordPress copies it into query_vars for
			// both structures, so that is checked too.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the route only, no state change.
			$routes = [ $GLOBALS['wp']->query_vars['rest_route'] ?? '', $_GET['rest_route'] ?? '' ];

			foreach ( $routes as $route ) {
				if ( is_string( $route ) && 0 === strpos( '/' . ltrim( rawurldecode( wp_unslash( $route ) ), '/' ), '/wc/store/' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Hides the method after the carrier failed during rate calculation: logs the failure and fires
		 * the failure action. No rate is added and nothing reaches the cart.
		 *
		 * The exception text is foreign (the carrier's own wording, possibly with a request URL or a
		 * credential) — it goes to the log only, redacted, and is never rendered on any screen.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Plugin_Exception $exception The failure thrown by the carrier API or the packer.
		 * @param array                    $package   Package data.
		 * @return void
		 */
		private function handle_rate_calculation_failure( \Woodev_Plugin_Exception $exception, array $package ): void {

			$this->get_plugin()->log_error(
				sprintf(
					'Rate calculation failed for the "%s" method, the method is hidden (%s): %s',
					$this->get_title(),
					get_class( $exception ),
					\Woodev_API_Base::redact_secret_log_text( $exception->getMessage() )
				),
				sprintf( '%s_%s', $this->get_plugin()->get_id(), $this->get_id() )
			);

			/**
			 * Shipping Method Rate Calculation Failed Action.
			 *
			 * Fires when the carrier API (or the packer) throws while a rate is being calculated. The
			 * method has already been hidden for this package — no rate is added and the exception is
			 * not rethrown — so a plugin or site owner can hook this to implement its own fallback
			 * (for example, adding a flat rate). The framework builds no tariff fallback of its own.
			 *
			 * Do not render `$exception->getMessage()` on a screen: it is the carrier's own text.
			 *
			 * @since 2.0.2
			 *
			 * @param \Woodev_Plugin_Exception $exception The thrown exception.
			 * @param array                    $package   Package data.
			 * @param Shipping_Method          $method    Method instance.
			 */
			do_action( 'woodev_shipping_method_rate_calculation_failed', $exception, $package, $this );
		}

		protected function get_shipping_classes_options(): array {

			$shipping_classes = WC()->shipping()->get_shipping_classes();

			$options = [
				self::SHIPPING_CLASS_ANY  => __( 'Any shipping class', 'woodev-plugin-framework' ),
				self::SHIPPING_CLASS_NONE => __( 'No shipping class', 'woodev-plugin-framework' ),
			];

			if ( ! empty( $shipping_classes ) ) {
				$options += wp_list_pluck( $shipping_classes, 'name', 'term_id' );
			}

			return $options;
		}

		protected function has_only_selected_shipping_class( $package ): bool {

			$shipping_class_id = $this->get_option( 'shipping_class_id', self::SHIPPING_CLASS_ANY );

			if ( self::SHIPPING_CLASS_ANY === $shipping_class_id ) {
				return true;
			}

			foreach ( $package['contents'] as $values ) {
				/** @var \WC_Product $product */
				$product = $values['data'];
				$qty     = $values['quantity'];

				if ( $qty > 0 && $product->needs_shipping() ) {

					$product_class_id = absint( $product->get_shipping_class_id() );

					if ( $shipping_class_id === self::SHIPPING_CLASS_NONE && $product_class_id !== 0 ) {
						return false;
					} elseif ( $product_class_id !== (int) $shipping_class_id ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Determines whether this method combines package contents into parcels before rating.
		 *
		 * Named predicate over {@see self::FEATURE_BOX_PACKING}: one point of change and a
		 * self-documenting capability surface (the convention from Woodev_Payment_Gateway's
		 * supports_*() wrappers).
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function supports_box_packing(): bool {
			return $this->supports( self::FEATURE_BOX_PACKING );
		}

		/**
		 * Determines whether this method is gated by a configured WooCommerce shipping class.
		 *
		 * Named predicate over {@see self::FEATURE_SHIPPING_CLASSES}.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function supports_shipping_classes(): bool {
			return $this->supports( self::FEATURE_SHIPPING_CLASSES );
		}

		/**
		 * Determines whether this method opted in to the rate cache.
		 *
		 * Named predicate over {@see self::FEATURE_RATE_CACHE}. Declare it with `add_support()` only
		 * once {@see self::get_rate_cache_context()} covers every input the rate depends on.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_rate_cache(): bool {
			return $this->supports( self::FEATURE_RATE_CACHE );
		}

		/**
		 * Determines whether this method restricts its fee to chosen payment methods (#1144).
		 *
		 * Named predicate over {@see self::FEATURE_FEE_PAYMENTS}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_fee_payments(): bool {
			return $this->supports( self::FEATURE_FEE_PAYMENTS );
		}

		/**
		 * The payment gateway ids the merchant limited the fee to; empty means «every payment method».
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_fee_payments(): array {
			return Fee_Payments::normalize( $this->get_option( Fee_Payments::OPTION_KEY, [] ) );
		}

		/**
		 * Whether the fee applies to `$package`, given the payment method the customer chose.
		 *
		 * The rule is the v1 CDEK plugin's (2.2.5.5, `class-wc-edostavka-shipping-method.php:883`):
		 *
		 * - a method that did not declare {@see self::FEATURE_FEE_PAYMENTS}, or an empty list → the fee
		 *   applies, always;
		 * - a list → the fee applies only when the chosen payment method is in it, so while NO method is
		 *   chosen yet (the cart page, a first visit to the order form before WooCommerce saved one) it
		 *   does not.
		 *
		 * The chosen method is read from the package, where {@see Fee_Payments} puts it, and from the
		 * session when the package carries none — the same two places, in the same order, that key the
		 * rate cache ({@see self::get_rate_cache_context()}), so a cached rate and the fee in it agree.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return bool
		 */
		public function fee_applies_for_package( array $package ): bool {

			if ( ! $this->supports_fee_payments() ) {
				return true;
			}

			$allowed = $this->get_fee_payments();

			if ( [] === $allowed ) {
				return true;
			}

			$chosen = $this->payment_method_for_package( $package );

			return '' !== $chosen && in_array( $chosen, $allowed, true );
		}

		/**
		 * {@see Shipping_Helper::apply_fee()} behind {@see self::fee_applies_for_package()}: the cost
		 * comes back untouched when the chosen payment method is outside the merchant's list.
		 *
		 * @since 2.0.2
		 *
		 * @param float  $cost             base shipping cost.
		 * @param string $fee              fee value (e.g. `250` or `5%`).
		 * @param float  $base_for_percent base amount for a percentage fee.
		 * @param array  $package          WooCommerce shipping package.
		 * @return float
		 */
		protected function apply_fee_for_package( float $cost, string $fee, float $base_for_percent, array $package ): float {
			return $this->fee_applies_for_package( $package ) ? Shipping_Helper::apply_fee( $cost, $fee, $base_for_percent ) : $cost;
		}

		/**
		 * Determines whether this method limits its calculated cost to a merchant-set range.
		 *
		 * Named predicate over {@see self::FEATURE_COST_LIMITS}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_cost_limits(): bool {
			return $this->supports( self::FEATURE_COST_LIMITS );
		}

		/**
		 * The lowest cost the customer may pay, or `null` for «no limit» (also for a method that did not
		 * declare {@see self::FEATURE_COST_LIMITS}).
		 *
		 * @since 2.0.2
		 *
		 * @return float|null
		 */
		public function get_min_cost(): ?float {
			return $this->supports_cost_limits() ? Shipping_Helper::normalize_cost_limit( $this->get_option( self::OPTION_MIN_COST, '' ) ) : null;
		}

		/**
		 * The highest cost the customer may pay, or `null` for «no limit» (also for a method that did not
		 * declare {@see self::FEATURE_COST_LIMITS}).
		 *
		 * @since 2.0.2
		 *
		 * @return float|null
		 */
		public function get_max_cost(): ?float {
			return $this->supports_cost_limits() ? Shipping_Helper::normalize_cost_limit( $this->get_option( self::OPTION_MAX_COST, '' ) ) : null;
		}

		/**
		 * Limits a calculated cost to the merchant's range.
		 *
		 * The framework already runs this over every rate of a method that declared
		 * {@see self::FEATURE_COST_LIMITS}, as the LAST step of {@see self::calculate_rate()} — after the
		 * carrier's `rate_package()` (so after its fee, {@see self::apply_fee_for_package()}, and after its
		 * free-shipping rule) and after the box-packing cost. A carrier therefore calls this only if it
		 * needs the limited number earlier, for example to compare it with something; calling it twice is
		 * harmless, the result does not change.
		 *
		 * A cost of `0` or below is returned as is: a free rate stays free, the minimum does not make it paid.
		 *
		 * @since 2.0.2
		 *
		 * @param float $cost calculated cost.
		 * @return float
		 */
		public function apply_cost_limits( float $cost ): float {
			return Shipping_Helper::limit_cost( $cost, $this->get_min_cost(), $this->get_max_cost() );
		}

		/**
		 * The total cost of a rate, whatever shape its cost has (a number, or a per-item cost array).
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate rate.
		 * @return float `0.0` for a cost that is not numeric.
		 */
		private static function rate_cost_total( Shipping_Rate $rate ): float {

			$cost = $rate->get_cost();

			if ( is_array( $cost ) ) {
				return (float) array_sum( array_filter( $cost, 'is_numeric' ) );
			}

			return is_numeric( $cost ) ? (float) $cost : 0.0;
		}

		/**
		 * Applies the cost limits to a rate, whatever shape its cost has.
		 *
		 * **What is clamped.** The FINAL price: the carrier's price, its fee and the box-packing cost together
		 * (the number the customer sees), so «minimum = maximum» is exactly the price paid. **What «free» means.**
		 * Whether the rate is free is decided from the carrier's own price, BEFORE packaging is added: a carrier
		 * price of 0 (a «free from» threshold, a free-shipping coupon) is never raised to the minimum, even when a
		 * box cost makes the final price positive; the box cost stays as the only charge. The maximum still bounds
		 * a free rate's final price. A rate the carrier priced above 0 is held to both limits.
		 *
		 * A per-item cost array (WooCommerce taxes each entry by its own class) is scaled proportionally to
		 * the limited total, so the mix of the entries is kept. The limits are on the rate cost BEFORE tax; an
		 * explicit `taxes` array a carrier passed in the rate args is not recomputed.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate         calculated rate (carrier price + fee + box cost).
		 * @param bool          $carrier_free whether the carrier priced the rate at 0 before packaging was added.
		 * @return Shipping_Rate
		 */
		private function limit_rate_cost( Shipping_Rate $rate, bool $carrier_free = false ): Shipping_Rate {

			$cost  = $rate->get_cost();
			$total = self::rate_cost_total( $rate );

			if ( ! is_array( $cost ) && ! is_numeric( $cost ) ) {
				return $rate;
			}

			$limited = Shipping_Helper::limit_cost( $total, $carrier_free ? null : $this->get_min_cost(), $this->get_max_cost() );

			if ( $limited === $total ) {
				return $rate;
			}

			if ( ! is_array( $cost ) ) {
				return $rate->with_cost( $limited );
			}

			$factor = $limited / $total;

			foreach ( $cost as $key => $part ) {
				if ( is_numeric( $part ) ) {
					$cost[ $key ] = (float) $part * $factor;
				}
			}

			return $rate->with_cost( $cost );
		}

		/**
		 * Validates the `min_cost` field: empty, a number not below zero, and not above the maximum.
		 *
		 * **The pair is validated together.** WooCommerce saves the fields one by one and keeps the earlier
		 * ones when a later one throws, so refusing only the maximum would still store the new minimum and
		 * leave a pair the merchant was told is forbidden. A contradicting pair is therefore refused HERE, the
		 * first field of the pair (one clear error), and {@see self::validate_max_cost_field()} then keeps the
		 * saved maximum too: neither is changed. The maximum compared against is the one that will be stored
		 * (the posted one, or the saved one when the posted one is itself invalid).
		 *
		 * @since 2.0.2
		 *
		 * @param string $key   field key.
		 * @param mixed  $value posted value.
		 * @return string
		 * @throws \Exception When the value is not a non-negative number, or is above the maximum.
		 */
		public function validate_min_cost_field( $key, $value ) {

			$min = $this->validate_cost_limit_value( $value, __( 'Минимальная стоимость доставки должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
			$max = $this->get_effective_cost_limit( self::OPTION_MAX_COST );

			if ( '' !== $min && null !== $max && (float) $min > $max ) {
				throw new \Exception( __( 'Минимальная стоимость доставки не может быть больше максимальной. Значения не сохранены.', 'woodev-plugin-framework' ) );
			}

			return $min;
		}

		/**
		 * Validates the `max_cost` field: empty, a number not below zero, and not below the minimum.
		 *
		 * See {@see self::validate_min_cost_field()}: a contradicting pair is reported once, by the minimum, and
		 * here the SAVED maximum is kept without a second error, so neither of the two changes. When the
		 * posted minimum is itself invalid (and so is not stored), the maximum is compared with the saved
		 * minimum and refused here with its own error.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key   field key.
		 * @param mixed  $value posted value.
		 * @return string
		 * @throws \Exception When the value is not a non-negative number, or is below the minimum that stays in force.
		 */
		public function validate_max_cost_field( $key, $value ) {

			$max = $this->validate_cost_limit_value( $value, __( 'Максимальная стоимость доставки должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
			$min = $this->get_effective_cost_limit( self::OPTION_MIN_COST );

			if ( '' === $max || null === $min || (float) $max >= $min ) {
				return $max;
			}

			if ( null !== $this->get_posted_cost_limit( self::OPTION_MIN_COST ) ) {
				// the minimum validator already refused the pair with its error; keep the saved maximum
				return $this->normalize_saved_cost_limit( self::OPTION_MAX_COST );
			}

			throw new \Exception( __( 'Максимальная стоимость доставки не может быть меньше минимальной.', 'woodev-plugin-framework' ) );
		}

		/**
		 * The valid value a limit field was posted with, or `null` when it was left empty or is invalid.
		 *
		 * @since 2.0.2
		 *
		 * @param string $option `min_cost` or `max_cost`.
		 * @return float|null
		 */
		private function get_posted_cost_limit( string $option ): ?float {

			$post_data = (array) $this->get_post_data();
			$posted    = $post_data[ $this->get_field_key( $option ) ] ?? '';

			if ( ! is_scalar( $posted ) ) {
				return null;
			}

			return Shipping_Helper::normalize_cost_limit( wc_format_decimal( trim( stripslashes( (string) $posted ) ) ) );
		}

		/**
		 * The limit that will be in force after the form is saved: the posted one when it is valid, an empty
		 * posted one meaning «no limit», and the saved one when the posted one is invalid (then it is refused
		 * and the saved value stays).
		 *
		 * @since 2.0.2
		 *
		 * @param string $option `min_cost` or `max_cost`.
		 * @return float|null `null` for «no limit».
		 */
		private function get_effective_cost_limit( string $option ): ?float {

			$post_data = (array) $this->get_post_data();
			$posted    = $post_data[ $this->get_field_key( $option ) ] ?? '';

			if ( null === $posted || ( is_scalar( $posted ) && '' === trim( (string) $posted ) ) ) {
				return null;
			}

			$limit = $this->get_posted_cost_limit( $option );

			return null !== $limit ? $limit : Shipping_Helper::normalize_cost_limit( $this->get_option( $option, '' ) );
		}

		/**
		 * The saved value of a limit as a decimal string, `''` when none or unusable.
		 *
		 * @since 2.0.2
		 *
		 * @param string $option `min_cost` or `max_cost`.
		 * @return string
		 */
		private function normalize_saved_cost_limit( string $option ): string {

			$limit = Shipping_Helper::normalize_cost_limit( $this->get_option( $option, '' ) );

			return null === $limit ? '' : wc_format_decimal( (string) $limit );
		}

		/**
		 * Normalises one posted cost limit to a stored decimal string, or refuses it.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed  $value   posted value.
		 * @param string $message error text for an invalid value.
		 * @return string `''` for no limit, otherwise the decimal.
		 * @throws \Exception When the value is not a non-negative number.
		 */
		private function validate_cost_limit_value( $value, string $message ): string {

			if ( null === $value || ! is_scalar( $value ) ) {
				return '';
			}

			$value = trim( stripslashes( (string) $value ) );

			if ( '' === $value ) {
				return '';
			}

			$limit = Shipping_Helper::normalize_cost_limit( wc_format_decimal( $value ) );

			if ( null === $limit ) {
				throw new \Exception( $message );
			}

			return wc_format_decimal( $value );
		}

		/**
		 * Everything the rate of this method depends on, as plain data — the identity of a cached rate.
		 *
		 * Two calculations with an equal context MUST produce the same rate; the rate cache serves
		 * the first one's answer for the second. **Opting in to {@see self::FEATURE_RATE_CACHE} is
		 * declaring that this array is complete** for the carrier.
		 *
		 * The default is what the framework itself can see: the method and instance, the instance
		 * settings, the effective packing mode, the package lines (product, variation, quantity,
		 * dimensions, weight, shipping class, virtual flag) with the store's dimension/weight units
		 * and currency, the contents cost, the destination down to the street, the chosen payment
		 * method (when a WooCommerce session exists) and — for a method with a pickup type — the point
		 * selected for THIS method.
		 *
		 * A carrier overrides it, calls the parent and adds what only it knows: credentials, the
		 * account or origin read from global plugin settings (those are NOT instance settings, so a
		 * change to them would otherwise keep serving the old tariff), and any request parameter
		 * its `rate_package()` reads that is not on the list above. Values must be scalars, `null` or
		 * arrays of them, and finite: anything else disables caching for that call.
		 *
		 * Public, not protected, because {@see Shipping_Rate_Cache} reads it from outside the class.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 *
		 * @return array<string, mixed>
		 */
		public function get_rate_cache_context( array $package ): array {

			$context = Shipping_Rate_Cache::package_context( $package );

			$context['method']   = (string) $this->get_id();
			$context['instance'] = property_exists( $this, 'instance_id' ) ? (int) $this->instance_id : 0;
			$context['settings'] = property_exists( $this, 'instance_settings' ) && is_array( $this->instance_settings ) ? $this->instance_settings : [];
			$context['packing']  = [
				'enabled'   => $this->supports_box_packing(),
				'algorithm' => $this->supports_box_packing() ? $this->get_packing_algorithm() : '',
			];

			// the boxes are what the `boxes` algorithm packs into: a changed list is a different parcel, so a different quote (#1138)
			if ( \Woodev_Packer_Dispatcher::ALGORITHM_BOXES === $context['packing']['algorithm'] ) {
				$context['packing']['boxes'] = Shipping_Rate_Cache::boxes_context();
				$context['packing']['carrier_boxes'] = $this->get_plugin()->get_packaging_settings()->get_boxes();
				$context['packing']['leftovers'] = $this->get_unpacked_algorithm();
				$context['packing']['values'] = Packaging::get_value_context( (array) ( $package['contents'] ?? [] ) );
			}
			$context['payment'] = $this->payment_method_for_package( $package );

			if ( $this->supports_insurance() ) {
				$context['insurance'] = $this->resolve_insurance_for_package( $package );
			}

			$handler = $this->get_plugin()->get_pickup_handler();

			// `null` for a method that carries no pickup type; `point_id` is '' while nothing is chosen.
			$context['pickup'] = null === $handler ? null : $handler->get_selected_point_for_method( (string) $this->get_id() );

			return $context;
		}

		/**
		 * The payment method chosen at checkout, or `''` with no WooCommerce session (admin, REST).
		 *
		 * `protected` as a test seam, like the pickup handler's session readers: a probe overrides this
		 * one line rather than `WC()` having to exist in the unit-test process.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function chosen_payment_method(): string {

			if ( ! function_exists( 'WC' ) ) {
				return '';
			}

			$wc      = WC();
			$session = is_object( $wc ) && isset( $wc->session ) ? $wc->session : null;

			if ( ! is_object( $session ) ) {
				return '';
			}

			$chosen = $session->get( 'chosen_payment_method' );

			return is_scalar( $chosen ) ? (string) $chosen : '';
		}

		/**
		 * The payment method this rate is calculated for: the one on the package
		 * ({@see Fee_Payments::add_chosen_payment_to_packages()}), else the session's.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return string `''` when none is chosen yet.
		 */
		private function payment_method_for_package( array $package ): string {

			$on_package = $package[ Fee_Payments::PACKAGE_KEY ] ?? '';

			return is_string( $on_package ) && '' !== $on_package ? $on_package : $this->chosen_payment_method();
		}


		/**
		 * Determines whether this method has declared support for cash-on-delivery (COD) payment.
		 *
		 * Named predicate over {@see self::FEATURE_COD}: one point of change and a
		 * self-documenting capability surface (the convention from Woodev_Payment_Gateway's
		 * supports_*() wrappers).
		 *
		 * BACKWARD SAFETY (issue #713): this answers "did this method DECLARE COD support",
		 * never "is COD actually possible right now" — that second question is domain logic
		 * (e.g. whether the chosen pickup point itself accepts cash) and belongs to the host
		 * plugin, not to this predicate. A method that never calls {@see self::add_support()}
		 * with `self::FEATURE_COD` simply returns `false` here and is otherwise completely
		 * unaffected: nothing in the framework starts refusing or removing a gateway, a rate,
		 * or anything else on the strength of this flag being absent.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_cod(): bool {
			return $this->supports( self::FEATURE_COD );
		}

		/**
		 * Determines whether this method has declared support for shipment insurance.
		 *
		 * Named predicate over {@see self::FEATURE_INSURANCE}. An opted-in method gets the
		 * insurance mode control; an undeclared method's resolver always disables insurance.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_insurance(): bool {
			return $this->supports( self::FEATURE_INSURANCE );
		}

		/**
		 * Carrier-defined default for an unsaved instance; override with INSURANCE_ALWAYS for CDEK.
		 *
		 * @since 2.0.2
		 *
		 * @return string One of the INSURANCE_* mode constants.
		 */
		protected function get_default_insurance_mode(): string {
			return self::INSURANCE_NONE;
		}

		/**
		 * Saved insurance mode, or the carrier's default before the first save.
		 *
		 * An invalid saved value disables insurance rather than accidentally adding a charge.
		 *
		 * @since 2.0.2
		 *
		 * @return string One of the INSURANCE_* mode constants.
		 */
		public function get_insurance_mode(): string {
			$mode = $this->get_option( self::OPTION_INSURANCE, $this->get_default_insurance_mode() );

			return in_array( $mode, [ self::INSURANCE_NONE, self::INSURANCE_ALWAYS, self::INSURANCE_DELIVERY_PAYMENT ], true ) ? $mode : self::INSURANCE_NONE;
		}

		/**
		 * Resolves insurance for a quote, using the same payment source as fee restrictions.
		 *
		 * No payment chosen yet disables the conditional mode, just like a restricted fee (#1144).
		 * Goods are valued after discounts and excluding tax, shipping and fees: the package's
		 * line_total values (already quantity-inclusive), or contents_cost for an aggregate package.
		 * The amount is in the store currency; the carrier owns any API currency conversion.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return array{enabled: bool, declared_value: float} Zero declared value when disabled.
		 */
		public function resolve_insurance_for_package( array $package ): array {
			if ( ! $this->supports_insurance() ) {
				return $this->resolve_insurance( '', 0.0 );
			}

			return $this->resolve_insurance( $this->payment_method_for_package( $package ), Shipping_Helper::get_package_declared_value( $package ) );
		}

		/**
		 * Resolves insurance for order creation through the same rule as the quote.
		 *
		 * Pass only this shipment's product lines for a split order; null means all shippable lines.
		 * Reads the order's payment method directly, never the current shopper's session. Line totals
		 * exclude tax and include discounts, matching the cart's line_total and CDEK's item cost.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order                     $order Order being shipped.
		 * @param \WC_Order_Item_Product[]|null $items Shipment lines, or null for all shippable lines.
		 * @return array{enabled: bool, declared_value: float} Zero declared value when disabled.
		 */
		public function resolve_insurance_for_order( \WC_Order $order, ?array $items = null ): array {
			if ( ! $this->supports_insurance() ) {
				return $this->resolve_insurance( '', 0.0 );
			}

			$contents = [];

			foreach ( null === $items ? $order->get_items( 'line_item' ) : $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();

				if ( null === $items && ( ! $product || ! $product->needs_shipping() ) ) {
					continue;
				}

				$contents[] = [ 'line_total' => $item->get_total() ];
			}

			return $this->resolve_insurance( (string) $order->get_payment_method(), Shipping_Helper::get_package_declared_value( [ 'contents' => $contents ] ) );
		}

		/**
		 * Shared quote/order decision. The existing COD convention is `cod`; stores may add gateways.
		 *
		 * @param string $payment        Payment gateway id, or empty when not chosen.
		 * @param float  $declared_value Goods value in store currency.
		 * @return array{enabled: bool, declared_value: float}
		 */
		private function resolve_insurance( string $payment, float $declared_value ): array {
			$enabled = false;

			if ( $this->supports_insurance() ) {
				$mode = $this->get_insurance_mode();
				$enabled = self::INSURANCE_ALWAYS === $mode;

				if ( self::INSURANCE_DELIVERY_PAYMENT === $mode && '' !== $payment ) {
					/**
					 * Payment gateway ids treated as payment on receipt for shipment insurance.
					 *
					 * @since 2.0.2
					 *
					 * @param string[]        $gateways COD gateway ids (default: cod).
					 * @param Shipping_Method $method   Shipping method instance.
					 */
					$gateways = apply_filters( 'woodev_shipping_insurance_cod_gateways', [ 'cod' ], $this );
					$enabled = in_array( $payment, Fee_Payments::normalize( $gateways ), true );
				}
			}

			return [
				'enabled' => $enabled,
				'declared_value' => $enabled ? $declared_value : 0.0,
			];
		}

		/**
		 * Determines whether this method has declared support for a declared (customs/parcel)
		 * value.
		 *
		 * Named predicate over {@see self::FEATURE_DECLARED_VALUE}. Same backward-safety rule
		 * as {@see self::supports_cod()}: declares intent only, never gates or removes
		 * framework behaviour by itself, and a method that never opts in returns `false`
		 * unchanged.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_declared_value(): bool {
			return $this->supports( self::FEATURE_DECLARED_VALUE );
		}

		/**
		 * Adds support for the named feature or features.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Rebuilds the settings form when the feature is one that shapes it, so a
		 *              declaration made after construction reaches the merchant's screen (#813).
		 * @since 2.0.2 #815: reports a call made while `$this->id` is still empty via
		 *              `_doing_it_wrong()`, naming the correct order. The action below still
		 *              fires under the empty id either way — that stays the current contract,
		 *              queuing it was rejected on the card as a behaviour change no one asked for.
		 *
		 * @param string|string[] $feature the feature name or names supported by this shipping method
		 */
		public function add_support( $feature ) {

			/*
			 * `$this->id` is assigned by `Shipping_Method::__construct()`, not by this method, so
			 * a subclass calling `add_support()` from its OWN constructor before chaining to
			 * `parent::__construct()` runs this with `get_id()` still `''`. The action below then
			 * fires as `woodev_shipping_method__supports_<feature>` — a name with no id segment,
			 * shared by every shipping method that declares a feature in this order (#815).
			 *
			 * Both documented orders are unaffected: `add_support()` after `parent::__construct()`
			 * runs with the id already set, and `$this->supports = [ ... ]` before the constructor
			 * never reaches this method at all.
			 */
			if ( '' === $this->get_id() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {

				_doing_it_wrong(
					self::class . '::add_support',
					sprintf(
						'%s called add_support() before $this->id was set, so the action below fires ' .
						'as "woodev_shipping_method__supports_*" -- a name with no id segment, shared ' .
						'by every shipping method declaring a feature in this order. Call add_support() ' .
						'after parent::__construct(), or set $this->supports = [ ... ] before it instead.',
						esc_html( get_class( $this ) )
					),
					'2.0.2'
				);
			}

			if ( ! is_array( $feature ) ) {
				$feature = [ $feature ];
			}

			$reshapes_form = false;

			foreach ( $feature as $name ) {

				// add support for feature if it's not already declared
				if ( ! in_array( $name, $this->supports ) ) {

					$this->supports[] = $name;

					if ( in_array( $name, self::FORM_SHAPING_FEATURES, true ) ) {
						$reshapes_form = true;
					}

					/**
					 * Shipping Method Add Support Action.
					 *
					 * Fired when declaring support for a specific method feature.
					 * Allows other actors (including ourselves) to take action when support is declared.
					 *
					 * @since 1.0.0
					 *
					 * @param Shipping_Method $instance instance
					 * @param string $name of supported feature being added
					 */
					do_action( 'woodev_shipping_method_' . $this->get_id() . '_supports_' . str_replace( '-', '_', $name ), $this, $name );
				}
			}

			$this->supports = array_values( $this->supports );

			/*
			 * Rebuild the settings form when the declaration just made changes what it contains.
			 *
			 * `init_form_fields()` runs INSIDE the constructor, so a feature declared after
			 * construction — which is the path `docs/shipping-method.md` recommends, in those
			 * words, for FEATURE_SHIPPING_CLASSES — arrived too late to be seen by it. Measured
			 * on the rig (#813), with a subclass declaring the feature ONLY this way:
			 *
			 *   supports_box_packing()      -> TRUE    `packing_algorithm` control -> ABSENT
			 *   supports_shipping_classes() -> TRUE    `shipping_class_id` control -> ABSENT
			 *
			 * so the flag read back true while the merchant had no control to set, and the only
			 * thing standing between the two was this rebuild: a manual `init_form_fields()` on
			 * the same object produced the control immediately.
			 *
			 * The guard is `instance_form_fields` being non-empty, which means "the constructor
			 * has already built the form once". A subclass calling `add_support()` BEFORE
			 * `parent::__construct()` therefore skips the rebuild and is unaffected — the
			 * constructor is about to build the form with the feature already declared. (That
			 * ordering has its own wart, reported above rather than fixed here: `get_id()` is
			 * still empty up there, so the action fires under a nameless hook. #815.)
			 *
			 * `init_form_fields()` builds from scratch, so re-running it is idempotent; it
			 * re-applies the `woodev_shipping_method_{id}_form_fields` filter, which is fine for
			 * a filter that is a function of its input and is the only reason this is gated on
			 * the two features that actually shape the form rather than run on every call.
			 *
			 * Reading settings stays correct without touching `instance_settings`:
			 * `WC_Shipping_Method::get_instance_option()` falls back to the field's own default
			 * for a key that is not in the saved array (verified against WooCommerce 11.1.0).
			 */
			if ( $reshapes_form && ! empty( $this->instance_form_fields ) ) {

				if ( $this->building_form_fields ) {
					// Declared from inside the form-fields filter: the pass in flight is about
					// to assign its own array over anything built here, so let it redo itself
					// once it has. See the comment at the end of init_form_fields().
					$this->pending_form_rebuild = true;
				} else {
					$this->init_form_fields();
				}
			}
		}
	}

endif;

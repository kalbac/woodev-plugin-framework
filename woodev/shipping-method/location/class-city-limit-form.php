<?php
/**
 * The city limit's instance-form control: the two fields, their saving, and the markup the zone modal's script
 * turns into a cities list.
 *
 * Shared by a Woodev method that declares {@see \Woodev\Framework\Shipping\Shipping_Method::FEATURE_CITY_LIMIT}
 * and by WooCommerce's own «Самовывоз» ({@see Core_Pickup_City_Limit}). The cities field is a CUSTOM
 * WooCommerce field type ({@see City_Limit::FIELD_TYPE}), rendered through WooCommerce's own
 * `woocommerce_generate_{type}_html` filter — which is why it works on a method class we do not own.
 *
 * WooCommerce draws a method's settings on the server (`WC_Shipping_Method::get_admin_options_html()`) and the
 * zone screen inserts that HTML into a Backbone modal; a script (`src/shipping-zone-city-limit`) finds
 * `.woodev-city-limit` in it on `wc_backbone_modal_loaded` and mounts the list.
 *
 * @package Woodev\Framework\Shipping\Location
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

use Woodev\Framework\Shipping\Shipping_Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\City_Limit_Form' ) ) :

	/**
	 * Class City_Limit_Form
	 *
	 * @since 2.0.2
	 */
	final class City_Limit_Form {

		/** Script / style handle of the built bundle. */
		public const SCRIPT_HANDLE = 'woodev-city-limit';

		/** Whether the render filter has been added. */
		private static bool $hooked = false;

		/** @var Location_Service|null A service a test put in place of the real one. */
		private static ?Location_Service $service_for_tests = null;

		/**
		 * The location service the control talks to.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Service
		 */
		private static function service(): Location_Service {
			return self::$service_for_tests ?? new Location_Service();
		}

		/**
		 * Test-only: puts a service in place of the real one (`null` takes it out again).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Service|null $service The service.
		 *
		 * @return void
		 */
		public static function use_service_for_tests( ?Location_Service $service ): void {
			self::$service_for_tests = $service;
		}

		/**
		 * Hooks the renderer of the cities field — once per request, whichever caller comes first.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function register(): void {
			if ( self::$hooked ) {
				return;
			}

			self::$hooked = true;

			add_filter( 'woocommerce_generate_' . City_Limit::FIELD_TYPE . '_html', [ self::class, 'render' ], 10, 4 );
		}

		/**
		 * Test-only: forgets that the render filter was added.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::$hooked           = false;
			self::$service_for_tests = null;
		}

		/**
		 * The two instance form fields: the mode and the cities.
		 *
		 * `show_if` hides the cities while the mode is «Без ограничений» (the same declaration the framework's own
		 * instance fields use, evaluated by `instance-field-conditions.js`); a hidden field is still submitted.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function fields(): array {
			return [
				City_Limit::OPTION_MODE   => [
					'title'             => __( 'Ограничение по городам', 'woodev-plugin-framework' ),
					'type'              => 'select',
					'class'             => 'wc-enhanced-select',
					'default'           => City_Limit::MODE_OFF,
					'options'           => City_Limit::modes(),
					'desc_tip'          => __( 'Город определяется по тому, что покупатель выбрал при оформлении заказа. Пока город не выбран, способ доставки доступен.', 'woodev-plugin-framework' ),
					'sanitize_callback' => [ self::class, 'sanitize_mode' ],
				],
				City_Limit::OPTION_CITIES => [
					'title'             => __( 'Города', 'woodev-plugin-framework' ),
					'type'              => City_Limit::FIELD_TYPE,
					'default'           => '[]',
					'desc_tip'          => __( 'Начните вводить название и выберите город из списка. Если в зоне доставки указаны регионы, поиск идёт только по ним.', 'woodev-plugin-framework' ),
					'show_if'           => [
						'setting'  => City_Limit::OPTION_MODE,
						'operator' => '!=',
						'value'    => City_Limit::MODE_OFF,
					],
					'sanitize_callback' => [ self::class, 'sanitize_cities' ],
				],
			];
		}

		/**
		 * `sanitize_callback` of the mode.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Posted value.
		 *
		 * @return string
		 */
		public static function sanitize_mode( $value ): string {
			return City_Limit::normalize_mode( is_string( $value ) ? wp_unslash( $value ) : '' );
		}

		/**
		 * `sanitize_callback` of the cities: valid settlement records only, no repeats, nothing outside the regions
		 * of the zone the method sits in (the search never offers such a city; this is the belt to its braces).
		 *
		 * The method instance is the one WooCommerce is saving — it insists on `$_REQUEST['instance_id']` matching
		 * before it calls any field, so reading it here is reading the same id.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Posted value (the JSON the list keeps in its hidden input).
		 *
		 * @return string
		 */
		public static function sanitize_cities( $value ): string {
			$records = City_Limit::decode( $value );

			// A classic settings page hands the value over still slashed.
			if ( [] === $records && is_string( $value ) && '' !== trim( $value ) ) {
				$records = City_Limit::decode( stripslashes( $value ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce verified the request before it called this field.
			$instance_id = isset( $_REQUEST['instance_id'] ) ? absint( wp_unslash( $_REQUEST['instance_id'] ) ) : 0;
			$states      = City_Limit::zone_scope( $instance_id )['states'];

			if ( [] !== $states ) {
				$service = self::service();
				$records = array_values(
					array_filter(
						$records,
						static function ( Location_Record $record ) use ( $states, $service ): bool {
							return City_Limit::in_zone( $record, $states, $service );
						}
					)
				);
			}

			return City_Limit::encode( $records );
		}

		/**
		 * What the zone modal's script needs to draw the list, and the notes under it.
		 *
		 * A stored city the current provider no longer answers for is re-resolved by its name — in memory, for this
		 * view only; the replacement is what goes into the hidden input, so saving the form is what keeps it. One that
		 * cannot be re-resolved stays listed, marked, and is ignored when rating (see {@see City_Limit}).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $stored  The stored cities setting.
		 * @param array            $scope   {@see City_Limit::zone_scope()}.
		 * @param Location_Service $service Location service.
		 * @param bool             $resolve Whether to try re-resolving stale cities (needs a working provider).
		 *
		 * @return array{value: string, items: array<int, array{record: array<string, mixed>, state: string}>, notes: string[], country: string, active: bool}
		 */
		public static function build_view( $stored, array $scope, Location_Service $service, bool $resolve ): array {
			$parts    = City_Limit::partition( City_Limit::decode( $stored ), $service );
			$states   = $scope['states'];
			$kept     = [];
			$items    = [];
			$replaced = 0;
			$stale    = 0;
			$outside  = 0;

			foreach ( $parts['current'] as $record ) {
				$kept[ $record->key() ] = [ $record, 'ok' ];
			}

			foreach ( $parts['stale'] as $record ) {
				$now = $resolve ? self::try_reresolve( $record, $service ) : null;

				if ( null !== $now ) {
					++$replaced;
					$kept[ $now->key() ] = $kept[ $now->key() ] ?? [ $now, 'ok' ];
				} else {
					++$stale;
					$kept[ $record->key() ] = $kept[ $record->key() ] ?? [ $record, 'stale' ];
				}
			}

			$records = [];

			foreach ( $kept as $pair ) {
				[ $record, $state ] = $pair;

				if ( 'ok' === $state && ! City_Limit::in_zone( $record, $states, $service ) ) {
					$state = 'outside';
					++$outside;
				}

				$records[] = $record;
				$items[]   = [
					'record' => array_merge( $record->to_array(), [ 'raw' => null ] ),
					'state'  => $state,
				];
			}

			$notes = [];

			if ( $stale > 0 ) {
				$notes[] = __( 'Часть городов не удалось сопоставить с текущим сервисом определения местоположения — они не учитываются. Удалите их и выберите заново.', 'woodev-plugin-framework' );
			}

			if ( $replaced > 0 ) {
				$notes[] = __( 'Сервис определения местоположения сменился: города подобраны заново по названию. Сохраните настройки, чтобы запомнить их.', 'woodev-plugin-framework' );
			}

			if ( $outside > 0 ) {
				$notes[] = __( 'Регионы зоны доставки изменились: часть городов в них больше не входит и не учитывается.', 'woodev-plugin-framework' );
			}

			if ( [] !== $states && $service->is_region_field_removed() ) {
				$notes[] = __( 'В зоне доставки указаны регионы, а поле региона убрано из формы оформления заказа — зона не сработает, пока покупатель не выберет регион.', 'woodev-plugin-framework' );
			}

			$active = $service->is_active();

			if ( ! $active ) {
				$notes[] = __( 'Поиск городов недоступен: настройте определение местоположения в настройках доставки.', 'woodev-plugin-framework' );
			}

			return [
				'value'   => City_Limit::encode( $records ),
				'items'   => $items,
				'notes'   => $notes,
				'country' => '' !== $scope['country'] ? $scope['country'] : $service->resolve_default_country(),
				'active'  => $active,
			];
		}

		/**
		 * Re-resolves one stale city, never throwing.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record  $record  Stale city.
		 * @param Location_Service $service Location service.
		 *
		 * @return Location_Record|null
		 */
		private static function try_reresolve( Location_Record $record, Location_Service $service ): ?Location_Record {
			try {
				return $service->reresolve_stranded_record( $record );
			} catch ( \Throwable $exception ) {
				return null;
			}
		}

		/**
		 * `woocommerce_generate_woodev_city_limit_html` — draws the cities field's table row.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $html     The markup so far (empty).
		 * @param mixed $key      Field key.
		 * @param mixed $data     Field definition.
		 * @param mixed $settings The settings object being drawn (a shipping method).
		 *
		 * @return string
		 */
		public static function render( $html, $key, $data, $settings = null ): string {
			if ( ! $settings instanceof \WC_Settings_API || ! is_string( $key ) ) {
				return is_string( $html ) ? $html : '';
			}

			$data        = wp_parse_args(
				(array) $data,
				[
					'title'             => '',
					'desc_tip'          => false,
					'description'       => '',
					'custom_attributes' => [],
				]
			);
			$field_key   = $settings->get_field_key( $key );
			$instance_id = isset( $settings->instance_id ) ? (int) $settings->instance_id : 0;
			$service     = self::service();
			$view        = self::build_view( $settings->get_option( $key ), City_Limit::zone_scope( $instance_id ), $service, $service->is_active() );

			$config = [
				'inputId'    => $field_key,
				'instanceId' => $instance_id,
				'country'    => $view['country'],
				'active'     => $view['active'],
				'restRoot'   => esc_url_raw( rest_url( 'woodev/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'items'      => $view['items'],
			];

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( (string) $data['title'] ); ?> <?php echo $settings->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own tooltip markup. ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( (string) $data['title'] ); ?></span></legend>
						<input type="hidden" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $view['value'] ); ?>" <?php echo $settings->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own attribute markup. ?> />
						<div class="woodev-city-limit" data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
							<ul class="woodev-city-limit__static">
								<?php foreach ( $view['items'] as $item ) : ?>
									<li><?php echo esc_html( (string) ( $item['record']['label'] ?? $item['record']['key'] ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
						<?php foreach ( $view['notes'] as $note ) : ?>
							<p class="description woodev-city-limit__note"><?php echo esc_html( $note ); ?></p>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<?php

			return (string) ob_get_clean();
		}

		/**
		 * Loads the built script of the cities list on the shipping settings screen (the method's own page and the
		 * modal WooCommerce opens from the zone screen).
		 *
		 * Several carrier plugins share the handle: the first one enqueues it. A build that is not there (a
		 * checkout of the source without `npm run build`) enqueues nothing — the field then shows its read-only
		 * list.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Plugin $plugin A shipping plugin (resolves the loaded framework copy).
		 *
		 * @return void
		 */
		public static function enqueue( Shipping_Plugin $plugin ): void {
			if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
				return;
			}

			$build_path = $plugin->get_framework_path() . '/assets/build/shipping-zone-city-limit';
			$asset_file = $build_path . '/index.asset.php';

			if ( ! file_exists( $asset_file ) ) {
				return;
			}

			$asset = include $asset_file;
			$asset = is_array( $asset ) ? $asset : [];
			$url   = $plugin->get_framework_assets_url() . '/build/shipping-zone-city-limit';

			if ( file_exists( $build_path . '/style-index.css' ) ) {
				wp_enqueue_style( self::SCRIPT_HANDLE, $url . '/style-index.css', [ 'wp-components' ], (string) filemtime( $build_path . '/style-index.css' ) );
			}

			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				$url . '/index.js',
				array_merge( (array) ( $asset['dependencies'] ?? [] ), [ 'jquery' ] ),
				(string) ( $asset['version'] ?? $plugin->get_version() ),
				true
			);

			\Woodev\Framework\Handlers\Script_Translations::register( $plugin, self::SCRIPT_HANDLE );
		}
	}

endif;

<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Plugin;

/**
 * Fleet-aware carrier competitor notices backed by the shared catalogue.
 *
 * @since 2.0.2
 */
final class Carrier_Competitor_Notification_Handler extends Competitor_Notification_Handler {

	/** @var bool whether fleet recommendations were evaluated this request */
	private static bool $recommendations_evaluated = false;

	/** @var string carrier id declared by the owning plugin */
	private string $carrier_id;

	/**
	 * @since 2.0.2
	 * @param Woodev_Plugin $plugin owning plugin
	 * @param string        $carrier_id catalogue carrier key
	 */
	public function __construct( Woodev_Plugin $plugin, string $carrier_id ) {
		parent::__construct( $plugin );
		$this->carrier_id = $carrier_id;
	}

	/**
	 * Runs this carrier's conflict and the fleet's recommendations once.
	 *
	 * @since 2.0.2
	 * @return void
	 */
	public function run(): void {
		parent::run();

		if ( self::$recommendations_evaluated ) {
			return;
		}

		self::$recommendations_evaluated = true;
		$this->render_recommendations();
	}

	/**
	 * Conflict rules are limited to the product represented by this handler.
	 *
	 * @since 2.0.2
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_competitor_rules(): array {
		$products = Carrier_Catalogue::products();

		if ( ! isset( $products[ $this->carrier_id ] ) ) {
			return [];
		}

		$product = $products[ $this->carrier_id ];
		$files   = array_column( $product['competitors'], 'file' );

		return [
			[
				'mode'            => Competitor_Rule::MODE_CONFLICT,
				'detect'          => $files,
				'competitor_name' => $product['competitors'][0]['name'],
			],
		];
	}

	/**
	 * Always use the regular WordPress admin-notice renderer for this feature.
	 *
	 * @since 2.0.2
	 * @return Competitor_Notice_Renderer
	 */
	protected function get_renderer(): Competitor_Notice_Renderer {
		return new Admin_Notice_Renderer( $this->get_plugin()->get_admin_notice_handler() );
	}

	/**
	 * Returns the dismiss action with the localized admin label.
	 *
	 * @since 2.0.2
	 * @return array<string,string>
	 */
	protected function dismiss_action(): array {
		return [
			'name' => 'dismiss',
			'label' => __( 'Скрыть', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Supplies carrier-specific conflict copy and the detected plugin's action.
	 *
	 * @since 2.0.2
	 * @param Competitor_Rule $rule        active conflict rule
	 * @param string          $active_slug detected competitor plugin file
	 * @return array<string,mixed>
	 */
	protected function build_note( Competitor_Rule $rule, string $active_slug ): array {
		$product = Carrier_Catalogue::products()[ $this->carrier_id ];
		$name    = $product['competitors'][0]['name'];
		foreach ( $product['competitors'] as $competitor ) {
			if ( $active_slug === $competitor['file'] ) {
				$name = $competitor['name'];
				break;
			}
		}

		$content = sprintf(
			__( 'На сайте активен плагин %1$s. Он тоже добавляет методы доставки %2$s: на оформлении заказа появятся дубли, а заказы могут уйти в %3$s дважды. Оставьте включённым один из плагинов.', 'woodev-plugin-framework' ),
			esc_html( $name ),
			esc_html( $product['carrier_name'] ),
			esc_html( $product['service_name'] )
		);

		return [
			'title'   => sprintf( __( 'Информация от Woodev: обнаружен плагин %s стороннего разработчика', 'woodev-plugin-framework' ), $product['carrier_name'] ),
			'content' => $content,
			'type'    => self::TYPE_ERROR,
			'actions' => [
				[
					'name' => 'deactivate-plugin',
					'label' => sprintf( __( 'Отключить %s', 'woodev-plugin-framework' ), $name ),
					'url' => $this->get_deactivation_url( $active_slug ),
					'primary' => true,
				],
				$this->dismiss_action(),
			],
		];
	}

	/**
	 * Evaluates every catalogue product once and renders one recommendation per carrier.
	 *
	 * @since 2.0.2
	 */
	private function render_recommendations(): void {
		$products = Carrier_Catalogue::products();

		foreach ( $products as $product ) {
			if ( $this->get_plugin()->is_plugin_active( $product['plugin_file'] ) ) {
				continue;
			}

			$rule = $this->active_recommendation_rule( $product );
			if ( null === $rule ) {
				continue;
			}

			$this->get_renderer()->render(
				$rule,
				[
					'title'   => sprintf( __( 'Информация от Woodev: плагин для %s', 'woodev-plugin-framework' ), $product['carrier_name'] ),
					'content' => sprintf(
						__( 'На сайте работает плагин стороннего разработчика <strong>%1$s</strong>. У Woodev есть плагин для %2$s: <strong>%3$s</strong>.', 'woodev-plugin-framework' ),
						esc_html( $rule->get_competitor_name() ),
						esc_html( $product['carrier_name'] ),
						esc_html( $product['name'] )
					),
					'type'    => self::TYPE_UPDATE,
					'actions' => [
						[
							'name' => 'plugin-details',
							'label' => __( 'Перейти на страницу плагина', 'woodev-plugin-framework' ),
							'url' => $this->get_recommend_action_url( $rule ),
							'primary' => true,
						],
						$this->dismiss_action(),
					],
				],
				true
			);
		}
	}

	/**
	 * Returns one recommendation rule if any listed competitor is active.
	 *
	 * @since 2.0.2
	 * @param array<string,mixed> $product catalogue product
	 * @return Competitor_Rule|null active competitor rule, or null when none is active
	 */
	private function active_recommendation_rule( array $product ): ?Competitor_Rule {
		foreach ( $product['competitors'] as $competitor ) {
			if ( $this->get_plugin()->is_plugin_active( $competitor['file'] ) ) {
				return new Competitor_Rule(
					[
						'mode'             => Competitor_Rule::MODE_RECOMMEND,
						'detect'           => $competitor['file'],
						'note_name'        => 'woodev-competitor-recommend-' . $product['id'],
						'competitor_name'  => $competitor['name'],
						'our_plugin_file'  => $product['plugin_file'],
						'our_name'         => $product['name'],
						'our_url'          => $product['url'],
						'our_download_id'  => $product['download_id'],
					]
				);
			}
		}

		return null;
	}
}

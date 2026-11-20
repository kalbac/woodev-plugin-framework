<?php
/**
 * REST routes of the «Диагностика» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Diagnostics;

use Woodev\Framework\Admin\Records\Records_Error;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * The server half of the diagnostics page, under `woodev/v1/admin/marketplace/{plugin id}/diagnostics`:
 *
 * | Route | Does |
 * |---|---|
 * | `GET R` | the «Логирование» state and the operational sections (licence, account health, key expiry) |
 * | `GET R/report` | the diagnostic sections and the support report text; 403 with {@see Logging::REFUSAL} while logging is off |
 *
 * The menu lists the page only while «Логирование» is on (the switch lives in the plugin's settings, «Дополнительно»), and the
 * report is refused with 403 otherwise, so the text never reaches a merchant who did not switch it on. Every route checks the
 * capability first.
 *
 * @since 2.1.5
 */
final class Diagnostics_Controller {
	/**
	 * @var string
	 */
	private $owner;

	/**
	 * @var string
	 */
	private $capability;

	/**
	 * @var Report_Builder
	 */
	private $builder;

	/**
	 * @var Logging
	 */
	private $logging;

	/**
	 * @since 2.1.5
	 *
	 * @param string         $owner      Plugin id.
	 * @param string         $capability Capability the page needs.
	 * @param Report_Builder $builder    Report sections and text.
	 * @param Logging        $logging    «Логирование» switch.
	 */
	public function __construct( string $owner, string $capability, Report_Builder $builder, Logging $logging ) {
		$this->owner      = $owner;
		$this->capability = $capability;
		$this->builder    = $builder;
		$this->logging    = $logging;
	}

	/**
	 * The sentences and labels of the page. PHP owns the copy; the view only places it.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return [
			'intro'           => __( 'Что происходит с подключением, уведомлениями, очередью и ошибками. Если нужна помощь поддержки, скопируйте отчёт в конце страницы и отправьте его в обращении. Страница показывается, пока включено «Логирование» (настройки плагина, раздел «Дополнительно»).', 'woodev-plugin-framework' ),
			'loggingOff'      => __( 'Логирование выключено, поэтому подробной диагностики нет. Включите его в настройках плагина, раздел «Дополнительно», и обновите страницу.', 'woodev-plugin-framework' ),
			'reportHeading'   => __( 'Отчёт для поддержки', 'woodev-plugin-framework' ),
			'reportHint'      => __( 'Скопируйте этот текст и отправьте в поддержку. Ключи доступа в него не попадают.', 'woodev-plugin-framework' ),
			'copyReport'      => __( 'Скопировать отчёт для поддержки', 'woodev-plugin-framework' ),
			'copied'          => __( 'Скопировано', 'woodev-plugin-framework' ),
			'copyFailed'      => __( 'Не удалось скопировать: выделите текст ниже и скопируйте вручную.', 'woodev-plugin-framework' ),
			'reportFailed'    => __( 'Не удалось получить отчёт. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Tells why the report may not be shown, or null when it may.
	 *
	 * @since 2.1.5
	 *
	 * @param bool $logging_enabled Whether «Логирование» is on.
	 * @return string|null The sentence the 403 carries; null when the report may be shown.
	 */
	public static function report_refusal( bool $logging_enabled ): ?string {
		return $logging_enabled ? null : Logging::REFUSAL;
	}

	/**
	 * Registers the routes.
	 *
	 * @since 2.1.5
	 *
	 * @internal
	 * @return void
	 */
	public function register_routes(): void {
		$base = '/admin/marketplace/' . $this->owner . '/diagnostics';

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			$base,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_state' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			$base . '/report',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_report' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * Gate for every route. A valid cookie nonce proves the request is the user's own, not that the user may see diagnostics.
	 *
	 * @since 2.1.5
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		return current_user_can( $this->capability ) ? true : Records_Error::forbidden();
	}

	/**
	 * `GET R`: the switch and the operational sections, which are always shown.
	 *
	 * @since 2.1.5
	 *
	 * @return \WP_REST_Response
	 */
	public function get_state(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'messages'    => self::messages(),
				'logging'     => [ 'enabled' => $this->logging->is_enabled() ],
				'operational' => Diagnostics_Payload::sections( $this->builder->operational_sections() ),
			]
		);
	}

	/**
	 * `GET R/report`: the diagnostic sections and the support report text. A 403 with the refusal sentence while logging is off.
	 *
	 * @since 2.1.5
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_report() {
		$refusal = self::report_refusal( $this->logging->is_enabled() );

		if ( null !== $refusal ) {
			return new \WP_Error( 'woodev_diagnostics_logging_off', $refusal, [ 'status' => 403 ] );
		}

		$operational = $this->builder->operational_sections();
		$diagnostic  = $this->builder->diagnostic_sections();

		return new \WP_REST_Response(
			[
				'messages'   => self::messages(),
				'diagnostic' => Diagnostics_Payload::sections( $diagnostic ),
				'report'     => $this->builder->support_report( $operational, $diagnostic ),
			]
		);
	}
}

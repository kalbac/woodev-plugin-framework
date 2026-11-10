<?php
/**
 * Records engine — REST errors.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the records REST routes build their `WP_Error`s, so a code always means the
 * same thing and the same status. Messages are merchant-safe: they never echo request input
 * or provider text.
 *
 * Unknown and inaccessible resources share {@see self::not_found()} on purpose — a forged
 * account or row id must not tell the caller whether the thing exists.
 *
 * @since 2.0.29
 */
final class Records_Error {
	/**
	 * 400: a request parameter is malformed, unknown or outside its declared set.
	 *
	 * @since 2.0.29
	 *
	 * @param string $param Name of the offending parameter (`filters.status`, `sort`…).
	 * @return \WP_Error
	 */
	public static function invalid( string $param ): \WP_Error {
		return new \WP_Error(
			'woodev_records_invalid_param',
			__( 'Некорректный параметр запроса.', 'woodev-plugin-framework' ),
			[
				'status' => 400,
				'param'  => $param,
			]
		);
	}

	/**
	 * 400: a list asked for more than the table allows. Rejected, never truncated.
	 *
	 * @since 2.0.29
	 *
	 * @param string $code  `woodev_records_per_page_too_large` or `woodev_records_selection_too_large`.
	 * @param string $param Offending parameter.
	 * @return \WP_Error
	 */
	public static function too_large( string $code, string $param ): \WP_Error {
		return new \WP_Error(
			$code,
			'woodev_records_selection_too_large' === $code
				? __( 'Выбрано больше записей, чем разрешено для этой таблицы.', 'woodev-plugin-framework' )
				: __( 'Запрошено больше строк, чем разрешено для этой таблицы.', 'woodev-plugin-framework' ),
			[
				'status' => 400,
				'param'  => $param,
			]
		);
	}

	/**
	 * 404: unknown owner, table, action, field, row or account — or one the caller may not reach.
	 *
	 * @since 2.0.29
	 *
	 * @return \WP_Error
	 */
	public static function not_found(): \WP_Error {
		return new \WP_Error(
			'woodev_records_not_found',
			__( 'Запись не найдена.', 'woodev-plugin-framework' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * 403: the user lacks the registration's or the action's capability.
	 *
	 * @since 2.0.29
	 *
	 * @return \WP_Error
	 */
	public static function forbidden(): \WP_Error {
		return new \WP_Error(
			'woodev_records_forbidden',
			__( 'Недостаточно прав для этого действия.', 'woodev-plugin-framework' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * 500: the provider broke its contract (bad definition, bad rows) or threw.
	 *
	 * @since 2.0.29
	 *
	 * @param string $code Specific code for logs and tests.
	 * @return \WP_Error
	 */
	public static function internal( string $code = 'woodev_records_provider_error' ): \WP_Error {
		return new \WP_Error(
			$code,
			__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
			[ 'status' => 500 ]
		);
	}
}

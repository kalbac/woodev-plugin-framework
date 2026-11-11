<?php
/**
 * Merchant-facing sentences for D7 sync-stop reasons.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a {@see Sync_Reason} from {@see \Woodev\Framework\Marketplace\Diagnostics\Why_Not_Syncing} into the translated
 * Russian sentence shown on admin surfaces (product tab, settings, diagnostics).
 *
 * Kept small and stateless so every admin surface shares one set of localized D7 sentences while the domain layer stays
 * free of WordPress gettext functions.
 *
 * @since 2.1.2
 */
final class Sync_Reason_Text {
	/**
	 * Translates one sync-stop reason into a merchant-facing sentence.
	 *
	 * @since 2.1.2
	 *
	 * @param Sync_Reason $reason Reason returned by `Why_Not_Syncing`.
	 * @return string Localized sentence; falls back to the reason code for unknown future codes.
	 */
	public static function sentence( Sync_Reason $reason ): string {
		$params = $reason->params();

		switch ( $reason->code() ) {
			case Sync_Reason::LICENSE_INVALID:
				return __( 'Лицензия не действует — продлите её на странице лицензии Woodev.', 'woodev-plugin-framework' );
			case Sync_Reason::ACCOUNT_DISABLED:
				return __( 'Аккаунт выключен — включите его в настройках.', 'woodev-plugin-framework' );
			case Sync_Reason::KEY_ROLE_MISSING:
				return __( 'У ключа нет нужных прав — создайте ключ с полным доступом и введите его в настройках.', 'woodev-plugin-framework' );
			case Sync_Reason::KEY_INVALID:
				return __( 'Ключ недействителен или истёк — введите новый ключ в настройках аккаунта.', 'woodev-plugin-framework' );
			case Sync_Reason::NOT_LINKED:
				return __( 'Товар не связан с карточкой маркетплейса — выгрузите товар или свяжите его с существующей карточкой.', 'woodev-plugin-framework' );
			case Sync_Reason::CARD_PENDING:
				/* translators: %s: marketplace card state code. */
				return sprintf( __( 'Карточка ещё создаётся (статус: %s) — остатки и цены отправятся, когда она будет готова.', 'woodev-plugin-framework' ), (string) ( $params['state'] ?? '' ) );
			case Sync_Reason::STOCK_SYNC_OFF:
				return __( 'Синхронизация остатков выключена для товара — включите её на вкладке товара.', 'woodev-plugin-framework' );
			case Sync_Reason::NO_WAREHOUSE:
				return __( 'Не выбран склад — выберите склад в настройках аккаунта.', 'woodev-plugin-framework' );
			case Sync_Reason::RATE_LIMITED:
				/* translators: %d: seconds until the next retry. */
				return sprintf( __( 'Маркетплейс ограничил частоту запросов — следующая попытка через %d с.', 'woodev-plugin-framework' ), (int) ( $params['seconds'] ?? 0 ) );
			case Sync_Reason::LAST_ERROR:
				/* translators: %s: error text returned by the marketplace. */
				return sprintf( __( 'Последняя ошибка маркетплейса: %s', 'woodev-plugin-framework' ), (string) ( $params['message'] ?? '' ) );
		}

		return $reason->code();
	}

	/**
	 * Translates an ordered list of sync-stop reasons into sentences.
	 *
	 * @since 2.1.2
	 *
	 * @param Sync_Reason[] $reasons Reasons returned by `Why_Not_Syncing::reasons()`.
	 * @return string[]
	 */
	public static function sentences( array $reasons ): array {
		$result = [];

		foreach ( $reasons as $reason ) {
			if ( $reason instanceof Sync_Reason ) {
				$result[] = self::sentence( $reason );
			}
		}

		return $result;
	}
}

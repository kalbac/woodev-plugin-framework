<?php
/**
 * Push-notification settings section and per-account registration actions.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Admin\Diagnostics\Diagnostics_Reader;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Redactor;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Url;
use Woodev\Framework\Marketplace\Intake\Registration_Refresher;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the push-notifications settings cards when the marketplace declares {@see Capability::PUSH_NOTIFICATIONS}:
 *
 * - Completely omitted (`sections() === []` and zero settings registered) when push notifications are unsupported (D2).
 * - Per-account card shows the masked webhook URL (`/notifications/{id}/[скрыто]`), wanted push event descriptions,
 *   stored registration status and latest received event, plus the four remote registration actions gated on
 *   {@see License_Gate}.
 * - Global security card validates `notifications_ip_allowlist` and `notifications_trusted_proxies` via
 *   {@see Ip_List_Validator}.
 *
 * @since 2.1.2
 */
final class Notifications_Section implements Settings_Section_Provider {
	public const ALLOWLIST_ID       = 'notifications_ip_allowlist';
	public const TRUSTED_PROXIES_ID = 'notifications_trusted_proxies';

	/**
	 * Marketplace adapter.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Notification accounts reader.
	 *
	 * @var Notification_Account_Reader
	 */
	private $accounts;

	/**
	 * Latest notification reader.
	 *
	 * @var Last_Notification_Reader
	 */
	private $last_notifications;

	/**
	 * Licence gate.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Sanitized plugin id prefix for unique action button ids.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the notifications section provider.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Adapter         $adapter            Marketplace adapter.
	 * @param Notification_Account_Reader $accounts           Notification accounts reader.
	 * @param Last_Notification_Reader    $last_notifications Latest notification reader.
	 * @param License_Gate                $license            Licence gate.
	 * @param string                      $plugin_id          Plugin id (scopes action button ids).
	 * @param callable|null               $clock              `function (): int`, defaults to `time`.
	 */
	public function __construct(
		Marketplace_Adapter $adapter,
		Notification_Account_Reader $accounts,
		Last_Notification_Reader $last_notifications,
		License_Gate $license,
		string $plugin_id = '',
		?callable $clock = null
	) {
		$this->adapter            = $adapter;
		$this->accounts           = $accounts;
		$this->last_notifications = $last_notifications;
		$this->license            = $license;
		$this->plugin_slug        = '' !== $plugin_id ? sanitize_key( str_replace( '-', '_', $plugin_id ) ) : $adapter->id();
		$this->clock              = $clock ?? 'time';
	}

	/**
	 * Registers IP allowlist and trusted-proxies settings when push notifications are supported.
	 *
	 * @since 2.1.2
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
		if ( ! $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			return;
		}

		$descriptor  = $this->adapter->settings_descriptor();
		$marketplace = $descriptor->marketplace_name();
		$ip_check    = static function ( $value ): bool {
			return [] === Ip_List_Validator::invalid_entries( (string) $value );
		};
		$ip_message  = __( 'Укажите корректные IP-адреса или подсети CIDR (по одному в строке, например 198.51.100.0/24).', 'woodev-plugin-framework' );

		$handler->register_setting(
			self::ALLOWLIST_ID,
			\Woodev_Setting::TYPE_STRING,
			[
				/* translators: %s: marketplace name. */
				'name'             => sprintf( __( 'Разрешённые адреса %s', 'woodev-plugin-framework' ), $marketplace ),
				'default'          => $descriptor->default_ip_allowlist(),
				'validate'         => $ip_check,
				'validate_message' => $ip_message,
			]
		);
		$handler->register_control(
			self::ALLOWLIST_ID,
			\Woodev_Control::TYPE_TEXTAREA,
			[
				/* translators: %s: marketplace name. */
				'label'   => sprintf( __( 'Разрешённые адреса %s', 'woodev-plugin-framework' ), $marketplace ),
				/* translators: %s: marketplace name. */
				'tooltip' => sprintf( __( 'IP-адреса и подсети CIDR, с которых %s присылает уведомления (по одному на строку). Пустое поле отключает фильтрацию по IP.', 'woodev-plugin-framework' ), $marketplace ),
			]
		);

		$handler->register_setting(
			self::TRUSTED_PROXIES_ID,
			\Woodev_Setting::TYPE_STRING,
			[
				'name'             => __( 'Доверенные обратные прокси', 'woodev-plugin-framework' ),
				'default'          => '',
				'validate'         => $ip_check,
				'validate_message' => $ip_message,
			]
		);
		$handler->register_control(
			self::TRUSTED_PROXIES_ID,
			\Woodev_Control::TYPE_TEXTAREA,
			[
				'label'   => __( 'Доверенные обратные прокси', 'woodev-plugin-framework' ),
				'tooltip' => __( 'Адреса ваших балансировщиков (nginx, Cloudflare), от которых можно доверять заголовку X-Forwarded-For.', 'woodev-plugin-framework' ),
			]
		);
	}

	/**
	 * Builds per-account push status/action cards and the IP security card; returns `[]` when push is unsupported.
	 *
	 * @since 2.1.2
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array {
		if ( ! $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			return [];
		}

		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
		$sections    = [];
		$now         = (int) call_user_func( $this->clock );

		foreach ( $this->accounts->all() as $account ) {
			$sections[] = $this->account_section( $account, $marketplace, $now );
		}

		$sections[] = Settings_Section::create(
			'notifications_security',
			/* translators: %s: marketplace name. */
			sprintf( __( 'Безопасность уведомлений %s', 'woodev-plugin-framework' ), $marketplace ),
			[ self::ALLOWLIST_ID, self::TRUSTED_PROXIES_ID ]
		);

		return $sections;
	}

	/**
	 * Builds the notification card and four remote action buttons for one account.
	 *
	 * @param Notification_Account $account     Account.
	 * @param string               $marketplace Marketplace name.
	 * @param int                  $now         Current timestamp.
	 * @return Settings_Section
	 */
	private function account_section( Notification_Account $account, string $marketplace, int $now ): Settings_Section {
		$id         = $account->id();
		$raw_url    = Notification_Url::for_account( $this->adapter->id(), $account );
		$masked_url = Report_Redactor::redact_text( $raw_url, [], [ $account->token() ] );
		$admin      = $this->adapter->settings_descriptor()->push_admin( $id );

		$wanted = null !== $admin ? $admin->wanted_descriptions( $account ) : [];
		$state  = $account->state();

		if ( [] === $state ) {
			$state_text = __( 'ещё не проверялось', 'woodev-plugin-framework' );
		} elseif ( null !== $admin ) {
			$state_text = $admin->describe_state( $state );
		} else {
			$state_text = ! empty( $state['enabled'] )
				? __( 'уведомления включены', 'woodev-plugin-framework' )
				: __( 'уведомления отключены', 'woodev-plugin-framework' );
		}

		$latest      = $this->last_notifications->latest( $id );
		$latest_text = null === $latest
			? __( 'пока не поступало', 'woodev-plugin-framework' )
			: sprintf( '%s (%s)', $latest['type'], Diagnostics_Reader::age_text( $latest['received_at'], $now ) );

		$wanted_text = [] === $wanted
			? __( 'по текущим настройкам аккаунта push-события не требуются', 'woodev-plugin-framework' )
			: implode( ', ', $wanted );

		$html = sprintf(
			'<p><strong>%1$s:</strong> <code>%2$s</code></p><p><strong>%3$s:</strong> %4$s</p><p><strong>%5$s:</strong> %6$s</p><p><strong>%7$s:</strong> %8$s</p>',
			esc_html__( 'Адрес для приёма уведомлений (токен скрыт)', 'woodev-plugin-framework' ),
			esc_html( $masked_url ),
			esc_html__( 'Нужные события', 'woodev-plugin-framework' ),
			esc_html( $wanted_text ),
			esc_html__( 'Состояние регистрации', 'woodev-plugin-framework' ),
			esc_html( $state_text ),
			esc_html__( 'Последнее уведомление', 'woodev-plugin-framework' ),
			esc_html( $latest_text )
		);

		$title_label = '' !== trim( $account->external_id() ) ? trim( $account->external_id() ) : ( '#' . $id );

		return Settings_Section::create(
			'notifications_account_' . $id,
			/* translators: 1: marketplace name, 2: account identifier. */
			sprintf( __( 'Уведомления %1$s — «%2$s»', 'woodev-plugin-framework' ), $marketplace, $title_label ),
			[],
			$html
		)->with_actions( $this->tools_for_account( $id ) );
	}

	/**
	 * Builds the four action buttons for an account (`register`, `update_types`, `enable`, `check`).
	 *
	 * @param int $account_id Account id.
	 * @return Shipping_Tool[]
	 */
	private function tools_for_account( int $account_id ): array {
		return [
			Shipping_Tool::create(
				$this->tool_id( $account_id, 'register' ),
				__( 'Регистрация адреса', 'woodev-plugin-framework' ),
				__( 'Передать адрес приёма уведомлений в кабинет маркетплейса', 'woodev-plugin-framework' ),
				__( 'Зарегистрировать URL', 'woodev-plugin-framework' ),
				function () use ( $account_id ): Tool_Result {
					return $this->run_action(
						$account_id,
						static function ( Push_Admin $admin, Notification_Account $account, string $url ): Tool_Result {
							$outcome = $admin->register( $account, $url );
							if ( Push_Admin::DISABLED === $outcome ) {
								return Tool_Result::failure( __( 'Маркетплейс отключил отправку уведомлений на этот адрес. Нажмите «Включить уведомления».', 'woodev-plugin-framework' ) );
							}
							return Tool_Result::success( __( 'Адрес уведомлений зарегистрирован.', 'woodev-plugin-framework' ) );
						}
					);
				}
			),
			Shipping_Tool::create(
				$this->tool_id( $account_id, 'update_types' ),
				__( 'Типы событий', 'woodev-plugin-framework' ),
				__( 'Привести список типов событий в соответствие с настройками аккаунта', 'woodev-plugin-framework' ),
				__( 'Обновить типы', 'woodev-plugin-framework' ),
				function () use ( $account_id ): Tool_Result {
					return $this->run_action(
						$account_id,
						static function ( Push_Admin $admin, Notification_Account $account, string $url ): Tool_Result {
							$outcome = $admin->update_types( $account, $url );
							if ( Push_Admin::NOT_REGISTERED === $outcome ) {
								return Tool_Result::failure( __( 'Адрес ещё не зарегистрирован — сначала нажмите «Зарегистрировать URL».', 'woodev-plugin-framework' ) );
							}
							return Tool_Result::success( __( 'Список типов уведомлений актуален.', 'woodev-plugin-framework' ) );
						}
					);
				}
			),
			Shipping_Tool::create(
				$this->tool_id( $account_id, 'enable' ),
				__( 'Доставка уведомлений', 'woodev-plugin-framework' ),
				__( 'Возобновить доставку уведомлений после автоматического отключения', 'woodev-plugin-framework' ),
				__( 'Включить уведомления', 'woodev-plugin-framework' ),
				function () use ( $account_id ): Tool_Result {
					return $this->run_action(
						$account_id,
						static function ( Push_Admin $admin, Notification_Account $account, string $url ): Tool_Result {
							if ( ! $admin->enable( $account, $url ) ) {
								return Tool_Result::failure( __( 'Адрес ещё не зарегистрирован — сначала нажмите «Зарегистрировать URL».', 'woodev-plugin-framework' ) );
							}
							return Tool_Result::success( __( 'Доставка уведомлений включена.', 'woodev-plugin-framework' ) );
						}
					);
				}
			),
			Shipping_Tool::create(
				$this->tool_id( $account_id, 'check' ),
				__( 'Проверка связи', 'woodev-plugin-framework' ),
				__( 'Попросить маркетплейс отправить проверочный запрос на адрес сайта', 'woodev-plugin-framework' ),
				__( 'Проверить', 'woodev-plugin-framework' ),
				function () use ( $account_id ): Tool_Result {
					return $this->run_action(
						$account_id,
						static function ( Push_Admin $admin, Notification_Account $account, string $url ): Tool_Result {
							$res = $admin->check( $account, $url );
							if ( empty( $res['active'] ) ) {
								$errs = isset( $res['errors'] ) && is_array( $res['errors'] ) ? array_filter( array_map( 'strval', $res['errors'] ) ) : [];
								$msg  = [] !== $errs
									? implode( '; ', $errs )
									: __( 'Проверка уведомлений не прошла.', 'woodev-plugin-framework' );
								return Tool_Result::failure( $msg );
							}
							return Tool_Result::success( __( 'Проверка прошла успешно — маркетплейс достучался до сайта.', 'woodev-plugin-framework' ) );
						}
					);
				}
			),
		];
	}

	/**
	 * Builds a globally unique tool id for one account and action.
	 *
	 * @since 2.1.2
	 *
	 * @param int    $account_id Account id.
	 * @param string $action     Action key (`register`, `update_types`, `enable`, `check`).
	 * @return string
	 */
	public function tool_id( int $account_id, string $action ): string {
		return sprintf( 'woodev_%s_notifications_%d_%s', $this->plugin_slug, $account_id, $action );
	}

	/**
	 * Executes a remote push action under the licence gate, refreshes stored registration state and redacts errors.
	 *
	 * @param int      $account_id Account id.
	 * @param callable $operation  `function ( Push_Admin $admin, Notification_Account $account, string $url ): Tool_Result`.
	 * @return Tool_Result
	 */
	private function run_action( int $account_id, callable $operation ): Tool_Result {
		if ( ! $this->license->is_in_force() ) {
			return Tool_Result::failure( __( 'Лицензия не действует — продлите её на странице лицензии Woodev.', 'woodev-plugin-framework' ) );
		}

		$account = $this->accounts->find( $account_id );
		if ( null === $account ) {
			return Tool_Result::failure( __( 'Аккаунт не найден.', 'woodev-plugin-framework' ) );
		}

		$admin = $this->adapter->settings_descriptor()->push_admin( $account_id );
		if ( null === $admin ) {
			return Tool_Result::failure( __( 'Push-уведомления недоступны для этого аккаунта.', 'woodev-plugin-framework' ) );
		}

		$url = Notification_Url::for_account( $this->adapter->id(), $account );

		try {
			$result = call_user_func( $operation, $admin, $account, $url );
			( new Registration_Refresher( $this->accounts, $this->adapter->id() ) )->refresh( $admin, $account );

			return $result;
		} catch ( \Throwable $e ) {
			$safe = Report_Redactor::redact_text(
				$e->getMessage(),
				$this->adapter->credential_fields()->secrets(),
				[ $account->token() ]
			);

			return Tool_Result::failure( '' !== $safe ? $safe : __( 'Не удалось выполнить запрос к маркетплейсу.', 'woodev-plugin-framework' ) );
		}
	}
}

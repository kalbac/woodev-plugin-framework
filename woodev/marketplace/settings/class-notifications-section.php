<?php
/**
 * Push-notification settings section and per-account registration actions.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Admin\Diagnostics\Diagnostics_Reader;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Redactor;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Url;
use Woodev\Framework\Marketplace\Intake\Registration_Refresher;
use Woodev\Framework\Marketplace\Intake\Sender_Network;
use Woodev\Framework\Marketplace\Intake\Webhook_Switches;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the push-notifications settings cards when the marketplace declares {@see Capability::PUSH_NOTIFICATIONS}:
 *
 * - Completely omitted (`sections() === []` and zero settings registered) when push notifications are unsupported (D2).
 * - A switches card: one on/off switch for every {@see Webhook_Type} the adapter declares, each explained (what it is for, where
 *   in the marketplace cabinet the address goes). The sender addresses and proxies are no merchant's setting ({@see Sender_Network}).
 * - Per-account card shows the full webhook URL to enter in the marketplace cabinet, wanted push event descriptions,
 *   stored registration status and latest received event, plus the four remote registration actions gated on
 *   {@see License_Gate}.
 *
 * @since 2.1.2
 */
final class Notifications_Section implements Settings_Section_Provider {
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
	 * Plugin id, as the option names are built from it.
	 *
	 * @var string
	 */
	private $plugin_id;

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
		$this->plugin_id          = '' !== $plugin_id ? $plugin_id : $adapter->id();
		$this->plugin_slug        = '' !== $plugin_id ? sanitize_key( str_replace( '-', '_', $plugin_id ) ) : $adapter->id();
		$this->clock              = $clock ?? 'time';
	}

	/**
	 * Registers one on/off switch for every notification type the marketplace declares.
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

		foreach ( $this->webhook_types() as $type ) {
			$id = Webhook_Switches::setting_id( $type->id() );

			$handler->register_setting(
				$id,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => $type->label(),
					'default' => $type->default_enabled(),
				]
			);
			$handler->register_control(
				$id,
				\Woodev_Control::TYPE_TOGGLE,
				[
					'label'   => $type->label(),
					'tooltip' => $type->purpose(),
				]
			);
		}
	}

	/**
	 * Builds the switches card and the per-account push status/action cards; returns `[]` when push is unsupported.
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
		$types       = $this->webhook_types();
		$notice      = $this->proxy_notice();
		$registers   = false;

		foreach ( $this->accounts->all() as $account ) {
			$registers = $registers || null !== $this->adapter->push_admin( $account->id() );
		}

		if ( [] !== $types || '' !== $notice ) {
			$sections[] = Settings_Section::create(
				'notifications_webhooks',
				/* translators: %s: marketplace name. */
				sprintf( __( 'Какие уведомления получать от %s', 'woodev-plugin-framework' ), $marketplace ),
				array_map(
					static function ( Webhook_Type $type ): string {
						return Webhook_Switches::setting_id( $type->id() );
					},
					$types
				),
				$this->webhooks_html( $types, $marketplace, $registers ) . $notice
			);
		}

		foreach ( $this->accounts->all() as $account ) {
			$sections[] = $this->account_section( $account, $marketplace, $now );
		}

		return $sections;
	}

	/**
	 * Returns the notification types the marketplace declares.
	 *
	 * @return Webhook_Type[]
	 */
	private function webhook_types(): array {
		$protocol = $this->adapter->push_protocol();

		return null === $protocol ? [] : $protocol->webhook_types();
	}

	/**
	 * Explains the switches: what every notification is for and where in the marketplace cabinet its address goes.
	 *
	 * @param Webhook_Type[] $types       Types.
	 * @param string         $marketplace Marketplace name.
	 * @param bool           $registers   Whether the adapter registers the address in the cabinet by itself.
	 * @return string
	 */
	private function webhooks_html( array $types, string $marketplace, bool $registers ): string {
		if ( [] === $types ) {
			return '';
		}

		$items = '';

		foreach ( $types as $type ) {
			$items .= sprintf(
				'<li><strong>%1$s</strong> — %2$s %3$s</li>',
				esc_html( $type->label() ),
				esc_html( $type->purpose() ),
				'' === $type->cabinet_location() ? '' : '<em>' . esc_html( sprintf( /* translators: %s: place in the marketplace cabinet. */ __( 'Где указать адрес: %s.', 'woodev-plugin-framework' ), $type->cabinet_location() ) ) . '</em>'
			);
		}

		return sprintf(
			'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
			/* translators: %s: marketplace name. */
			esc_html( sprintf( __( 'Включите уведомления, которые вам нужны: %s сообщит о них сайту сразу, а выключенные сайт не станет принимать.', 'woodev-plugin-framework' ), $marketplace ) ),
			$items,
			$registers
				? esc_html__( 'Адрес сайта для уведомлений показан в карточках аккаунтов ниже; кнопка «Зарегистрировать URL» сама передаёт его в личный кабинет, вписывать его вручную не нужно.', 'woodev-plugin-framework' )
				: esc_html__( 'Скопируйте адрес сайта для уведомлений из карточки аккаунта ниже и впишите его в личном кабинете там, где это указано выше.', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Tells, in debug mode only, that the site stands behind a reverse proxy.
	 *
	 * @return string HTML paragraph, or '' when the site is not behind a proxy or «Логирование» is off.
	 */
	private function proxy_notice(): string {
		if ( ! ( new Logging( Logging::option_name_for( $this->plugin_id ) ) )->is_enabled() || ! Sender_Network::behind_proxy( $_SERVER ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the peer address is read, and it is validated as an IP.
			return '';
		}

		return sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: name of the filter. */
					__( 'Сайт работает за прокси или CDN: сервер видит адрес прокси, а не отправителя. Уведомления по адресам с секретным ключом (как в карточках ниже) это не затрагивает — они принимаются по ключу. Старый адрес без ключа проверяет отправителя по IP, и за прокси такая проверка может отклонять настоящие уведомления; разработчик указывает адреса своих прокси фильтром %s.', 'woodev-plugin-framework' ),
					'woodev_' . str_replace( '-', '_', $this->adapter->id() ) . '_push_trusted_proxies'
				)
			)
		);
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
		// The full address, token included: the merchant copies it into the marketplace cabinet (v1 showed it too). The settings
		// screen is for administrators; logs and the support report still redact the token.
		$url   = Notification_Url::for_account( $this->adapter->id(), $account );
		$admin = $this->adapter->push_admin( $id );

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
			esc_html__( 'Адрес для приёма уведомлений', 'woodev-plugin-framework' ),
			esc_html( $url ),
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

		$admin = $this->adapter->push_admin( $account_id );
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

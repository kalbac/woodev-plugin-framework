<?php
/**
 * Diagnostics sections and redacted support-report builder.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Diagnostics\Support_Report;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Url;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the sections of the marketplace diagnostics screen and the copyable plain-text support report.
 *
 * Strict debug-gating invariant:
 * - While «Логирование» ({@see Logging::is_enabled()}) is OFF, {@see self::operational_sections()} shows only calm
 *   merchant-facing status (no troubleshooting hints), {@see self::diagnostic_sections()} returns `[]`, and
 *   {@see self::support_report()} returns `''`.
 * - Every section and the final plain-text report pass through {@see Support_Report::build()} and
 *   {@see Report_Redactor}, scrubbing HTTP auth headers, webhook tokens, adapter-declared secret field names/labels
 *   and any plaintext secret values held on the accounts.
 *
 * @since 2.1.2
 */
final class Report_Builder {
	/**
	 * Marketplace adapter.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Account store.
	 *
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * Notification account reader (optional when push notifications are unsupported).
	 *
	 * @var Notification_Account_Reader|null
	 */
	private $notification_accounts;

	/**
	 * Last notification reader (optional when push notifications are unsupported).
	 *
	 * @var Last_Notification_Reader|null
	 */
	private $last_notifications;

	/**
	 * Cached warehouse options reader.
	 *
	 * @var Warehouse_Options
	 */
	private $warehouses;

	/**
	 * Licence gate.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Logging toggle reader.
	 *
	 * @var Logging
	 */
	private $logging;

	/**
	 * Queue health reader.
	 *
	 * @var Queue_Health
	 */
	private $queue;

	/**
	 * Diagnostics table reader.
	 *
	 * @var Diagnostics_Reader
	 */
	private $reader;

	/**
	 * Site and environment facts reader.
	 *
	 * @var Site_Facts
	 */
	private $site;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the report builder.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Adapter              $adapter               Marketplace adapter.
	 * @param Account_Store                    $accounts              Account store.
	 * @param Warehouse_Options                $warehouses            Cached warehouse names reader.
	 * @param License_Gate                     $license               Licence gate.
	 * @param Logging                          $logging               Logging toggle reader.
	 * @param Queue_Health                     $queue                 Queue health reader.
	 * @param Diagnostics_Reader               $reader                Diagnostics DB reader.
	 * @param Site_Facts                       $site                  Site facts reader.
	 * @param Notification_Account_Reader|null $notification_accounts Notification account reader (when push is supported).
	 * @param Last_Notification_Reader|null    $last_notifications    Latest notification reader (when push is supported).
	 * @param callable|null                    $clock                 `function (): int`, defaults to `time`.
	 */
	public function __construct(
		Marketplace_Adapter $adapter,
		Account_Store $accounts,
		Warehouse_Options $warehouses,
		License_Gate $license,
		Logging $logging,
		Queue_Health $queue,
		Diagnostics_Reader $reader,
		Site_Facts $site,
		?Notification_Account_Reader $notification_accounts = null,
		?Last_Notification_Reader $last_notifications = null,
		?callable $clock = null
	) {
		$this->adapter               = $adapter;
		$this->accounts              = $accounts;
		$this->warehouses            = $warehouses;
		$this->license               = $license;
		$this->logging               = $logging;
		$this->queue                 = $queue;
		$this->reader                = $reader;
		$this->site                  = $site;
		$this->notification_accounts = $notification_accounts;
		$this->last_notifications    = $last_notifications;
		$this->clock                 = $clock ?? 'time';
	}

	/**
	 * Builds the always-visible operational sections (licence + one card per account).
	 *
	 * Troubleshooting hints («Что делать») appear ONLY when «Логирование» is enabled.
	 *
	 * @since 2.1.2
	 *
	 * @return Report_Section[]
	 */
	public function operational_sections(): array {
		$all_accounts = $this->loaded_accounts();
		$redaction    = $this->redaction_context( $all_accounts );
		$push_map     = $this->notification_accounts_by_id();
		$now          = (int) call_user_func( $this->clock );

		$sections   = [];
		$sections[] = new Report_Section(
			__( 'Лицензия', 'woodev-plugin-framework' ),
			[
				__( 'Состояние', 'woodev-plugin-framework' ) => $this->license->is_in_force()
					? __( 'действует', 'woodev-plugin-framework' )
					: __( 'недействительна — фоновые задачи остановлены', 'woodev-plugin-framework' ),
			]
		);

		if ( [] === $all_accounts ) {
			$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
			$sections[]  = new Report_Section(
				/* translators: %s: marketplace name. */
				sprintf( __( 'Аккаунты %s', 'woodev-plugin-framework' ), $marketplace ),
				[
					__( 'Состояние', 'woodev-plugin-framework' ) => __( 'ни один аккаунт ещё не добавлен', 'woodev-plugin-framework' ),
				]
			);

			return $this->redact_sections( $sections, $redaction );
		}

		foreach ( $all_accounts as $account ) {
			$sections[] = $this->account_operational_section(
				$account,
				$push_map[ $account->id() ] ?? null,
				$now,
				$redaction
			);
		}

		return $this->redact_sections( $sections, $redaction );
	}

	/**
	 * Builds the debug-only diagnostic sections; returns `[]` when «Логирование» is disabled.
	 *
	 * @since 2.1.2
	 *
	 * @return Report_Section[]
	 */
	public function diagnostic_sections(): array {
		if ( ! $this->logging->is_enabled() ) {
			return [];
		}

		$all_accounts = $this->loaded_accounts();
		$redaction    = $this->redaction_context( $all_accounts );
		$now          = (int) call_user_func( $this->clock );
		$sections     = [];

		$sections[] = $this->queue_section( $now );

		foreach ( $all_accounts as $account ) {
			$quota_section = $this->quota_section( $account );
			if ( null !== $quota_section ) {
				$sections[] = $quota_section;
			}

			foreach ( $this->adapter->support_sections( $account ) as $extra ) {
				if ( $extra instanceof Report_Section ) {
					$sections[] = $extra;
				}
			}

			if ( $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
				$sections[] = $this->events_section( $account, $redaction );
			}

			$sections[] = $this->backlog_section( $account );
		}

		$sections[] = $this->errors_section( $redaction );
		$sections[] = $this->site->site_section();

		return $this->redact_sections( $sections, $redaction );
	}

	/**
	 * Builds the redacted plain-text report for support; returns `''` when «Логирование» is disabled.
	 *
	 * @since 2.1.2
	 *
	 * @param Report_Section[]|null $operational Pre-built operational sections, or null to build them now.
	 * @param Report_Section[]|null $diagnostic  Pre-built diagnostic sections, or null to build them now.
	 * @return string
	 */
	public function support_report( ?array $operational = null, ?array $diagnostic = null ): string {
		if ( ! $this->logging->is_enabled() ) {
			return '';
		}

		$all_accounts = $this->loaded_accounts();
		$redaction    = $this->redaction_context( $all_accounts );
		$operational  = $operational ?? $this->operational_sections();
		$diagnostic   = $diagnostic ?? $this->diagnostic_sections();
		$environment  = $this->redact_sections( $this->site->environment_sections(), $redaction );

		$all_sections = $this->redact_sections(
			array_merge( $operational, $diagnostic, $environment ),
			$redaction
		);

		$built = Support_Report::build( $all_sections );

		return Report_Redactor::redact_text( $built, $redaction['fields'], $redaction['literals'] );
	}

	/**
	 * Loads accounts belonging to this adapter.
	 *
	 * @return Account[]
	 */
	private function loaded_accounts(): array {
		$list = [];
		foreach ( $this->accounts->all( $this->adapter->id(), false ) as $account ) {
			if ( $account->adapter_id() === $this->adapter->id() ) {
				$list[] = $account;
			}
		}

		return $list;
	}

	/**
	 * Reads stored health when the account store exposes it (`Wpdb_Account_Store` or a test double with `health()`).
	 *
	 * @param int $account_id Account id.
	 * @return Health_Result|null
	 */
	private function read_health( int $account_id ): ?Health_Result {
		if ( $this->accounts instanceof Wpdb_Account_Store ) {
			return $this->accounts->health( $account_id );
		}

		if ( method_exists( $this->accounts, 'health' ) ) {
			$health = $this->accounts->health( $account_id );

			return $health instanceof Health_Result ? $health : null;
		}

		if ( method_exists( $this->accounts, 'health_of' ) ) {
			$health = $this->accounts->health_of( $account_id );

			return $health instanceof Health_Result ? $health : null;
		}

		return null;
	}

	/**
	 * Maps notification accounts by id when push notifications are supported.
	 *
	 * @return array<int,Notification_Account>
	 */
	private function notification_accounts_by_id(): array {
		if ( null === $this->notification_accounts || ! $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			return [];
		}

		try {
			$map = [];
			foreach ( $this->notification_accounts->all() as $entry ) {
				$map[ $entry->id() ] = $entry;
			}
			return $map;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Builds the operational card of one account.
	 *
	 * @param Account                                                  $account              Account.
	 * @param Notification_Account|null                                $notification_account Notification view of the account, if any.
	 * @param int                                                      $now                  Current timestamp.
	 * @param array{fields:string[],labels:string[],literals:string[]} $redaction            Redaction context.
	 * @return Report_Section
	 */
	private function account_operational_section(
		Account $account,
		?Notification_Account $notification_account,
		int $now,
		array $redaction
	): Report_Section {
		$descriptor  = $this->adapter->settings_descriptor();
		$marketplace = $descriptor->marketplace_name();
		$id_field    = $this->adapter->credential_fields()->identifier();
		$id_label    = $descriptor->field_spec( $id_field )->label();
		$external_id = (string) ( $account->credentials()[ $id_field ] ?? '' );
		$unreadable  = ! empty( $account->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] );

		$health = $this->read_health( $account->id() );
		$status = $unreadable ? Health_Result::AUTH_FAILED : ( null !== $health ? $health->status() : 'unknown' );

		$lines = [
			$id_label                                                 => Support_Report::mask_identifier( $external_id ),
			__( 'Аккаунт', 'woodev-plugin-framework' )                => $account->is_enabled()
				? __( 'включён', 'woodev-plugin-framework' )
				: __( 'выключен', 'woodev-plugin-framework' ),
			__( 'Состояние подключения', 'woodev-plugin-framework' )  => $this->status_text( $status, $marketplace ),
		];

		$message = $unreadable
			? $descriptor->unreadable_secrets_message()
			: ( null !== $health ? trim( $health->message() ) : '' );

		if ( '' !== $message ) {
			/* translators: %s: marketplace name. */
			$msg_label           = sprintf( __( 'Сообщение %s', 'woodev-plugin-framework' ), $marketplace );
			$lines[ $msg_label ] = Report_Redactor::redact_text( $message, $redaction['fields'], $redaction['literals'] );
		}

		// Debug-only hint: merchants read troubleshooting prompts as active breakage when logging is off.
		if ( $this->logging->is_enabled() && Health_Result::OK !== $status ) {
			$hint = trim( $descriptor->health_hint( $status ) );
			if ( '' !== $hint ) {
				$lines[ __( 'Что делать', 'woodev-plugin-framework' ) ] = $hint;
			}
		}

		$expires_at = $account->expires_at();
		if ( null !== $expires_at ) {
			$lines[ __( 'Ключ действует до', 'woodev-plugin-framework' ) ] = (string) wp_date( 'd.m.Y', $expires_at->getTimestamp() );
		}

		$currency = trim( (string) $account->setting( 'currency', '' ) );
		if ( '' !== $currency ) {
			$lines[ __( 'Валюта кабинета', 'woodev-plugin-framework' ) ] = $currency;
		}

		$warehouse_id = (int) $account->setting( 'warehouse_id', 0 );
		if ( $warehouse_id > 0 ) {
			$names     = $this->warehouses->for_account( $account->id() );
			$warehouse = $names[ (string) $warehouse_id ] ?? ( '#' . $warehouse_id );
			$lines[ __( 'Склад по умолчанию', 'woodev-plugin-framework' ) ] = $warehouse;
		}

		if ( $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			/* translators: %s: marketplace name. */
			$push_label           = sprintf( __( 'Уведомления %s', 'woodev-plugin-framework' ), $marketplace );
			$lines[ $push_label ] = $this->push_summary( $account->id(), $notification_account, $now );

			if ( null !== $notification_account ) {
				$raw_url = Notification_Url::for_account( $this->adapter->id(), $notification_account );
				$lines[ __( 'Адрес уведомлений', 'woodev-plugin-framework' ) ] = Report_Redactor::redact_text(
					$raw_url,
					$redaction['fields'],
					$redaction['literals']
				);
			}
		}

		return new Report_Section(
			/* translators: %s: account display label. */
			sprintf( __( 'Аккаунт «%s»', 'woodev-plugin-framework' ), $this->account_title( $account ) ),
			$lines
		);
	}

	/**
	 * Builds the «Фоновые задачи» diagnostic section.
	 *
	 * @param int $now Current timestamp.
	 * @return Report_Section
	 */
	private function queue_section( int $now ): Report_Section {
		$last_run = $this->queue->last_run_at();
		$overdue  = $this->queue->overdue_count();
		$ledger   = $this->queue->ledger_summary();

		$lines = [
			__( 'Последний запуск очереди', 'woodev-plugin-framework' ) => null === $last_run
				? __( 'нет данных', 'woodev-plugin-framework' )
				: sprintf( '%s (%s)', Diagnostics_Reader::date_text( $last_run ), Diagnostics_Reader::age_text( $last_run, $now ) ),
			__( 'Просроченных задач плагина', 'woodev-plugin-framework' ) => (string) $overdue,
			__( 'Входящие события (ожидают / в работе / с ошибкой)', 'woodev-plugin-framework' ) => sprintf(
				'%d / %d / %d',
				$ledger['events_pending'],
				$ledger['events_processing'],
				$ledger['events_failed']
			),
			__( 'Открытых фоновых заданий / позиций с ошибкой', 'woodev-plugin-framework' ) => sprintf(
				'%d / %d',
				$ledger['open_tasks'],
				$ledger['failed_task_items']
			),
		];

		return new Report_Section( __( 'Фоновые задачи', 'woodev-plugin-framework' ), $lines );
	}

	/**
	 * Builds the cached quota snapshot section of an account when one is stored for its current identifier.
	 *
	 * Reads `Wpdb_Account_Store::remember_limits()` shape (`['external_id' => ..., 'fetched_at' => ..., 'limits' => ...]`).
	 *
	 * @param Account $account Account.
	 * @return Report_Section|null
	 */
	private function quota_section( Account $account ): ?Report_Section {
		$snapshot = $account->setting( 'limits_snapshot' );
		if ( ! is_array( $snapshot ) ) {
			return null;
		}

		$id_field    = $this->adapter->credential_fields()->identifier();
		$current_id  = (string) ( $account->credentials()[ $id_field ] ?? '' );
		$snapshot_id = (string) ( $snapshot['external_id'] ?? '' );

		if ( '' === $snapshot_id || $snapshot_id !== $current_id ) {
			return null;
		}

		$limits = isset( $snapshot['limits'] ) && is_array( $snapshot['limits'] ) ? $snapshot['limits'] : [];
		if ( [] === $limits ) {
			return null;
		}

		$lines = [];
		foreach ( $limits as $key => $entry ) {
			if ( is_scalar( $entry ) ) {
				$lines[ (string) $key ] = (string) $entry;
			} elseif ( is_array( $entry ) && ( isset( $entry['usage'] ) || isset( $entry['limit'] ) ) ) {
				$limit_val = (int) ( $entry['limit'] ?? 0 );
				$usage_val = (int) ( $entry['usage'] ?? 0 );
				$reset     = isset( $entry['reset_at'] ) && '' !== (string) $entry['reset_at']
					? ' (' . (string) $entry['reset_at'] . ')'
					: '';
				$lines[ (string) $key ] = $limit_val > 0
					? sprintf( '%d / %d%s', $usage_val, $limit_val, $reset )
					: sprintf( '%d%s', $usage_val, $reset );
			}
		}

		if ( [] === $lines ) {
			return null;
		}

		$fetched_at = (int) ( $snapshot['fetched_at'] ?? 0 );
		if ( $fetched_at > 0 ) {
			$lines[ __( 'Снимок обновлён', 'woodev-plugin-framework' ) ] = Diagnostics_Reader::date_text( $fetched_at );
		}

		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();

		return new Report_Section(
			/* translators: 1: marketplace name, 2: account display label. */
			sprintf( __( 'Лимиты %1$s — «%2$s»', 'woodev-plugin-framework' ), $marketplace, $this->account_title( $account ) ),
			$lines
		);
	}

	/**
	 * Builds the recent push notifications section of an account.
	 *
	 * @param Account                                                  $account   Account.
	 * @param array{fields:string[],labels:string[],literals:string[]} $redaction Redaction context.
	 * @return Report_Section
	 */
	private function events_section( Account $account, array $redaction ): Report_Section {
		$events = $this->reader->recent_events( $account->id(), 10, $redaction['fields'], $redaction['literals'] );
		$lines  = [];

		if ( [] === $events ) {
			$lines[ __( 'Уведомления', 'woodev-plugin-framework' ) ] = __( 'записей пока нет', 'woodev-plugin-framework' );
		} else {
			foreach ( $events as $event ) {
				$label = sprintf( '#%d %s', $event['event_id'], $event['type'] );
				$tail  = '' !== $event['result'] ? ' — ' . $event['result'] : '';
				$lines[ $label ] = sprintf(
					'%s, %s, попыток: %d%s',
					Diagnostics_Reader::date_text( $event['received_at'] ),
					$event['status'],
					$event['attempts'],
					$tail
				);
			}
		}

		return new Report_Section(
			/* translators: %s: account display label. */
			sprintf( __( 'Последние уведомления — «%s»', 'woodev-plugin-framework' ), $this->account_title( $account ) ),
			$lines
		);
	}

	/**
	 * Builds the product links and sync backlog section of an account.
	 *
	 * @param Account $account Account.
	 * @return Report_Section
	 */
	private function backlog_section( Account $account ): Report_Section {
		$counts  = $this->reader->link_counts( $account->id() );
		$backlog = $this->reader->sync_backlog( $account->id() );

		$lines = [
			__( 'Карточки (готовы / в обработке / ошибка / архив)', 'woodev-plugin-framework' ) => sprintf(
				'%d / %d / %d / %d',
				$counts['ready'],
				$counts['pending'],
				$counts['failed'],
				$counts['archived']
			),
			__( 'Очередь остатков (ожидают / ошибки)', 'woodev-plugin-framework' )              => sprintf(
				'%d / %d',
				$backlog['stock_pending'],
				$backlog['stock_errors']
			),
			__( 'Очередь цен (ожидают / ошибки)', 'woodev-plugin-framework' )                   => sprintf(
				'%d / %d',
				$backlog['price_pending'],
				$backlog['price_errors']
			),
			__( 'Задания импорта (открыты / позиций с ошибкой)', 'woodev-plugin-framework' )    => sprintf(
				'%d / %d',
				$backlog['open_tasks'],
				$backlog['failed_items']
			),
		];

		if ( null !== $backlog['throttled_until'] ) {
			$lines[ __( 'Ограничение частоты до', 'woodev-plugin-framework' ) ] = Diagnostics_Reader::date_text( $backlog['throttled_until'] );
		}

		return new Report_Section(
			/* translators: %s: account display label. */
			sprintf( __( 'Товары и очередь — «%s»', 'woodev-plugin-framework' ), $this->account_title( $account ) ),
			$lines
		);
	}

	/**
	 * Builds the recent API errors section.
	 *
	 * @param array{fields:string[],labels:string[],literals:string[]} $redaction Redaction context.
	 * @return Report_Section
	 */
	private function errors_section( array $redaction ): Report_Section {
		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
		$errors      = $this->reader->recent_errors( 20, $redaction['fields'], $redaction['literals'] );
		$lines       = [];

		if ( [] === $errors ) {
			$lines[ __( 'Ошибки API', 'woodev-plugin-framework' ) ] = __( 'ошибок не зафиксировано', 'woodev-plugin-framework' );
		} else {
			foreach ( $errors as $index => $err ) {
				$label = sprintf(
					'%d. %s [HTTP %d]',
					$index + 1,
					Diagnostics_Reader::date_text( $err['occurred_at'] ),
					$err['http_status']
				);
				$code  = '' !== $err['code'] ? ' (' . $err['code'] . ')' : '';
				$lines[ $label ] = sprintf(
					'аккаунт #%d %s%s: %s',
					$err['account_id'],
					$err['path'],
					$code,
					$err['message']
				);
			}
		}

		return new Report_Section(
			/* translators: %s: marketplace name. */
			sprintf( __( 'Последние ошибки %s', 'woodev-plugin-framework' ), $marketplace ),
			$lines
		);
	}

	/**
	 * Formats the localized connection status sentence.
	 *
	 * @param string $status      Health status or `unknown`.
	 * @param string $marketplace Marketplace name.
	 * @return string
	 */
	private function status_text( string $status, string $marketplace ): string {
		switch ( $status ) {
			case Health_Result::OK:
				return __( 'подключено', 'woodev-plugin-framework' );
			case Health_Result::AUTH_FAILED:
				return __( 'ключ неверный или не читается', 'woodev-plugin-framework' );
			case Health_Result::ROLE_MISSING:
				return __( 'у ключа нет нужных прав', 'woodev-plugin-framework' );
			case Health_Result::KEY_EXPIRED:
				return __( 'ключ истёк', 'woodev-plugin-framework' );
			case Health_Result::UNREACHABLE:
				/* translators: %s: marketplace name. */
				return sprintf( __( '%s не отвечает', 'woodev-plugin-framework' ), $marketplace );
		}

		return __( 'ещё не проверялось', 'woodev-plugin-framework' );
	}

	/**
	 * Summarizes push webhook registration and latest event for an account without remote calls.
	 *
	 * @param int                       $account_id           Account id.
	 * @param Notification_Account|null $notification_account Notification account view.
	 * @param int                       $now                  Current timestamp.
	 * @return string
	 */
	private function push_summary( int $account_id, ?Notification_Account $notification_account, int $now ): string {
		$state = null !== $notification_account ? $notification_account->state() : [];
		$admin = $this->adapter->settings_descriptor()->push_admin( $account_id );

		if ( [] === $state ) {
			$head = __( 'не проверялось', 'woodev-plugin-framework' );
		} elseif ( null !== $admin ) {
			$head = $admin->describe_state( $state );
		} elseif ( empty( $state['enabled'] ) ) {
			$head = __( 'отключены', 'woodev-plugin-framework' );
		} else {
			$head = __( 'включены', 'woodev-plugin-framework' );
		}

		$latest = null !== $this->last_notifications ? $this->last_notifications->latest( $account_id ) : null;
		if ( null === $latest ) {
			return $head;
		}

		return sprintf(
			/* translators: 1: registration state, 2: event type, 3: relative time. */
			__( '%1$s; последнее: %2$s (%3$s)', 'woodev-plugin-framework' ),
			$head,
			$latest['type'],
			Diagnostics_Reader::age_text( $latest['received_at'], $now )
		);
	}

	/**
	 * Display title of an account (name, or masked identifier, or numeric id).
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	private function account_title( Account $account ): string {
		if ( '' !== trim( $account->name() ) ) {
			return trim( $account->name() );
		}

		$id_field    = $this->adapter->credential_fields()->identifier();
		$external_id = trim( (string) ( $account->credentials()[ $id_field ] ?? '' ) );

		return '' !== $external_id ? Support_Report::mask_identifier( $external_id ) : (string) $account->id();
	}

	/**
	 * Collects adapter secret field names, labels and live secret/token literals across loaded accounts for redaction.
	 *
	 * @param Account[] $accounts Loaded accounts.
	 * @return array{fields:string[],labels:string[],literals:string[]}
	 */
	private function redaction_context( array $accounts ): array {
		$secret_fields = $this->adapter->credential_fields()->secrets();
		$descriptor    = $this->adapter->settings_descriptor();
		$labels        = [];

		foreach ( $secret_fields as $field ) {
			$labels[] = $descriptor->field_spec( $field )->label();
		}

		$literals = [];
		foreach ( $accounts as $account ) {
			$creds = $account->credentials();
			foreach ( $secret_fields as $field ) {
				if ( isset( $creds[ $field ] ) && is_string( $creds[ $field ] ) && '' !== $creds[ $field ] ) {
					$literals[] = $creds[ $field ];
				}
			}
			$token = $account->setting( 'notification_token', '' );
			if ( is_string( $token ) && '' !== $token ) {
				$literals[] = $token;
			}
		}

		foreach ( $this->notification_accounts_by_id() as $notif_account ) {
			if ( '' !== $notif_account->token() ) {
				$literals[] = $notif_account->token();
			}
		}

		return [
			'fields'   => $secret_fields,
			'labels'   => $labels,
			'literals' => $literals,
		];
	}

	/**
	 * Runs {@see Report_Redactor::redact_section()} across a list of sections.
	 *
	 * @param Report_Section[]                                         $sections  Sections.
	 * @param array{fields:string[],labels:string[],literals:string[]} $redaction Redaction context.
	 * @return Report_Section[]
	 */
	private function redact_sections( array $sections, array $redaction ): array {
		$out = [];
		foreach ( $sections as $section ) {
			if ( $section instanceof Report_Section ) {
				$out[] = Report_Redactor::redact_section(
					$section,
					$redaction['fields'],
					$redaction['literals'],
					$redaction['labels']
				);
			}
		}

		return $out;
	}
}

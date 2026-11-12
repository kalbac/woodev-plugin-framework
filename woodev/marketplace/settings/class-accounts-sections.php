<?php
/**
 * Multi-account connection and parameter sections for a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Redactor;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Settings\Settings_Group;
use Woodev\Framework\Settings\Settings_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists every seller account of a marketplace plugin over {@see Wpdb_Account_Store}:
 *
 * - Credential fields come dynamically from {@see Marketplace_Adapter::credential_fields()} (`identifier()` + all
 *   `secrets()`) and {@see Settings_Descriptor::field_spec()}, never hardcoded to one marketplace's field names.
 * - Every secret field is registered with `sensitive => true` and `Woodev_Control::TYPE_PASSWORD`, so `Field_Schema`
 *   never emits the plaintext value to the browser and an untouched blank submission preserves the stored secret.
 * - Optional capabilities (`INBOUND_STOCK`, `IMPORT_PRODUCTS`, `price_pusher()`, `price_reader()`, `posting_handler()`)
 *   are gated by D2: unsupported features are omitted from the UI rather than greyed out.
 * - Price-rule changes run inside `Wpdb_Account_Store::update_settings()` compare-and-swap and fire
 *   `woodev_{plugin_id}_account_price_rules_changed`, which `Flow_Wiring` listens to and queues a recompute for.
 *
 * @since 2.1.2
 */
final class Accounts_Sections implements Connection_Section_Provider, Table_Backed_Settings {
	public const NEW_KEY = 'new';

	private const CONNECTION_PREFIX = 'account_connection_';
	private const PARAMS_PREFIX     = 'account_params_';

	private const PRICE_RULE_FIELDS = [
		'price_base'           => 'base',
		'price_markup_kind'    => 'markup_kind',
		'price_markup'         => 'markup',
		'price_rounding'       => 'rounding',
		'price_old_price_rule' => 'old_price_rule',
		'price_min_percent'    => 'min_percent',
	];

	/**
	 * Table-backed account store.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $store;

	/**
	 * Marketplace adapter.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Stored connection status builder.
	 *
	 * @var Account_Status
	 */
	private $status;

	/**
	 * Cached warehouse choices reader.
	 *
	 * @var Warehouse_Options
	 */
	private $warehouses;

	/**
	 * Action hook fired when an account's price rule or price direction changes.
	 *
	 * @var string
	 */
	private $price_rules_changed_hook;

	/**
	 * Optional callback (`function ( int $account_id ): void`) invoked after a `Health_Result::OK` connection test.
	 *
	 * @var callable|null
	 */
	private $on_connected;

	/**
	 * Loaded accounts keyed by account id (`null` before first load).
	 *
	 * @var array<int,Account>|null
	 */
	private $accounts = null;

	/**
	 * Pending field values for the `new` account block within the current save pass.
	 *
	 * @var array<string,mixed>
	 */
	private $pending = [];

	/**
	 * Account id created from the `new` block during the current save pass (`null` before creation).
	 *
	 * @var int|null
	 */
	private $created_id = null;

	/**
	 * Creates the accounts sections provider.
	 *
	 * @since 2.1.2
	 *
	 * @param Wpdb_Account_Store  $store        Account store.
	 * @param Marketplace_Adapter $adapter      Marketplace adapter.
	 * @param Account_Status      $status       Connection status formatter.
	 * @param Warehouse_Options   $warehouses   Cached warehouses reader.
	 * @param string              $plugin_id    Plugin id (drives `Job_Names` hooks).
	 * @param callable|null       $on_connected Optional `function ( int $account_id ): void` called after an `ok` connection test.
	 */
	public function __construct(
		Wpdb_Account_Store $store,
		Marketplace_Adapter $adapter,
		Account_Status $status,
		Warehouse_Options $warehouses,
		string $plugin_id,
		?callable $on_connected = null
	) {
		$this->store                    = $store;
		$this->adapter                  = $adapter;
		$this->status                   = $status;
		$this->warehouses               = $warehouses;
		$this->price_rules_changed_hook = ( new Job_Names( $plugin_id ) )->hook( 'account_price_rules_changed' );
		$this->on_connected             = $on_connected;
	}

	/**
	 * Builds a field id (`account_{id|new}_{field}`).
	 *
	 * @since 2.1.2
	 *
	 * @param string $key   Account id or `self::NEW_KEY`.
	 * @param string $field Field name.
	 * @return string
	 */
	public static function setting_id( string $key, string $field ): string {
		return 'account_' . $key . '_' . $field;
	}

	/**
	 * Builds the connection section id (`account_connection_{id|new}`).
	 *
	 * @since 2.1.2
	 *
	 * @param string $key Account id or `self::NEW_KEY`.
	 * @return string
	 */
	public static function connection_section_id( string $key ): string {
		return self::CONNECTION_PREFIX . $key;
	}

	/**
	 * Builds the parameters section id (`account_params_{id}`).
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	public static function params_section_id( int $account_id ): string {
		return self::PARAMS_PREFIX . $account_id;
	}

	/**
	 * Registers connection and parameter fields for every saved account plus the `new` account block.
	 *
	 * @since 2.1.2
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
		foreach ( $this->accounts() as $account ) {
			$key = (string) $account->id();
			$this->register_connection_fields( $handler, $key );
			$this->register_parameter_fields( $handler, $key, $account->id() );
		}

		$this->register_connection_fields( $handler, self::NEW_KEY );
	}

	/**
	 * Builds the ordered list of connection and parameter sections.
	 *
	 * @since 2.1.2
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array {
		$accounts = $this->accounts();
		$sections = [];

		foreach ( $accounts as $account ) {
			$key        = (string) $account->id();
			$sections[] = $this->connection_section( $key, $this->account_title( $account ) );

			$params = $this->parameters_section( $account );
			if ( null !== $params ) {
				$sections[] = $params;
			}
		}

		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
		$new_title   = [] === $accounts
			/* translators: %s: marketplace name. */
			? sprintf( __( 'Подключение к %s', 'woodev-plugin-framework' ), $marketplace )
			: __( 'Добавить ещё один аккаунт', 'woodev-plugin-framework' );

		$sections[] = $this->connection_section( self::NEW_KEY, $new_title );

		return $sections;
	}

	/**
	 * Tells whether `$setting_id` belongs to an account block.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @return bool
	 */
	public function handles( string $setting_id ): bool {
		return null !== $this->parse( $setting_id );
	}

	/**
	 * Reads the current value of an account field.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @return mixed
	 */
	public function read( string $setting_id ) {
		$parsed = $this->parse( $setting_id );

		if ( null === $parsed || self::NEW_KEY === $parsed['key'] ) {
			return null !== $parsed && $this->is_boolean_field( $parsed['field'] ) ? false : '';
		}

		$account = $this->accounts()[ (int) $parsed['key'] ] ?? null;

		if ( null === $account ) {
			return $this->is_boolean_field( $parsed['field'] ) ? false : '';
		}

		return $this->value_of( $account, $parsed['field'] );
	}

	/**
	 * Writes one account field using targeted store mutations (never overwriting sibling fields or untouched secrets).
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @param mixed  $value      Submitted value.
	 * @return void
	 */
	public function write( string $setting_id, $value ): void {
		$parsed = $this->parse( $setting_id );

		if ( null === $parsed ) {
			return;
		}

		if ( self::NEW_KEY === $parsed['key'] ) {
			$this->write_new_block( $parsed['field'], $value );
			return;
		}

		$this->write_account( (int) $parsed['key'], $parsed['field'], $value );
	}

	/**
	 * Validates credential formats, duplicate identifiers, half-filled `new` blocks and coupled `Price_Rule` fields.
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $values Submitted `setting_id => value` map.
	 * @return array<string,string>
	 */
	public function validate( array $values ): array {
		$errors     = [];
		$descriptor = $this->adapter->settings_descriptor();
		$cred_all   = $this->credential_field_names();

		foreach ( $values as $setting_id => $raw_value ) {
			$parsed = $this->parse( (string) $setting_id );
			if ( null === $parsed || ! in_array( $parsed['field'], $cred_all, true ) ) {
				continue;
			}

			$trimmed = trim( (string) $raw_value );
			if ( '' === $trimmed ) {
				continue;
			}

			$field_error = $descriptor->field_spec( $parsed['field'] )->validate( $trimmed );
			if ( null !== $field_error ) {
				$errors[ (string) $setting_id ] = $field_error;
			}
		}

		$errors = array_merge(
			$errors,
			$this->validate_new_block( $values ),
			$this->validate_unique_identifiers( $values ),
			$this->validate_price_rules( $values )
		);

		return $errors;
	}

	/**
	 * Tells whether `$section_id` is one of our connection blocks.
	 *
	 * @since 2.1.2
	 *
	 * @param string $section_id Connection section id.
	 * @return bool
	 */
	public function handles_connection( string $section_id ): bool {
		return null !== $this->account_key_of( $section_id );
	}

	/**
	 * Runs the live connection check via `Marketplace_Adapter::credential_health()`, fencing health writes on the
	 * tested credentials and redacting any secrets from the returned message.
	 *
	 * @since 2.1.2
	 *
	 * @param string              $section_id Connection section id.
	 * @param array<string,mixed> $values     Submitted/merged block values.
	 * @return \Woodev_Connection_Result
	 */
	public function test_connection( string $section_id, array $values ): \Woodev_Connection_Result {
		$key = $this->account_key_of( $section_id );

		if ( null === $key ) {
			return \Woodev_Connection_Result::failure( __( 'Неизвестный блок аккаунта.', 'woodev-plugin-framework' ) );
		}

		$descriptor    = $this->adapter->settings_descriptor();
		$fields        = $this->adapter->credential_fields();
		$id_field      = $fields->identifier();
		$secret_fields = $fields->secrets();
		$id            = self::NEW_KEY === $key ? 0 : (int) $key;
		$existing      = $id > 0 ? $this->store->get( $id ) : null;

		$external_id = trim( (string) ( $values[ self::setting_id( $key, $id_field ) ] ?? '' ) );
		if ( '' === $external_id && null !== $existing ) {
			$external_id = trim( (string) ( $existing->credentials()[ $id_field ] ?? '' ) );
		}

		$unreadable = null !== $existing && ! empty( $existing->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] );
		$secrets    = [];

		foreach ( $secret_fields as $secret_field ) {
			$submitted = trim( (string) ( $values[ self::setting_id( $key, $secret_field ) ] ?? '' ) );
			if ( '' === $submitted && null !== $existing && ! $unreadable ) {
				$submitted = trim( (string) ( $existing->credentials()[ $secret_field ] ?? '' ) );
			}
			$secrets[ $secret_field ] = $submitted;
		}

		if ( $unreadable && in_array( '', $secrets, true ) ) {
			return \Woodev_Connection_Result::failure( $descriptor->unreadable_secrets_message() );
		}

		if ( '' === $external_id || in_array( '', $secrets, true ) ) {
			return \Woodev_Connection_Result::failure( __( 'Заполните все учётные данные аккаунта.', 'woodev-plugin-framework' ) );
		}

		$id_error = $descriptor->field_spec( $id_field )->validate( $external_id );
		if ( null !== $id_error ) {
			return \Woodev_Connection_Result::failure( $id_error );
		}

		foreach ( $secret_fields as $secret_field ) {
			$sec_error = $descriptor->field_spec( $secret_field )->validate( $secrets[ $secret_field ] );
			if ( null !== $sec_error ) {
				return \Woodev_Connection_Result::failure( $sec_error );
			}
		}

		$tested_credentials = array_merge( [ $id_field => $external_id ], $secrets );
		$name               = trim( (string) ( $values[ self::setting_id( $key, 'name' ) ] ?? '' ) );
		$enabled            = self::to_bool( $values[ self::setting_id( $key, 'enabled' ) ] ?? true );

		$probe  = new Account( $id, $this->adapter->id(), $name, $enabled, $tested_credentials );
		$result = $this->adapter->credential_health()->check( $probe );

		if ( $id > 0 && null !== $existing ) {
			$currency = trim( (string) ( $result->extra()['currency'] ?? '' ) );
			if ( '' !== $currency ) {
				$this->store->update_fields( $id, [ 'currency' => $currency ] );
			}

			$this->store->set_health_for_credentials( $id, $tested_credentials, $result );
			$this->refresh( $id );

			if ( $result->is_ok() && null !== $this->on_connected ) {
				call_user_func( $this->on_connected, $id );
			}
		}

		$safe_message = Report_Redactor::redact_text(
			$result->message(),
			$secret_fields,
			array_values( $secrets )
		);

		return $result->is_ok()
			? \Woodev_Connection_Result::success( $safe_message )
			: \Woodev_Connection_Result::failure( '' !== $safe_message ? $safe_message : __( 'Проверка подключения не прошла.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Returns the stored connection status of `$section_id` without making remote HTTP calls.
	 *
	 * @since 2.1.2
	 *
	 * @param string $section_id Connection section id.
	 * @return \Woodev_Connection_Result|null
	 */
	public function connection_status( string $section_id ): ?\Woodev_Connection_Result {
		$key = $this->account_key_of( $section_id );

		if ( null === $key || self::NEW_KEY === $key ) {
			return $this->status->empty_block();
		}

		$account = $this->store->get( (int) $key );

		return null === $account ? $this->status->empty_block() : $this->status->for_account( $account );
	}

	/**
	 * Loads and memoizes saved accounts of this adapter, recording an `auth_failed` health when salts rotated.
	 *
	 * @return array<int,Account>
	 */
	private function accounts(): array {
		if ( null !== $this->accounts ) {
			return $this->accounts;
		}

		$this->accounts = [];

		foreach ( $this->store->all( $this->adapter->id(), false ) as $account ) {
			$this->record_unreadable_secrets( $account );
			$this->accounts[ $account->id() ] = $account;
		}

		return $this->accounts;
	}

	/**
	 * Records a fenced `auth_failed` health when stored secrets fail decryption (after WordPress salt rotation).
	 *
	 * @param Account $account Account.
	 * @return void
	 */
	private function record_unreadable_secrets( Account $account ): void {
		$credentials = $account->credentials();
		if ( empty( $credentials[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] ) ) {
			return;
		}

		$message = $this->adapter->settings_descriptor()->unreadable_secrets_message();
		$current = $this->store->health( $account->id() );

		if ( null !== $current && Health_Result::AUTH_FAILED === $current->status() && $message === $current->message() ) {
			return;
		}

		$ciphertext = (string) ( $credentials[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE_ENC ] ?? '' );
		if ( '' === $ciphertext ) {
			return;
		}

		$this->store->set_health_for_ciphertext(
			$account->id(),
			$ciphertext,
			new Health_Result( Health_Result::AUTH_FAILED, $message, $account->expires_at() )
		);
	}

	/**
	 * Registers the connection fields (`name`, adapter `identifier`, adapter `secrets`, `enabled`) for `$key`.
	 *
	 * @param Settings_Handler $handler Handler.
	 * @param string           $key     Account id or `new`.
	 * @return void
	 */
	private function register_connection_fields( Settings_Handler $handler, string $key ): void {
		$descriptor = $this->adapter->settings_descriptor();
		$fields     = $this->adapter->credential_fields();
		$id_field   = $fields->identifier();
		$id_spec    = $descriptor->field_spec( $id_field );

		$this->add(
			$handler,
			self::setting_id( $key, 'name' ),
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => __( 'Название', 'woodev-plugin-framework' ),
				'default' => '',
			],
			\Woodev_Control::TYPE_TEXT,
			[
				'tooltip'     => __( 'Произвольная подпись кабинета в админке, чтобы различать несколько аккаунтов.', 'woodev-plugin-framework' ),
				'placeholder' => __( 'Основной магазин', 'woodev-plugin-framework' ),
			]
		);

		$this->add(
			$handler,
			self::setting_id( $key, $id_field ),
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => $id_spec->label(),
				'default' => '',
			],
			\Woodev_Control::TYPE_TEXT,
			array_filter(
				[
					'tooltip'     => $id_spec->tooltip(),
					'placeholder' => $id_spec->placeholder(),
				],
				'strlen'
			)
		);

		foreach ( $fields->secrets() as $secret_field ) {
			$secret_spec = $descriptor->field_spec( $secret_field );
			$this->add(
				$handler,
				self::setting_id( $key, $secret_field ),
				\Woodev_Setting::TYPE_STRING,
				[
					'name'      => $secret_spec->label(),
					'default'   => '',
					'sensitive' => true,
				],
				\Woodev_Control::TYPE_PASSWORD,
				array_filter(
					[
						'tooltip'     => $secret_spec->tooltip(),
						'placeholder' => $secret_spec->placeholder(),
					],
					'strlen'
				)
			);
		}

		$this->add_toggle(
			$handler,
			self::setting_id( $key, 'enabled' ),
			__( 'Аккаунт включён', 'woodev-plugin-framework' ),
			self::NEW_KEY !== $key,
			__( 'Выключенный аккаунт не отправляет изменения и не принимает новые заказы.', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Registers capability-gated parameter fields for a saved account.
	 *
	 * @param Settings_Handler $handler    Handler.
	 * @param string           $key        Account id string.
	 * @param int              $account_id Account id.
	 * @return void
	 */
	private function register_parameter_fields( Settings_Handler $handler, string $key, int $account_id ): void {
		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();

		$this->add_select(
			$handler,
			self::setting_id( $key, 'warehouse_id' ),
			__( 'Склад по умолчанию', 'woodev-plugin-framework' ),
			$this->warehouse_options( $account_id ),
			sprintf(
				/* translators: %s: marketplace name. */
				__( 'Склад кабинета %s, на который уходят остатки с сайта. Список складов подгружается после проверки подключения.', 'woodev-plugin-framework' ),
				$marketplace
			)
		);

		if ( $this->adapter->supports( Capability::OVERSIZE_WAREHOUSE ) ) {
			$this->add_select(
				$handler,
				self::setting_id( $key, 'oversize_warehouse_id' ),
				__( 'Склад для крупногабаритных товаров', 'woodev-plugin-framework' ),
				$this->warehouse_options( $account_id, 'oversize_warehouse_id' ),
				sprintf(
					/* translators: %s: marketplace name. */
					__( 'Склад кабинета %s для крупногабаритных товаров без своего склада. Если не выбран, у такого товара остатки не отправляются: подставлять обычный склад нельзя.', 'woodev-plugin-framework' ),
					$marketplace
				)
			);
		}

		if ( $this->adapter->supports( Capability::INBOUND_STOCK ) ) {
			$this->add_select(
				$handler,
				self::setting_id( $key, 'stock_mode' ),
				__( 'Остатки', 'woodev-plugin-framework' ),
				[
					self::option(
						Wpdb_Account_Store::STOCK_SITE,
						/* translators: %s: marketplace name. */
						sprintf( __( 'Главный — сайт: остатки уходят с сайта на %s', 'woodev-plugin-framework' ), $marketplace )
					),
					self::option(
						Wpdb_Account_Store::STOCK_MARKETPLACE,
						/* translators: %s: marketplace name. */
						sprintf( __( 'Главный — %1$s: остатки приходят с %2$s на сайт', 'woodev-plugin-framework' ), $marketplace, $marketplace )
					),
					self::option( Wpdb_Account_Store::STOCK_OFF, __( 'Не синхронизировать: остатки не отправляются и не приходят', 'woodev-plugin-framework' ) ),
				],
				__( 'Где ведётся учёт остатков.', 'woodev-plugin-framework' )
			);
		}

		$price_options = $this->price_direction_options( $marketplace );
		if ( [] !== $price_options ) {
			$this->add_select(
				$handler,
				self::setting_id( $key, 'price_direction' ),
				__( 'Цены', 'woodev-plugin-framework' ),
				$price_options,
				__( 'В какую сторону передаются цены.', 'woodev-plugin-framework' )
			);
			$this->register_price_rule_fields( $handler, $key, $marketplace );
		}

		if ( null !== $this->adapter->posting_handler() ) {
			$this->add_toggle(
				$handler,
				self::setting_id( $key, 'orders_enabled' ),
				/* translators: %s: marketplace name. */
				sprintf( __( 'Заказы %s → WooCommerce', 'woodev-plugin-framework' ), $marketplace ),
				true,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Заказы с %s появляются в WooCommerce как обычные заказы.', 'woodev-plugin-framework' ), $marketplace )
			);
		}

		if ( $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			$this->add_toggle(
				$handler,
				self::setting_id( $key, 'import_enabled' ),
				__( 'Импортировать новые карточки автоматически', 'woodev-plugin-framework' ),
				false,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Когда в кабинете %s появляется новая карточка, плагин создаёт для неё товар на сайте. По умолчанию выключено.', 'woodev-plugin-framework' ), $marketplace )
			);
		}
	}

	/**
	 * Registers the six `Price_Rule` fields for an account.
	 *
	 * @param Settings_Handler $handler     Handler.
	 * @param string           $key         Account id string.
	 * @param string           $marketplace Marketplace name.
	 * @return void
	 */
	private function register_price_rule_fields( Settings_Handler $handler, string $key, string $marketplace ): void {
		$this->add_select(
			$handler,
			self::setting_id( $key, 'price_base' ),
			__( 'От какой цены считать', 'woodev-plugin-framework' ),
			[
				self::option( Price_Rule::BASE_ACTIVE, __( 'От цены на сайте (со скидкой, если она есть)', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::BASE_REGULAR, __( 'От обычной цены (скидки сайта не учитывать)', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Какую цену сайта брать за основу. Со скидкой — %s получит цену со скидкой и зачёркнутую обычную цену.', 'woodev-plugin-framework' ), $marketplace )
		);

		$this->add_select(
			$handler,
			self::setting_id( $key, 'price_markup_kind' ),
			__( 'Наценка', 'woodev-plugin-framework' ),
			[
				self::option( Price_Rule::MARKUP_NONE, __( 'Без наценки', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::MARKUP_FIXED, __( 'Фиксированная сумма', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::MARKUP_PERCENT, __( 'Процент от цены', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Насколько цена на %s отличается от цены на сайте (например, на величину комиссии).', 'woodev-plugin-framework' ), $marketplace )
		);

		$this->add(
			$handler,
			self::setting_id( $key, 'price_markup' ),
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => __( 'Размер наценки', 'woodev-plugin-framework' ),
				'default' => '0',
			],
			\Woodev_Control::TYPE_TEXT,
			[
				'tooltip'     => __( 'Сумма в валюте кабинета или процент — как выбрано выше. Например, 150 или 15.', 'woodev-plugin-framework' ),
				'placeholder' => '0',
			]
		);

		if ( null === $this->adapter->price_pusher() ) {
			return;
		}

		$this->add_select(
			$handler,
			self::setting_id( $key, 'price_rounding' ),
			__( 'Округление', 'woodev-plugin-framework' ),
			[
				self::option( Price_Rule::ROUND_NONE, __( 'Не округлять', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::ROUND_TO_1, __( 'До целой единицы валюты', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::ROUND_TO_10, __( 'До десятков', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::ROUND_ENDS_99, __( 'Чтобы цена заканчивалась на 99', 'woodev-plugin-framework' ) ),
			],
			__( 'Как округлять цену после наценки.', 'woodev-plugin-framework' )
		);

		$this->add_select(
			$handler,
			self::setting_id( $key, 'price_old_price_rule' ),
			__( 'Старая цена', 'woodev-plugin-framework' ),
			[
				self::option( Price_Rule::OLD_PRICE_ON_SALE, __( 'Показывать зачёркнутую цену, пока на сайте скидка', 'woodev-plugin-framework' ) ),
				self::option( Price_Rule::OLD_PRICE_OFF, __( 'Не отправлять старую цену', 'woodev-plugin-framework' ) ),
			],
			/* translators: %s: marketplace name. */
			sprintf( __( 'Нужна ли на %s зачёркнутая цена, когда на сайте действует скидка.', 'woodev-plugin-framework' ), $marketplace )
		);

		$this->add(
			$handler,
			self::setting_id( $key, 'price_min_percent' ),
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => __( 'Минимальная цена, %', 'woodev-plugin-framework' ),
				'default' => '',
			],
			\Woodev_Control::TYPE_TEXT,
			[
				/* translators: %s: marketplace name. */
				'tooltip'     => sprintf( __( 'Процент от цены, ниже которого %s не опустит цену в акциях. Пусто — минимальная цена не отправляется.', 'woodev-plugin-framework' ), $marketplace ),
				'placeholder' => '80',
			]
		);
	}

	/**
	 * Builds the connection `Settings_Section` card for `$key`.
	 *
	 * @param string $key   Account id or `new`.
	 * @param string $title Section title.
	 * @return Settings_Section
	 */
	private function connection_section( string $key, string $title ): Settings_Section {
		$descriptor = $this->adapter->settings_descriptor();
		$field_ids  = [ self::setting_id( $key, 'name' ) ];

		foreach ( $this->credential_field_names() as $cred_field ) {
			$field_ids[] = self::setting_id( $key, $cred_field );
		}

		$field_ids[] = self::setting_id( $key, 'enabled' );

		$help        = trim( $descriptor->credentials_help() );
		$description = '' !== $help ? '<p>' . esc_html( $help ) . '</p>' : '';

		return Settings_Section::create_connection(
			self::connection_section_id( $key ),
			$title,
			$field_ids,
			__( 'Проверить подключение', 'woodev-plugin-framework' ),
			$description
		);
	}

	/**
	 * Builds the parameters `Settings_Section` card for a saved account.
	 *
	 * @param Account $account Saved account.
	 * @return Settings_Section|null
	 */
	private function parameters_section( Account $account ): ?Settings_Section {
		$key       = (string) $account->id();
		$groups    = [];
		$all_ids   = [];
		$stock_ids = [ self::setting_id( $key, 'warehouse_id' ) ];

		if ( $this->adapter->supports( Capability::OVERSIZE_WAREHOUSE ) ) {
			$stock_ids[] = self::setting_id( $key, 'oversize_warehouse_id' );
		}

		if ( $this->adapter->supports( Capability::INBOUND_STOCK ) ) {
			$stock_ids[] = self::setting_id( $key, 'stock_mode' );
		}

		$all_ids  = array_merge( $all_ids, $stock_ids );
		$groups[] = Settings_Group::create(
			'stock_' . $key,
			__( 'Остатки и склад', 'woodev-plugin-framework' )
		)->with_fields( $stock_ids );

		$has_pusher = null !== $this->adapter->price_pusher();
		$has_reader = null !== $this->adapter->price_reader();

		if ( $has_pusher || $has_reader ) {
			$price_ids = [
				self::setting_id( $key, 'price_direction' ),
				self::setting_id( $key, 'price_base' ),
				self::setting_id( $key, 'price_markup_kind' ),
				self::setting_id( $key, 'price_markup' ),
			];

			if ( $has_pusher ) {
				$price_ids[] = self::setting_id( $key, 'price_rounding' );
				$price_ids[] = self::setting_id( $key, 'price_old_price_rule' );
				$price_ids[] = self::setting_id( $key, 'price_min_percent' );
			}

			$all_ids  = array_merge( $all_ids, $price_ids );
			$groups[] = Settings_Group::create( 'prices_' . $key, __( 'Цены и наценка', 'woodev-plugin-framework' ) )->with_fields( $price_ids );
		}

		$exchange_ids = [];
		if ( null !== $this->adapter->posting_handler() ) {
			$exchange_ids[] = self::setting_id( $key, 'orders_enabled' );
		}
		if ( $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			$exchange_ids[] = self::setting_id( $key, 'import_enabled' );
		}
		if ( [] !== $exchange_ids ) {
			$all_ids  = array_merge( $all_ids, $exchange_ids );
			$groups[] = Settings_Group::create( 'exchange_' . $key, __( 'Заказы и карточки', 'woodev-plugin-framework' ) )->with_fields( $exchange_ids );
		}

		$description = $has_pusher ? $this->price_example_html( $account ) : '';

		return Settings_Section::create(
			self::params_section_id( $account->id() ),
			/* translators: %s: account display label. */
			sprintf( __( 'Параметры обмена — «%s»', 'woodev-plugin-framework' ), $this->account_title( $account ) ),
			$all_ids,
			$description
		)->with_groups( $groups );
	}

	/**
	 * Formats a live price calculation example (`1 000 -> ...`) from the account's saved `Price_Rule`.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	private function price_example_html( Account $account ): string {
		$currency = trim( (string) $account->setting( 'currency', '' ) );
		$currency = '' !== $currency ? $currency : 'RUB';

		try {
			$rule = Price_Rule::from_array( (array) $account->setting( 'price_rule', [] ) );
			$pair = Price_Calculator::calculate(
				Money::from_string( '1000', $currency ),
				Money::from_string( '1200', $currency ),
				$rule
			);
		} catch ( \Throwable $e ) {
			return '';
		}

		$parts = [
			sprintf(
				/* translators: 1: Calculated marketplace price, 2: Currency code. */
				__( 'цена %1$s %2$s', 'woodev-plugin-framework' ),
				$pair->price()->to_string(),
				$currency
			),
		];

		if ( null !== $pair->old_price() ) {
			$parts[] = sprintf(
				/* translators: 1: Calculated old price, 2: Currency code. */
				__( 'зачёркнутая %1$s %2$s', 'woodev-plugin-framework' ),
				$pair->old_price()->to_string(),
				$currency
			);
		}

		if ( null !== $pair->min_price() ) {
			$parts[] = sprintf(
				/* translators: 1: Calculated minimum price, 2: Currency code. */
				__( 'минимальная %1$s %2$s', 'woodev-plugin-framework' ),
				$pair->min_price()->to_string(),
				$currency
			);
		}

		$summary = sprintf(
			/* translators: 1: Currency code, 2: Comma-separated calculated prices. */
			__( 'Пример по текущему правилу (товар на сайте за 1000 %1$s со скидкой от 1200 %1$s): %2$s.', 'woodev-plugin-framework' ),
			$currency,
			implode( ', ', $parts )
		);

		return '<p>' . esc_html( $summary ) . '</p>';
	}

	/**
	 * Reads one field from a loaded `Account`.
	 *
	 * @param Account $account Account.
	 * @param string  $field   Field name.
	 * @return mixed
	 */
	private function value_of( Account $account, string $field ) {
		$cred_fields = $this->adapter->credential_fields();

		if ( $field === $cred_fields->identifier() || in_array( $field, $cred_fields->secrets(), true ) ) {
			return (string) ( $account->credentials()[ $field ] ?? '' );
		}

		switch ( $field ) {
			case 'name':
				return $account->name();
			case 'enabled':
				return $account->is_enabled();
			case 'warehouse_id':
				$id = (int) $account->setting( 'warehouse_id', 0 );
				return $id > 0 ? (string) $id : '0';
			case 'oversize_warehouse_id':
				return (string) max( 0, (int) $account->setting( 'oversize_warehouse_id', 0 ) );
			case 'stock_mode':
				return (string) $account->setting( 'stock_mode', Wpdb_Account_Store::STOCK_SITE );
			case 'price_direction':
				$default = null !== $this->adapter->price_pusher()
					? Wpdb_Account_Store::PRICE_TO_MARKETPLACE
					: Wpdb_Account_Store::PRICE_OFF;
				return (string) $account->setting( 'price_direction', $default );
			case 'orders_enabled':
				return (bool) $account->setting( 'orders_enabled', true );
			case 'import_enabled':
				return (bool) $account->setting( 'import_enabled', false );
		}

		if ( isset( self::PRICE_RULE_FIELDS[ $field ] ) ) {
			$rule_key = self::PRICE_RULE_FIELDS[ $field ];
			$stored   = array_merge( self::default_price_rule_array(), (array) $account->setting( 'price_rule', [] ) );

			return (string) ( $stored[ $rule_key ] ?? '' );
		}

		return '';
	}

	/**
	 * Default `Price_Rule::from_array()` map when an account has no stored `price_rule` yet.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_price_rule_array(): array {
		return [
			'base'           => Price_Rule::BASE_ACTIVE,
			'markup_kind'    => Price_Rule::MARKUP_NONE,
			'markup'         => '0',
			'rounding'       => Price_Rule::ROUND_NONE,
			'old_price_rule' => Price_Rule::OLD_PRICE_ON_SALE,
			'min_percent'    => null,
		];
	}

	/**
	 * Accumulates fields of the `new` block and inserts the account once identifier and all secrets are provided.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Submitted value.
	 * @return void
	 */
	private function write_new_block( string $field, $value ): void {
		$this->pending[ $field ] = $value;

		if ( null !== $this->created_id ) {
			$this->persist_field( $this->created_id, $field, $value );
			$this->refresh( $this->created_id );
			return;
		}

		$credentials = [];

		foreach ( $this->credential_field_names() as $cred_field ) {
			$val = trim( (string) ( $this->pending[ $cred_field ] ?? '' ) );
			if ( '' === $val ) {
				return;
			}
			$credentials[ $cred_field ] = $val;
		}

		$name    = trim( (string) ( $this->pending['name'] ?? '' ) );
		$enabled = array_key_exists( 'enabled', $this->pending ) ? self::to_bool( $this->pending['enabled'] ) : true;

		$this->created_id = $this->store->save(
			new Account( 0, $this->adapter->id(), $name, $enabled, $credentials )
		);
		$this->refresh( $this->created_id );
	}

	/**
	 * Persists a single changed field of an existing account.
	 *
	 * @param int    $id    Account id.
	 * @param string $field Field name.
	 * @param mixed  $value Submitted value.
	 * @return void
	 */
	private function write_account( int $id, string $field, $value ): void {
		$account = $this->accounts()[ $id ] ?? null;

		if ( null === $account || $this->is_unchanged( $account, $field, $value ) ) {
			return;
		}

		$this->persist_field( $id, $field, $value );
		$this->refresh( $id );
	}

	/**
	 * Tells whether a submitted value equals what the account already holds (and treats empty masked secret inputs as unchanged).
	 *
	 * @param Account $account Account.
	 * @param string  $field   Field name.
	 * @param mixed   $value   Submitted value.
	 * @return bool
	 */
	private function is_unchanged( Account $account, string $field, $value ): bool {
		if ( in_array( $field, $this->adapter->credential_fields()->secrets(), true ) ) {
			$trimmed = trim( (string) $value );
			return '' === $trimmed || $trimmed === (string) $this->value_of( $account, $field );
		}

		if ( $this->is_boolean_field( $field ) ) {
			return self::to_bool( $value ) === (bool) $this->value_of( $account, $field );
		}

		return trim( (string) $value ) === (string) $this->value_of( $account, $field );
	}

	/**
	 * Dispatches one field write to the appropriate targeted `Wpdb_Account_Store` method.
	 *
	 * @param int    $id    Account id.
	 * @param string $field Field name.
	 * @param mixed  $value Submitted value.
	 * @return void
	 */
	private function persist_field( int $id, string $field, $value ): void {
		$cred_fields = $this->adapter->credential_fields();
		$text        = trim( (string) $value );

		if ( $field === $cred_fields->identifier() ) {
			if ( '' !== $text ) {
				$this->store->replace_identifier( $id, $text );
			}
			return;
		}

		if ( in_array( $field, $cred_fields->secrets(), true ) ) {
			if ( '' !== $text ) {
				$this->store->replace_secrets( $id, [ $field => $text ] );
			}
			return;
		}

		if ( isset( self::PRICE_RULE_FIELDS[ $field ] ) ) {
			$rule_key = self::PRICE_RULE_FIELDS[ $field ];
			$defaults = self::default_price_rule_array();
			$updated  = $this->store->update_settings(
				$id,
				static function ( array $current ) use ( $rule_key, $text, $defaults ): array {
					$stored            = isset( $current['price_rule'] ) && is_array( $current['price_rule'] ) ? $current['price_rule'] : [];
					$rule              = array_merge( $defaults, $stored );
					$rule[ $rule_key ] = '' === $text && 'min_percent' === $rule_key ? null : $text;
					Price_Rule::from_array( $rule );
					$current['price_rule'] = $rule;

					return $current;
				}
			);

			if ( $updated ) {
				do_action( $this->price_rules_changed_hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names (`woodev_{plugin_id}_account_price_rules_changed`).
			}
			return;
		}

		switch ( $field ) {
			case 'name':
				$this->store->update_fields( $id, [ 'name' => $text ] );
				return;
			case 'enabled':
				$this->store->set_enabled( $id, self::to_bool( $value ) );
				return;
			case 'warehouse_id':
				$wh = (int) $text;
				$this->store->update_fields( $id, [ 'warehouse_id' => $wh > 0 ? $wh : null ] );
				return;
			case 'oversize_warehouse_id':
				$this->save_oversize_warehouse( $id, (int) $text );
				return;
			case 'stock_mode':
				$this->store->update_fields( $id, [ 'stock_mode' => $text ] );
				return;
			case 'price_direction':
				$this->store->update_fields( $id, [ 'price_direction' => $text ] );
				do_action( $this->price_rules_changed_hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names.
				return;
			case 'orders_enabled':
				$this->store->update_fields( $id, [ 'orders_enabled' => self::to_bool( $value ) ] );
				return;
			case 'import_enabled':
				$this->store->update_fields( $id, [ 'import_enabled' => self::to_bool( $value ) ] );
				return;
		}
	}

	/**
	 * Saves the account's oversize warehouse into the `settings` JSON (it has no column); none removes the entry.
	 *
	 * @param int $id        Account id.
	 * @param int $warehouse Marketplace warehouse id, 0 for none.
	 * @return void
	 */
	private function save_oversize_warehouse( int $id, int $warehouse ): void {
		$this->store->update_settings(
			$id,
			static function ( array $current ) use ( $warehouse ): array {
				if ( $warehouse > 0 ) {
					$current['oversize_warehouse_id'] = $warehouse;
				} else {
					unset( $current['oversize_warehouse_id'] );
				}

				return $current;
			}
		);
	}

	/**
	 * Re-reads an account into the in-memory cache after a write.
	 *
	 * @param int $id Account id.
	 * @return void
	 */
	private function refresh( int $id ): void {
		$fresh = $this->store->get( $id );
		if ( null !== $fresh ) {
			$this->accounts();
			$this->accounts[ $id ] = $fresh;
		}
	}

	/**
	 * Rejects a half-filled `new` account block (when at least one credential or name is filled, all credentials are required).
	 *
	 * @param array<string,mixed> $values Submitted map.
	 * @return array<string,string>
	 */
	private function validate_new_block( array $values ): array {
		$cred_fields = $this->credential_field_names();
		$descriptor  = $this->adapter->settings_descriptor();
		$filled      = '' !== trim( (string) ( $values[ self::setting_id( self::NEW_KEY, 'name' ) ] ?? '' ) );
		$missing     = [];

		foreach ( $cred_fields as $field ) {
			$id  = self::setting_id( self::NEW_KEY, $field );
			$val = trim( (string) ( $values[ $id ] ?? '' ) );
			if ( '' !== $val ) {
				$filled = true;
			} else {
				$missing[ $id ] = $descriptor->field_spec( $field )->label();
			}
		}

		if ( ! $filled || [] === $missing ) {
			return [];
		}

		$errors = [];
		foreach ( $missing as $id => $label ) {
			/* translators: %s: credential field label. */
			$errors[ $id ] = sprintf( __( 'Заполните поле «%s» для нового аккаунта.', 'woodev-plugin-framework' ), $label );
		}

		return $errors;
	}

	/**
	 * Rejects duplicate account identifiers across saved accounts and the `new` block.
	 *
	 * @param array<string,mixed> $values Submitted map.
	 * @return array<string,string>
	 */
	private function validate_unique_identifiers( array $values ): array {
		$id_field = $this->adapter->credential_fields()->identifier();
		$label    = $this->adapter->settings_descriptor()->field_spec( $id_field )->label();
		$seen     = [];
		$errors   = [];

		foreach ( $this->accounts() as $account ) {
			$key       = (string) $account->id();
			$field_id  = self::setting_id( $key, $id_field );
			$effective = array_key_exists( $field_id, $values )
				? trim( (string) $values[ $field_id ] )
				: trim( (string) ( $account->credentials()[ $id_field ] ?? '' ) );

			if ( '' === $effective ) {
				$errors[ $field_id ] = sprintf(
					/* translators: %s: identifier field label. */
					__( 'Поле «%s» не может быть пустым.', 'woodev-plugin-framework' ),
					$label
				);
				continue;
			}

			if ( isset( $seen[ $effective ] ) ) {
				$errors[ $field_id ] = sprintf(
					/* translators: %s: identifier field label. */
					__( 'Аккаунт с таким «%s» уже добавлен.', 'woodev-plugin-framework' ),
					$label
				);
			} else {
				$seen[ $effective ] = $key;
			}
		}

		$new_id  = self::setting_id( self::NEW_KEY, $id_field );
		$new_val = trim( (string) ( $values[ $new_id ] ?? '' ) );
		if ( '' !== $new_val && isset( $seen[ $new_val ] ) ) {
			$errors[ $new_id ] = sprintf(
				/* translators: %s: identifier field label. */
				__( 'Аккаунт с таким «%s» уже добавлен.', 'woodev-plugin-framework' ),
				$label
			);
		}

		return $errors;
	}

	/**
	 * Validates coupled `Price_Rule` fields per account via `Price_Rule::from_array()`.
	 *
	 * @param array<string,mixed> $values Submitted map.
	 * @return array<string,string>
	 */
	private function validate_price_rules( array $values ): array {
		$errors = [];

		foreach ( $this->accounts() as $account ) {
			$key     = (string) $account->id();
			$touched = false;
			$merged  = array_merge( self::default_price_rule_array(), (array) $account->setting( 'price_rule', [] ) );

			foreach ( self::PRICE_RULE_FIELDS as $setting_field => $rule_key ) {
				$id = self::setting_id( $key, $setting_field );
				if ( array_key_exists( $id, $values ) ) {
					$touched             = true;
					$val                 = trim( (string) $values[ $id ] );
					$merged[ $rule_key ] = '' === $val && 'min_percent' === $rule_key ? null : $val;
				}
			}

			if ( ! $touched ) {
				continue;
			}

			try {
				Price_Rule::from_array( $merged );
			} catch ( \InvalidArgumentException $e ) {
				$min_id    = self::setting_id( $key, 'price_min_percent' );
				$markup_id = self::setting_id( $key, 'price_markup' );
				$target    = false !== stripos( $e->getMessage(), 'min' ) ? $min_id : $markup_id;

				$errors[ $target ] = $target === $min_id
					? __( 'Укажите процент больше 0 и не больше 100 (например, 80) или оставьте поле пустым.', 'woodev-plugin-framework' )
					: __( 'Укажите корректное число для наценки (например, 150 или 15.5; скидка в процентах должна быть строго больше -100).', 'woodev-plugin-framework' );
			}
		}

		return $errors;
	}

	/**
	 * Builds warehouse select pairs for an account, preserving a stored warehouse id even before the cache is populated.
	 *
	 * @param int    $account_id Account id.
	 * @param string $setting    Account setting that holds the stored warehouse (`warehouse_id` or `oversize_warehouse_id`).
	 * @return array<int,array{value:string,label:string}>
	 */
	private function warehouse_options( int $account_id, string $setting = 'warehouse_id' ): array {
		$pairs   = [ self::option( '0', __( '— Выберите склад —', 'woodev-plugin-framework' ) ) ];
		$cached  = $this->warehouses->for_account( $account_id, 'oversize_warehouse_id' === $setting );
		$account = $this->accounts()[ $account_id ] ?? null;
		$stored  = null !== $account ? (int) $account->setting( $setting, 0 ) : 0;

		if ( $stored > 0 && ! isset( $cached[ (string) $stored ] ) ) {
			$pairs[] = self::option( (string) $stored, '#' . $stored );
		}

		foreach ( $cached as $id => $name ) {
			$pairs[] = self::option( (string) $id, $name );
		}

		return $pairs;
	}

	/**
	 * Builds the capability-gated `price_direction` option list.
	 *
	 * @param string $marketplace Marketplace name.
	 * @return array<int,array{value:string,label:string}>
	 */
	private function price_direction_options( string $marketplace ): array {
		$has_pusher = null !== $this->adapter->price_pusher();
		$has_reader = null !== $this->adapter->price_reader();

		if ( ! $has_pusher && ! $has_reader ) {
			return [];
		}

		$options = [];
		if ( $has_pusher ) {
			$options[] = self::option(
				Wpdb_Account_Store::PRICE_TO_MARKETPLACE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'С сайта на %1$s: цены на %2$s считаются от цен сайта', 'woodev-plugin-framework' ), $marketplace, $marketplace )
			);
		}
		if ( $has_reader ) {
			$options[] = self::option(
				Wpdb_Account_Store::PRICE_FROM_MARKETPLACE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'С %1$s на сайт: цены сайта берутся с %2$s', 'woodev-plugin-framework' ), $marketplace, $marketplace )
			);
		}
		$options[] = self::option(
			Wpdb_Account_Store::PRICE_OFF,
			__( 'Не синхронизировать цены', 'woodev-plugin-framework' )
		);

		return $options;
	}

	/**
	 * Parses `account_{id|new}_{field}` when `$field` is a known field of this adapter.
	 *
	 * @param string $setting_id Setting id.
	 * @return array{key:string,field:string}|null
	 */
	private function parse( string $setting_id ): ?array {
		if ( 1 !== preg_match( '/^account_(\d+|new)_([a-z0-9_]+)$/', $setting_id, $m ) ) {
			return null;
		}

		if ( '0' === $m[1] || ! in_array( $m[2], $this->known_fields(), true ) ) {
			return null;
		}

		return [
			'key'   => $m[1],
			'field' => $m[2],
		];
	}

	/**
	 * Lists the adapter's credential field names (`identifier` followed by `secrets`).
	 *
	 * @return string[]
	 */
	private function credential_field_names(): array {
		$fields = $this->adapter->credential_fields();

		return array_merge( [ $fields->identifier() ], $fields->secrets() );
	}

	/**
	 * Lists every valid field suffix for this adapter.
	 *
	 * @return string[]
	 */
	private function known_fields(): array {
		return array_values(
			array_unique(
				array_merge(
					[ 'name', 'enabled', 'warehouse_id', 'oversize_warehouse_id', 'stock_mode', 'price_direction', 'orders_enabled', 'import_enabled' ],
					$this->credential_field_names(),
					array_keys( self::PRICE_RULE_FIELDS )
				)
			)
		);
	}

	/**
	 * Extracts the account key (`new` or positive integer string) from `account_connection_{key}`.
	 *
	 * @param string $section_id Section id.
	 * @return string|null
	 */
	private function account_key_of( string $section_id ): ?string {
		if ( 0 !== strpos( $section_id, self::CONNECTION_PREFIX ) ) {
			return null;
		}

		$key = substr( $section_id, strlen( self::CONNECTION_PREFIX ) );
		if ( self::NEW_KEY === $key ) {
			return self::NEW_KEY;
		}

		return ctype_digit( $key ) && '0' !== $key ? $key : null;
	}

	/**
	 * Display title of a saved account.
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

		return '' !== $external_id ? $external_id : ( '#' . $account->id() );
	}

	/**
	 * Tells whether `$field` is a boolean toggle field.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_boolean_field( string $field ): bool {
		return in_array( $field, [ 'enabled', 'orders_enabled', 'import_enabled' ], true );
	}

	/**
	 * Coerces a mixed form value into a boolean.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Builds one `{value, label}` pair for `Woodev_Setting::set_options()`.
	 *
	 * @param string $value Option value.
	 * @param string $label Option label.
	 * @return array{value:string,label:string}
	 */
	private static function option( string $value, string $label ): array {
		return [
			'value' => $value,
			'label' => $label,
		];
	}

	/**
	 * Registers a setting + control on `$handler`.
	 *
	 * @param Settings_Handler    $handler      Handler.
	 * @param string              $setting_id   Setting id.
	 * @param string              $setting_type `Woodev_Setting::TYPE_*`.
	 * @param array<string,mixed> $setting_args Setting args.
	 * @param string              $control_type `Woodev_Control::TYPE_*`.
	 * @param array<string,mixed> $control_args Control args.
	 * @return void
	 */
	private function add(
		Settings_Handler $handler,
		string $setting_id,
		string $setting_type,
		array $setting_args,
		string $control_type,
		array $control_args = []
	): void {
		$handler->register_setting(
			$setting_id,
			$setting_type,
			$setting_args
		);
		$handler->register_control(
			$setting_id,
			$control_type,
			array_merge( [ 'label' => $setting_args['name'] ?? '' ], $control_args )
		);
	}

	/**
	 * Registers a select control with explicit `{value, label}` pairs.
	 *
	 * @param Settings_Handler                            $handler    Handler.
	 * @param string                                      $setting_id Setting id.
	 * @param string                                      $label      Label.
	 * @param array<int,array{value:string,label:string}> $options    Option pairs.
	 * @param string                                      $tooltip    Tooltip.
	 * @return void
	 */
	private function add_select( Settings_Handler $handler, string $setting_id, string $label, array $options, string $tooltip = '' ): void {
		$default = $options[0]['value'] ?? '';
		$this->add(
			$handler,
			$setting_id,
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => $label,
				'default' => $default,
				'options' => $options,
			],
			\Woodev_Control::TYPE_SELECT,
			array_filter(
				[
					'options' => $options,
					'tooltip' => $tooltip,
				],
				static function ( $v ): bool {
					return '' !== $v && [] !== $v;
				}
			)
		);
	}

	/**
	 * Registers a boolean toggle control.
	 *
	 * @param Settings_Handler $handler    Handler.
	 * @param string           $setting_id Setting id.
	 * @param string           $label      Label.
	 * @param bool             $default    Default boolean state.
	 * @param string           $tooltip    Tooltip.
	 * @return void
	 */
	private function add_toggle( Settings_Handler $handler, string $setting_id, string $label, bool $default, string $tooltip = '' ): void {
		$this->add(
			$handler,
			$setting_id,
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => $label,
				'default' => $default,
			],
			\Woodev_Control::TYPE_TOGGLE,
			array_filter( [ 'tooltip' => $tooltip ], 'strlen' )
		);
	}
}

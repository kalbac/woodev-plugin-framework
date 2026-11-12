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

	/**
	 * Fields that choose, per group, between the «Общие» defaults and the account's own values.
	 */
	public const OVERRIDE_PRICES   = 'override_prices';
	public const OVERRIDE_EXCHANGE = 'override_exchange';

	/**
	 * Values of an override field.
	 */
	public const SOURCE_DEFAULTS = 'defaults';
	public const SOURCE_OWN      = 'own';

	private const CONNECTION_PREFIX = 'account_connection_';
	private const PARAMS_PREFIX     = 'account_params_';

	/**
	 * Override field => the {@see Effective_Settings} group it decides.
	 */
	private const OVERRIDE_GROUPS = [
		self::OVERRIDE_PRICES   => Effective_Settings::GROUP_PRICES,
		self::OVERRIDE_EXCHANGE => Effective_Settings::GROUP_EXCHANGE,
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
	 * Action fired with the account id after an `ok` connection test (`Job_Names::hook( 'account_connected' )`).
	 *
	 * @var string
	 */
	private $account_connected_hook;

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
		$this->account_connected_hook   = ( new Job_Names( $plugin_id ) )->hook( 'account_connected' );
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

	public function sections(): array {
		return array_merge( $this->connection_sections(), $this->parameter_sections() );
	}

	/**
	 * Builds the «Подключение» sections: one per saved account, then the block that adds a new one.
	 *
	 * Each carries the card the screen draws for it ({@see Settings_Section::with_card()}).
	 *
	 * @since 2.1.5
	 *
	 * @return Settings_Section[]
	 */
	public function connection_sections(): array {
		$accounts = $this->accounts();
		$sections = [];

		foreach ( $accounts as $account ) {
			$sections[] = $this->connection_section( (string) $account->id(), $this->account_title( $account ) )->with_card( $this->card_of( $account ) );
		}

		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
		$new_title   = [] === $accounts
			/* translators: %s: marketplace name. */
			? sprintf( __( 'Подключение к %s', 'woodev-plugin-framework' ), $marketplace )
			: __( 'Добавить ещё один аккаунт', 'woodev-plugin-framework' );

		$sections[] = $this->connection_section( self::NEW_KEY, $new_title )->with_card( [ 'new' => true ] );

		return $sections;
	}

	public function parameter_sections(): array {
		$sections = [];

		foreach ( $this->accounts() as $account ) {
			$params = $this->parameters_section( $account );

			if ( null !== $params ) {
				$sections[] = $params;
			}
		}

		if ( [] === $sections ) {
			$sections[] = Settings_Section::create(
				'account_params_none',
				__( 'Параметры', 'woodev-plugin-framework' ),
				[],
				'<p>' . esc_html__( 'Подключите аккаунт на вкладке «Подключение».', 'woodev-plugin-framework' ) . '</p>'
			);
		}

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

			if ( $result->is_ok() ) {
				do_action( $this->account_connected_hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names (`woodev_{plugin_id}_account_connected`).
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

		Field_Kit::add(
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

		Field_Kit::add(
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
			Field_Kit::add(
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

		Field_Kit::add_toggle(
			$handler,
			self::setting_id( $key, 'enabled' ),
			__( 'Аккаунт включён', 'woodev-plugin-framework' ),
			self::NEW_KEY !== $key,
			__( 'Выключенный аккаунт не отправляет изменения и не принимает новые заказы.', 'woodev-plugin-framework' )
		);
	}

	private function register_parameter_fields( Settings_Handler $handler, string $key, int $account_id ): void {
		$marketplace = $this->adapter->settings_descriptor()->marketplace_name();
		$id_for      = static function ( string $field ) use ( $key ): string {
			return self::setting_id( $key, $field );
		};

		Field_Kit::add_select(
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
			Field_Kit::add_select(
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

		Field_Kit::add_select(
			$handler,
			self::setting_id( $key, 'stock_mode' ),
			__( 'Остатки', 'woodev-plugin-framework' ),
			$this->stock_mode_options( $marketplace ),
			__( 'Где ведётся учёт остатков.', 'woodev-plugin-framework' )
		);

		$price_options = $this->price_direction_options( $marketplace );
		if ( [] !== $price_options ) {
			Field_Kit::add_select(
				$handler,
				self::setting_id( $key, 'price_direction' ),
				__( 'Цены', 'woodev-plugin-framework' ),
				$price_options,
				__( 'В какую сторону передаются цены.', 'woodev-plugin-framework' )
			);
			$this->register_override_switch( $handler, $key, self::OVERRIDE_PRICES, __( 'Правило цены', 'woodev-plugin-framework' ) );
			Shared_Fields::register_price_rule( $handler, $id_for, $this->adapter, $this->shown_when_own( $key, self::OVERRIDE_PRICES ) );
		}

		if ( [] !== Shared_Fields::exchange_field_names( $this->adapter ) ) {
			$this->register_override_switch( $handler, $key, self::OVERRIDE_EXCHANGE, __( 'Заказы и карточки', 'woodev-plugin-framework' ) );
			Shared_Fields::register_exchange( $handler, $id_for, $this->adapter, $this->shown_when_own( $key, self::OVERRIDE_EXCHANGE ) );
		}
	}

	/**
	 * Registers the «как в Общих / свои» switch of one group for an account.
	 *
	 * @param Settings_Handler $handler Handler.
	 * @param string           $key     Account id string.
	 * @param string           $field   `override_prices` or `override_exchange`.
	 * @param string           $label   Label.
	 * @return void
	 */
	private function register_override_switch( Settings_Handler $handler, string $key, string $field, string $label ): void {
		Field_Kit::add_select(
			$handler,
			self::setting_id( $key, $field ),
			$label,
			[
				Field_Kit::option( self::SOURCE_DEFAULTS, __( 'Как на вкладке «Общие»', 'woodev-plugin-framework' ) ),
				Field_Kit::option( self::SOURCE_OWN, __( 'Свои значения для этого аккаунта', 'woodev-plugin-framework' ) ),
			],
			__( 'Значения берутся с вкладки «Общие» или задаются для этого аккаунта отдельно.', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Setting args that show a field only while the account's switch says «свои».
	 *
	 * @param string $key   Account id string.
	 * @param string $field `override_prices` or `override_exchange`.
	 * @return array<string,mixed>
	 */
	private function shown_when_own( string $key, string $field ): array {
		return [
			'show_if' => [
				'setting'  => self::setting_id( $key, $field ),
				'operator' => '=',
				'value'    => self::SOURCE_OWN,
			],
		];
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

	private function parameters_section( Account $account ): ?Settings_Section {
		$key       = (string) $account->id();
		$stock_ids = [ self::setting_id( $key, 'warehouse_id' ) ];

		if ( $this->adapter->supports( Capability::OVERSIZE_WAREHOUSE ) ) {
			$stock_ids[] = self::setting_id( $key, 'oversize_warehouse_id' );
		}

		$stock_ids[] = self::setting_id( $key, 'stock_mode' );

		$all_ids  = $stock_ids;
		$groups   = [ Settings_Group::create( 'stock_' . $key, __( 'Остатки и склад', 'woodev-plugin-framework' ) )->with_fields( $stock_ids ) ];
		$has_push = null !== $this->adapter->price_pusher();

		if ( [] !== $this->price_direction_options( '' ) ) {
			$price_ids = array_merge(
				[ self::setting_id( $key, 'price_direction' ), self::setting_id( $key, self::OVERRIDE_PRICES ) ],
				$this->ids_of( $key, Shared_Fields::price_rule_field_names( $this->adapter ) )
			);

			$all_ids  = array_merge( $all_ids, $price_ids );
			$groups[] = Settings_Group::create( 'prices_' . $key, __( 'Цены и наценка', 'woodev-plugin-framework' ) )->with_fields( $price_ids );
		}

		$exchange_names = Shared_Fields::exchange_field_names( $this->adapter );
		if ( [] !== $exchange_names ) {
			$exchange_ids = array_merge( [ self::setting_id( $key, self::OVERRIDE_EXCHANGE ) ], $this->ids_of( $key, $exchange_names ) );

			$all_ids  = array_merge( $all_ids, $exchange_ids );
			$groups[] = Settings_Group::create( 'exchange_' . $key, __( 'Заказы и карточки', 'woodev-plugin-framework' ) )->with_fields( $exchange_ids );
		}

		return Settings_Section::create(
			self::params_section_id( $account->id() ),
			$this->account_title( $account ),
			$all_ids,
			$has_push ? $this->price_example_html( $account ) : ''
		)->with_groups( $groups );
	}

	/**
	 * Setting ids of some of an account's fields.
	 *
	 * @param string   $key    Account id string.
	 * @param string[] $fields Field names.
	 * @return string[]
	 */
	private function ids_of( string $key, array $fields ): array {
		return array_map(
			static function ( string $field ) use ( $key ): string {
				return self::setting_id( $key, $field );
			},
			$fields
		);
	}

	/**
	 * What the «Подключение» tab draws for a saved account: its title, identifier, switch and the disabled «Удалить».
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 */
	private function card_of( Account $account ): array {
		$id_field = $this->adapter->credential_fields()->identifier();
		$spec     = $this->adapter->settings_descriptor()->field_spec( $id_field );

		return [
			'new'              => false,
			'title'            => $this->account_title( $account ),
			'identifier_label' => $spec->label(),
			'identifier'       => (string) ( $account->credentials()[ $id_field ] ?? '' ),
			'enabled'          => $account->is_enabled(),
			'enabled_field'    => self::setting_id( (string) $account->id(), 'enabled' ),
			'delete_hint'      => __( 'Удаление аккаунта появится в одном из следующих обновлений.', 'woodev-plugin-framework' ),
		];
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
			$rule = Price_Rule::from_array( array_merge( self::default_price_rule_array(), Effective_Settings::for_adapter( $this->adapter->id() )->price_rule( $account ) ) );
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
			case self::OVERRIDE_PRICES:
			case self::OVERRIDE_EXCHANGE:
				return Effective_Settings::uses_own( $account->settings(), self::OVERRIDE_GROUPS[ $field ] ) ? self::SOURCE_OWN : self::SOURCE_DEFAULTS;
			case 'orders_enabled':
				return (bool) $account->setting( 'orders_enabled', true );
			case 'import_enabled':
				return (bool) $account->setting( 'import_enabled', false );
		}

		if ( isset( Shared_Fields::PRICE_RULE_FIELDS[ $field ] ) ) {
			$rule_key = Shared_Fields::PRICE_RULE_FIELDS[ $field ];
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
		return array_intersect_key( Effective_Settings::builtin_defaults(), array_flip( Effective_Settings::PRICE_KEYS ) );
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
			new Account( 0, $this->adapter->id(), $name, $enabled, $credentials, null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] )
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

		if ( isset( Shared_Fields::PRICE_RULE_FIELDS[ $field ] ) ) {
			$rule_key = Shared_Fields::PRICE_RULE_FIELDS[ $field ];
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
				if ( $this->accepts_stock_mode( $text ) ) {
					$this->store->update_fields( $id, [ 'stock_mode' => $text ] );
				}
				return;
			case 'price_direction':
				$this->store->update_fields( $id, [ 'price_direction' => $text ] );
				do_action( $this->price_rules_changed_hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names.
				return;
			case self::OVERRIDE_PRICES:
			case self::OVERRIDE_EXCHANGE:
				$this->save_override( $id, self::OVERRIDE_GROUPS[ $field ], self::SOURCE_OWN === $text );
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
	 * Saves one group's «как в Общих / свои» choice into the account's `settings` JSON.
	 *
	 * Switching the price group queues the account's recompute: the rule in force changes with it.
	 *
	 * @param int    $id    Account id.
	 * @param string $group `Effective_Settings::GROUP_*`.
	 * @param bool   $own   True for «свои».
	 * @return void
	 */
	private function save_override( int $id, string $group, bool $own ): void {
		$updated = $this->store->update_settings(
			$id,
			static function ( array $current ) use ( $group, $own ): array {
				$flags                                   = isset( $current[ Effective_Settings::OVERRIDES_KEY ] ) && is_array( $current[ Effective_Settings::OVERRIDES_KEY ] ) ? $current[ Effective_Settings::OVERRIDES_KEY ] : [];
				$flags[ $group ]                         = $own;
				$current[ Effective_Settings::OVERRIDES_KEY ] = $flags;

				return $current;
			}
		);

		if ( $updated && Effective_Settings::GROUP_PRICES === $group ) {
			do_action( $this->price_rules_changed_hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names.
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
			$key    = (string) $account->id();
			$stored = (array) $account->setting( 'price_rule', [] );
			$id_for = static function ( string $field ) use ( $key ): string {
				return self::setting_id( $key, $field );
			};

			$errors = array_merge( $errors, Shared_Fields::validate_price_rule( $stored, $values, $id_for ) );
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
		$pairs   = [ Field_Kit::option( '0', __( '— Выберите склад —', 'woodev-plugin-framework' ) ) ];
		$cached  = $this->warehouses->for_account( $account_id, 'oversize_warehouse_id' === $setting );
		$account = $this->accounts()[ $account_id ] ?? null;
		$stored  = null !== $account ? (int) $account->setting( $setting, 0 ) : 0;

		if ( $stored > 0 && ! isset( $cached[ (string) $stored ] ) ) {
			$pairs[] = Field_Kit::option( (string) $stored, '#' . $stored );
		}

		foreach ( $cached as $id => $name ) {
			$pairs[] = Field_Kit::option( (string) $id, $name );
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
			$options[] = Field_Kit::option(
				Wpdb_Account_Store::PRICE_TO_MARKETPLACE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'С сайта на %1$s: цены на %2$s считаются от цен сайта', 'woodev-plugin-framework' ), $marketplace, $marketplace )
			);
		}
		if ( $has_reader ) {
			$options[] = Field_Kit::option(
				Wpdb_Account_Store::PRICE_FROM_MARKETPLACE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'С %1$s на сайт: цены сайта берутся с %2$s', 'woodev-plugin-framework' ), $marketplace, $marketplace )
			);
		}
		$options[] = Field_Kit::option(
			Wpdb_Account_Store::PRICE_OFF,
			__( 'Не синхронизировать цены', 'woodev-plugin-framework' )
		);

		return $options;
	}

	/**
	 * Builds the `stock_mode` option list: `marketplace` is offered only when the adapter receives stock inbound.
	 *
	 * @param string $marketplace Marketplace name.
	 * @return array<int,array{value:string,label:string}>
	 */
	private function stock_mode_options( string $marketplace ): array {
		$options = [
			Field_Kit::option(
				Wpdb_Account_Store::STOCK_SITE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Главный — сайт: остатки уходят с сайта на %s', 'woodev-plugin-framework' ), $marketplace )
			),
		];

		if ( $this->adapter->supports( Capability::INBOUND_STOCK ) ) {
			$options[] = Field_Kit::option(
				Wpdb_Account_Store::STOCK_MARKETPLACE,
				/* translators: %s: marketplace name. */
				sprintf( __( 'Главный — %1$s: остатки приходят с %2$s на сайт', 'woodev-plugin-framework' ), $marketplace, $marketplace )
			);
		}

		$options[] = Field_Kit::option(
			Wpdb_Account_Store::STOCK_OFF,
			__( 'Не синхронизировать: остатки не отправляются и не приходят', 'woodev-plugin-framework' )
		);

		return $options;
	}

	/**
	 * Tells whether a submitted `stock_mode` may be saved: `site` and `off` always, `marketplace` only with `INBOUND_STOCK`.
	 *
	 * @param string $mode Submitted value.
	 * @return bool
	 */
	private function accepts_stock_mode( string $mode ): bool {
		if ( Wpdb_Account_Store::STOCK_MARKETPLACE === $mode ) {
			return $this->adapter->supports( Capability::INBOUND_STOCK );
		}

		return in_array( $mode, [ Wpdb_Account_Store::STOCK_SITE, Wpdb_Account_Store::STOCK_OFF ], true );
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
					[ 'name', 'enabled', 'warehouse_id', 'oversize_warehouse_id', 'stock_mode', 'price_direction', 'orders_enabled', 'import_enabled', self::OVERRIDE_PRICES, self::OVERRIDE_EXCHANGE ],
					$this->credential_field_names(),
					array_keys( Shared_Fields::PRICE_RULE_FIELDS )
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
}

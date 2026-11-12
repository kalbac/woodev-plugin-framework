<?php
/**
 * «Общие» settings: the defaults every account can override.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Settings\Settings_Group;
use Woodev\Framework\Settings\Settings_Section;

defined( 'ABSPATH' ) || exit;

/**
 * The values an account uses while its group is «как в Общих»: the price rule (base, markup, rounding, old price, minimum price)
 * and the exchange switches (orders import, cards auto-import).
 *
 * The values are one option, {@see Effective_Settings::option_name()}, that {@see Effective_Settings} reads at runtime; this class is
 * only the screen over it. Setting ids are `defaults_{field}` (`defaults_price_base`, `defaults_orders_enabled`, …).
 *
 * Saving a price field queues the price recompute of every account that follows the defaults, through the same
 * `account_price_rules_changed` hook an account's own rule fires.
 *
 * @since 2.1.5
 */
final class Defaults_Section implements Settings_Section_Provider, Table_Backed_Settings {
	public const SECTION_ID = 'defaults';
	public const PREFIX     = 'defaults_';

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
	 * Action fired with an account id when its price rule changes.
	 *
	 * @var string
	 */
	private $price_rules_changed_hook;

	/**
	 * Accounts whose recompute was already queued in this request.
	 *
	 * @var array<int,true>
	 */
	private $queued = [];

	/**
	 * Creates the «Общие» provider.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Adapter $adapter   Adapter.
	 * @param Account_Store       $accounts  Account store.
	 * @param string              $plugin_id Plugin id (drives the `Job_Names` hook).
	 */
	public function __construct( Marketplace_Adapter $adapter, Account_Store $accounts, string $plugin_id ) {
		$this->adapter                  = $adapter;
		$this->accounts                 = $accounts;
		$this->price_rules_changed_hook = ( new Job_Names( $plugin_id ) )->hook( 'account_price_rules_changed' );
	}

	/**
	 * Setting id of a default (`defaults_{field}`).
	 *
	 * @since 2.1.5
	 *
	 * @param string $field Field name (`price_base`, `orders_enabled`, …).
	 * @return string
	 */
	public static function setting_id( string $field ): string {
		return self::PREFIX . $field;
	}

	/**
	 * Fills the defaults from the first account on the first run (before the handler loads its values), then registers the fields.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler $handler Handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
		( new Defaults_Migration( $this->accounts, $this->adapter->id() ) )->run();

		$id_for = [ self::class, 'setting_id' ];

		if ( [] !== $this->price_fields() ) {
			Shared_Fields::register_price_rule( $handler, $id_for, $this->adapter );
		}

		Shared_Fields::register_exchange( $handler, $id_for, $this->adapter );
	}

	/**
	 * Whether the adapter has any default to offer; without one the «Общие» tab is not listed.
	 *
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function has_fields(): bool {
		return [] !== $this->price_fields() || [] !== Shared_Fields::exchange_field_names( $this->adapter );
	}

	/**
	 * Builds the «Общие» section: a card for the price rule and a card for orders and cards; `[]` when the adapter has neither.
	 *
	 * @since 2.1.5
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array {
		$price_ids    = array_map( [ self::class, 'setting_id' ], $this->price_fields() );
		$exchange_ids = array_map( [ self::class, 'setting_id' ], Shared_Fields::exchange_field_names( $this->adapter ) );
		$groups       = [];

		if ( [] !== $price_ids ) {
			$groups[] = Settings_Group::create( 'defaults_prices', __( 'Цены и наценка', 'woodev-plugin-framework' ) )->with_fields( $price_ids );
		}

		if ( [] !== $exchange_ids ) {
			$groups[] = Settings_Group::create( 'defaults_exchange', __( 'Заказы и карточки', 'woodev-plugin-framework' ) )->with_fields( $exchange_ids );
		}

		if ( [] === $groups ) {
			return [];
		}

		return [
			Settings_Section::create(
				self::SECTION_ID,
				__( 'Общие', 'woodev-plugin-framework' ),
				array_merge( $price_ids, $exchange_ids ),
				'<p>' . esc_html__( 'Значения по умолчанию для всех аккаунтов. На вкладке «Параметры» каждый аккаунт может использовать их или задать свои.', 'woodev-plugin-framework' ) . '</p>'
			)->with_groups( $groups ),
		];
	}

	/**
	 * Tells whether `$setting_id` is one of the defaults this adapter shows.
	 *
	 * @since 2.1.5
	 *
	 * @param string $setting_id Setting id.
	 * @return bool
	 */
	public function handles( string $setting_id ): bool {
		return null !== $this->field_of( $setting_id );
	}

	/**
	 * Reads a default in the form its control expects.
	 *
	 * @since 2.1.5
	 *
	 * @param string $setting_id Setting id.
	 * @return mixed
	 */
	public function read( string $setting_id ) {
		$field = $this->field_of( $setting_id );

		if ( null === $field ) {
			return '';
		}

		$stored = Effective_Settings::for_adapter( $this->adapter->id() )->defaults();

		if ( $this->is_boolean( $field ) ) {
			return (bool) $stored[ $field ];
		}

		return (string) ( $stored[ Shared_Fields::PRICE_RULE_FIELDS[ $field ] ] ?? '' );
	}

	/**
	 * Saves a default; a changed price field queues the recompute of every account that follows the defaults.
	 *
	 * @since 2.1.5
	 *
	 * @param string $setting_id Setting id.
	 * @param mixed  $value      Submitted value.
	 * @return void
	 */
	public function write( string $setting_id, $value ): void {
		$field = $this->field_of( $setting_id );

		if ( null === $field ) {
			return;
		}

		$option  = Effective_Settings::option_name( $this->adapter->id() );
		$all     = get_option( $option, [] );
		$all     = is_array( $all ) ? $all : [];
		$key     = $this->is_boolean( $field ) ? $field : Shared_Fields::PRICE_RULE_FIELDS[ $field ];
		$new     = $this->is_boolean( $field ) ? $this->to_bool( $value ) : $this->price_value( $key, $value );
		$changed = ! array_key_exists( $key, $all ) || $all[ $key ] !== $new;

		if ( ! $changed ) {
			return;
		}

		$all[ $key ] = $new;
		update_option( $option, $all );

		if ( ! $this->is_boolean( $field ) ) {
			$this->queue_price_recompute();
		}
	}

	/**
	 * Checks the price-rule defaults together (a number that is not a number, a minimum percent out of range).
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,mixed> $values Submitted `setting id => value` map.
	 * @return array<string,string>
	 */
	public function validate( array $values ): array {
		$stored = Effective_Settings::for_adapter( $this->adapter->id() )->defaults();

		return Shared_Fields::validate_price_rule( $stored, $values, [ self::class, 'setting_id' ] );
	}

	/**
	 * Names of the price-rule fields the adapter shows, when it handles prices at all.
	 *
	 * @return string[]
	 */
	private function price_fields(): array {
		if ( null === $this->adapter->price_pusher() && null === $this->adapter->price_reader() ) {
			return [];
		}

		return Shared_Fields::price_rule_field_names( $this->adapter );
	}

	/**
	 * Field name behind a setting id, when it is a default this adapter shows.
	 *
	 * @param string $setting_id Setting id.
	 * @return string|null
	 */
	private function field_of( string $setting_id ): ?string {
		if ( 0 !== strpos( $setting_id, self::PREFIX ) ) {
			return null;
		}

		$field = substr( $setting_id, strlen( self::PREFIX ) );
		$known = array_merge( $this->price_fields(), Shared_Fields::exchange_field_names( $this->adapter ) );

		return in_array( $field, $known, true ) ? $field : null;
	}

	/**
	 * Whether a field is an on/off switch.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_boolean( string $field ): bool {
		return in_array( $field, [ 'orders_enabled', 'import_enabled' ], true );
	}

	/**
	 * Coerces a submitted switch value.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	private function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Normalises a submitted price-rule value: trimmed, and an empty minimum price is none.
	 *
	 * @param string $rule_key `Price_Rule` key.
	 * @param mixed  $value    Submitted value.
	 * @return string|null
	 */
	private function price_value( string $rule_key, $value ): ?string {
		$text = trim( (string) $value );

		return '' === $text && 'min_percent' === $rule_key ? null : $text;
	}

	/**
	 * Queues the price recompute of every account that follows the defaults, once per account and request.
	 *
	 * @return void
	 */
	private function queue_price_recompute(): void {
		foreach ( $this->accounts->all( $this->adapter->id(), false ) as $account ) {
			if ( Effective_Settings::uses_own( $account->settings(), Effective_Settings::GROUP_PRICES ) || isset( $this->queued[ $account->id() ] ) ) {
				continue;
			}

			$this->queued[ $account->id() ] = true;

			do_action( $this->price_rules_changed_hook, $account->id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built by Job_Names (`woodev_{plugin_id}_account_price_rules_changed`).
		}
	}
}

<?php
/**
 * Effective values of the account knobs that can come from the «Общие» defaults.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Price\Price_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * The ONE place that answers «what is this account's price rule / order receiving / card import right now».
 *
 * Two groups of knobs have defaults on the «Общие» tab: `prices` (the six {@see Price_Rule} fields) and `exchange`
 * (`orders_enabled`, `import_enabled`). Each account chooses per group whether it uses its own values («свои») or the defaults
 * («как в Общих»); the choice is `settings.overrides.{group}` in the account's `settings` JSON (true = own).
 *
 * - **No flag means «свои».** An account that existed before the defaults did, or one a plugin's own migration created, keeps
 *   behaving exactly as it did. Only an account created on the settings screen gets an explicit `false` ({@see self::default_overrides()}).
 * - The defaults are one option, `woodev_{adapter id}_account_defaults`, keyed by the **adapter** id because an {@see Account} knows
 *   its adapter, not its plugin, and every runtime reader holds an Account.
 * - Every runtime reader of `orders_enabled`, `import_enabled` and the account's `price_rule` asks this class instead of
 *   `Account::setting()`. The settings screen reads and writes the account's own values directly.
 *
 * @since 2.1.5
 */
final class Effective_Settings {
	public const GROUP_PRICES   = 'prices';
	public const GROUP_EXCHANGE = 'exchange';

	/**
	 * Key of the `settings` JSON that holds the per-group choice.
	 */
	public const OVERRIDES_KEY = 'overrides';

	/**
	 * Key of the defaults option that marks «already filled from the first account» (see {@see Defaults_Migration}).
	 */
	public const SEEDED_KEY = '_seeded';

	/**
	 * The `Price_Rule::from_array()` keys, in the order the screen lists them.
	 *
	 * @var string[]
	 */
	public const PRICE_KEYS = [ 'base', 'markup_kind', 'markup', 'rounding', 'old_price_rule', 'min_percent' ];

	/**
	 * Option reader (`function ( string $name, $default ): mixed`).
	 *
	 * @var callable
	 */
	private $get_option;

	/**
	 * Adapter id the defaults belong to.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Creates the resolver.
	 *
	 * @since 2.1.5
	 *
	 * @param string        $adapter_id Adapter id.
	 * @param callable|null $get_option Optional option reader, `get_option` by default.
	 */
	public function __construct( string $adapter_id, ?callable $get_option = null ) {
		$this->adapter_id = $adapter_id;
		$this->get_option = $get_option ?? 'get_option';
	}

	/**
	 * Builds the resolver of an adapter.
	 *
	 * @since 2.1.5
	 *
	 * @param string $adapter_id Adapter id.
	 * @return self
	 */
	public static function for_adapter( string $adapter_id ): self {
		return new self( $adapter_id );
	}

	/**
	 * Name of the option that holds the defaults of an adapter.
	 *
	 * @since 2.1.5
	 *
	 * @param string $adapter_id Adapter id.
	 * @return string
	 */
	public static function option_name( string $adapter_id ): string {
		return 'woodev_' . $adapter_id . '_account_defaults';
	}

	/**
	 * The flags a new account gets on the settings screen: both groups follow the defaults.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,bool>
	 */
	public static function default_overrides(): array {
		return [
			self::GROUP_PRICES   => false,
			self::GROUP_EXCHANGE => false,
		];
	}

	/**
	 * The built-in defaults, used for every key the merchant has not saved on «Общие».
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,mixed> `price_rule` keys plus `orders_enabled` and `import_enabled`.
	 */
	public static function builtin_defaults(): array {
		return [
			'base'           => Price_Rule::BASE_ACTIVE,
			'markup_kind'    => Price_Rule::MARKUP_NONE,
			'markup'         => '0',
			'rounding'       => Price_Rule::ROUND_NONE,
			'old_price_rule' => Price_Rule::OLD_PRICE_ON_SALE,
			'min_percent'    => null,
			'orders_enabled' => true,
			'import_enabled' => false,
		];
	}

	/**
	 * Tells whether an account uses its own values for a group.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,mixed> $account_settings `Account::settings()`.
	 * @param string              $group            `self::GROUP_*`.
	 * @return bool False only when the account was explicitly set to follow the defaults.
	 */
	public static function uses_own( array $account_settings, string $group ): bool {
		$flags = $account_settings[ self::OVERRIDES_KEY ] ?? null;

		if ( ! is_array( $flags ) || ! array_key_exists( $group, $flags ) ) {
			return true;
		}

		return (bool) $flags[ $group ];
	}

	/**
	 * The defaults as saved on «Общие», completed with the built-in ones.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		$stored = call_user_func( $this->get_option, self::option_name( $this->adapter_id ), [] );
		$stored = is_array( $stored ) ? $stored : [];

		return array_merge( self::builtin_defaults(), array_intersect_key( $stored, self::builtin_defaults() ) );
	}

	/**
	 * Whether the defaults were already filled from the first account.
	 *
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function is_seeded(): bool {
		$stored = call_user_func( $this->get_option, self::option_name( $this->adapter_id ), [] );

		return is_array( $stored ) && ! empty( $stored[ self::SEEDED_KEY ] );
	}

	/**
	 * The price rule in force for an account, in the shape `Price_Rule::from_array()` reads.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed> Empty when the account uses its own values and has none stored (the caller's fallback applies).
	 */
	public function price_rule( Account $account ): array {
		if ( self::uses_own( $account->settings(), self::GROUP_PRICES ) ) {
			$own = $account->setting( 'price_rule' );

			return is_array( $own ) ? $own : [];
		}

		return array_intersect_key( $this->defaults(), array_flip( self::PRICE_KEYS ) );
	}

	/**
	 * Whether the account turns marketplace orders into WooCommerce orders.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public function orders_enabled( Account $account ): bool {
		return $this->exchange_flag( $account, 'orders_enabled', true );
	}

	/**
	 * Whether the account creates site products for new marketplace cards.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public function import_enabled( Account $account ): bool {
		return $this->exchange_flag( $account, 'import_enabled', false );
	}

	/**
	 * The effective exchange switches of an accounts-table row, for the readers that work on rows and not on {@see Account} values.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,mixed> $row Row of the accounts table (`orders_enabled`, `import_enabled`, `settings`).
	 * @return array{orders_enabled:bool,import_enabled:bool}
	 */
	public function exchange_of_row( array $row ): array {
		$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );

		if ( self::uses_own( is_array( $settings ) ? $settings : [], self::GROUP_EXCHANGE ) ) {
			return [
				'orders_enabled' => (bool) ( $row['orders_enabled'] ?? true ),
				'import_enabled' => (bool) ( $row['import_enabled'] ?? false ),
			];
		}

		$defaults = $this->defaults();

		return [
			'orders_enabled' => (bool) $defaults['orders_enabled'],
			'import_enabled' => (bool) $defaults['import_enabled'],
		];
	}

	/**
	 * Reads one exchange switch: the account's own, or the default.
	 *
	 * @param Account $account  Account.
	 * @param string  $key      `orders_enabled` or `import_enabled`.
	 * @param bool    $fallback Value when the account holds none.
	 * @return bool
	 */
	private function exchange_flag( Account $account, string $key, bool $fallback ): bool {
		if ( self::uses_own( $account->settings(), self::GROUP_EXCHANGE ) ) {
			return (bool) $account->setting( $key, $fallback );
		}

		return (bool) $this->defaults()[ $key ];
	}
}

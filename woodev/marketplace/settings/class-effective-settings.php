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
	 * Key of the «switch synchronisation on right after linking» switch: part of the `exchange` group, kept in the account's `settings`
	 * JSON (it has no column), with a «Общие» default.
	 *
	 * @since 2.1.8
	 */
	public const KEY_SYNC_ON_LINK = 'sync_on_link';

	/**
	 * Keys of the two card-import settings. They exist once per shop, on «Общие» only: an account never overrides them.
	 */
	public const KEY_IMPORT_PUBLISH    = 'import_publish';
	public const KEY_IMPORT_CATEGORIES = 'import_create_categories';

	/**
	 * Key of the defaults option that holds the values of an adapter's own fields ({@see Settings_Extension}): a sub-array of its own,
	 * so a field of an adapter never meets a key of the framework.
	 *
	 * @since 2.1.7
	 */
	public const EXTRA_KEY = 'extra';

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
	 * @return array<string,mixed> `price_rule` keys, `orders_enabled`, `import_enabled`, `sync_on_link`, and the two card-import settings.
	 */
	public static function builtin_defaults(): array {
		return [
			'base'                      => Price_Rule::BASE_ACTIVE,
			'markup_kind'               => Price_Rule::MARKUP_NONE,
			'markup'                    => '0',
			'rounding'                  => Price_Rule::ROUND_NONE,
			'old_price_rule'            => Price_Rule::OLD_PRICE_ON_SALE,
			'min_percent'               => null,
			'orders_enabled'            => true,
			'import_enabled'            => false,
			self::KEY_SYNC_ON_LINK      => true, // Linking a card switches stock and price synchronisation on at once (an account that never saved the key reads this).
			self::KEY_IMPORT_PUBLISH    => false, // A new shop gets drafts: the price and the text are the marketplace's, nobody has looked at them yet.
			self::KEY_IMPORT_CATEGORIES => true,
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
	 * Whether linking a card to a product switches the product's stock and price synchronisation on at once.
	 *
	 * An account whose `settings` JSON holds no value (a migrated one, or one that never saved the switch) reads the built-in default,
	 * which is on. What «on» achieves still depends on the account's stock mode and price direction; the switch only grants the
	 * per-link permission.
	 *
	 * @since 2.1.8
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public function sync_on_link( Account $account ): bool {
		return $this->exchange_flag( $account, self::KEY_SYNC_ON_LINK, true );
	}

	/**
	 * Whether an imported product is published at once; otherwise it is saved as a draft.
	 *
	 * Shop-wide: it is not part of an account's «свои / как в Общих» choice.
	 *
	 * @since 2.1.6
	 *
	 * @return bool
	 */
	public function import_publish(): bool {
		return (bool) $this->defaults()[ self::KEY_IMPORT_PUBLISH ];
	}

	/**
	 * Whether the importer creates the shop categories a card needs when none is mapped.
	 *
	 * @since 2.1.6
	 *
	 * @return bool
	 */
	public function import_create_categories(): bool {
		return (bool) $this->defaults()[ self::KEY_IMPORT_CATEGORIES ];
	}

	/**
	 * The shop-wide value of a field an adapter added to «Общие» ({@see Settings_Extension::default_groups()}).
	 *
	 * @since 2.1.7
	 *
	 * @param string $field_id Field id.
	 * @param mixed  $default  Returned when the merchant has not saved the field (pass the field's own default).
	 * @return mixed A select's value is a string, a toggle's a bool. The stored value is returned unchecked: an option the adapter
	 *               has since dropped comes back as it was saved, so normalise it against your own options.
	 */
	public function extra( string $field_id, $default = null ) {
		$stored = call_user_func( $this->get_option, self::option_name( $this->adapter_id ), [] );
		$extra  = is_array( $stored ) && isset( $stored[ self::EXTRA_KEY ] ) && is_array( $stored[ self::EXTRA_KEY ] ) ? $stored[ self::EXTRA_KEY ] : [];

		return array_key_exists( $field_id, $extra ) ? $extra[ $field_id ] : $default;
	}

	/**
	 * The value in force for a product: what the merchant chose on the product's tab for a field of
	 * {@see Settings_Extension::product_groups()}, or, when the product holds none, the shop-wide value of the field with the same id
	 * ({@see self::extra()}). A variation with no value of its own follows its parent product first.
	 *
	 * @since 2.1.7
	 *
	 * @param \WC_Product $product  Product.
	 * @param string      $field_id Field id.
	 * @param mixed       $default  Returned when neither the product nor «Общие» holds a value.
	 * @return mixed The stored value, unchecked: an option the adapter has since dropped comes back as it was saved, so normalise it
	 *               against your own options.
	 */
	public function product_extra( \WC_Product $product, string $field_id, $default = null ) {
		$own = $this->own_product_value( $product, $field_id );

		if ( '' === $own && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( (int) $product->get_parent_id() );
			$own    = $parent instanceof \WC_Product ? $this->own_product_value( $parent, $field_id ) : '';
		}

		return '' !== $own ? $own : $this->extra( $field_id, $default );
	}

	/**
	 * The value a product itself holds for an adapter's field.
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param string      $field_id Field id.
	 * @return string '' when it holds none.
	 */
	private function own_product_value( \WC_Product $product, string $field_id ): string {
		$own = $product->get_meta( Extension_Field::product_meta_key( $this->adapter_id, $field_id ), true );

		return is_string( $own ) ? $own : '';
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
	 * @param string  $key      `orders_enabled`, `import_enabled` or `sync_on_link`.
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

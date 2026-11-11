<?php
/**
 * The part of an account the notification intake needs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Identity, switch, URL token and feature flags of one account, read from the accounts table. The intake, the drain and the
 * notification settings need nothing else, and never the secrets.
 *
 * The feature flags are here because the notification types an account should be subscribed to follow from them; which types
 * those are is the marketplace's knowledge, so the plugin derives the list from these getters.
 *
 * @since 2.1.0
 */
final class Notification_Account {
	/**
	 * Account id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The cabinet's identifier from the adapter's credential fields.
	 *
	 * @var string
	 */
	private $external_id;

	/**
	 * Seller id, '' when unknown.
	 *
	 * @var string
	 */
	private $seller_id;

	/**
	 * Merchant switch.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Secret part of the notification URL.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Whether marketplace orders become WooCommerce orders.
	 *
	 * @var bool
	 */
	private $orders_enabled;

	/**
	 * Stock mode: `site` or `marketplace`.
	 *
	 * @var string
	 */
	private $stock_mode;

	/**
	 * Price direction: `to_marketplace`, `from_marketplace` or `off`.
	 *
	 * @var string
	 */
	private $price_direction;

	/**
	 * Whether new cards are imported automatically.
	 *
	 * @var bool
	 */
	private $import_enabled;

	/**
	 * Last registration state stored for the URL.
	 *
	 * @var array<string,mixed>
	 */
	private $state;

	/**
	 * Creates the account view.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $row Row of the accounts table.
	 */
	public function __construct( array $row ) {
		$state = json_decode( (string) ( $row['notification_state'] ?? '' ), true );

		$this->id              = (int) $row['account_id'];
		$this->external_id     = (string) $row['external_id'];
		$this->seller_id       = isset( $row['seller_id'] ) ? (string) $row['seller_id'] : '';
		$this->enabled         = (bool) $row['is_enabled'];
		$this->token           = (string) $row['notification_token'];
		$this->orders_enabled  = (bool) $row['orders_enabled'];
		$this->stock_mode      = (string) $row['stock_mode'];
		$this->price_direction = (string) $row['price_direction'];
		$this->import_enabled  = (bool) $row['import_enabled'];
		$this->state           = is_array( $state ) ? $state : [];
	}

	/**
	 * Returns the account id.
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Returns the cabinet's identifier.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function external_id(): string {
		return $this->external_id;
	}

	/**
	 * Returns the seller id, '' when unknown.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function seller_id(): string {
		return $this->seller_id;
	}

	/**
	 * Tells whether the merchant switched the account on.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Returns the secret part of the notification URL.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Tells whether marketplace orders become WooCommerce orders.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function orders_enabled(): bool {
		return $this->orders_enabled;
	}

	/**
	 * Returns the stock mode: `site` or `marketplace`.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function stock_mode(): string {
		return $this->stock_mode;
	}

	/**
	 * Returns the price direction: `to_marketplace`, `from_marketplace` or `off`.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function price_direction(): string {
		return $this->price_direction;
	}

	/**
	 * Tells whether new cards are imported automatically.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function import_enabled(): bool {
		return $this->import_enabled;
	}

	/**
	 * Returns the stored registration state of the URL.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		return $this->state;
	}
}

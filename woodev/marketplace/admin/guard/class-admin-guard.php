<?php
/**
 * Transport-neutral authorization guard for marketplace admin actions.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Guard;

use Woodev\Framework\Marketplace\Account\Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a nonce, a capability (with an optional object id) and — when an action targets a marketplace account —
 * that the account exists in this plugin's store.
 *
 * Transport-neutral: {@see self::check()} takes the nonce string as an argument so classic AJAX, `admin-post` and
 * REST endpoints share one verification path without reading superglobals inside the check.
 *
 * @since 2.1.2
 */
final class Admin_Guard {
	/**
	 * Predicate telling whether an account id belongs to this plugin (`function ( int $account_id ): bool`).
	 *
	 * @var callable|null
	 */
	private $account_exists;

	/**
	 * Creates the guard.
	 *
	 * @since 2.1.2
	 *
	 * @param callable|null $account_exists `function ( int $account_id ): bool`, required when an action checks `$account_id`.
	 */
	public function __construct( ?callable $account_exists = null ) {
		$this->account_exists = $account_exists;
	}

	/**
	 * Builds a guard backed by an account store, scoped to one marketplace adapter id.
	 *
	 * @since 2.1.2
	 *
	 * @param Account_Store $store      Account store.
	 * @param string        $adapter_id Adapter id owned by this plugin.
	 * @return self
	 */
	public static function for_store( Account_Store $store, string $adapter_id ): self {
		return new self(
			static function ( int $account_id ) use ( $store, $adapter_id ): bool {
				$account = $store->get( $account_id );

				return null !== $account && $account->adapter_id() === $adapter_id;
			}
		);
	}

	/**
	 * Verifies nonce, capability and optional account ownership.
	 *
	 * @since 2.1.2
	 *
	 * @param string   $capability   Capability name (`edit_post`, `edit_shop_order`, `manage_woocommerce`, …).
	 * @param string   $nonce_action Nonce action expected by `wp_verify_nonce()`.
	 * @param string   $nonce        Submitted nonce token.
	 * @param int|null $object_id    Object id for meta capabilities (`edit_post`, `edit_shop_order`), or null.
	 * @param int|null $account_id   Account id the action targets, or null when no account is targeted.
	 * @return bool
	 */
	public function check( string $capability, string $nonce_action, string $nonce, ?int $object_id = null, ?int $account_id = null ): bool {
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $nonce_action ) ) {
			return false;
		}

		$allowed = null === $object_id
			? current_user_can( $capability ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is caller-specified.
			: current_user_can( $capability, $object_id ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is caller-specified.

		if ( ! $allowed ) {
			return false;
		}

		if ( null === $account_id ) {
			return true;
		}

		if ( $account_id <= 0 || null === $this->account_exists ) {
			return false;
		}

		return true === (bool) call_user_func( $this->account_exists, $account_id );
	}

	/**
	 * Convenience wrapper for classic `admin-ajax` / `admin-post` handlers that pass the nonce in `$_REQUEST`.
	 *
	 * @since 2.1.2
	 *
	 * @param string   $capability   Capability name.
	 * @param string   $nonce_action Nonce action.
	 * @param int|null $object_id    Optional object id for meta capabilities.
	 * @param int|null $account_id   Optional account id.
	 * @param string   $nonce_key    Key in `$_REQUEST`, default `_wpnonce`.
	 * @return bool
	 */
	public function check_request( string $capability, string $nonce_action, ?int $object_id = null, ?int $account_id = null, string $nonce_key = '_wpnonce' ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This method extracts the nonce to pass it to wp_verify_nonce() in self::check().
		$raw   = $_REQUEST[ $nonce_key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';

		return $this->check( $capability, $nonce_action, $nonce, $object_id, $account_id );
	}
}

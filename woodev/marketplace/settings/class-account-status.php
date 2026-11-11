<?php
/**
 * Stored connection status formatter for marketplace account settings blocks.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Account\Key_Expiry;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Redactor;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Formats the stored connection status (`Woodev_Connection_Result::success()`, `failure()`, or `null` when unchecked)
 * of an account block for `Woodev_Settings_Connection_Status`, reading only local database state and never making
 * remote HTTP calls.
 *
 * @since 2.1.2
 */
final class Account_Status {
	/**
	 * Account store.
	 *
	 * @var Account_Store
	 */
	private $store;

	/**
	 * Marketplace adapter.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the status reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Account_Store       $store   Account store.
	 * @param Marketplace_Adapter $adapter Marketplace adapter.
	 * @param callable|null       $clock   `function (): int`, defaults to `time`.
	 */
	public function __construct( Account_Store $store, Marketplace_Adapter $adapter, ?callable $clock = null ) {
		$this->store   = $store;
		$this->adapter = $adapter;
		$this->clock   = $clock ?? 'time';
	}

	/**
	 * Returns null for an empty or unchecked block (`Woodev_Settings_Connection_Status` treats null as not configured).
	 *
	 * @since 2.1.2
	 *
	 * @return \Woodev_Connection_Result|null
	 */
	public function empty_block(): ?\Woodev_Connection_Result {
		return null;
	}

	/**
	 * Returns the stored connection status of a saved account, or null when never checked yet.
	 *
	 * @since 2.1.2
	 *
	 * @param Account $account Saved account.
	 * @return \Woodev_Connection_Result|null
	 */
	public function for_account( Account $account ): ?\Woodev_Connection_Result {
		$descriptor  = $this->adapter->settings_descriptor();
		$credentials = $account->credentials();

		if ( ! empty( $credentials[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] ) ) {
			return \Woodev_Connection_Result::failure( $descriptor->unreadable_secrets_message() );
		}

		if ( ! $this->has_all_credentials( $credentials ) ) {
			return null;
		}

		$now_ts     = (int) call_user_func( $this->clock );
		$now        = ( new \DateTimeImmutable( '@' . $now_ts ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$health     = $this->read_health( $account->id() );
		$expires_at = ( null !== $health ? $health->expires_at() : null ) ?? $account->expires_at();

		if ( null === $health ) {
			if ( null !== $expires_at && Key_Expiry::TIER_EXPIRED === Key_Expiry::tier( $expires_at, $now ) ) {
				return \Woodev_Connection_Result::failure( $this->expiry_warning( $expires_at, $now ) ?? '' );
			}
			return null;
		}

		$secret_fields = $this->adapter->credential_fields()->secrets();
		$secret_vals   = [];
		foreach ( $secret_fields as $field ) {
			if ( isset( $credentials[ $field ] ) && is_string( $credentials[ $field ] ) && '' !== $credentials[ $field ] ) {
				$secret_vals[] = $credentials[ $field ];
			}
		}

		if ( ! $health->is_ok() ) {
			$raw_msg = trim( $health->message() );
			$detail  = '' !== $raw_msg
				? Report_Redactor::redact_text( $raw_msg, $secret_fields, $secret_vals )
				: $this->fallback_error_detail( $health->status(), $descriptor->marketplace_name() );

			return \Woodev_Connection_Result::failure( $detail );
		}

		if ( null !== $expires_at ) {
			$warning = $this->expiry_warning( $expires_at, $now );
			if ( null !== $warning ) {
				return \Woodev_Connection_Result::failure( $warning );
			}
		}

		return \Woodev_Connection_Result::success( $this->connected_detail( $account, $expires_at, $now ) );
	}

	/**
	 * Builds the warning/expired message when a key is inside a {@see Key_Expiry} tier (`<= 30` days or expired).
	 *
	 * @since 2.1.2
	 *
	 * @param \DateTimeImmutable $expires_at Expiry timestamp.
	 * @param \DateTimeImmutable $now        Current timestamp.
	 * @return string|null Null when more than 30 days remain.
	 */
	public function expiry_warning( \DateTimeImmutable $expires_at, \DateTimeImmutable $now ): ?string {
		$tier = Key_Expiry::tier( $expires_at, $now );
		if ( null === $tier ) {
			return null;
		}

		$date   = (string) wp_date( 'd.m.Y', $expires_at->getTimestamp() );
		$advice = trim( $this->adapter->settings_descriptor()->key_renewal_advice() );
		$tail   = '' !== $advice ? ' ' . $advice : '';

		if ( Key_Expiry::TIER_EXPIRED === $tier ) {
			/* translators: %s: key expiry date in d.m.Y format. */
			return sprintf( __( 'Срок действия ключа истёк %s.', 'woodev-plugin-framework' ), $date ) . $tail;
		}

		$days = Key_Expiry::days_left( $expires_at, $now );
		if ( $days < 1 ) {
			/* translators: %s: key expiry date in d.m.Y format. */
			return sprintf( __( 'Ключ перестанет действовать меньше чем через сутки (%s).', 'woodev-plugin-framework' ), $date ) . $tail;
		}

		/* translators: 1: key expiry date in d.m.Y format, 2: whole days remaining. */
		return sprintf( __( 'Ключ истекает %1$s (осталось дней: %2$d).', 'woodev-plugin-framework' ), $date, $days ) . $tail;
	}

	/**
	 * Reads stored health when the account store exposes it (`Wpdb_Account_Store` or a test double).
	 *
	 * @param int $account_id Account id.
	 * @return Health_Result|null
	 */
	private function read_health( int $account_id ): ?Health_Result {
		if ( $this->store instanceof Wpdb_Account_Store ) {
			return $this->store->health( $account_id );
		}

		if ( method_exists( $this->store, 'health' ) ) {
			$health = $this->store->health( $account_id );

			return $health instanceof Health_Result ? $health : null;
		}

		if ( method_exists( $this->store, 'health_of' ) ) {
			$health = $this->store->health_of( $account_id );

			return $health instanceof Health_Result ? $health : null;
		}

		return null;
	}

	/**
	 * Tells whether every required credential field of the adapter is non-empty.
	 *
	 * @param array<string,mixed> $credentials Account credentials map.
	 * @return bool
	 */
	private function has_all_credentials( array $credentials ): bool {
		$fields = $this->adapter->credential_fields();
		$names  = array_merge( [ $fields->identifier() ], $fields->secrets() );

		foreach ( $names as $field ) {
			if ( '' === trim( (string) ( $credentials[ $field ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds the connected detail line (currency, expiry date and switch state).
	 *
	 * @param Account                 $account    Account.
	 * @param \DateTimeImmutable|null $expires_at Expiry timestamp, or null.
	 * @param \DateTimeImmutable      $now        Current timestamp.
	 * @return string
	 */
	private function connected_detail( Account $account, ?\DateTimeImmutable $expires_at, \DateTimeImmutable $now ): string {
		$parts = [ __( 'Подключено', 'woodev-plugin-framework' ) ];

		$currency = trim( (string) $account->setting( 'currency', '' ) );
		if ( '' !== $currency ) {
			/* translators: %s: ISO currency code of the seller cabinet. */
			$parts[] = sprintf( __( 'валюта %s', 'woodev-plugin-framework' ), $currency );
		}

		if ( null !== $expires_at ) {
			$date    = (string) wp_date( 'd.m.Y', $expires_at->getTimestamp() );
			$days    = Key_Expiry::days_left( $expires_at, $now );
			$parts[] = sprintf(
				/* translators: 1: expiry date in d.m.Y format, 2: whole days remaining. */
				__( 'ключ до %1$s (дней: %2$d)', 'woodev-plugin-framework' ),
				$date,
				$days
			);
		}

		if ( ! $account->is_enabled() ) {
			$parts[] = __( 'аккаунт выключен', 'woodev-plugin-framework' );
		}

		return implode( ', ', $parts ) . '.';
	}

	/**
	 * Fallback localized error text when `Health_Result::message()` is empty.
	 *
	 * @param string $status      Non-OK status code.
	 * @param string $marketplace Marketplace display name.
	 * @return string
	 */
	private function fallback_error_detail( string $status, string $marketplace ): string {
		switch ( $status ) {
			case Health_Result::ROLE_MISSING:
				return __( 'У ключа недостаточно прав для работы с API.', 'woodev-plugin-framework' );
			case Health_Result::KEY_EXPIRED:
				return __( 'Срок действия ключа истёк.', 'woodev-plugin-framework' );
			case Health_Result::UNREACHABLE:
				/* translators: %s: marketplace name. */
				return sprintf( __( '%s сейчас не отвечает. Повторите проверку позже.', 'woodev-plugin-framework' ), $marketplace );
		}

		return __( 'Ключ отклонён маркетплейсом. Проверьте учётные данные.', 'woodev-plugin-framework' );
	}
}

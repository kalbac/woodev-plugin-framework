<?php
/**
 * Admin notices for marketplace credentials that are expiring or have expired.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Notices;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Key_Expiry;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one `Woodev_Admin_Notice_Handler` notice per enabled account whose `key_expires_at` has reached a
 * {@see Key_Expiry} tier (`30`, `7`, `1`, `expired`).
 *
 * Each notice id carries the plugin prefix, the account id, the first 8 characters of `secret_fingerprint` and the
 * tier: dismissing the 30-day warning leaves the 7-day, 1-day and expired tiers intact, and replacing the key resets
 * dismissals automatically because the fingerprint changes. Expired keys are non-dismissible errors.
 *
 * @since 2.1.2
 */
final class Key_Expiry_Notices {
	/**
	 * Notice handler.
	 *
	 * @var \Woodev_Admin_Notice_Handler
	 */
	private $notices;

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
	 * URL of the plugin's settings screen ('' when unavailable).
	 *
	 * @var string
	 */
	private $settings_url;

	/**
	 * Prefix for notice ids (`{plugin-id}-key-expiry-...`).
	 *
	 * @var string
	 */
	private $id_prefix;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the notice registrar.
	 *
	 * @since 2.1.2
	 *
	 * @param \Woodev_Admin_Notice_Handler $notices      Framework admin notice handler.
	 * @param Account_Store                $store        Account store.
	 * @param Marketplace_Adapter          $adapter      Marketplace adapter.
	 * @param string                       $settings_url URL of the plugin's settings page.
	 * @param string                       $id_prefix    Plugin slug prefix for notice ids; defaults to the adapter id.
	 * @param callable|null                $clock        `function (): int`, defaults to `time`.
	 */
	public function __construct(
		\Woodev_Admin_Notice_Handler $notices,
		Account_Store $store,
		Marketplace_Adapter $adapter,
		string $settings_url,
		string $id_prefix = '',
		?callable $clock = null
	) {
		$this->notices      = $notices;
		$this->store        = $store;
		$this->adapter      = $adapter;
		$this->settings_url = $settings_url;
		$this->id_prefix    = '' !== $id_prefix ? $id_prefix : $adapter->id();
		$this->clock        = $clock ?? 'time';
	}

	/**
	 * Builds the registrar for a `Marketplace_Plugin` instance.
	 *
	 * @since 2.1.2
	 *
	 * @param \Woodev_Plugin      $plugin  Plugin instance.
	 * @param Account_Store       $store   Account store.
	 * @param Marketplace_Adapter $adapter Marketplace adapter.
	 * @param callable|null       $clock   Optional clock (`function (): int`).
	 * @return self
	 */
	public static function for_plugin( \Woodev_Plugin $plugin, Account_Store $store, Marketplace_Adapter $adapter, ?callable $clock = null ): self {
		return new self(
			$plugin->get_admin_notice_handler(),
			$store,
			$adapter,
			(string) $plugin->get_settings_url(),
			$plugin->get_id_dasherized(),
			$clock
		);
	}

	/**
	 * Registers notices for every enabled account of this adapter that has reached a key-expiry tier.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function add(): void {
		$now_ts = (int) call_user_func( $this->clock );
		$now    = ( new \DateTimeImmutable( '@' . $now_ts ) )->setTimezone( new \DateTimeZone( 'UTC' ) );

		foreach ( $this->store->all( $this->adapter->id(), true ) as $account ) {
			if ( $account->adapter_id() !== $this->adapter->id() ) {
				continue;
			}

			$expires_at = $account->expires_at();
			$tier       = Key_Expiry::tier( $expires_at, $now );

			if ( null === $tier || null === $expires_at ) {
				continue;
			}

			$expired   = Key_Expiry::TIER_EXPIRED === $tier;
			$tier_slug = $expired ? 'expired' : (string) $tier;

			$this->notices->add_admin_notice(
				$this->message( $account, $tier, $expires_at, $now ),
				$this->notice_id( $account, $tier_slug ),
				[
					'notice_class' => $expired ? 'notice-error' : 'notice-warning',
					'dismissible'  => ! $expired,
				]
			);
		}
	}

	/**
	 * Builds the dismissal-scoped notice id for an account, secret fingerprint and tier.
	 *
	 * @param Account $account   Account.
	 * @param string  $tier_slug `30`, `7`, `1` or `expired`.
	 * @return string
	 */
	private function notice_id( Account $account, string $tier_slug ): string {
		$fingerprint = (string) $account->setting( 'secret_fingerprint', '' );

		$key = substr( $fingerprint, 0, 8 );
		$key = '' === $key ? 'current' : $key;

		return sprintf( '%s-key-expiry-%d-%s-%s', $this->id_prefix, $account->id(), $key, $tier_slug );
	}

	/**
	 * Formats the localized HTML body of the notice.
	 *
	 * @param Account            $account    Account.
	 * @param int                $tier       One of `Key_Expiry::TIERS` or `Key_Expiry::TIER_EXPIRED`.
	 * @param \DateTimeImmutable $expires_at Expiry timestamp.
	 * @param \DateTimeImmutable $now        Current timestamp.
	 * @return string
	 */
	private function message( Account $account, int $tier, \DateTimeImmutable $expires_at, \DateTimeImmutable $now ): string {
		$descriptor = $this->adapter->settings_descriptor();
		$identifier = $this->adapter->credential_fields()->identifier();
		$label      = '' !== trim( $account->name() )
			? trim( $account->name() )
			: trim( (string) ( $account->credentials()[ $identifier ] ?? '' ) );

		if ( '' === $label ) {
			$label = (string) $account->id();
		}

		$date = wp_date( 'd.m.Y', $expires_at->getTimestamp() );
		$days = Key_Expiry::days_left( $expires_at, $now );

		$lead = sprintf(
			'<strong>%s</strong> ',
			sprintf(
				/* translators: 1: marketplace name, 2: account name or identifier. */
				__( '%1$s, аккаунт «%2$s»:', 'woodev-plugin-framework' ),
				esc_html( $descriptor->marketplace_name() ),
				esc_html( $label )
			)
		);

		if ( Key_Expiry::TIER_EXPIRED === $tier ) {
			/* translators: %s: expiry date in d.m.Y format. */
			$when = sprintf( __( 'Срок действия ключа истёк %s.', 'woodev-plugin-framework' ), esc_html( (string) $date ) );
		} elseif ( 0 === $days ) {
			/* translators: %s: expiry date in d.m.Y format. */
			$when = sprintf( __( 'Ключ истекает сегодня (%s).', 'woodev-plugin-framework' ), esc_html( (string) $date ) );
		} else {
			/* translators: 1: expiry date in d.m.Y format, 2: whole days remaining. */
			$when = sprintf( __( 'Ключ истекает %1$s (осталось дней: %2$d).', 'woodev-plugin-framework' ), esc_html( (string) $date ), (int) $days );
		}

		$advice = trim( $descriptor->key_renewal_advice() );
		$body   = '' !== $advice ? $lead . $when . ' ' . esc_html( $advice ) : $lead . $when;

		if ( '' === $this->settings_url ) {
			return $body;
		}

		return sprintf(
			'%s <a href="%s">%s</a>',
			$body,
			esc_url( $this->settings_url ),
			esc_html__( 'Открыть настройки', 'woodev-plugin-framework' )
		);
	}
}

<?php
/**
 * Credential check with Wildberries token rules.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Account\Health_Result;

/**
 * One JWT token, 180-day expiry and a `GET /ping` per host: the token is the only credential and its expiry is
 * first-class, which is why `Health_Result` and `Account` carry `expires_at`.
 */
final class Fake_Credential_Health implements Credential_Health {
	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Creates the checker.
	 *
	 * @param Fake_Clock $clock Clock.
	 */
	public function __construct( Fake_Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Checks the token.
	 *
	 * @param Account $account Account to check.
	 * @return Health_Result
	 */
	public function check( Account $account ): Health_Result {
		$credentials = $account->credentials();
		if ( empty( $credentials['token'] ) ) {
			return new Health_Result( Health_Result::AUTH_FAILED, 'Токен не указан.' );
		}

		$expires_at = $account->expires_at();
		if ( null !== $expires_at && $expires_at <= $this->clock->now() ) {
			return new Health_Result( Health_Result::KEY_EXPIRED, 'Токен истёк — создайте новый.', $expires_at );
		}

		$hosts = array(
			'content-api'          => 'pong',
			'discounts-prices-api' => 'pong',
			'marketplace-api'      => 'pong',
		);

		return new Health_Result( Health_Result::OK, '', $expires_at, array( 'hosts' => $hosts ) );
	}
}

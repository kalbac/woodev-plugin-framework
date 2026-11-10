<?php
/**
 * The account claim a run holds, as the SQL of a store write needs it.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The accounts table and the claim token of the run that writes. A store that receives one adds
 * `AND EXISTS (SELECT 1 FROM <accounts> WHERE claim_token = <token> AND claim_until > now)` to the statement itself, so a run whose
 * claim was taken over changes nothing (claim fencing, the account row is the lease).
 *
 * It is a plain value on purpose: the run model that takes the claim arrives later and builds one from the token it holds.
 *
 * @since 2.0.29
 */
final class Claim_Fence {
	/**
	 * Full name of the accounts table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Claim token of the account row.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Creates the fence.
	 *
	 * @since 2.0.29
	 *
	 * @param string $table Full name of the accounts table.
	 * @param string $token Claim token the run holds.
	 */
	public function __construct( string $table, string $token ) {
		$this->table = $table;
		$this->token = $token;
	}

	/**
	 * Full name of the accounts table.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Claim token.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}
}

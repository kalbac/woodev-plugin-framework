<?php
/**
 * One reason a product does not sync.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Diagnostics;

use Woodev\Framework\Marketplace\Price\Price_Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * A reason as data: a stable code and the parameters its sentence needs. The layer never writes the merchant's sentence, because it
 * may not call a translation function; a shared mapper in the admin layer turns a code into a translated sentence in the framework's
 * text domain, so every screen that explains a sync (the diagnostics page, the product tab) says it the same way.
 *
 * @since 2.0.29
 */
final class Sync_Reason {
	public const LICENSE_INVALID  = 'license_invalid';
	public const ACCOUNT_DISABLED = 'account_disabled';
	public const KEY_ROLE_MISSING = 'key_role_missing';
	public const KEY_INVALID      = 'key_invalid';
	public const NOT_LINKED       = 'not_linked';
	public const CARD_PENDING     = 'card_pending';
	public const STOCK_SYNC_OFF   = 'stock_sync_off';
	public const NO_WAREHOUSE     = 'no_warehouse';
	public const RATE_LIMITED     = 'rate_limited';
	public const LAST_ERROR       = 'last_error';

	/**
	 * The chosen warehouse is switched off by the marketplace.
	 *
	 * @since 2.1.25
	 */
	public const WAREHOUSE_UNAVAILABLE = 'warehouse_unavailable';

	/**
	 * The marketplace refused the new price (2.1.25).
	 */
	public const PRICE_REFUSED = Price_Refusal::REFUSED;

	/**
	 * The marketplace holds the new price in quarantine (2.1.25).
	 */
	public const PRICE_QUARANTINED = Price_Refusal::QUARANTINED;

	/**
	 * The marketplace gave no answer about the new price in time (2.1.25).
	 */
	public const PRICE_EXPIRED = Price_Refusal::EXPIRED;

	/**
	 * The links of one price group want different prices and the marketplace keeps one price for the group, so none is sent
	 * (2.1.26). The price queue stores this code beside each of them ({@see \Woodev\Framework\Marketplace\Price\Price_Sync_Queue}).
	 */
	public const PRICE_GROUP_DIFFERS = 'price_group_differs';

	/**
	 * Reason code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Parameters of the sentence.
	 *
	 * @var array<string,scalar>
	 */
	private $params;

	/**
	 * Creates the reason.
	 *
	 * @since 2.0.29
	 *
	 * @param string               $code   One of the class constants.
	 * @param array<string,scalar> $params Parameters of the sentence: `state` for a pending card, `seconds` for a rate limit, `message`
	 *                                     for the last marketplace error and for a price that did not take effect.
	 */
	public function __construct( string $code, array $params = [] ) {
		$this->code   = $code;
		$this->params = $params;
	}

	/**
	 * Returns the reason code.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Returns the parameters of the sentence.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,scalar>
	 */
	public function params(): array {
		return $this->params;
	}
}

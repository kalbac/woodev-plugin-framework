<?php
/**
 * The remote half of an export on a marketplace that gives no task number.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a {@see Card_Lifecycle} implements it when the marketplace accepts an import and answers with no task number, so the
 * result can only be found by the seller's articles.
 *
 * What it changes:
 *
 * - an empty answer of {@see Export_Gateway::submit()} means «accepted»: the task is polled through
 *   {@see self::read_import_by_articles()} (without this interface an empty answer is a failed request and is retried);
 * - before a card an earlier request already carried is sent again, and no identifier of it is known, the job asks
 *   {@see self::find_cards()}: a card that is there is not sent twice, and goes on to {@see Card_Lifecycle::read_cards()}.
 *
 * A separate interface, not a new argument of {@see Card_Lifecycle::read_import()}: released plugins implement that one, and the
 * framework copy they run against may be newer than they are.
 *
 * @since 2.1.21
 */
interface Article_Keyed_Lifecycle extends Card_Lifecycle {
	/**
	 * Reads the result of an accepted import by the articles it carried.
	 *
	 * @since 2.1.21
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles of the task whose result is not known yet.
	 * @param string   $context   Name of the calling job.
	 * @return array<string,Import_Outcome> The items the marketplace reports (its cards and its refusals), by article. An item it
	 *                                      does not report yet is absent.
	 * @throws \Throwable When the request fails.
	 */
	public function read_import_by_articles( Account $account, array $offer_ids, string $context ): array;

	/**
	 * Looks the articles up in the marketplace's card list. A refusal kept in an error list is no card: such an article is absent,
	 * so that a corrected product can be sent again.
	 *
	 * @since 2.1.21
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles about to be sent again.
	 * @param string   $context   Name of the calling job.
	 * @return array<string,array<string,int|string>> Article => identifiers of the card that exists (name => id), with at least the
	 *                                                {@see Card_Lifecycle::card_id_name()} one. Absent when there is no card.
	 * @throws \Throwable When the request fails; nothing is sent then.
	 */
	public function find_cards( Account $account, array $offer_ids, string $context ): array;
}

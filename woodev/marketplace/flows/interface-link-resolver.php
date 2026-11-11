<?php
/**
 * The marketplace's side of linking existing cards to shop products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the cabinet of an account: which cards exist, with which identifiers and in which state. The framework matches those cards with
 * shop products by article ({@see Card_Matcher}) and writes the links ({@see Card_Linker}); everything that touches the marketplace is
 * here, so the jobs never name a request, a path or a field.
 *
 * Every read is resumable. `$state` is the cursor of the read: an implementation keeps its position (page cursor, ids already
 * answered) in it, and when the run is interrupted — a governor wait, a lost claim — the job stores it and hands it back to the next
 * run, so the pages already read are not paid for twice. `$renew` extends the account claim between requests and throws when another
 * run took it.
 *
 * A **card** is an array: `offer_id` (string, unique in the cabinet), `remote_ids` (name => identifier, the names are those of
 * {@see Card_Lifecycle::card_id_name()} and {@see Card_Lifecycle::late_id_names()}; an empty list means "not a card"), and, when the
 * marketplace says so, `state` (a `Link_Record` card state), `is_created` (bool), `archived` (bool), `failed` (bool) and `status_text`
 * (the marketplace's reason for a declined card).
 *
 * Any implementation may throw what its client throws (the adapter's {@see Error_Classifier} sorts it), or
 * {@see Link_Resolve_Exception} when the answer cannot be trusted.
 *
 * @since 2.0.30
 */
interface Link_Resolver {
	/**
	 * Names the request path the card list is read from. The error ring stores it next to a failure of a linking run.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function list_path(): string;

	/**
	 * Reads the whole cabinet: one summary card per article.
	 *
	 * @since 2.0.30
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job hook, for the client's context.
	 * @param callable            $renew   `function (): void`, extends the account claim or throws.
	 * @param array<string,mixed> $state   Cursor of the read; updated as it advances.
	 * @return array<int,array<string,mixed>> Cards.
	 * @throws Link_Resolve_Exception When the marketplace's answer cannot be trusted.
	 */
	public function catalogue( Account $account, string $context, callable $renew, array &$state ): array;

	/**
	 * Reads the details of cards (identifiers that the list does not carry, the state, the declined text).
	 *
	 * @since 2.0.30
	 *
	 * @param Account                        $account Account.
	 * @param string                         $context Job hook.
	 * @param array<int,array<string,mixed>> $cards   Summary cards of {@see self::catalogue()}.
	 * @param callable                       $renew   `function (): void`, extends the account claim or throws.
	 * @param array<string,mixed>            $state   Cursor of the read; updated as it advances.
	 * @return array<string,array<string,mixed>> Details by `offer_id`; a card the marketplace did not answer for is absent.
	 * @throws Link_Resolve_Exception When the marketplace answers with cards nobody asked about.
	 */
	public function details( Account $account, string $context, array $cards, callable $renew, array &$state ): array;

	/**
	 * Merges a summary card with its detail into the card that may be linked.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,mixed>      $card   Summary card.
	 * @param array<string,mixed>|null $detail Its detail, null when the marketplace did not answer for it.
	 * @return array<string,mixed>|null Null when there is no detail, or the detail names another article: such a pair must not be linked.
	 */
	public function confirmed( array $card, ?array $detail ): ?array;

	/**
	 * Reads the cabinet with names and states, for the tab that lists the marketplace's cards.
	 *
	 * @since 2.0.30
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job hook.
	 * @param callable            $renew   `function (): void`, extends the account claim or throws.
	 * @param array<string,mixed> $state   Cursor of the read; updated as it advances.
	 * @return array<int,array<string,mixed>> Cards for {@see \Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store::put()}.
	 * @throws Link_Resolve_Exception When the marketplace's answer cannot be trusted.
	 */
	public function snapshot( Account $account, string $context, callable $renew, array &$state ): array;
}

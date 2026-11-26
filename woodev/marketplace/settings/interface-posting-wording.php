<?php
/**
 * The marketplace's own word for a posting.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An optional part of a {@see Settings_Descriptor}: a marketplace that does not call a posting «отправление» (Wildberries says
 * «сборочное задание») gives its word here, and the order screens, the «Аналитика» tab and the account deletion use it. Without it
 * they say «отправление».
 *
 * @since 2.1.23
 */
interface Posting_Wording {
	/**
	 * Returns the noun.
	 *
	 * @since 2.1.23
	 *
	 * @return Posting_Noun
	 */
	public function posting_noun(): Posting_Noun;
}

<?php
/**
 * What a marketplace screen writes about an answer that is not the plugin's.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * A proxy or a bot filter in front of the shop may replace an answer with a body of its own. Every refusal of the plugin
 * carries a message, so an answer without one was replaced, and its HTTP status is all there is to show. The admin scripts
 * get the sentence from here; the React screens write the same one (`src/components/foreign-answer.ts`).
 *
 * @since 2.1.29
 */
final class Foreign_Answer_Text {
	/**
	 * The sentence with `%d` where the script puts the HTTP status.
	 *
	 * @since 2.1.29
	 *
	 * @return string
	 */
	public static function template(): string {
		/* translators: %d: the HTTP status code of the answer. */
		return __( 'Ответ пришёл не от плагина (HTTP %d) — возможно, его подменила защита или другой сервис перед сайтом.', 'woodev-plugin-framework' );
	}
}

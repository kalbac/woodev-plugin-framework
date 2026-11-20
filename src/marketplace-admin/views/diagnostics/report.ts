/**
 * Small decisions of the diagnostics page, kept apart from the markup so they can be tested without a DOM.
 *
 * @package woodev-plugin-framework
 */

/**
 * The sentence after the copy button was pressed.
 *
 * @param copied   Whether the clipboard accepted the text.
 * @param messages The server's copy.
 */
export function copyOutcome( copied: boolean, messages: Record< string, string > ): string {
	return copied ? messages.copied : messages.copyFailed;
}

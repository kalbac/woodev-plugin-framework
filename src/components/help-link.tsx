/**
 * The «Подробнее…» link PHP describes (`Help_Links::resolve()`): the address and the wording arrive finished, so
 * this component holds no copy (AGENT-RULES Rule 9). Drawn under a field, a section, a group, a page title or a note.
 *
 * It opens in a new tab and says so to a screen reader; it is a plain link, never wrapped around a button.
 *
 * @package woodev-plugin-framework
 */

/** What `Help_Links::resolve()` serialises. */
export interface HelpLinkData {
	url: string;
	text: string;
	/** The accessible name — the text and a note that the link opens in a new tab. */
	label?: string;
}

interface HelpLinkProps {
	/** The resolved link; absent when the topic was not declared or the plugin's table does not know it. */
	help?: HelpLinkData | null;
	className?: string;
}

/**
 * Whether the data is a link worth drawing: an http(s) address and some text. React would otherwise render a
 * `javascript:` address a broken table might carry.
 *
 * @param help The link data, as it arrived.
 */
export function isDrawableHelp( help: HelpLinkData | null | undefined ): help is HelpLinkData {
	return !! help && 'string' === typeof help.url && 'string' === typeof help.text && '' !== help.text && /^https?:\/\//i.test( help.url );
}

export default function HelpLink( { help, className }: HelpLinkProps ) {
	if ( ! isDrawableHelp( help ) ) {
		return null;
	}

	return (
		<a
			className={ `woodev-help-link${ className ? ` ${ className }` : '' }` }
			href={ help.url }
			target="_blank"
			rel="noopener noreferrer"
			aria-label={ help.label || help.text }
		>
			{ help.text }…
		</a>
	);
}

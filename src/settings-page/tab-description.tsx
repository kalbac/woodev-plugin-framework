/**
 * The sentence or two under a settings tab's name that say what the tab is for.
 *
 * The text is HTML that the owning plugin escaped on the server (`Settings_Tab` description), so it may carry a link; a tab
 * without one draws nothing.
 *
 * @package woodev-plugin-framework
 */

import { RawHTML } from '@wordpress/element';

interface TabDescriptionProps {
	tab: { description?: string };
}

/**
 * @param {TabDescriptionProps} props the tab.
 */
export default function TabDescription( { tab }: TabDescriptionProps ) {
	if ( ! tab.description ) {
		return null;
	}

	return (
		<div className="woodev-settings__section-desc woodev-settings__tab-desc">
			<RawHTML>{ tab.description }</RawHTML>
		</div>
	);
}

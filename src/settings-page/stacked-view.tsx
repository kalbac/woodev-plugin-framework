/**
 * A tab whose sections are drawn one after another, each card opening with its own heading, instead of as sub-tabs.
 *
 * Sections flagged `stacked` (`Settings_Section::stacked()`) belong to tabs that list one block per account: «Параметры»
 * (an account's settings under its name) and «Уведомления» (the switches, then a row per account). The sections keep their
 * own card, fields and Save button, so saving works as it does on any other tab.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import type { AccountsTab } from './accounts-view';
import TabDescription from './tab-description';

/**
 * Whether a tab draws its sections stacked.
 *
 * @param {AccountsTab} tab the tab schema.
 * @return {boolean} true when some section is flagged `stacked`.
 */
export function isStackedTab( tab: AccountsTab ): boolean {
	return ( tab.sections || [] ).some( ( section ) => !! section.stacked );
}

interface StackedViewProps {
	/** The stacked tab. */
	tab: AccountsTab;
	/** The card of one section; the heading is drawn INSIDE it, as the card's first child. */
	renderSection: ( tab: AccountsTab, sectionId: string, heading?: StackedHeading ) => ReactNode;
}

/** What opens a stacked section's card: the section label and its muted line. */
export interface StackedHeading {
	title: string;
	subtitle?: string;
}

/**
 * The tab's sections one after another, each card opening with its own heading.
 *
 * @param {StackedViewProps} props the view props.
 */
export default function StackedView( { tab, renderSection }: StackedViewProps ) {
	return (
		<div className="woodev-stacked-tab">
			<TabDescription tab={ tab } />
			<div className="woodev-stacked">
				{ ( tab.sections || [] ).map( ( section ) => (
					<section key={ section.id } className="woodev-stacked__section">
						{ renderSection( tab, section.id, { title: section.label ?? '', subtitle: section.subtitle } ) }
					</section>
				) ) }
			</div>
		</div>
	);
}

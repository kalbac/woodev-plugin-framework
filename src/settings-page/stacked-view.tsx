/**
 * A tab whose sections are drawn one after another, each under its own heading, instead of as sub-tabs.
 *
 * Sections flagged `stacked` (`Settings_Section::stacked()`) belong to tabs that list one block per account: «Параметры»
 * (an account's settings under its name) and «Уведомления» (the switches, then a row per account). The sections keep their
 * own card, fields and Save button, so saving works as it does on any other tab.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import type { AccountsTab } from './accounts-view';

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
	/** The card of one section. */
	renderSection: ( tab: AccountsTab, sectionId: string ) => ReactNode;
}

/**
 * The tab's sections one after another, each under its own heading.
 *
 * @param {StackedViewProps} props the view props.
 */
export default function StackedView( { tab, renderSection }: StackedViewProps ) {
	return (
		<div className="woodev-stacked">
			{ ( tab.sections || [] ).map( ( section ) => (
				<section key={ section.id } className="woodev-stacked__section">
					<h3 className="woodev-stacked__title">
						{ section.label }
						{ section.subtitle && <span className="woodev-stacked__subtitle">{ section.subtitle }</span> }
					</h3>
					{ renderSection( tab, section.id ) }
				</section>
			) ) }
		</div>
	);
}

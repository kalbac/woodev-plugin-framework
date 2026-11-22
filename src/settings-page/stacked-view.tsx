/**
 * A tab whose sections are drawn one after another, each card opening with its own heading, instead of as sub-tabs.
 *
 * Sections flagged `stacked` (`Settings_Section::stacked()`) belong to tabs that list one block per account: «Параметры»
 * (an account's settings) and «Уведомления» (the switches, then a block per account). The sections keep their own card,
 * fields and Save button, so saving works as it does on any other tab.
 *
 * The per-account blocks (`Settings_Section::account_tab()`) are not stacked: with several accounts they become tabs named
 * after the account and only the open one is drawn; with one account the block is drawn directly, with no tabs. The tabs are
 * the settings page's own sub-tab bar (`SubTabsNav`). The shared sections around them (the notification switches) keep their
 * heading and their place.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { useState } from '@wordpress/element';
import SubTabsNav from '../components/sub-tabs-nav';
import type { AccountSection, AccountsTab } from './accounts-view';
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
	/** The card of one section; a shared section's heading is drawn INSIDE it, as the card's first child. */
	renderSection: ( tab: AccountsTab, sectionId: string, heading?: StackedHeading ) => ReactNode;
}

/** What opens a stacked section's card: the section label and its muted line. */
export interface StackedHeading {
	title: string;
	subtitle?: string;
}

/**
 * The tab's sections one after another, the accounts' blocks behind tabs.
 *
 * @param {StackedViewProps} props the view props.
 */
export default function StackedView( { tab, renderSection }: StackedViewProps ) {
	const sections = tab.sections || [];
	const accounts = sections.filter( ( section ) => !! section.account_tab );
	const [ openId, setOpenId ] = useState( '' );
	// The open account may be gone after a reload (it was deleted): the first one opens then.
	const open: AccountSection | undefined = accounts.find( ( section ) => section.id === openId ) || accounts[ 0 ];
	const accountsInTabs = accounts.length > 1;
	const accountsBlock = open && (
		<section key="accounts" className="woodev-stacked__section">
			{ accountsInTabs && (
				<SubTabsNav tabs={ accounts } current={ open.id } onSelect={ setOpenId } />
			) }
			{ renderSection( tab, open.id ) }
		</section>
	);

	// The accounts' block takes the place of the first account section; shared sections stay where PHP put them.
	const firstAccountIndex = sections.findIndex( ( section ) => section.account_tab );
	const blocks = sections.map( ( section, index ) => {
		if ( section.account_tab ) {
			return index === firstAccountIndex ? accountsBlock : null;
		}

		return (
			<section key={ section.id } className="woodev-stacked__section">
				{ renderSection( tab, section.id, { title: section.label ?? '', subtitle: section.subtitle } ) }
			</section>
		);
	} );

	return (
		<div className="woodev-stacked-tab">
			<TabDescription tab={ tab } />
			<div className="woodev-stacked">{ blocks }</div>
		</div>
	);
}

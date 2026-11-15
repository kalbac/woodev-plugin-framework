/**
 * Woodev UI-kit — folder tabs, controlled.
 *
 * Draws the markup of WordPress's own `TabPanel` (`div.woodev-tabs > div.components-tab-panel__tabs[role=tablist] >
 * button.components-tab-panel__tabs-item[role=tab]`), so the folder look of `.woodev-tabs` in `_wp-recolor.scss` applies
 * unchanged and every page that uses it looks like the settings.
 *
 * Controlled on purpose: a page that keeps its active tab in the URL must follow Back and Forward, which an
 * uncontrolled `TabPanel` (it owns its selection) would not. The tab bar only reports a choice; the page decides.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import type { KeyboardEvent } from 'react';

export interface FolderTab {
	id: string;
	label: string;
}

interface Props {
	tabs: FolderTab[];
	/** The id of the tab that is open. */
	active: string;
	/** Called with the id of another tab; never with the open one. */
	onSelect: ( id: string ) => void;
	/** The accessible name of the tab list. */
	label?: string;
}

export function FolderTabs( { tabs, active, onSelect, label }: Props ) {
	const choose = ( id: string ) => {
		if ( id !== active ) {
			onSelect( id );
		}
	};

	// Arrow keys move between tabs and select, as the ARIA tab pattern (and TabPanel's `selectOnMove`) does.
	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];
		const index = tabs.findIndex( ( tab ) => tab.id === active );

		if ( step === undefined || index === -1 ) {
			return;
		}

		event.preventDefault();
		const next = tabs[ ( index + step + tabs.length ) % tabs.length ];
		choose( next.id );
		const buttons = event.currentTarget.querySelectorAll< HTMLElement >( '[role="tab"]' );
		buttons[ tabs.indexOf( next ) ]?.focus();
	};

	return (
		<div className="woodev-tabs">
			{ /* eslint-disable-next-line jsx-a11y/interactive-supports-focus -- the focus sits on the tabs inside */ }
			<div className="components-tab-panel__tabs" role="tablist" aria-label={ label } onKeyDown={ onKeyDown }>
				{ tabs.map( ( tab ) => {
					const isActive = tab.id === active;

					return (
						<Button
							key={ tab.id }
							__next40pxDefaultSize
							role="tab"
							aria-selected={ isActive }
							tabIndex={ isActive ? 0 : -1 }
							className={ `components-tab-panel__tabs-item${ isActive ? ' is-active' : '' }` }
							data-tab={ tab.id }
							onClick={ () => choose( tab.id ) }
						>
							{ tab.label }
						</Button>
					);
				} ) }
			</div>
		</div>
	);
}

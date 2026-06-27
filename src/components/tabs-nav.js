/**
 * Woodev UI-kit — two-level settings navigation.
 *
 * Level 1: folder-style provider tabs (native TabPanel, recolored to a folder
 * look in `_wp-recolor.scss`). Level 2: horizontal sub-tab links for the active
 * provider's sections (first section is the default). Both levels deep-link via
 * query params (`tab`, `section`) so a direct URL opens the right provider +
 * section, and switching updates the URL (history.replaceState).
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState, useEffect } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';

/**
 * Reads the current tab/section from the page URL.
 *
 * @return {{tab: string, section: string}} url selection.
 */
function readUrl() {
	const p = new URLSearchParams( window.location.search );
	return { tab: p.get( 'tab' ) || '', section: p.get( 'section' ) || '' };
}

/**
 * Writes the tab/section to the URL without reloading.
 *
 * @param {string} tab     provider id.
 * @param {string} section section id.
 */
function writeUrl( tab, section ) {
	const p = new URLSearchParams( window.location.search );
	p.set( 'tab', tab );
	p.set( 'section', section );
	window.history.replaceState( {}, '', `${ window.location.pathname }?${ p.toString() }` );
}

/**
 * @param {Object}   props               component props.
 * @param {Array}    props.tabs          [{ id, label, sections:[{id,label}] }].
 * @param {Function} props.renderSection (tab, sectionId) => node.
 * @param {Function} [props.onTabChange] called when the provider tab changes.
 * @return {JSX.Element} the navigation.
 */
export default function TabsNav( { tabs, renderSection, onTabChange } ) {
	const initial = readUrl();
	const initialTab = tabs.find( ( t ) => t.id === initial.tab ) ? initial.tab : tabs[ 0 ].id;

	// Per-tab active section (lazily defaulted to each tab's first section, or the
	// deep-linked section when it belongs to the deep-linked tab).
	const [ activeSection, setActiveSection ] = useState( {} );

	const sectionFor = ( tab ) => {
		if ( activeSection[ tab.id ] ) {
			return activeSection[ tab.id ];
		}
		if (
			tab.id === initial.tab &&
			tab.sections.find( ( s ) => s.id === initial.section )
		) {
			return initial.section;
		}
		return tab.sections[ 0 ] ? tab.sections[ 0 ].id : '';
	};

	// On mount, make the URL explicit so it is shareable even on first load.
	useEffect( () => {
		const tab = tabs.find( ( t ) => t.id === initialTab );
		writeUrl( initialTab, sectionFor( tab ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<TabPanel
			className="woodev-tabs"
			initialTabName={ initialTab }
			tabs={ tabs.map( ( t ) => ( { name: t.id, title: t.label } ) ) }
			onSelect={ ( name ) => {
				const tab = tabs.find( ( t ) => t.id === name );
				if ( tab ) {
					writeUrl( name, sectionFor( tab ) );
				}
				if ( onTabChange ) {
					onTabChange();
				}
			} }
		>
			{ ( tabOption ) => {
				const tab = tabs.find( ( t ) => t.id === tabOption.name );
				if ( ! tab ) {
					return null;
				}
				const current = sectionFor( tab );

				return (
					<>
						{ tab.sections.length > 1 && (
							<nav className="woodev-subtabs">
								{ tab.sections.map( ( s ) => (
									<button
										key={ s.id }
										type="button"
										className={
											'woodev-subtabs__link' +
											( s.id === current ? ' is-active' : '' )
										}
										onClick={ () => {
											setActiveSection( ( prev ) => ( {
												...prev,
												[ tab.id ]: s.id,
											} ) );
											writeUrl( tab.id, s.id );
										} }
									>
										{ s.label }
									</button>
								) ) }
							</nav>
						) }
						{ renderSection( tab, current ) }
					</>
				);
			} }
		</TabPanel>
	);
}

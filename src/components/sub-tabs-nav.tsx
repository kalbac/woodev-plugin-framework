/**
 * Woodev UI-kit — the sub-tab link bar (level 2, under the folder provider tabs).
 *
 * One bar for every place that switches between blocks of a page: the sections of a provider (`TabsNav`) and the accounts of
 * a marketplace tab (`StackedView`). Look: `_tabs.scss` (`.woodev-subtabs`).
 *
 * @package woodev-plugin-framework
 */

/** One link of the bar. */
export interface SubTab {
	id: string;
	label?: string;
}

interface SubTabsNavProps {
	tabs: SubTab[];
	/** The id of the open tab. */
	current: string;
	onSelect: ( id: string ) => void;
}

/**
 * @param {SubTabsNavProps} props the bar props.
 */
export default function SubTabsNav( { tabs, current, onSelect }: SubTabsNavProps ) {
	return (
		<nav className="woodev-subtabs">
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.id }
					type="button"
					className={ 'woodev-subtabs__link' + ( tab.id === current ? ' is-active' : '' ) }
					onClick={ () => onSelect( tab.id ) }
				>
					{ tab.label }
				</button>
			) ) }
		</nav>
	);
}

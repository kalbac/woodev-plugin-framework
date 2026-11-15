/**
 * The folder tabs: TabPanel's markup, driven by the page.
 *
 * @see src/components/folder-tabs.tsx
 */

import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import { FolderTabs } from '../../src/components/folder-tabs';

const TABS = [
	{ id: 'one', label: 'Первая' },
	{ id: 'two', label: 'Вторая' },
	{ id: 'three', label: 'Третья' },
];

describe( 'FolderTabs', () => {
	test( 'is a tab list with the open tab selected, in the markup the folder look is written for', () => {
		const { container } = render( <FolderTabs tabs={ TABS } active="two" onSelect={ jest.fn() } label="Раздел" /> );

		expect( screen.getByRole( 'tablist', { name: 'Раздел' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'tab', { name: 'Вторая' } ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByRole( 'tab', { name: 'Первая' } ) ).toHaveAttribute( 'aria-selected', 'false' );

		// `.woodev-tabs .components-tab-panel__tabs-item.is-active` is what `_wp-recolor.scss` draws as the folder.
		expect( container.querySelector( '.woodev-tabs > .components-tab-panel__tabs[role="tablist"]' ) ).not.toBeNull();
		expect( screen.getByRole( 'tab', { name: 'Вторая' } ) ).toHaveClass( 'components-tab-panel__tabs-item', 'is-active' );
		expect( screen.getByRole( 'tab', { name: 'Первая' } ) ).not.toHaveClass( 'is-active' );
	} );

	test( 'a click on another tab reports its id; a click on the open one reports nothing', () => {
		const onSelect = jest.fn();
		render( <FolderTabs tabs={ TABS } active="two" onSelect={ onSelect } /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Вторая' } ) );
		expect( onSelect ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'tab', { name: 'Третья' } ) );
		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( 'three' );
	} );

	test( 'it is controlled: it shows what the page says, not what was clicked', () => {
		const { rerender } = render( <FolderTabs tabs={ TABS } active="one" onSelect={ jest.fn() } /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Вторая' } ) );
		expect( screen.getByRole( 'tab', { name: 'Первая' } ) ).toHaveAttribute( 'aria-selected', 'true' );

		rerender( <FolderTabs tabs={ TABS } active="three" onSelect={ jest.fn() } /> );
		expect( screen.getByRole( 'tab', { name: 'Третья' } ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByRole( 'tab', { name: 'Первая' } ) ).toHaveAttribute( 'aria-selected', 'false' );
	} );

	test( 'only the open tab is in the tab order, and the arrow keys move to the next tab and wrap', () => {
		const onSelect = jest.fn();
		render( <FolderTabs tabs={ TABS } active="three" onSelect={ onSelect } /> );

		expect( screen.getByRole( 'tab', { name: 'Третья' } ) ).toHaveAttribute( 'tabindex', '0' );
		expect( screen.getByRole( 'tab', { name: 'Первая' } ) ).toHaveAttribute( 'tabindex', '-1' );

		fireEvent.keyDown( screen.getByRole( 'tab', { name: 'Третья' } ), { key: 'ArrowRight' } );
		expect( onSelect ).toHaveBeenLastCalledWith( 'one' );

		fireEvent.keyDown( screen.getByRole( 'tab', { name: 'Третья' } ), { key: 'ArrowLeft' } );
		expect( onSelect ).toHaveBeenLastCalledWith( 'two' );
	} );
} );

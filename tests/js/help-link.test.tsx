/**
 * The «Подробнее…» link PHP resolves: drawn under a field, a section, a group, a page and a note, and nowhere else.
 *
 * @see src/components/help-link.tsx
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import ControlField from '../../src/components/control-field';
import GroupCard from '../../src/settings-page/group-card';
import SectionView from '../../src/settings-page/section-view';
import HelpLink, { isDrawableHelp } from '../../src/components/help-link';
import { Notices } from '../../src/marketplace-admin/views/mappings/notices';
import { PageBody } from '../../src/marketplace-admin/page-view';
import type { ManifestMenu, ManifestPage } from '../../src/marketplace-admin/types';

const HELP = {
	url: 'https://woodev.ru/docs/ozon/#sync',
	text: 'Подробнее как настроить',
	label: 'Подробнее как настроить (откроется в новой вкладке)',
};

describe( 'HelpLink', () => {
	test( 'is a plain link that opens in a new tab and carries the accessible name PHP gave it', () => {
		render( <HelpLink help={ HELP } /> );

		const link = screen.getByRole( 'link', { name: HELP.label } );
		expect( link ).toHaveAttribute( 'href', HELP.url );
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect( link ).toHaveTextContent( 'Подробнее как настроить…' );
		expect( link.querySelector( 'button' ) ).toBeNull();
	} );

	test( 'falls back to the visible text as the accessible name', () => {
		render( <HelpLink help={ { url: HELP.url, text: HELP.text } } /> );

		expect( screen.getByRole( 'link', { name: HELP.text } ) ).toBeInTheDocument();
	} );

	test.each( [
		[ 'nothing', undefined ],
		[ 'null', null ],
		[ 'no text', { url: HELP.url, text: '' } ],
		[ 'a script address', { url: 'javascript:alert(1)', text: 'x' } ],
		[ 'a relative address', { url: '/docs', text: 'x' } ],
	] )( 'draws nothing for %s', ( _name, help ) => {
		const { container } = render( <HelpLink help={ help as never } /> );

		expect( container ).toBeEmptyDOMElement();
		expect( isDrawableHelp( help as never ) ).toBe( false );
	} );
} );

describe( 'where the link is drawn', () => {
	test( 'under a field with a text control, and under a toggle', () => {
		const { rerender } = render(
			<ControlField settingId="a" schema={ { type: 'string', name: 'Ключ', controlType: 'text', help: HELP } } value="" onChange={ jest.fn() } />
		);
		expect( screen.getByRole( 'link', { name: HELP.label } ) ).toHaveAttribute( 'href', HELP.url );

		rerender( <ControlField settingId="b" schema={ { type: 'boolean', name: 'Включить', controlType: 'toggle', help: HELP } } value={ false } onChange={ jest.fn() } /> );
		expect( screen.getByRole( 'link', { name: HELP.label } ) ).toHaveAttribute( 'href', HELP.url );
	} );

	test( 'a field without a declared help draws no link', () => {
		render( <ControlField settingId="a" schema={ { type: 'string', name: 'Ключ', controlType: 'text' } } value="" onChange={ jest.fn() } /> );

		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	test( 'under a section description and in a group card', () => {
		render(
			<SectionView
				providerId="t"
				section={ { id: 's', label: 'S', description: '', help: HELP, fields: {}, groups: [] } }
				values={ {} }
				onFieldChange={ jest.fn() }
				onFieldRevert={ jest.fn() }
			/>
		);
		expect( screen.getAllByRole( 'link' ) ).toHaveLength( 1 );

		render(
			<GroupCard
				providerId="t"
				group={ { id: 'g', title: 'Группа', description: '', notice: '', fields: [], actions: [], help: { ...HELP, url: 'https://woodev.ru/docs/ozon/#problems' } } }
				actions={ [] }
			/>
		);
		expect( screen.getAllByRole( 'link' ).map( ( link ) => link.getAttribute( 'href' ) ) ).toEqual( [ HELP.url, 'https://woodev.ru/docs/ozon/#problems' ] );
	} );

	test( 'under a note, with the «Частые проблемы» link', () => {
		render( <Notices notice={ { status: 'error', lines: [ 'Не удалось связать товар.' ], help: HELP } } onDismiss={ jest.fn() } /> );

		expect( screen.getByRole( 'link', { name: HELP.label } ) ).toBeInTheDocument();
	} );

	test( 'on a marketplace page, whether a view is registered or not', () => {
		const page: ManifestPage = { id: 'tools', title: 'Инструменты', path: '/p', kind: 'custom', tables: [], view: 'tools', breadcrumbs: [], analytics: null, help: HELP };
		const menu: ManifestMenu = { id: 'm', pluginId: 'p', title: 'M', domId: 'd', pages: [ page ] };

		render( <PageBody page={ page } menu={ menu } query={ {} } /> );

		expect( screen.getByRole( 'link', { name: HELP.label } ) ).toBeInTheDocument();
	} );
} );

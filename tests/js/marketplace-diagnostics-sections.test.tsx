/**
 * The «Диагностика» sections: the masked notification address is shown as code with a link to the settings tab where the real
 * address is copied — never a «Копировать» button, because a masked address is useless to copy.
 *
 * @see src/marketplace-admin/views/diagnostics/diagnostics-view.tsx
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { Sections } from '../../src/marketplace-admin/views/diagnostics/diagnostics-view';

const masked = 'https://shop.example/wp-json/woodev/v1/ozon-seller/notifications/12/[скрыто]';

const sections = [
	{
		title: 'Аккаунт «Главный»',
		lines: [
			{ label: 'Соединение', value: 'работает' },
			{
				label: 'Адрес для уведомлений',
				value: masked,
				monospace: true,
				link: {
					text: 'Скопировать адрес — в настройках уведомлений',
					url: 'https://shop.example/wp-admin/admin.php?page=ozon&tab=notifications',
				},
			},
		],
	},
];

describe( 'Diagnostics sections', () => {
	it( 'shows the masked address as code followed by a link to the Notifications tab', () => {
		const { container } = render( <Sections sections={ sections } /> );

		const code = container.querySelector( 'code.woodev-facts__code' );
		expect( code ).toHaveTextContent( masked );
		expect( container.textContent ).not.toMatch( /notifications\/12\/(?!\[скрыто\])/ );

		const link = screen.getByRole( 'link', { name: 'Скопировать адрес — в настройках уведомлений' } );
		expect( link ).toHaveAttribute( 'href', expect.stringContaining( 'tab=notifications' ) );
	} );

	it( 'has no copy button, and a plain line stays plain text', () => {
		const { container } = render( <Sections sections={ sections } /> );

		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( container.querySelectorAll( 'code' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'работает' ) ).toBeInTheDocument();
	} );
} );

/**
 * s158: a blocked field LOOKS blocked (row + toggle carry the disabled class the stylesheet paints), a toggle can
 * carry ONE line that shows only while it is on, and an ordinary section can carry action buttons under its fields.
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ControlField from '../../src/components/control-field';
import SectionView from '../../src/settings-page/section-view';
import { runTool } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( { runTool: jest.fn() } ) );

beforeEach( () => {
	( runTool as jest.Mock ).mockReset();
} );

const fieldRow = ( container: HTMLElement ) => container.querySelector( '.woodev-field' ) as HTMLElement;

test( 'a field blocked by disabled_if gets the disabled row class and a natively disabled input', () => {
	const schema = { name: 'Ключ', type: 'string', controlType: 'text', value: 'k', disabled_if: { setting: 'test_mode', value: true }, disabled_reason: 'Тестовый режим' };
	const props = { schema, value: 'k', onChange: jest.fn() };

	const view = render( <ControlField { ...props } conditionValues={ { test_mode: false } } /> );
	expect( fieldRow( view.container ) ).not.toHaveClass( 'woodev-field--disabled' );
	expect( screen.getByRole( 'textbox' ) ).toBeEnabled();

	view.rerender( <ControlField { ...props } conditionValues={ { test_mode: true } } /> );
	expect( fieldRow( view.container ) ).toHaveClass( 'woodev-field--disabled' );
	expect( screen.getByRole( 'textbox' ) ).toBeDisabled();
} );

test( 'a statically disabled select shows the disabled state on its trigger and its row', () => {
	const schema = { name: 'Режим', type: 'string', controlType: 'select', options: { a: 'A', b: 'B' }, value: 'a', disabled: true, disabled_reason: 'Недоступно' };

	const { container } = render( <ControlField schema={ schema } value="a" onChange={ jest.fn() } /> );

	expect( fieldRow( container ) ).toHaveClass( 'woodev-field--disabled' );
	const trigger = container.querySelector( '.woodev-select__trigger' ) as HTMLButtonElement;
	expect( trigger ).toBeDisabled();
	expect( trigger ).toHaveClass( 'is-disabled' );
} );

test( 'a disabled toggle row carries is-disabled and its reason, an enabled one does not', () => {
	const schema = { name: 'Ключи', type: 'boolean', controlType: 'toggle', value: true, disabled: true, disabled_reason: 'Тестовый режим' };

	const view = render( <ControlField schema={ schema } value={ true } onChange={ jest.fn() } /> );
	expect( view.container.querySelector( '.woodev-field__toggle-row' ) ).toHaveClass( 'is-disabled' );
	expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();
	expect( screen.getByText( 'Тестовый режим' ) ).toBeInTheDocument();

	view.rerender( <ControlField schema={ { ...schema, disabled: false } } value={ true } onChange={ jest.fn() } /> );
	expect( view.container.querySelector( '.woodev-field__toggle-row' ) ).not.toHaveClass( 'is-disabled' );
} );

test( 'description_on shows under a toggle only while it is on, once', () => {
	const schema = { name: 'Тестовый режим', type: 'boolean', controlType: 'toggle', value: false, description: 'Всегда', description_on: 'В тестовом режиме используются встроенные ключи.' };

	const view = render( <ControlField schema={ schema } value={ false } onChange={ jest.fn() } /> );
	expect( screen.getByText( 'Всегда' ) ).toBeInTheDocument();
	expect( screen.queryByText( 'В тестовом режиме используются встроенные ключи.' ) ).toBeNull();

	view.rerender( <ControlField schema={ schema } value={ true } onChange={ jest.fn() } /> );
	expect( screen.getAllByText( 'В тестовом режиме используются встроенные ключи.' ) ).toHaveLength( 1 );

	view.rerender( <ControlField schema={ schema } value={ false } onChange={ jest.fn() } /> );
	expect( screen.queryByText( 'В тестовом режиме используются встроенные ключи.' ) ).toBeNull();
} );

test( 'a toggle without description_on renders no extra line when on', () => {
	const schema = { name: 'Логирование', type: 'boolean', controlType: 'toggle', value: true };

	const { container } = render( <ControlField schema={ schema } value={ true } onChange={ jest.fn() } /> );

	expect( container.querySelector( '.woodev-field__toggle-desc' ) ).toBeNull();
} );

describe( 'an ordinary section with action buttons', () => {
	const section = {
		id: 'export',
		label: 'Выгрузка заказов',
		description: '',
		fields: { auto: { name: 'Автоэкспорт', type: 'boolean', controlType: 'toggle', value: false } },
		actions: [ { id: 'sync_delivery_statuses', name: 'Статусы доставки', desc: 'Получить статусы сейчас.', button: 'Обновить статусы сейчас', disabled: false, status_text: '' } ],
	};

	const view = () => render( <SectionView providerId="cdek" section={ section } tabFields={ {} } values={ {} } conditionValues={ {} } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } showErrors={ false } serverErrors={ {} } /> );

	test( 'renders the fields and the button beneath them', () => {
		view();

		expect( screen.getByText( 'Автоэкспорт' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Обновить статусы сейчас' } ) ).toBeEnabled();
	} );

	test( 'pressing the button runs the action on THIS tab and shows the result', async () => {
		( runTool as jest.Mock ).mockResolvedValue( { success: true, message: 'Статусы обновлены.' } );
		view();

		fireEvent.click( screen.getByRole( 'button', { name: 'Обновить статусы сейчас' } ) );

		expect( runTool ).toHaveBeenCalledWith( 'cdek', 'sync_delivery_statuses', {} );
		await waitFor( () => expect( screen.getByText( 'Статусы обновлены.' ) ).toBeInTheDocument() );
	} );

	test( 'a section without actions renders no tools block', () => {
		const { container } = render( <SectionView providerId="cdek" section={ { ...section, actions: undefined } } tabFields={ {} } values={ {} } conditionValues={ {} } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } showErrors={ false } serverErrors={ {} } /> );

		expect( container.querySelector( '.woodev-tools' ) ).toBeNull();
	} );
} );

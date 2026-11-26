/**
 * Pins #2016: a refusal the server answers «Сохранить» with must be shown at the field it names, in a connection
 * block too. The save route answers `data.errors` ( setting id => message ); before this fix two layers lost it:
 * `SectionView` did not hand the map to `ConnectionBlock`, and `ControlField` drew no error under a sensitive
 * field, so a refused token showed only the generic «Проверьте правильность заполнения полей.».
 *
 * WHY IT NEEDS A REAL RENDER of `App`: the message travels `saveTab` → `App` state → `SectionView` →
 * `ConnectionBlock` → `ControlField`, and a break in any one of those is invisible to a test of the others.
 *
 * @see src/settings-page/section-view.js
 * @see src/settings-page/connection-block.js
 */

import '@testing-library/jest-dom';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../../src/settings-page/app';
import { fetchSchema, saveTab } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
	testConnection: jest.fn(),
} ) );

const REFUSAL = 'Не удалось определить идентификатор аккаунта по этому токену. Проверьте токен.';
const NAME_REFUSAL = 'Слишком длинное название.';

/** The accounts tab of a marketplace plugin whose account identifier is derived from the token: only the add block. */
const accountsTab = () => ( {
	id: 'marketplace',
	label: 'Подключение',
	sections: [
		{
			id: 'account_connection_new',
			label: 'Подключение',
			is_connection: true,
			supports_test: true,
			card: { new: true },
			fields: {
				account_new_name: { type: 'string', controlType: 'text', name: 'Название', value: '' },
				account_new_api_token: { type: 'string', controlType: 'password', name: 'API-токен', value: '', sensitive: true, required: true },
			},
		},
	],
} );

const refusal = ( errors: Record< string, string > ) => ( {
	code: 'woodev_settings_invalid',
	message: 'Проверьте правильность заполнения полей.',
	data: { status: 400, errors },
} );

/** The field row that draws the label, so a message is checked to sit at ITS field and not merely on the page. */
const fieldRow = ( label: string ) => screen.getByText( label, { selector: '.woodev-field__label' } ).closest( '.woodev-field' ) as HTMLElement;

const tokenInput = () => fieldRow( 'API-токен' ).querySelector( 'input' ) as HTMLInputElement;
const nameInput = () => fieldRow( 'Название' ).querySelector( 'input' ) as HTMLInputElement;

/** Opens the add-account form, types a token and presses «Сохранить». */
async function saveNewAccount() {
	render( <App /> );

	fireEvent.click( await screen.findByRole( 'button', { name: 'Добавить аккаунт' } ) );
	fireEvent.change( tokenInput(), { target: { value: 'base-token' } } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

	await waitFor( () => expect( saveTab ).toHaveBeenCalledWith( 'marketplace', { account_new_api_token: 'base-token' } ) );
}

describe( 'a connection block shows the server\'s field errors on «Сохранить» (#2016)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		( fetchSchema as jest.Mock ).mockResolvedValue( { tabs: [ accountsTab() ] } );
	} );

	it( 'shows a refused token\'s reason at the token field and at no other', async () => {
		( saveTab as jest.Mock ).mockRejectedValue( refusal( { account_new_api_token: REFUSAL } ) );

		await saveNewAccount();

		await waitFor( () => expect( fieldRow( 'API-токен' ) ).toHaveTextContent( REFUSAL ) );
		expect( fieldRow( 'API-токен' ) ).toHaveClass( 'woodev-field--error' );
		expect( fieldRow( 'Название' ) ).not.toHaveClass( 'woodev-field--error' );
		expect( tokenInput() ).toHaveValue( 'base-token' );
	} );

	it( 'shows an ordinary field\'s reason too', async () => {
		( saveTab as jest.Mock ).mockRejectedValue( refusal( { account_new_name: NAME_REFUSAL } ) );

		await saveNewAccount();

		await waitFor( () => expect( fieldRow( 'Название' ) ).toHaveTextContent( NAME_REFUSAL ) );
		expect( fieldRow( 'API-токен' ) ).not.toHaveClass( 'woodev-field--error' );
	} );

	it( 'drops the reason of a field once it is edited, and keeps the others', async () => {
		( saveTab as jest.Mock ).mockRejectedValue( refusal( { account_new_api_token: REFUSAL, account_new_name: NAME_REFUSAL } ) );

		await saveNewAccount();
		await waitFor( () => expect( fieldRow( 'API-токен' ) ).toHaveTextContent( REFUSAL ) );

		fireEvent.change( tokenInput(), { target: { value: 'another-token' } } );

		expect( fieldRow( 'API-токен' ) ).not.toHaveTextContent( REFUSAL );
		expect( fieldRow( 'Название' ) ).toHaveTextContent( NAME_REFUSAL );

		fireEvent.change( nameInput(), { target: { value: 'Магазин' } } );

		expect( fieldRow( 'Название' ) ).not.toHaveTextContent( NAME_REFUSAL );
	} );
} );

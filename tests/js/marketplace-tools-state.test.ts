/**
 * The «Инструменты» page's decisions: which account is shown, which tool may be previewed or confirmed, and when to read
 * the account line again.
 *
 * @see src/marketplace-admin/views/tools/tool-state.ts
 */

import { accountFor, canConfirm, canPreview, chooseAccount, isBusy } from '../../src/marketplace-admin/views/tools/tool-state';
import type { ToolActivity, ToolPreview, ToolRow } from '../../src/marketplace-admin/views/tools/types';

const tool = ( overrides: Partial< ToolRow > = {} ): ToolRow => ( {
	id: 'rerun',
	title: 'Перенос данных',
	description: 'Переносит данные заново.',
	button: 'Запустить',
	takes_claim: true,
	scope: 'account',
	disabled: false,
	disabled_reason: '',
	...overrides,
} );

const preview = ( overrides: Partial< ToolPreview > = {} ): ToolPreview => ( {
	tool: 'rerun',
	summary: 'Будет перенесено 3 аккаунта.',
	disabled: false,
	disabled_reason: '',
	confirm_label: 'Запустить',
	can_run: true,
	...overrides,
} );

const activity = ( active: boolean ): ToolActivity => ( {
	active,
	text: active ? 'По аккаунту идёт фоновая работа.' : '',
	blocked_actions: [],
	observed_at: '2026-11-12T00:00:00Z',
} );

describe( 'chooseAccount', () => {
	const accounts = [
		{ id: 3, name: 'Главный' },
		{ id: 7, name: 'Запасной' },
	];

	it( 'keeps the chosen account while it is still on the list', () => {
		expect( chooseAccount( accounts, 7 ) ).toBe( 7 );
	} );

	it( 'falls back to the first account when the chosen one is gone or none was chosen', () => {
		expect( chooseAccount( accounts, 99 ) ).toBe( 3 );
		expect( chooseAccount( accounts, null ) ).toBe( 3 );
	} );

	it( 'chooses nothing when the plugin has no account', () => {
		expect( chooseAccount( [], 3 ) ).toBeNull();
	} );
} );

describe( 'canPreview', () => {
	it( 'needs an account and a tool that is not disabled', () => {
		expect( canPreview( tool(), 3 ) ).toBe( true );
		expect( canPreview( tool(), null ) ).toBe( false );
		expect( canPreview( tool( { disabled: true } ), 3 ) ).toBe( false );
	} );

	it( 'lets a site tool be previewed with no account, but not while it is disabled', () => {
		expect( canPreview( tool( { scope: 'site', takes_claim: false } ), null ) ).toBe( true );
		expect( canPreview( tool( { scope: 'site', disabled: true } ), null ) ).toBe( false );
	} );
} );

describe( 'accountFor', () => {
	it( 'names the chosen account for an account tool and none for a site tool', () => {
		expect( accountFor( tool(), 3 ) ).toBe( 3 );
		expect( accountFor( tool( { scope: 'site' } ), 3 ) ).toBeNull();
	} );
} );

describe( 'canConfirm', () => {
	it( 'confirms only the preview of this tool, when the tool is free and the server allows the run', () => {
		expect( canConfirm( preview(), tool() ) ).toBe( true );
	} );

	it( 'refuses when there is no preview, no tool on the page, or a preview of another tool', () => {
		expect( canConfirm( null, tool() ) ).toBe( false );
		expect( canConfirm( preview(), undefined ) ).toBe( false );
		expect( canConfirm( preview( { tool: 'stock' } ), tool() ) ).toBe( false );
	} );

	it( 'refuses a disabled tool even when a preview is open, and a preview the server will not run', () => {
		expect( canConfirm( preview(), tool( { disabled: true } ) ) ).toBe( false );
		expect( canConfirm( preview( { can_run: false } ), tool() ) ).toBe( false );
	} );
} );

describe( 'isBusy', () => {
	it( 'reads the account again only while a run holds the account', () => {
		expect( isBusy( activity( true ) ) ).toBe( true );
		expect( isBusy( activity( false ) ) ).toBe( false );
		expect( isBusy( null ) ).toBe( false );
	} );
} );

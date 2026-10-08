/**
 * Tests the Setup Wizard's server-side step graph (D3), plugin step components (D4) and the
 * honest finish (audit #5):
 *
 * - the wizard renders the VISIBLE part of the graph and re-renders from the graph a save or an
 *   action returns, keeping the merchant on the same step;
 * - a custom component gets the plumbing (values, onChange, save, next, runAction, errors, busy),
 *   a missing or crashing component shows an error state instead of a blank screen;
 * - a failed «completed» write never shows the success screen.
 *
 * @see src/setup-wizard/app.js
 * @see src/setup-wizard/types.ts
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import App from '../../src/setup-wizard/app';
import { saveStep, runAction, complete } from '../../src/setup-wizard/rest';

jest.mock( '../../src/setup-wizard/rest', () => ( {
	saveStep: jest.fn( () => Promise.resolve( { saved: true } ) ),
	complete: jest.fn( () => Promise.resolve( { complete: true } ) ),
	runAction: jest.fn( () => Promise.resolve( { status: 'success', message: '', data: {} } ) ),
} ) );

const entry = ( id, label, extra = {} ) => ( {
	id,
	visible: true,
	label,
	type: 'settings',
	description: '',
	fields: {},
	content: '',
	skippable: true,
	validates: false,
	component: null,
	actions: [],
	...extra,
} );

const finish = { id: 'finish', visible: true, label: 'Готово', type: 'finish', fields: {}, actions: [] };

/** The migration-wizard shape: «Начало» → «Сопоставление» (hideable) → «Итог». */
function graph( { mappingVisible = true } = {} ) {
	return [
		entry( 'start', 'Начало', { actions: [ { id: 'fresh', label: 'Начать с чистого листа', destructive: false, confirm: '' } ] } ),
		mappingVisible ? entry( 'mapping', 'Сопоставление' ) : { id: 'mapping', visible: false },
		entry( 'done', 'Итог', { type: 'content', content: '<p>ok</p>' } ),
		finish,
	];
}

function install( steps ) {
	window.woodevSetupWizard = {
		pluginId: 'woodev_test',
		pluginName: 'Woodev Test',
		adminUrl: '/wp-admin/',
		restRoot: 'https://example.test/wp-json/woodev/v1/setup',
		nonce: 'test-nonce',
		steps,
	};
}

const title = () => document.querySelector( '.woodev-setup__step-title' );
const stepperLabels = () =>
	Array.from( document.querySelectorAll( 'ol.woodev-stepper > li' ) ).map( ( li ) => li.querySelector( '.woodev-stepper__label' ).textContent );

async function click( element ) {
	await act( async () => {
		fireEvent.click( element );
	} );
}

const primary = () => document.querySelector( '.woodev-setup__primary' );

beforeEach( () => {
	window.location.hash = '';
	window.sessionStorage.clear();
	delete window.woodevSetupWizardComponents;
	saveStep.mockReset();
	saveStep.mockImplementation( () => Promise.resolve( { saved: true } ) );
	runAction.mockReset();
	runAction.mockImplementation( () => Promise.resolve( { status: 'success', message: '', data: {} } ) );
	complete.mockReset();
	complete.mockImplementation( () => Promise.resolve( { complete: true } ) );
	jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	jest.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete window.woodevSetupWizard;
	delete window.woodevSetupWizardComponents;
} );

describe( 'step graph (D3)', () => {
	test( 'a step the server hides is not in the stepper and Continue skips over it', async () => {
		install( graph( { mappingVisible: false } ) );
		render( createElement( App ) );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Итог', 'Готово' ] );

		await click( primary() );

		expect( title() ).toHaveTextContent( 'Итог' );
	} );

	test( 'a save that returns a graph hiding the next step re-renders from it and lands on the step after', async () => {
		install( graph() );
		saveStep.mockImplementation( () => Promise.resolve( { saved: true, step: 'start', graph: graph( { mappingVisible: false } ) } ) );
		render( createElement( App ) );
		expect( stepperLabels() ).toEqual( [ 'Начало', 'Сопоставление', 'Итог', 'Готово' ] );

		await click( primary() );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Итог', 'Готово' ] );
		expect( title() ).toHaveTextContent( 'Итог' );
	} );

	test( 'a save that reveals a hidden step shows it and goes to it', async () => {
		install( graph( { mappingVisible: false } ) );
		saveStep.mockImplementation( () => Promise.resolve( { saved: true, step: 'start', graph: graph() } ) );
		render( createElement( App ) );

		await click( primary() );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Сопоставление', 'Итог', 'Готово' ] );
		expect( title() ).toHaveTextContent( 'Сопоставление' );
	} );

	test( 'a response without a graph leaves the list alone and advances as before', async () => {
		install( graph() );
		render( createElement( App ) );

		await click( primary() );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Сопоставление', 'Итог', 'Готово' ] );
		expect( title() ).toHaveTextContent( 'Сопоставление' );
	} );

	test( 'a malformed graph (no terminal finish step) is ignored', async () => {
		install( graph() );
		saveStep.mockImplementation( () => Promise.resolve( { saved: true, graph: [ entry( 'only', 'Один' ) ] } ) );
		render( createElement( App ) );

		await click( primary() );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Сопоставление', 'Итог', 'Готово' ] );
		expect( title() ).toHaveTextContent( 'Сопоставление' );
	} );

	test( 'an action answer carries a graph too: the stepper updates and the merchant stays on the step', async () => {
		install( graph() );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Начинаем с нуля.', data: {}, graph: graph( { mappingVisible: false } ) } )
		);
		render( createElement( App ) );

		await click( screen.getByRole( 'button', { name: 'Начать с чистого листа' } ) );

		expect( stepperLabels() ).toEqual( [ 'Начало', 'Итог', 'Готово' ] );
		expect( title() ).toHaveTextContent( 'Начало' );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Начинаем с нуля.' ); // the answer survives the re-render.
	} );

	test( 'the current step keeps aria-current when the graph shifts its position', async () => {
		install( graph() );
		render( createElement( App ) );
		await click( primary() ); // «Сопоставление»
		saveStep.mockImplementation( () =>
			Promise.resolve( { saved: true, graph: [ { id: 'start', visible: false }, ...graph().slice( 1 ) ] } )
		);

		await click( primary() ); // saves «Сопоставление»; the first step vanishes from the graph

		const current = document.querySelector( 'ol.woodev-stepper > li[aria-current="step"]' );
		expect( current ).toHaveTextContent( 'Итог' );
		expect( stepperLabels() ).toEqual( [ 'Сопоставление', 'Итог', 'Готово' ] );
	} );
} );

describe( 'plugin step component (D4)', () => {
	const componentStep = ( extra = {} ) =>
		entry( 'review', 'Проверка', {
			type: 'content',
			component: { handle: 'acme-review', export: 'ReviewStep' },
			actions: [
				{ id: 'check', label: 'Проверить', destructive: false, confirm: '' },
				{ id: 'wipe', label: 'Очистить', destructive: true, confirm: 'Удалить всё?' },
			],
			...extra,
		} );

	function installWithComponent( Component, extra ) {
		install( [ entry( 'start', 'Начало' ), componentStep( extra ), finish ] );
		window.woodevSetupWizardComponents = { 'acme-review': { ReviewStep: Component } };
	}

	async function openReview() {
		await click( primary() ); // «Начало» → «Проверка»
		expect( title() ).toHaveTextContent( 'Проверка' );
		saveStep.mockClear(); // only what the component step itself sends is under test.
	}

	test( 'it renders inside the standard frame (heading, Back, Continue), not instead of it', async () => {
		installWithComponent( () => createElement( 'p', { className: 'acme-body' }, 'Свой шаг' ) );
		render( createElement( App ) );
		await openReview();

		expect( document.querySelector( '.woodev-setup__card .acme-body' ) ).toHaveTextContent( 'Свой шаг' );
		expect( document.querySelector( '.woodev-setup__back' ) ).toBeInTheDocument();
		expect( primary() ).toBeInTheDocument();
	} );

	test( 'it receives the step, the values and onChange, and the edits are what Continue saves', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'button', { type: 'button', onClick: () => p.onChange( { ...p.values, token: 'T1' } ) }, 'Ввести' );
		}, { validates: true } );
		render( createElement( App ) );
		await openReview();

		expect( props.step.id ).toBe( 'review' );
		expect( props.values ).toEqual( {} );

		await click( screen.getByRole( 'button', { name: 'Ввести' } ) );
		expect( props.values ).toEqual( { token: 'T1' } );

		await click( primary() );
		expect( saveStep ).toHaveBeenCalledWith( 'review', { token: 'T1' } );
	} );

	test( 'save() asks the server even for a content step, stays on the step and resolves true', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();

		let accepted;
		await act( async () => {
			accepted = await props.save();
		} );

		expect( saveStep ).toHaveBeenCalledWith( 'review', {} );
		expect( accepted ).toBe( true );
		expect( title() ).toHaveTextContent( 'Проверка' );
	} );

	test( 'a refused save() resolves false and the per-field errors arrive as props', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();
		saveStep.mockImplementation( () =>
			Promise.reject( { message: 'Проверьте поля.', data: { status: 400, errors: { token: 'Токен просрочен.' } } } )
		);

		let accepted;
		await act( async () => {
			accepted = await props.save();
		} );

		expect( accepted ).toBe( false );
		expect( props.errors ).toEqual( { token: 'Токен просрочен.' } );
		expect( document.querySelector( '.woodev-setup__error' ) ).toHaveTextContent( 'Проверьте поля.' );
	} );

	test( 'next() is the standard Continue: it saves and moves on', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		}, { validates: true } );
		render( createElement( App ) );
		await openReview();

		await act( async () => {
			await props.next();
		} );

		expect( saveStep ).toHaveBeenCalledWith( 'review', {} );
		expect( document.querySelector( '.woodev-setup__finish-title' ) ).toBeInTheDocument();
	} );

	test( 'back() and skip() navigate', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();

		await act( async () => {
			props.back();
		} );

		expect( title() ).toHaveTextContent( 'Начало' );
	} );

	test( 'runAction() runs a non-destructive action and resolves its answer', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		runAction.mockImplementation( () => Promise.resolve( { status: 'success', message: 'Верно.', data: { n: 3 } } ) );
		render( createElement( App ) );
		await openReview();

		let answer;
		await act( async () => {
			answer = await props.runAction( 'check' );
		} );

		expect( runAction ).toHaveBeenCalledWith( 'review', 'check', {}, false );
		expect( answer ).toEqual( { status: 'success', message: 'Верно.', data: { n: 3 } } );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Верно.' );
	} );

	test( 'runAction() of a destructive action waits for the framework confirmation, then runs', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();

		let answer;
		let pending;
		await act( async () => {
			pending = props.runAction( 'wipe' ).then( ( a ) => {
				answer = a;
			} );
		} );

		expect( runAction ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Удалить всё?' );

		await click( screen.getByRole( 'button', { name: 'Да, выполнить' } ) );
		await pending;

		expect( runAction ).toHaveBeenCalledWith( 'review', 'wipe', {}, true );
		expect( answer.status ).toBe( 'success' );
	} );

	test( 'declining the confirmation resolves the component\'s runAction() as cancelled', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();

		let answer;
		let pending;
		await act( async () => {
			pending = props.runAction( 'wipe' ).then( ( a ) => {
				answer = a;
			} );
		} );
		await click( screen.getByRole( 'button', { name: 'Отмена' } ) );
		await pending;

		expect( runAction ).not.toHaveBeenCalled();
		expect( answer.status ).toBe( 'cancelled' );
	} );

	test( 'the framework does not draw the declared action buttons for a component step (it owns its UI)', async () => {
		installWithComponent( () => createElement( 'span' ) );
		render( createElement( App ) );
		await openReview();

		expect( screen.queryByRole( 'button', { name: 'Проверить' } ) ).not.toBeInTheDocument();
	} );

	test( 'an unknown action id resolves as an error instead of hanging', async () => {
		let props;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();

		let answer;
		await act( async () => {
			answer = await props.runAction( 'nope' );
		} );

		expect( answer.status ).toBe( 'error' );
		expect( runAction ).not.toHaveBeenCalled();
	} );

	test( 'busy is true while a save is in flight', async () => {
		let props;
		let release;
		installWithComponent( ( p ) => {
			props = p;
			return createElement( 'span' );
		} );
		render( createElement( App ) );
		await openReview();
		saveStep.mockImplementation( () => new Promise( ( resolve ) => {
			release = resolve;
		} ) );
		expect( props.busy ).toBe( false );

		let saving;
		await act( async () => {
			saving = props.save();
		} );
		expect( props.busy ).toBe( true );

		await act( async () => {
			release( { saved: true } );
			await saving;
		} );
		expect( props.busy ).toBe( false );
	} );

	test( 'a script that never published the component gives an error state, not a blank step', async () => {
		install( [ entry( 'start', 'Начало' ), componentStep(), finish ] ); // no window.woodevSetupWizardComponents
		render( createElement( App ) );
		await openReview();

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Не удалось загрузить содержимое этого шага' );
		expect( title() ).toHaveTextContent( 'Проверка' ); // the frame is still there…
		expect( document.querySelector( '.woodev-setup__back' ) ).toBeInTheDocument(); // …and the merchant can leave
	} );

	test( 'a published script without that export gives the same error state', async () => {
		install( [ entry( 'start', 'Начало' ), componentStep(), finish ] );
		window.woodevSetupWizardComponents = { 'acme-review': { SomethingElse: () => null } };
		render( createElement( App ) );
		await openReview();

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Не удалось загрузить содержимое этого шага' );
	} );

	test( 'a component that throws while rendering is caught: error state, wizard still alive', async () => {
		installWithComponent( () => {
			throw new Error( 'plugin bug' );
		} );
		render( createElement( App ) );
		await openReview();

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Не удалось загрузить содержимое этого шага' );
		expect( stepperLabels() ).toEqual( [ 'Начало', 'Проверка', 'Готово' ] );
	} );
} );

describe( 'an honest finish (audit #5)', () => {
	function installTwoSteps() {
		install( [ entry( 'start', 'Начало' ), finish ] );
	}

	test( 'a failed «completed» write shows the error, not the success screen', async () => {
		installTwoSteps();
		complete.mockImplementation( () => Promise.reject( new Error( 'boom' ) ) );
		render( createElement( App ) );

		await click( primary() ); // finish → complete() rejects

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'отметить мастер завершённым не удалось' );
		expect( screen.queryByText( /готов к работе/ ) ).not.toBeInTheDocument();
		expect( document.querySelector( '.woodev-setup__finish-check' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Повторить' } ) ).toBeInTheDocument();
	} );

	test( 'the success screen appears only after the write succeeded, not while it is pending', async () => {
		installTwoSteps();
		let settle;
		complete.mockImplementation( () => new Promise( ( resolve ) => {
			settle = resolve;
		} ) );
		render( createElement( App ) );

		await click( primary() );
		expect( screen.queryByText( /готов к работе/ ) ).not.toBeInTheDocument();
		expect( document.querySelector( '.woodev-setup__finish-title' ) ).toHaveTextContent( 'Завершаем настройку' );

		await act( async () => {
			settle( { complete: true } );
		} );

		expect( screen.getByText( /готов к работе/ ) ).toBeInTheDocument();
		expect( document.querySelector( '.woodev-setup__finish-title' ) ).toHaveFocus();
	} );

	test( 'a retry that succeeds turns the error into the success screen', async () => {
		installTwoSteps();
		complete.mockImplementationOnce( () => Promise.reject( new Error( 'boom' ) ) );
		render( createElement( App ) );
		await click( primary() );

		await click( screen.getByRole( 'button', { name: 'Повторить' } ) );

		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( screen.getByText( /готов к работе/ ) ).toBeInTheDocument();
	} );
} );

/*
 * Critic round 1 on part B: the graph refresh must reconcile edits, navigate by order, and ignore
 * obsolete responses.
 */
describe( 'edits superseded by the server (critic 109b #1)', () => {
	const modeField = ( value ) => ( { mode: { id: 'mode', type: 'string', name: 'Режим', value } } );

	function modeGraph( mode ) {
		const list = graph( { mappingVisible: 'keep' === mode } );
		list[ 0 ] = { ...list[ 0 ], fields: modeField( mode ) };
		return list;
	}

	const modeInput = () => document.querySelector( '.woodev-setup__fields input' );

	/** A stateful server: the saved mode, the graph for it, and what each call does to it. */
	function statefulServer( { initial, reset } ) {
		const server = { mode: initial };
		runAction.mockImplementation( () => {
			server.mode = reset;
			return Promise.resolve( { status: 'success', message: 'Готово.', data: {}, graph: modeGraph( server.mode ), ...server.answerExtra } );
		} );
		saveStep.mockImplementation( ( id, edits ) => {
			if ( undefined !== edits.mode ) {
				server.mode = edits.mode;
			}
			return Promise.resolve( { saved: true, step: id, graph: modeGraph( server.mode ) } );
		} );
		return server;
	}

	test( 'edit → reset (action says so) → Continue: the reset is not undone', async () => {
		install( modeGraph( 'scratch' ) );
		const server = statefulServer( { initial: 'scratch', reset: 'scratch' } );
		server.answerExtra = { discard_edits: true }; // saved value is unchanged by the reset, so the action must say it
		render( createElement( App ) );

		fireEvent.change( modeInput(), { target: { value: 'keep' } } );
		await click( screen.getByRole( 'button', { name: 'Начать с чистого листа' } ) );

		expect( modeInput() ).toHaveValue( 'scratch' ); // the form agrees with the success message
		expect( stepperLabels() ).not.toContain( 'Сопоставление' );

		await click( primary() );

		expect( server.mode ).toBe( 'scratch' );
		expect( saveStep.mock.calls[ 0 ][ 1 ] ).toEqual( {} ); // no stale edit travels
		expect( title() ).toHaveTextContent( 'Итог' );
	} );

	test( 'a field whose SAVED value the action changed loses its edit even without the flag', async () => {
		install( modeGraph( 'keep' ) );
		statefulServer( { initial: 'keep', reset: 'scratch' } );
		render( createElement( App ) );

		fireEvent.change( modeInput(), { target: { value: 'other' } } );
		await click( screen.getByRole( 'button', { name: 'Начать с чистого листа' } ) );

		expect( modeInput() ).toHaveValue( 'scratch' );
	} );

	test( 'control: a read-only action keeps what the merchant typed', async () => {
		install( modeGraph( 'scratch' ) );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Ключ подходит.', data: {}, graph: modeGraph( 'scratch' ) } )
		);
		render( createElement( App ) );

		fireEvent.change( modeInput(), { target: { value: 'typed-key' } } );
		await click( screen.getByRole( 'button', { name: 'Начать с чистого листа' } ) );

		expect( modeInput() ).toHaveValue( 'typed-key' );
	} );

	test( 'an edit made after the action started survives the discard', async () => {
		install( modeGraph( 'scratch' ) );
		let release;
		runAction.mockImplementation( () => new Promise( ( resolve ) => {
			release = resolve;
		} ) );
		render( createElement( App ) );

		fireEvent.change( modeInput(), { target: { value: 'first' } } );
		await click( screen.getByRole( 'button', { name: 'Начать с чистого листа' } ) );
		fireEvent.change( modeInput(), { target: { value: 'typed-after' } } );
		await act( async () => {
			release( { status: 'success', message: 'Готово.', data: {}, discard_edits: true, graph: modeGraph( 'scratch' ) } );
		} );

		expect( modeInput() ).toHaveValue( 'typed-after' );
	} );

	test( 'a save that normalises a field shows the saved value, not the typed one', async () => {
		install( modeGraph( 'scratch' ) );
		saveStep.mockImplementation( () => Promise.resolve( { saved: true, graph: modeGraph( 'scratch' ).map( ( s ) => ( 'start' === s.id ? { ...s, fields: modeField( 'NORMALISED' ) } : s ) ) } ) );
		render( createElement( App ) );

		fireEvent.change( modeInput(), { target: { value: 'typed' } } );
		await click( primary() );
		await click( document.querySelector( '.woodev-setup__back' ) );

		expect( modeInput() ).toHaveValue( 'NORMALISED' );
	} );
} );

describe( 'navigation follows the graph order (critic 109b #2)', () => {
	const review = () => entry( 'done', 'Итог', { skippable: false } );

	test( 'a save that hides the current step AND an earlier one lands on the real successor, not on finish', async () => {
		install( [ entry( 'start', 'Начало' ), entry( 'mapping', 'Сопоставление' ), review(), finish ] );
		render( createElement( App ) );
		await click( primary() );
		expect( title() ).toHaveTextContent( 'Сопоставление' );
		saveStep.mockImplementation( () =>
			Promise.resolve( { saved: true, step: 'mapping', graph: [ { id: 'start', visible: false }, { id: 'mapping', visible: false }, review(), finish ] } )
		);

		await click( primary() );

		expect( title() ).toHaveTextContent( 'Итог' );
		expect( complete ).not.toHaveBeenCalled();
	} );

	test( 'the same with a permanent step in front (the index shifts the other way)', async () => {
		const welcome = entry( 'welcome', 'Приветствие', { type: 'content' } );
		install( [ welcome, entry( 'start', 'Начало' ), entry( 'mapping', 'Сопоставление' ), review(), finish ] );
		render( createElement( App ) );
		await click( primary() );
		await click( primary() );
		expect( title() ).toHaveTextContent( 'Сопоставление' );
		saveStep.mockImplementation( () =>
			Promise.resolve( { saved: true, graph: [ welcome, { id: 'start', visible: false }, { id: 'mapping', visible: false }, review(), finish ] } )
		);

		await click( primary() );

		expect( title() ).toHaveTextContent( 'Итог' );
		expect( complete ).not.toHaveBeenCalled();
	} );

	test( 'when the last visible step hides itself the wizard does reach finish', async () => {
		install( [ entry( 'start', 'Начало' ), entry( 'mapping', 'Сопоставление' ), finish ] );
		render( createElement( App ) );
		await click( primary() );
		saveStep.mockImplementation( () =>
			Promise.resolve( { saved: true, graph: [ entry( 'start', 'Начало' ), { id: 'mapping', visible: false }, finish ] } )
		);

		await click( primary() );

		expect( document.querySelector( '.woodev-setup__finish-title' ) ).toBeInTheDocument();
	} );

	test( 'an action that hides the current step moves to the nearest visible step, never to finish', async () => {
		const withAction = ( id, label, extra = {} ) =>
			entry( id, label, { actions: [ { id: 'hide', label: 'Скрыть шаг', destructive: false, confirm: '' } ], ...extra } );
		install( [ entry( 'start', 'Начало' ), withAction( 'mapping', 'Сопоставление' ), finish ] );
		render( createElement( App ) );
		await click( primary() );
		runAction.mockImplementation( () =>
			Promise.resolve( { status: 'success', message: 'Скрыто.', data: {}, graph: [ entry( 'start', 'Начало' ), { id: 'mapping', visible: false }, finish ] } )
		);

		await click( screen.getByRole( 'button', { name: 'Скрыть шаг' } ) );

		expect( title() ).toHaveTextContent( 'Начало' );
		expect( complete ).not.toHaveBeenCalled();
	} );

	test( 'the stepper boundary follows the step, not its old position', async () => {
		install( [ entry( 'start', 'Начало' ), entry( 'mapping', 'Сопоставление' ), review(), finish ] );
		render( createElement( App ) );
		await click( primary() );
		saveStep.mockImplementation( () =>
			Promise.resolve( { saved: true, graph: [ { id: 'start', visible: false }, entry( 'mapping', 'Сопоставление' ), review(), finish ] } )
		);
		await click( primary() ); // → «Итог», «Начало» is gone

		// «Сопоставление» was visited and is still reachable; «Итог» is current; finish stays locked.
		expect( screen.getByRole( 'button', { name: 'Сопоставление' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Готово' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'an obsolete response cannot bring back a hidden branch (critic 109b #3)', () => {
	test( 'action → Back → newer save → the old action resolves: the branch stays hidden', async () => {
		const initial = graph();
		initial[ 1 ] = { ...initial[ 1 ], actions: [ { id: 'preview', label: 'Предпросмотр', destructive: false, confirm: '' } ] };
		install( initial );
		render( createElement( App ) );
		await click( primary() );
		let release;
		runAction.mockImplementation( () => new Promise( ( resolve ) => {
			release = resolve;
		} ) );
		await click( screen.getByRole( 'button', { name: 'Предпросмотр' } ) );
		await click( document.querySelector( '.woodev-setup__back' ) );
		saveStep.mockImplementation( () => Promise.resolve( { saved: true, step: 'start', graph: graph( { mappingVisible: false } ) } ) );
		await click( primary() );
		expect( title() ).toHaveTextContent( 'Итог' );
		expect( stepperLabels() ).not.toContain( 'Сопоставление' );

		await act( async () => {
			release( { status: 'success', message: 'Старый ответ.', data: {}, graph: initial } );
		} );

		expect( stepperLabels() ).not.toContain( 'Сопоставление' );
		expect( title() ).toHaveTextContent( 'Итог' );
	} );

	test( 'control: a lone slow action that the merchant left behind still applies its graph', async () => {
		const initial = graph();
		initial[ 1 ] = { ...initial[ 1 ], actions: [ { id: 'preview', label: 'Предпросмотр', destructive: false, confirm: '' } ] };
		install( initial );
		render( createElement( App ) );
		await click( primary() );
		let release;
		runAction.mockImplementation( () => new Promise( ( resolve ) => {
			release = resolve;
		} ) );
		await click( screen.getByRole( 'button', { name: 'Предпросмотр' } ) );
		await click( document.querySelector( '.woodev-setup__back' ) );

		await act( async () => {
			release( { status: 'success', message: 'Ответ.', data: {}, graph: graph( { mappingVisible: false } ) } );
		} );

		expect( stepperLabels() ).not.toContain( 'Сопоставление' );
		expect( title() ).toHaveTextContent( 'Начало' );
	} );
} );

describe( 'component skip() honours skippable (critic 109b)', () => {
	function withSkippable( skippable ) {
		let props;
		install( [
			entry( 'start', 'Начало' ),
			entry( 'review', 'Проверка', { type: 'content', skippable, component: { handle: 'acme-review', export: 'ReviewStep' } } ),
			entry( 'after', 'Дальше', { type: 'content' } ),
			finish,
		] );
		window.woodevSetupWizardComponents = {
			'acme-review': {
				ReviewStep: ( p ) => {
					props = p;
					return createElement( 'span' );
				},
			},
		};
		return () => props;
	}

	test( 'a non-skippable step ignores skip()', async () => {
		const get = withSkippable( false );
		render( createElement( App ) );
		await click( primary() );

		await act( async () => {
			get().skip();
		} );

		expect( title() ).toHaveTextContent( 'Проверка' );
	} );

	test( 'a skippable step moves on', async () => {
		const get = withSkippable( true );
		render( createElement( App ) );
		await click( primary() );

		await act( async () => {
			get().skip();
		} );

		expect( title() ).toHaveTextContent( 'Дальше' );
	} );
} );
